=== Cluster Engine — Autoridade Tópica & Linkagem Interna ===
Contributors: 61labs
Tags: seo, internal links, content clusters, topical authority, aeo, geo, eeat, ai
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.27.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Motor de autoridade tópica para WordPress: clusters de conteúdo, mapa de palavras-chave, diagnóstico SEO/AEO/GEO/E-E-A-T e linkagem interna inteligente. Por 61 Labs (61labs.com.br).

== Description ==

O Cluster Engine lê todo o conteúdo publicado do seu site e o transforma em um grafo semântico:

* **Clusters emergentes** — os grupos temáticos são detectados a partir do próprio conteúdo (TF-IDF + similaridade de cosseno), com pilar identificado automaticamente e Score de Força do Cluster (0–100) composto por Cobertura, Arquitetura de links, AEO/GEO, E-E-A-T e Higiene.
* **Linkagem interna inteligente** — oportunidades de link (alta afinidade semântica sem link), links que não fazem sentido (linkados sem relação) e canibalização (posts quase idênticos competindo pela mesma intenção).
* **Mapa de palavras-chave** — termos e entidades extraídos do site inteiro, com detecção de meta titles e keywords foco duplicados.
* **Integração com plugins de SEO** — lê e grava direto nos campos do Yoast SEO, Rank Math, All in One SEO e SEOPress (meta title, description e keyword foco). Sem plugin de SEO, usa campos nativos próprios.
* **Central de diagnóstico** — problemas priorizados por impacto (críticos, on-page, oportunidades AEO/GEO), com botão "Corrigir com IA".
* **Hub de IA multi-provedor** — Anthropic (Claude), OpenAI (GPT) e Google (Gemini), com biblioteca de prompts editável por ação e variáveis dinâmicas como {{title}}, {{keyword}}, {{site_name}}, {{cluster_name}}, resolvidas em tempo real com dados do WordPress.
* **Geração de conteúdo para gaps** — gera rascunhos de artigos satélite direto no WordPress (nunca publica sozinho).
* **Criação de novos clusters com IA** — a IA analisa o conteúdo já publicado, entende o assunto do site e sugere novos clusters coerentes; você também cria clusters manualmente.
* **Planejamento e geração de conteúdo por cluster** — plano de artigos (pilar + satélites) por cluster, com opção de incrementar o plano, gerar um artigo na hora ou enviar vários para a fila.
* **Geração em massa com fila (WP-Cron)** — os artigos enfileirados são gerados em segundo plano pelo cron nativo do WordPress, com status, novas tentativas e processamento manual.
* **Páginas separadas no admin** — Painel (análise), Criação de Conteúdo (clusters novos + fila) e Configurações (IA, prompts e limiares).

Critérios alinhados ao SEO de 2026: answer capsules, headings em formato de pergunta, FAQ, fontes externas, bio de autor (E-E-A-T), conteúdo não-commodity e prontidão para AI Overviews.

== Installation ==

1. Envie a pasta `cluster-engine-61labs` para `/wp-content/plugins/` ou instale o .zip em Plugins → Adicionar novo → Enviar plugin.
2. Ative o plugin.
3. Acesse o menu **Cluster Engine** e clique em **Escanear site**.
4. (Opcional) Em Configurações, adicione sua chave de API de IA para habilitar reescritas e geração de conteúdo.

== Frequently Asked Questions ==

= O plugin publica ou altera conteúdo sozinho? =
Não. Toda ação de IA passa por aprovação: metas são gravadas apenas quando você escolhe uma opção, e artigos gerados entram como rascunho.

= Preciso de chave de API? =
A análise de clusters, keywords, diagnóstico e linkagem funciona 100% sem IA externa. A chave só é necessária para reescritas e geração de conteúdo.

= Funciona com qual plugin de SEO? =
Yoast SEO, Rank Math, All in One SEO e SEOPress são detectados automaticamente. Sem nenhum deles, o Cluster Engine usa campos próprios e os entrega no front-end.

== Changelog ==

= 2.27.0 =
* Novo: **página "Imagens" dedicada** que centraliza tudo — geração de destacadas e imagens no conteúdo, conversão WebP, presets, configurações e log de erros — em sub-abas próprias. A aba Imagens saiu do Painel e as configurações de imagem saíram das Configurações gerais.
* Novo: **imagens dentro do post (in-content)** — gere 1 a 3 imagens otimizadas em WebP com SEO completo (nome de arquivo, alt, título, legenda e descrição) e insira no corpo do artigo na posição que você escolher (início, após o 1º H2 ou no final).
* Novo: **conversão para WebP** das imagens dos artigos (destacadas e anexos) mantendo sempre o original — operação reversível — com repontamento automático de imagem destacada e URLs no conteúdo. Conversão avulsa ou em massa pela Fila.
* Novo: **imagem de referência** na geração (image-to-image) via Biblioteca de Mídia, upload por arraste ou seleção de arquivo — suportada em OpenAI (edits) e Gemini (inlineData).
* Novo: **prompt de otimização por geração** para sobrescrever o prompt mestre quando quiser.
* Novo: **biblioteca de presets** de imagem, alimentada por você ao gerar, para manter a mesma linha editorial e reaplicar modelos com um clique.
* Novo: **otimização WebP configurável** (ligar/desligar e qualidade 40–100) aplicada às imagens geradas e enviadas pelo plugin.

= 2.26.0 =
* Novo: **filtro por status** na página de Schema — "Somente com pendências" (padrão), "Todas as páginas" ou "Somente completas", para focar direto nas páginas que precisam de correção.
* Melhoria: após corrigir o schema de uma página (Article, FAQ, reparo ou remoção), a página é re-auditada e sai automaticamente da lista de pendências — o status é atualizado na hora.
* Melhoria: contador dinâmico mostrando quantas páginas correspondem ao filtro/busca atuais.

= 2.25.0 =
* Novo: a página de Schema agora mostra o **slug** de cada página (com link para abrir a URL).
* Novo: campo de **busca** na página de Schema, filtrando por título, slug e URL.
* Novo: **botões de ordenação** no cabeçalho da tabela (Página, Slug, Schema encontrado, Pendências) — clique para alternar entre crescente e decrescente.

= 2.24.0 =
* Correção importante: sem Rank Math, o schema JSON-LD deixava de ser injetado como texto no corpo do post. Agora ele é gravado em um **campo personalizado do Cluster Engine** (`_ce61_schema`) e impresso como `<script type="application/ld+json">` no `<head>` da página — nunca mais aparece como código visível no conteúdo.
* Correção da causa raiz: para usuários sem a permissão `unfiltered_html`, o `wp_kses_post` removia a tag `<script>` e deixava o JSON exposto como texto. Toda inserção de schema (Article, FAQ) agora passa pelo campo, então isso não acontece mais.
* Novo: mecanismo de **reparo de schema exposto**. A auditoria detecta páginas com JSON-LD exposto (como texto puro ou blocos `<script>` no conteúdo) e um botão "⚠ Corrigir schema exposto" move o schema para o campo e limpa o corpo do post. Disponível por página e em massa (via Fila de Geração).

= 2.23.1 =
* Melhoria: o campo de busca do painel de Desempenho agora filtra também por slug e URL (permalink), além de título e keyword.

= 2.23.0 =
* Correção: páginas excluídas (ou na lixeira) não são mais exibidas na tabela de Desempenho nem na Fila de geração — os registros antigos deixam de aparecer automaticamente.
* Novo: na Fila de geração, os artigos prontos agora mostram o título atual do post, a URL para visualizar e o slug.
* Melhoria: o status de publicação do artigo (Rascunho/Publicado/Agendado) é lido ao vivo na Fila — após publicar/agendar, o status é atualizado ao recarregar a lista.

= 2.22.0 =
* Novo: em "Melhorias sugeridas" (modal de IA), agora dá para marcar várias pendências (ou "Selecionar todas") e clicar em "Corrigir selecionadas" para aplicar as correções automáticas em fila, uma a uma, em massa. O andamento é exibido ("Corrigindo 2 de 5…") e a coluna de diagnóstico se atualiza ao final com o resumo.
* Melhoria: cada pendência com correção automática ganhou uma caixa de seleção ao lado do botão "Corrigir" individual.

= 2.21.0 =
* Novo: a modal "Melhorar com IA" agora traz o meta título e a meta descrição, cada um com "Gerar com IA" (mostra opções para escolher) e "Salvar" — de forma individualizada, sem precisar abrir o painel de configuração.
* Novo: as pendências em "Melhorias sugeridas" ganharam um botão "Corrigir" individual quando há correção automática (FAQ/schema, cápsula de resposta, meta descrição e imagem destacada); as demais indicam ajuste manual no editor.
* Melhoria: a coluna de diagnóstico se atualiza na hora após salvar um metadado ou corrigir uma pendência (notas e lista de melhorias recalculadas).

= 2.20.0 =
* Novo: na coluna "Status índice" da tabela de Desempenho, o "não checado" agora é um botão — passe o mouse e ele fica verde ("Verificar indexação"); ao clicar, consulta o estado real no Google (URL Inspection API) na hora, sem precisar rodar o lote.
* Novo: quando a página volta como "Não indexado", aparece o botão "Solicitar indexação" ao lado, que notifica o Google (Indexing API) para aquela URL específica. Também há um ↻ para reverificar qualquer página.
* Nota: solicitar indexação apenas avisa o Google — a indexação em si não é garantida nem imediata (a Indexing API é oficialmente para páginas JobPosting/BroadcastEvent).

= 2.19.0 =
* Novo: ícones de ordenação clicáveis no cabeçalho da tabela de desempenho (?page=cluster-engine-performance). Clique em qualquer coluna — Página, Posição Google, Status índice, Cliques, Impressões, CTR, Posição média, Sessões (GA4) — para ordenar; um novo clique inverte a direção. A seta ▲/▼ indica a coluna e o sentido ativos, sincronizados com o seletor de ordenação existente.
* Novo: colunas Impressões, CTR e Posição média agora também são ordenáveis.

= 2.18.0 =
* Novo: botão "Ajustar melhoria" na tela de aprovação do rascunho. Em vez de recomeçar do conteúdo original, a IA refina o próprio rascunho já gerado a partir de um novo pedido (ex.: "encurte a conclusão", "transforme o 3º parágrafo em lista"). Dá para ajustar quantas vezes quiser antes de aprovar.
* Novo: campo "Quantos H2 incluir?" na geração e no ajuste — você define o número desejado de subtítulos H2 e a IA estrutura o conteúdo com essa quantidade.

= 2.17.0 =
* Novo: a modal "Melhorar com IA" do editor agora tem 2 colunas. À esquerda, o diagnóstico do próprio Cluster Engine — notas E-E-A-T/AEO/GEO, o scan SEO/AEO do Painel, o desempenho dos últimos 90 dias (cliques, impressões, posição GSC/Google, sessões GA4, CTR, com variação) e a lista de melhorias sugeridas. À direita, o campo de prompt.
* Novo: dois botões de geração — "Gerar melhorias com base no diagnóstico" (a IA corrige as pendências detectadas) e "Gerar com diagnóstico + prompt" (a IA cruza o diagnóstico com o seu pedido).
* Novo: fluxo de aprovação. A IA gera um rascunho mostrando a nota atual; você revisa e só então aprova. Ao aprovar, dá para atualizar salvando ou "Aprovar e publicar" (atualiza + publica).
* Novo: histórico de alterações por URL (changelog). Cada atualização feita pela IA guarda a versão anterior; a modal lista o histórico da URL e um botão "Reverter" para voltar o conteúdo a qualquer versão registrada (o estado atual fica salvo antes de reverter).

= 2.16.0 =
* Novo: com o Rank Math ativo, o schema (Article/BlogPosting e FAQ) passa a ser gravado no campo de schema do Rank Math (postmeta rank_math_schema_*) em vez de ser injetado como <script> JSON-LD no conteúdo. Vale ao inserir Article, gerar FAQ, na correção "Ver ajustes" e na fila em massa. Reexecutar atualiza o mesmo schema em vez de duplicar.
* Novo: botão "Remover schema" na Auditoria de Schema — apaga o schema gerado pelo Cluster Engine (campo do Rank Math criado pelo plugin) e retira do conteúdo os blocos JSON-LD.
* Ao migrar para o campo do Rank Math, os blocos JSON-LD equivalentes que estavam no corpo do post são removidos, para o schema não aparecer mais como texto no conteúdo.

= 2.15.0 =
* Novo: extensão do Cluster Engine no editor. Um ícone de IA na barra superior do admin abre um modal onde você descreve o que precisa ser atualizado — a IA reescreve e melhora o conteúdo do post/página mantendo o assunto e a base de configuração do site, usando o conhecimento que já tem.
* Novo: meta box "Cluster Engine — Conteúdo com IA" abaixo do bloco Publicar, com o mesmo botão de melhorar conteúdo com IA sem sair do editor.
* Novo: botão "Gerar imagem com IA" dentro da caixa de imagem destacada, que gera e define a imagem destacada com o hub de imagens do plugin.

= 2.14.0 =
* Correção: a propriedade do Search Console do tipo Domínio (`sc-domain:seusite.com.br`) não era salva em Integrações — o `esc_url_raw()` apagava o valor por não ser uma URL http(s). Agora o prefixo `sc-domain:` é preservado e a conexão com o Search Console se mantém após selecionar a propriedade.

= 2.13.0 =
* Novo: coluna "Status índice" na página de Desempenho — mostra se cada URL está Indexada, Não indexada ou Desconhecida no Google, via URL Inspection API do Search Console, com botão "Checar indexação (lote)".
* Novo: seleção em massa + botão "Solicitar indexação" que envia as URLs marcadas para a Indexing API do Google. Requer reconectar o Google (novo escopo de indexação) e ativar a Indexing API no Google Cloud; o Google só garante suporte oficial a páginas com schema JobPosting/BroadcastEvent.
* Novo: ordenação "Índice — não indexados primeiro" na página de Desempenho.

= 2.12.0 =
* Novo: em "Ver ajustes" (Criação de Conteúdo), cada ajuste de E-E-A-T/AEO/GEO com correção automática ganha um botão "Corrigir" — gera FAQ, parágrafo de abertura (answer capsule), meta description ou imagem destacada com IA, direto no post, e recalcula as notas na hora.

= 2.11.0 =
* Novo: seleção em massa na Auditoria de Schema — marque páginas individualmente ou "Selecionar tudo", escolha a correção (Inserir Article ou Gerar FAQ + schema) e envie todas de uma vez para a Fila de Geração, processadas em segundo plano pelo WP-Cron.
* Páginas selecionadas que não precisam da correção escolhida são ignoradas automaticamente.

= 2.10.0 =
* Novo: após gerar ou criar um conteúdo, aparece um botão "Publicar/Agendar" para publicar o que foi criado sem precisar abrir o editor — no formulário de artigo, na geração de CPT e no artigo satélite (gap).

= 2.9.0 =
* Novo: a Auditoria de Schema agora deixa você escolher quais tipos de conteúdo (CPTs) auditar antes de rodar a fila — audita só o necessário, economizando tempo e chamadas.
* Novo: opção "Pular páginas já auditadas nos últimos 7 dias" para auditorias incrementais mais rápidas.
* Melhorado: a fila de auditoria é congelada no início do scan (paginação estável), evitando reprocessos ao aplicar filtros.

= 2.8.0 =
* Novo: página "Desempenho das URLs" com ordenação inteligente por padrão — primeiro as páginas com posição no Google (melhor primeiro), depois as com mais impressões, e por fim as demais em ordem alfabética.
* Novo: cada página exibe o slug da URL abaixo do título, como link clicável; a busca também considera o slug.

= 2.7.0 =
* Novo: na aba "Linkagem interna", cada post exibe o slug da URL abaixo do título, como link clicável que abre o post em nova aba.
* Melhorado: a barra de similaridade agora usa cor em gradiente — quanto mais próxima de 100, mais vermelha; quanto mais próxima de 0, mais verde.

= 2.5.2 =
* Melhorado: zoom da Rede de Palavras-chave mais fluido — botões na tela (aproximar, afastar e "ajustar à tela") e zoom proporcional/suave no wheel e trackpad.
* Corrigido: o scroll do mouse não sequestra mais a rolagem da página sobre o grafo; o zoom por scroll agora exige Ctrl/⌘ segurado.

= 2.5.0 =
* Novo: planejamento de cluster com briefing editorial — ao clicar em "Planejar conteúdo (IA)", escolha o número de artigos e a profundidade (~800/1200/2000/3000 palavras, com H2 correspondentes); a IA monta, para cada artigo, um briefing com FAQs e subtemas sugeridos (baseado no conhecimento do modelo — não é busca ao vivo no Google, deixado claro na interface).
* Novo: botão "📋 Briefing" em cada tópico planejado, mostrando as FAQs e subtemas sugeridos antes de gerar o artigo.
* Melhorado: a geração de artigo a partir de um tópico planejado agora segue o briefing (extensão alvo, quantidade de H2, subtemas e FAQs a cobrir), em vez de um prompt genérico.
* Novo: toda imagem destacada criada pelo plugin (IA, banco de imagens, fila) agora preenche todos os campos de SEO de imagem — nome de arquivo baseado na keyword (não mais um nome genérico), Alt text, Título do anexo, Legenda e Descrição — em vez de só o Alt text.

= 2.4.0 =
* CORRIGIDO (crítico): a OpenAI desligou DALL-E 2/3 da API em 12/05/2026 — o plugin usava "dall-e-3" como padrão, que já não funciona. Modelo padrão trocado para a família GPT Image (gpt-image-1/1-mini/1.5/2); qualquer configuração antiga com dall-e-2/3 agora cai automaticamente para gpt-image-1.
* Novo: seletor de modelo por provedor direto do catálogo oficial (sem digitar nome de modelo) — OpenAI: GPT Image 1, 1 Mini, 1.5 e 2; Google: Imagen 4 (padrão/Fast/Ultra) e os novos modelos nativos "Nano Banana" (gemini-2.5-flash-image, gemini-3.1-flash-image-preview), já preparado para a desativação do Imagen anunciada pelo Google para 17/08/2026.
* Novo: watermark em imagem — escolha um logo da Biblioteca de Mídia do WordPress (seletor nativo) ou cole uma URL, além do modo texto que já existia. Sobreposto automaticamente no canto inferior direito, redimensionado, preservando transparência.
* Novo: presets de estilo em checkbox (Ultra realista, Ilustração editorial, Minimalista, 3D render, Cinematográfico, Preto e branco, Sem pessoas/rostos) e seletor de proporção (Quadrado, Paisagem, Retrato, Widescreen, Vertical) — aplicados automaticamente no prompt, com mapeamento correto para o parâmetro nativo de cada provedor (size na OpenAI, aspectRatio no Google). Disponíveis tanto como padrão nas Configurações quanto como ajuste pontual na hora de gerar cada imagem.

= 2.3.0 =
* Novo: conexão com bancos de imagens gratuitos — Unsplash, Pexels, Pixabay e Openverse — como fonte de imagem destacada alternativa/preferida à geração por IA, com busca, grade de resultados e crédito automático na legenda (obrigatório para a maioria das licenças do Openverse, recomendado para Unsplash).
* Novo: controle real de cota por provedor (janela deslizante), com medidor visual em Configurações → Integrações mostrando uso atual, limite e quando libera — nunca estoura o limite grátis de nenhuma API.
* Novo: modo "Automático" — tenta os bancos de imagens na ordem de prioridade configurada e só recorre à IA se nenhum tiver resultado ou cota disponível.
* Novo: checkbox em cada imagem da aba Imagens + "Gerar em fila" em lote — enfileira a geração (banco de imagens, IA ou automático) de vários posts de uma vez, processada em segundo plano pela Fila de Geração.
* Melhorado: modal de imagem agora tem duas abas — Banco de imagens (padrão) e Gerar com IA — no mesmo lugar.

= 2.2.0 =
* Novo: botão "💾 Salvar este insight" no insight de IA da página Desempenho — grava o texto junto com uma foto das métricas do momento (cliques, posição, sessões), para comparar depois se a ação sugerida realmente mudou o desempenho.
* Novo: "🗒 Ver histórico salvo" — lista todas as notas de insight já salvas para aquele post, cada uma com as métricas de quando foi salva ao lado das métricas de agora, e opção de excluir.

= 2.1.1 =
* Corrigido: número e rótulo do anel de Autoridade Tópica estouravam a largura do círculo quando o rótulo ficou mais longo ("· 0–100"), ficando visualmente fora dele. Texto agora quebra em duas linhas e fica contido com folga.

= 2.1.0 =
* Novo: coleta diária automática de desempenho (tabela wp_ce_perf_history, 1 linha por post por dia) — Search Console, GA4 e um lote rotativo de checagens de posição no Google, no horário que você definir em Configurações → Integrações → Automação. Orçamento diário de SERP configurável para proteger a cota grátis das APIs.
* Novo: gráfico de evolução por URL (ícone 📈 em cada linha) com seletor de métrica (cliques, impressões, posição GSC, posição Google, sessões GA4) e períodos de 3, 7, 15, 30, 90 e 120 dias, com marcadores verticais nas datas em que o conteúdo foi atualizado — para comparar visualmente antes/depois de uma edição.
* Novo: flag de atualização de conteúdo — toda edição de um post publicado é registrada com a data, alimentando os marcadores do gráfico.
* Novo: insight de IA por URL (ícone ✦) — cruza o histórico de desempenho, as datas de atualização e o diagnóstico SEO/AEO/GEO/E-E-A-T do post para apontar o que está funcionando, o que piorou e as próximas ações concretas.
* Novo: busca ao vivo (sem botão de enviar) e ordenação (título A-Z/Z-A, cliques, sessões GA4, posição no Google, cada uma em ordem crescente ou decrescente) no cabeçalho da página Desempenho.

= 2.0.0 =
* Novo: página "Rede de Palavras-chave" — visualização em canvas, estilo rede neural, de todos os artigos e a linkagem interna real entre eles. Tamanho do nó = links recebidos; cor = cluster; anel brilhante = pilar. Passe o mouse para ver o título; clique para abrir Editar/Visualizar; arraste os nós; zoom com a roda do mouse; busque por título ou keyword.
* Novo: três notas independentes por artigo — E-E-A-T (Experiência/Expertise/Autoridade/Confiança), AEO (prontidão para respostas diretas) e GEO (prontidão para ser citado por IA generativa) — cada uma com a lista exata do que ajustar, calculadas a partir de sinais distintos do conteúdo (não são a mesma nota repetida três vezes).
* Novo: botão "Publicar/Agendar" e edição rápida de palavra-chave foco direto nos resultados da geração avulsa, na fila de geração (após conclusão) e na lista de artigos gerados por IA — sem precisar abrir o editor do WordPress.
* Atualizado: prompt padrão de geração de artigo agora exige menção natural ao nome do site e um link interno real para o pilar do cluster, usando a keyword do pilar como texto-âncora.
* Novo: botão "↺ Restaurar padrão" em cada prompt da biblioteca — restaura o texto de fábrica daquela ação específica sem afetar os demais prompts personalizados (útil quando o plugin melhora um prompt padrão e você já tinha salvo a versão antiga).

= 1.9.0 =
* Novo: card "Gerar artigo avulso" na página Criação de Conteúdo — título, palavra-chave foco, cluster opcional e um campo de prompt livre para instruções extras à IA.
* Novo: botão "✍ Melhorar prompt com IA" — reescreve suas instruções para pedir exemplos concretos, dados, estrutura e fontes externas, mantendo o assunto original.
* Novo: seleção de publicação em cada geração — salvar como rascunho, publicar imediatamente ou agendar data/hora (usa o WordPress nativamente, sem cron extra).
* Novo: nota de E-E-A-T (Experiência, Expertise, Autoridade, Confiança) calculada automaticamente em todo artigo gerado, com a lista exata do que ajustar para melhorar a nota (autor sem bio, sem fontes externas, sem dados concretos, sem FAQ, sem imagem destacada, sem subtítulos, abertura fora do formato de resposta direta).
* Novo: seção "Artigos gerados por IA" — lista todo post criado pelo plugin com status, nota de E-E-A-T e ações rápidas para publicar, agendar ou ver o que precisa ajustar.

= 1.8.0 =
* Novo: botão "↗ Abrir Google Cloud Console" e "📘 Ver passo a passo do credenciamento" em Configurações → Integrações — um modal guia as 6 etapas para criar o OAuth Client (projeto, ativar as 3 APIs necessárias, tela de consentimento, criar o Client ID com a Redirect URI certa, copiar as credenciais, conectar), com o link certo do Google Cloud Console em cada etapa.
* Novo: links diretos de cadastro (Serper.dev, SerpApi, ValueSERP) ao lado de cada campo de chave de API de SERP.

= 1.7.0 =
* Novo: botão "Listar minhas propriedades GA4" — escolha a propriedade certa numa lista (conta + nome), sem precisar descobrir e colar o ID manualmente.
* Novo: botões "⚡ Testar Search Console" e "⚡ Testar GA4" — validam a conexão na hora com a propriedade salva e mostram o erro exato devolvido pela API do Google quando algo falha, em vez de uma falha silenciosa.
* Corrigido: erros de permissão do Search Console (conta sem acesso à propriedade) não são mais mascarados como "nenhuma propriedade encontrada" — agora aparecem como erro real, com a mensagem da API.
* Corrigido: o ID de propriedade do GA4 agora é normalizado ao salvar (remove prefixo "properties/", espaços e caracteres não numéricos que o usuário cole por engano, como a Measurement ID "G-XXXXXXX").

= 1.6.1 =
* Corrigido: link "Conectar com o Google" expirava imediatamente ("Este link expirou") — o nonce estava sendo escapado duas vezes (uma vez pelo wp_nonce_url do WordPress, outra pelo JavaScript), corrompendo o parâmetro _wpnonce antes de chegar ao servidor.

= 1.6.0 =
* Corrigido: erro "Unknown parameter: 'response_format'" ao gerar/recriar imagem destacada com modelos como gpt-image-1 — o parâmetro agora só é enviado para dall-e-2/dall-e-3, com blindagem automática (retry) caso a API rejeite qualquer parâmetro no futuro, e o tamanho da imagem é normalizado por família de modelo.
* Novo: página "Desempenho" — posição no Google (via API de SERP), cliques, impressões, CTR e posição média (Google Search Console) e sessões (GA4) por página, tudo cruzado numa única tabela, com atualização em lote e reconsulta individual.
* Novo: integração com APIs de SERP — Serper.dev (padrão, 2.500 buscas grátis no cadastro), SerpApi e ValueSERP, com saída unificada independente do provedor escolhido.
* Novo: integração OAuth com Google Search Console e Google Analytics 4 (GA4) em Configurações → Integrações, incluindo listagem automática das propriedades do Search Console.
* Corrigido: salvar parcialmente uma aba de Configurações (ex.: só Integrações) não apaga mais as demais configurações (IA, imagens, limiares) — cada campo agora preserva o valor salvo quando não enviado.

= 1.5.0 =
* Novo: página "Criação de Conteúdo" — criação manual de clusters e sugestão de novos clusters com IA, com base no conteúdo real do site (clusters, keywords e títulos já publicados).
* Novo: planejamento de conteúdo por cluster (pilar + satélites) com IA, com opção de incrementar o plano com mais tópicos.
* Novo: geração em massa com fila de execução no WP-Cron (tabela wp_ce_queue, evento a cada minuto, novas tentativas automáticas, botão "Processar próximo agora" para hospedagens com cron instável).
* Novo: página "Configurações" separada (Configurações + IA & Prompts) e menu com submenus.
* Correção crítica: salvar as configurações não apaga mais as chaves de API salvas (a causa das conexões de IA pararem de funcionar); agora a chave só muda quando você digita uma nova ou pede a remoção explícita.
* Melhoria: mensagens de erro de conexão com IA agora incluem o código HTTP e orientação (chave inválida, créditos esgotados, modelo inexistente, timeout de rede).
* Melhoria: clusters criados manualmente são preservados no rescan do site.

= 1.0.0 =
* Lançamento inicial: indexação semântica, clusters, score de força, linkagem interna, mapa de keywords, diagnóstico SEO/AEO/GEO/E-E-A-T, hub de IA com prompts e variáveis, integração com plugins de SEO.

== Credits ==

Desenvolvido por 61 Labs — https://61labs.com.br
