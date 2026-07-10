<?php
/**
 * CE61_AI — multi-provider AI hub with prompt library and {{variable}} resolution.
 *
 * @package ClusterEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CE61_AI {

	/**
	 * Factory prompt templates per action. All editable in the UI.
	 */
	public static function default_prompts() {
		return array(
			'rewrite_title' => array(
				'label'  => 'Reescrever meta title',
				'prompt' => "Reescreva o meta title abaixo para SEO em 2026. Máximo 60 caracteres, keyword no início, sem clickbait vazio.\n\nTítulo do post: {{title}}\nKeyword principal: {{keyword}}\nResumo: {{excerpt}}\nSite: {{site_name}}\n\nResponda APENAS com 3 opções numeradas, uma por linha, sem explicações.",
			),
			'rewrite_desc' => array(
				'label'  => 'Reescrever meta description',
				'prompt' => "Escreva uma meta description de 140 a 156 caracteres para o post abaixo. Inclua a keyword naturalmente e termine com um chamado à ação sutil.\n\nTítulo: {{title}}\nKeyword: {{keyword}}\nPrimeiro parágrafo: {{first_paragraph}}\n\nResponda APENAS com 3 opções numeradas, uma por linha.",
			),
			'answer_capsule' => array(
				'label'  => 'Gerar answer capsule (AEO)',
				'prompt' => "Escreva um parágrafo de abertura de 40 a 60 palavras que responda diretamente à pergunta implícita no título, no formato ideal para ser citado por AI Overviews. Direto, factual, sem enrolação.\n\nTítulo: {{title}}\nKeyword: {{keyword}}\nConteúdo de referência:\n{{content}}\n\nResponda APENAS com o parágrafo.",
			),
			'anchor_text' => array(
				'label'  => 'Sugerir anchor text de link interno',
				'prompt' => "Sugira 3 anchor texts (3 a 6 palavras cada) para um link interno que sai do trecho abaixo em direção ao post de destino.\n\nPRIORIDADE: sempre que possível, use uma frase que exista LITERALMENTE no trecho de origem (cópia exata, palavra por palavra), pois ela será transformada em link. Se nenhuma frase literal servir, crie uma âncora natural com a entidade/keyword do destino.\n\nTrecho de origem: {{source_paragraph}}\nPost de destino: {{target_title}}\nKeyword do destino: {{target_keyword}}\n\nResponda APENAS com as 3 opções numeradas, uma por linha, sem aspas.",
			),
			'generate_article' => array(
				'label'  => 'Gerar rascunho de artigo satélite',
				'prompt' => "Escreva um artigo completo em HTML (use h2, h3, p, ul) sobre: {{gap_topic}}.\n\nContexto: este artigo é um satélite do cluster \"{{cluster_name}}\" do site {{site_name}}, cujo pilar é \"{{pillar_title}}\". Keyword alvo: {{keyword}}.\n\nRequisitos SEO/AEO/GEO 2026:\n- Abra com answer capsule de 40-60 palavras.\n- Use headings em formato de pergunta quando fizer sentido.\n- Inclua uma seção final de Perguntas Frequentes com 4 perguntas.\n- Cite dados com fonte quando possível, incluindo pelo menos um link para uma fonte externa de autoridade.\n- Mencione o nome do site {{site_name}} pelo menos uma vez, de forma natural (ex.: \"aqui na {{site_name}}\" ou \"a equipe da {{site_name}}\").\n- Inclua, dentro do corpo do texto (não apenas em uma lista de links no fim), um link interno HTML para o artigo pilar do cluster usando a keyword do pilar como parte do texto-âncora: <a href=\"{{pillar_url}}\">{{pillar_keyword}}</a> — encaixe isso numa frase que faça sentido, não cole o link solto. Se {{pillar_url}} estiver vazio, ignore esta instrução.\n- Conteúdo não-commodity com perspectiva própria; NÃO use travessão nem emojis.\n\nInclua também, quando fizer sentido, links internos para estes outros posts do site (use as URLs reais):\n{{internal_links_list}}\n\n{{custom_instructions}}\n\nResponda APENAS com o HTML do artigo.",
			),
			'generate_cpt_fields' => array(
				'label'  => 'Preencher campos personalizados de CPT',
				'prompt' => "Você vai preencher os campos personalizados (meta fields) de um item do tipo \"{{cpt_label}}\" no site {{site_name}}, com base no conteúdo já escrito abaixo.\n\nTítulo: {{title}}\nKeyword: {{keyword}}\n\nConteúdo do item:\n{{content}}\n\nCampos a preencher (nome técnico, rótulo, [tipo] e valores possíveis quando houver):\n{{meta_fields_spec}}\n\nRegras: preencha cada campo com um valor coerente e específico extraído ou inferido do conteúdo acima; para campos com valores possíveis, escolha SOMENTE entre eles; para número use apenas dígitos; para data use o formato AAAA-MM-DD; se não houver base para um campo, deixe-o de fora. Sem travessão e sem emojis.\n\nResponda APENAS com um objeto JSON válido, sem markdown e sem texto ao redor, mapeando o nome técnico de cada campo para o valor, no formato: {\"nome_do_campo\":\"valor\"}",
			),
			'refresh_post' => array(
				'label'  => 'Atualizar post antigo',
				'prompt' => "O post abaixo foi publicado em {{publish_date}} e precisa de atualização. Liste em formato de tópicos: 1) informações possivelmente desatualizadas, 2) seções que faltam para cobertura completa do tema, 3) sugestão de novo parágrafo de abertura (answer capsule).\n\nTítulo: {{title}}\nConteúdo:\n{{content}}",
			),
			'faq_schema' => array(
				'label'  => 'Gerar FAQ + Schema JSON-LD',
				'prompt' => "Com base no conteúdo abaixo, gere uma seção de Perguntas Frequentes com 4 perguntas e respostas curtas (40-60 palavras cada), seguida do bloco <script type=\"application/ld+json\"> com o schema FAQPage correspondente.\n\nTítulo: {{title}}\nKeyword: {{keyword}}\nConteúdo:\n{{content}}\n\nResponda APENAS com o HTML.",
			),
			'exec_summary' => array(
				'label'  => 'Resumo executivo do relatório',
				'prompt' => "Você é um consultor de SEO apresentando resultados para o dono do site {{site_name}}. Com base nos dados do relatório abaixo, escreva um resumo executivo de 3 parágrafos em português do Brasil: 1) estado geral da autoridade tópica do site; 2) os três problemas mais críticos e o impacto de cada um; 3) plano de ação recomendado em ordem de prioridade, com o resultado esperado. Tom direto e consultivo, sem travessões e sem emojis.\n\nDADOS DO RELATÓRIO:\n{{report_data}}",
			),
			'fix_headings' => array(
				'label'  => 'Corrigir capitalização dos headings',
				'prompt' => "Converta os headings abaixo de Title Case ou CAIXA ALTA para sentence case do português do Brasil: apenas a primeira letra da frase em maiúscula. MANTENHA maiúsculas em nomes próprios, marcas, produtos, siglas (CRM, SEO, IA, WhatsApp) e nomes de pessoas ou lugares. Não altere palavras, pontuação nem o significado — só a capitalização.\n\n{{headings_list}}\n\nResponda APENAS com os headings corrigidos, numerados na mesma ordem, um por linha.",
			),
			'suggest_keyword' => array(
				'label'  => 'Sugerir keyword foco',
				'prompt' => "Analise o post abaixo e sugira as 3 melhores opções de keyword foco para SEO, alinhadas à intenção de busca real do conteúdo. Cada opção deve ter 1 a 4 palavras, em minúsculas, no formato que as pessoas realmente pesquisam no Google.\n\nUse como candidatos os termos extraídos semanticamente do próprio post: {{keywords_list}}\n\nTítulo: {{title}}\nResumo: {{excerpt}}\n\nOrdene da melhor para a pior. Responda APENAS com as 3 opções numeradas, uma por linha, sem explicações.",
			),
			'performance_insight' => array(
				'label'  => 'Insight de performance (IA)',
				'prompt' => "Você é um analista de SEO e growth. Analise os dados de desempenho e o conteúdo do post abaixo e escreva um insight curto e acionável em português (máximo 5 parágrafos curtos, sem travessão, sem emojis):\n\n1) Diga em 1 frase se o post está melhorando, piorando ou estável, com base na série histórica.\n2) Se houve atualização de conteúdo na janela analisada, diga se o desempenho mudou depois dela (compare antes/depois).\n3) Aponte a causa mais provável (ex.: queda de posição, CTR baixo para a posição que ocupa, conteúdo desatualizado, falta de linkagem interna, notas SEO/AEO/GEO baixas).\n4) Termine com 2 a 3 ações concretas e priorizadas para melhorar, específicas para este post (não genéricas).\n\nDADOS DE DESEMPENHO (série temporal, mais recente por último):\n{{perf_series}}\n\nDatas em que o conteúdo foi atualizado nesta janela: {{update_dates}}\n\nDIAGNÓSTICO ATUAL DO POST:\n{{post_diagnostics}}\n\nTÍTULO: {{title}}\nKEYWORD FOCO: {{keyword}}\nRESUMO DO CONTEÚDO:\n{{excerpt}}",
			),
			'improve_prompt' => array(
				'label'  => 'Melhorar prompt de geração de artigo',
				'prompt' => "Você é um especialista em prompt engineering para geração de conteúdo SEO/AEO/GEO em 2026. Reescreva e melhore o prompt do usuário abaixo para produzir um artigo mais completo, específico e alinhado a E-E-A-T (Experiência, Expertise, Autoridade, Confiança): peça exemplos concretos e dados/estatísticas reais, estrutura clara com subtítulos, citação de pelo menos uma fonte externa de autoridade, e uma seção de Perguntas Frequentes. Mantenha o foco no título e na keyword informados — não mude o assunto nem invente um tema novo.\n\nTítulo do artigo: {{title}}\nKeyword foco: {{keyword}}\nPrompt/instruções atuais do usuário:\n{{user_prompt}}\n\nResponda APENAS com o prompt melhorado, em português, pronto para uso — sem comentários, sem explicações, sem aspas envolvendo o texto.",
			),
			'merge_posts' => array(
				'label'  => 'Recriar post unificado (canibalização)',
				'prompt' => "Os dois posts abaixo canibalizam a mesma intenção de busca no site {{site_name}}. Escreva UM artigo unificado em HTML (use h2, h3, p, ul) que substitua os dois, aproveitando o melhor conteúdo de cada um, eliminando redundâncias e cobrindo o tema por completo.\n\nRequisitos SEO/AEO/GEO 2026: abra com answer capsule de 40-60 palavras; headings em formato de pergunta quando fizer sentido; seção final de Perguntas Frequentes com 4 perguntas; mantenha dados e fontes citadas nos originais; NÃO use travessão nem emojis.\n\n== POST A: {{title}} ==\n{{content}}\n\n== POST B: {{title_b}} ==\n{{content_b}}\n\nResponda APENAS com o HTML do artigo unificado.",
			),
			'name_cluster' => array(
				'label'  => 'Nomear cluster',
				'prompt' => "Dê um nome curto (2 a 4 palavras) para um cluster de conteúdo que reúne os posts abaixo. Responda APENAS com o nome.\n\nPosts:\n{{cluster_posts}}",
			),
			'suggest_clusters' => array(
				'label'  => 'Sugerir novos clusters (com base no site)',
				'prompt' => "Você é um estrategista de conteúdo. Analise o contexto abaixo, entenda do que trata o site e sugira {{count}} NOVOS clusters de conteúdo que ampliem a autoridade tópica sem duplicar os clusters existentes. Cada sugestão deve ser um tema coerente com o negócio e o público do site.\n\nCONTEXTO DO SITE:\n{{site_context}}\n\nResponda APENAS com um array JSON válido, sem markdown e sem texto ao redor, no formato:\n[{\"name\":\"Nome do cluster (2-4 palavras)\",\"description\":\"1 frase sobre o que o cluster cobre\",\"keyword\":\"keyword principal do pilar\",\"rationale\":\"por que faz sentido para este site\"}]",
			),
			'plan_cluster_content' => array(
				'label'  => 'Planejar conteúdo de um cluster (com briefing)',
				'prompt' => "Você é um estrategista de conteúdo e um editor experiente. Planeje {{count}} artigos para o cluster \"{{cluster_name}}\" ({{cluster_description}}) do site descrito abaixo. Os artigos devem cobrir o tema de forma complementar, SEM repetir os posts que o cluster já tem, e alinhados à intenção de busca real do público.\n\nSe o cluster ainda não tem um post pilar, o primeiro item deve ser o pilar (guia completo do tema); os demais são satélites.\n\nProfundidade alvo desta leva: cada artigo deve mirar aproximadamente {{word_count_target}} palavras e {{h2_count_target}} subtítulos H2, ajustando um pouco para cima ou para baixo conforme o que o subtema realmente exigir.\n\nPara CADA artigo, além do título e keyword, monte um briefing editorial curto baseado no que você sabe sobre o assunto (não é busca ao vivo, é síntese do seu conhecimento): liste de 3 a 5 perguntas frequentes que o público realmente faz sobre esse tópico específico, e de 3 a 6 subtemas/pontos-chave que o artigo precisa cobrir para ser completo.\n\nCONTEXTO DO SITE:\n{{site_context}}\n\nPOSTS QUE O CLUSTER JÁ TEM:\n{{cluster_posts}}\n\nResponda APENAS com um array JSON válido, sem markdown e sem texto ao redor, no formato:\n[{\"title\":\"Título do artigo\",\"keyword\":\"keyword foco em minúsculas\",\"type\":\"pillar ou satellite\",\"word_count\":1200,\"h2_count\":6,\"faqs\":[\"Pergunta 1?\",\"Pergunta 2?\"],\"subtopics\":[\"Subtema 1\",\"Subtema 2\"]}]",
			),
		);
	}

	/**
	 * Saved prompts merged over factory defaults — new actions added in
	 * plugin updates always exist, and user edits are preserved.
	 */
	public static function get_prompts() {
		$defaults = self::default_prompts();
		$saved    = get_option( 'ce61_prompts', array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$out = $defaults;
		foreach ( $saved as $key => $p ) {
			if ( isset( $defaults[ $key ] ) && isset( $p['prompt'] ) && '' !== trim( $p['prompt'] ) ) {
				$out[ $key ]['prompt'] = $p['prompt'];
			}
		}
		return $out;
	}

	/**
	 * Variables available for templates, resolved from live WordPress data.
	 */
	public static function resolve_vars( $template, $post_id = 0, $extra = array() ) {
		$vars = array(
			'site_name'   => get_bloginfo( 'name' ),
			'site_url'    => home_url(),
			'today'       => date_i18n( get_option( 'date_format' ) ),
		);

		if ( $post_id && ( $post = get_post( $post_id ) ) ) {
			$content = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
			$first   = '';
			if ( preg_match( '/<p[^>]*>(.*?)<\/p>/is', $post->post_content, $m ) ) {
				$first = trim( wp_strip_all_tags( $m[1] ) );
			}
			$cats = wp_get_post_terms( $post_id, 'category', array( 'fields' => 'names' ) );

			global $wpdb;
			$kws = json_decode( (string) $wpdb->get_var( $wpdb->prepare(
				"SELECT keywords FROM {$wpdb->prefix}ce_index WHERE post_id = %d", $post_id
			) ), true );
			$kws_list = is_array( $kws ) ? implode( ', ', array_slice( $kws, 0, 12 ) ) : '';

			$vars += array(
				'title'           => $post->post_title,
				'url'             => get_permalink( $post_id ),
				'excerpt'         => wp_trim_words( $content, 40 ),
				'content'         => mb_substr( $content, 0, 6000 ),
				'first_paragraph' => $first,
				'keyword'         => CE61_SEO::get_focus_keyword( $post_id ) ?: self::indexed_keyword( $post_id ),
				'keywords_list'   => $kws_list,
				'category'        => is_array( $cats ) && $cats ? implode( ', ', $cats ) : '',
				'author_name'     => get_the_author_meta( 'display_name', $post->post_author ),
				'author_bio'      => get_the_author_meta( 'description', $post->post_author ),
				'publish_date'    => get_the_date( '', $post_id ),
			);
		}

		$vars = array_merge( $vars, (array) $extra );

		return preg_replace_callback( '/\{\{\s*([a-z_]+)\s*\}\}/i', function ( $m ) use ( $vars ) {
			$k = strtolower( $m[1] );
			return isset( $vars[ $k ] ) ? (string) $vars[ $k ] : '';
		}, $template );
	}

	private static function indexed_keyword( $post_id ) {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT main_keyword FROM {$wpdb->prefix}ce_index WHERE post_id = %d", $post_id
		) );
	}

	/**
	 * Run an action: build final prompt (global identity + template) and call the provider.
	 */
	public static function run( $action, $post_id = 0, $extra = array() ) {
		$prompts  = self::get_prompts();
		$settings = get_option( 'ce61_settings', array() );

		if ( ! isset( $prompts[ $action ] ) ) {
			return new WP_Error( 'ce61_no_action', __( 'Ação de IA desconhecida.', 'cluster-engine' ) );
		}

		$system = self::resolve_vars( isset( $settings['global_prompt'] ) ? $settings['global_prompt'] : '', $post_id, $extra );
		$user   = self::resolve_vars( $prompts[ $action ]['prompt'], $post_id, $extra );

		return self::complete( $system, $user, $settings );
	}

	/**
	 * Effective provider: the selected one if it has a key; otherwise the
	 * first provider that does have a key. Prevents misconfiguration errors.
	 */
	public static function effective_provider( $settings ) {
		$pref = isset( $settings['provider'] ) ? $settings['provider'] : 'anthropic';
		$keys = array(
			'anthropic' => ! empty( $settings['api_key_anthropic'] ),
			'openai'    => ! empty( $settings['api_key_openai'] ),
			'gemini'    => ! empty( $settings['api_key_gemini'] ),
		);
		if ( ! empty( $keys[ $pref ] ) ) {
			return $pref;
		}
		foreach ( $keys as $p => $has ) {
			if ( $has ) {
				return $p;
			}
		}
		return '';
	}

	public static function provider_names() {
		return array(
			'anthropic' => 'Anthropic (Claude)',
			'openai'    => 'OpenAI (GPT)',
			'gemini'    => 'Google (Gemini)',
		);
	}

	/**
	 * Traduz falhas de rede do wp_remote_post em mensagens acionáveis.
	 */
	private static function net_error( $err ) {
		$msg = $err->get_error_message();
		if ( false !== stripos( $msg, 'timed out' ) || false !== stripos( $msg, 'timeout' ) ) {
			$msg .= ' — ' . __( 'A requisição excedeu o tempo limite. Verifique a conexão do servidor com a internet ou tente novamente.', 'cluster-engine' );
		} elseif ( false !== stripos( $msg, 'could not resolve' ) || false !== stripos( $msg, 'ssl' ) ) {
			$msg .= ' — ' . __( 'O servidor não conseguiu alcançar a API. Verifique DNS/SSL/firewall da hospedagem.', 'cluster-engine' );
		}
		return new WP_Error( 'ce61_net', $msg );
	}

	/**
	 * Formata erro de API com o código HTTP (facilita diagnosticar chave inválida,
	 * sem créditos, modelo inexistente etc.).
	 */
	private static function api_error( $code, $body, $fallback ) {
		$msg = isset( $body['error']['message'] ) ? $body['error']['message'] : $fallback;
		if ( 401 === $code || 403 === $code ) {
			$msg .= ' — ' . __( 'Chave de API inválida ou sem permissão. Confira a chave em Configurações.', 'cluster-engine' );
		} elseif ( 429 === $code ) {
			$msg .= ' — ' . __( 'Limite de requisições ou créditos esgotados no provedor.', 'cluster-engine' );
		} elseif ( 404 === $code ) {
			$msg .= ' — ' . __( 'Modelo não encontrado. Deixe o campo Modelo vazio para usar o padrão.', 'cluster-engine' );
		}
		return new WP_Error( 'ce61_api', 'HTTP ' . $code . ': ' . $msg );
	}

	/**
	 * Provider-agnostic completion via wp_remote_post.
	 */
	public static function complete( $system, $user, $settings ) {
		$preferred = isset( $settings['provider'] ) ? $settings['provider'] : 'anthropic';
		$provider  = self::effective_provider( $settings );

		if ( '' === $provider ) {
			return new WP_Error( 'ce61_no_key', __( 'Nenhuma chave de API configurada. Adicione a chave de pelo menos um provedor em Configurações.', 'cluster-engine' ) );
		}
		// If we fell back to a different provider, the custom model name from the
		// preferred provider would be invalid — use the fallback provider's default.
		$custom_model = ( $provider === $preferred && ! empty( $settings['model_light'] ) ) ? $settings['model_light'] : '';

		switch ( $provider ) {
			case 'openai':
				$key = trim( isset( $settings['api_key_openai'] ) ? $settings['api_key_openai'] : '' );
				if ( ! $key ) {
					return new WP_Error( 'ce61_no_key', __( 'Configure a chave de API da OpenAI em Configurações.', 'cluster-engine' ) );
				}
				$model = $custom_model ? $custom_model : 'gpt-4o-mini';
				$res   = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
					'timeout' => 90,
					'headers' => array(
						'Authorization' => 'Bearer ' . $key,
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode( array(
						'model'    => $model,
						'messages' => array(
							array( 'role' => 'system', 'content' => $system ),
							array( 'role' => 'user', 'content' => $user ),
						),
						'max_tokens' => 3000,
					) ),
				) );
				if ( is_wp_error( $res ) ) {
					return self::net_error( $res );
				}
				$code = (int) wp_remote_retrieve_response_code( $res );
				$body = json_decode( wp_remote_retrieve_body( $res ), true );
				if ( isset( $body['choices'][0]['message']['content'] ) ) {
					return trim( $body['choices'][0]['message']['content'] );
				}
				return self::api_error( $code, $body, __( 'Resposta inesperada da OpenAI.', 'cluster-engine' ) );

			case 'gemini':
				$key = trim( isset( $settings['api_key_gemini'] ) ? $settings['api_key_gemini'] : '' );
				if ( ! $key ) {
					return new WP_Error( 'ce61_no_key', __( 'Configure a chave de API do Gemini em Configurações.', 'cluster-engine' ) );
				}
				$model = $custom_model ? $custom_model : 'gemini-2.0-flash';
				$res   = wp_remote_post( 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $key ), array(
					'timeout' => 90,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( array(
						'system_instruction' => array( 'parts' => array( array( 'text' => $system ) ) ),
						'contents'           => array( array( 'parts' => array( array( 'text' => $user ) ) ) ),
					) ),
				) );
				if ( is_wp_error( $res ) ) {
					return self::net_error( $res );
				}
				$code = (int) wp_remote_retrieve_response_code( $res );
				$body = json_decode( wp_remote_retrieve_body( $res ), true );
				if ( isset( $body['candidates'][0]['content']['parts'][0]['text'] ) ) {
					return trim( $body['candidates'][0]['content']['parts'][0]['text'] );
				}
				return self::api_error( $code, $body, __( 'Resposta inesperada do Gemini.', 'cluster-engine' ) );

			case 'anthropic':
			default:
				$key = trim( isset( $settings['api_key_anthropic'] ) ? $settings['api_key_anthropic'] : '' );
				if ( ! $key ) {
					return new WP_Error( 'ce61_no_key', __( 'Configure a chave de API da Anthropic em Configurações.', 'cluster-engine' ) );
				}
				$model = $custom_model ? $custom_model : 'claude-haiku-4-5-20251001';
				$res   = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
					'timeout' => 90,
					'headers' => array(
						'x-api-key'         => $key,
						'anthropic-version' => '2023-06-01',
						'Content-Type'      => 'application/json',
					),
					'body'    => wp_json_encode( array(
						'model'      => $model,
						'max_tokens' => 3000,
						'system'     => $system,
						'messages'   => array( array( 'role' => 'user', 'content' => $user ) ),
					) ),
				) );
				if ( is_wp_error( $res ) ) {
					return self::net_error( $res );
				}
				$code = (int) wp_remote_retrieve_response_code( $res );
				$body = json_decode( wp_remote_retrieve_body( $res ), true );
				if ( isset( $body['content'][0]['text'] ) ) {
					return trim( $body['content'][0]['text'] );
				}
				return self::api_error( $code, $body, __( 'Resposta inesperada da Anthropic.', 'cluster-engine' ) );
		}
	}
}
