<?php
/**
 * CE61_Categories — organizador de categorias: revisa posts e sugere a melhor
 * categoria, popula SEO (título/descrição/descrição nativa) e imagem de capa
 * de cada categoria, e sugere novas categorias. A recategorização é sempre
 * revisada pelo usuário antes de gravar; ao aplicar, a categoria sugerida é
 * definida como primária mantendo as demais (não destrutivo) e, quando a URL
 * muda por causa disso, um 301 é criado automaticamente.
 *
 * @package ClusterEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CE61_Categories {

	const TAX      = 'category';
	const IMG_META = '_ce61_category_image';

	/**
	 * Tipos de post que possuem a taxonomia 'category' e estão no escopo.
	 */
	private static function post_types() {
		$s   = get_option( 'ce61_settings', array() );
		$pts = array_values( array_filter( CE61_Indexer::resolved_post_types( $s ), function ( $pt ) {
			return is_object_in_taxonomy( $pt, self::TAX );
		} ) );
		return $pts ? $pts : array( 'post' );
	}

	/**
	 * Categorias com contagem, SEO e imagem (para a tabela de gestão).
	 */
	public static function list_categories() {
		$terms = get_terms( array( 'taxonomy' => self::TAX, 'hide_empty' => false, 'number' => 300 ) );
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $t ) {
			$img_id = (int) get_term_meta( $t->term_id, self::IMG_META, true );
			$seo    = CE61_SEO::get_term_seo( $t->term_id );
			$link   = get_term_link( $t );
			$out[]  = array(
				'id'          => (int) $t->term_id,
				'name'        => $t->name,
				'slug'        => $t->slug,
				'count'       => (int) $t->count,
				'description' => $t->description,
				'seo_title'   => $seo['title'],
				'seo_desc'    => $seo['desc'],
				'image_id'    => $img_id,
				'image_url'   => $img_id ? wp_get_attachment_image_url( $img_id, 'medium' ) : '',
				'link'        => is_wp_error( $link ) ? '' : $link,
			);
		}
		return $out;
	}

	/**
	 * Lista "Nome — descrição" das categorias, para injetar nos prompts.
	 */
	public static function categories_prompt_list() {
		$terms = get_terms( array( 'taxonomy' => self::TAX, 'hide_empty' => false, 'number' => 300 ) );
		if ( is_wp_error( $terms ) || ! $terms ) {
			return '(nenhuma categoria criada ainda)';
		}
		$lines = array();
		foreach ( $terms as $t ) {
			$lines[] = '- ' . $t->name . ( $t->description ? ' — ' . wp_trim_words( $t->description, 20 ) : '' );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Posts para a tela de revisão, com as categorias atuais.
	 */
	public static function review_posts( $limit = 30, $offset = 0 ) {
		$q = new WP_Query( array(
			'post_type'      => self::post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => (int) $limit,
			'offset'         => (int) $offset,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );
		$out = array();
		foreach ( $q->posts as $p ) {
			$cats  = wp_get_post_terms( $p->ID, self::TAX, array( 'fields' => 'names' ) );
			$out[] = array(
				'id'         => (int) $p->ID,
				'title'      => $p->post_title,
				'edit'       => get_edit_post_link( $p->ID, '' ),
				'categories' => is_array( $cats ) ? $cats : array(),
			);
		}
		return array( 'posts' => $out, 'total' => (int) $q->found_posts );
	}

	/**
	 * Pede à IA a melhor categoria para um post. Não grava nada.
	 */
	public static function analyze_post( $post_id, $provider = '' ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'ce61_cat', __( 'Post não encontrado.', 'cluster-engine' ) );
		}
		$current = wp_get_post_terms( $post_id, self::TAX, array( 'fields' => 'names' ) );
		$content = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
		$result  = CE61_AI::run( 'categorize_post', $post_id, array(
			'categories_list'    => self::categories_prompt_list(),
			'current_categories' => is_array( $current ) && $current ? implode( ', ', $current ) : '—',
			'excerpt'            => mb_substr( $content, 0, 2500 ),
		), $provider );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data = CE61_Creator::parse_json( $result );
		if ( ! is_array( $data ) || empty( $data['category'] ) ) {
			return new WP_Error( 'ce61_json', __( 'A IA não retornou uma sugestão válida. Tente novamente.', 'cluster-engine' ) );
		}
		$name     = sanitize_text_field( $data['category'] );
		$existing = get_term_by( 'name', $name, self::TAX );
		return array(
			'post_id'    => $post_id,
			'suggestion' => $name,
			'is_new'     => ! $existing,
			'confidence' => isset( $data['confidence'] ) ? (float) $data['confidence'] : null,
			'reason'     => isset( $data['reason'] ) ? sanitize_text_field( $data['reason'] ) : '',
			'current'    => is_array( $current ) ? $current : array(),
			'already'    => (bool) ( $existing && is_array( $current ) && in_array( $name, $current, true ) ),
		);
	}

	/**
	 * Aplica a categoria sugerida: define como primária mantendo as demais
	 * (append). Cria um 301 automático se a URL do post mudou por conta disso.
	 */
	public static function apply( $post_id, $category_name, $create_new = false ) {
		$post_id       = (int) $post_id;
		$category_name = trim( wp_strip_all_tags( (string) $category_name ) );
		if ( ! $post_id || '' === $category_name ) {
			return new WP_Error( 'ce61_cat', __( 'Dados insuficientes para aplicar a categoria.', 'cluster-engine' ) );
		}
		$term = get_term_by( 'name', $category_name, self::TAX );
		if ( ! $term ) {
			if ( ! $create_new ) {
				return new WP_Error( 'ce61_cat', __( 'Essa categoria não existe. Confirme a criação de uma nova.', 'cluster-engine' ) );
			}
			$ins = wp_insert_term( $category_name, self::TAX );
			if ( is_wp_error( $ins ) ) {
				return $ins;
			}
			$term_id = (int) $ins['term_id'];
		} else {
			$term_id = (int) $term->term_id;
		}

		$old_url = get_permalink( $post_id );
		wp_set_post_terms( $post_id, array( $term_id ), self::TAX, true ); // append: mantém as demais.
		self::set_primary_category( $post_id, $term_id );
		clean_post_cache( $post_id );
		$new_url = get_permalink( $post_id );

		$redirect = false;
		if ( class_exists( 'CE61_Redirects' ) && $old_url && $new_url && $old_url !== $new_url ) {
			$r        = CE61_Redirects::add( $old_url, $new_url, 301 );
			$redirect = ! is_wp_error( $r );
		}

		return array(
			'post_id'  => $post_id,
			'term_id'  => $term_id,
			'category' => $category_name,
			'redirect' => $redirect,
			'new_url'  => $new_url,
		);
	}

	/**
	 * Grava a categoria primária no meta lido pelo plugin de SEO ativo (para
	 * o permalink %category% e os breadcrumbs) e num meta próprio de reserva.
	 */
	private static function set_primary_category( $post_id, $term_id ) {
		switch ( CE61_SEO::active_plugin() ) {
			case 'yoast':
				update_post_meta( $post_id, '_yoast_wpseo_primary_category', (int) $term_id );
				break;
			case 'rankmath':
				update_post_meta( $post_id, 'rank_math_primary_category', (int) $term_id );
				break;
			case 'seopress':
				update_post_meta( $post_id, '_seopress_robots_primary_cat', (int) $term_id );
				break;
		}
		update_post_meta( $post_id, '_ce61_primary_category', (int) $term_id );
	}

	/**
	 * Sugere novas categorias com base no conteúdo do site.
	 */
	public static function suggest( $count = 5, $provider = '' ) {
		$context = CE61_Creator::site_context( 50 );
		if ( '' === trim( $context ) ) {
			return new WP_Error( 'ce61_ctx', __( 'Escaneie o site primeiro para o plugin entender o conteúdo existente.', 'cluster-engine' ) );
		}
		$result = CE61_AI::run( 'suggest_categories', 0, array(
			'count'           => (int) $count,
			'site_context'    => $context,
			'categories_list' => self::categories_prompt_list(),
		), $provider );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data = CE61_Creator::parse_json( $result );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'ce61_json', __( 'A IA não retornou JSON válido. Tente novamente.', 'cluster-engine' ) );
		}
		$out = array();
		foreach ( $data as $item ) {
			if ( empty( $item['name'] ) ) {
				continue;
			}
			$name  = sanitize_text_field( $item['name'] );
			$out[] = array(
				'name'        => $name,
				'description' => isset( $item['description'] ) ? sanitize_text_field( $item['description'] ) : '',
				'keyword'     => isset( $item['keyword'] ) ? sanitize_text_field( $item['keyword'] ) : '',
				'rationale'   => isset( $item['rationale'] ) ? sanitize_text_field( $item['rationale'] ) : '',
				'exists'      => (bool) get_term_by( 'name', $name, self::TAX ),
			);
		}
		return $out;
	}

	/**
	 * Cria uma categoria manualmente.
	 */
	public static function create( $name, $description = '' ) {
		$name = trim( wp_strip_all_tags( (string) $name ) );
		if ( '' === $name ) {
			return new WP_Error( 'ce61_cat', __( 'Informe o nome da categoria.', 'cluster-engine' ) );
		}
		if ( get_term_by( 'name', $name, self::TAX ) ) {
			return new WP_Error( 'ce61_cat', __( 'Já existe uma categoria com esse nome.', 'cluster-engine' ) );
		}
		$ins = wp_insert_term( $name, self::TAX, array( 'description' => sanitize_textarea_field( $description ) ) );
		if ( is_wp_error( $ins ) ) {
			return $ins;
		}
		return array( 'term_id' => (int) $ins['term_id'], 'name' => $name );
	}

	/**
	 * Gera (IA) e aplica SEO + descrição nativa da categoria. Devolve os
	 * valores gravados e um image_prompt sugerido para a capa.
	 */
	public static function generate_seo( $term_id, $provider = '' ) {
		$term_id = (int) $term_id;
		$term    = get_term( $term_id, self::TAX );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'ce61_cat', __( 'Categoria não encontrada.', 'cluster-engine' ) );
		}
		$sample = self::sample_post_titles( $term_id, 15 );
		$result = CE61_AI::run( 'category_seo', 0, array(
			'category_name'  => $term->name,
			'category_posts' => $sample ? $sample : '(sem posts nesta categoria ainda)',
		), $provider );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data = CE61_Creator::parse_json( $result );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'ce61_json', __( 'A IA não retornou JSON válido. Tente novamente.', 'cluster-engine' ) );
		}
		$title      = isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '';
		$desc       = isset( $data['description'] ) ? sanitize_text_field( $data['description'] ) : '';
		$native     = isset( $data['native_description'] ) ? sanitize_textarea_field( $data['native_description'] ) : '';
		$img_prompt = isset( $data['image_prompt'] ) ? sanitize_text_field( $data['image_prompt'] ) : '';

		if ( $title || $desc ) {
			CE61_SEO::set_term_seo( $term_id, $title, $desc );
		}
		if ( $native ) {
			wp_update_term( $term_id, self::TAX, array( 'description' => $native ) );
		}
		return array(
			'term_id'            => $term_id,
			'title'              => $title,
			'desc'               => $desc,
			'native_description' => $native,
			'image_prompt'       => $img_prompt,
		);
	}

	/**
	 * Salva edições manuais do SEO da categoria.
	 */
	public static function save_seo( $term_id, $title, $desc, $native ) {
		$term_id = (int) $term_id;
		if ( ! get_term( $term_id, self::TAX ) ) {
			return new WP_Error( 'ce61_cat', __( 'Categoria não encontrada.', 'cluster-engine' ) );
		}
		CE61_SEO::set_term_seo( $term_id, $title, $desc );
		wp_update_term( $term_id, self::TAX, array( 'description' => sanitize_textarea_field( $native ) ) );
		return array( 'term_id' => $term_id );
	}

	/**
	 * Gera a imagem de capa da categoria (WebP + SEO) e a guarda em term meta.
	 */
	public static function generate_image( $term_id, $prompt = '' ) {
		$term_id = (int) $term_id;
		$term    = get_term( $term_id, self::TAX );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'ce61_cat', __( 'Categoria não encontrada.', 'cluster-engine' ) );
		}
		$prompt = trim( (string) $prompt );
		if ( '' === $prompt ) {
			$prompt = 'Editorial cover image representing the topic "' . $term->name . '", clean modern flat style, soft lighting, no text, no watermark.';
		}
		$bytes = CE61_Images::generate( $prompt );
		if ( is_wp_error( $bytes ) ) {
			return $bytes;
		}
		$bytes = CE61_Images::apply_watermark( $bytes );
		$store = CE61_Images::store_attachment( 0, $bytes, array(
			'keyword'     => $term->name,
			'slug_suffix' => 'categoria',
			'alt'         => sprintf( __( 'Imagem da categoria %s', 'cluster-engine' ), $term->name ),
			'caption'     => $term->name,
			'description' => sprintf( __( 'Imagem de capa da categoria %s.', 'cluster-engine' ), $term->name ),
		) );
		if ( is_wp_error( $store ) ) {
			return $store;
		}
		update_term_meta( $term_id, self::IMG_META, (int) $store['attachment_id'] );
		return array(
			'term_id'   => $term_id,
			'image_id'  => (int) $store['attachment_id'],
			'image_url' => $store['url'],
		);
	}

	private static function sample_post_titles( $term_id, $limit = 15 ) {
		$q = new WP_Query( array(
			'post_type'      => self::post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => (int) $limit,
			'no_found_rows'  => true,
			'fields'         => 'ids',
			'tax_query'      => array(
				array( 'taxonomy' => self::TAX, 'field' => 'term_id', 'terms' => (int) $term_id ),
			),
		) );
		$lines = array();
		foreach ( $q->posts as $pid ) {
			$lines[] = '- ' . get_the_title( $pid );
		}
		return implode( "\n", $lines );
	}
}
