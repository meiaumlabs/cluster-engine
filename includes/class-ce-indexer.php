<?php
/**
 * CE61_Indexer — reads site content and builds the semantic index.
 *
 * @package ClusterEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CE61_Indexer {

	/**
	 * Portuguese + English stopwords (compact list).
	 */
	public static function stopwords() {
		static $s = null;
		if ( null === $s ) {
			$words = 'a o e é de da do das dos em um uma umas uns para por com sem sob sobre entre até após antes durante que se não sim mais menos muito pouco como quando onde qual quais quem cujo cuja isso isto aquilo este esta esse essa aquele aquela seu sua seus suas meu minha nosso nossa dele dela deles delas eu tu ele ela nós vós eles elas você vocês ao aos à às no na nos nas num numa pelo pela pelos pelas mesmo mesma também já ainda apenas ou mas porém contudo todavia então assim pois porque portanto logo cada todo toda todos todas outro outra outros outras ser estar ter haver fazer pode podem deve devem foi era são está estão tem têm há vai vão ser sido sendo the a an and or but if of to in on for with without at by from as is are was were be been being this that these those it its he she they them his her their you your we our i my me do does did not no yes can could should would will just also very much many few more most other some any all each';
			$s = array_flip( explode( ' ', $words ) );
		}
		return $s;
	}

	/**
	 * Tokenize text into normalized terms (unigrams + bigrams).
	 */
	public static function tokenize( $text, $bigrams = true ) {
		$text = wp_strip_all_tags( strip_shortcodes( (string) $text ) );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = mb_strtolower( $text, 'UTF-8' );
		$text = preg_replace( '/https?:\/\/\S+/u', ' ', $text );
		$text = preg_replace( '/[^\p{L}\p{N}\s\-]/u', ' ', $text );
		$raw  = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );

		$stop  = self::stopwords();
		$terms = array();
		$clean = array();
		foreach ( $raw as $w ) {
			if ( mb_strlen( $w ) < 3 || isset( $stop[ $w ] ) || is_numeric( $w ) ) {
				$clean[] = null;
				continue;
			}
			$clean[] = $w;
			$terms[] = $w;
		}
		if ( $bigrams ) {
			$n = count( $clean );
			for ( $i = 0; $i < $n - 1; $i++ ) {
				if ( $clean[ $i ] && $clean[ $i + 1 ] ) {
					$terms[] = $clean[ $i ] . ' ' . $clean[ $i + 1 ];
				}
			}
		}
		return $terms;
	}

	/**
	 * Build weighted term-frequency map. Title/headings weigh more.
	 */
	public static function build_tf( $post ) {
		$tf = array();

		$add = function ( $terms, $weight ) use ( &$tf ) {
			foreach ( $terms as $t ) {
				$tf[ $t ] = ( isset( $tf[ $t ] ) ? $tf[ $t ] : 0 ) + $weight;
			}
		};

		$content = (string) $post->post_content;

		// Headings weigh 3x, title 4x.
		if ( preg_match_all( '/<h[1-4][^>]*>(.*?)<\/h[1-4]>/is', $content, $m ) ) {
			foreach ( $m[1] as $h ) {
				$add( self::tokenize( $h ), 3 );
			}
		}
		$add( self::tokenize( $post->post_title ), 4 );
		$add( self::tokenize( $content ), 1 );

		// Focus keyword from SEO plugin weighs 5x.
		$fk = CE61_SEO::get_focus_keyword( $post->ID );
		if ( $fk ) {
			$add( self::tokenize( $fk ), 5 );
		}

		// Taxonomy terms (categories, tags, custom taxonomies) weigh 3x to anchor
		// semantic context for CPTs — ensures custom-taxonomy-based clusters form correctly.
		foreach ( get_object_taxonomies( $post->post_type ) as $tax ) {
			$tterms = wp_get_post_terms( $post->ID, $tax, array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $tterms ) && $tterms ) {
				$add( self::tokenize( implode( ' ', $tterms ) ), 3 );
			}
		}

		arsort( $tf );
		return array_slice( $tf, 0, 60, true ); // keep top 60 terms per post.
	}

	/**
	 * Extract internal links (target post IDs) from content.
	 * Tolerant to scheme (http/https) and www variations.
	 */
	public static function extract_internal_links( $content ) {
		$out  = array();
		$home = untrailingslashit( home_url() );
		$host = preg_replace( '/^www\./i', '', wp_parse_url( $home, PHP_URL_HOST ) );
		if ( ! preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $content, $m, PREG_SET_ORDER ) ) {
			return $out;
		}
		foreach ( $m as $link ) {
			$href = trim( $link[1] );
			if ( '' === $href || '#' === $href[0] ) {
				continue;
			}
			if ( 0 === strpos( $href, '/' ) && 0 !== strpos( $href, '//' ) ) {
				$href = $home . $href;
			} elseif ( preg_match( '#^https?://(www\.)?' . preg_quote( $host, '#' ) . '#i', $href ) ) {
				// Normalize scheme/www to the canonical home URL so url_to_postid resolves.
				$href = preg_replace( '#^https?://(www\.)?' . preg_quote( $host, '#' ) . '#i', $home, $href );
			} else {
				continue;
			}
			$pid = url_to_postid( $href );
			if ( $pid ) {
				$anchor      = trim( wp_strip_all_tags( $link[2] ) );
				$out[ $pid ] = mb_substr( $anchor, 0, 120 );
			}
		}
		return $out; // [ target_post_id => anchor_text ]
	}

	/**
	 * Index one post into wp_ce_index.
	 */
	public static function index_post( $post_id ) {
		global $wpdb;
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		$tf    = self::build_tf( $post );
		$links = self::extract_internal_links( $post->post_content );
		$words = str_word_count( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) );

		$main = '';
		foreach ( $tf as $term => $w ) {
			if ( false !== strpos( $term, ' ' ) ) { // prefer a bigram as main keyword.
				$main = $term;
				break;
			}
		}
		if ( ! $main ) {
			$keys = array_keys( $tf );
			$main = $keys ? $keys[0] : '';
		}
		$fk = CE61_SEO::get_focus_keyword( $post_id );
		if ( $fk ) {
			$main = mb_strtolower( $fk );
		}

		$wpdb->replace(
			$wpdb->prefix . 'ce_index',
			array(
				'post_id'      => $post_id,
				'title'        => $post->post_title,
				'word_count'   => (int) $words,
				'main_keyword' => mb_substr( $main, 0, 191 ),
				'keywords'     => wp_json_encode( array_slice( array_keys( $tf ), 0, 20 ), JSON_UNESCAPED_UNICODE ),
				'vector'       => wp_json_encode( $tf, JSON_UNESCAPED_UNICODE ),
				'links_out'    => wp_json_encode( $links ),
				'indexed_at'   => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		return true;
	}

	/**
	 * Resolve the active post-type scope from settings.
	 * An empty (or absent) post_types setting means full coverage:
	 * all public types except 'attachment'.
	 *
	 * @param array|null $settings ce61_settings option (fetched when null).
	 * @return string[] Post type names.
	 */
	public static function resolved_post_types( $settings = null ) {
		if ( null === $settings ) {
			$settings = get_option( 'ce61_settings', array() );
		}
		if ( ! empty( $settings['post_types'] ) && is_array( $settings['post_types'] ) ) {
			return array_values( $settings['post_types'] );
		}
		return array_values( array_diff(
			array_keys( get_post_types( array( 'public' => true ) ) ),
			array( 'attachment' )
		) );
	}

	/**
	 * Get IDs of all indexable published posts.
	 */
	public static function indexable_ids() {
		$settings = get_option( 'ce61_settings', array() );
		$types    = self::resolved_post_types( $settings );
		return get_posts( array(
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) );
	}

	/**
	 * Index a batch. Returns [done, total].
	 */
	public static function index_batch( $offset, $size = 20 ) {
		$ids   = self::indexable_ids();
		$total = count( $ids );
		$slice = array_slice( $ids, $offset, $size );
		foreach ( $slice as $id ) {
			self::index_post( $id );
		}
		return array(
			'done'  => min( $offset + $size, $total ),
			'total' => $total,
		);
	}
}
