<?php
/**
 * CE61_Serp — abstração unificada de APIs de SERP (posicionamento no Google).
 *
 * Provedores suportados, com Serper.dev como padrão:
 *  - serper    → https://serper.dev            (2.500 buscas grátis no cadastro)
 *  - serpapi   → https://serpapi.com           (100 buscas/mês grátis)
 *  - valueserp → https://valueserp.com         (trial ~100 requisições)
 *
 * Todas retornam o mesmo formato normalizado, independente do provedor,
 * para que o restante do plugin nunca precise saber qual API está ativa.
 *
 * @package ClusterEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CE61_Serp {

	public static function provider_names() {
		return array(
			'serper'    => 'Serper.dev',
			'serpapi'   => 'SerpApi',
			'valueserp' => 'ValueSERP',
		);
	}

	public static function active_provider( $settings = null ) {
		if ( null === $settings ) {
			$settings = get_option( 'ce61_settings', array() );
		}
		return isset( $settings['serp_provider'] ) && $settings['serp_provider'] ? $settings['serp_provider'] : 'serper';
	}

	public static function has_key( $settings = null ) {
		if ( null === $settings ) {
			$settings = get_option( 'ce61_settings', array() );
		}
		$p = self::active_provider( $settings );
		return ! empty( $settings[ 'serp_key_' . $p ] );
	}

	/**
	 * Busca a keyword no Google e retorna o resultado normalizado:
	 * [ 'position' => int|null, 'url' => string|null, 'organic' => [ [position,title,url], ... ], 'provider' => string ]
	 * ou WP_Error em caso de falha.
	 */
	public static function check( $keyword ) {
		$settings = get_option( 'ce61_settings', array() );
		$provider = self::active_provider( $settings );
		$keyword  = trim( (string) $keyword );
		if ( '' === $keyword ) {
			return new WP_Error( 'ce61_serp', __( 'Keyword vazia.', 'cluster-engine' ) );
		}

		switch ( $provider ) {
			case 'serpapi':
				$organic = self::fetch_serpapi( $keyword, $settings );
				break;
			case 'valueserp':
				$organic = self::fetch_valueserp( $keyword, $settings );
				break;
			case 'serper':
			default:
				$organic = self::fetch_serper( $keyword, $settings );
				break;
		}
		if ( is_wp_error( $organic ) ) {
			return $organic;
		}

		$host = self::home_host();
		$pos  = null;
		$url  = null;
		foreach ( $organic as $r ) {
			$rhost = wp_parse_url( $r['url'], PHP_URL_HOST );
			$rhost = $rhost ? preg_replace( '/^www\./i', '', strtolower( $rhost ) ) : '';
			if ( $rhost === $host ) {
				$pos = (int) $r['position'];
				$url = $r['url'];
				break;
			}
		}

		return array(
			'position' => $pos,
			'url'      => $url,
			'organic'  => array_slice( $organic, 0, 10 ),
			'provider' => self::provider_names()[ $provider ],
			'checked_at' => current_time( 'mysql' ),
		);
	}

	private static function home_host() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return $host ? preg_replace( '/^www\./i', '', strtolower( $host ) ) : '';
	}

	/**
	 * Normalize a raw provider result list into [ position, title, url ].
	 */
	private static function normalize( $items, $pos_key, $title_key, $url_key ) {
		$out = array();
		foreach ( (array) $items as $i => $it ) {
			$out[] = array(
				'position' => isset( $it[ $pos_key ] ) ? (int) $it[ $pos_key ] : ( $i + 1 ),
				'title'    => isset( $it[ $title_key ] ) ? $it[ $title_key ] : '',
				'url'      => isset( $it[ $url_key ] ) ? $it[ $url_key ] : '',
			);
		}
		return $out;
	}

	private static function fetch_serper( $keyword, $settings ) {
		$key = trim( isset( $settings['serp_key_serper'] ) ? $settings['serp_key_serper'] : '' );
		if ( ! $key ) {
			return new WP_Error( 'ce61_no_key', __( 'Configure a chave do Serper.dev em Configurações → Integrações.', 'cluster-engine' ) );
		}
		$res = wp_remote_post( 'https://google.serper.dev/search', array(
			'timeout' => 30,
			'headers' => array( 'X-API-KEY' => $key, 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'q' => $keyword, 'gl' => 'br', 'hl' => 'pt-br' ) ),
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code ) {
			return new WP_Error( 'ce61_serp', isset( $body['message'] ) ? $body['message'] : ( 'Serper.dev HTTP ' . $code ) );
		}
		return self::normalize( isset( $body['organic'] ) ? $body['organic'] : array(), 'position', 'title', 'link' );
	}

	private static function fetch_serpapi( $keyword, $settings ) {
		$key = trim( isset( $settings['serp_key_serpapi'] ) ? $settings['serp_key_serpapi'] : '' );
		if ( ! $key ) {
			return new WP_Error( 'ce61_no_key', __( 'Configure a chave da SerpApi em Configurações → Integrações.', 'cluster-engine' ) );
		}
		$url = add_query_arg( array(
			'engine'  => 'google',
			'q'       => rawurlencode( $keyword ),
			'gl'      => 'br',
			'hl'      => 'pt-br',
			'num'     => 20,
			'api_key' => rawurlencode( $key ),
		), 'https://serpapi.com/search.json' );
		$res = wp_remote_get( $url, array( 'timeout' => 30 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code ) {
			return new WP_Error( 'ce61_serp', isset( $body['error'] ) ? $body['error'] : ( 'SerpApi HTTP ' . $code ) );
		}
		return self::normalize( isset( $body['organic_results'] ) ? $body['organic_results'] : array(), 'position', 'title', 'link' );
	}

	private static function fetch_valueserp( $keyword, $settings ) {
		$key = trim( isset( $settings['serp_key_valueserp'] ) ? $settings['serp_key_valueserp'] : '' );
		if ( ! $key ) {
			return new WP_Error( 'ce61_no_key', __( 'Configure a chave da ValueSERP em Configurações → Integrações.', 'cluster-engine' ) );
		}
		$url = add_query_arg( array(
			'api_key'       => rawurlencode( $key ),
			'q'             => rawurlencode( $keyword ),
			'google_domain' => 'google.com.br',
			'gl'            => 'br',
			'hl'            => 'pt',
		), 'https://api.valueserp.com/search' );
		$res = wp_remote_get( $url, array( 'timeout' => 30 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code ) {
			return new WP_Error( 'ce61_serp', isset( $body['request_info']['message'] ) ? $body['request_info']['message'] : ( 'ValueSERP HTTP ' . $code ) );
		}
		return self::normalize( isset( $body['organic_results'] ) ? $body['organic_results'] : array(), 'position', 'title', 'link' );
	}

	/* ---------- Cache por post (postmeta) ---------- */

	public static function get_cached( $post_id ) {
		$raw = get_post_meta( $post_id, '_ce61_serp', true );
		$d   = $raw ? json_decode( $raw, true ) : null;
		return is_array( $d ) ? $d : null;
	}

	public static function set_cached( $post_id, $data ) {
		update_post_meta( $post_id, '_ce61_serp', wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) );
	}
}
