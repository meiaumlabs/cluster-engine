<?php
/**
 * CE61_Images — featured image generation: catálogo de modelos atualizado,
 * presets de estilo/proporção, e watermark em texto ou imagem.
 *
 * @package ClusterEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CE61_Images {

	public static function catalog() {
		return array(
			'openai' => array(
				'gpt-image-1' => array(
					'label' => 'GPT Image 1 (recomendado)',
					'note'  => 'Mais compatível e testado da família.',
					'sizes' => array( '1024x1024' => 'Quadrado', '1536x1024' => 'Paisagem (3:2)', '1024x1536' => 'Retrato (2:3)', 'auto' => 'Automático' ),
				),
				'gpt-image-1-mini' => array(
					'label' => 'GPT Image 1 Mini',
					'note'  => 'Mais barato e rápido; qualidade um degrau abaixo.',
					'sizes' => array( '1024x1024' => 'Quadrado', '1536x1024' => 'Paisagem (3:2)', '1024x1536' => 'Retrato (2:3)', 'auto' => 'Automático' ),
				),
				'gpt-image-1.5' => array(
					'label' => 'GPT Image 1.5',
					'note'  => 'Mais recente da linha 1.x, melhor fidelidade ao prompt.',
					'sizes' => array( '1024x1024' => 'Quadrado', '1536x1024' => 'Paisagem (3:2)', '1024x1536' => 'Retrato (2:3)', 'auto' => 'Automático' ),
				),
				'gpt-image-2' => array(
					'label' => 'GPT Image 2',
					'note'  => 'Mais avançado; aceita proporções livres, sem fundo transparente.',
					'sizes' => array( '1024x1024' => 'Quadrado', '1536x1024' => 'Paisagem (3:2)', '1024x1536' => 'Retrato (2:3)', '1792x1024' => 'Widescreen', '1024x1792' => 'Vertical', 'auto' => 'Automático' ),
				),
			),
			'gemini' => array(
				'gemini-2.5-flash-image' => array(
					'label'  => 'Nano Banana (Gemini nativo)',
					'note'   => 'Geração nativa do Gemini, edição conversacional.',
					'family' => 'gemini',
				),
				'gemini-3.1-flash-image-preview' => array(
					'label'  => 'Nano Banana 2 (preview)',
					'note'   => 'Mais recente, até 4K e melhor texto — em preview.',
					'family' => 'gemini',
				),
				'imagen-4.0-generate-001' => array(
					'label'  => 'Imagen 4',
					'note'   => 'Google avisou desligamento p/ 17/08/2026 — prefira os modelos Nano Banana acima.',
					'family' => 'imagen',
				),
				'imagen-4.0-fast-generate-001' => array(
					'label'  => 'Imagen 4 Fast',
					'note'   => 'Mais barato da linha Imagen. Mesmo aviso de desligamento.',
					'family' => 'imagen',
				),
				'imagen-4.0-ultra-generate-001' => array(
					'label'  => 'Imagen 4 Ultra',
					'note'   => 'Maior qualidade da linha Imagen (2K). Mesmo aviso de desligamento.',
					'family' => 'imagen',
				),
			),
		);
	}

	public static function style_presets() {
		return array(
			'ultra_real' => array( 'label' => 'Ultra realista', 'phrase' => 'fotografia ultra realista, alta definição, textura e luz natural, como uma foto profissional real' ),
			'editorial'  => array( 'label' => 'Ilustração editorial', 'phrase' => 'ilustração editorial moderna e conceitual, traços limpos' ),
			'minimal'    => array( 'label' => 'Minimalista / flat', 'phrase' => 'design minimalista flat, poucas cores, muito espaço em branco' ),
			'3d_render'  => array( 'label' => '3D render', 'phrase' => 'render 3D estilizado, iluminação suave, materiais realistas (PBR)' ),
			'cinematic'  => array( 'label' => 'Iluminação cinematográfica', 'phrase' => 'iluminação cinematográfica dramática, alto contraste' ),
			'bw'         => array( 'label' => 'Preto e branco', 'phrase' => 'preto e branco, alto contraste' ),
			'no_people'  => array( 'label' => 'Sem pessoas/rostos', 'phrase' => 'sem pessoas nem rostos humanos na cena' ),
		);
	}

	public static function aspect_ratios() {
		return array(
			'square'    => array( 'label' => 'Quadrado (1:1)', 'ratio' => '1:1' ),
			'landscape' => array( 'label' => 'Paisagem (4:3)', 'ratio' => '4:3' ),
			'portrait'  => array( 'label' => 'Retrato (3:4)', 'ratio' => '3:4' ),
			'wide'      => array( 'label' => 'Widescreen (16:9)', 'ratio' => '16:9' ),
			'tall'      => array( 'label' => 'Vertical/Stories (9:16)', 'ratio' => '9:16' ),
		);
	}

	public static function default_prompt() {
		return "Imagem de destaque para artigo de blog profissional sobre: {{title}} (assunto central: {{keyword}}).\n"
			. "Composição ampla com espaço de respiro.\n"
			. "Paleta de cores predominante: {{colors}}.\n"
			. "Contexto do site {{site_name}}: {{style_notes}}\n"
			. "IMPORTANTE: SEM nenhum texto, letras, palavras, números ou logotipos dentro da imagem. Sem rostos fotorrealistas de pessoas identificáveis.";
	}

	public static function resolve_prompt( $post_id, $template = '', $overrides = array() ) {
		$settings = get_option( 'ce61_settings', array() );
		if ( '' === trim( $template ) ) {
			$template = isset( $settings['image_prompt'] ) && '' !== trim( $settings['image_prompt'] )
				? $settings['image_prompt']
				: self::default_prompt();
		}
		global $wpdb;
		$cluster_name = '';
		$cid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT cluster_id FROM {$wpdb->prefix}ce_index WHERE post_id = %d", $post_id ) );
		if ( $cid ) {
			$cluster_name = (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}ce_clusters WHERE id = %d", $cid ) );
		}
		$extra = array(
			'colors'       => isset( $settings['image_colors'] ) && $settings['image_colors'] ? $settings['image_colors'] : 'azuis profissionais com um acento quente',
			'style_notes'  => isset( $settings['image_style'] ) ? $settings['image_style'] : '',
			'cluster_name' => $cluster_name,
		);
		$prompt = CE61_AI::resolve_vars( $template, $post_id, $extra );

		$presets = isset( $overrides['style_presets'] )
			? (array) $overrides['style_presets']
			: ( isset( $settings['image_style_presets'] ) ? (array) $settings['image_style_presets'] : array() );
		$catalog = self::style_presets();
		$phrases = array();
		foreach ( $presets as $key ) {
			if ( isset( $catalog[ $key ] ) ) {
				$phrases[] = $catalog[ $key ]['phrase'];
			}
		}
		if ( $phrases ) {
			$prompt .= "\nEstilo adicional: " . implode( '; ', $phrases ) . '.';
		}

		$aspect = isset( $overrides['aspect'] ) ? $overrides['aspect'] : ( isset( $settings['image_aspect'] ) ? $settings['image_aspect'] : 'wide' );
		$ratios = self::aspect_ratios();
		if ( isset( $ratios[ $aspect ] ) ) {
			$prompt .= "\nProporção da composição: " . $ratios[ $aspect ]['ratio'] . '.';
		}

		return $prompt;
	}

	private static function resolve_dimensions( $provider, $model, $aspect ) {
		$ratios = self::aspect_ratios();
		$ratio  = isset( $ratios[ $aspect ] ) ? $ratios[ $aspect ]['ratio'] : '16:9';

		if ( 'openai' === $provider ) {
			$map = array( '1:1' => '1024x1024', '4:3' => '1536x1024', '16:9' => '1536x1024', '3:4' => '1024x1536', '9:16' => '1024x1536' );
			return isset( $map[ $ratio ] ) ? $map[ $ratio ] : '1536x1024';
		}
		$valid = array( '1:1', '4:3', '3:4', '16:9', '9:16' );
		return in_array( $ratio, $valid, true ) ? $ratio : '16:9';
	}

	public static function generate( $prompt, $overrides = array() ) {
		$settings = get_option( 'ce61_settings', array() );
		$provider = isset( $settings['image_provider'] ) ? $settings['image_provider'] : 'openai';
		$aspect   = isset( $overrides['aspect'] ) ? $overrides['aspect'] : ( isset( $settings['image_aspect'] ) ? $settings['image_aspect'] : 'wide' );

		if ( 'gemini' === $provider ) {
			$key = trim( isset( $settings['api_key_gemini'] ) ? $settings['api_key_gemini'] : '' );
			if ( ! $key ) {
				return new WP_Error( 'ce61_no_key', __( 'Geração de imagem via Google requer a chave do Gemini em Configurações.', 'cluster-engine' ) );
			}
			$model   = ! empty( $overrides['model'] ) ? $overrides['model'] : ( ! empty( $settings['image_model'] ) ? $settings['image_model'] : 'gemini-2.5-flash-image' );
			$catalog = self::catalog();
			$gcat    = $catalog['gemini'];
			$family  = isset( $gcat[ $model ]['family'] ) ? $gcat[ $model ]['family'] : ( 0 === strpos( $model, 'imagen' ) ? 'imagen' : 'gemini' );
			$ratio   = self::resolve_dimensions( 'gemini', $model, $aspect );

			if ( 'imagen' === $family ) {
				$res = wp_remote_post(
					'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':predict?key=' . rawurlencode( $key ),
					array(
						'timeout' => 55,
						'headers' => array( 'Content-Type' => 'application/json' ),
						'body'    => wp_json_encode( array(
							'instances'  => array( array( 'prompt' => $prompt ) ),
							'parameters' => array( 'sampleCount' => 1, 'aspectRatio' => $ratio ),
						) ),
					)
				);
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				$http_code = (int) wp_remote_retrieve_response_code( $res );
				$body      = json_decode( wp_remote_retrieve_body( $res ), true );
				if ( $http_code && $http_code >= 400 ) {
					$api_msg = isset( $body['error']['message'] ) ? $body['error']['message'] : wp_remote_retrieve_response_message( $res );
					return new WP_Error( 'ce61_img', sprintf( 'Imagen API HTTP %d: %s', $http_code, $api_msg ) );
				}
				if ( isset( $body['predictions'][0]['bytesBase64Encoded'] ) ) {
					return base64_decode( $body['predictions'][0]['bytesBase64Encoded'] );
				}
				return new WP_Error( 'ce61_img', isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'Resposta inesperada do Imagen.', 'cluster-engine' ) );
			}

			$parts = array( array( 'text' => $prompt ) );
			if ( ! empty( $overrides['reference_bytes'] ) ) {
				$ref_mime = self::sniff_mime( $overrides['reference_bytes'] );
				$parts[]  = array( 'inline_data' => array( 'mime_type' => $ref_mime, 'data' => base64_encode( $overrides['reference_bytes'] ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			}
			$res = wp_remote_post(
				'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $key ),
				array(
					'timeout' => 55,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( array(
						'contents'         => array( array( 'parts' => $parts ) ),
						'generationConfig' => array(
							'responseModalities' => array( 'TEXT', 'IMAGE' ),
							'imageConfig'         => array( 'aspectRatio' => $ratio ),
						),
					) ),
				)
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$http_code = (int) wp_remote_retrieve_response_code( $res );
			$body      = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( $http_code && $http_code >= 400 ) {
				$api_msg = isset( $body['error']['message'] ) ? $body['error']['message'] : wp_remote_retrieve_response_message( $res );
				return new WP_Error( 'ce61_img', sprintf( 'Gemini API HTTP %d: %s', $http_code, $api_msg ) );
			}
			$parts = isset( $body['candidates'][0]['content']['parts'] ) ? $body['candidates'][0]['content']['parts'] : array();
			foreach ( $parts as $part ) {
				if ( ! empty( $part['inlineData']['data'] ) ) {
					return base64_decode( $part['inlineData']['data'] );
				}
				if ( ! empty( $part['inline_data']['data'] ) ) {
					return base64_decode( $part['inline_data']['data'] );
				}
			}
			return new WP_Error( 'ce61_img', isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'Resposta inesperada do Gemini.', 'cluster-engine' ) );
		}

		$key = trim( isset( $settings['api_key_openai'] ) ? $settings['api_key_openai'] : '' );
		if ( ! $key ) {
			return new WP_Error( 'ce61_no_key', __( 'Geração de imagem requer a chave da OpenAI (ou Google) em Configurações. A Anthropic não gera imagens.', 'cluster-engine' ) );
		}
		$model = ! empty( $overrides['model'] ) ? $overrides['model'] : ( ! empty( $settings['image_model'] ) ? trim( $settings['image_model'] ) : 'gpt-image-1' );
		if ( in_array( $model, array( 'dall-e-2', 'dall-e-3' ), true ) ) {
			$model = 'gpt-image-1';
		}
		$size = self::resolve_dimensions( 'openai', $model, $aspect );

		// Imagem de referência → endpoint de edição (image-to-image) via multipart.
		if ( ! empty( $overrides['reference_bytes'] ) ) {
			return self::openai_edit( $key, $model, $prompt, $size, $overrides['reference_bytes'] );
		}

		$body = array(
			'model'  => $model,
			'prompt' => $prompt,
			'n'      => 1,
			'size'   => $size,
		);

		$do_request = function ( $req_body ) use ( $key ) {
			return wp_remote_post( 'https://api.openai.com/v1/images/generations', array(
				'timeout' => 55,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $req_body ),
			) );
		};

		$res  = $do_request( $body );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$http_code = (int) wp_remote_retrieve_response_code( $res );
		$data      = json_decode( wp_remote_retrieve_body( $res ), true );
		$err       = isset( $data['error']['message'] ) ? $data['error']['message'] : '';

		if ( $err && false !== stripos( $err, 'size' ) && isset( $body['size'] ) ) {
			$body['size'] = '1024x1024';
			$res  = $do_request( $body );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$http_code = (int) wp_remote_retrieve_response_code( $res );
			$data      = json_decode( wp_remote_retrieve_body( $res ), true );
			$err       = isset( $data['error']['message'] ) ? $data['error']['message'] : '';
		}

		if ( $http_code && $http_code >= 400 ) {
			$api_msg = $err ? $err : wp_remote_retrieve_response_message( $res );
			return new WP_Error( 'ce61_img', sprintf( 'OpenAI API HTTP %d: %s', $http_code, $api_msg ) );
		}

		if ( isset( $data['data'][0]['b64_json'] ) ) {
			return base64_decode( $data['data'][0]['b64_json'] );
		}
		if ( isset( $data['data'][0]['url'] ) ) {
			$img = wp_remote_get( $data['data'][0]['url'], array( 'timeout' => 60 ) );
			if ( ! is_wp_error( $img ) ) {
				return wp_remote_retrieve_body( $img );
			}
		}
		return new WP_Error( 'ce61_img', $err ? $err : __( 'Resposta inesperada da OpenAI.', 'cluster-engine' ) );
	}

	/**
	 * Detecta o MIME de bytes de imagem (para enviar a referência com o tipo
	 * certo às APIs). Cai em image/png quando não consegue identificar.
	 */
	private static function sniff_mime( $bytes ) {
		if ( function_exists( 'getimagesizefromstring' ) ) {
			$info = @getimagesizefromstring( $bytes );
			if ( ! empty( $info['mime'] ) ) {
				return $info['mime'];
			}
		}
		return 'image/png';
	}

	/**
	 * Geração com imagem de referência na OpenAI (endpoint /images/edits).
	 * Monta um corpo multipart/form-data manualmente porque wp_remote_post não
	 * envia arquivos binários por conta própria.
	 */
	private static function openai_edit( $key, $model, $prompt, $size, $ref_bytes ) {
		$mime     = self::sniff_mime( $ref_bytes );
		$ext      = 'image/webp' === $mime ? 'webp' : ( 'image/jpeg' === $mime ? 'jpg' : 'png' );
		$boundary = 'ce61' . md5( microtime() . wp_rand() );
		$eol      = "\r\n";

		$fields = array(
			'model'  => $model,
			'prompt' => $prompt,
			'n'      => '1',
			'size'   => $size,
		);
		$payload = '';
		foreach ( $fields as $name => $value ) {
			$payload .= '--' . $boundary . $eol;
			$payload .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
			$payload .= $value . $eol;
		}
		$payload .= '--' . $boundary . $eol;
		$payload .= 'Content-Disposition: form-data; name="image"; filename="reference.' . $ext . '"' . $eol;
		$payload .= 'Content-Type: ' . $mime . $eol . $eol;
		$payload .= $ref_bytes . $eol;
		$payload .= '--' . $boundary . '--' . $eol;

		$res = wp_remote_post( 'https://api.openai.com/v1/images/edits', array(
			'timeout' => 90,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
			),
			'body'    => $payload,
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$http_code = (int) wp_remote_retrieve_response_code( $res );
		$data      = json_decode( wp_remote_retrieve_body( $res ), true );
		$err       = isset( $data['error']['message'] ) ? $data['error']['message'] : '';
		if ( $http_code && $http_code >= 400 ) {
			$api_msg = $err ? $err : wp_remote_retrieve_response_message( $res );
			return new WP_Error( 'ce61_img', sprintf( 'OpenAI edits HTTP %d: %s', $http_code, $api_msg ) );
		}
		if ( isset( $data['data'][0]['b64_json'] ) ) {
			return base64_decode( $data['data'][0]['b64_json'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		}
		if ( isset( $data['data'][0]['url'] ) ) {
			$img = wp_remote_get( $data['data'][0]['url'], array( 'timeout' => 60 ) );
			if ( ! is_wp_error( $img ) ) {
				return wp_remote_retrieve_body( $img );
			}
		}
		return new WP_Error( 'ce61_img', $err ? $err : __( 'Resposta inesperada da OpenAI (edits).', 'cluster-engine' ) );
	}

	public static function apply_watermark( $bytes ) {
		$s    = get_option( 'ce61_settings', array() );
		$type = isset( $s['image_watermark_type'] ) ? $s['image_watermark_type'] : 'text';

		if ( 'image' === $type ) {
			$url = '';
			if ( ! empty( $s['image_watermark_media_id'] ) ) {
				$url = wp_get_attachment_url( (int) $s['image_watermark_media_id'] );
			}
			if ( ! $url && ! empty( $s['image_watermark_url'] ) ) {
				$url = $s['image_watermark_url'];
			}
			if ( ! $url ) {
				return $bytes;
			}
			$res = wp_remote_get( $url, array( 'timeout' => 20 ) );
			if ( is_wp_error( $res ) ) {
				return $bytes;
			}
			$logo = wp_remote_retrieve_body( $res );
			return $logo ? self::watermark_image( $bytes, $logo ) : $bytes;
		}

		return self::watermark_text( $bytes, isset( $s['image_watermark'] ) ? $s['image_watermark'] : '' );
	}

	public static function watermark_text( $bytes, $text ) {
		$text = trim( (string) $text );
		if ( '' === $text || ! function_exists( 'imagecreatefromstring' ) ) {
			return $bytes;
		}
		$im = @imagecreatefromstring( $bytes );
		if ( ! $im ) {
			return $bytes;
		}
		$ascii = remove_accents( $text );
		$font  = 5;
		$w     = imagesx( $im );
		$h     = imagesy( $im );
		$tw    = imagefontwidth( $font ) * strlen( $ascii );
		$th    = imagefontheight( $font );
		$x     = max( 8, $w - $tw - 20 );
		$y     = max( 8, $h - $th - 16 );

		$shadow = imagecolorallocatealpha( $im, 0, 0, 0, 45 );
		$white  = imagecolorallocatealpha( $im, 255, 255, 255, 18 );
		imagestring( $im, $font, $x + 1, $y + 1, $ascii, $shadow );
		imagestring( $im, $font, $x, $y, $ascii, $white );

		ob_start();
		imagepng( $im );
		$out = ob_get_clean();
		imagedestroy( $im );
		return $out ? $out : $bytes;
	}

	public static function watermark_image( $bytes, $logo_bytes, $opacity = 85 ) {
		if ( ! function_exists( 'imagecreatefromstring' ) ) {
			return $bytes;
		}
		$im   = @imagecreatefromstring( $bytes );
		$logo = @imagecreatefromstring( $logo_bytes );
		if ( ! $im || ! $logo ) {
			return $bytes;
		}
		imagesavealpha( $logo, true );
		$w  = imagesx( $im );
		$h  = imagesy( $im );
		$lw = imagesx( $logo );
		$lh = imagesy( $logo );
		if ( ! $lw || ! $lh ) {
			imagedestroy( $im );
			imagedestroy( $logo );
			return $bytes;
		}
		$target_w = max( 40, (int) round( $w * 0.16 ) );
		$target_h = max( 1, (int) round( $lh * ( $target_w / $lw ) ) );

		$resized = imagecreatetruecolor( $target_w, $target_h );
		imagealphablending( $resized, false );
		imagesavealpha( $resized, true );
		$transparent = imagecolorallocatealpha( $resized, 0, 0, 0, 127 );
		imagefill( $resized, 0, 0, $transparent );
		imagecopyresampled( $resized, $logo, 0, 0, 0, 0, $target_w, $target_h, $lw, $lh );
		imagealphablending( $resized, true );

		$x = max( 10, $w - $target_w - 24 );
		$y = max( 10, $h - $target_h - 20 );
		imagecopymerge( $im, $resized, $x, $y, 0, 0, $target_w, $target_h, $opacity );

		ob_start();
		imagepng( $im );
		$out = ob_get_clean();
		imagedestroy( $im );
		imagedestroy( $logo );
		imagedestroy( $resized );
		return $out ? $out : $bytes;
	}

	/* ---------- Otimização WebP ---------- */

	/**
	 * Converte bytes de imagem (PNG/JPEG) para WebP otimizado, respeitando as
	 * Configurações (ativar/desativar e qualidade). Usa GD (imagewebp) e, na
	 * falta, Imagick. Retorna os bytes WebP ou null quando não é possível/desligado
	 * — nesse caso o chamador segue com o formato original.
	 */
	public static function to_webp( $bytes, $force = false ) {
		$s       = get_option( 'ce61_settings', array() );
		$enabled = ! isset( $s['image_webp'] ) || $s['image_webp'];
		if ( ( ! $enabled && ! $force ) || '' === (string) $bytes ) {
			return null;
		}
		$quality = isset( $s['image_webp_quality'] ) && (int) $s['image_webp_quality'] > 0
			? min( 100, max( 40, (int) $s['image_webp_quality'] ) )
			: 82;

		if ( function_exists( 'imagecreatefromstring' ) && function_exists( 'imagewebp' ) ) {
			$im = @imagecreatefromstring( $bytes );
			if ( $im ) {
				if ( function_exists( 'imagepalettetotruecolor' ) ) {
					@imagepalettetotruecolor( $im );
				}
				imagealphablending( $im, false );
				imagesavealpha( $im, true );
				ob_start();
				$ok  = @imagewebp( $im, null, $quality );
				$out = ob_get_clean();
				imagedestroy( $im );
				if ( $ok && $out ) {
					return $out;
				}
			}
		}

		if ( class_exists( 'Imagick' ) ) {
			try {
				$img = new Imagick();
				$img->readImageBlob( $bytes );
				$img->setImageFormat( 'webp' );
				$img->setImageCompressionQuality( $quality );
				$out = $img->getImageBlob();
				$img->clear();
				$img->destroy();
				if ( $out ) {
					return $out;
				}
			} catch ( Exception $e ) {
				return null;
			}
		}
		return null;
	}

	/* ---------- Anexo com SEO completo ---------- */

	/**
	 * Salva os bytes na Biblioteca de Mídia (convertendo para WebP quando
	 * possível) preenchendo TODOS os campos relevantes para SEO de imagem:
	 * nome de arquivo baseado na keyword/título, Alt text, Título, Legenda e
	 * Descrição. NÃO define como destacada nem insere no corpo — apenas cria o
	 * anexo e devolve os dados. É a base compartilhada por attach_as_featured()
	 * e attach_inline().
	 *
	 * @param array $meta { keyword?, alt?, caption?, description?, slug_suffix? }.
	 */
	public static function store_attachment( $post_id, $bytes, $meta = array() ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$title   = get_the_title( $post_id );
		$keyword = isset( $meta['keyword'] ) && $meta['keyword'] ? $meta['keyword'] : self::guess_keyword( $post_id );
		$site    = get_bloginfo( 'name' );

		// Otimização: converte para WebP quando o servidor suporta e está ligado.
		$ext  = 'png';
		$mime = 'image/png';
		$webp = self::to_webp( $bytes );
		if ( null !== $webp ) {
			$bytes = $webp;
			$ext   = 'webp';
			$mime  = 'image/webp';
		}

		// Nome de arquivo amigável para SEO (Google Imagens também lê a URL).
		$slug_source = $keyword ? $keyword : $title;
		$slug        = sanitize_title( $slug_source );
		$slug        = $slug ? $slug : 'imagem';
		if ( ! empty( $meta['slug_suffix'] ) ) {
			$slug .= '-' . sanitize_title( (string) $meta['slug_suffix'] );
		}
		$filename = $slug . '-' . substr( md5( $post_id . microtime() ), 0, 6 ) . '.' . $ext;

		$upload = wp_upload_bits( $filename, null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'ce61_upload', $upload['error'] );
		}

		$alt         = isset( $meta['alt'] ) && $meta['alt'] ? $meta['alt'] : ( $keyword ? ucfirst( $keyword ) . ' — ' . $title : $title );
		$caption     = isset( $meta['caption'] ) && $meta['caption'] ? $meta['caption'] : $alt;
		$description = isset( $meta['description'] ) && $meta['description']
			? $meta['description']
			: sprintf(
				/* translators: 1: keyword or title, 2: article title, 3: site name */
				__( 'Imagem ilustrativa sobre %1$s, usada no artigo "%2$s" publicado em %3$s.', 'cluster-engine' ),
				$keyword ? $keyword : $title, $title, $site
			);

		$attachment = array(
			'post_mime_type' => $mime,
			'post_title'     => wp_strip_all_tags( $title ),
			'post_excerpt'   => wp_strip_all_tags( $caption ),  // legenda (caption).
			'post_content'   => wp_strip_all_tags( $description ), // descrição.
			'post_status'    => 'inherit',
		);
		$attach_id = wp_insert_attachment( $attachment, $upload['file'], $post_id );
		if ( is_wp_error( $attach_id ) ) {
			return $attach_id;
		}
		wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $upload['file'] ) );
		update_post_meta( $attach_id, '_wp_attachment_image_alt', wp_strip_all_tags( $alt ) );
		update_post_meta( $attach_id, '_ce61_generated', 1 ); // marca como gerada pelo plugin.

		$url = wp_get_attachment_image_url( $attach_id, 'large' );
		return array(
			'attachment_id' => $attach_id,
			'url'           => $url ? $url : $upload['url'],
			'mime'          => $mime,
			'alt'           => wp_strip_all_tags( $alt ),
			'caption'       => wp_strip_all_tags( $caption ),
		);
	}

	/**
	 * Cria o anexo (WebP + SEO) e define como imagem destacada do post.
	 */
	public static function attach_as_featured( $post_id, $bytes, $meta = array() ) {
		$stored = self::store_attachment( $post_id, $bytes, $meta );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		set_post_thumbnail( $post_id, $stored['attachment_id'] );
		return array( 'attachment_id' => $stored['attachment_id'], 'url' => $stored['url'] );
	}

	/**
	 * Cria o anexo (WebP + SEO) e insere a imagem DENTRO do corpo do post como
	 * <figure> semântico (img com alt/title/width/height/loading/decoding +
	 * <figcaption>), na posição escolhida: 'start', 'after_h2' (após o 1º H2) ou
	 * 'end'. Não altera a imagem destacada.
	 *
	 * @param string $position start|after_h2|end
	 */
	public static function attach_inline( $post_id, $bytes, $meta = array(), $position = 'after_h2' ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'ce61_no_post', __( 'Post não encontrado.', 'cluster-engine' ) );
		}
		$meta['slug_suffix'] = isset( $meta['slug_suffix'] ) ? $meta['slug_suffix'] : 'ilustracao';
		$stored              = self::store_attachment( $post_id, $bytes, $meta );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		$figure  = self::build_figure( $stored['attachment_id'], $stored['alt'], $stored['caption'] );
		$content = self::insert_at_position( (string) $post->post_content, $figure, $position );

		$upd = wp_update_post( array( 'ID' => $post_id, 'post_content' => wp_slash( $content ) ), true );
		if ( is_wp_error( $upd ) ) {
			return $upd;
		}
		return array( 'attachment_id' => $stored['attachment_id'], 'url' => $stored['url'], 'position' => $position );
	}

	/**
	 * HTML de <figure> responsivo e otimizado para uma imagem já anexada.
	 */
	public static function build_figure( $attach_id, $alt, $caption ) {
		$img = wp_get_attachment_image(
			$attach_id,
			'large',
			false,
			array(
				'alt'      => $alt,
				'title'    => $alt,
				'loading'  => 'lazy',
				'decoding' => 'async',
				'class'    => 'ce61-inline-img',
			)
		);
		if ( ! $img ) {
			$url = wp_get_attachment_image_url( $attach_id, 'large' );
			$img = '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" title="' . esc_attr( $alt ) . '" loading="lazy" decoding="async" class="ce61-inline-img">';
		}
		$cap = $caption ? '<figcaption>' . esc_html( $caption ) . '</figcaption>' : '';
		return "\n<figure class=\"ce61-figure wp-block-image size-large\" data-ce61-image=\"" . (int) $attach_id . "\">" . $img . $cap . "</figure>\n";
	}

	/**
	 * Insere um bloco HTML no conteúdo na posição pedida. 'after_h2' cai após o
	 * fechamento do primeiro H2; sem H2, insere após o 1º parágrafo; e, na falta
	 * dele, no fim.
	 */
	private static function insert_at_position( $content, $block, $position ) {
		if ( 'start' === $position ) {
			return $block . "\n" . $content;
		}
		if ( 'end' === $position ) {
			return $content . "\n" . $block;
		}
		// after_h2 (padrão).
		if ( preg_match( '/<\/h2>/i', $content, $m, PREG_OFFSET_CAPTURE ) ) {
			$at = $m[0][1] + strlen( $m[0][0] );
			return substr( $content, 0, $at ) . $block . substr( $content, $at );
		}
		if ( preg_match( '/<\/p>/i', $content, $m, PREG_OFFSET_CAPTURE ) ) {
			$at = $m[0][1] + strlen( $m[0][0] );
			return substr( $content, 0, $at ) . $block . substr( $content, $at );
		}
		return $content . "\n" . $block;
	}

	/* ---------- Biblioteca de presets (modelos de imagem do usuário) ---------- */

	/**
	 * Lista os presets salvos pelo usuário (modelos de imagem reutilizáveis que
	 * mantêm a mesma linha editorial). Cada preset guarda prompt, estilos,
	 * proporção, provedor/modelo e, opcionalmente, uma imagem de referência.
	 */
	public static function presets() {
		$list = get_option( 'ce61_image_presets', array() );
		return is_array( $list ) ? array_values( $list ) : array();
	}

	/**
	 * Salva (cria ou atualiza) um preset. Devolve a lista completa atualizada.
	 *
	 * @param array $data { id?, label, prompt?, style_presets?, aspect?, provider?, model?, reference_id? }
	 */
	public static function preset_save( $data ) {
		$label = isset( $data['label'] ) ? sanitize_text_field( $data['label'] ) : '';
		if ( '' === trim( $label ) ) {
			return new WP_Error( 'ce61_preset', __( 'Dê um nome ao preset.', 'cluster-engine' ) );
		}
		$list = self::presets();
		$id   = isset( $data['id'] ) && $data['id'] ? sanitize_key( $data['id'] ) : 'p' . substr( md5( microtime() . wp_rand() ), 0, 10 );

		$preset = array(
			'id'            => $id,
			'label'         => $label,
			'prompt'        => isset( $data['prompt'] ) ? sanitize_textarea_field( $data['prompt'] ) : '',
			'style_presets' => isset( $data['style_presets'] ) ? array_values( array_map( 'sanitize_key', (array) $data['style_presets'] ) ) : array(),
			'aspect'        => isset( $data['aspect'] ) ? sanitize_key( $data['aspect'] ) : '',
			'provider'      => isset( $data['provider'] ) ? sanitize_key( $data['provider'] ) : '',
			'model'         => isset( $data['model'] ) ? sanitize_text_field( $data['model'] ) : '',
			'reference_id'  => isset( $data['reference_id'] ) ? (int) $data['reference_id'] : 0,
			'updated'       => time(),
		);

		$found = false;
		foreach ( $list as $i => $p ) {
			if ( isset( $p['id'] ) && $p['id'] === $id ) {
				$list[ $i ] = $preset;
				$found      = true;
				break;
			}
		}
		if ( ! $found ) {
			$list[] = $preset;
		}
		update_option( 'ce61_image_presets', array_values( $list ), false );
		return self::presets();
	}

	/**
	 * Remove um preset pelo id. Devolve a lista atualizada.
	 */
	public static function preset_delete( $id ) {
		$id   = sanitize_key( $id );
		$list = self::presets();
		$out  = array();
		foreach ( $list as $p ) {
			if ( ! isset( $p['id'] ) || $p['id'] !== $id ) {
				$out[] = $p;
			}
		}
		update_option( 'ce61_image_presets', array_values( $out ), false );
		return $out;
	}

	/**
	 * Bytes de uma imagem de referência a partir de um anexo da biblioteca.
	 * Usado tanto pelo campo de referência quanto pelo modelo editorial do preset.
	 */
	public static function reference_bytes_from_attachment( $attach_id ) {
		$attach_id = (int) $attach_id;
		if ( ! $attach_id ) {
			return null;
		}
		$file = get_attached_file( $attach_id );
		if ( $file && file_exists( $file ) ) {
			return file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$url = wp_get_attachment_url( $attach_id );
		if ( $url ) {
			$res = wp_remote_get( $url, array( 'timeout' => 30 ) );
			if ( ! is_wp_error( $res ) ) {
				return wp_remote_retrieve_body( $res );
			}
		}
		return null;
	}

	/**
	 * Keyword foco do post (via plugin de SEO) ou, na falta dela, o termo
	 * mais forte já indexado pelo Cluster Engine — usado para nomear o
	 * arquivo e escrever o alt/legenda/descrição da imagem.
	 */
	private static function guess_keyword( $post_id ) {
		$kw = CE61_SEO::get_focus_keyword( $post_id );
		if ( $kw ) {
			return $kw;
		}
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT main_keyword FROM {$wpdb->prefix}ce_index WHERE post_id = %d", $post_id
		) );
	}
}
