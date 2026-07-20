<?php
/**
 * CE61_Queue — fila de execução de jobs de IA via WP-Cron.
 *
 * Jobs (ex.: geração de artigos em massa) são gravados na tabela
 * wp_ce_queue e processados em lotes pelo evento ce61_queue_tick,
 * agendado a cada minuto no cron nativo do WordPress.
 *
 * @package ClusterEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CE61_Queue {

	const HOOK      = 'ce61_queue_tick';
	const SCHEDULE  = 'ce61_every_minute';
	const PER_TICK  = 2;   // jobs por execução do cron (IA é lenta; evita timeout).
	const MAX_TRIES = 3;

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
	}

	public static function schedules( $schedules ) {
		if ( ! isset( $schedules[ self::SCHEDULE ] ) ) {
			$schedules[ self::SCHEDULE ] = array(
				'interval' => 60,
				'display'  => __( 'A cada minuto (Cluster Engine)', 'cluster-engine' ),
			);
		}
		return $schedules;
	}

	/**
	 * Garante o evento agendado (auto-recupera se alguém limpar o cron).
	 */
	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 30, self::SCHEDULE, self::HOOK );
		}
	}

	public static function unschedule() {
		$ts = wp_next_scheduled( self::HOOK );
		while ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK );
			$ts = wp_next_scheduled( self::HOOK );
		}
	}

	/* ---------- CRUD da fila ---------- */

	/**
	 * Enfileira um job. Retorna o ID do job ou WP_Error.
	 *
	 * @param string $type    Tipo do job (ex.: generate_article).
	 * @param array  $payload Dados do job.
	 */
	public static function add( $type, $payload ) {
		global $wpdb;
		$ok = $wpdb->insert(
			$wpdb->prefix . 'ce_queue',
			array(
				'job_type'   => sanitize_key( $type ),
				'payload'    => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
				'status'     => 'pending',
				'attempts'   => 0,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);
		if ( ! $ok ) {
			return new WP_Error( 'ce61_queue', __( 'Não foi possível enfileirar o job.', 'cluster-engine' ) );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Lista os jobs mais recentes + contadores por status.
	 */
	public static function overview( $limit = 60 ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}ce_queue ORDER BY id DESC LIMIT %d", $limit
		), ARRAY_A );
		$jobs = array();
		foreach ( $rows as $r ) {
			$r['payload'] = json_decode( $r['payload'], true );
			$r['result']  = $r['result'] ? json_decode( $r['result'], true ) : null;

			// Jobs de artigo concluídos: reflete o estado ATUAL do post
			// (título, url, slug e status de publicação, que podem ter mudado
			// desde a geração) e oculta os cujo post foi excluído/lixeira.
			if ( 'generate_article' === $r['job_type'] && 'done' === $r['status']
				&& is_array( $r['result'] ) && ! empty( $r['result']['post_id'] ) ) {
				$pid = (int) $r['result']['post_id'];
				$st  = get_post_status( $pid );
				if ( ! $st || 'trash' === $st ) {
					continue; // página excluída: não exibir mais na fila.
				}
				$r['result']['status'] = $st;
				$r['result']['title']  = get_the_title( $pid );
				$r['result']['view']   = get_permalink( $pid );
				$r['result']['slug']   = get_post_field( 'post_name', $pid );
				$r['result']['edit']   = admin_url( 'post.php?post=' . $pid . '&action=edit' );
			}
			$jobs[] = $r;
		}
		$rows = $jobs;
		$counts = array( 'pending' => 0, 'running' => 0, 'done' => 0, 'error' => 0, 'cancelled' => 0 );
		$agg = $wpdb->get_results( "SELECT status, COUNT(*) c FROM {$wpdb->prefix}ce_queue GROUP BY status", ARRAY_A );
		foreach ( $agg as $a ) {
			$counts[ $a['status'] ] = (int) $a['c'];
		}
		return array(
			'jobs'      => $rows,
			'counts'    => $counts,
			'next_tick' => wp_next_scheduled( self::HOOK ) ? wp_next_scheduled( self::HOOK ) - time() : null,
			'cron_off'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		);
	}

	public static function cancel( $id ) {
		global $wpdb;
		return (bool) $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}ce_queue SET status = 'cancelled' WHERE id = %d AND status IN ('pending','error')", $id
		) );
	}

	public static function retry( $id ) {
		global $wpdb;
		return (bool) $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}ce_queue SET status = 'pending', attempts = 0, error = NULL WHERE id = %d AND status IN ('error','cancelled')", $id
		) );
	}

	/**
	 * Remove jobs finalizados (done/error/cancelled).
	 */
	public static function clear_finished() {
		global $wpdb;
		return (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}ce_queue WHERE status IN ('done','error','cancelled')" );
	}

	/* ---------- Worker ---------- */

	/**
	 * Tick do cron: processa até PER_TICK jobs pendentes, com trava
	 * atômica (UPDATE condicional) contra execução dupla.
	 */
	public static function tick() {
		// Também libera jobs travados em "running" há mais de 10 min (processo morto).
		// Usa current_time() para casar com o fuso de started_at (horário do WP, não do MySQL).
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 10 * MINUTE_IN_SECONDS );
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}ce_queue SET status = 'pending' WHERE status = 'running' AND started_at < %s",
			$cutoff
		) );

		$start = time();
		for ( $i = 0; $i < self::PER_TICK; $i++ ) {
			// Guarda de tempo: não passar de ~50s por tick.
			if ( time() - $start > 50 ) {
				break;
			}
			if ( ! self::run_next() ) {
				break; // fila vazia.
			}
		}
	}

	/**
	 * Reivindica e executa o próximo job pendente. Retorna false se não havia job.
	 */
	public static function run_next() {
		global $wpdb;
		$job = $wpdb->get_row(
			"SELECT * FROM {$wpdb->prefix}ce_queue WHERE status = 'pending' ORDER BY id ASC LIMIT 1",
			ARRAY_A
		);
		if ( ! $job ) {
			return false;
		}
		// Trava atômica: só processa se ESTE processo mudou pending -> running.
		$claimed = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}ce_queue
			 SET status = 'running', attempts = attempts + 1, started_at = %s
			 WHERE id = %d AND status = 'pending'",
			current_time( 'mysql' ), $job['id']
		) );
		if ( ! $claimed ) {
			return true; // outro processo pegou; tenta o próximo no próximo loop.
		}

		$payload = json_decode( $job['payload'], true );
		$payload = is_array( $payload ) ? $payload : array();
		$result  = self::execute( $job['job_type'], $payload );

		if ( is_wp_error( $result ) ) {
			$attempts = (int) $job['attempts'] + 1;
			$final    = $attempts >= self::MAX_TRIES;
			$wpdb->update(
				$wpdb->prefix . 'ce_queue',
				array(
					'status'      => $final ? 'error' : 'pending',
					'error'       => mb_substr( $result->get_error_message(), 0, 1000 ),
					'finished_at' => $final ? current_time( 'mysql' ) : null,
				),
				array( 'id' => $job['id'] )
			);
		} else {
			$wpdb->update(
				$wpdb->prefix . 'ce_queue',
				array(
					'status'      => 'done',
					'result'      => wp_json_encode( $result, JSON_UNESCAPED_UNICODE ),
					'error'       => null,
					'finished_at' => current_time( 'mysql' ),
				),
				array( 'id' => $job['id'] )
			);
		}
		return true;
	}

	/**
	 * Dispatcher de tipos de job.
	 *
	 * @return array|WP_Error Resultado serializável do job.
	 */
	private static function execute( $type, $payload ) {
		switch ( $type ) {
			case 'generate_article':
				return CE61_Creator::job_generate_article( $payload );
			case 'generate_image':
				return self::job_generate_image( $payload );
			case 'convert_image':
				return self::job_convert_image( $payload );
			case 'schema_fix':
				return self::job_schema_fix( $payload );
			default:
				return new WP_Error( 'ce61_job', sprintf( __( 'Tipo de job desconhecido: %s', 'cluster-engine' ), $type ) );
		}
	}

	/**
	 * Job de imagem em fila: busca no banco de imagens (na ordem de
	 * prioridade configurada) ou gera por IA, conforme o modo escolhido
	 * ao enfileirar. Sem interação humana, então "banco de imagens" pega
	 * automaticamente o primeiro resultado relevante.
	 */
	private static function job_generate_image( $payload ) {
		$post_id = isset( $payload['post_id'] ) ? (int) $payload['post_id'] : 0;
		$source  = isset( $payload['source'] ) ? $payload['source'] : 'auto';
		$query   = isset( $payload['query'] ) ? trim( (string) $payload['query'] ) : '';
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new WP_Error( 'ce61_job', __( 'Post inválido para geração de imagem.', 'cluster-engine' ) );
		}
		if ( ! $query ) {
			$query = get_the_title( $post_id );
		}

		$use_stock = in_array( $source, array( 'stock', 'auto' ), true );
		if ( $use_stock ) {
			$found = CE61_Stock::auto_search( $query, 6 );
			if ( ! is_wp_error( $found ) && ! empty( $found['results'][0] ) ) {
				$attach = CE61_Stock::apply_to_post( $post_id, $found['results'][0] );
				if ( ! is_wp_error( $attach ) ) {
					return array( 'attachment_id' => $attach['attachment_id'], 'url' => $attach['url'], 'source' => 'stock:' . $found['provider'] );
				}
			}
			if ( 'stock' === $source ) {
				return is_wp_error( $found ) ? $found : new WP_Error( 'ce61_stock', __( 'Nenhum resultado nos bancos de imagens para este termo.', 'cluster-engine' ) );
			}
			// source === 'auto': banco de imagens falhou/sem resultado, cai para IA abaixo.
		}

		$prompt = CE61_Images::resolve_prompt( $post_id );
		$bytes  = CE61_Images::generate( $prompt );
		if ( is_wp_error( $bytes ) ) {
			return $bytes;
		}
		$settings = get_option( 'ce61_settings', array() );
		$bytes    = CE61_Images::apply_watermark( $bytes );
		$attach   = CE61_Images::attach_as_featured( $post_id, $bytes );
		if ( is_wp_error( $attach ) ) {
			return $attach;
		}
		return array( 'attachment_id' => $attach['attachment_id'], 'url' => $attach['url'], 'source' => 'ai' );
	}

	/**
	 * Job de conversão de imagem em massa: converte UM anexo (imagem de artigo)
	 * para WebP mantendo o original e repondo as referências. Idempotente.
	 */
	private static function job_convert_image( $payload ) {
		$attach_id = isset( $payload['attachment_id'] ) ? (int) $payload['attachment_id'] : 0;
		if ( ! $attach_id ) {
			return new WP_Error( 'ce61_job', __( 'Anexo inválido para conversão.', 'cluster-engine' ) );
		}
		$result = CE61_Media::convert_attachment( $attach_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array( 'new_id' => (int) $result['new_id'], 'url' => $result['url'], 'saved_bytes' => isset( $result['saved_bytes'] ) ? (int) $result['saved_bytes'] : 0 );
	}

	/**
	 * Job de correção de schema em massa: insere Article/BlogPosting ou gera
	 * FAQ + FAQPage via IA, conforme o modo escolhido ao enfileirar. Reaudita
	 * a página no fim para o painel refletir a correção.
	 */
	private static function job_schema_fix( $payload ) {
		$post_id = isset( $payload['post_id'] ) ? (int) $payload['post_id'] : 0;
		$mode    = isset( $payload['mode'] ) ? $payload['mode'] : 'article';
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new WP_Error( 'ce61_job', __( 'Post inválido para correção de schema.', 'cluster-engine' ) );
		}

		if ( 'faq' === $mode ) {
			$ai = CE61_AI::run( 'faq_schema', $post_id );
			if ( is_wp_error( $ai ) ) {
				return $ai;
			}
			$result = CE61_Schema::apply_faq( $post_id, $ai );
		} elseif ( 'repair' === $mode ) {
			$result = CE61_Schema::repair_content_schema( $post_id );
		} else {
			$result = CE61_Schema::insert_article_schema( $post_id );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		CE61_Schema::audit_post( $post_id );
		return array( 'mode' => $mode, 'status' => is_string( $result ) ? $result : 'inserted' );
	}
}
