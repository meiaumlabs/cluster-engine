<?php
/**
 * CE61_History — snapshot diário de desempenho (série temporal) e log de
 * datas de atualização de conteúdo, para o gráfico "antes/depois" na
 * página Desempenho.
 *
 * Separação de responsabilidade deliberada:
 *  - Cache "ao vivo" (options ce61_gsc_cache/ce61_ga4_cache, postmeta
 *    _ce61_serp) = o que a tabela da página Desempenho mostra agora,
 *    sobrescrito a cada clique em "Atualizar dados".
 *  - wp_ce_perf_history (esta classe) = série temporal append-only,
 *    1 linha por post por dia, alimentada 1x/dia pelo cron no horário
 *    configurado. Nunca é reescrita — só ganha linhas novas.
 *
 * @package ClusterEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CE61_History {

	const HOOK = 'ce61_daily_snapshot';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run_daily_snapshot' ) );
		self::ensure_scheduled();
	}

	/**
	 * Agenda o evento diário no horário configurado (Configurações →
	 * Integrações → Automação). Idempotente: só reagenda se o horário
	 * salvo mudou desde o último agendamento.
	 */
	public static function ensure_scheduled() {
		$settings = get_option( 'ce61_settings', array() );
		$time     = isset( $settings['perf_cron_time'] ) && $settings['perf_cron_time'] ? $settings['perf_cron_time'] : '03:00';
		$stored   = get_option( 'ce61_perf_cron_time_scheduled', '' );

		if ( $stored === $time && wp_next_scheduled( self::HOOK ) ) {
			return; // já agendado no horário certo.
		}
		self::unschedule();

		list( $h, $m ) = array_pad( explode( ':', $time ), 2, '0' );
		$ts = strtotime( sprintf( '%02d:%02d:00', (int) $h, (int) $m ) );
		if ( ! $ts || $ts < time() ) {
			$ts = strtotime( '+1 day', $ts ? $ts : time() );
		}
		// strtotime() acima usa o fuso do servidor; converte para o timestamp
		// correto respeitando o fuso configurado no WordPress.
		$gmt_ts = $ts - ( (int) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );

		wp_schedule_event( $gmt_ts, 'daily', self::HOOK );
		update_option( 'ce61_perf_cron_time_scheduled', $time, false );
	}

	public static function unschedule() {
		$ts = wp_next_scheduled( self::HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK );
		}
	}

	/* ---------- Log de datas de atualização de conteúdo ---------- */

	/**
	 * Marca "hoje" como data de atualização deste post (1x por dia, mantém
	 * as últimas 60 datas para não crescer sem limite).
	 */
	public static function log_update( $post_id ) {
		$today = current_time( 'Y-m-d' );
		$log   = json_decode( (string) get_post_meta( $post_id, '_ce61_update_log', true ), true );
		$log   = is_array( $log ) ? $log : array();
		if ( ! in_array( $today, $log, true ) ) {
			$log[] = $today;
			$log   = array_slice( $log, -60 );
			update_post_meta( $post_id, '_ce61_update_log', wp_json_encode( $log ) );
		}
	}

	public static function update_dates( $post_id, $since = null ) {
		$log = json_decode( (string) get_post_meta( $post_id, '_ce61_update_log', true ), true );
		$log = is_array( $log ) ? $log : array();
		if ( $since ) {
			$log = array_values( array_filter( $log, function ( $d ) use ( $since ) { return $d >= $since; } ) );
		}
		return $log;
	}

	/* ---------- Snapshot diário (worker do cron) ---------- */

	/**
	 * Roda 1x/dia: grava clicks/impressions/ctr/posição do Search Console e
	 * sessões do GA4 (dados do último dia disponível) para TODOS os posts
	 * indexados, e checa a posição SERP de um lote rotativo de posts
	 * (orçamento diário configurável) para não estourar a cota das APIs.
	 */
	public static function run_daily_snapshot() {
		global $wpdb;
		$p    = $wpdb->prefix;
		$date = gmdate( 'Y-m-d', current_time( 'timestamp' ) - 2 * DAY_IN_SECONDS ); // GSC normalmente só tem dados completos com ~2 dias de atraso.

		$gsc_by_path = array();
		$ga4_by_path = array();

		if ( CE61_Gsc::is_connected() ) {
			$gsc = CE61_Gsc::search_analytics_range( $date, $date );
			if ( ! is_wp_error( $gsc ) ) {
				$gsc_by_path = $gsc;
			}
			$ga4 = CE61_Gsc::ga4_sessions_range( $date, $date );
			if ( ! is_wp_error( $ga4 ) ) {
				$ga4_by_path = $ga4;
			}
		}

		// Lote de SERP do dia, priorizando quem está checado há mais tempo (ou nunca).
		$settings = get_option( 'ce61_settings', array() );
		$budget   = isset( $settings['serp_daily_budget'] ) ? max( 0, (int) $settings['serp_daily_budget'] ) : 20;
		$serp_ids = $budget && CE61_Serp::has_key() ? self::next_serp_batch( $budget ) : array();
		$serp_results = array();
		foreach ( $serp_ids as $pid ) {
			$kw = $wpdb->get_var( $wpdb->prepare( "SELECT main_keyword FROM {$p}ce_index WHERE post_id = %d", $pid ) );
			if ( ! $kw ) {
				continue;
			}
			$r = CE61_Serp::check( $kw );
			if ( ! is_wp_error( $r ) ) {
				CE61_Serp::set_cached( $pid, $r );
				$serp_results[ $pid ] = $r['position'];
			}
		}

		$rows = $wpdb->get_results( "SELECT post_id FROM {$p}ce_index", ARRAY_A );
		foreach ( $rows as $r ) {
			$pid  = (int) $r['post_id'];
			$path = untrailingslashit( strtolower( (string) wp_parse_url( get_permalink( $pid ), PHP_URL_PATH ) ) );
			$gsc  = isset( $gsc_by_path[ $path ] ) ? $gsc_by_path[ $path ] : null;
			$ga4  = isset( $ga4_by_path[ $path ] ) ? $ga4_by_path[ $path ] : null;
			$serp = isset( $serp_results[ $pid ] ) ? $serp_results[ $pid ] : null;

			if ( null === $gsc && null === $ga4 && null === $serp ) {
				continue; // nada de novo para este post hoje: não grava linha vazia.
			}
			$wpdb->replace(
				$p . 'ce_perf_history',
				array(
					'post_id'       => $pid,
					'snap_date'     => $date,
					'clicks'        => $gsc ? $gsc['clicks'] : null,
					'impressions'   => $gsc ? $gsc['impressions'] : null,
					'ctr'           => $gsc ? $gsc['ctr'] : null,
					'gsc_position'  => $gsc ? $gsc['position'] : null,
					'serp_position' => $serp,
					'ga4_sessions'  => $ga4,
				),
				array( '%d', '%s', '%d', '%d', '%f', '%f', '%d', '%d' )
			);
		}
		update_option( 'ce61_last_daily_snapshot', current_time( 'mysql' ), false );
	}

	/**
	 * Próximos N posts a checar no SERP hoje: os nunca checados primeiro,
	 * depois os checados há mais tempo — garante que todo post é coberto
	 * ao longo dos dias sem estourar a cota diária da API.
	 */
	private static function next_serp_batch( $n ) {
		global $wpdb;
		$p    = $wpdb->prefix;
		$rows = $wpdb->get_results( "SELECT post_id FROM {$p}ce_index", ARRAY_A );
		$dated = array();
		foreach ( $rows as $r ) {
			$pid  = (int) $r['post_id'];
			$meta = get_post_meta( $pid, '_ce61_serp', true );
			$data = $meta ? json_decode( $meta, true ) : null;
			$ts   = ( $data && ! empty( $data['checked_at'] ) ) ? strtotime( $data['checked_at'] ) : 0;
			$dated[] = array( 'id' => $pid, 'ts' => $ts );
		}
		usort( $dated, function ( $a, $b ) { return $a['ts'] - $b['ts']; } );
		return wp_list_pluck( array_slice( $dated, 0, $n ), 'id' );
	}

	/* ---------- Notas de insight salvas (para comparação posterior) ---------- */

	/**
	 * Salva o texto de um insight de IA junto com uma foto das métricas do
	 * momento (cliques, posição, sessões...), para depois comparar se o
	 * que foi sugerido de fato mudou o desempenho.
	 */
	public static function save_insight( $post_id, $text, $metrics ) {
		$notes = json_decode( (string) get_post_meta( $post_id, '_ce61_insight_notes', true ), true );
		$notes = is_array( $notes ) ? $notes : array();
		$notes[] = array(
			'date'    => current_time( 'mysql' ),
			'text'    => wp_strip_all_tags( (string) $text ),
			'metrics' => $metrics,
		);
		$notes = array_slice( $notes, -20 ); // mantém as 20 mais recentes.
		update_post_meta( $post_id, '_ce61_insight_notes', wp_json_encode( $notes, JSON_UNESCAPED_UNICODE ) );
		return $notes;
	}

	/**
	 * Notas salvas, mais recente primeiro.
	 */
	public static function get_insights( $post_id ) {
		$notes = json_decode( (string) get_post_meta( $post_id, '_ce61_insight_notes', true ), true );
		$notes = is_array( $notes ) ? $notes : array();
		return array_reverse( $notes );
	}

	public static function delete_insight( $post_id, $index ) {
		$notes = json_decode( (string) get_post_meta( $post_id, '_ce61_insight_notes', true ), true );
		$notes = is_array( $notes ) ? $notes : array();
		// $index é a posição na lista invertida (mais recente primeiro) mostrada na UI.
		$real_index = count( $notes ) - 1 - (int) $index;
		if ( isset( $notes[ $real_index ] ) ) {
			unset( $notes[ $real_index ] );
			update_post_meta( $post_id, '_ce61_insight_notes', wp_json_encode( array_values( $notes ), JSON_UNESCAPED_UNICODE ) );
		}
	}

	/* ---------- Leitura para o gráfico ---------- */

	/**
	 * Série histórica de um post nos últimos $days dias, mais as datas de
	 * atualização de conteúdo dentro da mesma janela (para os marcadores).
	 */
	public static function get_history( $post_id, $days = 30 ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT snap_date, clicks, impressions, ctr, gsc_position, serp_position, ga4_sessions
			 FROM {$wpdb->prefix}ce_perf_history WHERE post_id = %d AND snap_date >= %s ORDER BY snap_date ASC",
			$post_id, $since
		), ARRAY_A );
		return array(
			'points'  => $rows,
			'updates' => self::update_dates( $post_id, $since ),
		);
	}
}
