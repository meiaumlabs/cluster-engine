<?php
/**
 * CE61_Stock — bancos de imagens gratuitos (Unsplash, Pexels, Pixabay,
 * Openverse) como fonte alternativa/primária à geração por IA, com
 * controle real de cota por provedor (janela deslizante) para nunca
 * estourar os limites gratuitos de cada API.
 *
 * @package ClusterEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CE61_Stock {

	const OPT_USAGE = 'ce61_stock_usage';

	/**
	 * Metadados de cada provedor: janela de cota (segundos) e limite
	 * padrão de fábrica (o usuário pode ajustar nas Configurações caso
	 * tenha uma cota maior aprovada, ex.: Unsplash Production).
	 */
	public static function providers() {
		return array(
			'pexels'    => array(
				'label'        => 'Pexels',
				'needs_key'    => true,
				'window'       => HOUR_IN_SECONDS,
				'default_limit'=> 200,
				'signup'       => 'https://www.pexels.com/api/',
				'attribution'  => 'opcional',
			),
			'pixabay'   => array(
				'label'        => 'Pixabay',
				'needs_key'    => true,
				'window'       => 60,
				'default_limit'=> 100,
				'signup'       => 'https://pixabay.com/api/docs/',
				'attribution'  => 'opcional',
			),
			'unsplash'  => array(
				'label'        => 'Unsplash',
				'needs_key'    => true,
				'window'       => HOUR_IN_SECONDS,
				'default_limit'=> 50,
				'signup'       => 'https://unsplash.com/developers',
				'attribution'  => 'recomendada',
			),
			'openverse' => array(
				'label'        => 'Openverse',
				'needs_key'    => false,
				'window'       => HOUR_IN_SECONDS,
				'default_limit'=> 100,
				'signup'       => 'https://api.openverse.org/v1/',
				'attribution'  => 'varia por imagem',
			),
		);
	}

	private static function settings() {
		return get_option( 'ce61_settings', array() );
	}

	private static function limit_for( $provider ) {
		$s   = self::settings();
		$def = self::providers();
		$key = 'stock_limit_' . $provider;
		return isset( $s[ $key ] ) && $s[ $key ] > 0 ? (int) $s[ $key ] : $def[ $provider ]['default_limit'];
	}

	public static function has_key( $provider ) {
		$defs = self::providers();
		if ( empty( $defs[ $provider ]['needs_key'] ) ) {
			return true; // Openverse funciona sem chave.
		}
		$s = self::settings();
		return ! empty( $s[ 'stock_key_' . $provider ] );
	}

	private static function key( $provider ) {
		$s = self::settings();
		return isset( $s[ 'stock_key_' . $provider ] ) ? trim( $s[ 'stock_key_' . $provider ] ) : '';
	}

	/* ---------- Controle de cota (janela deslizante) ---------- */

	private static function usage_data() {
		$u = get_option( self::OPT_USAGE, array() );
		return is_array( $u ) ? $u : array();
	}

	/**
	 * Uso atual de um provedor: quantas chamadas na janela vigente, limite,
	 * e em quantos segundos a janela libera espaço de novo.
	 */
	public static function usage( $provider ) {
		$defs   = self::providers();
		$window = $defs[ $provider ]['window'];
		$limit  = self::limit_for( $provider );
		$all    = self::usage_data();
		$stamps = isset( $all[ $provider ] ) ? $all[ $provider ] : array();
		$now    = time();
		$stamps = array_values( array_filter( $stamps, function ( $t ) use ( $now, $window ) { return $t > $now - $window; } ) );
		$resets_in = $stamps ? max( 0, $window - ( $now - min( $stamps ) ) ) : 0;
		return array(
			'used'      => count( $stamps ),
			'limit'     => $limit,
			'remaining' => max( 0, $limit - count( $stamps ) ),
			'resets_in' => $resets_in,
			'window'    => $window,
		);
	}

	/**
	 * Reserva 1 chamada de cota; retorna true se havia espaço, false se a
	 * cota da janela já foi atingida (o chamador deve então tentar outro
	 * provedor ou esperar).
	 */
	private static function consume( $provider ) {
		$u = self::usage( $provider );
		if ( $u['remaining'] <= 0 ) {
			return false;
		}
		$all    = self::usage_data();
		$stamps = isset( $all[ $provider ] ) ? $all[ $provider ] : array();
		$now    = time();
		$window = self::providers()[ $provider ]['window'];
		$stamps = array_values( array_filter( $stamps, function ( $t ) use ( $now, $window ) { return $t > $now - $window; } ) );
		$stamps[] = $now;
		$all[ $provider ] = $stamps;
		update_option( self::OPT_USAGE, $all, false );
		return true;
	}

	/* ---------- Busca unificada ---------- */

	/**
	 * Busca em UM provedor específico. Retorna resultados normalizados:
	 * [ [id, thumb, full, credit, credit_url, source_url, license] ] ou WP_Error.
	 */
	public static function search( $provider, $query, $per_page = 12 ) {
		$defs = self::providers();
		if ( ! isset( $defs[ $provider ] ) ) {
			return new WP_Error( 'ce61_stock', __( 'Banco de imagens desconhecido.', 'cluster-engine' ) );
		}
		if ( $defs[ $provider ]['needs_key'] && ! self::has_key( $provider ) ) {
			return new WP_Error( 'ce61_stock_key', sprintf( __( 'Configure a chave do %s em Configurações → Integrações.', 'cluster-engine' ), $defs[ $provider ]['label'] ) );
		}
		if ( ! self::consume( $provider ) ) {
			$u = self::usage( $provider );
			return new WP_Error( 'ce61_stock_budget', sprintf(
				/* translators: 1: provider name, 2: seconds until reset */
				__( 'Cota do %1$s esgotada por agora (libera em ~%2$ds). Tente outro banco de imagens ou aguarde.', 'cluster-engine' ),
				$defs[ $provider ]['label'], $u['resets_in']
			) );
		}
		switch ( $provider ) {
			case 'pexels':    return self::search_pexels( $query, $per_page );
			case 'pixabay':   return self::search_pixabay( $query, $per_page );
			case 'unsplash':  return self::search_unsplash( $query, $per_page );
			case 'openverse': return self::search_openverse( $query, $per_page );
		}
		return new WP_Error( 'ce61_stock', __( 'Provedor não implementado.', 'cluster-engine' ) );
	}

	/**
	 * Busca "automática": tenta os provedores na ordem de prioridade
	 * configurada, pulando quem está sem chave ou sem cota, e devolve o
	 * primeiro resultado que funcionar. Ideal para geração em fila, sem
	 * humano escolhendo — e para não deixar o site sem imagem só porque
	 * um provedor específico estourou a cota do dia.
	 */
	public static function auto_search( $query, $per_page = 12 ) {
		$order = self::priority_order();
		$last_error = null;
		foreach ( $order as $provider ) {
			$defs = self::providers();
			if ( $defs[ $provider ]['needs_key'] && ! self::has_key( $provider ) ) {
				continue;
			}
			if ( self::usage( $provider )['remaining'] <= 0 ) {
				continue;
			}
			$r = self::search( $provider, $query, $per_page );
			if ( ! is_wp_error( $r ) && $r ) {
				return array( 'provider' => $provider, 'results' => $r );
			}
			$last_error = $r;
		}
		return $last_error instanceof WP_Error ? $last_error : new WP_Error( 'ce61_stock', __( 'Nenhum banco de imagens disponível (configure ao menos uma chave, ou aguarde a cota liberar).', 'cluster-engine' ) );
	}

	public static function priority_order() {
		$s = self::settings();
		$order = isset( $s['stock_priority'] ) && is_array( $s['stock_priority'] ) ? $s['stock_priority'] : array();
		$all   = array_keys( self::providers() );
		$order = array_values( array_intersect( $order, $all ) );
		foreach ( $all as $p ) {
			if ( ! in_array( $p, $order, true ) ) {
				$order[] = $p;
			}
		}
		return $order;
	}

	private static function normalize( $items ) {
		return array_values( array_filter( $items ) );
	}

	private static function search_pexels( $query, $per_page ) {
		$key = self::key( 'pexels' );
		$res = wp_remote_get( add_query_arg( array( 'query' => rawurlencode( $query ), 'per_page' => $per_page ), 'https://api.pexels.com/v1/search' ), array(
			'timeout' => 20,
			'headers' => array( 'Authorization' => $key ),
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( wp_remote_retrieve_response_code( $res ) !== 200 ) {
			return new WP_Error( 'ce61_stock', isset( $body['error'] ) ? $body['error'] : 'Pexels HTTP error' );
		}
		return self::normalize( array_map( function ( $p ) {
			return array(
				'id'         => 'pexels_' . $p['id'],
				'thumb'      => $p['src']['medium'],
				'full'       => $p['src']['large2x'] ?? $p['src']['large'],
				'credit'     => 'Foto de ' . $p['photographer'] . ' no Pexels',
				'credit_url' => $p['photographer_url'],
				'source_url' => $p['url'],
				'license'    => 'Pexels License',
				'provider'   => 'pexels',
			);
		}, isset( $body['photos'] ) ? $body['photos'] : array() ) );
	}

	private static function search_pixabay( $query, $per_page ) {
		$key = self::key( 'pixabay' );
		$url = add_query_arg( array(
			'key' => rawurlencode( $key ), 'q' => rawurlencode( $query ),
			'per_page' => max( 3, $per_page ), 'image_type' => 'photo', 'safesearch' => 'true',
		), 'https://pixabay.com/api/' );
		$res = wp_remote_get( $url, array( 'timeout' => 20 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( wp_remote_retrieve_response_code( $res ) !== 200 ) {
			return new WP_Error( 'ce61_stock', isset( $body['error'] ) ? $body['error'] : 'Pixabay HTTP error' );
		}
		return self::normalize( array_map( function ( $p ) {
			return array(
				'id'         => 'pixabay_' . $p['id'],
				'thumb'      => $p['webformatURL'],
				'full'       => isset( $p['largeImageURL'] ) ? $p['largeImageURL'] : $p['webformatURL'],
				'credit'     => 'Imagem de ' . $p['user'] . ' via Pixabay',
				'credit_url' => $p['pageURL'],
				'source_url' => $p['pageURL'],
				'license'    => 'Pixabay License',
				'provider'   => 'pixabay',
			);
		}, isset( $body['hits'] ) ? $body['hits'] : array() ) );
	}

	private static function search_unsplash( $query, $per_page ) {
		$key = self::key( 'unsplash' );
		$url = add_query_arg( array( 'query' => rawurlencode( $query ), 'per_page' => $per_page ), 'https://api.unsplash.com/search/photos' );
		$res = wp_remote_get( $url, array(
			'timeout' => 20,
			'headers' => array( 'Authorization' => 'Client-ID ' . $key ),
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( wp_remote_retrieve_response_code( $res ) !== 200 ) {
			return new WP_Error( 'ce61_stock', isset( $body['errors'][0] ) ? $body['errors'][0] : 'Unsplash HTTP error' );
		}
		return self::normalize( array_map( function ( $p ) {
			return array(
				'id'              => 'unsplash_' . $p['id'],
				'thumb'           => $p['urls']['small'],
				'full'            => $p['urls']['regular'],
				'credit'          => 'Foto de ' . $p['user']['name'] . ' no Unsplash',
				'credit_url'      => $p['user']['links']['html'] . '?utm_source=cluster_engine&utm_medium=referral',
				'source_url'      => $p['links']['html'],
				'license'         => 'Unsplash License',
				'provider'        => 'unsplash',
				'download_ping'   => isset( $p['links']['download_location'] ) ? $p['links']['download_location'] : '',
			);
		}, isset( $body['results'] ) ? $body['results'] : array() ) );
	}

	private static function search_openverse( $query, $per_page ) {
		$url = add_query_arg( array( 'q' => rawurlencode( $query ), 'page_size' => $per_page, 'license_type' => 'commercial' ), 'https://api.openverse.org/v1/images/' );
		$res = wp_remote_get( $url, array( 'timeout' => 20, 'headers' => array( 'User-Agent' => 'ClusterEngine/61Labs' ) ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( wp_remote_retrieve_response_code( $res ) !== 200 ) {
			return new WP_Error( 'ce61_stock', isset( $body['detail'] ) ? $body['detail'] : 'Openverse HTTP error' );
		}
		return self::normalize( array_map( function ( $p ) {
			$license = isset( $p['license'] ) ? strtoupper( $p['license'] ) : '';
			return array(
				'id'         => 'openverse_' . $p['id'],
				'thumb'      => isset( $p['thumbnail'] ) ? $p['thumbnail'] : $p['url'],
				'full'       => $p['url'],
				'credit'     => 'Imagem de ' . ( $p['creator'] ?? 'autor desconhecido' ) . ' via Openverse (' . $license . ')',
				'credit_url' => isset( $p['foreign_landing_url'] ) ? $p['foreign_landing_url'] : '',
				'source_url' => isset( $p['foreign_landing_url'] ) ? $p['foreign_landing_url'] : '',
				'license'    => $license ? $license : 'verificar na fonte',
				'provider'   => 'openverse',
				// CC0 não exige atribuição; as demais licenças do Openverse exigem.
				'requires_attribution' => 'CC0' !== $license,
			);
		}, isset( $body['results'] ) ? $body['results'] : array() ) );
	}

	/* ---------- Download e anexo ---------- */

	/**
	 * Baixa a imagem escolhida, aplica o watermark configurado, anexa como
	 * imagem destacada e grava a atribuição correta (obrigatória para
	 * Openverse quando a licença exige, recomendada para Unsplash).
	 */
	/**
	 * Baixa os bytes da imagem escolhida e aplica o watermark configurado.
	 */
	private static function fetch_bytes( $image ) {
		$url = isset( $image['full'] ) ? $image['full'] : '';
		if ( ! $url ) {
			return new WP_Error( 'ce61_stock', __( 'Imagem inválida.', 'cluster-engine' ) );
		}
		$res = wp_remote_get( $url, array( 'timeout' => 60 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$bytes = wp_remote_retrieve_body( $res );
		if ( ! $bytes ) {
			return new WP_Error( 'ce61_stock', __( 'Não foi possível baixar a imagem.', 'cluster-engine' ) );
		}
		return CE61_Images::apply_watermark( $bytes );
	}

	/**
	 * Notifica o Unsplash do download (exigência da API deles) ao USAR a imagem.
	 */
	private static function ping_download( $image ) {
		if ( ! empty( $image['download_ping'] ) ) {
			$key = self::key( 'unsplash' );
			wp_remote_get( $image['download_ping'], array( 'timeout' => 10, 'headers' => array( 'Authorization' => 'Client-ID ' . $key ) ) );
		}
	}

	/**
	 * Baixa a imagem escolhida, otimiza (WebP), insere DENTRO do corpo do post
	 * como <figure> na posição escolhida e grava a atribuição na legenda.
	 */
	public static function apply_inline( $post_id, $image, $position = 'after_h2' ) {
		$bytes = self::fetch_bytes( $image );
		if ( is_wp_error( $bytes ) ) {
			return $bytes;
		}
		$credit = isset( $image['credit'] ) ? $image['credit'] : '';
		$attach = CE61_Images::attach_inline( $post_id, $bytes, $credit ? array( 'caption' => $credit ) : array(), $position );
		if ( is_wp_error( $attach ) ) {
			return $attach;
		}
		update_post_meta( $attach['attachment_id'], '_ce61_image_credit', wp_json_encode( $image, JSON_UNESCAPED_UNICODE ) );
		self::ping_download( $image );
		return $attach;
	}

	public static function apply_to_post( $post_id, $image ) {
		$bytes = self::fetch_bytes( $image );
		if ( is_wp_error( $bytes ) ) {
			return $bytes;
		}
		$settings = get_option( 'ce61_settings', array() );

		$attach = CE61_Images::attach_as_featured( $post_id, $bytes );
		if ( is_wp_error( $attach ) ) {
			return $attach;
		}

		// Legenda com crédito — obrigatório para a maioria das licenças do
		// Openverse e boa prática para Unsplash; opcional para Pexels/Pixabay.
		$include_caption = ! isset( $settings['stock_credit_caption'] ) || $settings['stock_credit_caption'];
		$must_credit     = ! empty( $image['requires_attribution'] ) || 'unsplash' === ( $image['provider'] ?? '' );
		if ( $include_caption || $must_credit ) {
			$credit = isset( $image['credit'] ) ? $image['credit'] : '';
			if ( $credit ) {
				wp_update_post( array(
					'ID'           => $attach['attachment_id'],
					'post_excerpt' => $credit, // legenda do anexo.
				) );
			}
		}
		update_post_meta( $post_id, '_ce61_image_credit', wp_json_encode( $image, JSON_UNESCAPED_UNICODE ) );

		// Unsplash exige notificar o endpoint de download ao USAR a imagem
		// (não na busca) — cumpre as diretrizes de uso da API deles.
		self::ping_download( $image );

		return $attach;
	}
}
