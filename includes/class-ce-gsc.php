<?php
/**
 * CE61_Gsc — integração OAuth com Google Search Console e Google Analytics 4.
 *
 * Fluxo: o usuário cria um OAuth Client (tipo "Web application") no Google
 * Cloud Console, cola Client ID/Secret em Configurações → Integrações, e
 * clica em "Conectar com o Google". O redirect_uri exigido é sempre:
 *   admin-post.php?action=ce61_google_callback
 *
 * @package ClusterEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CE61_Gsc {

	const OPT   = 'ce61_google';
	const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/analytics.readonly https://www.googleapis.com/auth/indexing';

	public static function data() {
		$d = get_option( self::OPT, array() );
		return is_array( $d ) ? $d : array();
	}

	public static function save( $d ) {
		update_option( self::OPT, $d );
	}

	public static function is_connected() {
		$d = self::data();
		return ! empty( $d['refresh_token'] );
	}

	public static function redirect_uri() {
		return admin_url( 'admin-post.php?action=ce61_google_callback' );
	}

	public static function auth_url() {
		$d = self::data();
		if ( empty( $d['client_id'] ) ) {
			return '';
		}
		$state = wp_create_nonce( 'ce61_google_oauth' );
		set_transient( 'ce61_google_state_' . $state, 1, 10 * MINUTE_IN_SECONDS );
		return add_query_arg( array(
			'client_id'     => rawurlencode( $d['client_id'] ),
			'redirect_uri'  => rawurlencode( self::redirect_uri() ),
			'response_type' => 'code',
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'include_granted_scopes' => 'true',
			'state'         => $state,
			'scope'         => rawurlencode( self::SCOPE ),
		), 'https://accounts.google.com/o/oauth2/v2/auth' );
	}

	/**
	 * Troca o code por tokens e salva.
	 */
	public static function exchange_code( $code ) {
		$d = self::data();
		$res = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
			'timeout' => 30,
			'body'    => array(
				'code'          => $code,
				'client_id'     => $d['client_id'],
				'client_secret' => $d['client_secret'],
				'redirect_uri'  => self::redirect_uri(),
				'grant_type'    => 'authorization_code',
			),
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $body['access_token'] ) ) {
			return new WP_Error( 'ce61_oauth', isset( $body['error_description'] ) ? $body['error_description'] : __( 'Falha ao trocar o código por token.', 'cluster-engine' ) );
		}
		$d['access_token']  = $body['access_token'];
		$d['expires_at']    = time() + ( isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600 ) - 60;
		if ( ! empty( $body['refresh_token'] ) ) {
			$d['refresh_token'] = $body['refresh_token']; // só vem na primeira autorização (prompt=consent garante isso).
		}
		if ( isset( $body['scope'] ) ) {
			$d['scopes'] = $body['scope']; // escopos realmente concedidos, para saber se o de indexação está ativo.
		}
		self::save( $d );
		return true;
	}

	/**
	 * Retorna um access_token válido, renovando via refresh_token se preciso.
	 */
	public static function access_token() {
		$d = self::data();
		if ( empty( $d['refresh_token'] ) ) {
			return new WP_Error( 'ce61_oauth', __( 'Google não conectado.', 'cluster-engine' ) );
		}
		if ( ! empty( $d['access_token'] ) && ! empty( $d['expires_at'] ) && time() < $d['expires_at'] ) {
			return $d['access_token'];
		}
		$res = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
			'timeout' => 30,
			'body'    => array(
				'client_id'     => $d['client_id'],
				'client_secret' => $d['client_secret'],
				'refresh_token' => $d['refresh_token'],
				'grant_type'    => 'refresh_token',
			),
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $body['access_token'] ) ) {
			return new WP_Error( 'ce61_oauth', isset( $body['error_description'] ) ? $body['error_description'] : __( 'Não foi possível renovar o token do Google. Reconecte.', 'cluster-engine' ) );
		}
		$d['access_token'] = $body['access_token'];
		$d['expires_at']   = time() + ( isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600 ) - 60;
		self::save( $d );
		return $d['access_token'];
	}

	public static function disconnect() {
		$d = self::data();
		unset( $d['access_token'], $d['refresh_token'], $d['expires_at'] );
		self::save( $d );
	}

	/**
	 * Lista as propriedades GA4 que a conta conectada administra
	 * (Google Analytics Admin API — accountSummaries.list).
	 * Retorna [ [id => '123456789', label => 'Conta X · Propriedade Y'], ... ]
	 */
	public static function list_ga4_properties() {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$res = wp_remote_get( 'https://analyticsadmin.googleapis.com/v1beta/accountSummaries?pageSize=200', array(
			'timeout' => 20,
			'headers' => array( 'Authorization' => 'Bearer ' . $token ),
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code ) {
			return new WP_Error( 'ce61_ga4', isset( $body['error']['message'] ) ? $body['error']['message'] : ( 'GA4 Admin API HTTP ' . $code ) );
		}
		$out = array();
		foreach ( ( isset( $body['accountSummaries'] ) ? $body['accountSummaries'] : array() ) as $acc ) {
			foreach ( ( isset( $acc['propertySummaries'] ) ? $acc['propertySummaries'] : array() ) as $prop ) {
				$id    = preg_replace( '/^properties\//', '', isset( $prop['property'] ) ? $prop['property'] : '' );
				if ( ! $id ) {
					continue;
				}
				$out[] = array(
					'id'    => $id,
					'label' => ( isset( $acc['displayName'] ) ? $acc['displayName'] : '' ) . ' · ' . ( isset( $prop['displayName'] ) ? $prop['displayName'] : $id ),
				);
			}
		}
		if ( ! $out ) {
			return new WP_Error( 'ce61_ga4', __( 'Nenhuma propriedade GA4 encontrada nesta conta Google. Confirme que você autorizou com a conta correta e que ela tem acesso a alguma propriedade GA4.', 'cluster-engine' ) );
		}
		return $out;
	}

	/**
	 * Lista as propriedades (sites) do Search Console que o usuário administra.
	 */
	public static function list_sites() {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$res = wp_remote_get( 'https://www.googleapis.com/webmasters/v3/sites', array(
			'timeout' => 20,
			'headers' => array( 'Authorization' => 'Bearer ' . $token ),
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code ) {
			return new WP_Error( 'ce61_gsc', isset( $body['error']['message'] ) ? $body['error']['message'] : ( 'Search Console HTTP ' . $code ) );
		}
		if ( ! isset( $body['siteEntry'] ) ) {
			return new WP_Error( 'ce61_gsc', __( 'Nenhuma propriedade encontrada no Search Console para esta conta Google.', 'cluster-engine' ) );
		}
		return wp_list_pluck( $body['siteEntry'], 'siteUrl' );
	}

	/**
	 * Search Analytics: cliques, impressões, CTR e posição média por página,
	 * últimos $days dias. Retorna [ path_normalizado => [clicks,impressions,ctr,position] ].
	 */
	public static function search_analytics( $days = 28 ) {
		$end   = gmdate( 'Y-m-d', strtotime( '-2 days' ) );
		$start = gmdate( 'Y-m-d', strtotime( '-' . ( $days + 2 ) . ' days' ) );
		return self::search_analytics_range( $start, $end );
	}

	/**
	 * Mesma coisa, mas para um intervalo de datas explícito (usado pelo
	 * snapshot diário, que sempre pede exatamente 1 dia).
	 */
	public static function search_analytics_range( $start, $end ) {
		$d = self::data();
		if ( empty( $d['gsc_site_url'] ) ) {
			return new WP_Error( 'ce61_gsc', __( 'Defina a propriedade do Search Console em Configurações → Integrações.', 'cluster-engine' ) );
		}
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$url = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( $d['gsc_site_url'] ) . '/searchAnalytics/query';
		$res = wp_remote_post( $url, array(
			'timeout' => 30,
			'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'startDate'  => $start,
				'endDate'    => $end,
				'dimensions' => array( 'page' ),
				'rowLimit'   => 5000,
			) ),
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code ) {
			return new WP_Error( 'ce61_gsc', isset( $body['error']['message'] ) ? $body['error']['message'] : ( 'Search Console HTTP ' . $code ) );
		}
		$out = array();
		foreach ( ( isset( $body['rows'] ) ? $body['rows'] : array() ) as $row ) {
			$page = isset( $row['keys'][0] ) ? $row['keys'][0] : '';
			$path = untrailingslashit( strtolower( (string) wp_parse_url( $page, PHP_URL_PATH ) ) );
			$out[ $path ] = array(
				'clicks'      => (int) $row['clicks'],
				'impressions' => (int) $row['impressions'],
				'ctr'         => round( $row['ctr'] * 100, 1 ),
				'position'    => round( $row['position'], 1 ),
			);
		}
		return $out;
	}

	/**
	 * GA4 Data API: sessões por pagePath nos últimos $days dias.
	 * Retorna [ path_normalizado => sessions ].
	 */
	public static function ga4_sessions( $days = 28 ) {
		return self::ga4_sessions_range( $days . 'daysAgo', 'today' );
	}

	/**
	 * Mesma coisa, mas com startDate/endDate explícitos no formato aceito
	 * pela GA4 Data API ('YYYY-MM-DD' ou 'NdaysAgo'/'today'/'yesterday').
	 */
	public static function ga4_sessions_range( $start, $end ) {
		$d = self::data();
		if ( empty( $d['ga4_property_id'] ) ) {
			return new WP_Error( 'ce61_ga4', __( 'Defina o ID de propriedade do GA4 em Configurações → Integrações.', 'cluster-engine' ) );
		}
		$token    = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$property = preg_replace( '/^properties\//', '', trim( $d['ga4_property_id'] ) );
		$url      = 'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode( $property ) . ':runReport';
		$res      = wp_remote_post( $url, array(
			'timeout' => 30,
			'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'dateRanges' => array( array( 'startDate' => $start, 'endDate' => $end ) ),
				'dimensions' => array( array( 'name' => 'pagePath' ) ),
				'metrics'    => array( array( 'name' => 'sessions' ) ),
				'limit'      => 5000,
			) ),
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code ) {
			return new WP_Error( 'ce61_ga4', isset( $body['error']['message'] ) ? $body['error']['message'] : ( 'GA4 HTTP ' . $code ) );
		}
		$out = array();
		foreach ( ( isset( $body['rows'] ) ? $body['rows'] : array() ) as $row ) {
			$path = untrailingslashit( strtolower( isset( $row['dimensionValues'][0]['value'] ) ? $row['dimensionValues'][0]['value'] : '' ) );
			$out[ $path ] = isset( $row['metricValues'][0]['value'] ) ? (int) $row['metricValues'][0]['value'] : 0;
		}
		return $out;
	}

	/**
	 * Verdadeiro se a conta conectada concedeu o escopo de indexação
	 * (Indexing API). Usuários que conectaram antes desse recurso precisam
	 * reconectar para o escopo passar a constar.
	 */
	public static function has_indexing_scope() {
		$d = self::data();
		return ! empty( $d['scopes'] ) && false !== strpos( $d['scopes'], 'auth/indexing' );
	}

	/**
	 * URL Inspection API: estado de indexação de uma URL no índice do Google.
	 * Retorna [ state => 'indexed'|'not_indexed'|'unknown', coverage, verdict, last_crawl ]
	 * ou WP_Error.
	 */
	public static function inspect_url( $url ) {
		$d = self::data();
		if ( empty( $d['gsc_site_url'] ) ) {
			return new WP_Error( 'ce61_gsc', __( 'Defina a propriedade do Search Console em Configurações → Integrações.', 'cluster-engine' ) );
		}
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$res = wp_remote_post( 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', array(
			'timeout' => 25,
			'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'inspectionUrl' => $url,
				'siteUrl'       => $d['gsc_site_url'],
			) ),
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code ) {
			return new WP_Error( 'ce61_gsc', isset( $body['error']['message'] ) ? $body['error']['message'] : ( 'URL Inspection HTTP ' . $code ) );
		}
		$idx      = isset( $body['inspectionResult']['indexStatusResult'] ) ? $body['inspectionResult']['indexStatusResult'] : array();
		$verdict  = isset( $idx['verdict'] ) ? $idx['verdict'] : 'VERDICT_UNSPECIFIED';
		$coverage = isset( $idx['coverageState'] ) ? $idx['coverageState'] : '';
		$state    = 'unknown';
		if ( 'PASS' === $verdict ) {
			$state = 'indexed';
		} elseif ( in_array( $verdict, array( 'FAIL', 'PARTIAL', 'NEUTRAL' ), true ) ) {
			$state = 'not_indexed';
		}
		return array(
			'state'      => $state,
			'coverage'   => $coverage,
			'verdict'    => $verdict,
			'last_crawl' => isset( $idx['lastCrawlTime'] ) ? $idx['lastCrawlTime'] : '',
		);
	}

	/**
	 * Indexing API: notifica o Google de que uma URL foi criada/atualizada.
	 * ATENÇÃO: oficialmente o Google só suporta este endpoint para páginas
	 * com schema JobPosting ou BroadcastEvent; para páginas comuns funciona
	 * na prática mas está fora do suporte oficial. Requer o escopo de indexação.
	 */
	public static function request_indexing( $url ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		if ( ! self::has_indexing_scope() ) {
			return new WP_Error( 'ce61_indexing_scope', __( 'Reconecte o Google em Integrações para habilitar o envio de indexação (novo escopo).', 'cluster-engine' ) );
		}
		$res = wp_remote_post( 'https://indexing.googleapis.com/v3/urlNotifications:publish', array(
			'timeout' => 25,
			'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'url'  => $url,
				'type' => 'URL_UPDATED',
			) ),
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code ) {
			return new WP_Error( 'ce61_indexing', isset( $body['error']['message'] ) ? $body['error']['message'] : ( 'Indexing API HTTP ' . $code ) );
		}
		return true;
	}
}

/* ---- admin-post: início do OAuth e callback (fora de admin-ajax porque é redirect real) ---- */

add_action( 'admin_post_ce61_google_connect', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sem permissão.', 'cluster-engine' ) );
	}
	check_admin_referer( 'ce61_google_connect' );
	$url = CE61_Gsc::auth_url();
	if ( ! $url ) {
		wp_safe_redirect( add_query_arg( 'ce61_google', 'no_client', admin_url( 'admin.php?page=cluster-engine-settings&ce-tab=integrations' ) ) );
		exit;
	}
	wp_redirect( $url ); // domínio externo: wp_redirect, não wp_safe_redirect.
	exit;
} );

add_action( 'admin_post_ce61_google_callback', function () {
	$back = admin_url( 'admin.php?page=cluster-engine-settings&ce-tab=integrations' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sem permissão.', 'cluster-engine' ) );
	}
	$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
	if ( ! $state || ! get_transient( 'ce61_google_state_' . $state ) ) {
		wp_safe_redirect( add_query_arg( 'ce61_google', 'bad_state', $back ) );
		exit;
	}
	delete_transient( 'ce61_google_state_' . $state );

	if ( ! empty( $_GET['error'] ) ) {
		wp_safe_redirect( add_query_arg( 'ce61_google', 'denied', $back ) );
		exit;
	}
	$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
	if ( ! $code ) {
		wp_safe_redirect( add_query_arg( 'ce61_google', 'no_code', $back ) );
		exit;
	}
	$result = CE61_Gsc::exchange_code( $code );
	if ( is_wp_error( $result ) ) {
		wp_safe_redirect( add_query_arg( array( 'ce61_google' => 'error', 'msg' => rawurlencode( $result->get_error_message() ) ), $back ) );
		exit;
	}
	wp_safe_redirect( add_query_arg( 'ce61_google', 'connected', $back ) );
	exit;
} );
