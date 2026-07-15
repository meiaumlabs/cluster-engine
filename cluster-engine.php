<?php
/**
 * Plugin Name:       Cluster Engine — Autoridade Tópica & Linkagem Interna
 * Plugin URI:        https://61labs.com.br/cluster-engine
 * Description:       Motor de autoridade tópica: mapeia clusters de conteúdo, palavras-chave, diagnostica SEO/AEO/GEO, sugere e corrige linkagem interna e reescreve metadados com IA. Desenvolvido pela 61 Labs.
 * Version:           2.15.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            61 Labs
 * Author URI:        https://61labs.com.br
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cluster-engine
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CE61_VERSION', '2.15.0' );
define( 'CE61_FILE', __FILE__ );
define( 'CE61_DIR', plugin_dir_path( __FILE__ ) );
define( 'CE61_URL', plugin_dir_url( __FILE__ ) );

/*
 * URL do repositório público no GitHub usado para atualizações automáticas.
 * Ajuste OWNER/REPO para o seu repositório (ex.: https://github.com/61labs/cluster-engine/).
 */
define( 'CE61_GITHUB_URL', 'https://github.com/meiaumlabs/cluster-engine/' );

/*
 * ------------------------------------------------------------------
 * Atualização automática via GitHub (Plugin Update Checker).
 * Fluxo de release: publique uma Release com a tag "vX.Y.Z" (ex.: v2.5.2)
 * e anexe o ZIP do plugin como asset. O WordPress detecta e oferece a
 * atualização no painel, igual a um plugin do repositório oficial.
 * ------------------------------------------------------------------
 */
require_once CE61_DIR . 'includes/plugin-update-checker/plugin-update-checker.php';

$ce61_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	CE61_GITHUB_URL,
	CE61_FILE,
	'cluster-engine'
);
// Usa o ZIP anexado nas Releases do GitHub como pacote de atualização.
$ce61_update_checker->getVcsApi()->enableReleaseAssets();

/*
 * Safety polyfills for hosts without the mbstring extension.
 * (WordPress core only polyfills mb_substr/mb_strlen.)
 */
if ( ! function_exists( 'mb_stripos' ) ) {
	function mb_stripos( $haystack, $needle, $offset = 0 ) { return stripos( $haystack, $needle, $offset ); }
}
if ( ! function_exists( 'mb_strpos' ) ) {
	function mb_strpos( $haystack, $needle, $offset = 0 ) { return strpos( $haystack, $needle, $offset ); }
}
if ( ! function_exists( 'mb_strtolower' ) ) {
	function mb_strtolower( $str ) { return strtolower( $str ); }
}

require_once CE61_DIR . 'includes/class-ce-indexer.php';
require_once CE61_DIR . 'includes/class-ce-analyzer.php';
require_once CE61_DIR . 'includes/class-ce-seo.php';
require_once CE61_DIR . 'includes/class-ce-ai.php';
require_once CE61_DIR . 'includes/class-ce-schema.php';
require_once CE61_DIR . 'includes/class-ce-images.php';
require_once CE61_DIR . 'includes/class-ce-stock.php';
require_once CE61_DIR . 'includes/class-ce-serp.php';
require_once CE61_DIR . 'includes/class-ce-gsc.php';
require_once CE61_DIR . 'includes/class-ce-history.php';
require_once CE61_DIR . 'includes/class-ce-creator.php';
require_once CE61_DIR . 'includes/class-ce-cpt.php';
require_once CE61_DIR . 'includes/class-ce-queue.php';
require_once CE61_DIR . 'includes/class-ce-ajax.php';
require_once CE61_DIR . 'includes/class-ce-admin.php';
require_once CE61_DIR . 'includes/class-ce-editor.php';

/**
 * 301 redirect engine: old URLs of merged posts → the unified post.
 * Map stored in option ce61_redirects as [ path => target_post_id ].
 */
add_action( 'template_redirect', function () {
	if ( is_admin() ) {
		return;
	}
	$redirects = get_option( 'ce61_redirects', array() );
	if ( ! $redirects || ! is_array( $redirects ) ) {
		return;
	}
	$request = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
	$request = untrailingslashit( strtolower( (string) $request ) );
	if ( '' === $request || ! isset( $redirects[ $request ] ) ) {
		return;
	}
	$target = get_permalink( (int) $redirects[ $request ] );
	if ( $target ) {
		wp_safe_redirect( $target, 301 );
		exit;
	}
}, 1 );

/**
 * Activation: create custom tables and default options.
 */
function ce61_activate() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset = $wpdb->get_charset_collate();

	$index = "CREATE TABLE {$wpdb->prefix}ce_index (
		post_id BIGINT(20) UNSIGNED NOT NULL,
		title TEXT NULL,
		word_count INT UNSIGNED NOT NULL DEFAULT 0,
		main_keyword VARCHAR(191) NULL,
		keywords LONGTEXT NULL,
		vector LONGTEXT NULL,
		links_out LONGTEXT NULL,
		inbound INT UNSIGNED NOT NULL DEFAULT 0,
		cluster_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		is_pillar TINYINT(1) NOT NULL DEFAULT 0,
		seo_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
		aeo_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
		issues LONGTEXT NULL,
		indexed_at DATETIME NULL,
		PRIMARY KEY (post_id),
		KEY cluster_id (cluster_id)
	) $charset;";

	$clusters = "CREATE TABLE {$wpdb->prefix}ce_clusters (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		name VARCHAR(191) NOT NULL DEFAULT '',
		description TEXT NULL,
		is_custom TINYINT(1) NOT NULL DEFAULT 0,
		planned LONGTEXT NULL,
		pillar_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		post_count INT UNSIGNED NOT NULL DEFAULT 0,
		score TINYINT UNSIGNED NOT NULL DEFAULT 0,
		score_parts LONGTEXT NULL,
		top_terms LONGTEXT NULL,
		PRIMARY KEY (id)
	) $charset;";

	$queue = "CREATE TABLE {$wpdb->prefix}ce_queue (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		job_type VARCHAR(40) NOT NULL DEFAULT '',
		payload LONGTEXT NULL,
		status VARCHAR(20) NOT NULL DEFAULT 'pending',
		attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
		result LONGTEXT NULL,
		error TEXT NULL,
		created_at DATETIME NULL,
		started_at DATETIME NULL,
		finished_at DATETIME NULL,
		PRIMARY KEY (id),
		KEY status (status)
	) $charset;";

	$relations = "CREATE TABLE {$wpdb->prefix}ce_relations (
		post_a BIGINT(20) UNSIGNED NOT NULL,
		post_b BIGINT(20) UNSIGNED NOT NULL,
		similarity FLOAT NOT NULL DEFAULT 0,
		link_ab TINYINT(1) NOT NULL DEFAULT 0,
		link_ba TINYINT(1) NOT NULL DEFAULT 0,
		status VARCHAR(20) NOT NULL DEFAULT 'auto',
		PRIMARY KEY (post_a, post_b),
		KEY sim (similarity)
	) $charset;";

	/*
	 * Histórico de desempenho: 1 linha por post por dia (grão diário,
	 * nunca reescrita — append-only). Suficiente para 120 dias × milhares
	 * de posts sem pesar no banco, e a chave composta (post_id, snap_date)
	 * torna a leitura de uma janela de tempo uma busca por índice direta.
	 * Separado de propósito do cache "ao vivo" (options ce61_gsc_cache /
	 * ce61_ga4_cache / postmeta _ce61_serp), que é sobrescrito a cada
	 * atualização manual e alimenta só a foto do momento na tabela.
	 */
	$history = "CREATE TABLE {$wpdb->prefix}ce_perf_history (
		post_id BIGINT(20) UNSIGNED NOT NULL,
		snap_date DATE NOT NULL,
		clicks INT UNSIGNED NULL,
		impressions INT UNSIGNED NULL,
		ctr FLOAT NULL,
		gsc_position FLOAT NULL,
		serp_position SMALLINT UNSIGNED NULL,
		ga4_sessions INT UNSIGNED NULL,
		PRIMARY KEY (post_id, snap_date),
		KEY snap_date (snap_date)
	) $charset;";

	dbDelta( $index );
	dbDelta( $clusters );
	dbDelta( $relations );
	dbDelta( $queue );
	dbDelta( $history );

	update_option( 'ce61_db_version', CE61_VERSION );
	CE61_Queue::ensure_scheduled();

	if ( ! get_option( 'ce61_settings' ) ) {
		add_option( 'ce61_settings', array(
			'post_types'      => array( 'post' ),
			'sim_link'        => 0.22, // similarity >= : suggest link.
			'sim_weak'        => 0.08, // linked pairs below this = senseless link.
			'sim_cannibal'    => 0.62, // above this + same intent = cannibalization.
			'min_words'       => 300,
			'stale_months'    => 18,
			'provider'        => 'anthropic',
			'api_key_openai'  => '',
			'api_key_anthropic' => '',
			'api_key_gemini'  => '',
			'model_light'     => '',
			'model_heavy'     => '',
			'global_prompt'   => "Você escreve para o site {{site_name}}. Tom: técnico-acessível, direto, sem travessões e sem emojis. Sempre em português do Brasil.",
			'perf_cron_time'    => '03:00', // horário local do site para o snapshot diário de desempenho.
			'serp_daily_budget' => 20,      // máximo de posts com posição SERP checada por dia (protege a cota grátis das APIs).
		) );
	}

	if ( ! get_option( 'ce61_prompts' ) ) {
		add_option( 'ce61_prompts', CE61_AI::default_prompts() );
	}
}
register_activation_hook( __FILE__, 'ce61_activate' );

/**
 * Deactivation: remove o evento da fila do WP-Cron.
 */
register_deactivation_hook( __FILE__, function () {
	CE61_Queue::unschedule();
	CE61_History::unschedule();
} );

/**
 * Upgrade automático de schema em updates sem reativação
 * (novas colunas de ce_clusters e tabela ce_queue da 1.5.0).
 */
function ce61_maybe_upgrade() {
	if ( get_option( 'ce61_db_version' ) !== CE61_VERSION ) {
		ce61_activate();
	}
}
add_action( 'plugins_loaded', 'ce61_maybe_upgrade', 5 );

/**
 * Reindex a post when saved.
 */
function ce61_on_save( $post_id, $post ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	$settings = get_option( 'ce61_settings', array() );
	$types    = isset( $settings['post_types'] ) ? (array) $settings['post_types'] : array( 'post' );
	if ( 'publish' === $post->post_status && in_array( $post->post_type, $types, true ) ) {
		CE61_Indexer::index_post( $post_id );
		// Marca a data de edição de um post JÁ existente (não a criação),
		// para o gráfico de desempenho mostrar "aqui o conteúdo mudou" e
		// permitir comparar antes/depois. Só grava 1x por dia por post.
		if ( $post->post_date !== $post->post_modified ) {
			CE61_History::log_update( $post_id );
		}
	}
}
add_action( 'save_post', 'ce61_on_save', 20, 2 );

add_action( 'plugins_loaded', function () {
	load_plugin_textdomain( 'cluster-engine', false, dirname( plugin_basename( CE61_FILE ) ) . '/languages' );
	CE61_Admin::init();
	CE61_Ajax::init();
	CE61_Queue::init();
	CE61_History::init();
	CE61_Editor::init();
} );
