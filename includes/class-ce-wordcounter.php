<?php
/**
 * CE61_WordCounter — Word Counter: análise detalhada de texto no editor.
 *
 * Ferramenta avançada de análise de texto que vai além da contagem básica de
 * palavras e caracteres: legibilidade, densidade de palavras-chave e estimativas
 * de tempo de leitura e de fala. Aparece como uma meta box no editor de posts e
 * de CPTs personalizados.
 *
 * "Lê o site e entende onde pode aparecer": os tipos de conteúdo elegíveis são
 * descobertos em tempo de execução (todos os post types públicos que suportam o
 * editor), sem lista fixa — então CPTs novos passam a exibir a análise sozinhos.
 *
 * Toda a métrica é calculada no cliente (ao vivo, conforme se digita), tanto no
 * editor de blocos (Gutenberg) quanto no editor clássico.
 *
 * Habilitado/desabilitado na aba Configurações do plugin (setting word_counter).
 *
 * @package ClusterEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CE61_WordCounter {

	public static function init() {
		if ( ! self::is_enabled() ) {
			return;
		}
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * A ferramenta está ligada nas configurações? Padrão: ligada.
	 */
	public static function is_enabled() {
		$settings = get_option( 'ce61_settings', array() );
		return ! isset( $settings['word_counter'] ) || $settings['word_counter'];
	}

	/**
	 * Tipos de conteúdo onde a análise pode aparecer: descobertos lendo o site.
	 * Todos os post types públicos que suportam o editor (posts, páginas e CPTs),
	 * menos os anexos. Se nada casar, cai no par seguro post/page.
	 */
	public static function eligible_types() {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $type ) {
			if ( 'attachment' === $type ) {
				continue;
			}
			if ( post_type_supports( $type, 'editor' ) ) {
				$out[] = $type;
			}
		}
		/**
		 * Permite ajustar a lista de tipos onde o Word Counter aparece.
		 *
		 * @param string[] $out Nomes de post types elegíveis.
		 */
		$out = apply_filters( 'ce61_wordcounter_post_types', $out );
		return $out ? array_values( array_unique( $out ) ) : array( 'post', 'page' );
	}

	/**
	 * Registra a meta box em cada tipo elegível.
	 */
	public static function meta_box() {
		foreach ( self::eligible_types() as $type ) {
			add_meta_box(
				'ce61-wordcounter-box',
				__( 'Word Counter — análise de texto', 'cluster-engine' ),
				array( __CLASS__, 'render_box' ),
				$type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Casca da meta box. O conteúdo é preenchido ao vivo pelo wordcounter.js.
	 */
	public static function render_box( $post ) {
		echo '<div class="ce61-wc" id="ce61-wc" aria-live="polite">'
			. '<p class="ce61-wc-loading">' . esc_html__( 'Analisando o texto…', 'cluster-engine' ) . '</p>'
			. '</div>';
	}

	/**
	 * Enfileira script/estilo só nas telas de edição dos tipos elegíveis.
	 */
	public static function assets( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type, self::eligible_types(), true ) ) {
			return;
		}

		wp_enqueue_style( 'ce61-wordcounter', CE61_URL . 'admin/css/wordcounter.css', array(), CE61_VERSION );
		wp_enqueue_script( 'ce61-wordcounter', CE61_URL . 'admin/js/wordcounter.js', array(), CE61_VERSION, true );

		wp_localize_script( 'ce61-wordcounter', 'CE61_WC', array(
			'readingWpm'  => 200, // palavras por minuto — leitura silenciosa (adulto médio, PT-BR).
			'speakingWpm' => 130, // palavras por minuto — fala em voz alta (narração/podcast).
			'topKeywords' => 8,   // quantas palavras-chave mostrar na densidade.
			'stopwords'   => self::stopwords(),
			'i18n'        => array(
				'words'          => __( 'Palavras', 'cluster-engine' ),
				'chars'          => __( 'Caracteres', 'cluster-engine' ),
				'charsNoSpaces'  => __( 'Caracteres (sem espaços)', 'cluster-engine' ),
				'sentences'      => __( 'Frases', 'cluster-engine' ),
				'paragraphs'     => __( 'Parágrafos', 'cluster-engine' ),
				'readingTime'    => __( 'Tempo de leitura', 'cluster-engine' ),
				'speakingTime'   => __( 'Tempo de fala', 'cluster-engine' ),
				'avgWordsSent'   => __( 'Média de palavras por frase', 'cluster-engine' ),
				'readability'    => __( 'Legibilidade', 'cluster-engine' ),
				'density'        => __( 'Densidade de palavras-chave', 'cluster-engine' ),
				'basicTitle'     => __( 'Contagem', 'cluster-engine' ),
				'timeTitle'      => __( 'Tempo estimado', 'cluster-engine' ),
				'empty'          => __( 'Comece a escrever para ver a análise do texto.', 'cluster-engine' ),
				'densityEmpty'   => __( 'Sem palavras-chave suficientes ainda.', 'cluster-engine' ),
				'min'            => __( 'min', 'cluster-engine' ),
				'sec'            => __( 's', 'cluster-engine' ),
				'occurrences'    => __( 'ocorrências', 'cluster-engine' ),
				/* translators: níveis de facilidade de leitura (escala Flesch adaptada ao PT-BR). */
				'readVeryEasy'   => __( 'Muito fácil', 'cluster-engine' ),
				'readEasy'       => __( 'Fácil', 'cluster-engine' ),
				'readMedium'     => __( 'Médio', 'cluster-engine' ),
				'readHard'       => __( 'Difícil', 'cluster-engine' ),
				'readVeryHard'   => __( 'Muito difícil', 'cluster-engine' ),
			),
		) );
	}

	/**
	 * Lista compacta de stopwords PT-BR + EN para não poluir a densidade de
	 * palavras-chave com artigos, preposições e conectivos.
	 */
	private static function stopwords() {
		$words = 'a o e é de da do das dos em um uma umas uns para por com sem sob sobre entre até após antes durante que se não sim mais menos muito pouco como quando onde qual quais quem cujo cuja isso isto aquilo este esta esse essa aquele aquela seu sua seus suas meu minha nosso nossa dele dela deles delas eu tu ele ela nós vós eles elas você vocês ao aos à às no na nos nas num numa pelo pela pelos pelas mesmo mesma também já ainda apenas ou mas porém contudo todavia então assim pois porque portanto logo cada todo toda todos todas outro outra outros outras ser estar ter haver fazer pode podem deve devem foi era são está estão tem têm há vai vão sido sendo the an and or but if of to in on for with without at by from as is are was were be been being this that these those it its he she they them his her their you your we our my me do does did not no yes can could should would will just also very much many few more most other some any all each';
		return array_values( array_unique( explode( ' ', $words ) ) );
	}
}
