<?php
/**
 * CE61_Schema — reads rendered JSON-LD from pages, audits gaps, inserts fixes.
 *
 * @package ClusterEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CE61_Schema {

	/**
	 * Fetch the rendered page and extract all JSON-LD blocks.
	 * Returns [ 'types' => [], 'broken' => int, 'error' => string|null, 'raw' => [] ]
	 */
	public static function read_page( $post_id ) {
		$url = get_permalink( $post_id );
		if ( ! $url ) {
			return array( 'types' => array(), 'broken' => 0, 'error' => 'no_url' );
		}
		$res = wp_remote_get( $url, array( 'timeout' => 15, 'redirection' => 3 ) );
		if ( is_wp_error( $res ) ) {
			return array( 'types' => array(), 'broken' => 0, 'error' => $res->get_error_message() );
		}
		$html = wp_remote_retrieve_body( $res );
		if ( ! $html ) {
			return array( 'types' => array(), 'broken' => 0, 'error' => 'empty_body' );
		}

		$types  = array();
		$broken = 0;
		$nodes  = array();

		if ( preg_match_all( '/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $m ) ) {
			foreach ( $m[1] as $block ) {
				$data = json_decode( trim( $block ), true );
				if ( null === $data ) {
					$broken++;
					continue;
				}
				self::collect_nodes( $data, $nodes );
			}
		}
		foreach ( $nodes as $node ) {
			if ( isset( $node['@type'] ) ) {
				foreach ( (array) $node['@type'] as $t ) {
					$types[ $t ] = $node; // last node of each type wins; enough for auditing.
				}
			}
		}
		return array( 'types' => $types, 'broken' => $broken, 'error' => null );
	}

	/**
	 * Flatten @graph / nested arrays into a list of nodes.
	 */
	private static function collect_nodes( $data, &$nodes ) {
		if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
			foreach ( $data['@graph'] as $n ) {
				self::collect_nodes( $n, $nodes );
			}
			return;
		}
		if ( isset( $data[0] ) && is_array( $data[0] ) ) {
			foreach ( $data as $n ) {
				self::collect_nodes( $n, $nodes );
			}
			return;
		}
		if ( is_array( $data ) ) {
			$nodes[] = $data;
		}
	}

	/**
	 * Audit one post: which schema exists, which is broken, which is missing.
	 * Persists the result in postmeta _ce61_schema_audit.
	 */
	public static function audit_post( $post_id ) {
		$read   = self::read_page( $post_id );
		$issues = array();
		$found  = array_keys( $read['types'] );

		if ( $read['error'] ) {
			$issues[] = 'fetch_failed';
		} else {
			if ( $read['broken'] > 0 ) {
				$issues[] = 'broken_schema';
			}
			$article_types = array( 'Article', 'BlogPosting', 'NewsArticle' );
			$article       = null;
			foreach ( $article_types as $t ) {
				if ( isset( $read['types'][ $t ] ) ) {
					$article = $read['types'][ $t ];
					break;
				}
			}
			if ( ! $article ) {
				$issues[] = 'no_article_schema';
			} else {
				if ( empty( $article['author'] ) )        { $issues[] = 'article_no_author'; }
				if ( empty( $article['datePublished'] ) ) { $issues[] = 'article_no_date'; }
				if ( empty( $article['image'] ) )         { $issues[] = 'article_no_image'; }
			}
			if ( ! isset( $read['types']['FAQPage'] ) ) {
				$issues[] = 'no_faq_schema';
			}
			if ( ! isset( $read['types']['BreadcrumbList'] ) ) {
				$issues[] = 'no_breadcrumb';
			}
			if ( ! $found && ! $read['broken'] ) {
				$issues = array( 'no_schema' );
			}
		}

		// Schema exposto como texto no corpo: JSON-LD pelado (sem <script>) ou
		// blocos <script> deixados dentro do conteúdo. A leitura da página
		// renderizada não pega o JSON pelado, então checamos o post_content.
		$post = get_post( $post_id );
		if ( $post ) {
			$body = (string) $post->post_content;
			$has_inline_script = (bool) preg_match( '/<script[^>]*type=["\']application\/ld\+json["\']/i', $body );
			$stripped = preg_replace( '/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>.*?<\/script>/is', '', $body );
			if ( $has_inline_script || self::has_naked_jsonld( (string) $stripped ) ) {
				if ( ! in_array( 'exposed_schema', $issues, true ) ) {
					$issues[] = 'exposed_schema';
				}
			}
		}

		$audit = array(
			'types'      => array_values( $found ),
			'issues'     => $issues,
			'checked_at' => current_time( 'mysql' ),
		);
		update_post_meta( $post_id, '_ce61_schema_audit', wp_json_encode( $audit, JSON_UNESCAPED_UNICODE ) );
		return $audit;
	}

	/**
	 * Tipos de conteúdo (post types) presentes no índice, com contagem,
	 * para o usuário escolher o que auditar antes de rodar a fila.
	 */
	public static function indexed_post_types() {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT p.post_type AS type, COUNT(*) AS n
			 FROM {$wpdb->prefix}ce_index i
			 JOIN {$wpdb->posts} p ON p.ID = i.post_id
			 GROUP BY p.post_type ORDER BY n DESC",
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $r ) {
			$obj   = get_post_type_object( $r['type'] );
			$out[] = array(
				'type'  => $r['type'],
				'label' => $obj ? $obj->labels->name : $r['type'],
				'count' => (int) $r['n'],
			);
		}
		return $out;
	}

	/**
	 * Monta a lista estável de IDs a auditar, filtrando por post types e,
	 * opcionalmente, pulando páginas já auditadas nos últimos N dias.
	 */
	private static function build_audit_queue( $types, $skip_recent_days ) {
		global $wpdb;
		$where  = '';
		$params = array();
		if ( ! empty( $types ) ) {
			$place  = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			$where  = "WHERE p.post_type IN ($place)";
			$params = $types;
		}
		$sql = "SELECT i.post_id FROM {$wpdb->prefix}ce_index i
				JOIN {$wpdb->posts} p ON p.ID = i.post_id
				$where ORDER BY i.post_id ASC";
		$ids = $params
			? $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) // phpcs:ignore WordPress.DB.PreparedSQL
			: $wpdb->get_col( $sql );
		$ids = array_map( 'intval', $ids );

		if ( $skip_recent_days > 0 ) {
			$cutoff = time() - ( $skip_recent_days * DAY_IN_SECONDS );
			$ids    = array_values( array_filter( $ids, function ( $id ) use ( $cutoff ) {
				$meta = get_post_meta( $id, '_ce61_schema_audit', true );
				if ( ! $meta ) {
					return true; // nunca auditado.
				}
				$audit = json_decode( $meta, true );
				if ( ! is_array( $audit ) || empty( $audit['checked_at'] ) ) {
					return true;
				}
				return strtotime( $audit['checked_at'] ) < $cutoff;
			} ) );
		}
		return $ids;
	}

	/**
	 * Audita um lote de posts. Na primeira chamada (offset 0) monta e congela a
	 * fila num transient, para a paginação permanecer estável mesmo com filtros
	 * (post types, pular já auditadas). Retorna [done, total].
	 */
	public static function audit_batch( $offset, $size = 5, $types = array(), $skip_recent_days = 0 ) {
		$key = 'ce61_schema_queue';
		if ( 0 === (int) $offset ) {
			$ids = self::build_audit_queue( $types, $skip_recent_days );
			set_transient( $key, $ids, HOUR_IN_SECONDS );
		} else {
			$ids = get_transient( $key );
			if ( ! is_array( $ids ) ) {
				$ids = self::build_audit_queue( $types, $skip_recent_days );
			}
		}

		$total = count( $ids );
		$slice = array_slice( $ids, $offset, $size );
		foreach ( $slice as $id ) {
			self::audit_post( (int) $id );
		}

		$done = min( $offset + $size, $total );
		if ( $done >= $total ) {
			delete_transient( $key );
		}
		return array( 'done' => $done, 'total' => $total );
	}

	/**
	 * Collected audit results for the panel.
	 */
	public static function results() {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT post_id, title FROM {$wpdb->prefix}ce_index ORDER BY title ASC", ARRAY_A );
		$out  = array();
		foreach ( $rows as $r ) {
			$meta = get_post_meta( (int) $r['post_id'], '_ce61_schema_audit', true );
			if ( ! $meta ) {
				continue;
			}
			$audit = json_decode( $meta, true );
			if ( ! is_array( $audit ) ) {
				continue;
			}
			$out[] = array(
				'post_id' => (int) $r['post_id'],
				'title'   => $r['title'],
				'types'   => isset( $audit['types'] ) ? $audit['types'] : array(),
				'issues'  => isset( $audit['issues'] ) ? $audit['issues'] : array(),
				'edit'    => get_edit_post_link( (int) $r['post_id'], 'raw' ),
			);
		}
		return $out;
	}

	/**
	 * Deterministic Article/BlogPosting JSON-LD built from real post data.
	 */
	public static function build_article_schema( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}
		$image  = get_the_post_thumbnail_url( $post_id, 'full' );
		$schema = array(
			'@context'      => 'https://schema.org',
			'@type'         => 'BlogPosting',
			'headline'      => wp_strip_all_tags( get_the_title( $post_id ) ),
			'url'           => get_permalink( $post_id ),
			'datePublished' => get_the_date( 'c', $post_id ),
			'dateModified'  => get_the_modified_date( 'c', $post_id ),
			'author'        => array(
				'@type' => 'Person',
				'name'  => get_the_author_meta( 'display_name', $post->post_author ),
				'url'   => get_author_posts_url( $post->post_author ),
			),
			'publisher'     => array(
				'@type' => 'Organization',
				'name'  => get_bloginfo( 'name' ),
				'url'   => home_url(),
			),
			'mainEntityOfPage' => get_permalink( $post_id ),
		);
		$desc = CE61_SEO::get_meta_desc( $post_id );
		if ( $desc ) {
			$schema['description'] = $desc;
		}
		if ( $image ) {
			$schema['image'] = $image;
		}
		$bio = get_the_author_meta( 'description', $post->post_author );
		if ( $bio ) {
			$schema['author']['description'] = $bio;
		}
		return $schema;
	}

	/* ---------- Campo nativo de schema do Cluster Engine ---------- */

	/**
	 * Chave do campo personalizado (postmeta) onde o Cluster Engine guarda o
	 * schema JSON-LD quando não há Rank Math. Estrutura: mapa [ Tipo => nó ],
	 * ex.: { "BlogPosting": {...}, "FAQPage": {...} }. O nó é gravado sem
	 * @context (adicionado na saída) e reexecutar um tipo atualiza em vez de
	 * duplicar. A saída acontece no wp_head como <script type="application/ld+json">,
	 * então o código NUNCA aparece como texto dentro do conteúdo do post.
	 */
	const NATIVE_META = '_ce61_schema';

	/**
	 * Lê o mapa de schema nativo do post. Retorna [] se não houver.
	 */
	public static function get_native_schema( $post_id ) {
		$raw  = get_post_meta( $post_id, self::NATIVE_META, true );
		$data = $raw ? json_decode( $raw, true ) : array();
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Grava/atualiza um nó no campo nativo, indexado por @type.
	 */
	public static function save_native_schema( $post_id, $type, $node ) {
		$type = preg_replace( '/[^A-Za-z0-9]/', '', (string) $type );
		if ( '' === $type || ! is_array( $node ) ) {
			return false;
		}
		unset( $node['@context'] ); // adicionado na saída (render_head).
		$node['@type'] = $type;
		$map           = self::get_native_schema( $post_id );
		$map[ $type ]  = $node;
		update_post_meta( $post_id, self::NATIVE_META, wp_json_encode( $map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		return true;
	}

	/**
	 * Remove um tipo (ou todo o campo, se $type vazio) do schema nativo.
	 */
	public static function delete_native_schema( $post_id, $type = '' ) {
		if ( '' === $type ) {
			delete_post_meta( $post_id, self::NATIVE_META );
			return;
		}
		$type = preg_replace( '/[^A-Za-z0-9]/', '', (string) $type );
		$map  = self::get_native_schema( $post_id );
		unset( $map[ $type ] );
		if ( $map ) {
			update_post_meta( $post_id, self::NATIVE_META, wp_json_encode( $map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		} else {
			delete_post_meta( $post_id, self::NATIVE_META );
		}
	}

	/**
	 * Imprime o schema nativo como <script type="application/ld+json"> no <head>
	 * das páginas singulares. Com Rank Math ativo, o schema vive no campo do
	 * Rank Math (que já cuida da saída), então aqui não fazemos nada para evitar
	 * duplicidade. Barras são escapadas (sem JSON_UNESCAPED_SLASHES) para que um
	 * eventual "</script>" no conteúdo não quebre a tag.
	 *
	 * Registrado em cluster-engine.php: add_action( 'wp_head', ..., 91 ).
	 */
	public static function render_head() {
		if ( self::is_rankmath() || ! is_singular() ) {
			return;
		}
		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}
		$map = self::get_native_schema( $post_id );
		if ( ! $map ) {
			return;
		}
		foreach ( $map as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( empty( $node['@context'] ) ) {
				$node = array( '@context' => 'https://schema.org' ) + $node;
			}
			echo "\n" . '<script type="application/ld+json">' . wp_json_encode( $node, JSON_UNESCAPED_UNICODE ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Grava um nó de schema no destino correto: campo do Rank Math quando ele
	 * está ativo, senão o campo nativo. Ponto único usado por toda inserção e
	 * pelo reparo, garantindo que o schema nunca fique como texto no conteúdo.
	 */
	public static function store_node( $post_id, $type, $node, $title = '' ) {
		if ( self::is_rankmath() ) {
			return self::save_rm_schema( $post_id, $type, $node, $title ? $title : $type, false );
		}
		return self::save_native_schema( $post_id, $type, $node );
	}

	/* ---------- Integração com o campo de schema do Rank Math ---------- */

	/**
	 * Rank Math ativo? Quando ativo, o schema é gravado no campo de schema do
	 * Rank Math (postmeta rank_math_schema_*) em vez de virar <script> no corpo
	 * do post — assim o schema não aparece como texto dentro do conteúdo.
	 */
	public static function is_rankmath() {
		return defined( 'RANK_MATH_VERSION' );
	}

	/**
	 * Grava um schema no campo do Rank Math, com o invólucro "metadata" que o
	 * Rank Math espera. Usa uma chave estável por tipo (rank_math_schema_<Type>),
	 * então reexecutar atualiza em vez de duplicar. Registra a chave em
	 * _ce61_rm_schema_keys para permitir remoção posterior sem tocar em schemas
	 * criados pelo próprio usuário.
	 */
	public static function save_rm_schema( $post_id, $type, $schema, $title = '', $primary = false ) {
		$type  = preg_replace( '/[^A-Za-z0-9]/', '', (string) $type );
		$title = $title ? $title : $type;
		$key   = 'rank_math_schema_' . $type;

		unset( $schema['@context'] );
		$schema['@type']    = $type;
		$schema['metadata'] = array(
			'title'     => $title,
			'type'      => 'custom',
			'shortcode' => 's-' . substr( md5( $post_id . $type . microtime() ), 0, 8 ),
			'isPrimary' => $primary ? 1 : 0,
		);

		update_post_meta( $post_id, $key, $schema );

		$keys = get_post_meta( $post_id, '_ce61_rm_schema_keys', true );
		$keys = is_array( $keys ) ? $keys : array();
		if ( ! in_array( $key, $keys, true ) ) {
			$keys[] = $key;
			update_post_meta( $post_id, '_ce61_rm_schema_keys', $keys );
		}
		return $key;
	}

	/**
	 * Remove blocos <script type="application/ld+json"> do conteúdo. Se $needles
	 * for informado, remove apenas os blocos cujo texto contenha um dos termos
	 * (ex.: "BlogPosting", "FAQPage"); vazio remove todos. Usado para migrar o
	 * schema do corpo do post para o campo do Rank Math.
	 */
	public static function strip_content_schema( $post_id, $needles = array() ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		$content = $post->post_content;
		$new     = preg_replace_callback(
			'/\s*<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is',
			function ( $m ) use ( $needles ) {
				if ( empty( $needles ) ) {
					return '';
				}
				foreach ( $needles as $n ) {
					if ( false !== stripos( $m[1], $n ) ) {
						return '';
					}
				}
				return $m[0];
			},
			$content
		);
		$new = preg_replace( "/\n{3,}/", "\n\n", (string) $new );
		if ( null !== $new && $new !== $content ) {
			wp_update_post( array( 'ID' => $post_id, 'post_content' => wp_slash( trim( $new ) ) ), false );
		}
	}

	/**
	 * Já existe schema de artigo (no campo do Rank Math ou no conteúdo)?
	 */
	private static function has_article_schema( $post_id ) {
		if ( self::is_rankmath() ) {
			foreach ( array( 'BlogPosting', 'Article', 'NewsArticle' ) as $t ) {
				if ( get_post_meta( $post_id, 'rank_math_schema_' . $t, true ) ) {
					return true;
				}
			}
		} else {
			$native = self::get_native_schema( $post_id );
			foreach ( array( 'BlogPosting', 'Article', 'NewsArticle' ) as $t ) {
				if ( isset( $native[ $t ] ) ) {
					return true;
				}
			}
		}
		$post = get_post( $post_id );
		if ( $post && ( false !== stripos( $post->post_content, 'BlogPosting' ) || false !== stripos( $post->post_content, '"Article"' ) ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Remove o schema gerido pelo Cluster Engine: apaga os campos de schema do
	 * Rank Math que este plugin criou (registrados em _ce61_rm_schema_keys) e
	 * retira do conteúdo quaisquer blocos JSON-LD.
	 */
	public static function remove_schema( $post_id ) {
		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'ce61_no_post', __( 'Post não encontrado.', 'cluster-engine' ) );
		}
		$keys = get_post_meta( $post_id, '_ce61_rm_schema_keys', true );
		if ( is_array( $keys ) ) {
			foreach ( $keys as $key ) {
				if ( 0 === strpos( (string) $key, 'rank_math_schema_' ) ) {
					delete_post_meta( $post_id, $key );
				}
			}
		}
		delete_post_meta( $post_id, '_ce61_rm_schema_keys' );
		self::delete_native_schema( $post_id ); // campo nativo do Cluster Engine.
		self::strip_content_schema( $post_id, array() );
		return 'removed';
	}

	/**
	 * Insere o schema de Article/BlogPosting. Com Rank Math ativo, grava no
	 * campo de schema do Rank Math (e limpa o JSON-LD equivalente do conteúdo);
	 * sem Rank Math, anexa o bloco <script> ao conteúdo (comportamento antigo).
	 */
	public static function insert_article_schema( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'ce61_no_post', __( 'Post não encontrado.', 'cluster-engine' ) );
		}
		if ( self::has_article_schema( $post_id ) ) {
			return 'already';
		}
		$schema = self::build_article_schema( $post_id );
		if ( ! $schema ) {
			return new WP_Error( 'ce61_schema', __( 'Não foi possível montar o schema.', 'cluster-engine' ) );
		}

		if ( self::is_rankmath() ) {
			self::save_rm_schema( $post_id, $schema['@type'], $schema, 'BlogPosting', true );
			self::strip_content_schema( $post_id, array( 'BlogPosting', '"Article"', 'NewsArticle' ) );
			return 'inserted';
		}

		// Sem Rank Math: grava no campo nativo (_ce61_schema), que é impresso
		// como <script> no <head>. Nunca injeta JSON-LD no corpo do post.
		self::save_native_schema( $post_id, 'BlogPosting', $schema );
		self::strip_content_schema( $post_id, array( 'BlogPosting', '"Article"', 'NewsArticle' ) );
		return 'inserted';
	}

	/**
	 * Aplica a FAQ gerada por IA. Extrai o FAQPage do JSON-LD e grava no campo
	 * de schema (Rank Math quando ativo, senão o campo nativo do Cluster Engine),
	 * adicionando ao corpo apenas a seção de perguntas visível — o JSON-LD nunca
	 * fica como <script> nem como texto dentro do conteúdo.
	 */
	public static function apply_faq( $post_id, $ai_html ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'ce61_no_post', __( 'Post não encontrado.', 'cluster-engine' ) );
		}
		$html = preg_replace( '/^```(?:html)?\s*|\s*```$/i', '', trim( (string) $ai_html ) );

		// Extrai as perguntas do(s) bloco(s) FAQPage em JSON-LD (independe de Rank Math).
		$main_entity = array();
		if ( preg_match_all( '/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $mm ) ) {
			foreach ( $mm[1] as $block ) {
				$data  = json_decode( trim( $block ), true );
				$nodes = array();
				if ( is_array( $data ) ) {
					self::collect_nodes( $data, $nodes );
				}
				foreach ( $nodes as $node ) {
					$types = isset( $node['@type'] ) ? (array) $node['@type'] : array();
					if ( in_array( 'FAQPage', $types, true ) && ! empty( $node['mainEntity'] ) ) {
						foreach ( (array) $node['mainEntity'] as $q ) {
							$name   = isset( $q['name'] ) ? wp_strip_all_tags( $q['name'] ) : '';
							$answer = isset( $q['acceptedAnswer']['text'] ) ? wp_kses_post( $q['acceptedAnswer']['text'] ) : '';
							if ( '' !== $name && '' !== $answer ) {
								$main_entity[] = array(
									'@type'          => 'Question',
									'name'           => $name,
									'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $answer ),
								);
							}
						}
					}
				}
			}
		}

		// Sem JSON-LD utilizável: só o HTML visível vai para o corpo. O append_html
		// já colhe qualquer JSON-LD residual para o campo, nunca deixando texto cru.
		if ( empty( $main_entity ) ) {
			return self::append_html( $post_id, $html );
		}

		// Grava o FAQPage no campo (Rank Math ou nativo) — nunca como <script> no corpo.
		self::store_node( $post_id, 'FAQPage', array( '@type' => 'FAQPage', 'mainEntity' => $main_entity ), 'FAQ' );

		// Só a FAQ visível (sem o <script>) vai para o corpo do post.
		$visible = preg_replace( '/\s*<script[^>]*type=["\']application\/ld\+json["\'][^>]*>.*?<\/script>/is', '', $html );
		$visible = trim( (string) $visible );
		if ( '' !== $visible ) {
			if ( ! current_user_can( 'unfiltered_html' ) ) {
				$visible = wp_kses_post( $visible );
			}
			$content = $post->post_content . "\n" . $visible;
			$upd     = wp_update_post( array( 'ID' => $post_id, 'post_content' => wp_slash( $content ) ), true );
			if ( is_wp_error( $upd ) ) {
				return $upd;
			}
		}
		return 'inserted';
	}

	/**
	 * Append AI-generated HTML (FAQ + FAQPage schema) to the post content.
	 */
	public static function append_html( $post_id, $html ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'ce61_no_post', __( 'Post não encontrado.', 'cluster-engine' ) );
		}
		// Strip markdown fences the model may add.
		$html = preg_replace( '/^```(?:html)?\s*|\s*```$/i', '', trim( $html ) );
		// Colhe qualquer JSON-LD embutido para o campo de schema e remove os
		// blocos <script> do HTML. Isso evita o bug em que o wp_kses_post abaixo
		// removeria a tag <script> e deixaria o JSON como texto puro no corpo.
		$html = self::harvest_jsonld( $post_id, $html );
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$html = wp_kses_post( $html ); // scripts stripped for restricted users.
		}
		$html = trim( (string) $html );
		if ( '' === $html ) {
			return 'inserted'; // só havia JSON-LD; já foi para o campo.
		}
		$content = $post->post_content . "\n" . $html;
		$result  = wp_update_post( array( 'ID' => $post_id, 'post_content' => wp_slash( $content ) ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return 'inserted';
	}

	/**
	 * Move todo JSON-LD embutido em <script> do HTML para o campo de schema
	 * (Rank Math ou nativo) e devolve o HTML sem esses blocos — garantindo que
	 * o JSON nunca sobre como texto no corpo do post, mesmo após sanitização.
	 */
	private static function harvest_jsonld( $post_id, $html ) {
		if ( ! preg_match_all( '/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', (string) $html, $m ) ) {
			return $html;
		}
		foreach ( $m[1] as $block ) {
			$data = json_decode( trim( $block ), true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$nodes = array();
			self::collect_nodes( $data, $nodes );
			foreach ( $nodes as $node ) {
				if ( empty( $node['@type'] ) ) {
					continue;
				}
				$type = is_array( $node['@type'] ) ? reset( $node['@type'] ) : $node['@type'];
				self::store_node( $post_id, $type, $node );
			}
		}
		$html = preg_replace( '/\s*<script[^>]*type=["\']application\/ld\+json["\'][^>]*>.*?<\/script>/is', '', $html );
		return (string) $html;
	}

	/* ---------- Reparo de schema exposto no corpo do post ---------- */

	/**
	 * Repara schema exposto no conteúdo do post movendo-o para o campo de schema
	 * (Rank Math ou nativo) e limpando o corpo. Trata dois casos:
	 *   1) blocos <script type="application/ld+json"> dentro do conteúdo;
	 *   2) JSON-LD "pelado" — texto puro sem <script>, resultado de uma
	 *      sanitização que removeu a tag e deixou o JSON visível.
	 * Retorna [ 'moved' => int, 'naked' => int ] ou WP_Error.
	 */
	public static function repair_content_schema( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'ce61_no_post', __( 'Post não encontrado.', 'cluster-engine' ) );
		}
		$content = (string) $post->post_content;
		$moved   = 0;
		$naked   = 0;

		// 1) Blocos <script> JSON-LD → campo, depois removidos do corpo.
		if ( preg_match_all( '/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $content, $m ) ) {
			foreach ( $m[1] as $block ) {
				$data = json_decode( trim( $block ), true );
				if ( ! is_array( $data ) ) {
					continue;
				}
				$nodes = array();
				self::collect_nodes( $data, $nodes );
				foreach ( $nodes as $node ) {
					if ( empty( $node['@type'] ) ) {
						continue;
					}
					$type = is_array( $node['@type'] ) ? reset( $node['@type'] ) : $node['@type'];
					self::store_node( $post_id, $type, $node );
					$moved++;
				}
			}
			$content = preg_replace( '/\s*<script[^>]*type=["\']application\/ld\+json["\'][^>]*>.*?<\/script>/is', '', $content );
		}

		// 2) JSON-LD pelado (sem <script>).
		foreach ( self::extract_naked_jsonld( $content ) as $item ) {
			$nodes = array();
			self::collect_nodes( $item['json'], $nodes );
			foreach ( $nodes as $node ) {
				if ( empty( $node['@type'] ) ) {
					continue;
				}
				$type = is_array( $node['@type'] ) ? reset( $node['@type'] ) : $node['@type'];
				self::store_node( $post_id, $type, $node );
			}
			$content = str_replace( $item['raw'], '', $content );
			$naked++;
		}

		if ( ( $moved + $naked ) > 0 ) {
			$content = preg_replace( '#<p>\s*</p>#i', '', $content );        // parágrafos que ficaram vazios.
			$content = preg_replace( "/\n{3,}/", "\n\n", (string) $content );
			wp_update_post( array( 'ID' => $post_id, 'post_content' => wp_slash( trim( $content ) ) ), false );
		}
		return array( 'moved' => $moved, 'naked' => $naked );
	}

	/**
	 * Há JSON-LD pelado (texto cru schema.org fora de <script>) no HTML dado?
	 * Espera-se que o chamador já tenha removido os blocos <script>.
	 */
	public static function has_naked_jsonld( $html ) {
		return ! empty( self::extract_naked_jsonld( (string) $html ) );
	}

	/**
	 * Localiza objetos JSON-LD "pelados" (schema.org como texto puro) no HTML.
	 * Assume que blocos <script> já foram removidos. Para cada ocorrência de
	 * "schema.org", encontra a chave de abertura anterior e faz o balanceamento
	 * de chaves (respeitando strings) para isolar o objeto completo. Retorna
	 * [ [ 'raw' => trecho original, 'json' => array decodificado ], ... ].
	 */
	private static function extract_naked_jsonld( $content ) {
		$out    = array();
		$offset = 0;
		$len    = strlen( $content );
		$seen   = array();
		while ( $offset < $len ) {
			$pos = stripos( $content, 'schema.org', $offset );
			if ( false === $pos ) {
				break;
			}
			$start = strrpos( substr( $content, 0, $pos ), '{' );
			if ( false === $start ) {
				$offset = $pos + 10;
				continue;
			}
			$end = self::match_brace( $content, $start );
			if ( false === $end ) {
				$offset = $pos + 10;
				continue;
			}
			$raw     = substr( $content, $start, $end - $start + 1 );
			$decoded = json_decode( html_entity_decode( $raw, ENT_QUOTES, 'UTF-8' ), true );
			if ( is_array( $decoded ) && ( isset( $decoded['@context'] ) || isset( $decoded['@type'] ) || isset( $decoded['@graph'] ) ) ) {
				if ( ! isset( $seen[ $raw ] ) ) {
					$out[]        = array( 'raw' => $raw, 'json' => $decoded );
					$seen[ $raw ] = 1;
				}
				$offset = $end + 1;
			} else {
				$offset = $pos + 10;
			}
		}
		return $out;
	}

	/**
	 * Dado o índice de uma "{" em $s, devolve o índice da "}" que a fecha,
	 * respeitando strings entre aspas e escapes. false se não fechar.
	 */
	private static function match_brace( $s, $start ) {
		$len    = strlen( $s );
		$depth  = 0;
		$in_str = false;
		$esc    = false;
		for ( $i = $start; $i < $len; $i++ ) {
			$ch = $s[ $i ];
			if ( $in_str ) {
				if ( $esc ) {
					$esc = false;
				} elseif ( '\\' === $ch ) {
					$esc = true;
				} elseif ( '"' === $ch ) {
					$in_str = false;
				}
				continue;
			}
			if ( '"' === $ch ) {
				$in_str = true;
			} elseif ( '{' === $ch ) {
				$depth++;
			} elseif ( '}' === $ch ) {
				$depth--;
				if ( 0 === $depth ) {
					return $i;
				}
			}
		}
		return false;
	}
}
