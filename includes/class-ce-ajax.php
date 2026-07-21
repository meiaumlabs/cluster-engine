<?php
/**
 * CE61_Ajax — all admin-side endpoints.
 *
 * @package ClusterEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CE61_Ajax {

	public static function init() {
		$actions = array(
			'scan_index', 'scan_relations', 'scan_cluster', 'scan_finalize',
			'dashboard', 'clusters', 'cluster_detail', 'links', 'diagnostics', 'keywords', 'report',
			'ai_run', 'test_ai', 'apply_meta', 'dismiss_relation', 'create_draft', 'insert_link',
			'merge_apply', 'schema_scan', 'schema_results', 'schema_fix_article', 'schema_fix_faq', 'schema_post_types', 'schema_queue_add', 'schema_remove', 'schema_repair',
			'headings_preview', 'apply_headings',
			'images_list', 'image_prompt', 'image_generate', 'images_queue_add',
			'image_generate_inline', 'images_convert_list', 'image_convert', 'images_convert_queue_add',
			'image_presets', 'image_preset_save', 'image_preset_delete', 'image_reference_upload', 'image_errors',
			'stock_search', 'stock_apply', 'stock_status',
			'save_settings', 'save_prompts', 'reset_prompt', 'rename_cluster', 'set_pillar',
			'creator_data', 'suggest_clusters', 'create_cluster', 'delete_cluster',
			'cluster_plan', 'remove_topic', 'generate_now', 'improve_prompt', 'ai_posts_list', 'post_eeat', 'set_publish', 'creator_fix_issue',
			'editor_improve', 'editor_diagnostics', 'editor_apply', 'editor_log', 'editor_revert', 'editor_fix',
			'queue_add', 'queue_list', 'queue_cancel', 'queue_retry', 'queue_clear', 'queue_run_now',
			'performance_data', 'performance_refresh_batch', 'performance_serp_one', 'index_status_batch', 'index_request_batch', 'index_status_one', 'index_request_one',
			'performance_history', 'performance_insight', 'performance_insight_save', 'performance_insight_list', 'performance_insight_delete',
			'google_status', 'google_disconnect', 'google_list_sites', 'google_list_ga4', 'google_test_gsc', 'google_test_ga4',
			'keyword_network',
			'cpt_list', 'cpt_toggle', 'cpt_generate',
			'categories_list', 'categories_review_posts', 'category_analyze_post', 'category_apply',
			'categories_suggest', 'category_create', 'category_generate_seo', 'category_save_seo', 'category_generate_image',
			'redirects_list', 'redirect_add', 'redirect_delete', 'redirect_toggle',
		);
		foreach ( $actions as $a ) {
			add_action( 'wp_ajax_ce61_' . $a, array( __CLASS__, $a ) );
		}
	}

	private static function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sem permissão.', 'cluster-engine' ) ), 403 );
		}
		check_ajax_referer( 'ce61_nonce', 'nonce' );
	}

	/* ---------- Scanning pipeline ---------- */

	public static function scan_index() {
		self::guard();
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		wp_send_json_success( CE61_Indexer::index_batch( $offset, 20 ) );
	}

	public static function scan_relations() {
		self::guard();
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		if ( 0 === $offset ) {
			global $wpdb;
			$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}ce_relations" );
		}
		wp_send_json_success( CE61_Analyzer::relations_batch( $offset, 15 ) );
	}

	public static function scan_cluster() {
		self::guard();
		wp_send_json_success( array( 'clusters' => CE61_Analyzer::cluster() ) );
	}

	public static function scan_finalize() {
		self::guard();
		CE61_Analyzer::finalize();
		update_option( 'ce61_last_scan', current_time( 'mysql' ) );
		wp_send_json_success( array( 'ok' => true ) );
	}

	/* ---------- Data ---------- */

	public static function dashboard() {
		self::guard();
		global $wpdb;
		$p = $wpdb->prefix;

		$posts     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ce_index" );
		$clusters  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ce_clusters" );
		$orphans   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ce_index WHERE inbound = 0" );
		$unclustered = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ce_index WHERE cluster_id = 0" );
		$links     = 0;
		$rows      = $wpdb->get_col( "SELECT links_out FROM {$p}ce_index" );
		foreach ( $rows as $r ) {
			$l = json_decode( $r, true );
			$links += is_array( $l ) ? count( $l ) : 0;
		}
		$no_title = 0; $no_desc = 0;
		$ids = $wpdb->get_col( "SELECT post_id FROM {$p}ce_index" );
		foreach ( $ids as $id ) {
			if ( ! CE61_SEO::get_meta_title( $id ) ) { $no_title++; }
			if ( ! CE61_SEO::get_meta_desc( $id ) )  { $no_desc++; }
		}
		$avg_seo = (int) $wpdb->get_var( "SELECT ROUND(AVG(seo_score)) FROM {$p}ce_index" );
		$avg_aeo = (int) $wpdb->get_var( "SELECT ROUND(AVG(aeo_score)) FROM {$p}ce_index" );
		$site    = $clusters ? (int) $wpdb->get_var( "SELECT ROUND(AVG(score)) FROM {$p}ce_clusters" ) : 0;

		$opps      = count( CE61_Analyzer::link_opportunities( 500 ) );
		$senseless = count( CE61_Analyzer::senseless_links( 500 ) );
		$cannibal  = count( CE61_Analyzer::cannibalization( 200 ) );

		wp_send_json_success( array(
			'site_score'  => $site,
			'posts'       => $posts,
			'clusters'    => $clusters,
			'orphans'     => $orphans,
			'unclustered' => $unclustered,
			'links'       => $links,
			'opps'        => $opps,
			'senseless'   => $senseless,
			'cannibal'    => $cannibal,
			'no_title'    => $no_title,
			'no_desc'     => $no_desc,
			'avg_seo'     => $avg_seo,
			'avg_aeo'     => $avg_aeo,
			'seo_plugin'  => CE61_SEO::plugin_label(),
			'last_scan'   => get_option( 'ce61_last_scan', '' ),
		) );
	}

	public static function clusters() {
		self::guard();
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}ce_clusters ORDER BY score DESC", ARRAY_A );
		foreach ( $rows as &$r ) {
			$r['pillar_title'] = $r['pillar_id'] ? get_the_title( (int) $r['pillar_id'] ) : '';
			$r['top_terms']    = json_decode( $r['top_terms'], true );
			$r['score_parts']  = json_decode( $r['score_parts'], true );
		}
		wp_send_json_success( array( 'clusters' => $rows ) );
	}

	public static function cluster_detail() {
		self::guard();
		global $wpdb;
		$cid = isset( $_POST['cluster_id'] ) ? absint( $_POST['cluster_id'] ) : 0;
		$c   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ce_clusters WHERE id = %d", $cid ), ARRAY_A );
		if ( ! $c ) {
			wp_send_json_error( array( 'message' => __( 'Cluster não encontrado.', 'cluster-engine' ) ) );
		}
		$c['top_terms']   = json_decode( $c['top_terms'], true );
		$c['score_parts'] = json_decode( $c['score_parts'], true );

		$members = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id, title, word_count, main_keyword, inbound, is_pillar, seo_score, aeo_score, issues
			 FROM {$wpdb->prefix}ce_index WHERE cluster_id = %d ORDER BY is_pillar DESC, inbound DESC", $cid
		), ARRAY_A );
		foreach ( $members as &$m ) {
			$m['issues']    = json_decode( $m['issues'], true );
			$m['edit_link'] = get_edit_post_link( (int) $m['post_id'], 'raw' );
			$m['permalink'] = get_permalink( (int) $m['post_id'] );
			$m['meta_title'] = CE61_SEO::get_meta_title( (int) $m['post_id'] );
			$m['meta_desc']  = CE61_SEO::get_meta_desc( (int) $m['post_id'] );
		}
		wp_send_json_success( array( 'cluster' => $c, 'members' => $members ) );
	}

	public static function links() {
		self::guard();
		wp_send_json_success( array(
			'opportunities' => self::with_slugs( CE61_Analyzer::link_opportunities( 100 ) ),
			'senseless'     => self::with_slugs( CE61_Analyzer::senseless_links( 100 ) ),
			'cannibal'      => self::with_slugs( CE61_Analyzer::cannibalization( 50 ) ),
		) );
	}

	/**
	 * Enriquece pares de links com o slug (post_name) de cada post,
	 * exibido abaixo do título na aba de Linkagem interna.
	 */
	private static function with_slugs( $rows ) {
		foreach ( $rows as &$r ) {
			$r['slug_a'] = ! empty( $r['post_a'] ) ? get_post_field( 'post_name', (int) $r['post_a'] ) : '';
			$r['slug_b'] = ! empty( $r['post_b'] ) ? get_post_field( 'post_name', (int) $r['post_b'] ) : '';
			$r['url_a']  = ! empty( $r['post_a'] ) ? get_permalink( (int) $r['post_a'] ) : '';
			$r['url_b']  = ! empty( $r['post_b'] ) ? get_permalink( (int) $r['post_b'] ) : '';
		}
		unset( $r );
		return $rows;
	}

	public static function keywords() {
		self::guard();
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT post_id, title, main_keyword, keywords, cluster_id FROM {$wpdb->prefix}ce_index ORDER BY inbound DESC", ARRAY_A );
		$map  = array();
		foreach ( $rows as $r ) {
			$ks = json_decode( $r['keywords'], true );
			if ( ! is_array( $ks ) ) { continue; }
			foreach ( array_slice( $ks, 0, 8 ) as $k ) {
				if ( ! isset( $map[ $k ] ) ) {
					$map[ $k ] = array( 'term' => $k, 'count' => 0, 'posts' => array() );
				}
				$map[ $k ]['count']++;
				if ( count( $map[ $k ]['posts'] ) < 5 ) {
					$map[ $k ]['posts'][] = array( 'id' => (int) $r['post_id'], 'title' => $r['title'] );
				}
			}
		}
		usort( $map, function ( $a, $b ) { return $b['count'] - $a['count']; } );

		// Duplicates expanded with post titles + edit links so the UI can act on them.
		$dups = CE61_SEO::duplicates();
		foreach ( array( 'title', 'kw' ) as $type ) {
			foreach ( $dups[ $type ] as &$group ) {
				$ids   = array_filter( array_map( 'absint', explode( ',', (string) $group['ids'] ) ) );
				$posts = array();
				foreach ( $ids as $id ) {
					$posts[] = array(
						'id'    => $id,
						'title' => get_the_title( $id ),
						'edit'  => get_edit_post_link( $id, 'raw' ),
					);
				}
				$group['posts'] = $posts;
				unset( $group['ids'] );
			}
			unset( $group );
		}

		wp_send_json_success( array(
			'keywords'   => array_slice( array_values( $map ), 0, 150 ),
			'duplicates' => $dups,
		) );
	}

	public static function diagnostics() {
		self::guard();
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT post_id, title, issues, seo_score, aeo_score FROM {$wpdb->prefix}ce_index WHERE issues IS NOT NULL", ARRAY_A );
		$by   = array();
		foreach ( $rows as $r ) {
			$issues = json_decode( $r['issues'], true );
			if ( ! is_array( $issues ) ) { continue; }
			foreach ( $issues as $i ) {
				if ( ! isset( $by[ $i ] ) ) { $by[ $i ] = array(); }
				$by[ $i ][] = array(
					'id'    => (int) $r['post_id'],
					'title' => $r['title'],
					'edit'  => get_edit_post_link( (int) $r['post_id'], 'raw' ),
				);
			}
		}
		wp_send_json_success( array( 'issues' => $by ) );
	}

	/* ---------- Actions ---------- */

	/**
	 * Insights compiler: crosses everything analyzed (clusters, links, SEO,
	 * AEO, headings) into a prioritized action plan with affected pages.
	 */
	public static function report() {
		self::guard();
		global $wpdb;
		$p = $wpdb->prefix;

		// Aggregate per-post issues with the affected pages.
		$rows = $wpdb->get_results( "SELECT post_id, title, issues FROM {$p}ce_index WHERE issues IS NOT NULL", ARRAY_A );
		$by   = array();
		foreach ( $rows as $r ) {
			$list = json_decode( $r['issues'], true );
			if ( ! is_array( $list ) ) {
				continue;
			}
			foreach ( $list as $i ) {
				if ( ! isset( $by[ $i ] ) ) {
					$by[ $i ] = array();
				}
				$by[ $i ][] = array(
					'id'    => (int) $r['post_id'],
					'title' => $r['title'],
					'edit'  => get_edit_post_link( (int) $r['post_id'], 'raw' ),
					'view'  => get_permalink( (int) $r['post_id'] ),
				);
			}
		}
		$count = function ( $key ) use ( $by ) { return isset( $by[ $key ] ) ? count( $by[ $key ] ) : 0; };
		$posts = function ( $key, $n = 10 ) use ( $by ) { return isset( $by[ $key ] ) ? array_slice( $by[ $key ], 0, $n ) : array(); };

		$opps      = CE61_Analyzer::link_opportunities( 500 );
		$senseless = CE61_Analyzer::senseless_links( 500 );
		$cannibal  = CE61_Analyzer::cannibalization( 200 );

		$clusters   = $wpdb->get_results( "SELECT * FROM {$p}ce_clusters ORDER BY score ASC", ARRAY_A );
		$part_names = array( 'coverage' => 'Cobertura tópica', 'arch' => 'Arquitetura de links', 'aeo' => 'AEO/GEO', 'eeat' => 'E-E-A-T', 'hygiene' => 'Higiene' );
		$weak_clusters = array();
		foreach ( $clusters as $c ) {
			$parts   = json_decode( $c['score_parts'], true );
			$weakest = '';
			$min     = 101;
			if ( is_array( $parts ) ) {
				foreach ( $parts as $k => $v ) {
					if ( $v < $min ) {
						$min     = $v;
						$weakest = isset( $part_names[ $k ] ) ? $part_names[ $k ] : $k;
					}
				}
			}
			$weak_clusters[] = array(
				'id'            => (int) $c['id'],
				'name'          => $c['name'],
				'score'         => (int) $c['score'],
				'posts'         => (int) $c['post_count'],
				'pillar'        => $c['pillar_id'] ? get_the_title( (int) $c['pillar_id'] ) : '',
				'weakest'       => $weakest,
				'weakest_score' => ( 101 === $min ) ? null : $min,
			);
		}

		// Rules engine -> prioritized insights.
		$insights = array();
		$add = function ( $priority, $title, $why, $n, $goto, $pages = array(), $fix = '' ) use ( &$insights ) {
			if ( $n < 1 ) {
				return;
			}
			$insights[] = compact( 'priority', 'title', 'why', 'n', 'goto', 'pages', 'fix' );
		};

		$add( 'alta', 'Resolver canibalizações',
			'Posts quase idênticos dividem cliques e confundem o Google sobre qual rankear. Fundir com 301 consolida a autoridade numa URL só.',
			count( $cannibal ), 'links#ce-sec-cannibal' );

		$add( 'alta', 'Dar links de entrada aos posts órfãos',
			'Posts sem nenhum link interno de entrada quase não recebem autoridade nem rastreamento. Use as oportunidades de link para conectá-los ao cluster.',
			$count( 'orphan_no_inbound' ), 'links#ce-sec-opps', $posts( 'orphan_no_inbound' ) );

		$add( 'alta', 'Encorpar conteúdo fino',
			'Posts muito curtos são vistos como conteúdo commodity e derrubam a higiene do cluster. Expanda ou funda com um post irmão.',
			$count( 'thin_content' ), 'diagnostics#ce-issue-thin_content', $posts( 'thin_content' ) );

		$add( 'alta', 'Integrar posts fora de cluster',
			'Posts sem cluster não somam autoridade tópica a nenhum tema. Linke-os ao cluster mais próximo ou avalie se pertencem ao site.',
			$count( 'no_cluster' ), 'diagnostics#ce-issue-no_cluster', $posts( 'no_cluster' ) );

		$add( 'media', 'Implementar as oportunidades de linkagem interna',
			'Pares com alta afinidade semântica sem link entre si. Cada link implementado reforça o sinal do cluster.',
			count( $opps ), 'links#ce-sec-opps' );

		$add( 'media', 'Remover ou retrabalhar links sem sentido',
			'Links entre posts sem relação semântica diluem a autoridade que a linkagem deveria concentrar.',
			count( $senseless ), 'links#ce-sec-senseless' );

		$add( 'media', 'Preencher meta titles ausentes',
			'Sem meta title o Google improvisa o snippet, geralmente pior que o que você escreveria.',
			$count( 'no_meta_title' ), 'diagnostics#ce-issue-no_meta_title', $posts( 'no_meta_title' ), 'rewrite_title' );

		$add( 'media', 'Preencher meta descriptions ausentes',
			'A description é seu texto de venda na SERP; sem ela o CTR cai.',
			$count( 'no_meta_desc' ), 'diagnostics#ce-issue-no_meta_desc', $posts( 'no_meta_desc' ), 'rewrite_desc' );

		$add( 'media', 'Definir keywords foco ausentes',
			'A keyword foco declara a intenção do post para o plugin de SEO e pesa 5x na análise semântica do Cluster Engine.',
			$count( 'no_focus_keyword' ), 'diagnostics#ce-issue-no_focus_keyword', $posts( 'no_focus_keyword' ), 'suggest_keyword' );

		$add( 'media', 'Atualizar conteúdo desatualizado',
			'Frescor é sinal de qualidade para o Google e para as IAs de busca. Posts antigos sem revisão perdem citabilidade.',
			$count( 'stale_content' ), 'diagnostics#ce-issue-stale_content', $posts( 'stale_content' ), 'refresh_post' );

		$add( 'aeo', 'Adicionar answer capsules (AEO)',
			'Um parágrafo de abertura de 40-60 palavras respondendo à pergunta do título é o formato que AI Overviews mais cita.',
			$count( 'no_answer_capsule' ), 'diagnostics#ce-issue-no_answer_capsule', $posts( 'no_answer_capsule' ), 'answer_capsule' );

		$add( 'aeo', 'Adicionar seções de FAQ',
			'FAQs com schema FAQPage aumentam a chance de aparecer em People Also Ask e respostas de IA.',
			$count( 'no_faq' ), 'diagnostics#ce-issue-no_faq', $posts( 'no_faq' ), 'faq_schema' );

		$add( 'aeo', 'Corrigir capitalização dos headings',
			'Title Case e CAIXA ALTA são heranças do inglês; sentence case lê melhor e padroniza o site.',
			$count( 'capitalized_headings' ), 'diagnostics#ce-issue-capitalized_headings', $posts( 'capitalized_headings' ), 'HEADINGS' );

		$add( 'aeo', 'Citar fontes externas de autoridade',
			'Links para leis, estudos e fontes oficiais são sinal E-E-A-T e aumentam a confiança das IAs no conteúdo.',
			$count( 'no_external_sources' ), 'diagnostics#ce-issue-no_external_sources', $posts( 'no_external_sources' ) );

		$order = array( 'alta' => 0, 'media' => 1, 'aeo' => 2 );
		usort( $insights, function ( $a, $b ) use ( $order ) {
			if ( $order[ $a['priority'] ] !== $order[ $b['priority'] ] ) {
				return $order[ $a['priority'] ] - $order[ $b['priority'] ];
			}
			return $b['n'] - $a['n'];
		} );

		$site_score = $clusters ? (int) $wpdb->get_var( "SELECT ROUND(AVG(score)) FROM {$p}ce_clusters" ) : 0;

		wp_send_json_success( array(
			'generated_at' => date_i18n( get_option( 'date_format' ) . ' H:i' ),
			'site_name'    => get_bloginfo( 'name' ),
			'site_url'     => home_url(),
			'site_score'   => $site_score,
			'avg_seo'      => (int) $wpdb->get_var( "SELECT ROUND(AVG(seo_score)) FROM {$p}ce_index" ),
			'avg_aeo'      => (int) $wpdb->get_var( "SELECT ROUND(AVG(aeo_score)) FROM {$p}ce_index" ),
			'total_posts'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ce_index" ),
			'clusters'     => $weak_clusters,
			'insights'     => $insights,
		) );
	}

	/**
	 * Connection test: tiny completion, reports which provider actually answered.
	 */
	public static function test_ai() {
		self::guard();
		$settings = get_option( 'ce61_settings', array() );
		$provider = CE61_AI::effective_provider( $settings );
		if ( '' === $provider ) {
			wp_send_json_error( array( 'message' => __( 'Nenhuma chave de API configurada. Adicione a chave de pelo menos um provedor e salve.', 'cluster-engine' ) ) );
		}
		$result = CE61_AI::complete( '', 'Responda apenas com a palavra: ok', $settings );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		$names    = CE61_AI::provider_names();
		$fallback = ( isset( $settings['provider'] ) && $settings['provider'] !== $provider );
		wp_send_json_success( array(
			'provider' => $names[ $provider ],
			'fallback' => $fallback,
			'selected' => isset( $names[ $settings['provider'] ] ) ? $names[ $settings['provider'] ] : '',
		) );
	}

	public static function ai_run() {
		self::guard();
		$action  = isset( $_POST['ai_action'] ) ? sanitize_key( $_POST['ai_action'] ) : '';
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$extra   = array();
		if ( isset( $_POST['extra'] ) && is_array( $_POST['extra'] ) ) {
			foreach ( wp_unslash( $_POST['extra'] ) as $k => $v ) {
				$extra[ sanitize_key( $k ) ] = sanitize_textarea_field( $v );
			}
		}
		// Second post (e.g. cannibalization merge): expose its data as {{title_b}}, {{content_b}}, {{url_b}}.
		$second = isset( $_POST['second_post_id'] ) ? absint( $_POST['second_post_id'] ) : 0;
		if ( $second && ( $post_b = get_post( $second ) ) ) {
			$extra['title_b']   = $post_b->post_title;
			$extra['content_b'] = mb_substr( wp_strip_all_tags( strip_shortcodes( $post_b->post_content ) ), 0, 6000 );
			$extra['url_b']     = get_permalink( $second );
			// Anchor suggestions: feed the REAL most relevant paragraph of the source
			// post, so suggested anchors have a chance of existing in the text.
			if ( 'anchor_text' === $action && empty( $extra['source_paragraph'] ) ) {
				$extra['source_paragraph'] = self::best_paragraph( $post_id, $second );
				$extra['target_title']     = $post_b->post_title;
				if ( empty( $extra['target_keyword'] ) ) {
					global $wpdb;
					$extra['target_keyword'] = (string) $wpdb->get_var( $wpdb->prepare(
						"SELECT main_keyword FROM {$wpdb->prefix}ce_index WHERE post_id = %d", $second
					) );
				}
			}
		}
		$result = CE61_AI::run( $action, $post_id, $extra );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'result' => $result ) );
	}

	public static function apply_meta() {
		self::guard();
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$field   = isset( $_POST['field'] ) ? sanitize_key( $_POST['field'] ) : '';
		$value   = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';
		if ( ! $post_id || ! in_array( $field, array( 'title', 'desc', 'keyword' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Parâmetros inválidos.', 'cluster-engine' ) ) );
		}
		if ( 'title' === $field )   { CE61_SEO::set_meta_title( $post_id, $value ); }
		if ( 'desc' === $field )    { CE61_SEO::set_meta_desc( $post_id, $value ); }
		if ( 'keyword' === $field ) { CE61_SEO::set_focus_keyword( $post_id, $value ); }
		wp_send_json_success( array( 'ok' => true ) );
	}

	public static function dismiss_relation() {
		self::guard();
		global $wpdb;
		$a = isset( $_POST['post_a'] ) ? absint( $_POST['post_a'] ) : 0;
		$b = isset( $_POST['post_b'] ) ? absint( $_POST['post_b'] ) : 0;
		$wpdb->update( $wpdb->prefix . 'ce_relations', array( 'status' => 'dismissed' ), array( 'post_a' => $a, 'post_b' => $b ) );
		wp_send_json_success( array( 'ok' => true ) );
	}

	public static function create_draft() {
		self::guard();
		$title    = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$content  = isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : '';
		$kw       = isset( $_POST['focus_keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['focus_keyword'] ) ) : '';
		$source   = isset( $_POST['source_post_id'] ) ? absint( $_POST['source_post_id'] ) : 0;

		// Strip markdown fences the model may add; sanitize per capability.
		$content = preg_replace( '/^```(?:html)?\s*|\s*```$/i', '', trim( $content ) );
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$content = wp_kses_post( $content );
		}
		if ( ! $title || ! $content ) {
			wp_send_json_error( array( 'message' => __( 'Título e conteúdo são obrigatórios.', 'cluster-engine' ) ) );
		}
		$id = wp_insert_post( array(
			'post_title'   => $title,
			'post_content' => wp_slash( $content ),
			'post_status'  => 'draft',
			'post_type'    => 'post',
		) );
		if ( is_wp_error( $id ) ) {
			wp_send_json_error( array( 'message' => $id->get_error_message() ) );
		}

		// SEO completo desde o nascimento: keyword foco, meta title e description.
		if ( ! $kw && $source ) {
			global $wpdb;
			$kw = (string) $wpdb->get_var( $wpdb->prepare(
				"SELECT main_keyword FROM {$wpdb->prefix}ce_index WHERE post_id = %d", $source
			) );
		}
		if ( $kw ) {
			CE61_SEO::set_focus_keyword( $id, $kw );
		}
		CE61_SEO::set_meta_title( $id, mb_substr( $title, 0, 60 ) );
		$first = '';
		if ( preg_match( '/<p[^>]*>(.*?)<\/p>/is', $content, $m ) ) {
			$first = trim( wp_strip_all_tags( $m[1] ) );
		}
		if ( $first ) {
			CE61_SEO::set_meta_desc( $id, mb_substr( $first, 0, 155 ) );
		}

		wp_send_json_success( array(
			'id'      => $id,
			'edit'    => get_edit_post_link( $id, 'raw' ),
			'preview' => get_preview_post_link( $id ),
		) );
	}

	/**
	 * One-click merge apply: publish the unified draft, 301 both old posts
	 * to it, insert Article schema, and archive (trash) the originals.
	 */
	public static function merge_apply() {
		self::guard();
		$draft = isset( $_POST['draft_id'] ) ? absint( $_POST['draft_id'] ) : 0;
		$a     = isset( $_POST['post_a'] ) ? absint( $_POST['post_a'] ) : 0;
		$b     = isset( $_POST['post_b'] ) ? absint( $_POST['post_b'] ) : 0;

		$draft_post = get_post( $draft );
		if ( ! $draft_post || ! get_post( $a ) || ! get_post( $b ) ) {
			wp_send_json_error( array( 'message' => __( 'Posts não encontrados.', 'cluster-engine' ) ) );
		}

		// 1. Capture old paths BEFORE trashing (permalinks die with the trash).
		$paths = array();
		foreach ( array( $a, $b ) as $old ) {
			$p = wp_parse_url( get_permalink( $old ), PHP_URL_PATH );
			if ( $p ) {
				$paths[] = untrailingslashit( strtolower( $p ) );
			}
		}

		// 2. Publish the unified draft.
		$pub = wp_update_post( array( 'ID' => $draft, 'post_status' => 'publish' ), true );
		if ( is_wp_error( $pub ) ) {
			wp_send_json_error( array( 'message' => $pub->get_error_message() ) );
		}

		// 3. Register the 301 redirects.
		$redirects = get_option( 'ce61_redirects', array() );
		foreach ( $paths as $path ) {
			$redirects[ $path ] = $draft;
		}
		update_option( 'ce61_redirects', $redirects );

		// 4. Article schema on the published post (skipped if one already exists in content).
		CE61_Schema::insert_article_schema( $draft );

		// 5. Archive the originals (trash = reversible) and clean the index.
		wp_trash_post( $a );
		wp_trash_post( $b );
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}ce_index WHERE post_id IN (%d, %d)", $a, $b ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}ce_relations WHERE post_a IN (%d, %d) OR post_b IN (%d, %d)", $a, $b, $a, $b ) );

		wp_send_json_success( array(
			'view'      => get_permalink( $draft ),
			'edit'      => get_edit_post_link( $draft, 'raw' ),
			'redirects' => count( $paths ),
		) );
	}

	/* ---------- Schema module ---------- */

	public static function schema_post_types() {
		self::guard();
		wp_send_json_success( array( 'types' => CE61_Schema::indexed_post_types() ) );
	}

	public static function schema_scan() {
		self::guard();
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$types  = array();
		if ( isset( $_POST['types'] ) ) {
			$decoded = json_decode( wp_unslash( $_POST['types'] ), true );
			if ( is_array( $decoded ) ) {
				$types = array_map( 'sanitize_key', $decoded );
			}
		}
		$skip_recent = isset( $_POST['skip_recent'] ) ? absint( $_POST['skip_recent'] ) : 0;
		wp_send_json_success( CE61_Schema::audit_batch( $offset, 5, $types, $skip_recent ) );
	}

	public static function schema_results() {
		self::guard();
		wp_send_json_success( array( 'results' => CE61_Schema::results() ) );
	}

	public static function schema_fix_article() {
		self::guard();
		$pid    = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$result = CE61_Schema::insert_article_schema( $pid );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		CE61_Schema::audit_post( $pid ); // re-audit so the panel reflects the fix.
		wp_send_json_success( array( 'mode' => $result ) );
	}

	public static function schema_fix_faq() {
		self::guard();
		$pid = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$ai  = CE61_AI::run( 'faq_schema', $pid );
		if ( is_wp_error( $ai ) ) {
			wp_send_json_error( array( 'message' => $ai->get_error_message() ) );
		}
		$result = CE61_Schema::apply_faq( $pid, $ai );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		CE61_Schema::audit_post( $pid );
		wp_send_json_success( array( 'mode' => 'inserted' ) );
	}

	/**
	 * Remove o schema gerido pelo Cluster Engine desta página (campo do Rank
	 * Math + blocos JSON-LD no conteúdo) e re-audita.
	 */
	public static function schema_remove() {
		self::guard();
		$pid = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $pid || ! get_post( $pid ) ) {
			wp_send_json_error( array( 'message' => __( 'Página inválida.', 'cluster-engine' ) ) );
		}
		$result = CE61_Schema::remove_schema( $pid );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		CE61_Schema::audit_post( $pid );
		wp_send_json_success( array( 'mode' => $result ) );
	}

	/**
	 * Corrige o schema exposto no corpo de UMA página: move os blocos JSON-LD
	 * (ou o JSON "pelado", sem <script>) para o campo de schema e limpa o corpo.
	 */
	public static function schema_repair() {
		self::guard();
		$pid = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $pid || ! get_post( $pid ) ) {
			wp_send_json_error( array( 'message' => __( 'Página inválida.', 'cluster-engine' ) ) );
		}
		$r = CE61_Schema::repair_content_schema( $pid );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		CE61_Schema::audit_post( $pid );
		wp_send_json_success( array( 'moved' => $r['moved'], 'naked' => $r['naked'] ) );
	}

	/**
	 * Enfileira correções de schema em massa (Inserir Article, Gerar FAQ + schema
	 * ou Corrigir schema exposto) para os posts selecionados, processadas em
	 * segundo plano pelo WP-Cron.
	 */
	public static function schema_queue_add() {
		self::guard();
		$ids  = isset( $_POST['post_ids'] ) ? json_decode( wp_unslash( $_POST['post_ids'] ), true ) : null;
		$mode = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'article';
		if ( ! in_array( $mode, array( 'article', 'faq', 'repair' ), true ) ) {
			$mode = 'article';
		}
		if ( ! is_array( $ids ) || ! $ids ) {
			wp_send_json_error( array( 'message' => __( 'Nenhuma página selecionada.', 'cluster-engine' ) ) );
		}
		$labels = array(
			'faq'    => __( 'FAQ + schema', 'cluster-engine' ),
			'repair' => __( 'Corrigir schema exposto', 'cluster-engine' ),
		);
		$label = isset( $labels[ $mode ] ) ? $labels[ $mode ] : __( 'Inserir Article', 'cluster-engine' );
		$added = 0;
		foreach ( array_slice( $ids, 0, 200 ) as $pid ) {
			$pid = absint( $pid );
			if ( ! $pid || ! get_post( $pid ) ) {
				continue;
			}
			CE61_Queue::add( 'schema_fix', array(
				'post_id' => $pid,
				'mode'    => $mode,
				'title'   => get_the_title( $pid ) . ' — ' . $label,
			) );
			$added++;
		}
		wp_send_json_success( array( 'added' => $added ) );
	}

	/**
	 * The paragraph of $post_a most related to $post_b (by keyword overlap).
	 */
	private static function best_paragraph( $post_a, $post_b ) {
		global $wpdb;
		$post = get_post( $post_a );
		if ( ! $post ) {
			return '';
		}
		$keywords = json_decode( (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT keywords FROM {$wpdb->prefix}ce_index WHERE post_id = %d", $post_b
		) ), true );
		$keywords = is_array( $keywords ) ? $keywords : array();

		$paras = preg_split( '/<\/p>/i', $post->post_content );
		$best  = '';
		$score = -1;
		foreach ( $paras as $p ) {
			$text = trim( wp_strip_all_tags( $p ) );
			if ( mb_strlen( $text ) < 60 ) {
				continue;
			}
			$s   = 0;
			$low = mb_strtolower( $text );
			foreach ( $keywords as $k ) {
				if ( false !== mb_strpos( $low, mb_strtolower( $k ) ) ) {
					$s++;
				}
			}
			if ( $s > $score ) {
				$score = $s;
				$best  = $text;
			}
		}
		if ( '' === $best && isset( $paras[0] ) ) {
			$best = trim( wp_strip_all_tags( $paras[0] ) );
		}
		return mb_substr( $best, 0, 500 );
	}

	/**
	 * Insert an internal link from post A to post B with the chosen anchor.
	 * Strategy: wrap the anchor phrase if it exists in the text (outside existing
	 * links and headings); otherwise insert a "Leia também" after the most
	 * relevant paragraph. Updating the post triggers automatic reindexing.
	 */
	public static function insert_link() {
		self::guard();
		global $wpdb;
		$a      = isset( $_POST['post_a'] ) ? absint( $_POST['post_a'] ) : 0;
		$b      = isset( $_POST['post_b'] ) ? absint( $_POST['post_b'] ) : 0;
		$anchor = isset( $_POST['anchor'] ) ? sanitize_text_field( wp_unslash( $_POST['anchor'] ) ) : '';

		$post = get_post( $a );
		$url  = get_permalink( $b );
		if ( ! $post || ! $url || '' === $anchor ) {
			wp_send_json_error( array( 'message' => __( 'Parâmetros inválidos.', 'cluster-engine' ) ) );
		}

		$content = $post->post_content;
		if ( false !== strpos( $content, $url ) ) {
			self::mark_linked( $a, $b );
			wp_send_json_success( array( 'mode' => 'already', 'edit' => get_edit_post_link( $a, 'raw' ) ) );
		}

		// Protect existing links and headings from being altered.
		$placeholders = array();
		$protected    = preg_replace_callback(
			'/<a\s.*?<\/a>|<h[1-6][^>]*>.*?<\/h[1-6]>/is',
			function ( $m ) use ( &$placeholders ) {
				$key                  = '@@CE61PH' . count( $placeholders ) . '@@';
				$placeholders[ $key ] = $m[0];
				return $key;
			},
			$content
		);

		$mode = '';
		$pos  = mb_stripos( $protected, $anchor );
		if ( false !== $pos ) {
			// Wrap the existing phrase, preserving its original casing.
			$original  = mb_substr( $protected, $pos, mb_strlen( $anchor ) );
			$protected = mb_substr( $protected, 0, $pos )
				. '<a href="' . esc_url( $url ) . '">' . $original . '</a>'
				. mb_substr( $protected, $pos + mb_strlen( $anchor ) );
			$mode = 'wrapped';
		} else {
			// Insert a "Leia também" after the paragraph most related to post B.
			$link_html = "\n" . '<p><em>Leia também: <a href="' . esc_url( $url ) . '">' . esc_html( $anchor ) . '</a></em></p>';
			$keywords  = json_decode( (string) $wpdb->get_var( $wpdb->prepare(
				"SELECT keywords FROM {$wpdb->prefix}ce_index WHERE post_id = %d", $b
			) ), true );
			$keywords  = is_array( $keywords ) ? $keywords : array();

			if ( preg_match_all( '/<\/p>/i', $protected, $mm, PREG_OFFSET_CAPTURE ) ) {
				$paras      = preg_split( '/<\/p>/i', $protected );
				$best_index = 0;
				$best_score = -1;
				$count      = min( count( $paras ), count( $mm[0] ) );
				for ( $i = 0; $i < $count; $i++ ) {
					$text = mb_strtolower( wp_strip_all_tags( $paras[ $i ] ) );
					if ( mb_strlen( trim( $text ) ) < 60 ) {
						continue;
					}
					$s = 0;
					foreach ( $keywords as $k ) {
						if ( false !== mb_strpos( $text, mb_strtolower( $k ) ) ) {
							$s++;
						}
					}
					if ( $s > $best_score ) {
						$best_score = $s;
						$best_index = $i;
					}
				}
				$insert_at = $mm[0][ $best_index ][1] + 4; // right after that </p>.
				$protected = substr( $protected, 0, $insert_at ) . $link_html . substr( $protected, $insert_at );
			} else {
				$protected .= $link_html;
			}
			$mode = 'inserted';
		}

		$content = strtr( $protected, $placeholders );

		$result = wp_update_post( array( 'ID' => $a, 'post_content' => wp_slash( $content ) ), true );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		self::mark_linked( $a, $b );

		wp_send_json_success( array(
			'mode' => $mode,
			'edit' => get_edit_post_link( $a, 'raw' ),
			'view' => get_permalink( $a ),
		) );
	}

	/**
	 * Flag the relation as linked so it leaves the opportunities list.
	 */
	private static function mark_linked( $a, $b ) {
		global $wpdb;
		if ( $a < $b ) {
			$wpdb->update( $wpdb->prefix . 'ce_relations', array( 'link_ab' => 1 ), array( 'post_a' => $a, 'post_b' => $b ) );
		} else {
			$wpdb->update( $wpdb->prefix . 'ce_relations', array( 'link_ba' => 1 ), array( 'post_a' => $b, 'post_b' => $a ) );
		}
	}

	/* ---------- Headings capitalization ---------- */

	/**
	 * Extract headings, ask the AI for sentence-case versions, return before/after pairs.
	 */
	public static function headings_preview() {
		self::guard();
		$pid  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$post = get_post( $pid );
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => __( 'Post não encontrado.', 'cluster-engine' ) ) );
		}
		if ( ! preg_match_all( '/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', $post->post_content, $m ) ) {
			wp_send_json_error( array( 'message' => __( 'Este post não tem headings no conteúdo.', 'cluster-engine' ) ) );
		}
		$originals = array();
		foreach ( $m[1] as $h ) {
			$t = trim( wp_strip_all_tags( $h ) );
			if ( '' !== $t && ! in_array( $t, $originals, true ) ) {
				$originals[] = $t;
			}
			if ( count( $originals ) >= 25 ) {
				break;
			}
		}
		if ( ! $originals ) {
			wp_send_json_error( array( 'message' => __( 'Nenhum heading com texto encontrado.', 'cluster-engine' ) ) );
		}
		$numbered = array();
		foreach ( $originals as $i => $t ) {
			$numbered[] = ( $i + 1 ) . '. ' . $t;
		}
		$ai = CE61_AI::run( 'fix_headings', $pid, array( 'headings_list' => implode( "\n", $numbered ) ) );
		if ( is_wp_error( $ai ) ) {
			wp_send_json_error( array( 'message' => $ai->get_error_message() ) );
		}
		$lines = array_values( array_filter( array_map( function ( $l ) {
			return trim( preg_replace( '/^\s*\d+[\).\-]\s*/', '', $l ) );
		}, preg_split( '/\n+/', $ai ) ) ) );

		$pairs = array();
		foreach ( $originals as $i => $from ) {
			$to = isset( $lines[ $i ] ) ? $lines[ $i ] : $from;
			$pairs[] = array(
				'from'    => $from,
				'to'      => $to,
				'changed' => ( $from !== $to ),
			);
		}
		wp_send_json_success( array( 'pairs' => $pairs ) );
	}

	/**
	 * Apply approved heading replacements inside the post content.
	 */
	public static function apply_headings() {
		self::guard();
		$pid   = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$post  = get_post( $pid );
		$pairs = isset( $_POST['pairs'] ) ? json_decode( wp_unslash( $_POST['pairs'] ), true ) : null;
		if ( ! $post || ! is_array( $pairs ) || ! $pairs ) {
			wp_send_json_error( array( 'message' => __( 'Parâmetros inválidos.', 'cluster-engine' ) ) );
		}
		$map = array();
		foreach ( $pairs as $p ) {
			if ( ! empty( $p['from'] ) && isset( $p['to'] ) && $p['from'] !== $p['to'] ) {
				$map[ sanitize_text_field( $p['from'] ) ] = sanitize_text_field( $p['to'] );
			}
		}
		if ( ! $map ) {
			wp_send_json_error( array( 'message' => __( 'Nenhuma alteração para aplicar.', 'cluster-engine' ) ) );
		}
		$applied = 0;
		$content = preg_replace_callback(
			'/(<h[1-6][^>]*>)(.*?)(<\/h[1-6]>)/is',
			function ( $m ) use ( $map, &$applied ) {
				$text = trim( wp_strip_all_tags( $m[2] ) );
				if ( isset( $map[ $text ] ) ) {
					$applied++;
					return $m[1] . esc_html( $map[ $text ] ) . $m[3];
				}
				return $m[0];
			},
			$post->post_content
		);
		if ( ! $applied ) {
			wp_send_json_error( array( 'message' => __( 'Os headings não foram localizados no conteúdo (o post pode ter sido editado).', 'cluster-engine' ) ) );
		}
		$result = wp_update_post( array( 'ID' => $pid, 'post_content' => wp_slash( $content ) ), true );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'applied' => $applied, 'edit' => get_edit_post_link( $pid, 'raw' ) ) );
	}

	/* ---------- Featured images ---------- */

	public static function images_list() {
		self::guard();
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT post_id, title FROM {$wpdb->prefix}ce_index ORDER BY title ASC", ARRAY_A );
		$out  = array();
		foreach ( $rows as $r ) {
			$pid   = (int) $r['post_id'];
			$thumb = get_the_post_thumbnail_url( $pid, 'medium' );
			$full  = get_the_post_thumbnail_url( $pid, 'full' );
			$out[] = array(
				'post_id' => $pid,
				'title'   => $r['title'],
				'edit'    => get_edit_post_link( $pid, 'raw' ),
				'thumb'   => $thumb ? $thumb : '',
				'full'    => $full ? $full : '',
			);
		}
		wp_send_json_success( array( 'posts' => $out ) );
	}

	/**
	 * Resolved master prompt for a post (editable in the modal before generating).
	 */
	public static function image_prompt() {
		self::guard();
		$pid = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! get_post( $pid ) ) {
			wp_send_json_error( array( 'message' => __( 'Post não encontrado.', 'cluster-engine' ) ) );
		}
		$overrides = array();
		if ( isset( $_POST['style_presets'] ) ) {
			$overrides['style_presets'] = array_map( 'sanitize_key', (array) json_decode( wp_unslash( $_POST['style_presets'] ), true ) );
		}
		if ( isset( $_POST['aspect'] ) ) {
			$overrides['aspect'] = sanitize_key( $_POST['aspect'] );
		}
		wp_send_json_success( array( 'prompt' => CE61_Images::resolve_prompt( $pid, '', $overrides ) ) );
	}

	/**
	 * Generate → watermark → attach as featured image.
	 */
	public static function image_generate() {
		self::guard();
		@set_time_limit( 120 );
		$pid    = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$prompt = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		if ( ! get_post( $pid ) ) {
			wp_send_json_error( array( 'message' => __( 'Post não encontrado.', 'cluster-engine' ) ) );
		}
		$overrides = array();
		if ( ! empty( $_POST['model'] ) ) {
			$overrides['model'] = sanitize_text_field( wp_unslash( $_POST['model'] ) );
		}
		if ( ! empty( $_POST['aspect'] ) ) {
			$overrides['aspect'] = sanitize_key( $_POST['aspect'] );
		}
		if ( '' === trim( $prompt ) ) {
			$prompt = CE61_Images::resolve_prompt( $pid, '', $overrides );
		}
		$bytes = CE61_Images::generate( $prompt, $overrides );
		if ( is_wp_error( $bytes ) ) {
			wp_send_json_error( array( 'message' => $bytes->get_error_message() ) );
		}
		$bytes = CE61_Images::apply_watermark( $bytes );

		$result = CE61_Images::attach_as_featured( $pid, $bytes );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array(
			'url'           => $result['url'],
			'thumb'         => get_the_post_thumbnail_url( $pid, 'medium' ),
			'attachment_id' => isset( $result['attachment_id'] ) ? (int) $result['attachment_id'] : 0,
		) );
	}

	/**
	 * Enfileira geração de imagem (banco de imagens, IA, ou automático) para
	 * vários posts marcados na grade da aba Imagens.
	 */
	public static function images_queue_add() {
		self::guard();
		$ids    = isset( $_POST['post_ids'] ) ? json_decode( wp_unslash( $_POST['post_ids'] ), true ) : null;
		$source = isset( $_POST['source'] ) ? sanitize_key( $_POST['source'] ) : 'auto';
		if ( ! in_array( $source, array( 'ai', 'stock', 'auto' ), true ) ) {
			$source = 'auto';
		}
		if ( ! is_array( $ids ) || ! $ids ) {
			wp_send_json_error( array( 'message' => __( 'Nenhum post selecionado.', 'cluster-engine' ) ) );
		}
		$added = 0;
		foreach ( array_slice( $ids, 0, 200 ) as $pid ) {
			$pid = absint( $pid );
			if ( ! $pid || ! get_post( $pid ) ) {
				continue;
			}
			CE61_Queue::add( 'generate_image', array(
				'post_id' => $pid,
				'source'  => $source,
				'query'   => get_the_title( $pid ),
			) );
			$added++;
		}
		wp_send_json_success( array( 'added' => $added ) );
	}

	/* ---------- Imagens dentro do post (in-content) ---------- */

	/**
	 * Resolve os bytes de referência a partir do POST: reference_id (biblioteca /
	 * upload já sideloaded) ou preset editorial. Devolve bytes|null.
	 */
	private static function resolve_reference_bytes() {
		$rid = isset( $_POST['reference_id'] ) ? absint( $_POST['reference_id'] ) : 0;
		if ( $rid ) {
			return CE61_Images::reference_bytes_from_attachment( $rid );
		}
		return null;
	}

	/**
	 * Gera imagem(ns) e insere DENTRO do corpo do post na posição escolhida.
	 * Aceita prompt customizado, imagem de referência, presets de estilo,
	 * proporção, modelo e quantidade. Opcionalmente salva um preset.
	 */
	public static function image_generate_inline() {
		self::guard();
		@set_time_limit( 180 );
		$pid = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! get_post( $pid ) ) {
			wp_send_json_error( array( 'message' => __( 'Post não encontrado.', 'cluster-engine' ) ) );
		}
		$position = isset( $_POST['position'] ) ? sanitize_key( $_POST['position'] ) : 'after_h2';
		if ( ! in_array( $position, array( 'start', 'after_h2', 'end' ), true ) ) {
			$position = 'after_h2';
		}
		$quantity = isset( $_POST['quantity'] ) ? max( 1, min( 3, (int) $_POST['quantity'] ) ) : 1;
		$prompt   = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';

		$overrides = array();
		if ( ! empty( $_POST['model'] ) ) {
			$overrides['model'] = sanitize_text_field( wp_unslash( $_POST['model'] ) );
		}
		if ( ! empty( $_POST['aspect'] ) ) {
			$overrides['aspect'] = sanitize_key( $_POST['aspect'] );
		}
		if ( isset( $_POST['style_presets'] ) ) {
			$overrides['style_presets'] = array_map( 'sanitize_key', (array) json_decode( wp_unslash( $_POST['style_presets'] ), true ) );
		}
		$ref = self::resolve_reference_bytes();
		if ( $ref ) {
			$overrides['reference_bytes'] = $ref;
		}
		if ( '' === trim( $prompt ) ) {
			$prompt = CE61_Images::resolve_prompt( $pid, '', $overrides );
		}

		$inserted = array();
		for ( $i = 0; $i < $quantity; $i++ ) {
			$bytes = CE61_Images::generate( $prompt, $overrides );
			if ( is_wp_error( $bytes ) ) {
				self::log_image_error( $pid, $bytes->get_error_message() );
				if ( ! $inserted ) {
					wp_send_json_error( array( 'message' => $bytes->get_error_message() ) );
				}
				break;
			}
			$bytes  = CE61_Images::apply_watermark( $bytes );
			$result = CE61_Images::attach_inline( $pid, $bytes, array(), $position );
			if ( is_wp_error( $result ) ) {
				self::log_image_error( $pid, $result->get_error_message() );
				if ( ! $inserted ) {
					wp_send_json_error( array( 'message' => $result->get_error_message() ) );
				}
				break;
			}
			$inserted[] = array( 'attachment_id' => (int) $result['attachment_id'], 'url' => $result['url'] );
		}

		// Salva preset (modelo editorial) se pedido.
		if ( ! empty( $_POST['save_preset'] ) && ! empty( $_POST['preset_label'] ) ) {
			CE61_Images::preset_save( array(
				'label'         => sanitize_text_field( wp_unslash( $_POST['preset_label'] ) ),
				'prompt'        => $prompt,
				'style_presets' => isset( $overrides['style_presets'] ) ? $overrides['style_presets'] : array(),
				'aspect'        => isset( $overrides['aspect'] ) ? $overrides['aspect'] : '',
				'model'         => isset( $overrides['model'] ) ? $overrides['model'] : '',
				'reference_id'  => isset( $_POST['reference_id'] ) ? absint( $_POST['reference_id'] ) : 0,
			) );
		}

		wp_send_json_success( array(
			'inserted' => $inserted,
			'count'    => count( $inserted ),
			'edit'     => get_edit_post_link( $pid, 'raw' ),
		) );
	}

	/* ---------- Conversão WebP das imagens dos artigos ---------- */

	/**
	 * Lista as imagens dos artigos indexados candidatas à conversão WebP.
	 */
	public static function images_convert_list() {
		self::guard();
		wp_send_json_success( array( 'images' => CE61_Media::article_images() ) );
	}

	/**
	 * Converte UM anexo para WebP mantendo o original (reversível).
	 */
	public static function image_convert() {
		self::guard();
		@set_time_limit( 120 );
		$aid = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		if ( ! $aid ) {
			wp_send_json_error( array( 'message' => __( 'Anexo inválido.', 'cluster-engine' ) ) );
		}
		$result = CE61_Media::convert_attachment( $aid );
		if ( is_wp_error( $result ) ) {
			self::log_image_error( 0, $result->get_error_message() );
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Enfileira conversão WebP em massa das imagens marcadas.
	 */
	public static function images_convert_queue_add() {
		self::guard();
		$ids = isset( $_POST['attachment_ids'] ) ? json_decode( wp_unslash( $_POST['attachment_ids'] ), true ) : null;
		if ( ! is_array( $ids ) || ! $ids ) {
			wp_send_json_error( array( 'message' => __( 'Nenhuma imagem selecionada.', 'cluster-engine' ) ) );
		}
		$added = 0;
		foreach ( array_slice( $ids, 0, 300 ) as $aid ) {
			$aid = absint( $aid );
			if ( ! $aid ) {
				continue;
			}
			CE61_Queue::add( 'convert_image', array( 'attachment_id' => $aid ) );
			$added++;
		}
		wp_send_json_success( array( 'added' => $added ) );
	}

	/* ---------- Presets de imagem (modelos do usuário) ---------- */

	public static function image_presets() {
		self::guard();
		wp_send_json_success( array( 'presets' => CE61_Images::presets() ) );
	}

	public static function image_preset_save() {
		self::guard();
		$data = isset( $_POST['preset'] ) ? json_decode( wp_unslash( $_POST['preset'] ), true ) : null;
		if ( ! is_array( $data ) ) {
			wp_send_json_error( array( 'message' => __( 'Dados do preset inválidos.', 'cluster-engine' ) ) );
		}
		$result = CE61_Images::preset_save( $data );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'presets' => $result ) );
	}

	public static function image_preset_delete() {
		self::guard();
		$id = isset( $_POST['id'] ) ? sanitize_key( $_POST['id'] ) : '';
		wp_send_json_success( array( 'presets' => CE61_Images::preset_delete( $id ) ) );
	}

	/**
	 * Recebe uma imagem de referência via upload (drag-and-drop ou seleção no
	 * dispositivo) e a coloca na Biblioteca de Mídia. Devolve o attachment_id
	 * para ser usado como referência na geração.
	 */
	public static function image_reference_upload() {
		self::guard();
		if ( empty( $_FILES['file'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Nenhum arquivo enviado.', 'cluster-engine' ) ) );
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$overrides = array( 'test_form' => false, 'mimes' => array(
			'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
		) );
		$file = wp_handle_upload( $_FILES['file'], $overrides );
		if ( isset( $file['error'] ) ) {
			wp_send_json_error( array( 'message' => $file['error'] ) );
		}
		$attach_id = wp_insert_attachment( array(
			'post_mime_type' => $file['type'],
			'post_title'     => sanitize_file_name( pathinfo( $file['file'], PATHINFO_FILENAME ) ),
			'post_status'    => 'inherit',
		), $file['file'] );
		if ( is_wp_error( $attach_id ) ) {
			wp_send_json_error( array( 'message' => $attach_id->get_error_message() ) );
		}
		wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $file['file'] ) );
		update_post_meta( $attach_id, '_ce61_reference', 1 );
		wp_send_json_success( array(
			'attachment_id' => (int) $attach_id,
			'url'           => wp_get_attachment_image_url( $attach_id, 'medium' ),
		) );
	}

	/* ---------- Log de erros de imagem ---------- */

	/**
	 * Registra um erro de imagem (geração/conversão) num buffer circular em
	 * option, para exibição centralizada na página de Imagens.
	 */
	private static function log_image_error( $post_id, $message ) {
		$log = get_option( 'ce61_image_errors', array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift( $log, array(
			'time'    => current_time( 'mysql' ),
			'post_id' => (int) $post_id,
			'title'   => $post_id ? get_the_title( $post_id ) : '',
			'message' => wp_strip_all_tags( (string) $message ),
		) );
		$log = array_slice( $log, 0, 50 );
		update_option( 'ce61_image_errors', $log, false );
	}

	/**
	 * Lista os erros de imagem: os registrados pelo plugin + os jobs de imagem
	 * que falharam na fila (geração/conversão em massa).
	 */
	public static function image_errors() {
		self::guard();
		global $wpdb;
		$manual = get_option( 'ce61_image_errors', array() );
		$manual = is_array( $manual ) ? $manual : array();

		$rows = $wpdb->get_results(
			"SELECT id, job_type, payload, error, started_at FROM {$wpdb->prefix}ce_queue
			 WHERE status = 'error' AND job_type IN ('generate_image','convert_image')
			 ORDER BY id DESC LIMIT 50", ARRAY_A
		);
		$queue = array();
		foreach ( (array) $rows as $r ) {
			$p   = json_decode( $r['payload'], true );
			$pid = isset( $p['post_id'] ) ? (int) $p['post_id'] : 0;
			$queue[] = array(
				'time'    => $r['started_at'],
				'post_id' => $pid,
				'title'   => $pid ? get_the_title( $pid ) : ( 'convert_image' === $r['job_type'] ? __( 'Conversão em massa', 'cluster-engine' ) : '' ),
				'message' => wp_strip_all_tags( (string) $r['error'] ),
				'source'  => $r['job_type'],
			);
		}
		wp_send_json_success( array( 'manual' => $manual, 'queue' => $queue ) );
	}

	/* ---------- Bancos de imagens gratuitos (Unsplash/Pexels/Pixabay/Openverse) ---------- */

	/**
	 * Busca num provedor específico ou, se provider='auto', tenta na ordem
	 * de prioridade configurada até um responder com resultados.
	 */
	public static function stock_search() {
		self::guard();
		$provider = isset( $_POST['provider'] ) ? sanitize_key( $_POST['provider'] ) : 'auto';
		$query    = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
		if ( '' === trim( $query ) ) {
			wp_send_json_error( array( 'message' => __( 'Digite um termo de busca.', 'cluster-engine' ) ) );
		}
		if ( 'auto' === $provider ) {
			$r = CE61_Stock::auto_search( $query, 12 );
			if ( is_wp_error( $r ) ) {
				wp_send_json_error( array( 'message' => $r->get_error_message() ) );
			}
			wp_send_json_success( array( 'results' => $r['results'], 'provider' => $r['provider'] ) );
		}
		$r = CE61_Stock::search( $provider, $query, 12 );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		wp_send_json_success( array( 'results' => $r, 'provider' => $provider ) );
	}

	/**
	 * Aplica a imagem escolhida do banco como destacada do post.
	 */
	public static function stock_apply() {
		self::guard();
		$pid   = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$image = isset( $_POST['image'] ) ? json_decode( wp_unslash( $_POST['image'] ), true ) : null;
		if ( ! $pid || ! is_array( $image ) ) {
			wp_send_json_error( array( 'message' => __( 'Parâmetros inválidos.', 'cluster-engine' ) ) );
		}
		$result = CE61_Stock::apply_to_post( $pid, $image );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'url' => $result['url'], 'thumb' => get_the_post_thumbnail_url( $pid, 'medium' ) ) );
	}

	/**
	 * Uso de cota atual de cada provedor (para o medidor nas Configurações).
	 */
	public static function stock_status() {
		self::guard();
		$out = array();
		foreach ( array_keys( CE61_Stock::providers() ) as $p ) {
			$out[ $p ] = CE61_Stock::usage( $p );
		}
		wp_send_json_success( array( 'usage' => $out ) );
	}

	/* ---------- Desempenho das URLs (SERP + Search Console + GA4) ---------- */

	/**
	 * Dados compilados da tabela de desempenho: uma linha por post indexado,
	 * cruzando posição no Google (SERP API), cliques/impressões/CTR/posição
	 * média (Search Console) e sessões (GA4) — tudo já cacheado localmente.
	 */
	public static function performance_data() {
		self::guard();
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT post_id, title, main_keyword FROM {$wpdb->prefix}ce_index ORDER BY title ASC", ARRAY_A );

		$gsc      = get_option( 'ce61_gsc_cache', array() );
		$ga4      = get_option( 'ce61_ga4_cache', array() );
		$gsc_data = isset( $gsc['data'] ) && is_array( $gsc['data'] ) ? $gsc['data'] : array();
		$ga4_data = isset( $ga4['data'] ) && is_array( $ga4['data'] ) ? $ga4['data'] : array();

		$out = array();
		foreach ( $rows as $r ) {
			$pid = (int) $r['post_id'];
			// Página excluída ou na lixeira: não exibir mais na tabela.
			$pstatus = get_post_status( $pid );
			if ( ! $pstatus || 'trash' === $pstatus || 'auto-draft' === $pstatus ) {
				continue;
			}
			$path = untrailingslashit( strtolower( (string) wp_parse_url( get_permalink( $pid ), PHP_URL_PATH ) ) );
			$idx  = get_post_meta( $pid, '_ce61_index_status', true );
			$idx  = $idx ? json_decode( $idx, true ) : null;
			$out[] = array(
				'post_id'      => $pid,
				'title'        => $r['title'],
				'keyword'      => $r['main_keyword'],
				'slug'         => get_post_field( 'post_name', $pid ),
				'edit'         => get_edit_post_link( $pid, 'raw' ),
				'view'         => get_permalink( $pid ),
				'serp'         => CE61_Serp::get_cached( $pid ),
				'gsc'          => isset( $gsc_data[ $path ] ) ? $gsc_data[ $path ] : null,
				'ga4_sessions' => isset( $ga4_data[ $path ] ) ? $ga4_data[ $path ] : null,
				'index'        => is_array( $idx ) ? $idx : null,
			);
		}

		wp_send_json_success( array(
			'posts'            => $out,
			'google_connected' => CE61_Gsc::is_connected(),
			'indexing_scope'   => CE61_Gsc::has_indexing_scope(),
			'gsc_updated'      => isset( $gsc['ts'] ) ? $gsc['ts'] : '',
			'ga4_updated'      => isset( $ga4['ts'] ) ? $ga4['ts'] : '',
			'has_serp'         => CE61_Serp::has_key(),
			'serp_provider'    => CE61_Serp::provider_names()[ CE61_Serp::active_provider() ],
		) );
	}

	/**
	 * Checa em lote o estado de indexação (URL Inspection API), guardando o
	 * resultado em postmeta _ce61_index_status. Lotes pequenos por causa da
	 * cota da API (2.000/dia, 600/min por propriedade). Retorna done/total.
	 */
	public static function index_status_batch() {
		self::guard();
		if ( ! CE61_Gsc::is_connected() ) {
			wp_send_json_error( array( 'message' => __( 'Conecte o Google Search Console em Integrações.', 'cluster-engine' ) ) );
		}
		global $wpdb;
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$ids    = array_map( 'intval', $wpdb->get_col( "SELECT post_id FROM {$wpdb->prefix}ce_index ORDER BY post_id ASC" ) );
		$total  = count( $ids );
		$size   = 5;
		$notes  = array();
		$slice  = array_slice( $ids, $offset, $size );
		foreach ( $slice as $pid ) {
			$url = get_permalink( $pid );
			if ( ! $url ) {
				continue;
			}
			$r = CE61_Gsc::inspect_url( $url );
			if ( is_wp_error( $r ) ) {
				$notes[] = $r->get_error_message();
				continue;
			}
			$r['checked_at'] = current_time( 'mysql' );
			update_post_meta( $pid, '_ce61_index_status', wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );
		}
		wp_send_json_success( array(
			'done'  => min( $offset + $size, $total ),
			'total' => $total,
			'notes' => array_slice( array_unique( $notes ), 0, 3 ),
		) );
	}

	/**
	 * Solicita indexação em massa (Indexing API) para os posts selecionados.
	 * Reaudita o estado logo após, para o painel refletir. Retorna contadores.
	 */
	public static function index_request_batch() {
		self::guard();
		if ( ! CE61_Gsc::is_connected() ) {
			wp_send_json_error( array( 'message' => __( 'Conecte o Google Search Console em Integrações.', 'cluster-engine' ) ) );
		}
		if ( ! CE61_Gsc::has_indexing_scope() ) {
			wp_send_json_error( array( 'message' => __( 'Reconecte o Google em Integrações para habilitar o envio de indexação (novo escopo).', 'cluster-engine' ) ) );
		}
		$ids = isset( $_POST['post_ids'] ) ? json_decode( wp_unslash( $_POST['post_ids'] ), true ) : null;
		if ( ! is_array( $ids ) || ! $ids ) {
			wp_send_json_error( array( 'message' => __( 'Nenhuma página selecionada.', 'cluster-engine' ) ) );
		}
		$sent  = 0;
		$fail  = 0;
		$notes = array();
		foreach ( array_slice( $ids, 0, 100 ) as $pid ) {
			$pid = absint( $pid );
			$url = $pid ? get_permalink( $pid ) : '';
			if ( ! $url ) {
				continue;
			}
			$r = CE61_Gsc::request_indexing( $url );
			if ( is_wp_error( $r ) ) {
				$fail++;
				$notes[] = $r->get_error_message();
			} else {
				$sent++;
			}
		}
		wp_send_json_success( array(
			'sent'  => $sent,
			'fail'  => $fail,
			'notes' => array_slice( array_unique( $notes ), 0, 3 ),
		) );
	}

	/**
	 * Checa a indexação de UMA página (URL Inspection API) sob demanda — usado
	 * pelo botão "Verificar indexação" na coluna Status índice da tabela. Salva
	 * o resultado em _ce61_index_status e devolve o estado para a linha.
	 */
	public static function index_status_one() {
		self::guard();
		if ( ! CE61_Gsc::is_connected() ) {
			wp_send_json_error( array( 'message' => __( 'Conecte o Google Search Console em Integrações.', 'cluster-engine' ) ) );
		}
		$pid = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$url = $pid ? get_permalink( $pid ) : '';
		if ( ! $url ) {
			wp_send_json_error( array( 'message' => __( 'Página inválida.', 'cluster-engine' ) ) );
		}
		$r = CE61_Gsc::inspect_url( $url );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		$r['checked_at'] = current_time( 'mysql' );
		update_post_meta( $pid, '_ce61_index_status', wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );
		wp_send_json_success( array( 'index' => $r ) );
	}

	/**
	 * Solicita a indexação de UMA página (Indexing API) sob demanda — usado pelo
	 * botão "Solicitar indexação" que aparece quando a página está "Não indexado".
	 */
	public static function index_request_one() {
		self::guard();
		if ( ! CE61_Gsc::is_connected() ) {
			wp_send_json_error( array( 'message' => __( 'Conecte o Google Search Console em Integrações.', 'cluster-engine' ) ) );
		}
		if ( ! CE61_Gsc::has_indexing_scope() ) {
			wp_send_json_error( array( 'message' => __( 'Reconecte o Google em Integrações para habilitar o envio de indexação (novo escopo).', 'cluster-engine' ) ) );
		}
		$pid = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$url = $pid ? get_permalink( $pid ) : '';
		if ( ! $url ) {
			wp_send_json_error( array( 'message' => __( 'Página inválida.', 'cluster-engine' ) ) );
		}
		$r = CE61_Gsc::request_indexing( $url );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		wp_send_json_success( array(
			'ok'      => true,
			'message' => __( 'Indexação solicitada. O Google recebeu o aviso — a indexação em si não é garantida nem imediata.', 'cluster-engine' ),
		) );
	}

	/**
	 * Um lote do botão "Atualizar dados": na primeira chamada (offset 0) busca
	 * Search Console e GA4 de uma vez (a API já devolve todas as páginas);
	 * a cada chamada também checa a posição no Google de poucos posts por vez,
	 * respeitando a cota limitada das APIs de SERP gratuitas.
	 */
	public static function performance_refresh_batch() {
		self::guard();
		global $wpdb;
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$ids    = $wpdb->get_col( "SELECT post_id FROM {$wpdb->prefix}ce_index ORDER BY post_id ASC" );
		$total  = count( $ids );
		$notes  = array();

		if ( 0 === $offset && CE61_Gsc::is_connected() ) {
			$gsc = CE61_Gsc::search_analytics( 28 );
			if ( is_wp_error( $gsc ) ) {
				$notes[] = 'Search Console: ' . $gsc->get_error_message();
			} else {
				update_option( 'ce61_gsc_cache', array( 'data' => $gsc, 'ts' => current_time( 'mysql' ) ), false );
			}
			$ga4 = CE61_Gsc::ga4_sessions( 28 );
			if ( is_wp_error( $ga4 ) ) {
				$notes[] = 'GA4: ' . $ga4->get_error_message();
			} else {
				update_option( 'ce61_ga4_cache', array( 'data' => $ga4, 'ts' => current_time( 'mysql' ) ), false );
			}
		}

		$has_serp = CE61_Serp::has_key();
		$size     = 3; // lotes pequenos: APIs de SERP gratuitas têm cota limitada.
		$checked  = 0;
		if ( $has_serp ) {
			$slice = array_slice( $ids, $offset, $size );
			foreach ( $slice as $pid ) {
				$pid = (int) $pid;
				$kw  = $wpdb->get_var( $wpdb->prepare( "SELECT main_keyword FROM {$wpdb->prefix}ce_index WHERE post_id = %d", $pid ) );
				if ( ! $kw ) {
					continue;
				}
				$r = CE61_Serp::check( $kw );
				if ( is_wp_error( $r ) ) {
					$notes[] = $r->get_error_message();
					continue;
				}
				CE61_Serp::set_cached( $pid, $r );
				$checked++;
			}
		} else {
			// Sem chave de SERP: nada a fazer em lote, avança direto para o fim.
			$offset = $total;
		}

		wp_send_json_success( array(
			'done'     => min( $offset + $size, $total ),
			'total'    => $total,
			'checked'  => $checked,
			'has_serp' => $has_serp,
			'notes'    => array_slice( array_unique( $notes ), 0, 3 ),
		) );
	}

	/**
	 * Reconsulta a posição de um único post (botão individual na tabela).
	 */
	public static function performance_serp_one() {
		self::guard();
		global $wpdb;
		$pid = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$kw  = isset( $_POST['keyword'] ) && $_POST['keyword']
			? sanitize_text_field( wp_unslash( $_POST['keyword'] ) )
			: ( $pid ? (string) $wpdb->get_var( $wpdb->prepare( "SELECT main_keyword FROM {$wpdb->prefix}ce_index WHERE post_id = %d", $pid ) ) : '' );
		if ( ! $kw ) {
			wp_send_json_error( array( 'message' => __( 'Informe uma keyword.', 'cluster-engine' ) ) );
		}
		$r = CE61_Serp::check( $kw );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		if ( $pid ) {
			CE61_Serp::set_cached( $pid, $r );
		}
		wp_send_json_success( array( 'serp' => $r ) );
	}

	/**
	 * Série histórica de um post (para o gráfico) + datas de atualização.
	 */
	public static function performance_history() {
		self::guard();
		$pid  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$days = isset( $_POST['days'] ) ? max( 1, min( 120, absint( $_POST['days'] ) ) ) : 30;
		if ( ! $pid ) {
			wp_send_json_error( array( 'message' => __( 'Post inválido.', 'cluster-engine' ) ) );
		}
		$h = CE61_History::get_history( $pid, $days );
		wp_send_json_success( $h );
	}

	/**
	 * Insight de IA cruzando a série histórica, as datas de atualização e o
	 * diagnóstico SEO/AEO/GEO/E-E-A-T já calculado do post.
	 */
	public static function performance_insight() {
		self::guard();
		global $wpdb;
		$pid = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$post = $pid ? get_post( $pid ) : null;
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => __( 'Post inválido.', 'cluster-engine' ) ) );
		}

		$h      = CE61_History::get_history( $pid, 90 );
		$series = array();
		foreach ( $h['points'] as $pt ) {
			$series[] = $pt['snap_date'] . ': cliques=' . ( $pt['clicks'] ?? '—' ) . ', impressões=' . ( $pt['impressions'] ?? '—' )
				. ', posição GSC=' . ( $pt['gsc_position'] ?? '—' ) . ', posição Google=' . ( $pt['serp_position'] ?? '—' )
				. ', sessões GA4=' . ( $pt['ga4_sessions'] ?? '—' );
		}
		$series_text = $series ? implode( "\n", $series ) : 'Sem histórico suficiente ainda (a coleta diária precisa de mais dias para gerar tendência).';

		$idx = $wpdb->get_row( $wpdb->prepare(
			"SELECT seo_score, aeo_score, issues, main_keyword FROM {$wpdb->prefix}ce_index WHERE post_id = %d", $pid
		), ARRAY_A );
		$diag = array();
		if ( $idx ) {
			$diag[] = 'Score SEO: ' . (int) $idx['seo_score'] . '/100. Score AEO/GEO: ' . (int) $idx['aeo_score'] . '/100.';
			$issues = json_decode( (string) $idx['issues'], true );
			if ( is_array( $issues ) && $issues ) {
				$diag[] = 'Pendências detectadas: ' . implode( ', ', $issues );
			}
		}
		$scores_raw = get_post_meta( $pid, '_ce61_scores', true );
		if ( $scores_raw ) {
			$scores = json_decode( $scores_raw, true );
			if ( is_array( $scores ) ) {
				foreach ( array( 'eeat' => 'E-E-A-T', 'aeo' => 'AEO', 'geo' => 'GEO' ) as $k => $label ) {
					if ( isset( $scores[ $k ]['score'] ) ) {
						$diag[] = $label . ': ' . $scores[ $k ]['score'] . '/100.';
					}
				}
			}
		}

		$content = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
		$keyword = CE61_SEO::get_focus_keyword( $pid ) ?: ( $idx ? $idx['main_keyword'] : '' );

		$result = CE61_AI::run( 'performance_insight', $pid, array(
			'perf_series'       => $series_text,
			'update_dates'      => $h['updates'] ? implode( ', ', $h['updates'] ) : 'nenhuma atualização registrada nesta janela',
			'post_diagnostics'  => $diag ? implode( "\n", $diag ) : 'Sem diagnóstico disponível — rode o scan no Painel.',
			'title'             => $post->post_title,
			'keyword'           => $keyword,
			'excerpt'           => wp_trim_words( $content, 60 ),
		) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'insight' => trim( $result ) ) );
	}

	/**
	 * Salva o insight exibido como uma nota fixa, junto com uma foto das
	 * métricas do momento — para comparar depois se o que a IA sugeriu
	 * realmente mudou o desempenho.
	 */
	public static function performance_insight_save() {
		self::guard();
		$pid  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$text = isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : '';
		if ( ! $pid || '' === trim( $text ) ) {
			wp_send_json_error( array( 'message' => __( 'Nada para salvar.', 'cluster-engine' ) ) );
		}
		$raw_metrics = isset( $_POST['metrics'] ) ? json_decode( wp_unslash( $_POST['metrics'] ), true ) : array();
		$raw_metrics = is_array( $raw_metrics ) ? $raw_metrics : array();
		$metrics     = array();
		foreach ( array( 'clicks', 'impressions', 'gsc_position', 'serp_position', 'ga4_sessions', 'ctr' ) as $k ) {
			$metrics[ $k ] = isset( $raw_metrics[ $k ] ) && '' !== $raw_metrics[ $k ] && null !== $raw_metrics[ $k ]
				? round( (float) $raw_metrics[ $k ], 2 )
				: null;
		}
		$notes = CE61_History::save_insight( $pid, $text, $metrics );
		wp_send_json_success( array( 'count' => count( $notes ) ) );
	}

	public static function performance_insight_list() {
		self::guard();
		$pid = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		wp_send_json_success( array( 'notes' => CE61_History::get_insights( $pid ) ) );
	}

	public static function performance_insight_delete() {
		self::guard();
		$pid   = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$index = isset( $_POST['index'] ) ? absint( $_POST['index'] ) : -1;
		CE61_History::delete_insight( $pid, $index );
		wp_send_json_success( array( 'notes' => CE61_History::get_insights( $pid ) ) );
	}

	/* ---------- Google (Search Console + GA4) ---------- */

	public static function google_status() {
		self::guard();
		$g = CE61_Gsc::data();
		wp_send_json_success( array(
			'connected'       => CE61_Gsc::is_connected(),
			'has_client'      => ! empty( $g['client_id'] ) && ! empty( $g['client_secret'] ),
			'gsc_site_url'    => isset( $g['gsc_site_url'] ) ? $g['gsc_site_url'] : '',
			'ga4_property_id' => isset( $g['ga4_property_id'] ) ? $g['ga4_property_id'] : '',
		) );
	}

	public static function google_disconnect() {
		self::guard();
		CE61_Gsc::disconnect();
		wp_send_json_success( array( 'ok' => true ) );
	}

	public static function google_list_sites() {
		self::guard();
		$sites = CE61_Gsc::list_sites();
		if ( is_wp_error( $sites ) ) {
			wp_send_json_error( array( 'message' => $sites->get_error_message() ) );
		}
		wp_send_json_success( array( 'sites' => $sites ) );
	}

	public static function google_list_ga4() {
		self::guard();
		$props = CE61_Gsc::list_ga4_properties();
		if ( is_wp_error( $props ) ) {
			wp_send_json_error( array( 'message' => $props->get_error_message() ) );
		}
		wp_send_json_success( array( 'properties' => $props ) );
	}

	/**
	 * Testa a conexão com o Search Console usando a propriedade salva —
	 * devolve o erro exato da API em vez de deixar a falha silenciosa.
	 */
	public static function google_test_gsc() {
		self::guard();
		if ( ! CE61_Gsc::is_connected() ) {
			wp_send_json_error( array( 'message' => __( 'Conecte-se ao Google primeiro.', 'cluster-engine' ) ) );
		}
		$g = CE61_Gsc::data();
		if ( empty( $g['gsc_site_url'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Defina e salve a propriedade do Search Console antes de testar.', 'cluster-engine' ) ) );
		}
		$data = CE61_Gsc::search_analytics( 7 );
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( array( 'message' => $data->get_error_message() ) );
		}
		wp_send_json_success( array( 'rows' => count( $data ) ) );
	}

	/**
	 * Testa a conexão com o GA4 usando a propriedade salva.
	 */
	public static function google_test_ga4() {
		self::guard();
		if ( ! CE61_Gsc::is_connected() ) {
			wp_send_json_error( array( 'message' => __( 'Conecte-se ao Google primeiro.', 'cluster-engine' ) ) );
		}
		$g = CE61_Gsc::data();
		if ( empty( $g['ga4_property_id'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Defina e salve o ID de propriedade do GA4 antes de testar.', 'cluster-engine' ) ) );
		}
		$data = CE61_Gsc::ga4_sessions( 7 );
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( array( 'message' => $data->get_error_message() ) );
		}
		wp_send_json_success( array( 'rows' => count( $data ) ) );
	}

	/**
	 * Rede visual: nós são posts, arestas são links internos reais entre
	 * eles (a mesma malha usada na detecção de canibalização/oportunidades),
	 * rotuladas pela keyword foco de cada post — a "rede de palavras-chave".
	 */
	public static function keyword_network() {
		self::guard();
		global $wpdb;
		$p = $wpdb->prefix;

		$posts = $wpdb->get_results(
			"SELECT post_id, title, main_keyword, cluster_id, inbound, is_pillar FROM {$p}ce_index ORDER BY inbound DESC LIMIT 500",
			ARRAY_A
		);
		if ( ! $posts ) {
			wp_send_json_success( array( 'nodes' => array(), 'edges' => array(), 'clusters' => array() ) );
		}

		$cluster_names = array();
		$clusters      = $wpdb->get_results( "SELECT id, name FROM {$p}ce_clusters", ARRAY_A );
		foreach ( $clusters as $c ) {
			$cluster_names[ (int) $c['id'] ] = $c['name'];
		}

		$ids  = wp_list_pluck( $posts, 'post_id' );
		$in   = implode( ',', array_map( 'intval', $ids ) );
		$rows = $in ? $wpdb->get_results(
			"SELECT post_a, post_b, similarity, link_ab, link_ba FROM {$p}ce_relations
			 WHERE (link_ab = 1 OR link_ba = 1) AND post_a IN ($in) AND post_b IN ($in)", // phpcs:ignore
			ARRAY_A
		) : array();

		$nodes = array();
		foreach ( $posts as $r ) {
			$pid     = (int) $r['post_id'];
			$cid     = (int) $r['cluster_id'];
			$nodes[] = array(
				'id'      => $pid,
				'title'   => $r['title'],
				'keyword' => $r['main_keyword'],
				'cluster' => $cid,
				'cname'   => isset( $cluster_names[ $cid ] ) ? $cluster_names[ $cid ] : '',
				'inbound' => (int) $r['inbound'],
				'pillar'  => (int) $r['is_pillar'],
				'edit'    => get_edit_post_link( $pid, 'raw' ),
				'view'    => get_permalink( $pid ),
			);
		}
		$edges = array();
		foreach ( $rows as $r ) {
			$edges[] = array(
				'a' => (int) $r['post_a'],
				'b' => (int) $r['post_b'],
				'w' => ( (int) $r['link_ab'] && (int) $r['link_ba'] ) ? 2 : 1,
			);
		}

		wp_send_json_success( array( 'nodes' => $nodes, 'edges' => $edges ) );
	}

	public static function rename_cluster() {
		self::guard();
		global $wpdb;
		$cid  = isset( $_POST['cluster_id'] ) ? absint( $_POST['cluster_id'] ) : 0;
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$wpdb->update( $wpdb->prefix . 'ce_clusters', array( 'name' => $name ), array( 'id' => $cid ) );
		wp_send_json_success( array( 'ok' => true ) );
	}

	public static function set_pillar() {
		self::guard();
		global $wpdb;
		$cid = isset( $_POST['cluster_id'] ) ? absint( $_POST['cluster_id'] ) : 0;
		$pid = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}ce_index SET is_pillar = 0 WHERE cluster_id = %d", $cid ) );
		$wpdb->update( $wpdb->prefix . 'ce_index', array( 'is_pillar' => 1 ), array( 'post_id' => $pid ) );
		$wpdb->update( $wpdb->prefix . 'ce_clusters', array( 'pillar_id' => $pid ), array( 'id' => $cid ) );
		wp_send_json_success( array( 'ok' => true ) );
	}

	/* ---------- Criação de clusters e conteúdo ---------- */

	/**
	 * Dados da página de criação: todos os clusters (automáticos + manuais)
	 * com o plano de conteúdo salvo.
	 */
	public static function creator_data() {
		self::guard();
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT id, name, description, is_custom, pillar_id, post_count, score, top_terms, planned
			 FROM {$wpdb->prefix}ce_clusters ORDER BY is_custom DESC, post_count DESC",
			ARRAY_A
		);
		foreach ( $rows as &$r ) {
			$r['top_terms']    = json_decode( (string) $r['top_terms'], true );
			$r['planned']      = json_decode( (string) $r['planned'], true );
			$r['planned']      = is_array( $r['planned'] ) ? $r['planned'] : array();
			$r['pillar_title'] = $r['pillar_id'] ? get_the_title( (int) $r['pillar_id'] ) : '';
			foreach ( $r['planned'] as &$t ) {
				if ( ! empty( $t['post_id'] ) ) {
					$t['edit'] = get_edit_post_link( (int) $t['post_id'], 'raw' );
				}
			}
			unset( $t );
		}
		$indexed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ce_index" );
		wp_send_json_success( array( 'clusters' => $rows, 'indexed' => $indexed ) );
	}

	/**
	 * IA sugere novos clusters coerentes com o conteúdo do site.
	 */
	public static function suggest_clusters() {
		self::guard();
		$count  = isset( $_POST['count'] ) ? max( 1, min( 10, absint( $_POST['count'] ) ) ) : 5;
		$result = CE61_Creator::suggest_clusters( $count );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'suggestions' => $result ) );
	}

	public static function create_cluster() {
		self::guard();
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$desc = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
		$kw   = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
		$id   = CE61_Creator::create_cluster( $name, $desc, $kw );
		if ( is_wp_error( $id ) ) {
			wp_send_json_error( array( 'message' => $id->get_error_message() ) );
		}
		wp_send_json_success( array( 'id' => $id ) );
	}

	public static function delete_cluster() {
		self::guard();
		$cid = isset( $_POST['cluster_id'] ) ? absint( $_POST['cluster_id'] ) : 0;
		$ok  = CE61_Creator::delete_cluster( $cid );
		if ( is_wp_error( $ok ) ) {
			wp_send_json_error( array( 'message' => $ok->get_error_message() ) );
		}
		wp_send_json_success( array( 'ok' => true ) );
	}

	/**
	 * IA planeja tópicos de artigos para um cluster (novo ou existente).
	 */
	public static function cluster_plan() {
		self::guard();
		$cid   = isset( $_POST['cluster_id'] ) ? absint( $_POST['cluster_id'] ) : 0;
		$count = isset( $_POST['count'] ) ? max( 1, min( 12, absint( $_POST['count'] ) ) ) : 6;
		$config = array(
			'word_count_target' => isset( $_POST['word_count_target'] ) ? absint( $_POST['word_count_target'] ) : 1200,
			'h2_count_target'   => isset( $_POST['h2_count_target'] ) ? absint( $_POST['h2_count_target'] ) : 6,
		);
		$plan  = CE61_Creator::plan_topics( $cid, $count, $config );
		if ( is_wp_error( $plan ) ) {
			wp_send_json_error( array( 'message' => $plan->get_error_message() ) );
		}
		wp_send_json_success( array( 'planned' => $plan ) );
	}

	/**
	 * Remove um tópico do plano de um cluster.
	 */
	public static function remove_topic() {
		self::guard();
		global $wpdb;
		$cid   = isset( $_POST['cluster_id'] ) ? absint( $_POST['cluster_id'] ) : 0;
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$planned = json_decode( (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT planned FROM {$wpdb->prefix}ce_clusters WHERE id = %d", $cid
		) ), true );
		$planned = is_array( $planned ) ? $planned : array();
		$planned = array_values( array_filter( $planned, function ( $t ) use ( $title ) {
			return ! isset( $t['title'] ) || mb_strtolower( $t['title'] ) !== mb_strtolower( $title );
		} ) );
		CE61_Creator::save_plan( $cid, $planned );
		wp_send_json_success( array( 'planned' => $planned ) );
	}

	/**
	 * Gera UM artigo imediatamente (síncrono), sem passar pela fila.
	 */
	public static function generate_now() {
		self::guard();
		$publish = isset( $_POST['publish'] ) ? sanitize_key( $_POST['publish'] ) : 'draft';
		if ( ! in_array( $publish, array( 'draft', 'publish', 'schedule' ), true ) ) {
			$publish = 'draft';
		}
		$payload = array(
			'cluster_id'    => isset( $_POST['cluster_id'] ) ? absint( $_POST['cluster_id'] ) : 0,
			'title'         => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'keyword'       => isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '',
			'custom_prompt' => isset( $_POST['custom_prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['custom_prompt'] ) ) : '',
			'publish'       => $publish,
			'schedule_at'   => isset( $_POST['schedule_at'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_at'] ) ) : '',
		);
		$result = CE61_Creator::job_generate_article( $payload );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/* ---------- Custom Post Types (CPT) ---------- */

	/**
	 * Lista os CPTs públicos do site com origem (JetEngine/nativo), contagem,
	 * campos detectados e se já estão habilitados no Cluster Engine.
	 */
	public static function cpt_list() {
		self::guard();
		wp_send_json_success( array(
			'cpts'      => CE61_CPT::list_cpts(),
			'jetengine' => (bool) CE61_CPT::is_jetengine(),
		) );
	}

	/**
	 * Habilita/desabilita um CPT no pipeline (indexação, clusters, linkagem).
	 */
	public static function cpt_toggle() {
		self::guard();
		$pt = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
		$on = ! empty( $_POST['enabled'] ) && '0' !== (string) $_POST['enabled'];
		$res = $on ? CE61_CPT::enable( $pt ) : CE61_CPT::disable( $pt );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( array( 'cpts' => CE61_CPT::list_cpts() ) );
	}

	/**
	 * Gera UM item de CPT imediatamente, aplicando a estratégia de cluster,
	 * linkagem interna e preenchimento dos meta fields do CPT.
	 */
	public static function cpt_generate() {
		self::guard();
		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
		if ( ! $post_type || ! post_type_exists( $post_type ) ) {
			wp_send_json_error( array( 'message' => __( 'Selecione um CPT válido.', 'cluster-engine' ) ) );
		}
		$publish = isset( $_POST['publish'] ) ? sanitize_key( $_POST['publish'] ) : 'draft';
		if ( ! in_array( $publish, array( 'draft', 'publish', 'schedule' ), true ) ) {
			$publish = 'draft';
		}
		$payload = array(
			'post_type'     => $post_type,
			'cluster_id'    => isset( $_POST['cluster_id'] ) ? absint( $_POST['cluster_id'] ) : 0,
			'title'         => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'keyword'       => isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '',
			'custom_prompt' => isset( $_POST['custom_prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['custom_prompt'] ) ) : '',
			'publish'       => $publish,
			'schedule_at'   => isset( $_POST['schedule_at'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_at'] ) ) : '',
		);
		$result = CE61_Creator::job_generate_article( $payload );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * IA melhora um prompt de geração de artigo, mantendo o assunto (título/keyword).
	 */
	public static function improve_prompt() {
		self::guard();
		$title    = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$keyword  = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
		$current  = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		if ( '' === $title ) {
			wp_send_json_error( array( 'message' => __( 'Informe o título do artigo antes de melhorar o prompt.', 'cluster-engine' ) ) );
		}
		if ( '' === $current ) {
			$current = CE61_Creator::default_prompt_seed( $title, $keyword );
		}
		$result = CE61_AI::run( 'improve_prompt', 0, array(
			'title'       => $title,
			'keyword'     => $keyword,
			'user_prompt' => $current,
		) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'prompt' => trim( $result ) ) );
	}

	/**
	 * Lista os posts gerados por IA, com status e nota de E-E-A-T de cada um.
	 */
	public static function ai_posts_list() {
		self::guard();
		wp_send_json_success( array( 'posts' => CE61_Creator::list_ai_posts( 50 ) ) );
	}

	/**
	 * Recalcula as notas de E-E-A-T, AEO e GEO de um post (após editar/melhorar o conteúdo).
	 */
	public static function post_eeat() {
		self::guard();
		$pid = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $pid ) {
			wp_send_json_error( array( 'message' => __( 'Post inválido.', 'cluster-engine' ) ) );
		}
		$scores = CE61_Creator::analyze_scores( $pid );
		update_post_meta( $pid, '_ce61_scores', wp_json_encode( $scores, JSON_UNESCAPED_UNICODE ) );
		wp_send_json_success( array( 'scores' => $scores, 'keyword' => CE61_SEO::get_focus_keyword( $pid ) ) );
	}

	/**
	 * Corrige com IA um ajuste específico apontado em "Ver ajustes" (E-E-A-T/AEO/GEO).
	 * Cada chave de issue tem um remédio determinístico; ao final, recalcula as notas.
	 * Issues sem correção automática segura não expõem botão no painel.
	 */
	public static function creator_fix_issue() {
		self::guard();
		$pid = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$key = isset( $_POST['key'] ) ? sanitize_key( $_POST['key'] ) : '';

		$res = self::run_issue_fix( $pid, $key );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		if ( ! empty( $res['queued'] ) ) {
			wp_send_json_success( array( 'queued' => true ) );
		}

		$scores = CE61_Creator::analyze_scores( $pid );
		update_post_meta( $pid, '_ce61_scores', wp_json_encode( $scores, JSON_UNESCAPED_UNICODE ) );
		wp_send_json_success( array( 'scores' => $scores ) );
	}

	/**
	 * Aplica a correção automática de uma pendência do diagnóstico (por key).
	 * Compartilhado entre o painel (creator_fix_issue) e a modal do editor
	 * (editor_fix), garantindo a mesma lógica nos dois lugares.
	 *
	 * Retorna array( 'queued' => bool ) em sucesso, ou WP_Error em falha.
	 * Keys com correção automática: no_faq, no_answer_capsule, no_meta_desc, no_image.
	 */
	private static function run_issue_fix( $pid, $key ) {
		$post = $pid ? get_post( $pid ) : null;
		if ( ! $post ) {
			return new WP_Error( 'ce_no_post', __( 'Post inválido.', 'cluster-engine' ) );
		}

		switch ( $key ) {
			case 'no_faq':
				$ai = CE61_AI::run( 'faq_schema', $pid );
				if ( is_wp_error( $ai ) ) {
					return $ai;
				}
				$r = CE61_Schema::apply_faq( $pid, $ai );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				return array( 'queued' => false );

			case 'no_answer_capsule':
				$ai = CE61_AI::run( 'answer_capsule', $pid );
				if ( is_wp_error( $ai ) ) {
					return $ai;
				}
				$para = trim( wp_strip_all_tags( $ai ) );
				$para = preg_replace( '/^```(?:html)?\s*|\s*```$/i', '', $para );
				if ( '' === $para ) {
					return new WP_Error( 'ce_ai_empty', __( 'A IA não retornou um parágrafo de abertura.', 'cluster-engine' ) );
				}
				$content = '<p>' . $para . '</p>' . "\n" . $post->post_content;
				$upd = wp_update_post( array( 'ID' => $pid, 'post_content' => wp_slash( $content ) ), true );
				if ( is_wp_error( $upd ) ) {
					return $upd;
				}
				return array( 'queued' => false );

			case 'no_meta_desc':
				$ai = CE61_AI::run( 'rewrite_desc', $pid );
				if ( is_wp_error( $ai ) ) {
					return $ai;
				}
				$desc = '';
				foreach ( preg_split( '/\r\n|\r|\n/', (string) $ai ) as $line ) {
					$line = trim( preg_replace( '/^\s*\d+[\).\-]\s*/', '', $line ) );
					$line = trim( $line, "\"' " );
					if ( '' !== $line ) {
						$desc = $line;
						break;
					}
				}
				if ( '' === $desc ) {
					return new WP_Error( 'ce_ai_empty', __( 'A IA não retornou uma meta description.', 'cluster-engine' ) );
				}
				CE61_SEO::set_meta_desc( $pid, mb_substr( $desc, 0, 156 ) );
				return array( 'queued' => false );

			case 'no_image':
				$id = CE61_Queue::add( 'generate_image', array(
					'post_id' => $pid,
					'source'  => 'auto',
					'query'   => get_the_title( $pid ),
				) );
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				return array( 'queued' => true );

			default:
				return new WP_Error( 'ce_no_autofix', __( 'Esse ajuste não tem correção automática — revise no editor.', 'cluster-engine' ) );
		}
	}

	/**
	 * Keys de pendência que possuem correção automática (usado no front para
	 * decidir se mostra o botão "Corrigir" na modal).
	 */
	private static function autofix_keys() {
		return array( 'no_faq', 'no_answer_capsule', 'no_meta_desc', 'no_image' );
	}

	/**
	 * Extrai as opções (uma por linha, numeradas) devolvidas pela IA em
	 * rewrite_title/rewrite_desc, já limpas e deduplicadas.
	 */
	private static function parse_ai_options( $raw ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
			$line = trim( preg_replace( '/^\s*\d+[\).\-]\s*/', '', $line ) );
			$line = trim( $line, "\"' " );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return array_slice( array_values( array_unique( $out ) ), 0, 5 );
	}

	/**
	 * Meta título e meta descrição atuais (via bridge de SEO) para a modal.
	 */
	private static function current_meta( $pid ) {
		return array(
			'title' => CE61_SEO::get_meta_title( $pid ),
			'desc'  => CE61_SEO::get_meta_desc( $pid ),
		);
	}

	/**
	 * Endpoint da modal do editor para ações individualizadas:
	 *  - task 'gen_title' / 'gen_desc': gera 3+ opções com IA (NÃO salva);
	 *  - task 'save_meta' (field title|desc, value): salva o meta escolhido/editado;
	 *  - task 'issue' (key): aplica a correção automática de uma pendência.
	 * Guardado por manage_options (guard) + edit_post do post alvo.
	 */
	public static function editor_fix() {
		self::guard();
		@set_time_limit( 120 );
		$pid  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$task = isset( $_POST['task'] ) ? sanitize_key( $_POST['task'] ) : '';
		$post = $pid ? get_post( $pid ) : null;
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => __( 'Post não encontrado.', 'cluster-engine' ) ) );
		}
		if ( ! current_user_can( 'edit_post', $pid ) ) {
			wp_send_json_error( array( 'message' => __( 'Sem permissão para editar este conteúdo.', 'cluster-engine' ) ), 403 );
		}

		if ( 'gen_title' === $task || 'gen_desc' === $task ) {
			$tmpl = ( 'gen_title' === $task ) ? 'rewrite_title' : 'rewrite_desc';
			$ai   = CE61_AI::run( $tmpl, $pid );
			if ( is_wp_error( $ai ) ) {
				wp_send_json_error( array( 'message' => $ai->get_error_message() ) );
			}
			$opts = self::parse_ai_options( (string) $ai );
			if ( empty( $opts ) ) {
				wp_send_json_error( array( 'message' => __( 'A IA não retornou opções utilizáveis.', 'cluster-engine' ) ) );
			}
			wp_send_json_success( array( 'options' => $opts ) );
		}

		if ( 'save_meta' === $task ) {
			$field = isset( $_POST['field'] ) ? sanitize_key( $_POST['field'] ) : '';
			$value = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';
			if ( ! in_array( $field, array( 'title', 'desc' ), true ) || '' === trim( $value ) ) {
				wp_send_json_error( array( 'message' => __( 'Parâmetros inválidos.', 'cluster-engine' ) ) );
			}
			if ( 'title' === $field ) {
				CE61_SEO::set_meta_title( $pid, mb_substr( $value, 0, 60 ) );
			} else {
				CE61_SEO::set_meta_desc( $pid, mb_substr( $value, 0, 156 ) );
			}
			wp_send_json_success( array( 'ok' => true, 'diag' => self::collect_diagnostics( $pid ) ) );
		}

		if ( 'issue' === $task ) {
			$key = isset( $_POST['key'] ) ? sanitize_key( $_POST['key'] ) : '';
			$res = self::run_issue_fix( $pid, $key );
			if ( is_wp_error( $res ) ) {
				wp_send_json_error( array( 'message' => $res->get_error_message() ) );
			}
			$out = array( 'ok' => true, 'diag' => self::collect_diagnostics( $pid ) );
			if ( ! empty( $res['queued'] ) ) {
				$out['queued']  = true;
				$out['message'] = __( 'Geração de imagem na fila — a imagem destacada será definida em instantes.', 'cluster-engine' );
			}
			wp_send_json_success( $out );
		}

		wp_send_json_error( array( 'message' => __( 'Ação inválida.', 'cluster-engine' ) ) );
	}

	/**
	 * Coleta o diagnóstico completo de um post para a modal do editor:
	 * notas E-E-A-T/AEO/GEO (recalculadas na hora), o scan SEO/AEO da tabela
	 * ce_index, a keyword foco e um resumo de desempenho (GSC/GA4/SERP) na
	 * janela de 90 dias (último ponto com dados + variação vs. o primeiro).
	 */
	private static function collect_diagnostics( $pid ) {
		global $wpdb;
		$post = get_post( $pid );

		$scores = CE61_Creator::analyze_scores( $pid );
		update_post_meta( $pid, '_ce61_scores', wp_json_encode( $scores, JSON_UNESCAPED_UNICODE ) );

		$idx = $wpdb->get_row( $wpdb->prepare(
			"SELECT seo_score, aeo_score, issues, main_keyword FROM {$wpdb->prefix}ce_index WHERE post_id = %d", $pid
		), ARRAY_A );
		$index = null;
		if ( $idx ) {
			$issues = json_decode( (string) $idx['issues'], true );
			$index  = array(
				'seo'    => (int) $idx['seo_score'],
				'aeo'    => (int) $idx['aeo_score'],
				'issues' => is_array( $issues ) ? array_values( array_filter( array_map( 'strval', $issues ) ) ) : array(),
			);
		}

		$keyword = CE61_SEO::get_focus_keyword( $pid );
		if ( ! $keyword && $idx ) {
			$keyword = (string) $idx['main_keyword'];
		}

		$perf = array( 'latest' => null, 'delta' => null, 'has_data' => false );
		if ( class_exists( 'CE61_History' ) ) {
			$h    = CE61_History::get_history( $pid, 90 );
			$pts  = isset( $h['points'] ) && is_array( $h['points'] ) ? $h['points'] : array();
			$keys = array( 'clicks', 'impressions', 'ctr', 'gsc_position', 'serp_position', 'ga4_sessions' );
			$has  = function ( $p, $k ) {
				return isset( $p[ $k ] ) && null !== $p[ $k ] && '' !== $p[ $k ];
			};
			$latest = null;
			for ( $i = count( $pts ) - 1; $i >= 0; $i-- ) {
				foreach ( $keys as $k ) {
					if ( $has( $pts[ $i ], $k ) ) { $latest = $pts[ $i ]; break 2; }
				}
			}
			$first = null;
			foreach ( $pts as $p ) {
				foreach ( $keys as $k ) {
					if ( $has( $p, $k ) ) { $first = $p; break 2; }
				}
			}
			if ( $latest ) {
				$perf['has_data'] = true;
				$perf['latest']   = array( 'date' => $latest['snap_date'] );
				foreach ( $keys as $k ) {
					$perf['latest'][ $k ] = $has( $latest, $k ) ? round( (float) $latest[ $k ], 2 ) : null;
				}
				if ( $first && $first !== $latest ) {
					$perf['delta'] = array();
					foreach ( array( 'clicks', 'impressions', 'gsc_position', 'serp_position', 'ga4_sessions' ) as $k ) {
						$perf['delta'][ $k ] = ( $has( $latest, $k ) && $has( $first, $k ) )
							? round( (float) $latest[ $k ] - (float) $first[ $k ], 2 )
							: null;
					}
				}
			}
		}

		return array(
			'title'       => $post ? $post->post_title : '',
			'status'      => $post ? $post->post_status : '',
			'url'         => $post ? get_permalink( $pid ) : '',
			'keyword'     => $keyword,
			'meta'        => self::current_meta( $pid ),
			'scores'      => $scores,
			'index'       => $index,
			'performance' => $perf,
			'autofix'     => self::autofix_keys(),
			'changelog'   => class_exists( 'CE61_Changelog' ) ? CE61_Changelog::entries( $pid ) : array(),
		);
	}

	/**
	 * Lista de pendências (labels) reunidas do diagnóstico de notas + scan SEO,
	 * já deduplicadas — é o que a IA recebe para corrigir prioritariamente.
	 */
	private static function diagnostics_pending( $data ) {
		$pend = array();
		if ( ! empty( $data['scores'] ) && is_array( $data['scores'] ) ) {
			foreach ( array( 'eeat' => 'E-E-A-T', 'aeo' => 'AEO', 'geo' => 'GEO' ) as $k => $lab ) {
				if ( ! empty( $data['scores'][ $k ]['issues'] ) ) {
					foreach ( $data['scores'][ $k ]['issues'] as $iss ) {
						if ( ! empty( $iss['label'] ) ) {
							$pend[] = '[' . $lab . '] ' . $iss['label'];
						}
					}
				}
			}
		}
		if ( ! empty( $data['index']['issues'] ) ) {
			foreach ( $data['index']['issues'] as $iss ) {
				$pend[] = '[SEO] ' . $iss;
			}
		}
		return array_values( array_unique( $pend ) );
	}

	/**
	 * Resumo textual do diagnóstico injetado no prompt da IA.
	 */
	private static function diagnostics_text( $data ) {
		$s     = $data['scores'];
		$lines = array( sprintf(
			'Notas atuais — E-E-A-T: %d/100, AEO: %d/100, GEO: %d/100.',
			isset( $s['eeat']['score'] ) ? (int) $s['eeat']['score'] : 0,
			isset( $s['aeo']['score'] ) ? (int) $s['aeo']['score'] : 0,
			isset( $s['geo']['score'] ) ? (int) $s['geo']['score'] : 0
		) );
		if ( ! empty( $data['index'] ) ) {
			$lines[] = sprintf( 'Scan do Painel — Score SEO: %d/100, Score AEO/GEO: %d/100.', (int) $data['index']['seo'], (int) $data['index']['aeo'] );
		}
		if ( ! empty( $data['performance']['has_data'] ) && ! empty( $data['performance']['latest'] ) ) {
			$l   = $data['performance']['latest'];
			$fmt = function ( $v ) { return ( null === $v ) ? '—' : $v; };
			$lines[] = sprintf(
				'Desempenho recente (%s) — cliques: %s, impressões: %s, posição GSC: %s, posição Google: %s, sessões GA4: %s.',
				$l['date'], $fmt( $l['clicks'] ), $fmt( $l['impressions'] ), $fmt( $l['gsc_position'] ), $fmt( $l['serp_position'] ), $fmt( $l['ga4_sessions'] )
			);
		}
		$pend = self::diagnostics_pending( $data );
		if ( $pend ) {
			$lines[] = 'Pendências a corrigir prioritariamente:';
			foreach ( $pend as $p ) {
				$lines[] = '- ' . $p;
			}
		} else {
			$lines[] = 'Nenhuma pendência crítica detectada — foque em aprofundar, atualizar dados e reforçar autoridade.';
		}
		return implode( "\n", $lines );
	}

	/**
	 * Devolve o diagnóstico do post para a coluna esquerda da modal do editor.
	 */
	public static function editor_diagnostics() {
		self::guard();
		$pid  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$post = $pid ? get_post( $pid ) : null;
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => __( 'Post não encontrado.', 'cluster-engine' ) ) );
		}
		if ( ! current_user_can( 'edit_post', $pid ) ) {
			wp_send_json_error( array( 'message' => __( 'Sem permissão para editar este conteúdo.', 'cluster-engine' ) ), 403 );
		}
		wp_send_json_success( self::collect_diagnostics( $pid ) );
	}

	/**
	 * Gera com IA uma versão melhorada de UM post/página, cruzando o diagnóstico
	 * do Cluster Engine (notas E-E-A-T/AEO/GEO, scan SEO e desempenho GSC/GA4)
	 * com as instruções do usuário. NÃO salva: devolve o rascunho para aprovação
	 * junto das notas atuais. O salvamento/publicação ocorre em editor_apply().
	 *
	 * mode:
	 *  - 'diagnostic': só o diagnóstico corrige as pendências;
	 *  - 'combined':   diagnóstico + prompt do usuário;
	 *  - 'refine':     ajusta o RASCUNHO já gerado (param base) com um novo pedido.
	 * h2:   nº desejado de subtítulos H2 (0 = livre).
	 * base: HTML de um rascunho anterior a ser refinado (só no modo 'refine').
	 */
	public static function editor_improve() {
		self::guard();
		@set_time_limit( 120 );
		$pid          = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$mode         = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'combined';
		$instructions = isset( $_POST['instructions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['instructions'] ) ) : '';
		$h2           = isset( $_POST['h2'] ) ? absint( $_POST['h2'] ) : 0;
		$base         = isset( $_POST['base'] ) ? wp_kses_post( wp_unslash( $_POST['base'] ) ) : '';
		$post         = $pid ? get_post( $pid ) : null;
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => __( 'Post não encontrado.', 'cluster-engine' ) ) );
		}
		if ( ! current_user_can( 'edit_post', $pid ) ) {
			wp_send_json_error( array( 'message' => __( 'Sem permissão para editar este conteúdo.', 'cluster-engine' ) ), 403 );
		}
		if ( ! in_array( $mode, array( 'diagnostic', 'combined', 'refine' ), true ) ) {
			$mode = 'combined';
		}
		if ( $h2 > 12 ) {
			$h2 = 12;
		}
		if ( in_array( $mode, array( 'combined', 'refine' ), true ) && '' === trim( $instructions ) ) {
			$err = ( 'refine' === $mode )
				? __( 'Descreva o ajuste que a IA deve aplicar ao rascunho.', 'cluster-engine' )
				: __( 'Escreva o que a IA deve fazer, ou use o botão de melhorar só com base no diagnóstico.', 'cluster-engine' );
			wp_send_json_error( array( 'message' => $err ) );
		}
		if ( 'refine' === $mode && '' === trim( $base ) ) {
			wp_send_json_error( array( 'message' => __( 'Rascunho base ausente para ajustar. Gere um rascunho primeiro.', 'cluster-engine' ) ) );
		}

		$diag      = self::collect_diagnostics( $pid );
		$diag_text = self::diagnostics_text( $diag );

		if ( 'diagnostic' === $mode ) {
			$final = "Melhore o conteúdo corrigindo prioritariamente as pendências do diagnóstico abaixo e elevando as notas E-E-A-T, AEO e GEO.\n\nDIAGNÓSTICO DO CLUSTER ENGINE:\n" . $diag_text;
		} elseif ( 'refine' === $mode ) {
			$final = "O conteúdo abaixo JÁ é uma versão melhorada (rascunho). Aplique APENAS o ajuste pedido, preservando o que já está bom — estrutura, links internos e imagens.\n\nAJUSTE PEDIDO:\n" . $instructions . "\n\nSe for útil, use também o diagnóstico abaixo.\n\nDIAGNÓSTICO DO CLUSTER ENGINE:\n" . $diag_text;
		} else {
			$final = "PEDIDO DO USUÁRIO:\n" . $instructions . "\n\nAlém do pedido acima, cruze com o diagnóstico do Cluster Engine e corrija as pendências relevantes, elevando as notas E-E-A-T, AEO e GEO.\n\nDIAGNÓSTICO DO CLUSTER ENGINE:\n" . $diag_text;
		}

		if ( $h2 > 0 ) {
			$final .= "\n\nESTRUTURA: organize o conteúdo com aproximadamente " . $h2 . " subtítulos <h2> bem distribuídos, cada um cobrindo um subtema distinto (sem contar o título/H1).";
		}

		// No modo 'refine', a base a melhorar é o rascunho enviado; senão, o conteúdo salvo.
		$source       = ( 'refine' === $mode && '' !== trim( $base ) ) ? $base : (string) $post->post_content;
		$content_html = mb_substr( $source, 0, 12000 );
		$ai = CE61_AI::run( 'improve_post', $pid, array(
			'instructions' => $final,
			'content_html' => $content_html,
		) );
		if ( is_wp_error( $ai ) ) {
			wp_send_json_error( array( 'message' => $ai->get_error_message() ) );
		}
		$html = trim( (string) $ai );
		$html = preg_replace( '/^```(?:html)?\s*/i', '', $html );
		$html = preg_replace( '/\s*```$/', '', $html );
		$html = trim( $html );
		if ( '' === $html ) {
			wp_send_json_error( array( 'message' => __( 'A IA não retornou conteúdo utilizável.', 'cluster-engine' ) ) );
		}

		$pt_obj      = get_post_type_object( $post->post_type );
		$can_publish = $pt_obj && current_user_can( $pt_obj->cap->publish_posts );

		// Preview para aprovação: NÃO salva ainda.
		wp_send_json_success( array(
			'message'      => __( 'Rascunho gerado. Revise e aprove para atualizar o conteúdo.', 'cluster-engine' ),
			'draft'        => $html,
			'preview'      => wp_kses_post( $html ),
			'scores'       => $diag['scores'],
			'status'       => $diag['status'],
			'can_publish'  => $can_publish ? 1 : 0,
			'is_published' => ( 'publish' === $diag['status'] ) ? 1 : 0,
		) );
	}

	/**
	 * Aplica o rascunho aprovado: sobrescreve o conteúdo, opcionalmente publica
	 * (se o usuário tiver permissão e o post ainda não estiver publicado),
	 * recalcula as notas e devolve as notas novas para a modal comparar.
	 */
	public static function editor_apply() {
		self::guard();
		@set_time_limit( 120 );
		$pid     = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$content = isset( $_POST['content'] ) ? (string) wp_unslash( $_POST['content'] ) : '';
		$publish = ! empty( $_POST['publish'] );
		$post    = $pid ? get_post( $pid ) : null;
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => __( 'Post não encontrado.', 'cluster-engine' ) ) );
		}
		if ( ! current_user_can( 'edit_post', $pid ) ) {
			wp_send_json_error( array( 'message' => __( 'Sem permissão para editar este conteúdo.', 'cluster-engine' ) ), 403 );
		}
		$html = trim( $content );
		$html = preg_replace( '/^```(?:html)?\s*/i', '', $html );
		$html = preg_replace( '/\s*```$/', '', $html );
		$html = wp_kses_post( trim( $html ) );
		if ( '' === $html ) {
			wp_send_json_error( array( 'message' => __( 'Rascunho vazio — gere o conteúdo novamente.', 'cluster-engine' ) ) );
		}

		$mode         = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : '';
		$prev_content = (string) $post->post_content;

		$args        = array( 'ID' => $pid, 'post_content' => wp_slash( $html ) );
		$pt_obj      = get_post_type_object( $post->post_type );
		$can_publish = $pt_obj && current_user_can( $pt_obj->cap->publish_posts );
		$published   = false;
		if ( $publish && $can_publish && 'publish' !== $post->post_status ) {
			$args['post_status'] = 'publish';
			$published           = true;
		}
		$upd = wp_update_post( $args, true );
		if ( is_wp_error( $upd ) ) {
			wp_send_json_error( array( 'message' => $upd->get_error_message() ) );
		}

		// Registra a alteração no changelog (guarda o conteúdo anterior p/ reverter).
		if ( class_exists( 'CE61_Changelog' ) ) {
			$summary = ( 'diagnostic' === $mode )
				? __( 'Melhoria com base no diagnóstico', 'cluster-engine' )
				: ( 'combined' === $mode ? __( 'Melhoria com diagnóstico + prompt', 'cluster-engine' ) : __( 'Conteúdo atualizado com IA', 'cluster-engine' ) );
			CE61_Changelog::add( $pid, $prev_content, array(
				'summary'   => $summary,
				'mode'      => $mode,
				'published' => $published,
			) );
		}

		$scores = CE61_Creator::analyze_scores( $pid );
		update_post_meta( $pid, '_ce61_scores', wp_json_encode( $scores, JSON_UNESCAPED_UNICODE ) );

		wp_send_json_success( array(
			'message'   => $published ? __( 'Conteúdo atualizado e publicado.', 'cluster-engine' ) : __( 'Conteúdo atualizado.', 'cluster-engine' ),
			'scores'    => $scores,
			'status'    => get_post_status( $pid ),
			'published' => $published ? 1 : 0,
			'changelog' => class_exists( 'CE61_Changelog' ) ? CE61_Changelog::entries( $pid ) : array(),
			'edit_url'  => get_edit_post_link( $pid, 'raw' ),
			'view_url'  => get_permalink( $pid ),
		) );
	}

	/**
	 * Registro global de logs: URLs atualizadas pela IA + histórico de cada uma.
	 * Se post_id for informado, devolve só o histórico daquela URL.
	 */
	public static function editor_log() {
		self::guard();
		$pid = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( $pid ) {
			if ( ! current_user_can( 'edit_post', $pid ) ) {
				wp_send_json_error( array( 'message' => __( 'Sem permissão.', 'cluster-engine' ) ), 403 );
			}
			wp_send_json_success( array( 'entries' => CE61_Changelog::entries( $pid ) ) );
		}
		wp_send_json_success( array( 'registry' => CE61_Changelog::registry( 50 ) ) );
	}

	/**
	 * Reverte uma URL para a versão guardada em uma entrada do changelog.
	 */
	public static function editor_revert() {
		self::guard();
		@set_time_limit( 120 );
		$pid = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$eid = isset( $_POST['entry_id'] ) ? sanitize_text_field( wp_unslash( $_POST['entry_id'] ) ) : '';
		if ( ! $pid || '' === $eid ) {
			wp_send_json_error( array( 'message' => __( 'Parâmetros inválidos.', 'cluster-engine' ) ) );
		}
		if ( ! current_user_can( 'edit_post', $pid ) ) {
			wp_send_json_error( array( 'message' => __( 'Sem permissão para editar este conteúdo.', 'cluster-engine' ) ), 403 );
		}
		$res = CE61_Changelog::revert( $pid, $eid );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		$scores = CE61_Creator::analyze_scores( $pid );
		update_post_meta( $pid, '_ce61_scores', wp_json_encode( $scores, JSON_UNESCAPED_UNICODE ) );

		wp_send_json_success( array(
			'message'   => __( 'Conteúdo revertido para a versão selecionada.', 'cluster-engine' ),
			'scores'    => $scores,
			'changelog' => CE61_Changelog::entries( $pid ),
			'edit_url'  => get_edit_post_link( $pid, 'raw' ),
		) );
	}

	/**
	 * Publica agora ou agenda um post já criado (lista de posts gerados por IA).
	 */
	public static function set_publish() {
		self::guard();
		$pid         = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$publish     = isset( $_POST['publish'] ) ? sanitize_key( $_POST['publish'] ) : 'draft';
		$schedule_at = isset( $_POST['schedule_at'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_at'] ) ) : '';
		$result      = CE61_Creator::set_publish_status( $pid, $publish, $schedule_at );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'status' => $result ) );
	}

	/* ---------- Fila de geração (WP-Cron) ---------- */

	/**
	 * Geração em massa: enfileira vários tópicos de um cluster.
	 * topics = JSON [{title, keyword}].
	 */
	public static function queue_add() {
		self::guard();
		$cid    = isset( $_POST['cluster_id'] ) ? absint( $_POST['cluster_id'] ) : 0;
		$topics = isset( $_POST['topics'] ) ? json_decode( wp_unslash( $_POST['topics'] ), true ) : null;
		if ( ! is_array( $topics ) || ! $topics ) {
			wp_send_json_error( array( 'message' => __( 'Nenhum tópico selecionado.', 'cluster-engine' ) ) );
		}
		$added = 0;
		foreach ( array_slice( $topics, 0, 50 ) as $t ) {
			if ( empty( $t['title'] ) ) {
				continue;
			}
			$id = CE61_Queue::add( 'generate_article', array(
				'cluster_id' => $cid,
				'title'      => sanitize_text_field( $t['title'] ),
				'keyword'    => isset( $t['keyword'] ) ? sanitize_text_field( $t['keyword'] ) : '',
			) );
			if ( ! is_wp_error( $id ) ) {
				$added++;
			}
		}
		CE61_Queue::ensure_scheduled();
		spawn_cron(); // dá o pontapé inicial imediato quando possível.
		wp_send_json_success( array( 'added' => $added ) );
	}

	public static function queue_list() {
		self::guard();
		wp_send_json_success( CE61_Queue::overview() );
	}

	public static function queue_cancel() {
		self::guard();
		$id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
		CE61_Queue::cancel( $id );
		wp_send_json_success( array( 'ok' => true ) );
	}

	public static function queue_retry() {
		self::guard();
		$id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
		CE61_Queue::retry( $id );
		CE61_Queue::ensure_scheduled();
		spawn_cron();
		wp_send_json_success( array( 'ok' => true ) );
	}

	public static function queue_clear() {
		self::guard();
		wp_send_json_success( array( 'removed' => CE61_Queue::clear_finished() ) );
	}

	/**
	 * Processa um job pendente agora (fallback para hospedagens com cron instável).
	 */
	public static function queue_run_now() {
		self::guard();
		$had = CE61_Queue::run_next();
		wp_send_json_success( array( 'processed' => (bool) $had ) );
	}

	public static function save_settings() {
		self::guard();
		$in  = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();
		$cur = get_option( 'ce61_settings', array() );

		$cur['post_types'] = isset( $in['post_types'] )
			? array_map( 'sanitize_key', (array) $in['post_types'] )
			: ( isset( $cur['post_types'] ) ? $cur['post_types'] : array( 'post' ) );
		$cur['provider']   = isset( $in['provider'] )
			? sanitize_key( $in['provider'] )
			: ( isset( $cur['provider'] ) ? $cur['provider'] : 'anthropic' );
		/*
		 * CORREÇÃO CRÍTICA (salvamento parcial por aba): Configurações agora tem
		 * botões de salvar independentes (Geral, Imagens, Integrações...), cada
		 * um enviando só os campos da própria aba. Todo campo abaixo passou a
		 * preservar o valor já salvo quando ausente do POST — só é sobrescrito
		 * quando a aba que o contém realmente o envia. Chaves de API seguem a
		 * mesma regra e usam o sentinela '__CLEAR__' para remoção explícita.
		 */
		foreach ( array( 'api_key_openai', 'api_key_anthropic', 'api_key_gemini', 'api_key_groq' ) as $kf ) {
			if ( ! isset( $in[ $kf ] ) ) {
				continue; // não enviada: mantém a atual.
			}
			$val = trim( sanitize_text_field( $in[ $kf ] ) );
			if ( '__CLEAR__' === $val ) {
				$cur[ $kf ] = '';
			} elseif ( '' !== $val ) {
				$cur[ $kf ] = $val;
			}
		}
		// Provedor por papel (texto/análise/diagnóstico). Vazio = usa o principal.
		if ( isset( $in['role_provider'] ) && is_array( $in['role_provider'] ) ) {
			$rp = isset( $cur['role_provider'] ) && is_array( $cur['role_provider'] ) ? $cur['role_provider'] : array();
			foreach ( $in['role_provider'] as $r => $p ) {
				$rp[ sanitize_key( $r ) ] = sanitize_key( $p );
			}
			$cur['role_provider'] = $rp;
		}
		$text_fields = array(
			'model_light'     => '',
			'global_prompt'   => '',
			'image_model'     => '',
			'image_size'      => '1792x1024',
			'image_watermark' => '',
			'image_watermark_url' => '',
			'image_colors'    => '',
			'image_style'     => '',
			'image_prompt'    => '',
		);
		foreach ( $text_fields as $f => $default ) {
			if ( isset( $in[ $f ] ) ) {
				$cur[ $f ] = ( false !== strpos( $f, 'prompt' ) || 'image_style' === $f )
					? sanitize_textarea_field( $in[ $f ] )
					: ( 'image_watermark_url' === $f ? esc_url_raw( $in[ $f ] ) : sanitize_text_field( $in[ $f ] ) );
			} elseif ( ! isset( $cur[ $f ] ) ) {
				$cur[ $f ] = $default;
			}
		}
		if ( isset( $in['image_provider'] ) ) {
			$cur['image_provider'] = sanitize_key( $in['image_provider'] );
		} elseif ( ! isset( $cur['image_provider'] ) ) {
			$cur['image_provider'] = 'openai';
		}
		if ( isset( $in['image_aspect'] ) ) {
			$cur['image_aspect'] = sanitize_key( $in['image_aspect'] );
		} elseif ( ! isset( $cur['image_aspect'] ) ) {
			$cur['image_aspect'] = 'wide';
		}
		if ( isset( $in['image_style_presets'] ) ) {
			$cur['image_style_presets'] = array_values( array_map( 'sanitize_key', (array) $in['image_style_presets'] ) );
		}
		if ( isset( $in['image_watermark_type'] ) ) {
			$cur['image_watermark_type'] = in_array( $in['image_watermark_type'], array( 'text', 'image' ), true ) ? $in['image_watermark_type'] : 'text';
		}
		if ( isset( $in['image_watermark_media_id'] ) ) {
			$cur['image_watermark_media_id'] = absint( $in['image_watermark_media_id'] );
		}
		foreach ( array( 'sim_link', 'sim_weak', 'sim_cannibal' ) as $f ) {
			if ( isset( $in[ $f ] ) ) { $cur[ $f ] = (float) $in[ $f ]; }
		}
		foreach ( array( 'min_words', 'stale_months' ) as $f ) {
			if ( isset( $in[ $f ] ) ) { $cur[ $f ] = (int) $in[ $f ]; }
		}
		if ( isset( $in['serp_provider'] ) ) {
			$cur['serp_provider'] = sanitize_key( $in['serp_provider'] );
		} elseif ( ! isset( $cur['serp_provider'] ) ) {
			$cur['serp_provider'] = 'serper';
		}
		foreach ( array( 'serp_key_serper', 'serp_key_serpapi', 'serp_key_valueserp' ) as $kf ) {
			if ( ! isset( $in[ $kf ] ) ) {
				continue;
			}
			$val = trim( sanitize_text_field( $in[ $kf ] ) );
			if ( '__CLEAR__' === $val ) {
				$cur[ $kf ] = '';
			} elseif ( '' !== $val ) {
				$cur[ $kf ] = $val;
			}
		}
		if ( isset( $in['perf_cron_time'] ) && preg_match( '/^\d{1,2}:\d{2}$/', $in['perf_cron_time'] ) ) {
			$cur['perf_cron_time'] = $in['perf_cron_time'];
		} elseif ( ! isset( $cur['perf_cron_time'] ) ) {
			$cur['perf_cron_time'] = '03:00';
		}
		if ( isset( $in['serp_daily_budget'] ) ) {
			$cur['serp_daily_budget'] = max( 0, min( 500, (int) $in['serp_daily_budget'] ) );
		}
		foreach ( array( 'stock_key_unsplash', 'stock_key_pexels', 'stock_key_pixabay' ) as $kf ) {
			if ( ! isset( $in[ $kf ] ) ) {
				continue;
			}
			$val = trim( sanitize_text_field( $in[ $kf ] ) );
			if ( '__CLEAR__' === $val ) {
				$cur[ $kf ] = '';
			} elseif ( '' !== $val ) {
				$cur[ $kf ] = $val;
			}
		}
		foreach ( array( 'stock_limit_unsplash', 'stock_limit_pexels', 'stock_limit_pixabay', 'stock_limit_openverse' ) as $lf ) {
			if ( isset( $in[ $lf ] ) ) {
				$cur[ $lf ] = max( 0, (int) $in[ $lf ] );
			}
		}
		if ( isset( $in['stock_priority'] ) ) {
			$cur['stock_priority'] = array_values( array_map( 'sanitize_key', (array) $in['stock_priority'] ) );
		}
		if ( isset( $in['stock_credit_caption'] ) ) {
			$cur['stock_credit_caption'] = (bool) $in['stock_credit_caption'];
		}
		if ( isset( $in['image_source_default'] ) ) {
			$cur['image_source_default'] = sanitize_key( $in['image_source_default'] );
		}
		if ( isset( $in['image_webp'] ) ) {
			$cur['image_webp'] = (bool) $in['image_webp'];
		} elseif ( ! isset( $cur['image_webp'] ) ) {
			$cur['image_webp'] = true;
		}
		if ( isset( $in['image_webp_quality'] ) ) {
			$cur['image_webp_quality'] = min( 100, max( 40, (int) $in['image_webp_quality'] ) );
		} elseif ( ! isset( $cur['image_webp_quality'] ) ) {
			$cur['image_webp_quality'] = 82;
		}
		update_option( 'ce61_settings', $cur );
		CE61_History::ensure_scheduled(); // reagenda o cron diário se o horário mudou.

		// Integração Google (OAuth client + propriedades) fica em option separada.
		if ( isset( $in['google_client_id'] ) || isset( $in['google_client_secret'] ) || isset( $in['gsc_site_url'] ) || isset( $in['ga4_property_id'] ) ) {
			$g = CE61_Gsc::data();
			if ( isset( $in['google_client_id'] ) ) {
				$g['client_id'] = sanitize_text_field( $in['google_client_id'] );
			}
			if ( isset( $in['google_client_secret'] ) ) {
				$val = trim( sanitize_text_field( $in['google_client_secret'] ) );
				if ( '__CLEAR__' === $val ) {
					$g['client_secret'] = '';
				} elseif ( '' !== $val ) {
					$g['client_secret'] = $val;
				}
			}
			if ( isset( $in['gsc_site_url'] ) ) {
				$site = trim( (string) $in['gsc_site_url'] );
				if ( 0 === stripos( $site, 'sc-domain:' ) ) {
					// Propriedade de Domínio do Search Console: não é URL http(s), então
					// esc_url_raw() a apagaria. Preserva o prefixo e sanitiza como texto.
					$domain = sanitize_text_field( substr( $site, strlen( 'sc-domain:' ) ) );
					$g['gsc_site_url'] = $domain ? 'sc-domain:' . $domain : '';
				} else {
					$g['gsc_site_url'] = esc_url_raw( $site );
				}
			}
			if ( isset( $in['ga4_property_id'] ) ) {
				$raw = sanitize_text_field( $in['ga4_property_id'] );
				$raw = preg_replace( '/^properties\//i', '', trim( $raw ) );
				$raw = preg_replace( '/[^0-9]/', '', $raw ); // GA4 property IDs são só dígitos.
				$g['ga4_property_id'] = $raw;
			}
			CE61_Gsc::save( $g );
		}
		wp_send_json_success( array( 'ok' => true ) );
	}

	public static function save_prompts() {
		self::guard();
		$in       = isset( $_POST['prompts'] ) && is_array( $_POST['prompts'] ) ? wp_unslash( $_POST['prompts'] ) : array();
		$defaults = CE61_AI::default_prompts();
		$out      = CE61_AI::get_prompts();
		foreach ( $in as $key => $prompt ) {
			$key = sanitize_key( $key );
			if ( isset( $defaults[ $key ] ) ) {
				$out[ $key ]['label']  = $defaults[ $key ]['label'];
				$out[ $key ]['prompt'] = sanitize_textarea_field( $prompt );
			}
		}
		update_option( 'ce61_prompts', $out );
		wp_send_json_success( array( 'ok' => true ) );
	}

	/**
	 * Restaura UMA ação para o texto padrão de fábrica (útil quando o plugin
	 * atualiza um prompt e o usuário já tinha salvo a versão antiga).
	 */
	public static function reset_prompt() {
		self::guard();
		$key      = isset( $_POST['key'] ) ? sanitize_key( $_POST['key'] ) : '';
		$defaults = CE61_AI::default_prompts();
		if ( ! isset( $defaults[ $key ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Ação desconhecida.', 'cluster-engine' ) ) );
		}
		$saved = get_option( 'ce61_prompts', array() );
		if ( is_array( $saved ) && isset( $saved[ $key ] ) ) {
			unset( $saved[ $key ] );
			update_option( 'ce61_prompts', $saved );
		}
		wp_send_json_success( array( 'prompt' => $defaults[ $key ]['prompt'] ) );
	}

	/* ---------- Category organizer ---------- */

	public static function categories_list() {
		self::guard();
		wp_send_json_success( array( 'categories' => CE61_Categories::list_categories() ) );
	}

	public static function categories_review_posts() {
		self::guard();
		$limit  = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 30;
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		wp_send_json_success( CE61_Categories::review_posts( $limit, $offset ) );
	}

	public static function category_analyze_post() {
		self::guard();
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Post inválido.', 'cluster-engine' ) ) );
		}
		$res = CE61_Categories::analyze_post( $post_id );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( $res );
	}

	public static function category_apply() {
		self::guard();
		$post_id  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$category = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';
		$is_new   = ! empty( $_POST['is_new'] );
		if ( ! $post_id || '' === $category ) {
			wp_send_json_error( array( 'message' => __( 'Dados incompletos.', 'cluster-engine' ) ) );
		}
		$res = CE61_Categories::apply( $post_id, $category, $is_new );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( $res );
	}

	public static function categories_suggest() {
		self::guard();
		$count = isset( $_POST['count'] ) ? absint( $_POST['count'] ) : 5;
		$res   = CE61_Categories::suggest( $count );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( array( 'suggestions' => $res ) );
	}

	public static function category_create() {
		self::guard();
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$desc = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => __( 'Nome obrigatório.', 'cluster-engine' ) ) );
		}
		$res = CE61_Categories::create( $name, $desc );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( $res );
	}

	public static function category_generate_seo() {
		self::guard();
		$term_id = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
		if ( ! $term_id ) {
			wp_send_json_error( array( 'message' => __( 'Categoria inválida.', 'cluster-engine' ) ) );
		}
		$res = CE61_Categories::generate_seo( $term_id );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( $res );
	}

	public static function category_save_seo() {
		self::guard();
		$term_id = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
		$title   = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$desc    = isset( $_POST['desc'] ) ? sanitize_text_field( wp_unslash( $_POST['desc'] ) ) : '';
		$native  = isset( $_POST['native'] ) ? sanitize_textarea_field( wp_unslash( $_POST['native'] ) ) : '';
		if ( ! $term_id ) {
			wp_send_json_error( array( 'message' => __( 'Categoria inválida.', 'cluster-engine' ) ) );
		}
		$res = CE61_Categories::save_seo( $term_id, $title, $desc, $native );
		wp_send_json_success( $res );
	}

	public static function category_generate_image() {
		self::guard();
		$term_id = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
		$prompt  = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		if ( ! $term_id ) {
			wp_send_json_error( array( 'message' => __( 'Categoria inválida.', 'cluster-engine' ) ) );
		}
		$res = CE61_Categories::generate_image( $term_id, $prompt );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( $res );
	}

	/* ---------- Redirects (Rank Math integrado) ---------- */

	public static function redirects_list() {
		self::guard();
		wp_send_json_success( array(
			'redirects' => CE61_Redirects::list_all(),
			'rankmath'  => CE61_Redirects::rankmath_active(),
		) );
	}

	public static function redirect_add() {
		self::guard();
		$from = isset( $_POST['from'] ) ? esc_url_raw( wp_unslash( $_POST['from'] ) ) : '';
		$to   = isset( $_POST['to'] ) ? esc_url_raw( wp_unslash( $_POST['to'] ) ) : '';
		$code = isset( $_POST['code'] ) ? absint( $_POST['code'] ) : 301;
		if ( '' === $from || '' === $to ) {
			wp_send_json_error( array( 'message' => __( 'Origem e destino são obrigatórios.', 'cluster-engine' ) ) );
		}
		$res = CE61_Redirects::add( $from, $to, $code );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( array( 'id' => $res ) );
	}

	public static function redirect_delete() {
		self::guard();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		wp_send_json_success( array( 'ok' => CE61_Redirects::delete( $id ) ) );
	}

	public static function redirect_toggle() {
		self::guard();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		wp_send_json_success( array( 'status' => CE61_Redirects::toggle( $id ) ) );
	}
}
