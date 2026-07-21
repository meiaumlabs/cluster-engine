<?php
/**
 * CE61_Redirects — gestão de redirects 301 integrada ao Rank Math.
 *
 * Quando o Rank Math está ativo, lê e grava na tabela nativa
 * {prefix}rank_math_redirections (mesmo formato do módulo Redirections).
 * Sem Rank Math, usa armazenamento próprio na option 'ce61_redirects' e
 * resolve os redirects no front-end via template_redirect.
 *
 * @package ClusterEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CE61_Redirects {

	const OPTION = 'ce61_redirects';

	/**
	 * Rank Math ativo com o módulo de redirects disponível?
	 */
	public static function rankmath_active() {
		return defined( 'RANK_MATH_VERSION' ) && self::rankmath_has_table();
	}

	private static function rankmath_has_table() {
		global $wpdb;
		$table = $wpdb->prefix . 'rank_math_redirections';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $found === $table;
	}

	/**
	 * Normaliza uma URL para o "source" relativo que o Rank Math usa
	 * (caminho + query sem barra inicial), p.ex. "categoria/velho/post".
	 */
	private static function to_source( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! $path ) {
			$path = $url;
		}
		$query = wp_parse_url( $url, PHP_URL_QUERY );
		$src   = ltrim( (string) $path, '/' );
		if ( $query ) {
			$src .= '?' . $query;
		}
		return $src;
	}

	/**
	 * Adiciona um redirect. Devolve o ID criado ou WP_Error.
	 *
	 * @param string $from_url URL de origem (absoluta ou relativa).
	 * @param string $to_url   URL de destino.
	 * @param int    $code     Código HTTP (301, 302, 307, 410, 451).
	 */
	public static function add( $from_url, $to_url, $code = 301 ) {
		$from = esc_url_raw( $from_url );
		$to   = esc_url_raw( $to_url );
		$code = (int) $code;
		if ( ! in_array( $code, array( 301, 302, 307, 410, 451 ), true ) ) {
			$code = 301;
		}
		$source = self::to_source( $from );
		if ( '' === $source ) {
			return new WP_Error( 'ce61_redirect_source', __( 'Origem inválida.', 'cluster-engine' ) );
		}

		if ( self::rankmath_active() ) {
			return self::rankmath_add( $source, $to, $code );
		}
		return self::native_add( $source, $to, $code );
	}

	private static function rankmath_add( $source, $to, $code ) {
		global $wpdb;
		$table = $wpdb->prefix . 'rank_math_redirections';

		$sources = array(
			array(
				'pattern'    => $source,
				'comparison' => 'exact',
				'ignore'     => '',
			),
		);

		// Evita duplicar a mesma origem.
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE sources LIKE %s LIMIT 1",
			'%' . $wpdb->esc_like( $source ) . '%'
		) );
		if ( $existing ) {
			$wpdb->update(
				$table,
				array(
					'url_to'      => $to,
					'header_code' => (string) $code,
					'status'      => 'active',
					'updated'     => current_time( 'mysql' ),
				),
				array( 'id' => (int) $existing ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			return (int) $existing;
		}

		$now = current_time( 'mysql' );
		$ok  = $wpdb->insert(
			$table,
			array(
				'sources'     => maybe_serialize( $sources ),
				'url_to'      => $to,
				'header_code' => (string) $code,
				'hits'        => 0,
				'status'      => 'active',
				'created'     => $now,
				'updated'     => $now,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		if ( ! $ok ) {
			return new WP_Error( 'ce61_redirect_db', __( 'Falha ao gravar redirect no Rank Math.', 'cluster-engine' ) );
		}
		return (int) $wpdb->insert_id;
	}

	private static function native_add( $source, $to, $code ) {
		$all = self::native_all();
		// Substitui se a origem já existir.
		foreach ( $all as $id => $r ) {
			if ( isset( $r['source'] ) && $r['source'] === $source ) {
				$all[ $id ]['to']      = $to;
				$all[ $id ]['code']    = $code;
				$all[ $id ]['status']  = 'active';
				$all[ $id ]['updated'] = time();
				update_option( self::OPTION, $all, false );
				return $id;
			}
		}
		$id         = self::next_id( $all );
		$all[ $id ] = array(
			'id'      => $id,
			'source'  => $source,
			'to'      => $to,
			'code'    => $code,
			'hits'    => 0,
			'status'  => 'active',
			'created' => time(),
			'updated' => time(),
		);
		update_option( self::OPTION, $all, false );
		return $id;
	}

	private static function native_all() {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	private static function next_id( $all ) {
		$max = 0;
		foreach ( array_keys( $all ) as $k ) {
			$max = max( $max, (int) $k );
		}
		return $max + 1;
	}

	/**
	 * Lista os redirects (origem própria + Rank Math), normalizados.
	 */
	public static function list_all() {
		if ( self::rankmath_active() ) {
			return self::rankmath_list();
		}
		return self::native_list();
	}

	private static function rankmath_list() {
		global $wpdb;
		$table = $wpdb->prefix . 'rank_math_redirections';
		$rows  = $wpdb->get_results( "SELECT id, sources, url_to, header_code, hits, status FROM {$table} ORDER BY id DESC LIMIT 500", ARRAY_A );
		$out   = array();
		if ( ! $rows ) {
			return $out;
		}
		foreach ( $rows as $r ) {
			$sources = maybe_unserialize( $r['sources'] );
			$from    = '';
			if ( is_array( $sources ) && isset( $sources[0]['pattern'] ) ) {
				$from = $sources[0]['pattern'];
			}
			$out[] = array(
				'id'     => (int) $r['id'],
				'from'   => $from,
				'to'     => $r['url_to'],
				'code'   => (int) $r['header_code'],
				'hits'   => (int) $r['hits'],
				'status' => $r['status'],
				'source' => 'rankmath',
			);
		}
		return $out;
	}

	private static function native_list() {
		$all = self::native_all();
		$out = array();
		foreach ( $all as $r ) {
			$out[] = array(
				'id'     => (int) $r['id'],
				'from'   => isset( $r['source'] ) ? $r['source'] : '',
				'to'     => isset( $r['to'] ) ? $r['to'] : '',
				'code'   => isset( $r['code'] ) ? (int) $r['code'] : 301,
				'hits'   => isset( $r['hits'] ) ? (int) $r['hits'] : 0,
				'status' => isset( $r['status'] ) ? $r['status'] : 'active',
				'source' => 'ce61',
			);
		}
		// Mais novos primeiro.
		usort( $out, function ( $a, $b ) {
			return $b['id'] - $a['id'];
		} );
		return $out;
	}

	/**
	 * Remove um redirect pelo ID (respeitando a fonte ativa).
	 */
	public static function delete( $id ) {
		$id = (int) $id;
		if ( self::rankmath_active() ) {
			global $wpdb;
			$table = $wpdb->prefix . 'rank_math_redirections';
			$ok    = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
			return (bool) $ok;
		}
		$all = self::native_all();
		if ( isset( $all[ $id ] ) ) {
			unset( $all[ $id ] );
			update_option( self::OPTION, $all, false );
			return true;
		}
		return false;
	}

	/**
	 * Alterna active/inactive.
	 */
	public static function toggle( $id ) {
		$id = (int) $id;
		if ( self::rankmath_active() ) {
			global $wpdb;
			$table  = $wpdb->prefix . 'rank_math_redirections';
			$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $id ) );
			if ( null === $status ) {
				return false;
			}
			$new = ( 'active' === $status ) ? 'inactive' : 'active';
			$wpdb->update( $table, array( 'status' => $new ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
			return $new;
		}
		$all = self::native_all();
		if ( isset( $all[ $id ] ) ) {
			$new                   = ( 'active' === $all[ $id ]['status'] ) ? 'inactive' : 'active';
			$all[ $id ]['status']  = $new;
			$all[ $id ]['updated'] = time();
			update_option( self::OPTION, $all, false );
			return $new;
		}
		return false;
	}

	/**
	 * Resolve redirects próprios no front-end (só quando o Rank Math não cuida).
	 */
	public static function maybe_redirect() {
		if ( is_admin() || self::rankmath_active() ) {
			return;
		}
		$all = self::native_all();
		if ( ! $all ) {
			return;
		}
		$req = isset( $_SERVER['REQUEST_URI'] ) ? ltrim( wp_unslash( $_SERVER['REQUEST_URI'] ), '/' ) : '';
		$req = rtrim( $req, '/' );
		if ( '' === $req ) {
			return;
		}
		foreach ( $all as $id => $r ) {
			if ( 'active' !== $r['status'] ) {
				continue;
			}
			$src = rtrim( $r['source'], '/' );
			if ( $src === $req ) {
				$all[ $id ]['hits'] = (int) $r['hits'] + 1;
				update_option( self::OPTION, $all, false );
				$code = in_array( (int) $r['code'], array( 301, 302, 307 ), true ) ? (int) $r['code'] : 301;
				wp_safe_redirect( $r['to'], $code );
				exit;
			}
		}
	}
}

add_action( 'template_redirect', array( 'CE61_Redirects', 'maybe_redirect' ), 1 );
