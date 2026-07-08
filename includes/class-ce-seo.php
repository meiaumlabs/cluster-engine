<?php
/**
 * CE61_SEO — bridge to Yoast, Rank Math, AIOSEO and SEOPress meta fields.
 *
 * @package ClusterEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CE61_SEO {

	/**
	 * Detect the active SEO plugin.
	 */
	public static function active_plugin() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return 'rankmath';
		}
		if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
			return 'aioseo';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return 'seopress';
		}
		return 'none';
	}

	public static function plugin_label() {
		$map = array(
			'yoast'    => 'Yoast SEO',
			'rankmath' => 'Rank Math',
			'aioseo'   => 'All in One SEO',
			'seopress' => 'SEOPress',
			'none'     => __( 'Nenhum (usando campos nativos do Cluster Engine)', 'cluster-engine' ),
		);
		return $map[ self::active_plugin() ];
	}

	/**
	 * Meta keys per plugin.
	 */
	private static function keys() {
		switch ( self::active_plugin() ) {
			case 'yoast':
				return array( 'title' => '_yoast_wpseo_title', 'desc' => '_yoast_wpseo_metadesc', 'kw' => '_yoast_wpseo_focuskw' );
			case 'rankmath':
				return array( 'title' => 'rank_math_title', 'desc' => 'rank_math_description', 'kw' => 'rank_math_focus_keyword' );
			case 'aioseo':
				return array( 'title' => '_aioseo_title', 'desc' => '_aioseo_description', 'kw' => '_aioseo_keywords' );
			case 'seopress':
				return array( 'title' => '_seopress_titles_title', 'desc' => '_seopress_titles_desc', 'kw' => '_seopress_analysis_target_kw' );
			default:
				return array( 'title' => '_ce61_seo_title', 'desc' => '_ce61_seo_desc', 'kw' => '_ce61_focus_kw' );
		}
	}

	public static function get_meta_title( $post_id ) {
		$k = self::keys();
		return (string) get_post_meta( $post_id, $k['title'], true );
	}

	public static function get_meta_desc( $post_id ) {
		$k = self::keys();
		return (string) get_post_meta( $post_id, $k['desc'], true );
	}

	public static function get_focus_keyword( $post_id ) {
		$k  = self::keys();
		$kw = get_post_meta( $post_id, $k['kw'], true );
		if ( is_array( $kw ) ) {
			$kw = reset( $kw );
		}
		return (string) $kw;
	}

	public static function set_meta_title( $post_id, $value ) {
		$k = self::keys();
		return update_post_meta( $post_id, $k['title'], sanitize_text_field( $value ) );
	}

	public static function set_meta_desc( $post_id, $value ) {
		$k = self::keys();
		return update_post_meta( $post_id, $k['desc'], sanitize_text_field( $value ) );
	}

	public static function set_focus_keyword( $post_id, $value ) {
		$k = self::keys();
		return update_post_meta( $post_id, $k['kw'], sanitize_text_field( $value ) );
	}

	/**
	 * Duplicate meta titles / focus keywords across the site (declared cannibalization).
	 */
	public static function duplicates() {
		global $wpdb;
		$k   = self::keys();
		$out = array( 'title' => array(), 'kw' => array() );
		foreach ( array( 'title', 'kw' ) as $type ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT meta_value, COUNT(*) c, GROUP_CONCAT(post_id) ids
				 FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''
				 GROUP BY meta_value HAVING c > 1 LIMIT 50",
				$k[ $type ]
			), ARRAY_A );
			$out[ $type ] = $rows ? $rows : array();
		}
		return $out;
	}
}

/**
 * Front-end output for native fields when no SEO plugin is active.
 */
add_action( 'wp_head', function () {
	if ( 'none' !== CE61_SEO::active_plugin() || ! is_singular() ) {
		return;
	}
	$id   = get_queried_object_id();
	$desc = CE61_SEO::get_meta_desc( $id );
	if ( $desc ) {
		echo '<meta name="description" content="' . esc_attr( $desc ) . '" />' . "\n";
	}
}, 1 );

add_filter( 'pre_get_document_title', function ( $title ) {
	if ( 'none' !== CE61_SEO::active_plugin() || ! is_singular() ) {
		return $title;
	}
	$custom = CE61_SEO::get_meta_title( get_queried_object_id() );
	return $custom ? $custom : $title;
} );
