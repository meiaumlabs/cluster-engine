<?php
/**
 * CE61_Admin — menus, assets e shell do app.
 *
 * O plugin agora tem três páginas separadas no admin:
 *  - Painel (análise: dashboard, clusters, links, keywords, diagnóstico, schema, imagens)
 *  - Criação de Conteúdo (novos clusters com IA, planejamento, geração em massa e fila)
 *  - Configurações (provedores de IA, prompts, limiares, imagens)
 *
 * @package ClusterEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CE61_Admin {

	/**
	 * Hooks reais retornados por add_menu_page/add_submenu_page,
	 * mapeados para a chave da página ('main', 'creator', 'settings').
	 */
	private static $hooks = array();

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_init', array( __CLASS__, 'redirect_legacy' ) );
	}

	/**
	 * Abas de cada página do plugin.
	 */
	private static function pages() {
		return array(
			'main' => array(
				'tabs' => array(
					'dashboard'   => __( 'Painel', 'cluster-engine' ),
					'clusters'    => __( 'Clusters', 'cluster-engine' ),
					'links'       => __( 'Linkagem interna', 'cluster-engine' ),
					'keywords'    => __( 'Palavras-chave', 'cluster-engine' ),
					'diagnostics' => __( 'Diagnóstico', 'cluster-engine' ),
					'schema'      => __( 'Schema', 'cluster-engine' ),
					'network'     => __( 'Rede de Keywords', 'cluster-engine' ),
					'performance' => __( 'Desempenho', 'cluster-engine' ),
				),
			),
			'images' => array(
				'tabs' => array(
					'images_articles' => __( 'Imagens dos artigos', 'cluster-engine' ),
					'images_convert'  => __( 'Converter para WebP', 'cluster-engine' ),
					'images_presets'  => __( 'Presets', 'cluster-engine' ),
					'images_settings' => __( 'Configurações de imagem', 'cluster-engine' ),
					'images_errors'   => __( 'Erros', 'cluster-engine' ),
				),
			),
			'creator' => array(
				'tabs' => array(
					'creator'              => __( 'Novos clusters & conteúdo', 'cluster-engine' ),
					'cpt_manage'           => __( 'CPTs existentes', 'cluster-engine' ),
					'cpt_content'          => __( 'Conteúdo & clusters do CPT', 'cluster-engine' ),
					'categories_organize'  => __( 'Organizar posts', 'cluster-engine' ),
					'categories_seo'       => __( 'SEO & imagens', 'cluster-engine' ),
					'categories_suggest'   => __( 'Sugerir categorias', 'cluster-engine' ),
					'categories_redirects' => __( 'Redirects 301', 'cluster-engine' ),
					'queue'                => __( 'Fila de geração', 'cluster-engine' ),
				),
			),
			'settings' => array(
				'tabs' => array(
					'settings'     => __( 'Configurações', 'cluster-engine' ),
					'ai'           => __( 'IA & Prompts', 'cluster-engine' ),
					'integrations' => __( 'Integrações', 'cluster-engine' ),
				),
			),
		);
	}

	public static function menu() {
		$hook = add_menu_page(
			'Cluster Engine',
			'Cluster Engine',
			'manage_options',
			'cluster-engine',
			function () { self::render( 'main' ); },
			'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="3.4" fill="#a7aaad"/><circle cx="12" cy="12" r="9" stroke="#a7aaad" stroke-width="1.6" fill="none" stroke-dasharray="4 3"/><circle cx="19.5" cy="7" r="2" fill="#a7aaad"/><circle cx="5" cy="16.5" r="2" fill="#a7aaad"/></svg>' ),
			26
		);
		self::$hooks[ $hook ] = 'main';
		add_submenu_page( 'cluster-engine', __( 'Painel', 'cluster-engine' ), __( 'Painel', 'cluster-engine' ), 'manage_options', 'cluster-engine', function () { self::render( 'main' ); } );
		$hook = add_submenu_page( 'cluster-engine', __( 'Conteúdo', 'cluster-engine' ), __( 'Conteúdo', 'cluster-engine' ), 'manage_options', 'cluster-engine-creator', function () { self::render( 'creator' ); } );
		self::$hooks[ $hook ] = 'creator';
		$hook = add_submenu_page( 'cluster-engine', __( 'Imagens', 'cluster-engine' ), __( 'Imagens', 'cluster-engine' ), 'manage_options', 'cluster-engine-images', function () { self::render( 'images' ); } );
		self::$hooks[ $hook ] = 'images';
		$hook = add_submenu_page( 'cluster-engine', __( 'Configurações', 'cluster-engine' ), __( 'Configurações', 'cluster-engine' ), 'manage_options', 'cluster-engine-settings', function () { self::render( 'settings' ); } );
		self::$hooks[ $hook ] = 'settings';
	}

	/**
	 * Redireciona slugs de página legados para a nova hierarquia de 4 itens.
	 * Garante que bookmarks de ?page=cluster-engine-performance etc. não 404.
	 */
	public static function redirect_legacy() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
		$map  = array(
			'cluster-engine-performance' => admin_url( 'admin.php?page=cluster-engine' ),
			'cluster-engine-network'     => admin_url( 'admin.php?page=cluster-engine' ),
			'cluster-engine-cpt'         => admin_url( 'admin.php?page=cluster-engine-creator' ),
			'cluster-engine-categories'  => admin_url( 'admin.php?page=cluster-engine-creator' ),
		);
		if ( isset( $map[ $page ] ) ) {
			wp_safe_redirect( $map[ $page ] );
			exit;
		}
	}

	public static function assets( $hook ) {
		if ( ! isset( self::$hooks[ $hook ] ) ) {
			return;
		}
		$page = self::$hooks[ $hook ];
		wp_enqueue_style( 'ce61-fonts', CE61_URL . 'admin/fonts/fonts.css', array(), CE61_VERSION );
		wp_enqueue_style( 'ce61-app', CE61_URL . 'admin/css/app.css', array(), CE61_VERSION );
		wp_enqueue_script( 'ce61-app', CE61_URL . 'admin/js/app.js', array(), CE61_VERSION, true );
		wp_enqueue_media(); // habilita o seletor de mídia do WordPress (wp.media) para o watermark em imagem.

		$prompts = CE61_AI::get_prompts();
		$safe    = array();
		foreach ( $prompts as $k => $p ) {
			$safe[ $k ] = array( 'label' => $p['label'], 'prompt' => $p['prompt'] );
		}
		$settings = get_option( 'ce61_settings', array() );
		$google   = CE61_Gsc::data();

		wp_localize_script( 'ce61-app', 'CE61', array(
			'ajax'     => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'ce61_nonce' ),
			'page'     => $page,
			'pages'    => array(
				'main'     => admin_url( 'admin.php?page=cluster-engine' ),
				'creator'  => admin_url( 'admin.php?page=cluster-engine-creator' ),
				'images'   => admin_url( 'admin.php?page=cluster-engine-images' ),
				'settings' => admin_url( 'admin.php?page=cluster-engine-settings' ),
			),
			'prompts'  => $safe,
			'settings' => array(
				'post_types'   => isset( $settings['post_types'] ) ? $settings['post_types'] : array(),
				'provider'     => isset( $settings['provider'] ) ? $settings['provider'] : 'anthropic',
				'role_provider' => isset( $settings['role_provider'] ) && is_array( $settings['role_provider'] ) ? $settings['role_provider'] : array(),
				'model_light'  => isset( $settings['model_light'] ) ? $settings['model_light'] : '',
				'global_prompt' => isset( $settings['global_prompt'] ) ? $settings['global_prompt'] : '',
				'sim_link'     => isset( $settings['sim_link'] ) ? $settings['sim_link'] : 0.22,
				'sim_weak'     => isset( $settings['sim_weak'] ) ? $settings['sim_weak'] : 0.08,
				'sim_cannibal' => isset( $settings['sim_cannibal'] ) ? $settings['sim_cannibal'] : 0.62,
				'min_words'    => isset( $settings['min_words'] ) ? $settings['min_words'] : 300,
				'stale_months' => isset( $settings['stale_months'] ) ? $settings['stale_months'] : 18,
				'image_provider'  => isset( $settings['image_provider'] ) ? $settings['image_provider'] : 'openai',
				'image_model'     => isset( $settings['image_model'] ) ? $settings['image_model'] : '',
				'image_size'      => isset( $settings['image_size'] ) ? $settings['image_size'] : '1792x1024',
				'image_watermark' => isset( $settings['image_watermark'] ) ? $settings['image_watermark'] : '',
				'image_colors'    => isset( $settings['image_colors'] ) ? $settings['image_colors'] : '',
				'image_style'     => isset( $settings['image_style'] ) ? $settings['image_style'] : '',
				'image_prompt'    => isset( $settings['image_prompt'] ) && '' !== trim( $settings['image_prompt'] ) ? $settings['image_prompt'] : CE61_Images::default_prompt(),
				'image_aspect'         => isset( $settings['image_aspect'] ) ? $settings['image_aspect'] : 'wide',
				'image_webp'           => ! isset( $settings['image_webp'] ) || $settings['image_webp'],
				'image_webp_quality'   => isset( $settings['image_webp_quality'] ) ? (int) $settings['image_webp_quality'] : 82,
				'image_style_presets'  => isset( $settings['image_style_presets'] ) ? (array) $settings['image_style_presets'] : array(),
				'image_watermark_type' => isset( $settings['image_watermark_type'] ) ? $settings['image_watermark_type'] : 'text',
				'image_watermark_url'  => isset( $settings['image_watermark_url'] ) ? $settings['image_watermark_url'] : '',
				'image_watermark_media_id' => isset( $settings['image_watermark_media_id'] ) ? (int) $settings['image_watermark_media_id'] : 0,
				'image_watermark_media_url' => ! empty( $settings['image_watermark_media_id'] ) ? wp_get_attachment_url( (int) $settings['image_watermark_media_id'] ) : '',
				'serp_provider'   => CE61_Serp::active_provider( $settings ),
				'perf_cron_time'    => isset( $settings['perf_cron_time'] ) ? $settings['perf_cron_time'] : '03:00',
				'serp_daily_budget' => isset( $settings['serp_daily_budget'] ) ? (int) $settings['serp_daily_budget'] : 20,
				'last_daily_snapshot' => get_option( 'ce61_last_daily_snapshot', '' ),
				'image_source_default' => isset( $settings['image_source_default'] ) ? $settings['image_source_default'] : 'stock',
				'stock_priority'       => CE61_Stock::priority_order(),
				'stock_credit_caption' => ! isset( $settings['stock_credit_caption'] ) || $settings['stock_credit_caption'],
				'stock_limits' => array(
					'unsplash'  => isset( $settings['stock_limit_unsplash'] ) ? (int) $settings['stock_limit_unsplash'] : 50,
					'pexels'    => isset( $settings['stock_limit_pexels'] ) ? (int) $settings['stock_limit_pexels'] : 200,
					'pixabay'   => isset( $settings['stock_limit_pixabay'] ) ? (int) $settings['stock_limit_pixabay'] : 100,
					'openverse' => isset( $settings['stock_limit_openverse'] ) ? (int) $settings['stock_limit_openverse'] : 100,
				),
				'has_key'      => array(
					'openai'    => ! empty( $settings['api_key_openai'] ),
					'anthropic' => ! empty( $settings['api_key_anthropic'] ),
					'gemini'    => ! empty( $settings['api_key_gemini'] ),
					'groq'      => ! empty( $settings['api_key_groq'] ),
					'serper'    => ! empty( $settings['serp_key_serper'] ),
					'serpapi'   => ! empty( $settings['serp_key_serpapi'] ),
					'valueserp' => ! empty( $settings['serp_key_valueserp'] ),
					'unsplash'  => ! empty( $settings['stock_key_unsplash'] ),
					'pexels'    => ! empty( $settings['stock_key_pexels'] ),
					'pixabay'   => ! empty( $settings['stock_key_pixabay'] ),
				),
			),
			'aiProviders'    => CE61_AI::providers_meta(),
			'aiRoles'        => CE61_AI::roles(),
			'stockProviders' => CE61_Stock::providers(),
			'imageCatalog'   => CE61_Images::catalog(),
			'imageStylePresets' => CE61_Images::style_presets(),
			'imageAspectRatios' => CE61_Images::aspect_ratios(),
			'imagePresets'      => CE61_Images::presets(),
			'google' => array(
				'connected'    => CE61_Gsc::is_connected(),
				'client_id'    => isset( $google['client_id'] ) ? $google['client_id'] : '',
				'has_secret'   => ! empty( $google['client_secret'] ),
				'gsc_site_url' => isset( $google['gsc_site_url'] ) ? $google['gsc_site_url'] : '',
				'ga4_property_id' => isset( $google['ga4_property_id'] ) ? $google['ga4_property_id'] : '',
				'redirect_uri' => CE61_Gsc::redirect_uri(),
				'connect_url'  => add_query_arg( '_wpnonce', wp_create_nonce( 'ce61_google_connect' ), admin_url( 'admin-post.php?action=ce61_google_connect' ) ),
			),
			'postTypes' => array_map( function ( $pt ) {
				return array( 'name' => $pt->name, 'label' => $pt->labels->name );
			}, array_values( get_post_types( array( 'public' => true ), 'objects' ) ) ),
			'seoPlugin' => CE61_SEO::plugin_label(),
			'jetengine' => (bool) CE61_CPT::is_jetengine(),
		) );
	}

	/**
	 * Shell do app para a página pedida.
	 */
	public static function render( $page ) {
		$pages = self::pages();
		$tabs  = $pages[ $page ]['tabs'];
		$first = true;

		$subtitles = array(
			'main'     => __( 'Autoridade tópica, clusters, linkagem interna, desempenho e rede de keywords', 'cluster-engine' ),
			'creator'  => __( 'Conteúdo, CPTs, Categorias e fila de geração', 'cluster-engine' ),
			'images'   => __( 'Geração, conversão WebP e SEO de todas as imagens dos artigos', 'cluster-engine' ),
			'settings' => __( 'Provedores de IA, prompts, limiares e integrações externas', 'cluster-engine' ),
		);
		?>
		<div class="ce-app" id="ce-app">
			<header class="ce-header">
				<div class="ce-brand">
					<span class="ce-orbit-mark" aria-hidden="true"><i></i><i></i><i></i></span>
					<div>
						<h1>Cluster Engine</h1>
						<p class="ce-tagline"><?php echo esc_html( $subtitles[ $page ] ); ?></p>
					</div>
				</div>
				<div class="ce-header-actions">
					<?php if ( 'main' === $page ) : ?>
						<span class="ce-seo-badge" title="<?php esc_attr_e( 'Plugin de SEO detectado', 'cluster-engine' ); ?>">◈ <span id="ce-seo-plugin"></span></span>
						<button class="ce-btn ce-btn-primary" id="ce-scan-btn">
							<span class="ce-btn-label"><?php esc_html_e( 'Escanear site', 'cluster-engine' ); ?></span>
						</button>
					<?php endif; ?>
				</div>
			</header>

			<?php if ( 'main' === $page ) : ?>
			<div class="ce-scanbar" id="ce-scanbar" hidden>
				<div class="ce-scanbar-track"><div class="ce-scanbar-fill" id="ce-scan-fill"></div></div>
				<p id="ce-scan-msg"></p>
			</div>
			<?php endif; ?>

			<nav class="ce-tabs" role="tablist">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<button class="ce-tab<?php echo $first ? ' is-active' : ''; ?>" data-tab="<?php echo esc_attr( $key ); ?>" role="tab"><?php echo esc_html( $label ); ?></button>
					<?php $first = false; ?>
				<?php endforeach; ?>
			</nav>

			<main class="ce-main">
				<?php $first = true; foreach ( $tabs as $key => $label ) : ?>
					<section class="ce-panel<?php echo $first ? ' is-active' : ''; ?>" data-panel="<?php echo esc_attr( $key ); ?>" id="ce-panel-<?php echo esc_attr( $key ); ?>"></section>
					<?php $first = false; ?>
				<?php endforeach; ?>
			</main>

			<div class="ce-modal" id="ce-modal" hidden>
				<div class="ce-modal-card" role="dialog" aria-modal="true">
					<button class="ce-modal-close" id="ce-modal-close" aria-label="<?php esc_attr_e( 'Fechar', 'cluster-engine' ); ?>">✕</button>
					<div id="ce-modal-body"></div>
				</div>
			</div>

			<div class="ce-toast" id="ce-toast" hidden></div>

			<footer class="ce-footer">
				<p><?php esc_html_e( 'Desenvolvido por', 'cluster-engine' ); ?> <a href="https://61labs.com.br" target="_blank" rel="noopener">61 Labs</a> · 61labs.com.br</p>
			</footer>
		</div>
		<?php
	}
}
