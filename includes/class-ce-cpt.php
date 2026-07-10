<?php
/**
 * CE61_CPT — descoberta de Custom Post Types e integração com o JetEngine.
 *
 * Somente LEITURA das definições existentes: lista os CPTs públicos do site
 * (nativos e criados pelo JetEngine), lê a estrutura dos meta fields do
 * JetEngine para dar contexto à IA e grava valores nesses campos nos posts
 * gerados. Nunca cria novos CPTs nem altera a configuração do JetEngine.
 *
 * Habilitar um CPT = adicioná-lo a ce61_settings['post_types'], que já é a
 * chave usada por todo o pipeline (indexação, relações, clusters, linkagem
 * interna, diagnóstico) — ou seja, o CPT passa a receber exatamente o mesmo
 * tratamento que os posts comuns.
 *
 * @package ClusterEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CE61_CPT {

	/**
	 * JetEngine está ativo?
	 */
	public static function is_jetengine() {
		return function_exists( 'jet_engine' ) && jet_engine();
	}

	/**
	 * Tipos de campo do JetEngine que sabemos preencher com texto/valor simples.
	 * Campos de mídia, repeater e afins ficam de fora desta versão.
	 */
	private static function writable_types() {
		return array( 'text', 'textarea', 'wysiwyg', 'number', 'date', 'datetime-local', 'time', 'select', 'radio', 'checkbox', 'switcher', 'colorpicker' );
	}

	/**
	 * Slugs dos CPTs registrados pelo próprio JetEngine (para marcar a origem).
	 */
	private static function jetengine_cpt_slugs() {
		$slugs = array();
		if ( ! self::is_jetengine() ) {
			return $slugs;
		}
		$je = jet_engine();
		if ( isset( $je->cpt ) && $je->cpt && method_exists( $je->cpt, 'get_items' ) ) {
			foreach ( (array) $je->cpt->get_items() as $item ) {
				$item = (array) $item;
				if ( ! empty( $item['slug'] ) ) {
					$slugs[] = $item['slug'];
				}
			}
		}
		return $slugs;
	}

	/**
	 * Lista todos os CPTs públicos não nativos com metadados de gestão.
	 */
	public static function list_cpts() {
		global $wpdb;
		$settings = get_option( 'ce61_settings', array() );
		$enabled  = isset( $settings['post_types'] ) && $settings['post_types'] ? (array) $settings['post_types'] : array( 'post' );
		$je_slugs = self::jetengine_cpt_slugs();

		$types = get_post_types( array( 'public' => true, '_builtin' => false ), 'objects' );
		$out   = array();
		foreach ( $types as $pt ) {
			$counts    = wp_count_posts( $pt->name );
			$published = isset( $counts->publish ) ? (int) $counts->publish : 0;
			$indexed   = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}ce_index i INNER JOIN {$wpdb->posts} p ON p.ID = i.post_id WHERE p.post_type = %s",
				$pt->name
			) );
			$out[] = array(
				'name'     => $pt->name,
				'label'    => $pt->labels->name,
				'singular' => $pt->labels->singular_name,
				'count'    => $published,
				'source'   => in_array( $pt->name, $je_slugs, true ) ? 'jetengine' : 'native',
				'enabled'  => in_array( $pt->name, $enabled, true ),
				'indexed'  => $indexed,
				'fields'   => self::meta_fields( $pt->name ),
			);
		}
		return $out;
	}

	/**
	 * Meta fields de um post type (só leitura da estrutura).
	 * Tenta a API do JetEngine em camadas e cai para a meta registrada nativa.
	 *
	 * @return array Lista de [ name, title, type, options ].
	 */
	public static function meta_fields( $post_type ) {
		$fields = array();

		if ( self::is_jetengine() ) {
			$je  = jet_engine();
			$raw = array();

			// 1) API direta por contexto, se disponível nesta versão do JetEngine.
			if ( isset( $je->meta_boxes ) && $je->meta_boxes ) {
				if ( method_exists( $je->meta_boxes, 'get_fields_for_context' ) ) {
					$raw = $je->meta_boxes->get_fields_for_context( 'post_type', $post_type );
				} elseif ( method_exists( $je->meta_boxes, 'get_meta_fields_for_object' ) ) {
					$raw = $je->meta_boxes->get_meta_fields_for_object( $post_type );
				}
			}
			foreach ( (array) $raw as $f ) {
				$field = self::normalize_field( (array) $f );
				if ( $field ) {
					$fields[ $field['name'] ] = $field;
				}
			}

			// 2) Data store dos meta boxes, filtrando pelo post type permitido.
			if ( ! $fields && isset( $je->meta_boxes->data ) && method_exists( $je->meta_boxes->data, 'get_items' ) ) {
				foreach ( (array) $je->meta_boxes->data->get_items() as $box ) {
					$box     = (array) $box;
					$args    = isset( $box['args'] ) ? (array) $box['args'] : array();
					$allowed = isset( $args['allowed_post_type'] ) ? (array) $args['allowed_post_type'] : array();
					if ( $allowed && ! in_array( $post_type, $allowed, true ) ) {
						continue;
					}
					$mfields = isset( $box['meta_fields'] ) ? (array) $box['meta_fields'] : array();
					foreach ( $mfields as $f ) {
						$field = self::normalize_field( (array) $f );
						if ( $field ) {
							$fields[ $field['name'] ] = $field;
						}
					}
				}
			}
		}

		// 3) Fallback: meta registrada nativamente para o post type.
		if ( ! $fields ) {
			$registered = get_registered_meta_keys( 'post', $post_type );
			foreach ( (array) $registered as $key => $args ) {
				if ( '' === $key || '_' === $key[0] ) {
					continue; // ignora meta protegida.
				}
				$fields[ $key ] = array( 'name' => $key, 'title' => $key, 'type' => 'text', 'options' => array() );
			}
		}

		return array_values( $fields );
	}

	/**
	 * Normaliza um field cru do JetEngine para o formato interno, filtrando
	 * tipos que não sabemos preencher.
	 */
	private static function normalize_field( $f ) {
		if ( empty( $f['name'] ) ) {
			return null;
		}
		$type = isset( $f['type'] ) ? $f['type'] : 'text';
		if ( ! in_array( $type, self::writable_types(), true ) ) {
			return null;
		}
		return array(
			'name'    => (string) $f['name'],
			'title'   => isset( $f['title'] ) && '' !== $f['title'] ? (string) $f['title'] : (string) $f['name'],
			'type'    => $type,
			'options' => isset( $f['options'] ) ? self::normalize_options( $f['options'] ) : array(),
		);
	}

	/**
	 * Opções do JetEngine podem vir como lista de [key,value] ou mapa; devolve
	 * uma lista simples de valores utilizáveis.
	 */
	private static function normalize_options( $options ) {
		$out = array();
		foreach ( (array) $options as $k => $v ) {
			if ( is_array( $v ) && isset( $v['value'] ) ) {
				$out[] = (string) $v['value'];
			} elseif ( is_scalar( $v ) ) {
				$out[] = ( is_string( $k ) && '' !== $k ) ? $k : (string) $v;
			}
		}
		return array_values( array_filter( $out, 'strlen' ) );
	}

	/**
	 * Habilita/desabilita um CPT no pipeline (membership em post_types).
	 */
	public static function enable( $post_type ) {
		return self::toggle( $post_type, true );
	}

	public static function disable( $post_type ) {
		return self::toggle( $post_type, false );
	}

	private static function toggle( $post_type, $on ) {
		if ( ! post_type_exists( $post_type ) ) {
			return new WP_Error( 'ce61_cpt', __( 'Tipo de post inexistente.', 'cluster-engine' ) );
		}
		$settings = get_option( 'ce61_settings', array() );
		$types    = isset( $settings['post_types'] ) && $settings['post_types'] ? array_values( (array) $settings['post_types'] ) : array( 'post' );
		if ( $on ) {
			if ( ! in_array( $post_type, $types, true ) ) {
				$types[] = $post_type;
			}
		} else {
			$types = array_values( array_filter( $types, function ( $t ) use ( $post_type ) {
				return $t !== $post_type;
			} ) );
		}
		$settings['post_types'] = $types;
		update_option( 'ce61_settings', $settings );
		if ( $on ) {
			self::reindex_type( $post_type );
		}
		return true;
	}

	/**
	 * Indexa os posts publicados do tipo recém-habilitado (para já aparecerem
	 * na base). Relações e clusters são construídos no "Escanear site".
	 * Cap defensivo para não estourar o tempo do request.
	 */
	private static function reindex_type( $post_type ) {
		$ids = get_posts( array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => 300,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'DESC',
		) );
		foreach ( $ids as $id ) {
			CE61_Indexer::index_post( $id );
		}
	}

	/**
	 * Descrição legível dos campos para injetar no prompt da IA.
	 */
	public static function fields_spec( $fields ) {
		$lines = array();
		foreach ( (array) $fields as $f ) {
			$line = '- ' . $f['name'] . ' (' . $f['title'] . ') [' . $f['type'] . ']';
			if ( ! empty( $f['options'] ) ) {
				$line .= ' — valores possíveis: ' . implode( ', ', array_slice( $f['options'], 0, 12 ) );
			}
			$lines[] = $line;
		}
		return implode( "\n", $lines );
	}

	/**
	 * Grava valores nos meta fields do post. Só grava chaves que existem na
	 * definição do CPT — nunca meta arbitrária. Sanitiza por tipo de campo.
	 */
	public static function write_meta( $post_id, $values ) {
		if ( ! is_array( $values ) || ! $values ) {
			return;
		}
		$post_type = get_post_type( $post_id );
		$fields    = self::meta_fields( $post_type );
		if ( ! $fields ) {
			return;
		}
		$allowed = array();
		foreach ( $fields as $f ) {
			$allowed[ $f['name'] ] = $f;
		}
		foreach ( $values as $key => $val ) {
			if ( ! isset( $allowed[ $key ] ) ) {
				continue;
			}
			update_post_meta( $post_id, $key, self::sanitize_value( $allowed[ $key ], $val ) );
		}
	}

	private static function sanitize_value( $field, $val ) {
		if ( is_array( $val ) ) {
			$val = implode( ', ', array_map( 'strval', $val ) );
		}
		$val = (string) $val;
		switch ( $field['type'] ) {
			case 'number':
				return is_numeric( $val ) ? $val + 0 : 0;
			case 'wysiwyg':
				return wp_kses_post( $val );
			case 'textarea':
				return sanitize_textarea_field( $val );
			default:
				return sanitize_text_field( $val );
		}
	}
}
