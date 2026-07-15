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

	/**
	 * Append a JSON-LD script block to the post content (if not already there).
	 */
	public static function insert_article_schema( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'ce61_no_post', __( 'Post não encontrado.', 'cluster-engine' ) );
		}
		if ( false !== stripos( $post->post_content, 'BlogPosting' ) || false !== stripos( $post->post_content, '"Article"' ) ) {
			return 'already';
		}
		$schema = self::build_article_schema( $post_id );
		if ( ! $schema ) {
			return new WP_Error( 'ce61_schema', __( 'Não foi possível montar o schema.', 'cluster-engine' ) );
		}
		$block   = "\n" . '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>';
		$content = $post->post_content . $block;
		$result  = wp_update_post( array( 'ID' => $post_id, 'post_content' => wp_slash( $content ) ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
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
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$html = wp_kses_post( $html ); // scripts stripped for restricted users.
		}
		$content = $post->post_content . "\n" . $html;
		$result  = wp_update_post( array( 'ID' => $post_id, 'post_content' => wp_slash( $content ) ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return 'inserted';
	}
}
