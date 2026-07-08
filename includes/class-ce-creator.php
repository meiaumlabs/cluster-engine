<?php
/**
 * CE61_Creator — criação de novos clusters e de conteúdo com base
 * no que o site já publicou.
 *
 * Constrói um "contexto do site" a partir do índice semântico
 * (clusters, termos, títulos) e usa a IA para: sugerir novos clusters,
 * planejar tópicos de artigos por cluster e gerar rascunhos completos,
 * sempre coerentes com o assunto real do site.
 *
 * @package ClusterEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CE61_Creator {

	/* ---------- Contexto do site ---------- */

	/**
	 * Resumo compacto do site para a IA entender do que ele trata:
	 * nome/descrição, clusters existentes com termos, e amostra de títulos.
	 */
	public static function site_context( $max_titles = 40 ) {
		global $wpdb;
		$lines   = array();
		$lines[] = 'Site: ' . get_bloginfo( 'name' ) . ' (' . home_url() . ')';
		$desc    = get_bloginfo( 'description' );
		if ( $desc ) {
			$lines[] = 'Descrição: ' . $desc;
		}

		$clusters = $wpdb->get_results(
			"SELECT id, name, post_count, top_terms, is_custom, description FROM {$wpdb->prefix}ce_clusters ORDER BY post_count DESC",
			ARRAY_A
		);
		if ( $clusters ) {
			$lines[] = "\nClusters de conteúdo já existentes:";
			foreach ( $clusters as $c ) {
				$terms = json_decode( (string) $c['top_terms'], true );
				$terms = is_array( $terms ) ? implode( ', ', array_slice( $terms, 0, 6 ) ) : '';
				$line  = '- ' . $c['name'] . ' (' . (int) $c['post_count'] . ' posts';
				$line .= $c['is_custom'] ? ', criado manualmente' : '';
				$line .= ')' . ( $terms ? ' — termos: ' . $terms : '' );
				if ( ! empty( $c['description'] ) ) {
					$line .= ' — ' . mb_substr( $c['description'], 0, 160 );
				}
				$lines[] = $line;
			}
		}

		$titles = $wpdb->get_col( $wpdb->prepare(
			"SELECT title FROM {$wpdb->prefix}ce_index ORDER BY inbound DESC, word_count DESC LIMIT %d",
			$max_titles
		) );
		if ( $titles ) {
			$lines[] = "\nAmostra de títulos publicados:";
			foreach ( $titles as $t ) {
				$lines[] = '- ' . $t;
			}
		}

		$kws = $wpdb->get_col( "SELECT main_keyword FROM {$wpdb->prefix}ce_index WHERE main_keyword <> '' LIMIT 60" );
		if ( $kws ) {
			$lines[] = "\nPrincipais keywords do site: " . implode( ', ', array_unique( array_slice( $kws, 0, 40 ) ) );
		}

		return mb_substr( implode( "\n", $lines ), 0, 7000 );
	}

	/**
	 * Lista "Título — URL" dos posts mais fortes (do cluster, se houver;
	 * senão do site) para a IA inserir links internos reais no artigo.
	 */
	public static function internal_links_list( $cluster_id = 0, $limit = 8 ) {
		global $wpdb;
		if ( $cluster_id ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT post_id, title FROM {$wpdb->prefix}ce_index WHERE cluster_id = %d ORDER BY is_pillar DESC, inbound DESC LIMIT %d",
				$cluster_id, $limit
			), ARRAY_A );
		} else {
			$rows = array();
		}
		if ( ! $rows ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT post_id, title FROM {$wpdb->prefix}ce_index ORDER BY inbound DESC LIMIT %d", $limit
			), ARRAY_A );
		}
		$out = array();
		foreach ( $rows as $r ) {
			$url = get_permalink( (int) $r['post_id'] );
			if ( $url ) {
				$out[] = '- ' . $r['title'] . ' — ' . $url;
			}
		}
		return implode( "\n", $out );
	}

	/* ---------- Clusters ---------- */

	/**
	 * IA sugere novos clusters coerentes com o assunto do site.
	 * Retorna array de sugestões ou WP_Error.
	 */
	public static function suggest_clusters( $count = 5 ) {
		$context = self::site_context();
		if ( '' === trim( $context ) ) {
			return new WP_Error( 'ce61_ctx', __( 'Escaneie o site primeiro para o plugin entender o conteúdo existente.', 'cluster-engine' ) );
		}
		$result = CE61_AI::run( 'suggest_clusters', 0, array(
			'site_context' => $context,
			'count'        => (int) $count,
		) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data = self::parse_json( $result );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'ce61_json', __( 'A IA não retornou JSON válido. Tente novamente.', 'cluster-engine' ) );
		}
		$out = array();
		foreach ( $data as $item ) {
			if ( empty( $item['name'] ) ) {
				continue;
			}
			$out[] = array(
				'name'        => sanitize_text_field( $item['name'] ),
				'description' => isset( $item['description'] ) ? sanitize_text_field( $item['description'] ) : '',
				'keyword'     => isset( $item['keyword'] ) ? sanitize_text_field( $item['keyword'] ) : '',
				'rationale'   => isset( $item['rationale'] ) ? sanitize_text_field( $item['rationale'] ) : '',
			);
		}
		return $out;
	}

	/**
	 * Cria um cluster manual (is_custom = 1).
	 */
	public static function create_cluster( $name, $description = '', $keyword = '' ) {
		global $wpdb;
		$name = trim( $name );
		if ( '' === $name ) {
			return new WP_Error( 'ce61_name', __( 'Informe o nome do cluster.', 'cluster-engine' ) );
		}
		$wpdb->insert(
			$wpdb->prefix . 'ce_clusters',
			array(
				'name'        => mb_substr( $name, 0, 191 ),
				'description' => $description,
				'is_custom'   => 1,
				'post_count'  => 0,
				'top_terms'   => wp_json_encode( $keyword ? array( $keyword ) : array(), JSON_UNESCAPED_UNICODE ),
			),
			array( '%s', '%s', '%d', '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	public static function delete_cluster( $cluster_id ) {
		global $wpdb;
		$is_custom = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT is_custom FROM {$wpdb->prefix}ce_clusters WHERE id = %d", $cluster_id
		) );
		if ( ! $is_custom ) {
			return new WP_Error( 'ce61_del', __( 'Só clusters criados manualmente podem ser excluídos; os automáticos são recriados no scan.', 'cluster-engine' ) );
		}
		$wpdb->delete( $wpdb->prefix . 'ce_clusters', array( 'id' => $cluster_id ), array( '%d' ) );
		$wpdb->update( $wpdb->prefix . 'ce_index', array( 'cluster_id' => 0 ), array( 'cluster_id' => $cluster_id ) );
		return true;
	}

	/* ---------- Planejamento de conteúdo ---------- */

	/**
	 * IA planeja tópicos de artigos para um cluster (novo ou existente),
	 * com base no contexto do site e no que o cluster já cobre.
	 * O plano fica salvo na coluna `planned` do cluster.
	 */
	public static function plan_topics( $cluster_id, $count = 6, $config = array() ) {
		global $wpdb;
		$cluster = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}ce_clusters WHERE id = %d", $cluster_id
		), ARRAY_A );
		if ( ! $cluster ) {
			return new WP_Error( 'ce61_cluster', __( 'Cluster não encontrado.', 'cluster-engine' ) );
		}

		$existing = $wpdb->get_col( $wpdb->prepare(
			"SELECT title FROM {$wpdb->prefix}ce_index WHERE cluster_id = %d LIMIT 30", $cluster_id
		) );
		$existing_list = $existing ? implode( "\n", array_map( function ( $t ) { return '- ' . $t; }, $existing ) ) : __( '(cluster ainda sem posts publicados)', 'cluster-engine' );

		$word_target = isset( $config['word_count_target'] ) ? max( 300, (int) $config['word_count_target'] ) : 1200;
		$h2_target   = isset( $config['h2_count_target'] ) ? max( 2, (int) $config['h2_count_target'] ) : 6;

		$result = CE61_AI::run( 'plan_cluster_content', 0, array(
			'site_context'        => self::site_context( 25 ),
			'cluster_name'        => $cluster['name'],
			'cluster_description' => (string) $cluster['description'],
			'cluster_posts'       => $existing_list,
			'count'               => (int) $count,
			'word_count_target'   => $word_target,
			'h2_count_target'     => $h2_target,
		) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data = self::parse_json( $result );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'ce61_json', __( 'A IA não retornou JSON válido. Tente novamente.', 'cluster-engine' ) );
		}
		$topics = array();
		foreach ( $data as $item ) {
			if ( empty( $item['title'] ) ) {
				continue;
			}
			$faqs = array();
			if ( ! empty( $item['faqs'] ) && is_array( $item['faqs'] ) ) {
				foreach ( array_slice( $item['faqs'], 0, 6 ) as $q ) {
					$faqs[] = sanitize_text_field( (string) $q );
				}
			}
			$subtopics = array();
			if ( ! empty( $item['subtopics'] ) && is_array( $item['subtopics'] ) ) {
				foreach ( array_slice( $item['subtopics'], 0, 8 ) as $t ) {
					$subtopics[] = sanitize_text_field( (string) $t );
				}
			}
			$topics[] = array(
				'title'      => sanitize_text_field( $item['title'] ),
				'keyword'    => isset( $item['keyword'] ) ? sanitize_text_field( $item['keyword'] ) : '',
				'type'       => ( isset( $item['type'] ) && 'pillar' === $item['type'] ) ? 'pillar' : 'satellite',
				'status'     => 'planned',
				'word_count' => isset( $item['word_count'] ) ? max( 300, (int) $item['word_count'] ) : $word_target,
				'h2_count'   => isset( $item['h2_count'] ) ? max( 2, (int) $item['h2_count'] ) : $h2_target,
				'faqs'       => $faqs,
				'subtopics'  => $subtopics,
			);
		}
		if ( ! $topics ) {
			return new WP_Error( 'ce61_json', __( 'A IA não sugeriu tópicos. Tente novamente.', 'cluster-engine' ) );
		}

		// Acrescenta ao plano existente (incrementa em vez de sobrescrever).
		$planned = json_decode( (string) $cluster['planned'], true );
		$planned = is_array( $planned ) ? $planned : array();
		$titles  = array_map( function ( $t ) { return mb_strtolower( $t['title'] ); }, $planned );
		foreach ( $topics as $t ) {
			if ( ! in_array( mb_strtolower( $t['title'] ), $titles, true ) ) {
				$planned[] = $t;
			}
		}
		$wpdb->update(
			$wpdb->prefix . 'ce_clusters',
			array( 'planned' => wp_json_encode( $planned, JSON_UNESCAPED_UNICODE ) ),
			array( 'id' => $cluster_id )
		);
		return $planned;
	}

	/**
	 * Atualiza o plano salvo do cluster (remoção de tópicos, marcação de status).
	 */
	public static function save_plan( $cluster_id, $planned ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'ce_clusters',
			array( 'planned' => wp_json_encode( array_values( $planned ), JSON_UNESCAPED_UNICODE ) ),
			array( 'id' => $cluster_id )
		);
	}

	/**
	 * Marca um tópico do plano como gerado (guarda o post criado).
	 */
	public static function mark_topic_done( $cluster_id, $topic_title, $post_id ) {
		global $wpdb;
		$planned = json_decode( (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT planned FROM {$wpdb->prefix}ce_clusters WHERE id = %d", $cluster_id
		) ), true );
		if ( ! is_array( $planned ) ) {
			return;
		}
		foreach ( $planned as &$t ) {
			if ( isset( $t['title'] ) && mb_strtolower( $t['title'] ) === mb_strtolower( $topic_title ) ) {
				$t['status']  = 'done';
				$t['post_id'] = (int) $post_id;
			}
		}
		self::save_plan( $cluster_id, $planned );
	}

	/**
	 * Gera um artigo para um tópico de cluster (ou avulso) e salva o post.
	 *
	 * @param array $payload {
	 *   cluster_id, title, keyword, custom_prompt,
	 *   publish ('draft'|'publish'|'schedule'), schedule_at (Y-m-d H:i:s local)
	 * }
	 * @return array|WP_Error [ post_id, edit, title, eeat ]
	 */
	public static function job_generate_article( $payload ) {
		global $wpdb;
		$cluster_id    = isset( $payload['cluster_id'] ) ? (int) $payload['cluster_id'] : 0;
		$title         = isset( $payload['title'] ) ? trim( (string) $payload['title'] ) : '';
		$keyword       = isset( $payload['keyword'] ) ? trim( (string) $payload['keyword'] ) : '';
		$custom_prompt = isset( $payload['custom_prompt'] ) ? trim( (string) $payload['custom_prompt'] ) : '';
		$publish       = isset( $payload['publish'] ) ? $payload['publish'] : 'draft';
		$schedule_at   = isset( $payload['schedule_at'] ) ? $payload['schedule_at'] : '';
		if ( '' === $title ) {
			return new WP_Error( 'ce61_job', __( 'Job sem título de tópico.', 'cluster-engine' ) );
		}

		$cluster = $cluster_id ? $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}ce_clusters WHERE id = %d", $cluster_id
		), ARRAY_A ) : null;

		$pillar_title   = '';
		$pillar_url     = '';
		$pillar_keyword = '';
		if ( $cluster && $cluster['pillar_id'] ) {
			$pid_pillar     = (int) $cluster['pillar_id'];
			$pillar_title   = get_the_title( $pid_pillar );
			$pillar_url     = get_permalink( $pid_pillar );
			$pillar_keyword = CE61_SEO::get_focus_keyword( $pid_pillar );
			if ( ! $pillar_keyword ) {
				$pillar_keyword = (string) $wpdb->get_var( $wpdb->prepare(
					"SELECT main_keyword FROM {$wpdb->prefix}ce_index WHERE post_id = %d", $pid_pillar
				) );
			}
		}

		// Busca o briefing editorial do tópico planejado (volume de palavras,
		// quantidade de H2, FAQs e subtemas sugeridos no planejamento) para a
		// geração seguir exatamente o que foi combinado, não um texto genérico.
		$brief_instructions = '';
		if ( $cluster ) {
			$planned = json_decode( (string) $cluster['planned'], true );
			if ( is_array( $planned ) ) {
				foreach ( $planned as $t ) {
					if ( isset( $t['title'] ) && mb_strtolower( $t['title'] ) === mb_strtolower( $title ) ) {
						$lines = array();
						if ( ! empty( $t['word_count'] ) ) {
							$lines[] = 'Extensão alvo: aproximadamente ' . (int) $t['word_count'] . ' palavras.';
						}
						if ( ! empty( $t['h2_count'] ) ) {
							$lines[] = 'Use aproximadamente ' . (int) $t['h2_count'] . ' subtítulos H2 para estruturar o artigo.';
						}
						if ( ! empty( $t['subtopics'] ) && is_array( $t['subtopics'] ) ) {
							$lines[] = "Cubra obrigatoriamente estes subtemas (um ou mais por H2):\n- " . implode( "\n- ", $t['subtopics'] );
						}
						if ( ! empty( $t['faqs'] ) && is_array( $t['faqs'] ) ) {
							$lines[] = "Responda estas perguntas na seção de Perguntas Frequentes (pode reformular a redação, mas cubra o conteúdo):\n- " . implode( "\n- ", $t['faqs'] );
						}
						if ( $lines ) {
							$brief_instructions = "Briefing editorial deste artigo (planejado previamente):\n" . implode( "\n", $lines );
						}
						break;
					}
				}
			}
		}

		$custom_instructions = $brief_instructions;
		if ( $custom_prompt ) {
			$custom_instructions .= ( $custom_instructions ? "\n\n" : '' )
				. "Instruções adicionais do usuário para este artigo (siga à risca, além dos requisitos acima):\n" . $custom_prompt;
		}

		$html = CE61_AI::run( 'generate_article', 0, array(
			'gap_topic'            => $title,
			'keyword'              => $keyword ? $keyword : $title,
			'cluster_name'         => $cluster ? $cluster['name'] : '',
			'pillar_title'         => $pillar_title,
			'pillar_url'           => $pillar_url,
			'pillar_keyword'       => $pillar_keyword ? $pillar_keyword : $pillar_title,
			'internal_links_list'  => self::internal_links_list( $cluster_id ),
			'site_context'         => self::site_context( 15 ),
			'custom_instructions'  => $custom_instructions,
		) );
		if ( is_wp_error( $html ) ) {
			return $html;
		}

		$post_id = self::create_draft_from_html( $title, $html, $keyword, $cluster_id, $publish, $schedule_at );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		if ( $cluster_id ) {
			self::mark_topic_done( $cluster_id, $title, $post_id );
		}
		return array(
			'post_id' => $post_id,
			// get_edit_post_link() retorna null no cron (sem usuário logado); monta a URL direta.
			'edit'    => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
			'view'    => get_permalink( $post_id ),
			'title'   => $title,
			'keyword' => $keyword,
			'scores'  => self::analyze_scores( $post_id ),
			'status'  => get_post_status( $post_id ),
		);
	}

	/**
	 * Cria o post com SEO completo (keyword foco, meta title, description),
	 * vincula ao cluster de origem, marca como gerado por IA e aplica o
	 * status de publicação escolhido (rascunho, publicar agora, ou agendar).
	 */
	public static function create_draft_from_html( $title, $html, $keyword = '', $cluster_id = 0, $publish = 'draft', $schedule_at = '' ) {
		$content = preg_replace( '/^```(?:html)?\s*|\s*```$/i', '', trim( (string) $html ) );
		$content = wp_kses_post( $content ); // cron roda sem usuário; sanitização segura sempre.
		if ( '' === trim( $content ) ) {
			return new WP_Error( 'ce61_empty', __( 'A IA retornou conteúdo vazio.', 'cluster-engine' ) );
		}

		$args = array(
			'post_title'   => sanitize_text_field( $title ),
			'post_content' => wp_slash( $content ),
			'post_status'  => 'draft',
			'post_type'    => 'post',
		);

		if ( 'schedule' === $publish && $schedule_at ) {
			$ts = strtotime( $schedule_at );
			if ( $ts && $ts > time() ) {
				$args['post_status'] = 'future';
				$args['post_date']     = gmdate( 'Y-m-d H:i:s', $ts + ( (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) );
				$args['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $ts );
			}
			// data inválida ou no passado: cai para rascunho silenciosamente (mais seguro que publicar sem querer).
		} elseif ( 'publish' === $publish ) {
			$args['post_status'] = 'publish';
		}

		$post_id = wp_insert_post( $args, true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		if ( $keyword ) {
			CE61_SEO::set_focus_keyword( $post_id, $keyword );
		}
		CE61_SEO::set_meta_title( $post_id, mb_substr( $title, 0, 60 ) );
		if ( preg_match( '/<p[^>]*>(.*?)<\/p>/is', $content, $m ) ) {
			$first = trim( wp_strip_all_tags( $m[1] ) );
			if ( $first ) {
				CE61_SEO::set_meta_desc( $post_id, mb_substr( $first, 0, 155 ) );
			}
		}
		if ( $cluster_id ) {
			update_post_meta( $post_id, '_ce61_cluster_id', (int) $cluster_id );
		}
		update_post_meta( $post_id, '_ce61_ai_generated', 1 );
		update_post_meta( $post_id, '_ce61_ai_created_at', current_time( 'mysql' ) );

		$scores = self::analyze_scores( $post_id );
		update_post_meta( $post_id, '_ce61_scores', wp_json_encode( $scores, JSON_UNESCAPED_UNICODE ) );

		return (int) $post_id;
	}

	/**
	 * Atualiza o status de publicação de um post já criado (usado pela
	 * seleção "Publicar agora" / "Agendar" na lista de posts gerados por IA).
	 */
	public static function set_publish_status( $post_id, $publish, $schedule_at = '' ) {
		$post_id = (int) $post_id;
		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'ce61_post', __( 'Post não encontrado.', 'cluster-engine' ) );
		}
		$args = array( 'ID' => $post_id );
		if ( 'schedule' === $publish ) {
			$ts = $schedule_at ? strtotime( $schedule_at ) : 0;
			if ( ! $ts || $ts <= time() ) {
				return new WP_Error( 'ce61_date', __( 'Escolha uma data e hora futuras para agendar.', 'cluster-engine' ) );
			}
			$args['post_status']  = 'future';
			$args['post_date']     = gmdate( 'Y-m-d H:i:s', $ts + ( (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) );
			$args['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $ts );
		} elseif ( 'publish' === $publish ) {
			$args['post_status'] = 'publish';
		} else {
			$args['post_status'] = 'draft';
		}
		$result = wp_update_post( $args, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return get_post_status( $post_id );
	}

	/**
	 * Lista os posts gerados pelo Cluster Engine (marcados em _ce61_ai_generated),
	 * mais recentes primeiro, com status, cluster e nota de E-E-A-T.
	 */
	public static function list_ai_posts( $limit = 40 ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_ce61_ai_generated' AND meta_value = '1'
			 ORDER BY post_id DESC LIMIT %d", $limit
		) );
		$out = array();
		foreach ( $ids as $pid ) {
			$pid  = (int) $pid;
			$post = get_post( $pid );
			if ( ! $post ) {
				continue;
			}
			$raw    = get_post_meta( $pid, '_ce61_scores', true );
			$scores = $raw ? json_decode( $raw, true ) : self::analyze_scores( $pid );
			$cid    = (int) get_post_meta( $pid, '_ce61_cluster_id', true );
			$out[]  = array(
				'post_id'      => $pid,
				'title'        => $post->post_title,
				'status'       => $post->post_status,
				'date'         => $post->post_status === 'future' ? $post->post_date : $post->post_modified,
				'edit'         => get_edit_post_link( $pid, 'raw' ),
				'view'         => get_permalink( $pid ),
				'cluster_id'   => $cid,
				'cluster_name' => $cid ? (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}ce_clusters WHERE id = %d", $cid ) ) : '',
				'keyword'      => CE61_SEO::get_focus_keyword( $pid ),
				'scores'       => $scores,
			);
		}
		return $out;
	}

	/**
	 * Extrai os sinais de conteúdo de um post uma única vez (usado pelos
	 * três scorers abaixo, evitando reprocessar o HTML três vezes).
	 */
	private static function extract_signals( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}
		$content = (string) $post->post_content;
		$plain   = wp_strip_all_tags( $content );

		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$home_host = $home_host ? preg_replace( '/^www\./i', '', strtolower( $home_host ) ) : '';
		$has_external = false;
		if ( preg_match_all( '/<a\s[^>]*href=["\']https?:\/\/([^"\'\/]+)/i', $content, $m ) ) {
			foreach ( $m[1] as $h ) {
				$h = preg_replace( '/^www\./i', '', strtolower( $h ) );
				if ( $h && $h !== $home_host ) {
					$has_external = true;
					break;
				}
			}
		}

		$first = '';
		if ( preg_match( '/<p[^>]*>(.*?)<\/p>/is', $content, $m2 ) ) {
			$first = trim( wp_strip_all_tags( $m2[1] ) );
		}

		return array(
			'author_bio'        => (bool) get_the_author_meta( 'description', $post->post_author ),
			'has_external'      => $has_external,
			'has_data'          => (bool) preg_match( '/\d{1,3}([.,]\d+)?\s?%|\bR\$\s?\d|\b(19|20)\d{2}\b/', $plain ),
			'answer_capsule_ok' => mb_strlen( $first ) >= 120 && mb_strlen( $first ) <= 480,
			'has_faq'           => false !== stripos( $content, 'faqpage' ) || (bool) preg_match( '/perguntas\s+frequentes|faq/iu', $content ),
			'has_image'         => (bool) get_the_post_thumbnail_url( $post_id ),
			'has_headings'      => (bool) preg_match( '/<h[2-3][^>]*>/i', $content ),
			'question_headings' => (bool) preg_match( '/<h[2-3][^>]*>[^<]*\?/iu', $content ),
			'has_meta_desc'     => (bool) CE61_SEO::get_meta_desc( $post_id ),
			'has_list_or_table' => (bool) preg_match( '/<(ul|ol|table)[\s>]/i', $content ),
			'word_count'        => str_word_count( $plain ),
			'has_internal_link' => (bool) preg_match( '/<a\s[^>]*href=["\']' . preg_quote( untrailingslashit( home_url() ), '/' ) . '/i', $content ),
			'fresh'             => ( time() - strtotime( $post->post_modified ) ) < ( 12 * MONTH_IN_SECONDS ),
		);
	}

	/**
	 * Três notas independentes calculadas do mesmo conteúdo:
	 * E-E-A-T (Experiência/Expertise/Autoridade/Confiança), AEO (prontidão
	 * para respostas diretas / People Also Ask) e GEO (prontidão para ser
	 * citado por IA generativa). Retorna [ eeat, aeo, geo ], cada um com
	 * { score, issues:[{key,label,weight}], checked_at }.
	 */
	public static function analyze_scores( $post_id ) {
		$now  = current_time( 'mysql' );
		$empty = array( 'score' => 0, 'issues' => array(), 'checked_at' => $now );
		$s = self::extract_signals( $post_id );
		if ( null === $s ) {
			return array( 'eeat' => $empty, 'aeo' => $empty, 'geo' => $empty );
		}

		$build = function ( $checks ) use ( $now ) {
			$score  = 100;
			$issues = array();
			foreach ( $checks as $c ) {
				list( $ok, $key, $label, $weight ) = $c;
				if ( ! $ok ) {
					$issues[] = array( 'key' => $key, 'label' => $label, 'weight' => $weight );
					$score   -= $weight;
				}
			}
			return array( 'score' => max( 0, $score ), 'issues' => $issues, 'checked_at' => $now );
		};

		$eeat = $build( array(
			array( $s['author_bio'], 'no_author_bio', __( 'Autor sem bio publicada no perfil', 'cluster-engine' ), 22 ),
			array( $s['has_external'], 'no_external_sources', __( 'Sem links para fontes externas de autoridade', 'cluster-engine' ), 20 ),
			array( $s['has_data'], 'no_data', __( 'Sem dados, números ou estatísticas concretas', 'cluster-engine' ), 18 ),
			array( $s['has_image'], 'no_image', __( 'Sem imagem destacada', 'cluster-engine' ), 15 ),
			array( $s['has_headings'], 'no_headings', __( 'Sem subtítulos estruturando o conteúdo', 'cluster-engine' ), 13 ),
			array( $s['fresh'], 'stale', __( 'Conteúdo não é atualizado há mais de 12 meses', 'cluster-engine' ), 12 ),
		) );

		$aeo = $build( array(
			array( $s['answer_capsule_ok'], 'no_answer_capsule', __( 'Abertura fora do formato de resposta direta (40-60 palavras)', 'cluster-engine' ), 30 ),
			array( $s['question_headings'], 'no_question_headings', __( 'Sem subtítulos em formato de pergunta (People Also Ask)', 'cluster-engine' ), 25 ),
			array( $s['has_faq'], 'no_faq', __( 'Sem seção de Perguntas Frequentes', 'cluster-engine' ), 25 ),
			array( $s['has_meta_desc'], 'no_meta_desc', __( 'Sem meta description (usada como snippet de resposta)', 'cluster-engine' ), 20 ),
		) );

		$geo = $build( array(
			array( $s['has_external'], 'no_external_sources', __( 'Sem fontes externas citáveis (reduz confiança da IA generativa)', 'cluster-engine' ), 25 ),
			array( $s['has_data'], 'no_data', __( 'Sem dados/estatísticas concretas para a IA citar', 'cluster-engine' ), 20 ),
			array( $s['has_list_or_table'], 'no_structure', __( 'Sem listas ou tabelas (IA generativa prefere conteúdo estruturado)', 'cluster-engine' ), 20 ),
			array( $s['word_count'] >= 600, 'thin_for_geo', __( 'Conteúdo raso demais para cobertura completa do tema (menos de 600 palavras)', 'cluster-engine' ), 20 ),
			array( $s['has_internal_link'], 'no_internal_link', __( 'Sem link interno para o resto do site (isola o artigo do cluster)', 'cluster-engine' ), 15 ),
		) );

		return array( 'eeat' => $eeat, 'aeo' => $aeo, 'geo' => $geo );
	}

	/**
	 * Compatibilidade: só a nota de E-E-A-T.
	 */
	public static function estimate_eeat( $post_id ) {
		$s = self::analyze_scores( $post_id );
		return $s['eeat'];
	}

	/**
	 * Cria a instrução base de prompt (título + keyword) usada como ponto de
	 * partida quando o usuário ainda não escreveu nada no campo de prompt.
	 */
	public static function default_prompt_seed( $title, $keyword ) {
		$seed = 'Escreva um artigo completo e aprofundado sobre "' . $title . '"';
		if ( $keyword ) {
			$seed .= ', com foco na keyword "' . $keyword . '"';
		}
		$seed .= '. Inclua exemplos práticos, dados concretos com fonte, e uma perspectiva própria (não genérica).';
		return $seed;
	}

	/* ---------- Util ---------- */

	/**
	 * Extrai JSON de uma resposta da IA (tolerante a ```json e a texto ao redor).
	 */
	public static function parse_json( $text ) {
		$text = trim( (string) $text );
		$text = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $text );
		$data = json_decode( $text, true );
		if ( is_array( $data ) ) {
			return $data;
		}
		// Fallback: primeiro bloco [ ... ] ou { ... } encontrado.
		if ( preg_match( '/\[[\s\S]*\]|\{[\s\S]*\}/', $text, $m ) ) {
			$data = json_decode( $m[0], true );
			if ( is_array( $data ) ) {
				return $data;
			}
		}
		return null;
	}
}
