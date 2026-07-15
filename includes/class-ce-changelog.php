<?php
/**
 * CE61_Changelog — histórico de alterações de conteúdo feitas pela IA do editor.
 *
 * Cada vez que o conteúdo de um post/página é atualizado pela extensão do
 * editor (editor_apply), o conteúdo ANTERIOR é guardado como uma entrada do
 * changelog daquele post. Isso permite:
 *  - listar as URLs atualizadas e o histórico de mudanças de cada uma;
 *  - reverter uma URL para a versão guardada antes de uma alteração.
 *
 * Armazenado em postmeta (_ce61_change_log) como JSON, com no máximo MAX
 * entradas por post (as mais recentes primeiro). O snapshot é gravado com
 * wp_slash() para sobreviver ao wp_unslash() interno de update_metadata.
 *
 * @package ClusterEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CE61_Changelog {

	const META = '_ce61_change_log';
	const MAX  = 12;

	/**
	 * Lê o log bruto de um post (array de entradas, mais recentes primeiro).
	 */
	public static function get( $post_id ) {
		$raw = get_post_meta( (int) $post_id, self::META, true );
		$log = $raw ? json_decode( $raw, true ) : array();
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Registra uma alteração guardando o conteúdo anterior (para reverter).
	 *
	 * @param int    $post_id      Post alterado.
	 * @param string $prev_content Conteúdo ANTES da alteração.
	 * @param array  $args         summary, mode, published.
	 * @return string ID da entrada criada.
	 */
	public static function add( $post_id, $prev_content, $args = array() ) {
		$post_id = (int) $post_id;
		if ( ! $post_id ) {
			return '';
		}
		$log   = self::get( $post_id );
		$entry = array(
			'id'        => uniqid( 'ce', true ),
			'at'        => current_time( 'mysql' ),
			'summary'   => isset( $args['summary'] ) ? (string) $args['summary'] : __( 'Conteúdo atualizado com IA', 'cluster-engine' ),
			'mode'      => isset( $args['mode'] ) ? (string) $args['mode'] : '',
			'published' => ! empty( $args['published'] ) ? 1 : 0,
			'by'        => get_current_user_id(),
			'prev'      => (string) $prev_content,
		);
		array_unshift( $log, $entry );
		if ( count( $log ) > self::MAX ) {
			$log = array_slice( $log, 0, self::MAX );
		}
		update_post_meta( $post_id, self::META, wp_slash( wp_json_encode( $log, JSON_UNESCAPED_UNICODE ) ) );
		return $entry['id'];
	}

	/**
	 * Entradas para exibição (sem o conteúdo completo, só metadados).
	 */
	public static function entries( $post_id ) {
		$out = array();
		foreach ( self::get( $post_id ) as $e ) {
			if ( empty( $e['id'] ) ) {
				continue;
			}
			$who = ! empty( $e['by'] ) ? get_userdata( (int) $e['by'] ) : null;
			$out[] = array(
				'id'        => $e['id'],
				'at'        => isset( $e['at'] ) ? $e['at'] : '',
				'summary'   => isset( $e['summary'] ) ? $e['summary'] : '',
				'mode'      => isset( $e['mode'] ) ? $e['mode'] : '',
				'published' => ! empty( $e['published'] ) ? 1 : 0,
				'by'        => $who ? $who->display_name : '',
				'chars'     => isset( $e['prev'] ) ? mb_strlen( (string) $e['prev'] ) : 0,
			);
		}
		return $out;
	}

	/**
	 * Reverte o post ao conteúdo guardado na entrada informada. Antes de
	 * reverter, registra o estado atual como nova entrada (revert reversível).
	 */
	public static function revert( $post_id, $entry_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'ce61_cl', __( 'Post não encontrado.', 'cluster-engine' ) );
		}
		$target = null;
		foreach ( self::get( $post_id ) as $e ) {
			if ( isset( $e['id'] ) && $e['id'] === $entry_id ) {
				$target = $e;
				break;
			}
		}
		if ( ! $target || ! isset( $target['prev'] ) ) {
			return new WP_Error( 'ce61_cl', __( 'Versão não encontrada no histórico.', 'cluster-engine' ) );
		}
		// Guarda o estado atual antes de reverter.
		self::add( $post_id, $post->post_content, array(
			'summary' => __( 'Estado antes de reverter', 'cluster-engine' ),
			'mode'    => 'pre_revert',
		) );
		$upd = wp_update_post( array( 'ID' => $post_id, 'post_content' => wp_slash( (string) $target['prev'] ) ), true );
		if ( is_wp_error( $upd ) ) {
			return $upd;
		}
		return true;
	}

	/**
	 * Registro global: URLs atualizadas + histórico de cada uma (metadados).
	 */
	public static function registry( $limit = 50 ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY meta_id DESC LIMIT %d",
			self::META, (int) $limit
		) );
		$out = array();
		foreach ( array_unique( array_map( 'intval', (array) $ids ) ) as $pid ) {
			$post = get_post( $pid );
			if ( ! $post ) {
				continue;
			}
			$entries = self::entries( $pid );
			if ( ! $entries ) {
				continue;
			}
			$out[] = array(
				'post_id' => $pid,
				'title'   => $post->post_title,
				'url'     => get_permalink( $pid ),
				'edit'    => get_edit_post_link( $pid, 'raw' ),
				'entries' => $entries,
			);
		}
		return $out;
	}
}
