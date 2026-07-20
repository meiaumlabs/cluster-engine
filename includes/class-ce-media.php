<?php
/**
 * CE61_Media — conversão para WebP das imagens dos artigos (imagens destacadas
 * e anexos ligados aos posts indexados), mantendo SEMPRE o arquivo original.
 * A conversão cria um novo anexo .webp, copia os campos de SEO e repõe as
 * referências (imagem destacada e URLs no corpo) para o novo arquivo. Como o
 * original é preservado, a operação é reversível.
 *
 * @package ClusterEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CE61_Media {

	/**
	 * Imagens dos artigos candidatas à conversão: imagens destacadas dos posts
	 * indexados + anexos de imagem filhos desses posts. Marca quais já são WebP
	 * e quais já foram convertidas antes.
	 */
	public static function article_images() {
		global $wpdb;
		$post_ids = array_map( 'intval', (array) $wpdb->get_col( "SELECT post_id FROM {$wpdb->prefix}ce_index" ) );
		if ( ! $post_ids ) {
			return array();
		}

		$att_to_post = array();
		foreach ( $post_ids as $pid ) {
			$thumb = (int) get_post_thumbnail_id( $pid );
			if ( $thumb ) {
				$att_to_post[ $thumb ] = $pid;
			}
		}

		$in   = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_parent FROM {$wpdb->posts}
			 WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%%' AND post_parent IN ($in)",
			$post_ids
		), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( (array) $rows as $r ) {
			$aid = (int) $r['ID'];
			if ( ! isset( $att_to_post[ $aid ] ) ) {
				$att_to_post[ $aid ] = (int) $r['post_parent'];
			}
		}

		$out = array();
		foreach ( $att_to_post as $aid => $pid ) {
			$mime = (string) get_post_mime_type( $aid );
			$file = get_attached_file( $aid );
			$size = ( $file && file_exists( $file ) ) ? filesize( $file ) : 0;
			$out[] = array(
				'attachment_id' => (int) $aid,
				'post_id'       => (int) $pid,
				'post_title'    => get_the_title( $pid ),
				'edit'          => get_edit_post_link( $pid, 'raw' ),
				'thumb'         => wp_get_attachment_image_url( $aid, 'thumbnail' ),
				'mime'          => $mime,
				'filename'      => $file ? basename( $file ) : '',
				'bytes'         => (int) $size,
				'is_webp'       => ( 'image/webp' === $mime ),
				'converted'     => (bool) get_post_meta( $aid, '_ce61_converted_to', true ),
			);
		}

		usort( $out, function ( $a, $b ) {
			// WebP e já convertidas por último; maiores arquivos primeiro.
			if ( $a['is_webp'] !== $b['is_webp'] ) { return $a['is_webp'] ? 1 : -1; }
			return $b['bytes'] - $a['bytes'];
		} );
		return $out;
	}

	/**
	 * Converte UM anexo para WebP mantendo o original. Cria novo anexo, copia o
	 * SEO, repõe imagem destacada e URLs no corpo do post pai. Idempotente: se já
	 * foi convertido, devolve o anexo existente.
	 *
	 * @return array|WP_Error { new_id, url, saved_bytes }
	 */
	public static function convert_attachment( $attach_id ) {
		$attach_id = (int) $attach_id;
		$attach    = get_post( $attach_id );
		if ( ! $attach || 'attachment' !== $attach->post_type ) {
			return new WP_Error( 'ce61_media', __( 'Anexo inválido.', 'cluster-engine' ) );
		}
		if ( 'image/webp' === get_post_mime_type( $attach_id ) ) {
			return new WP_Error( 'ce61_media', __( 'Este anexo já está em WebP.', 'cluster-engine' ) );
		}
		$existing = (int) get_post_meta( $attach_id, '_ce61_converted_to', true );
		if ( $existing && get_post( $existing ) ) {
			return array( 'new_id' => $existing, 'url' => wp_get_attachment_url( $existing ), 'saved_bytes' => 0, 'already' => true );
		}

		$file = get_attached_file( $attach_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return new WP_Error( 'ce61_media', __( 'Arquivo original não encontrado.', 'cluster-engine' ) );
		}
		$src_bytes = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$webp      = CE61_Images::to_webp( $src_bytes, true );
		if ( null === $webp ) {
			return new WP_Error( 'ce61_media', __( 'Este servidor não consegue gerar WebP (faltam GD/Imagick com suporte a WebP).', 'cluster-engine' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$parent   = (int) $attach->post_parent;
		$name     = pathinfo( $file, PATHINFO_FILENAME );
		$filename = $name . '.webp';
		$upload   = wp_upload_bits( $filename, null, $webp );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'ce61_media', $upload['error'] );
		}

		$new = wp_insert_attachment( array(
			'post_mime_type' => 'image/webp',
			'post_title'     => $attach->post_title,
			'post_excerpt'   => $attach->post_excerpt, // legenda.
			'post_content'   => $attach->post_content, // descrição.
			'post_status'    => 'inherit',
		), $upload['file'], $parent );
		if ( is_wp_error( $new ) ) {
			return $new;
		}
		wp_update_attachment_metadata( $new, wp_generate_attachment_metadata( $new, $upload['file'] ) );
		$alt = get_post_meta( $attach_id, '_wp_attachment_image_alt', true );
		if ( $alt ) {
			update_post_meta( $new, '_wp_attachment_image_alt', $alt );
		}
		update_post_meta( $new, '_ce61_generated', 1 );
		update_post_meta( $new, '_ce61_converted_from', $attach_id );
		update_post_meta( $attach_id, '_ce61_converted_to', $new );

		self::repoint_references( $attach_id, $new, $parent );

		$saved = max( 0, (int) ( filesize( $file ) - strlen( $webp ) ) );
		return array( 'new_id' => (int) $new, 'url' => wp_get_attachment_url( $new ), 'saved_bytes' => $saved );
	}

	/**
	 * Repõe as referências do anexo antigo para o novo: imagem destacada e URLs
	 * (e classe wp-image-ID) no corpo do post pai. O original é mantido, então
	 * qualquer referência não coberta continua válida (sem quebrar a página).
	 */
	private static function repoint_references( $old_id, $new_id, $parent ) {
		if ( ! $parent ) {
			return;
		}
		if ( (int) get_post_thumbnail_id( $parent ) === (int) $old_id ) {
			set_post_thumbnail( $parent, $new_id );
		}
		$post = get_post( $parent );
		if ( ! $post ) {
			return;
		}
		$content = (string) $post->post_content;
		$old_url = wp_get_attachment_url( $old_id );
		$new_url = wp_get_attachment_url( $new_id );
		$changed = false;

		if ( $old_url && $new_url && false !== strpos( $content, $old_url ) ) {
			$content = str_replace( $old_url, $new_url, $content );
			$changed = true;
		}
		if ( false !== strpos( $content, 'wp-image-' . $old_id ) ) {
			$content = str_replace( 'wp-image-' . $old_id, 'wp-image-' . $new_id, $content );
			$changed = true;
		}
		if ( $changed ) {
			wp_update_post( array( 'ID' => $parent, 'post_content' => wp_slash( $content ) ), false );
		}
	}
}
