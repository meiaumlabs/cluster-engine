<?php
/**
 * CE61_Editor — extensão do Cluster Engine no editor de posts/páginas.
 *
 * Adiciona:
 *  - um ícone de IA na barra superior do admin (toolbar) que abre um modal
 *    para descrever o que precisa ser atualizado e melhora o conteúdo com IA;
 *  - uma meta box "Cluster Engine — Conteúdo com IA" abaixo do bloco Publicar;
 *  - um botão para gerar a imagem destacada com IA dentro da caixa de
 *    imagem destacada.
 *
 * Toda a geração usa o mesmo hub de IA e a base de configuração do plugin
 * (global prompt aplicado como identidade/system).
 *
 * @package ClusterEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CE61_Editor {

	public static function init() {
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 90 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_filter( 'admin_post_thumbnail_html', array( __CLASS__, 'featured_button' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'front_assets' ) );
	}

	/**
	 * Tipos de post onde a extensão aparece: os públicos que suportam editor.
	 */
	private static function editable_types() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		$out   = array();
		foreach ( $types as $t ) {
			if ( post_type_supports( $t, 'editor' ) && 'attachment' !== $t ) {
				$out[] = $t;
			}
		}
		return $out ? $out : array( 'post', 'page' );
	}

	/**
	 * ID do post em edição/visualização no contexto atual, se houver.
	 */
	private static function current_post_id() {
		if ( is_admin() ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && 'post' === $screen->base ) {
				if ( isset( $_GET['post'] ) ) {
					return absint( $_GET['post'] );
				}
			}
			return 0;
		}
		if ( is_singular() ) {
			return (int) get_queried_object_id();
		}
		return 0;
	}

	private static function should_load() {
		if ( ! is_user_logged_in() ) {
			return 0;
		}
		$pid = self::current_post_id();
		if ( is_admin() ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( ! $screen || 'post' !== $screen->base ) {
				return 0;
			}
			if ( ! in_array( $screen->post_type, self::editable_types(), true ) ) {
				return 0;
			}
			// post-new.php ainda não tem ID; a extensão precisa de um post salvo.
			return $pid && current_user_can( 'edit_post', $pid ) ? $pid : 0;
		}
		return ( $pid && current_user_can( 'edit_post', $pid ) ) ? $pid : 0;
	}

	/**
	 * Enfileira o script/estilo da extensão e injeta a config.
	 */
	private static function enqueue( $post_id ) {
		wp_enqueue_style( 'ce61-editor', CE61_URL . 'admin/css/editor.css', array(), CE61_VERSION );
		wp_enqueue_script( 'ce61-editor', CE61_URL . 'admin/js/editor.js', array(), CE61_VERSION, true );

		$settings   = get_option( 'ce61_settings', array() );
		$has_openai = ! empty( $settings['api_key_openai'] );
		$has_gemini = ! empty( $settings['api_key_gemini'] );

		wp_localize_script( 'ce61-editor', 'CE61_EDITOR', array(
			'ajax'        => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'ce61_nonce' ),
			'postId'      => (int) $post_id,
			'title'       => get_the_title( $post_id ),
			'isAdmin'     => is_admin() ? 1 : 0,
			'canImage'    => ( $has_openai || $has_gemini ) ? 1 : 0,
			'imageAspect' => isset( $settings['image_aspect'] ) ? $settings['image_aspect'] : 'wide',
			'i18n'        => array(
				'barTitle'      => __( 'Melhorar com IA', 'cluster-engine' ),
				'modalTitle'    => __( 'Melhorar conteúdo com IA', 'cluster-engine' ),
				'modalHint'     => __( 'A IA cruza o diagnóstico do Cluster Engine (notas e desempenho) com o que você pedir. O rascunho é gerado para você aprovar antes de publicar.', 'cluster-engine' ),
				'placeholder'   => __( 'Ex.: atualize os dados para 2026, deixe a introdução mais direta, adicione uma seção de perguntas frequentes e reforce o E-E-A-T.', 'cluster-engine' ),
				'colDiag'       => __( 'Diagnóstico e desempenho', 'cluster-engine' ),
				'colPrompt'     => __( 'O que melhorar', 'cluster-engine' ),
				'loadingDiag'   => __( 'Carregando diagnóstico…', 'cluster-engine' ),
				'noDiag'        => __( 'Não foi possível carregar o diagnóstico.', 'cluster-engine' ),
				'scoresTitle'   => __( 'Notas do conteúdo', 'cluster-engine' ),
				'perfTitle'     => __( 'Desempenho (90 dias)', 'cluster-engine' ),
				'perfNone'      => __( 'Sem dados de desempenho ainda. Conecte o Search Console/GA4 e aguarde a coleta diária.', 'cluster-engine' ),
				'issuesTitle'   => __( 'Melhorias sugeridas', 'cluster-engine' ),
				'issuesNone'    => __( 'Nenhuma pendência crítica detectada.', 'cluster-engine' ),
				'scanTitle'     => __( 'Scan do Painel', 'cluster-engine' ),
				'genDiag'       => __( 'Gerar melhorias com base no diagnóstico', 'cluster-engine' ),
				'genCombined'   => __( 'Gerar com diagnóstico + prompt', 'cluster-engine' ),
				'cancel'        => __( 'Cancelar', 'cluster-engine' ),
				'working'       => __( 'A IA está gerando o rascunho…', 'cluster-engine' ),
				'reload'        => __( 'Recarregar editor', 'cluster-engine' ),
				'emptyCombined' => __( 'Escreva o que a IA deve fazer, ou use o botão de melhorar só com base no diagnóstico.', 'cluster-engine' ),
				'metClicks'     => __( 'Cliques', 'cluster-engine' ),
				'metImpr'       => __( 'Impressões', 'cluster-engine' ),
				'metGsc'        => __( 'Posição GSC', 'cluster-engine' ),
				'metSerp'       => __( 'Posição Google', 'cluster-engine' ),
				'metGa4'        => __( 'Sessões GA4', 'cluster-engine' ),
				'metCtr'        => __( 'CTR', 'cluster-engine' ),
				'draftTitle'    => __( 'Rascunho para aprovação', 'cluster-engine' ),
				'draftHint'     => __( 'Revise abaixo. Ao aprovar, o conteúdo do post é atualizado.', 'cluster-engine' ),
				'approvePublish' => __( 'Aprovar e publicar', 'cluster-engine' ),
				'approveDraft'  => __( 'Aprovar (salvar sem publicar)', 'cluster-engine' ),
				'redo'          => __( 'Descartar e refazer', 'cluster-engine' ),
				'applying'      => __( 'Aplicando…', 'cluster-engine' ),
				'appliedPub'    => __( 'Conteúdo atualizado e publicado.', 'cluster-engine' ),
				'applied'       => __( 'Conteúdo atualizado.', 'cluster-engine' ),
				'logTitle'      => __( 'Histórico de alterações', 'cluster-engine' ),
				'logNone'       => __( 'Nenhuma alteração registrada nesta URL ainda.', 'cluster-engine' ),
				'revert'        => __( 'Reverter', 'cluster-engine' ),
				'reverting'     => __( 'Revertendo…', 'cluster-engine' ),
				'revertConfirm' => __( 'Reverter o conteúdo desta URL para esta versão? O estado atual fica salvo no histórico.', 'cluster-engine' ),
				'reverted'      => __( 'Conteúdo revertido.', 'cluster-engine' ),
				'published'     => __( 'Publicado', 'cluster-engine' ),
				'notPublished'  => __( 'Não publicado', 'cluster-engine' ),
				'imgBtn'        => __( 'Gerar imagem com IA', 'cluster-engine' ),
				'imgWorking'    => __( 'Gerando imagem com IA…', 'cluster-engine' ),
				'imgDone'       => __( 'Imagem destacada gerada e definida.', 'cluster-engine' ),
				'imgNoKey'      => __( 'Configure a chave da OpenAI ou do Gemini no Cluster Engine para gerar imagens.', 'cluster-engine' ),
				'error'         => __( 'Algo deu errado. Tente novamente.', 'cluster-engine' ),
			),
		) );
	}

	public static function admin_assets( $hook ) {
		if ( 'post.php' !== $hook ) {
			return;
		}
		$pid = self::should_load();
		if ( $pid ) {
			self::enqueue( $pid );
		}
	}

	public static function front_assets() {
		if ( ! is_admin_bar_showing() ) {
			return;
		}
		$pid = self::should_load();
		if ( $pid ) {
			self::enqueue( $pid );
		}
	}

	/**
	 * Ícone de IA na barra superior do admin.
	 */
	public static function admin_bar( $bar ) {
		$pid = self::should_load();
		if ( ! $pid ) {
			return;
		}
		$bar->add_node( array(
			'id'    => 'ce61-ai',
			'title' => '<span class="ce61-ab-icon dashicons dashicons-superhero-alt" aria-hidden="true"></span><span class="ab-label">' . esc_html__( 'Melhorar com IA', 'cluster-engine' ) . '</span>',
			'href'  => '#ce61-ai',
			'meta'  => array(
				'title' => __( 'Cluster Engine — melhorar este conteúdo com IA', 'cluster-engine' ),
				'class' => 'ce61-ab-node',
			),
		) );
	}

	/**
	 * Meta box abaixo do bloco Publicar (contexto lateral, prioridade default).
	 */
	public static function meta_box() {
		foreach ( self::editable_types() as $type ) {
			add_meta_box(
				'ce61-ai-box',
				__( 'Cluster Engine — Conteúdo com IA', 'cluster-engine' ),
				array( __CLASS__, 'render_box' ),
				$type,
				'side',
				'default'
			);
		}
	}

	public static function render_box( $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			echo '<p>' . esc_html__( 'Sem permissão.', 'cluster-engine' ) . '</p>';
			return;
		}
		?>
		<div class="ce61-box">
			<p class="ce61-box-hint"><?php esc_html_e( 'Melhore este conteúdo com IA sem sair do editor. A base de configuração do site é sempre mantida.', 'cluster-engine' ); ?></p>
			<button type="button" class="button button-primary button-large ce61-box-btn" data-ce61-open>
				<span class="dashicons dashicons-superhero-alt" aria-hidden="true"></span>
				<?php esc_html_e( 'Melhorar com IA', 'cluster-engine' ); ?>
			</button>
		</div>
		<?php
	}

	/**
	 * Botão de gerar imagem destacada com IA dentro da caixa de imagem destacada.
	 */
	public static function featured_button( $content, $post_id ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return $content;
		}
		$btn = '<p class="ce61-featured-ai">'
			. '<button type="button" class="button ce61-featured-btn" data-ce61-image="' . esc_attr( $post_id ) . '">'
			. '<span class="dashicons dashicons-superhero-alt" aria-hidden="true"></span> '
			. esc_html__( 'Gerar imagem com IA', 'cluster-engine' )
			. '</button>'
			. '<span class="ce61-featured-status" aria-live="polite"></span>'
			. '</p>';
		return $content . $btn;
	}
}
