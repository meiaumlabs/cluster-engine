/* Cluster Engine — 61 Labs · 61labs.com.br */
(function () {
	'use strict';

	var $ = function (s, ctx) { return (ctx || document).querySelector(s); };
	var $$ = function (s, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(s)); };

	var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	function esc(str) {
		return String(str == null ? '' : str)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}

	function api(action, data, opts) {
		var body = new FormData();
		body.append('action', 'ce61_' + action);
		body.append('nonce', CE61.nonce);
		Object.keys(data || {}).forEach(function (k) {
			var v = data[k];
			if (v && typeof v === 'object') {
				Object.keys(v).forEach(function (kk) {
					if (Array.isArray(v[kk])) {
						v[kk].forEach(function (item) { body.append(k + '[' + kk + '][]', item); });
					} else {
						body.append(k + '[' + kk + ']', v[kk]);
					}
				});
			} else {
				body.append(k, v);
			}
		});
		opts = opts || {};
		var timeoutMs = opts.timeout != null ? opts.timeout : 70000;
		var controller = new AbortController();
		var signal = controller.signal;
		if (opts.signal) {
			if (opts.signal.aborted) { controller.abort(); }
			else { opts.signal.addEventListener('abort', function () { controller.abort(); }); }
		}
		var timer = timeoutMs ? setTimeout(function () { controller.abort(); }, timeoutMs) : null;
		return fetch(CE61.ajax, { method: 'POST', credentials: 'same-origin', body: body, signal: signal })
			.then(function (r) { if (timer) { clearTimeout(timer); } return r.json(); })
			.then(function (j) {
				if (!j || !j.success) {
					throw new Error(j && j.data && j.data.message ? j.data.message : 'Falha na requisição.');
				}
				return j.data;
			})
			.catch(function (err) {
				if (timer) { clearTimeout(timer); }
				if (err && err.name === 'AbortError') {
					throw new Error('Requisição cancelada ou tempo limite excedido.');
				}
				throw err;
			});
	}

	function toast(msg, isError) {
		var el = $('#ce-toast');
		el.textContent = msg;
		el.classList.toggle('is-error', !!isError);
		el.hidden = false;
		clearTimeout(el._t);
		el._t = setTimeout(function () { el.hidden = true; }, 3400);
	}

	var _activeController = null;

	function modal(html) {
		$('#ce-modal-body').innerHTML = html;
		$('#ce-modal').hidden = false;
	}
	function closeModal() {
		if (_activeController) { _activeController.abort(); _activeController = null; }
		$('#ce-modal').hidden = true;
	}
	$('#ce-modal-close').addEventListener('click', closeModal);
	$('#ce-modal').addEventListener('click', function (e) { if (e.target === this) { closeModal(); } });
	document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeModal(); } });

	function countUp(el, target) {
		target = parseInt(target, 10) || 0;
		if (reduced) { el.textContent = target.toLocaleString('pt-BR'); return; }
		var start = null, dur = 800;
		function step(ts) {
			if (!start) { start = ts; }
			var p = Math.min(1, (ts - start) / dur);
			p = 1 - Math.pow(1 - p, 3);
			el.textContent = Math.round(target * p).toLocaleString('pt-BR');
			if (p < 1) { requestAnimationFrame(step); }
		}
		requestAnimationFrame(step);
	}

	var tabPage = {
		dashboard: 'main', clusters: 'main', links: 'main', keywords: 'main',
		diagnostics: 'main', schema: 'main', images: 'main',
		creator: 'creator', queue: 'creator',
		settings: 'settings', ai: 'settings', integrations: 'settings',
		performance: 'performance', network: 'network'
	};

	function gotoTab(name) {
		var base = name.split('#')[0];
		var tab = $('.ce-tab[data-tab="' + base + '"]');
		if (tab) { tab.click(); return; }
		// A aba está em outra página do plugin: navega até ela.
		var page = tabPage[base];
		if (page && CE61.pages && CE61.pages[page]) {
			window.location.href = CE61.pages[page] + '&ce-tab=' + encodeURIComponent(base);
		}
	}

	/* Preflight: is there an API key for any provider? (server falls back automatically) */
	function requireKey() {
		var s = CE61.settings;
		var any = s.has_key && (s.has_key.anthropic || s.has_key.openai || s.has_key.gemini);
		if (any) { return true; }
		modal(
			'<h3 class="ce-h2">Falta configurar uma chave de IA</h3>' +
			'<p class="ce-sub">As ações de reescrita e geração precisam de uma chave de API de pelo menos um provedor (Anthropic, OpenAI ou Gemini). A análise de clusters e links funciona sem IA; só a geração de texto precisa da chave.</p>' +
			'<p><button class="ce-btn ce-btn-primary" id="ce-goto-settings">Ir para Configurações</button></p>'
		);
		$('#ce-goto-settings').addEventListener('click', function () {
			closeModal();
			gotoTab('settings');
		});
		return false;
	}

	function scoreBar(v, invert) {
		v = Math.max(0, Math.min(100, parseInt(v, 10) || 0));
		var cls = v >= 75 ? 'is-good' : (v >= 45 ? 'is-warn' : 'is-bad');
		if (invert) { cls = v <= 25 ? 'is-good' : (v <= 55 ? 'is-warn' : 'is-bad'); }
		return '<span class="ce-score" title="Escala de 0 a 100"><span class="ce-score-track"><span class="ce-score-fill ' + cls + '" data-w="' + v + '"></span></span><b>' + v + '<span class="ce-score-max">/100</span></b></span>';
	}

	/* Auto-animate every score bar that enters the DOM (panels, modals, drawers). */
	function animateBars(root) {
		$$('.ce-score-fill[data-w]', root || document).forEach(function (f) {
			if (f._done) { return; }
			f._done = true;
			var w = f.dataset.w + '%';
			if (reduced) { f.style.width = w; return; }
			requestAnimationFrame(function () {
				requestAnimationFrame(function () { f.style.width = w; });
			});
		});
	}
	new MutationObserver(function () { animateBars(); })
		.observe(document.documentElement, { childList: true, subtree: true });

	/* ---------- Tabs ---------- */
	var loaded = {};
	$$('.ce-tab').forEach(function (tab) {
		tab.addEventListener('click', function () {
			var leaving = $('.ce-tab.is-active');
			if (leaving && leaving.dataset.tab === 'network' && tab.dataset.tab !== 'network' && netState) {
				netState.stop(); netState = null;
			}
			$$('.ce-tab').forEach(function (t) { t.classList.remove('is-active'); });
			$$('.ce-panel').forEach(function (p) { p.classList.remove('is-active'); });
			tab.classList.add('is-active');
			var name = tab.dataset.tab;
			$('[data-panel="' + name + '"]').classList.add('is-active');
			loadPanel(name);
		});
	});

	function loadPanel(name, force) {
		if (loaded[name] && !force) { return; }
		loaded[name] = true;
		({
			dashboard: renderDashboard,
			clusters: renderClusters,
			links: renderLinks,
			keywords: renderKeywords,
			diagnostics: renderDiagnostics,
			schema: renderSchema,
			images: renderImages,
			ai: renderAI,
			settings: renderSettings,
			integrations: renderIntegrations,
			creator: renderCreator,
			queue: renderQueue,
			performance: renderPerformance,
			network: renderNetwork,
			cpt_manage: renderCptManage,
			cpt_content: renderCptContent
		}[name] || function () {})();
	}

	function refreshAll() {
		loaded = {};
		loadPanel($('.ce-tab.is-active').dataset.tab, true);
	}

	/* ---------- Scan pipeline (só existe na página Painel) ---------- */
	if ($('#ce-scan-btn')) { $('#ce-scan-btn').addEventListener('click', runScan); }

	function runScan() {
		var btn = $('#ce-scan-btn'), bar = $('#ce-scanbar'), fill = $('#ce-scan-fill'), msg = $('#ce-scan-msg');
		btn.disabled = true;
		bar.hidden = false;

		function phase(action, label, from, span) {
			return new Promise(function (resolve, reject) {
				(function step(offset) {
					api(action, { offset: offset }).then(function (d) {
						var pct = d.total ? d.done / d.total : 1;
						fill.style.width = (from + pct * span) + '%';
						msg.textContent = label + ' ' + d.done + '/' + d.total;
						if (d.done < d.total) { step(d.done); } else { resolve(); }
					}).catch(reject);
				})(0);
			});
		}

		phase('scan_index', 'Indexando conteúdo', 0, 40)
			.then(function () { return phase('scan_relations', 'Calculando relações semânticas', 40, 40); })
			.then(function () {
				msg.textContent = 'Formando clusters…';
				fill.style.width = '88%';
				return api('scan_cluster', {});
			})
			.then(function () {
				msg.textContent = 'Calculando scores e diagnósticos…';
				fill.style.width = '96%';
				return api('scan_finalize', {});
			})
			.then(function () {
				fill.style.width = '100%';
				msg.textContent = 'Análise concluída.';
				toast('Site escaneado com sucesso');
				setTimeout(function () { bar.hidden = true; btn.disabled = false; refreshAll(); }, 900);
			})
			.catch(function (e) {
				toast(e.message, true);
				btn.disabled = false;
				bar.hidden = true;
			});
	}

	/* ---------- Dashboard ---------- */
	function renderDashboard() {
		var el = $('#ce-panel-dashboard');
		el.innerHTML = '<div class="ce-loading">Carregando painel</div>';
		api('dashboard', {}).then(function (d) {
			if ($('#ce-seo-plugin')) { $('#ce-seo-plugin').textContent = d.seo_plugin; }
			if (!d.posts) {
				el.innerHTML =
					'<div class="ce-empty">' +
					'<span class="ce-orbit-mark" aria-hidden="true"><i></i><i></i><i></i></span>' +
					'<h3>Seu grafo de conteúdo ainda não existe</h3>' +
					'<p>Clique em Escanear site para indexar os posts, mapear palavras-chave, formar os clusters e diagnosticar a linkagem interna.</p>' +
					'<button class="ce-btn ce-btn-primary" onclick="document.getElementById(\'ce-scan-btn\').click()">Escanear site agora</button>' +
					'</div>';
				return;
			}
			var r = 88, circ = 2 * Math.PI * r;
			var off = circ * (1 - d.site_score / 100);
			el.innerHTML =
				'<div class="ce-hero">' +
					'<div class="ce-ring-wrap">' +
						'<svg width="200" height="200" viewBox="0 0 200 200">' +
							'<defs><linearGradient id="ceGrad" x1="0" y1="0" x2="1" y2="1">' +
							'<stop offset="0%" stop-color="#2547F4"/><stop offset="100%" stop-color="#7C3AED"/></linearGradient></defs>' +
							'<circle class="ce-ring-bg" cx="100" cy="100" r="' + r + '" fill="none" stroke-width="12"/>' +
							'<circle class="ce-ring-val" cx="100" cy="100" r="' + r + '" fill="none" stroke-width="12" stroke-dasharray="' + circ + '" stroke-dashoffset="' + circ + '"/>' +
						'</svg>' +
						'<div class="ce-ring-center"><strong data-count="' + d.site_score + '">0</strong><span>Autoridade<br>tópica · 0–100</span></div>' +
					'</div>' +
					'<div>' +
						'<h2>Saúde da arquitetura de conteúdo</h2>' +
						'<p>Média da força dos seus clusters, combinando cobertura tópica, arquitetura de links, prontidão para IA (AEO/GEO), sinais E-E-A-T e higiene do conteúdo.</p>' +
						'<span class="ce-chip">' + d.clusters + ' clusters</span>' +
						'<span class="ce-chip ce-chip-kw">' + d.links + ' links internos</span>' +
						(d.last_scan ? '<span class="ce-chip ce-chip-kw">último scan: ' + esc(d.last_scan) + '</span>' : '') +
					'</div>' +
				'</div>' +
				'<div class="ce-grid ce-grid-kpi">' +
					kpi('Posts indexados', d.posts, null, null, 'clusters') +
					kpi('Oportunidades de link', d.opps, d.opps ? 'warn' : 'good', 'alta similaridade, sem link', 'links#ce-sec-opps') +
					kpi('Links sem sentido', d.senseless, d.senseless ? 'bad' : 'good', 'linkados, sem relação semântica', 'links#ce-sec-senseless') +
					kpi('Posts órfãos', d.orphans, d.orphans ? 'bad' : 'good', 'sem nenhum link de entrada', 'diagnostics#ce-issue-orphan_no_inbound') +
					kpi('Canibalizações', d.cannibal, d.cannibal ? 'bad' : 'good', 'posts competindo pelo mesmo tema', 'links#ce-sec-cannibal') +
					kpi('Sem meta title', d.no_title, d.no_title ? 'warn' : 'good', null, 'diagnostics#ce-issue-no_meta_title') +
					kpi('Sem meta description', d.no_desc, d.no_desc ? 'warn' : 'good', null, 'diagnostics#ce-issue-no_meta_desc') +
					kpi('Fora de cluster', d.unclustered, d.unclustered ? 'warn' : 'good', null, 'diagnostics#ce-issue-no_cluster') +
					kpi('Score SEO médio', d.avg_seo, d.avg_seo >= 75 ? 'good' : 'warn', null, 'diagnostics') +
					kpi('Score AEO/GEO médio', d.avg_aeo, d.avg_aeo >= 75 ? 'good' : 'warn', 'prontidão para AI Overviews', 'diagnostics') +
				'</div>' +
				'<div class="ce-section" id="ce-insights" style="margin-top:26px"><div class="ce-loading">Compilando insights</div></div>';
			renderInsights();
			$$('[data-count]', el).forEach(function (n) { countUp(n, n.dataset.count); });
			requestAnimationFrame(function () {
				var ring = $('.ce-ring-val', el);
				if (ring) { ring.style.strokeDashoffset = off; }
			});
		}).catch(function (e) { el.innerHTML = '<div class="ce-empty"><p>' + esc(e.message) + '</p></div>'; });
	}

	/* ---------- Insights & Performance report ---------- */
	var reportData = null;
	var prioMeta = {
		alta:  { label: 'Prioridade alta', chip: 'ce-chip-red' },
		media: { label: 'Prioridade média', chip: 'ce-chip-amber' },
		aeo:   { label: 'Oportunidade AEO/GEO', chip: 'ce-chip' }
	};

	function renderInsights() {
		var box = $('#ce-insights');
		if (!box) { return; }
		api('report', {}).then(function (d) {
			reportData = d;
			if (!d.insights.length) {
				box.innerHTML = '<div class="ce-card"><h2 class="ce-h2">Insights do diagnóstico</h2><p class="ce-sub" style="margin:0">Nenhuma pendência encontrada nas análises. Rode um novo scan após publicar conteúdo.</p></div>';
				return;
			}
			var cards = d.insights.map(function (ins, idx) {
				var meta = prioMeta[ins.priority] || prioMeta.aeo;
				var pageRows = '';
				if (ins.pages && ins.pages.length) {
					pageRows = ins.pages.map(function (pg) {
						var fixBtn = '';
						if (ins.fix === 'HEADINGS') {
							fixBtn = ' <button class="ce-btn ce-btn-sm" data-fixheadings="' + pg.id + '">✍ Corrigir</button>';
						} else if (ins.fix) {
							fixBtn = ' <button class="ce-btn ce-btn-sm" data-ai="' + ins.fix + '" data-pid="' + pg.id + '">✍ Corrigir</button>';
						}
						return '<tr><td><a href="' + esc(pg.edit) + '" target="_blank" rel="noopener">' + esc(pg.title) + '</a></td>' +
							'<td style="white-space:nowrap;text-align:right">' +
							'<a class="ce-btn ce-btn-sm ce-btn-ghost" href="' + esc(pg.view) + '" target="_blank" rel="noopener">Ver</a>' + fixBtn + '</td></tr>';
					}).join('');
					pageRows = '<div class="ce-insight-pages" id="ce-ipages-' + idx + '" hidden><table class="ce-table"><tbody>' + pageRows + '</tbody></table>' +
						(ins.n > ins.pages.length ? '<p class="ce-sub" style="margin:8px 0 0">Mostrando ' + ins.pages.length + ' de ' + ins.n + ' — veja todos na seção de correção.</p>' : '') + '</div>';
				}
				return '<div class="ce-card" style="margin-bottom:12px">' +
					'<p style="margin:0 0 6px"><span class="ce-chip ' + meta.chip + '">' + meta.label + '</span> ' +
					'<b style="font-family:var(--ce-display)">' + ins.n + '</b></p>' +
					'<h3 style="font-family:var(--ce-display);font-size:15px;margin:0 0 4px">' + esc(ins.title) + '</h3>' +
					'<p class="ce-sub" style="margin:0 0 10px">' + esc(ins.why) + '</p>' +
					'<p style="margin:0">' +
						(ins.pages && ins.pages.length ? '<button class="ce-btn ce-btn-sm" data-tpages="' + idx + '">Ver páginas afetadas</button> ' : '') +
						'<button class="ce-btn ce-btn-sm ce-btn-ghost" data-goto="' + ins.goto + '">Ir para a correção →</button>' +
					'</p>' + pageRows +
				'</div>';
			}).join('');

			box.innerHTML =
				'<div class="ce-cluster-head" style="margin-bottom:14px">' +
					'<h2 class="ce-h2" style="margin:0">Insights do diagnóstico</h2>' +
					'<button class="ce-btn" id="ce-report-btn">📄 Gerar relatório de performance</button>' +
					'<button class="ce-btn ce-btn-ghost" id="ce-exec-btn">✍ Resumo executivo (IA)</button>' +
				'</div>' +
				'<p class="ce-sub">Plano de ação priorizado a partir de tudo que foi analisado: clusters, linkagem, SEO on-page, AEO/GEO e E-E-A-T.</p>' + cards;

			$$('[data-tpages]', box).forEach(function (b) {
				b.addEventListener('click', function () {
					var pg = $('#ce-ipages-' + b.dataset.tpages);
					pg.hidden = !pg.hidden;
					b.textContent = pg.hidden ? 'Ver páginas afetadas' : 'Ocultar páginas';
				});
			});
			$('#ce-report-btn').addEventListener('click', openReportWindow);
			$('#ce-exec-btn').addEventListener('click', execSummary);
		}).catch(function (e) {
			box.innerHTML = '<div class="ce-card"><p class="ce-sub" style="margin:0">' + esc(e.message) + '</p></div>';
		});
	}

	function reportAsText(d) {
		var t = 'Site: ' + d.site_name + ' | Autoridade tópica: ' + d.site_score + '/100 | SEO médio: ' + d.avg_seo + '/100 | AEO médio: ' + d.avg_aeo + '/100 | Posts: ' + d.total_posts + '\n\nClusters (força, componente mais fraco):\n';
		d.clusters.forEach(function (c) {
			t += '- ' + c.name + ': ' + c.score + '/100, ' + c.posts + ' posts, mais fraco em ' + (c.weakest || 'n/d') + (c.weakest_score !== null ? ' (' + c.weakest_score + '/100)' : '') + '\n';
		});
		t += '\nPendências priorizadas:\n';
		d.insights.forEach(function (i) {
			t += '- [' + i.priority.toUpperCase() + '] ' + i.title + ' (' + i.n + ' ocorrências)\n';
		});
		return t;
	}

	function execSummary() {
		if (!reportData || !requireKey()) { return; }
		modal('<h3 class="ce-h2">Escrevendo o resumo executivo…</h3><div class="ce-loading">Analisando os dados do relatório</div>');
		api('ai_run', { ai_action: 'exec_summary', post_id: 0, extra: { report_data: reportAsText(reportData) } }).then(function (d) {
			modal('<h3 class="ce-h2">Resumo executivo</h3><div class="ce-pre" style="background:var(--ce-paper);color:var(--ce-ink)">' + esc(d.result) + '</div>' +
				'<p style="margin-top:14px"><button class="ce-btn ce-btn-primary" id="ce-copy">Copiar</button></p>');
			$('#ce-copy').addEventListener('click', function () {
				navigator.clipboard.writeText(d.result).then(function () { toast('Copiado'); });
			});
		}).catch(function (e) {
			modal('<h3 class="ce-h2">Não foi possível gerar</h3><p>' + esc(e.message) + '</p>');
		});
	}

	function openReportWindow() {
		var d = reportData;
		if (!d) { return; }
		var prioLabel = { alta: 'ALTA', media: 'MÉDIA', aeo: 'AEO/GEO' };
		var prioColor = { alta: '#D93843', media: '#C77800', aeo: '#2547F4' };
		var clusterRows = d.clusters.map(function (c) {
			return '<tr><td>' + esc(c.name) + '</td><td style="text-align:center"><b>' + c.score + '</b>/100</td><td style="text-align:center">' + c.posts + '</td><td>' + esc(c.pillar || '—') + '</td><td>' + esc(c.weakest || '—') + (c.weakest_score !== null ? ' (' + c.weakest_score + '/100)' : '') + '</td></tr>';
		}).join('');
		var insightBlocks = d.insights.map(function (i, n) {
			var pages = (i.pages || []).map(function (p) {
				return '<li><a href="' + esc(p.view) + '">' + esc(p.title) + '</a></li>';
			}).join('');
			return '<div class="item"><p><span class="prio" style="background:' + prioColor[i.priority] + '">' + prioLabel[i.priority] + '</span> <b>' + (n + 1) + '. ' + esc(i.title) + '</b> · ' + i.n + ' ocorrências</p>' +
				'<p class="why">' + esc(i.why) + '</p>' +
				(pages ? '<ul>' + pages + (i.n > i.pages.length ? '<li>… e mais ' + (i.n - i.pages.length) + ' páginas (ver no painel)</li>' : '') + '</ul>' : '') + '</div>';
		}).join('');

		var html = '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><title>Relatório de Performance — ' + esc(d.site_name) + '</title><style>' +
			'body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#101426;max-width:820px;margin:40px auto;padding:0 24px;line-height:1.6}' +
			'h1{font-size:24px;margin:0 0 4px}h2{font-size:17px;margin:32px 0 10px;border-bottom:2px solid #2547F4;padding-bottom:6px}' +
			'.meta{color:#7A80A0;font-size:13px;margin:0 0 24px}' +
			'.scores{display:flex;gap:16px;margin:20px 0}.scorebox{border:1px solid #E3E6F2;border-radius:12px;padding:14px 20px;text-align:center}' +
			'.scorebox b{font-size:28px;display:block}.scorebox span{font-size:11px;color:#7A80A0;text-transform:uppercase;letter-spacing:.06em}' +
			'table{width:100%;border-collapse:collapse;font-size:13.5px}th,td{padding:8px 10px;border-bottom:1px solid #E3E6F2;text-align:left}th{font-size:11px;text-transform:uppercase;color:#7A80A0}' +
			'.item{margin-bottom:18px;page-break-inside:avoid}.prio{color:#fff;font-size:10.5px;font-weight:700;border-radius:99px;padding:2px 9px;letter-spacing:.04em}' +
			'.why{color:#3B4159;font-size:13.5px;margin:4px 0}ul{margin:6px 0 0 18px;font-size:13px}a{color:#2547F4}' +
			'.footer{margin-top:44px;padding-top:16px;border-top:1px solid #E3E6F2;color:#7A80A0;font-size:12.5px;text-align:center}' +
			'.printbtn{position:fixed;top:16px;right:16px;background:#101426;color:#fff;border:none;border-radius:99px;padding:10px 20px;cursor:pointer;font-weight:600}' +
			'@media print{.printbtn{display:none}body{margin:0 auto}}' +
			'</style></head><body>' +
			'<button class="printbtn" onclick="window.print()">Imprimir / Salvar PDF</button>' +
			'<h1>Relatório de Performance de Conteúdo</h1>' +
			'<p class="meta">' + esc(d.site_name) + ' · ' + esc(d.site_url) + ' · gerado em ' + esc(d.generated_at) + '</p>' +
			'<div class="scores">' +
				'<div class="scorebox"><b>' + d.site_score + '<small style="font-size:14px;color:#7A80A0">/100</small></b><span>Autoridade tópica</span></div>' +
				'<div class="scorebox"><b>' + d.avg_seo + '<small style="font-size:14px;color:#7A80A0">/100</small></b><span>SEO on-page médio</span></div>' +
				'<div class="scorebox"><b>' + d.avg_aeo + '<small style="font-size:14px;color:#7A80A0">/100</small></b><span>AEO/GEO médio</span></div>' +
				'<div class="scorebox"><b>' + d.total_posts + '</b><span>Posts analisados</span></div>' +
			'</div>' +
			'<h2>Força dos clusters</h2>' +
			'<table><thead><tr><th>Cluster</th><th>Força</th><th>Posts</th><th>Pilar</th><th>Componente mais fraco</th></tr></thead><tbody>' + clusterRows + '</tbody></table>' +
			'<h2>Plano de ação priorizado</h2>' + insightBlocks +
			'<div class="footer">Relatório gerado pelo Cluster Engine · Desenvolvido por <a href="https://61labs.com.br">61 Labs</a> · 61labs.com.br</div>' +
			'</body></html>';

		var w = window.open('', '_blank');
		if (!w) { toast('Permita pop-ups para gerar o relatório', true); return; }
		w.document.write(html);
		w.document.close();
	}

	function kpi(label, value, tone, sub, goto) {
		return '<div class="ce-card' + (goto ? ' is-link" tabindex="0" role="button" data-goto="' + goto + '"' : '"') + '>' +
			'<p class="ce-kpi-label">' + label + '</p>' +
			'<p class="ce-kpi-value' + (tone ? ' is-' + tone : '') + '" data-count="' + (value || 0) + '">0</p>' +
			(sub ? '<p class="ce-kpi-sub">' + sub + '</p>' : '') +
			(goto ? '<p class="ce-kpi-go">Ver posts →</p>' : '') + '</div>';
	}

	/* Scroll to a section that may not exist yet (panel renders async). */
	function scrollToSection(id, tries) {
		var el = document.getElementById(id);
		if (el) {
			el.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'start' });
			el.classList.add('ce-flash');
			setTimeout(function () { el.classList.remove('ce-flash'); }, 1600);
			return;
		}
		if ((tries || 0) < 20) {
			setTimeout(function () { scrollToSection(id, (tries || 0) + 1); }, 200);
		}
	}

	/* ---------- Clusters ---------- */
	function renderClusters() {
		var el = $('#ce-panel-clusters');
		el.innerHTML = '<div class="ce-loading">Carregando clusters</div>';
		api('clusters', {}).then(function (d) {
			if (!d.clusters.length) {
				el.innerHTML = '<div class="ce-empty"><h3>Nenhum cluster ainda</h3><p>Rode o scan para que os clusters emerjam do seu conteúdo.</p></div>';
				return;
			}
			var rows = d.clusters.map(function (c) {
				return '<tr data-cid="' + c.id + '">' +
					'<td><strong>' + esc(c.name) + '</strong><br><small style="color:var(--ce-ink-3)">' + (c.top_terms || []).slice(0, 4).map(function (t) { return '<span class="ce-chip ce-chip-kw">' + esc(t) + '</span>'; }).join('') + '</small></td>' +
					'<td>' + scoreBar(c.score) + '</td>' +
					'<td>' + c.post_count + '</td>' +
					'<td>' + (c.pillar_title ? esc(c.pillar_title) : '<span class="ce-chip ce-chip-red">sem pilar</span>') + '</td>' +
					'<td><button class="ce-btn ce-btn-sm" data-open="' + c.id + '">Abrir</button></td>' +
				'</tr>';
			}).join('');
			el.innerHTML =
				'<div class="ce-section"><h2 class="ce-h2">Clusters do site</h2>' +
				'<p class="ce-sub">Grupos temáticos que emergiram semanticamente do seu conteúdo. O score é a força de autoridade tópica de cada um.</p></div>' +
				'<div class="ce-card ce-table-wrap"><table class="ce-table"><thead><tr>' +
				'<th>Cluster</th><th>Força</th><th>Posts</th><th>Pilar</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
				'<div id="ce-cluster-detail"></div>';
		}).catch(function (e) { el.innerHTML = '<div class="ce-empty"><p>' + esc(e.message) + '</p></div>'; });
	}

	var issueLabels = {
		no_meta_title: 'Sem meta title', no_meta_desc: 'Sem meta description', short_meta_desc: 'Description curta',
		no_focus_keyword: 'Sem keyword foco', thin_content: 'Conteúdo fino', orphan_no_inbound: 'Órfão (0 links de entrada)',
		no_cluster: 'Fora de cluster', stale_content: 'Desatualizado', no_answer_capsule: 'Sem answer capsule',
		no_question_headings: 'Sem headings-pergunta', no_faq: 'Sem FAQ', no_external_sources: 'Sem fontes externas',
		no_author_bio: 'Autor sem bio (E-E-A-T)', images_no_alt: 'Imagens sem alt',
		capitalized_headings: 'Headings com capitalização excessiva'
	};

	function openCluster(cid) {
		var box = $('#ce-cluster-detail');
		box.innerHTML = '<div class="ce-loading">Abrindo cluster</div>';
		api('cluster_detail', { cluster_id: cid }).then(function (d) {
			var c = d.cluster, parts = c.score_parts || {};
			var partNames = { coverage: 'Cobertura', arch: 'Arquitetura', aeo: 'AEO/GEO', eeat: 'E-E-A-T', hygiene: 'Higiene' };
			var rows = d.members.map(function (m) {
				var issues = (m.issues || []).map(function (i) {
					return '<span class="ce-chip ' + (/orphan|thin|no_cluster/.test(i) ? 'ce-chip-red' : 'ce-chip-amber') + '">' + (issueLabels[i] || i) + '</span>';
				}).join('');
				return '<tr>' +
					'<td>' + (parseInt(m.is_pillar, 10) ? '<span class="ce-chip ce-chip-pillar">PILAR</span> ' : '') +
						'<a href="' + esc(m.edit_link) + '" target="_blank" rel="noopener"><strong>' + esc(m.title) + '</strong></a>' +
						'<br><small class="ce-chip ce-chip-kw">' + esc(m.main_keyword || '') + '</small></td>' +
					'<td>' + m.word_count + '</td>' +
					'<td>' + m.inbound + '</td>' +
					'<td>' + scoreBar(m.seo_score) + '</td>' +
					'<td>' + scoreBar(m.aeo_score) + '</td>' +
					'<td style="max-width:230px">' + (issues || '<span class="ce-chip ce-chip-green">ok</span>') + '</td>' +
					'<td style="white-space:nowrap">' +
						'<button class="ce-btn ce-btn-sm" data-ai="rewrite_title" data-pid="' + m.post_id + '" title="Reescrever meta title com IA">✍ Title</button> ' +
						'<button class="ce-btn ce-btn-sm" data-ai="rewrite_desc" data-pid="' + m.post_id + '" title="Reescrever meta description com IA">✍ Desc</button> ' +
						'<button class="ce-btn ce-btn-sm" data-ai="suggest_keyword" data-pid="' + m.post_id + '" title="Sugerir e gravar a keyword foco">⌖ Keyword</button> ' +
						'<button class="ce-btn ce-btn-sm" data-ai="answer_capsule" data-pid="' + m.post_id + '" title="Gerar answer capsule">◎ AEO</button>' +
						(parseInt(m.is_pillar, 10) ? '' : ' <button class="ce-btn ce-btn-sm ce-btn-ghost" data-pillar="' + m.post_id + '" data-cid="' + c.id + '">Tornar pilar</button>') +
					'</td>' +
				'</tr>';
			}).join('');

			box.innerHTML =
				'<div class="ce-card" style="margin-top:22px">' +
					'<div class="ce-cluster-head">' +
						'<h3 id="ce-cname">' + esc(c.name) + '</h3>' +
						'<button class="ce-btn ce-btn-sm ce-btn-ghost" id="ce-rename">Renomear</button>' +
						scoreBar(c.score) +
						'<button class="ce-btn ce-btn-sm" id="ce-gap" data-cid="' + c.id + '">✦ Gerar artigo para gap</button>' +
					'</div>' +
					'<div class="ce-parts">' +
						Object.keys(partNames).map(function (k) {
							return '<div class="ce-part"><small>' + partNames[k] + '</small>' + scoreBar(parts[k] || 0) + '</div>';
						}).join('') +
					'</div>' +
					'<div class="ce-table-wrap"><table class="ce-table"><thead><tr>' +
					'<th>Post</th><th>Palavras</th><th>Links in</th><th>SEO</th><th>AEO</th><th>Problemas</th><th>Ações IA</th>' +
					'</tr></thead><tbody>' + rows + '</tbody></table></div>' +
				'</div>';

			box.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'start' });

			$('#ce-rename').addEventListener('click', function () {
				var name = window.prompt('Novo nome do cluster:', c.name);
				if (name) {
					api('rename_cluster', { cluster_id: c.id, name: name }).then(function () {
						$('#ce-cname').textContent = name;
						toast('Cluster renomeado');
					}).catch(function (e) { toast(e.message, true); });
				}
			});
			$('#ce-gap').addEventListener('click', function () { gapArticle(c); });
		}).catch(function (e) { box.innerHTML = '<div class="ce-empty"><p>' + esc(e.message) + '</p></div>'; });
	}

	/* Meta rewrite / capsule / keyword flows — invoked by the global delegated click handler */
	var aiFieldMap = { rewrite_title: 'title', rewrite_desc: 'desc', suggest_keyword: 'keyword' };
	var aiFieldHeading = {
		title: 'Escolha o novo meta title',
		desc: 'Escolha a nova meta description',
		keyword: 'Escolha a keyword foco'
	};

	function runAIAction(btn) {
		if (!requireKey()) { return; }
		var action = btn.dataset.ai, pid = btn.dataset.pid;
		btn.disabled = true;
		modal('<h3 class="ce-h2">Gerando com IA…</h3><div class="ce-loading">Consultando o modelo</div>');
		api('ai_run', { ai_action: action, post_id: pid }).then(function (d) {
			btn.disabled = false;
			var field = aiFieldMap[action];
			if (field) {
				var opts = d.result.split(/\n+/).map(function (l) {
					return l.replace(/^\s*\d+[\).\-]\s*/, '').replace(/^["“”']|["“”']$/g, '').trim();
				}).filter(Boolean).slice(0, 5);
				modal(
					'<h3 class="ce-h2">' + aiFieldHeading[field] + '</h3>' +
					'<p class="ce-sub">Ao escolher, o valor é gravado direto no campo do seu plugin de SEO (' + esc(CE61.seoPlugin) + ').' +
					(field === 'keyword' ? ' A primeira opção é a que a IA considera melhor. A keyword foco pesa 5x na análise semântica — rode um novo scan depois para o índice refletir.' : '') + '</p>' +
					opts.map(function (o) {
						return '<div class="ce-option" data-val="' + esc(o) + '"><p>' + esc(o) + '</p><small>' + o.length + ' chars</small></div>';
					}).join('')
				);
				$$('.ce-option').forEach(function (op) {
					op.addEventListener('click', function () {
						api('apply_meta', { post_id: pid, field: field, value: op.dataset.val }).then(function () {
							closeModal();
							toast((field === 'keyword' ? 'Keyword foco gravada no ' : 'Meta atualizada no ') + CE61.seoPlugin);
							var chipRow = btn.closest('tr');
							if (chipRow && field === 'keyword') { btn.remove(); }
						}).catch(function (e) { toast(e.message, true); });
					});
				});
			} else {
				modal(
					'<h3 class="ce-h2">Resultado</h3>' +
					'<div class="ce-pre">' + esc(d.result) + '</div>' +
					'<p style="margin-top:14px"><button class="ce-btn ce-btn-primary" id="ce-copy">Copiar</button></p>'
				);
				$('#ce-copy').addEventListener('click', function () {
					navigator.clipboard.writeText(d.result).then(function () { toast('Copiado'); });
				});
			}
		}).catch(function (e) {
			btn.disabled = false;
			modal('<h3 class="ce-h2">Não foi possível gerar</h3><p>' + esc(e.message) + '</p>');
		});
	}

	/* Anchor suggestion for a link opportunity — suggestions are implementable */
	function runAnchorAction(btn) {
		if (!requireKey()) { return; }
		var p = btn.dataset.anchor.split(':');
		var row = btn.closest('tr');
		modal('<h3 class="ce-h2">Sugerindo anchor text…</h3><div class="ce-loading">Lendo o parágrafo mais relevante e consultando o modelo</div>');
		api('ai_run', {
			ai_action: 'anchor_text',
			post_id: p[0],
			second_post_id: p[1]
		}).then(function (d) {
			var opts = d.result.split(/\n+/).map(function (l) {
				return l.replace(/^\s*\d+[\).\-]\s*/, '').replace(/^["“”']|["“”']$/g, '').trim();
			}).filter(Boolean).slice(0, 5);
			modal(
				'<h3 class="ce-h2">Escolha a âncora para implementar</h3>' +
				'<p class="ce-sub">Link de “' + esc(btn.dataset.ta) + '” → “' + esc(btn.dataset.tb) + '”. Se a frase existir no texto, ela vira o link; se não, um “Leia também” é inserido após o parágrafo mais relevante.</p>' +
				opts.map(function (o) {
					return '<div class="ce-option" data-anchor-pick="' + esc(o) + '"><p>' + esc(o) + '</p><small>' + o.length + ' chars</small></div>';
				}).join('') +
				'<div id="ce-anchor-out"></div>'
			);
			$$('[data-anchor-pick]').forEach(function (op) {
				op.addEventListener('click', function () {
					var out = $('#ce-anchor-out');
					out.innerHTML = '<div class="ce-loading">Inserindo o link no post</div>';
					api('insert_link', { post_a: p[0], post_b: p[1], anchor: op.dataset.anchorPick }).then(function (r) {
						var msg = r.mode === 'wrapped'
							? 'A frase já existia no texto e virou o link — inserção perfeita.'
							: (r.mode === 'inserted'
								? 'A frase não existia no texto, então um “Leia também” foi inserido após o parágrafo mais relevante.'
								: 'Este post já linkava para o destino — a oportunidade foi marcada como resolvida.');
						out.innerHTML =
							'<p style="margin-top:14px"><span class="ce-chip ce-chip-green">Link implementado</span></p>' +
							'<p class="ce-sub">' + msg + '</p>' +
							'<p><a class="ce-btn ce-btn-sm" href="' + esc(r.edit) + '" target="_blank" rel="noopener">Revisar no editor</a> ' +
							(r.view ? '<a class="ce-btn ce-btn-sm ce-btn-ghost" href="' + esc(r.view) + '" target="_blank" rel="noopener">Ver no site</a>' : '') + '</p>';
						if (row) { row.remove(); }
						toast('Link interno implementado');
					}).catch(function (e) {
						out.innerHTML = '<p style="margin-top:12px">' + esc(e.message) + '</p>';
					});
				});
			});
		}).catch(function (e) {
			modal('<h3 class="ce-h2">Não foi possível gerar</h3><p>' + esc(e.message) + '</p>');
		});
	}

	function runDismissAction(btn) {
		var p = btn.dataset.dismiss.split(':');
		api('dismiss_relation', { post_a: p[0], post_b: p[1] }).then(function () {
			var tr = btn.closest('tr');
			if (tr) { tr.remove(); }
			toast('Sugestão ignorada');
		}).catch(function (e) { toast(e.message, true); });
	}

	/* Cannibalization: recreate the two competing posts as one unified draft */
	function runMergeAction(btn) {
		if (!requireKey()) { return; }
		var p = btn.dataset.merge.split(':');
		var ta = btn.dataset.ta, tb = btn.dataset.tb;
		var row = btn.closest('tr');
		modal(
			'<h3 class="ce-h2">Recriar post unificado</h3>' +
			'<p class="ce-sub">A IA vai fundir “' + esc(ta) + '” e “' + esc(tb) + '” num único artigo completo, eliminando a canibalização. O resultado entra como <b>rascunho</b>; os originais não são tocados.</p>' +
			'<div class="ce-field"><label>Título do post unificado</label><input class="ce-input" id="ce-merge-title" value="' + esc(ta) + '"></div>' +
			'<p><button class="ce-btn ce-btn-primary" id="ce-merge-go">✦ Gerar rascunho unificado</button></p>' +
			'<div id="ce-merge-out"></div>'
		);
		$('#ce-merge-go').addEventListener('click', function () {
			var title = $('#ce-merge-title').value.trim() || ta;
			var out = $('#ce-merge-out');
			$('#ce-merge-go').disabled = true;
			out.innerHTML = '<div class="ce-loading">Fundindo os dois posts</div>';
			api('ai_run', {
				ai_action: 'merge_posts',
				post_id: p[0],
				second_post_id: p[1]
			}).then(function (d) {
				return api('create_draft', { title: title, content: d.result, source_post_id: p[0] });
			}).then(function (d) {
				out.innerHTML =
					'<p><span class="ce-chip ce-chip-green">Rascunho criado com SEO completo</span></p>' +
					'<p class="ce-sub">Meta title, description e keyword foco já foram gravados no seu plugin de SEO.</p>' +
					'<p><a class="ce-btn ce-btn-sm" href="' + esc(d.edit) + '" target="_blank" rel="noopener">Abrir no editor</a> ' +
					(d.preview ? '<a class="ce-btn ce-btn-sm ce-btn-ghost" href="' + esc(d.preview) + '" target="_blank" rel="noopener">Pré-visualizar rascunho</a>' : '') + '</p>' +
					'<hr style="border:none;border-top:1px solid var(--ce-line);margin:16px 0">' +
					'<p class="ce-sub"><b>Aplicar tudo com 1 clique:</b> publica o unificado, cria redirecionamento 301 das duas URLs antigas para ele, insere o schema Article e arquiva os originais na lixeira (reversível).</p>' +
					'<p><button class="ce-btn ce-btn-primary" id="ce-merge-apply">⚡ Publicar, aplicar 301 e arquivar antigos</button></p>' +
					'<div id="ce-merge-apply-out"></div>';
				toast('Rascunho unificado criado');
				$('#ce-merge-apply').addEventListener('click', function () {
					if (!window.confirm('Confirma? O unificado será publicado, as URLs antigas passarão a redirecionar (301) para ele, e os dois posts originais irão para a lixeira.')) { return; }
					var ao = $('#ce-merge-apply-out');
					$('#ce-merge-apply').disabled = true;
					ao.innerHTML = '<div class="ce-loading">Publicando e aplicando redirecionamentos</div>';
					api('merge_apply', { draft_id: d.id, post_a: p[0], post_b: p[1] }).then(function (r) {
						ao.innerHTML =
							'<p><span class="ce-chip ce-chip-green">Concluído</span> ' + r.redirects + ' redirecionamentos 301 ativos · schema Article inserido · originais na lixeira</p>' +
							'<p><a class="ce-btn ce-btn-sm" href="' + esc(r.view) + '" target="_blank" rel="noopener">Ver post publicado</a> ' +
							'<a class="ce-btn ce-btn-sm ce-btn-ghost" href="' + esc(r.edit) + '" target="_blank" rel="noopener">Editar</a></p>';
						if (row) { row.remove(); }
						toast('Canibalização resolvida');
					}).catch(function (e) {
						$('#ce-merge-apply').disabled = false;
						ao.innerHTML = '<p>' + esc(e.message) + '</p>';
					});
				});
			}).catch(function (e) {
				$('#ce-merge-go').disabled = false;
				out.innerHTML = '<p>' + esc(e.message) + '</p>';
			});
		});
	}

	/* ---------- Global delegated clicks: works for any dynamically rendered button ---------- */
	document.addEventListener('click', function (e) {
		var t = e.target.closest ? e.target.closest('[data-ai],[data-anchor],[data-dismiss],[data-open],[data-pillar],[data-merge],[data-goto],[data-fixschema-article],[data-fixschema-faq],[data-fixheadings],[data-imggen],[data-imgview]') : null;
		if (!t || t.disabled) { return; }
		if (t.dataset.goto) {
			var parts = t.dataset.goto.split('#');
			gotoTab(parts[0]);
			if (parts[1]) { scrollToSection(parts[1]); }
			return;
		}
		if (t.dataset.fixheadings) { runHeadingsFix(t); return; }
		if (t.dataset.imggen) { runImageGen(t); return; }
		if (t.dataset.imgview) {
			modal('<img src="' + esc(t.dataset.imgview) + '" alt="" style="max-width:100%;border-radius:12px;display:block">');
			return;
		}
		if (t.dataset.fixschemaArticle) { runSchemaFix(t, 'article'); return; }
		if (t.dataset.fixschemaFaq) { runSchemaFix(t, 'faq'); return; }
		if (t.dataset.ai) { runAIAction(t); return; }
		if (t.dataset.anchor) { runAnchorAction(t); return; }
		if (t.dataset.merge) { runMergeAction(t); return; }
		if (t.dataset.dismiss) { runDismissAction(t); return; }
		if (t.dataset.open) { openCluster(t.dataset.open); return; }
		if (t.dataset.pillar) {
			api('set_pillar', { cluster_id: t.dataset.cid, post_id: t.dataset.pillar }).then(function () {
				toast('Pilar atualizado');
				openCluster(t.dataset.cid);
			}).catch(function (e2) { toast(e2.message, true); });
		}
	});
	/* Keyboard access for clickable KPI cards */
	document.addEventListener('keydown', function (e) {
		if ((e.key === 'Enter' || e.key === ' ') && e.target.dataset && e.target.dataset.goto) {
			e.preventDefault();
			e.target.click();
		}
	});

	/* Gap article generation → WP draft */
	function gapArticle(cluster) {
		if (!requireKey()) { return; }
		modal(
			'<h3 class="ce-h2">Gerar artigo satélite para o cluster “' + esc(cluster.name) + '”</h3>' +
			'<p class="ce-sub">O rascunho entra no WordPress como draft, nunca é publicado sozinho.</p>' +
			'<div class="ce-field"><label>Tópico do gap</label><input class="ce-input" id="ce-gap-topic" placeholder="Ex.: Como calcular verbas rescisórias em 2026"></div>' +
			'<div class="ce-field"><label>Keyword alvo</label><input class="ce-input" id="ce-gap-kw" placeholder="Ex.: verbas rescisórias"></div>' +
			'<p><button class="ce-btn ce-btn-primary" id="ce-gap-go">Gerar rascunho</button></p>' +
			'<div id="ce-gap-out"></div>'
		);
		$('#ce-gap-go').addEventListener('click', function () {
			var topic = $('#ce-gap-topic').value.trim();
			var kw = $('#ce-gap-kw').value.trim();
			if (!topic) { toast('Informe o tópico do gap', true); return; }
			var out = $('#ce-gap-out');
			out.innerHTML = '<div class="ce-loading">Escrevendo o artigo</div>';
			api('ai_run', {
				ai_action: 'generate_article',
				post_id: cluster.pillar_id || 0,
				extra: {
					gap_topic: topic,
					keyword: kw,
					cluster_name: cluster.name,
					pillar_title: cluster.pillar_title || '',
					internal_links_list: ''
				}
			}).then(function (d) {
				return api('create_draft', { title: topic, content: d.result, focus_keyword: kw, source_post_id: cluster.pillar_id || 0 });
			}).then(function (d) {
				out.innerHTML = '<p class="ce-chip ce-chip-green">Rascunho criado com SEO completo</p> <a class="ce-btn ce-btn-sm" href="' + esc(d.edit) + '" target="_blank" rel="noopener">Abrir no editor</a>' +
					(d.preview ? ' <a class="ce-btn ce-btn-sm ce-btn-ghost" href="' + esc(d.preview) + '" target="_blank" rel="noopener">Pré-visualizar</a>' : '');
				toast('Rascunho criado no WordPress');
			}).catch(function (e) { out.innerHTML = '<p>' + esc(e.message) + '</p>'; });
		});
	}

	/* ---------- Links ---------- */
	function renderLinks() {
		var el = $('#ce-panel-links');
		el.innerHTML = '<div class="ce-loading">Analisando o grafo de links</div>';
		api('links', {}).then(function (d) {
			function pairRow(r, kind) {
				var actions = '';
				if (kind === 'opp') {
					actions = '<button class="ce-btn ce-btn-sm" data-anchor="' + r.post_a + ':' + r.post_b + '" data-ta="' + esc(r.title_a) + '" data-tb="' + esc(r.title_b) + '" data-kb="' + esc(r.kw_b || '') + '">✍ Sugerir anchor</button> ' +
						'<button class="ce-btn ce-btn-sm ce-btn-ghost" data-dismiss="' + r.post_a + ':' + r.post_b + '">Ignorar</button>';
				}
				if (kind === 'cannibal') {
					actions = '<button class="ce-btn ce-btn-sm" data-merge="' + r.post_a + ':' + r.post_b + '" data-ta="' + esc(r.title_a) + '" data-tb="' + esc(r.title_b) + '">✦ Recriar unificado</button>';
				}
				return '<tr>' +
					'<td>' + esc(r.title_a) + '</td>' +
					'<td>' + esc(r.title_b) + '</td>' +
					'<td>' + scoreBar(Math.round(r.similarity * 100), kind === 'senseless') + '</td>' +
					'<td style="white-space:nowrap">' + actions + '</td>' +
				'</tr>';
			}
			function block(id, title, sub, rows, kind, emptyMsg) {
				return '<div class="ce-section" id="' + id + '"><h2 class="ce-h2">' + title + '</h2><p class="ce-sub">' + sub + '</p>' +
					(rows.length
						? '<div class="ce-card ce-table-wrap"><table class="ce-table"><thead><tr><th>Post A</th><th>Post B</th><th>Similaridade</th><th></th></tr></thead><tbody>' +
							rows.map(function (r) { return pairRow(r, kind); }).join('') + '</tbody></table></div>'
						: '<div class="ce-empty" style="padding:34px"><p>' + emptyMsg + '</p></div>') +
					'</div>';
			}
			el.innerHTML =
				block('ce-sec-opps', 'Oportunidades de link', 'Pares com alta afinidade semântica que ainda não se linkam. A mina de ouro da autoridade tópica.', d.opportunities, 'opp', 'Nenhuma oportunidade pendente. Arquitetura em dia.') +
				block('ce-sec-senseless', 'Links que não fazem sentido', 'Links existentes entre posts sem relação semântica. Diluem o sinal do cluster; considere remover ou retrabalhar.', d.senseless, 'senseless', 'Nenhum link incoerente encontrado.') +
				block('ce-sec-cannibal', 'Possível canibalização', 'Posts quase idênticos competindo pela mesma intenção. Use ✦ Recriar unificado para fundir os dois num único artigo e depois aplicar redirecionamento 301.', d.cannibal, 'cannibal', 'Nenhuma canibalização detectada.');
		}).catch(function (e) { el.innerHTML = '<div class="ce-empty"><p>' + esc(e.message) + '</p></div>'; });
	}

	/* ---------- Keywords ---------- */
	function renderKeywords() {
		var el = $('#ce-panel-keywords');
		el.innerHTML = '<div class="ce-loading">Mapeando palavras-chave</div>';
		api('keywords', {}).then(function (d) {
			var rows = d.keywords.map(function (k) {
				return '<tr><td><span class="ce-chip ce-chip-kw">' + esc(k.term) + '</span></td>' +
					'<td>' + k.count + '</td>' +
					'<td>' + k.posts.map(function (p) { return esc(p.title); }).join(' · ') + '</td></tr>';
			}).join('');
			function dupGroup(group, type) {
				var posts = group.posts || [];
				var mergeBtn = '';
				if (posts.length === 2) {
					mergeBtn = ' <button class="ce-btn ce-btn-sm" data-merge="' + posts[0].id + ':' + posts[1].id + '" data-ta="' + esc(posts[0].title) + '" data-tb="' + esc(posts[1].title) + '">✦ Recriar unificado</button>';
				}
				var rows = posts.map(function (p) {
					var fix = type === 'title'
						? '<button class="ce-btn ce-btn-sm" data-ai="rewrite_title" data-pid="' + p.id + '">✍ Novo title</button>'
						: '<button class="ce-btn ce-btn-sm" data-ai="suggest_keyword" data-pid="' + p.id + '">⌖ Nova keyword</button>';
					return '<tr><td><a href="' + esc(p.edit) + '" target="_blank" rel="noopener">' + esc(p.title) + '</a></td>' +
						'<td style="white-space:nowrap;text-align:right">' + fix + '</td></tr>';
				}).join('');
				return '<div style="margin-bottom:14px">' +
					'<p style="margin:0 0 6px"><span class="ce-chip ce-chip-red">' + posts.length + ' posts</span> <b>' + esc(group.meta_value) + '</b>' + mergeBtn + '</p>' +
					'<table class="ce-table"><tbody>' + rows + '</tbody></table></div>';
			}
			var dupT = (d.duplicates.title || []).map(function (g) { return dupGroup(g, 'title'); }).join('');
			var dupK = (d.duplicates.kw || []).map(function (g) { return dupGroup(g, 'kw'); }).join('');
			el.innerHTML =
				'<div class="ce-section"><h2 class="ce-h2">Mapa de palavras-chave do site</h2>' +
				'<p class="ce-sub">Termos e entidades extraídos semanticamente do conteúdo (título e headings pesam mais; a keyword foco do plugin de SEO pesa mais ainda).</p>' +
				'<div class="ce-card ce-table-wrap"><table class="ce-table"><thead><tr><th>Termo</th><th>Posts</th><th>Onde aparece</th></tr></thead><tbody>' + (rows || '') + '</tbody></table></div></div>' +
				'<div class="ce-section"><h2 class="ce-h2">Canibalização declarada</h2>' +
				'<p class="ce-sub">Meta titles e keywords foco repetidos no seu plugin de SEO — dois posts disputando a mesma SERP. Estratégia: mantenha o valor no post mais forte e reescreva nos demais; ou, se os posts são quase iguais, recrie unificado.</p>' +
				'<div class="ce-grid" style="grid-template-columns:1fr 1fr;align-items:start">' +
					'<div class="ce-card"><p class="ce-kpi-label">Meta titles duplicados</p>' + (dupT || '<p class="ce-sub" style="margin:0">Nenhum.</p>') + '</div>' +
					'<div class="ce-card"><p class="ce-kpi-label">Keywords foco duplicadas</p>' + (dupK || '<p class="ce-sub" style="margin:0">Nenhuma.</p>') + '</div>' +
				'</div></div>';
		}).catch(function (e) { el.innerHTML = '<div class="ce-empty"><p>' + esc(e.message) + '</p></div>'; });
	}

	/* ---------- Diagnostics ---------- */
	function renderDiagnostics() {
		var el = $('#ce-panel-diagnostics');
		el.innerHTML = '<div class="ce-loading">Rodando diagnóstico</div>';
		var severity = {
			orphan_no_inbound: 'red', thin_content: 'red', no_cluster: 'red',
			no_meta_title: 'amber', no_meta_desc: 'amber', short_meta_desc: 'amber',
			no_focus_keyword: 'amber', stale_content: 'amber', images_no_alt: 'amber',
			capitalized_headings: 'amber',
			no_answer_capsule: 'blue', no_question_headings: 'blue', no_faq: 'blue',
			no_external_sources: 'blue', no_author_bio: 'blue'
		};
		var fixable = { no_meta_title: 'rewrite_title', no_meta_desc: 'rewrite_desc', short_meta_desc: 'rewrite_desc', no_focus_keyword: 'suggest_keyword', no_answer_capsule: 'answer_capsule', no_faq: 'faq_schema', stale_content: 'refresh_post' };
		api('diagnostics', {}).then(function (d) {
			var order = ['red', 'amber', 'blue'];
			var groups = Object.keys(d.issues).sort(function (a, b) {
				return order.indexOf(severity[a] || 'blue') - order.indexOf(severity[b] || 'blue');
			});
			if (!groups.length) {
				el.innerHTML = '<div class="ce-empty"><h3>Nada para corrigir</h3><p>Rode o scan ou celebre: seu site está limpo.</p></div>';
				return;
			}
			el.innerHTML = '<div class="ce-section"><h2 class="ce-h2">Central de diagnóstico</h2><p class="ce-sub">Problemas priorizados por impacto. Vermelho compromete a autoridade do cluster; âmbar é SEO on-page; azul é oportunidade AEO/GEO.</p></div>' +
				groups.map(function (g) {
					var sev = severity[g] || 'blue';
					var chip = sev === 'red' ? 'ce-chip-red' : (sev === 'amber' ? 'ce-chip-amber' : 'ce-chip');
					var list = d.issues[g].slice(0, 30).map(function (p) {
						var fix = '';
						if (g === 'capitalized_headings') {
							fix = ' <button class="ce-btn ce-btn-sm" data-fixheadings="' + p.id + '">✍ Corrigir headings</button>';
						} else if (fixable[g]) {
							fix = ' <button class="ce-btn ce-btn-sm" data-ai="' + fixable[g] + '" data-pid="' + p.id + '">✍ Corrigir com IA</button>';
						}
						return '<tr><td><a href="' + esc(p.edit) + '" target="_blank" rel="noopener">' + esc(p.title) + '</a></td><td style="white-space:nowrap">' + fix + '</td></tr>';
					}).join('');
					return '<div class="ce-card" style="margin-bottom:16px" id="ce-issue-' + g + '">' +
						'<p style="margin:0 0 10px"><span class="ce-chip ' + chip + '">' + (issueLabels[g] || g) + '</span> <b style="font-family:var(--ce-display)">' + d.issues[g].length + '</b> posts</p>' +
						'<div class="ce-table-wrap"><table class="ce-table"><tbody>' + list + '</tbody></table></div></div>';
				}).join('');
		}).catch(function (e) { el.innerHTML = '<div class="ce-empty"><p>' + esc(e.message) + '</p></div>'; });
	}

	/* ---------- Schema ---------- */
	var schemaIssueLabels = {
		no_schema: 'Nenhum schema na página', broken_schema: 'Schema quebrado (JSON inválido)',
		no_article_schema: 'Sem schema Article/BlogPosting', article_no_author: 'Article sem autor (E-E-A-T)',
		article_no_date: 'Article sem datePublished', article_no_image: 'Article sem imagem',
		no_faq_schema: 'Sem schema FAQPage', no_breadcrumb: 'Sem BreadcrumbList', fetch_failed: 'Não foi possível ler a página'
	};

	function renderSchema() {
		var el = $('#ce-panel-schema');
		el.innerHTML =
			'<div class="ce-section"><h2 class="ce-h2">Auditoria de Schema (dados estruturados)</h2>' +
			'<p class="ce-sub">O plugin abre cada página publicada, lê o JSON-LD renderizado (incluindo o que seu plugin de SEO gera) e aponta o que está quebrado ou faltando. As correções são inseridas direto no post.</p>' +
			'<p><button class="ce-btn ce-btn-primary" id="ce-schema-scan">Auditar schema das páginas</button></p>' +
			'<div class="ce-scanbar" id="ce-schema-bar" hidden><div class="ce-scanbar-track"><div class="ce-scanbar-fill" id="ce-schema-fill"></div></div><p id="ce-schema-msg"></p></div>' +
			'</div><div id="ce-schema-results"><div class="ce-loading">Carregando resultados anteriores</div></div>';

		$('#ce-schema-scan').addEventListener('click', function () {
			var btn = $('#ce-schema-scan'), bar = $('#ce-schema-bar'), fill = $('#ce-schema-fill'), msg = $('#ce-schema-msg');
			btn.disabled = true;
			bar.hidden = false;
			(function step(offset) {
				api('schema_scan', { offset: offset }).then(function (d) {
					var pct = d.total ? (d.done / d.total) * 100 : 100;
					fill.style.width = pct + '%';
					msg.textContent = 'Lendo páginas ' + d.done + '/' + d.total + ' (cada página é aberta e analisada)';
					if (d.done < d.total) { step(d.done); } else {
						msg.textContent = 'Auditoria concluída.';
						btn.disabled = false;
						setTimeout(function () { bar.hidden = true; }, 800);
						loadSchemaResults();
					}
				}).catch(function (e) {
					btn.disabled = false;
					bar.hidden = true;
					toast(e.message, true);
				});
			})(0);
		});
		loadSchemaResults();
	}

	function loadSchemaResults() {
		var box = $('#ce-schema-results');
		api('schema_results', {}).then(function (d) {
			if (!d.results.length) {
				box.innerHTML = '<div class="ce-empty"><h3>Nenhuma auditoria ainda</h3><p>Clique em Auditar schema das páginas para o plugin ler o JSON-LD renderizado de cada post.</p></div>';
				return;
			}
			var withIssues = d.results.filter(function (r) { return r.issues.length; }).length;
			var rows = d.results.map(function (r) {
				var types = r.types.length
					? r.types.map(function (t) { return '<span class="ce-chip ce-chip-green">' + esc(t) + '</span>'; }).join('')
					: '<span class="ce-chip ce-chip-red">nenhum</span>';
				var issues = r.issues.map(function (i) {
					var cls = /broken|no_schema|no_article|fetch/.test(i) ? 'ce-chip-red' : 'ce-chip-amber';
					return '<span class="ce-chip ' + cls + '">' + (schemaIssueLabels[i] || i) + '</span>';
				}).join('') || '<span class="ce-chip ce-chip-green">completo</span>';
				var actions = '';
				if (r.issues.indexOf('no_article_schema') > -1 || r.issues.indexOf('no_schema') > -1) {
					actions += '<button class="ce-btn ce-btn-sm" data-fixschema-article="' + r.post_id + '">◈ Inserir Article</button> ';
				}
				if (r.issues.indexOf('no_faq_schema') > -1) {
					actions += '<button class="ce-btn ce-btn-sm" data-fixschema-faq="' + r.post_id + '">✍ Gerar FAQ + schema</button>';
				}
				return '<tr id="ce-schema-row-' + r.post_id + '">' +
					'<td><a href="' + esc(r.edit) + '" target="_blank" rel="noopener">' + esc(r.title) + '</a></td>' +
					'<td>' + types + '</td>' +
					'<td style="max-width:280px">' + issues + '</td>' +
					'<td style="white-space:nowrap">' + actions + '</td>' +
				'</tr>';
			}).join('');
			box.innerHTML =
				'<div class="ce-card ce-table-wrap">' +
				'<p style="margin:0 0 12px"><b style="font-family:var(--ce-display)">' + withIssues + '</b> de ' + d.results.length + ' páginas com pendências de schema</p>' +
				'<table class="ce-table"><thead><tr><th>Página</th><th>Schema encontrado</th><th>Pendências</th><th>Corrigir</th></tr></thead><tbody>' + rows + '</tbody></table></div>';
		}).catch(function (e) { box.innerHTML = '<div class="ce-empty"><p>' + esc(e.message) + '</p></div>'; });
	}

	/* Headings: preview sentence-case corrections and apply to the post */
	function runHeadingsFix(btn) {
		if (!requireKey()) { return; }
		var pid = btn.dataset.fixheadings;
		modal('<h3 class="ce-h2">Analisando os headings…</h3><div class="ce-loading">Convertendo para sentence case, preservando nomes próprios e siglas</div>');
		api('headings_preview', { post_id: pid }).then(function (d) {
			var changed = d.pairs.filter(function (p) { return p.changed; });
			if (!changed.length) {
				modal('<h3 class="ce-h2">Nada a corrigir</h3><p class="ce-sub">A IA analisou os headings e considerou a capitalização adequada (ou composta apenas por nomes próprios e siglas).</p>');
				return;
			}
			modal(
				'<h3 class="ce-h2">Correção de headings — antes e depois</h3>' +
				'<p class="ce-sub">' + changed.length + ' de ' + d.pairs.length + ' headings serão ajustados para sentence case. Revise e aplique.</p>' +
				changed.map(function (p) {
					return '<div class="ce-option" style="cursor:default;display:block">' +
						'<p style="color:var(--ce-ink-3)"><s>' + esc(p.from) + '</s></p>' +
						'<p><b>→ ' + esc(p.to) + '</b></p></div>';
				}).join('') +
				'<p style="margin-top:14px"><button class="ce-btn ce-btn-primary" id="ce-headings-apply">Aplicar ' + changed.length + ' correções no post</button></p>' +
				'<div id="ce-headings-out"></div>'
			);
			$('#ce-headings-apply').addEventListener('click', function () {
				var out = $('#ce-headings-out');
				$('#ce-headings-apply').disabled = true;
				out.innerHTML = '<div class="ce-loading">Atualizando o post</div>';
				api('apply_headings', { post_id: pid, pairs: JSON.stringify(changed) }).then(function (r) {
					out.innerHTML = '<p><span class="ce-chip ce-chip-green">' + r.applied + ' headings corrigidos</span> <a class="ce-btn ce-btn-sm" href="' + esc(r.edit) + '" target="_blank" rel="noopener">Revisar no editor</a></p>';
					toast('Headings corrigidos');
				}).catch(function (e) {
					$('#ce-headings-apply').disabled = false;
					out.innerHTML = '<p>' + esc(e.message) + '</p>';
				});
			});
		}).catch(function (e) {
			modal('<h3 class="ce-h2">Não foi possível analisar</h3><p>' + esc(e.message) + '</p>');
		});
	}

	function runSchemaFix(btn, kind) {
		if (kind === 'faq' && !requireKey()) { return; }
		var pid = kind === 'faq' ? btn.dataset.fixschemaFaq : btn.dataset.fixschemaArticle;
		btn.disabled = true;
		btn.textContent = 'Inserindo…';
		api(kind === 'faq' ? 'schema_fix_faq' : 'schema_fix_article', { post_id: pid }).then(function (d) {
			toast(d.mode === 'already' ? 'Este post já tinha o schema no conteúdo' : 'Schema inserido no post');
			loadSchemaResults();
		}).catch(function (e) {
			btn.disabled = false;
			btn.textContent = kind === 'faq' ? '✍ Gerar FAQ + schema' : '◈ Inserir Article';
			modal('<h3 class="ce-h2">Não foi possível inserir</h3><p>' + esc(e.message) + '</p>');
		});
	}

	/* ---------- Featured images ---------- */
	function renderImages() {
		var el = $('#ce-panel-images');
		el.innerHTML = '<div class="ce-loading">Carregando imagens destacadas</div>';
		api('images_list', {}).then(function (d) {
			if (!d.posts.length) {
				el.innerHTML = '<div class="ce-empty"><h3>Nenhum post indexado</h3><p>Rode o scan primeiro.</p></div>';
				return;
			}
			var missing = d.posts.filter(function (p) { return !p.thumb; });
			var having  = d.posts.filter(function (p) { return p.thumb; });

			function card(p) {
				var media = p.thumb
					? '<img class="ce-imgcard-thumb" src="' + esc(p.thumb) + '" alt="" loading="lazy" data-imgview="' + esc(p.full || p.thumb) + '" title="Clique para ampliar">'
					: '<div class="ce-imgcard-empty">sem imagem</div>';
				return '<div class="ce-imgcard" id="ce-imgcard-' + p.post_id + '">' +
					'<label class="ce-imgcard-check"><input type="checkbox" class="ce-img-select" value="' + p.post_id + '"></label>' +
					media +
					'<div class="ce-imgcard-body">' +
						'<p class="ce-imgcard-title"><a href="' + esc(p.edit) + '" target="_blank" rel="noopener">' + esc(p.title) + '</a></p>' +
						'<button class="ce-btn ce-btn-sm" data-imggen="' + p.post_id + '" data-title="' + esc(p.title) + '">' + (p.thumb ? '✦ Recriar imagem' : '✦ Gerar imagem') + '</button>' +
					'</div></div>';
			}
			el.innerHTML =
				'<div class="ce-section"><h2 class="ce-h2">Imagens destacadas</h2>' +
				'<p class="ce-sub">Busca primeiro em bancos de imagens gratuitos (Unsplash, Pexels, Pixabay, Openverse) respeitando a cota de cada API; gera por IA quando preferir ou quando os bancos não tiverem resultado. Clique numa imagem para ampliar.</p>' +
				'<div class="ce-net-toolbar">' +
					'<label class="ce-check"><input type="checkbox" id="ce-img-selall"> Selecionar todos sem imagem</label>' +
					'<select class="ce-select" id="ce-img-bulk-source" style="max-width:220px">' +
						'<option value="auto">Automático (banco → IA)</option>' +
						'<option value="stock">Só banco de imagens</option>' +
						'<option value="ai">Só IA</option>' +
					'</select>' +
					'<button class="ce-btn ce-btn-primary" id="ce-img-bulk-go">✦ Gerar em fila (<span id="ce-img-selcount">0</span>)</button>' +
					'<span class="ce-sub" id="ce-img-bulk-hint">Marque as imagens e processe em lote pela Fila de Geração</span>' +
				'</div></div>' +
				(missing.length
					? '<div class="ce-section"><p style="margin:0 0 10px"><span class="ce-chip ce-chip-red">Sem imagem destacada</span> <b style="font-family:var(--ce-display)">' + missing.length + '</b> posts</p><div class="ce-imggrid">' + missing.map(card).join('') + '</div></div>'
					: '') +
				(having.length
					? '<div class="ce-section"><p style="margin:0 0 10px"><span class="ce-chip ce-chip-green">Com imagem</span> <b style="font-family:var(--ce-display)">' + having.length + '</b> posts</p><div class="ce-imggrid">' + having.map(card).join('') + '</div></div>'
					: '');

			function updateSelCount() {
				var n = $$('.ce-img-select:checked', el).length;
				$('#ce-img-selcount').textContent = n;
				$('#ce-img-bulk-go').disabled = !n;
			}
			$('#ce-img-bulk-go').disabled = true;
			$$('.ce-img-select', el).forEach(function (c) { c.addEventListener('change', updateSelCount); });
			$('#ce-img-selall').addEventListener('change', function () {
				var checked = this.checked;
				missing.forEach(function (p) {
					var c = el.querySelector('.ce-img-select[value="' + p.post_id + '"]');
					if (c) { c.checked = checked; }
				});
				updateSelCount();
			});
			$('#ce-img-bulk-go').addEventListener('click', function () {
				var ids = $$('.ce-img-select:checked', el).map(function (c) { return parseInt(c.value, 10); });
				if (!ids.length) { return; }
				var source = $('#ce-img-bulk-source').value;
				var btn = $('#ce-img-bulk-go');
				btn.disabled = true;
				api('images_queue_add', { post_ids: JSON.stringify(ids), source: source }).then(function (r) {
					toast(r.added + ' imagem(ns) na fila de geração');
					gotoTab('queue');
				}).catch(function (e) { btn.disabled = false; toast(e.message, true); });
			});
		}).catch(function (e) { el.innerHTML = '<div class="ce-empty"><p>' + esc(e.message) + '</p></div>'; });
	}

	function requireImageKey() {
		var s = CE61.settings;
		var prov = s.image_provider || 'openai';
		if (s.has_key && s.has_key[prov]) { return true; }
		var names = { openai: 'OpenAI', gemini: 'Google (Gemini)' };
		modal('<h3 class="ce-h2">Falta a chave para gerar imagens com IA</h3>' +
			'<p class="ce-sub">O provedor de imagens configurado é <b>' + (names[prov] || prov) + '</b>, mas a chave dele não foi salva. A Anthropic não gera imagens; use OpenAI ou Gemini — ou busque em um banco de imagens, que não precisa disso.</p>' +
			'<p><button class="ce-btn ce-btn-primary" id="ce-goto-settings">Ir para Configurações</button></p>');
		$('#ce-goto-settings').addEventListener('click', function () { closeModal(); gotoTab('settings'); });
		return false;
	}

	function updateImageCard(pid, url, thumb) {
		var cardImg = $('#ce-imgcard-' + pid);
		if (!cardImg) { return; }
		var body = cardImg.querySelector('.ce-imgcard-body');
		var checkbox = cardImg.querySelector('.ce-imgcard-check');
		cardImg.innerHTML = (checkbox ? checkbox.outerHTML : '') +
			'<img class="ce-imgcard-thumb" src="' + esc(thumb || url) + '" alt="" data-imgview="' + esc(url) + '">' + (body ? body.outerHTML : '');
		var genBtn = cardImg.querySelector('[data-imggen]');
		if (genBtn) { genBtn.textContent = '✦ Recriar imagem'; }
	}

	function providerBadge(provider) {
		var names = { unsplash: 'Unsplash', pexels: 'Pexels', pixabay: 'Pixabay', openverse: 'Openverse' };
		return '<span class="ce-chip ce-chip-kw">' + (names[provider] || provider) + '</span>';
	}

	/* Modal de geração com duas abas: Banco de imagens (padrão) e IA. */
	function runImageGen(btn) {
		var pid = btn.dataset.imggen;
		var title = btn.dataset.title;
		var defaultSource = (CE61.settings.image_source_default === 'ai') ? 'ai' : 'stock';

		function shell(activeTab) {
			return '<h3 class="ce-h2">Imagem destacada — ' + esc(title) + '</h3>' +
				'<div class="ce-tabs" style="margin-bottom:14px"><button class="ce-tab' + (activeTab === 'stock' ? ' is-active' : '') + '" id="ce-imgmode-stock" type="button">🖼 Banco de imagens</button>' +
				'<button class="ce-tab' + (activeTab === 'ai' ? ' is-active' : '') + '" id="ce-imgmode-ai" type="button">✦ Gerar com IA</button></div>' +
				'<div id="ce-imgmode-body"></div>';
		}

		function showStock() {
			$('#ce-imgmode-body').innerHTML =
				'<div class="ce-field" style="display:flex;gap:8px;margin-bottom:10px">' +
					'<input class="ce-input" id="ce-stock-q" value="' + esc(title) + '" style="flex:1">' +
					'<select class="ce-select" id="ce-stock-provider" style="max-width:170px">' +
						'<option value="auto">Automático</option>' +
						'<option value="unsplash">Unsplash</option>' +
						'<option value="pexels">Pexels</option>' +
						'<option value="pixabay">Pixabay</option>' +
						'<option value="openverse">Openverse</option>' +
					'</select>' +
					'<button class="ce-btn ce-btn-primary" id="ce-stock-go">Buscar</button>' +
				'</div><div id="ce-stock-out"></div>';
			$('#ce-stock-go').addEventListener('click', doStockSearch);
			$('#ce-stock-q').addEventListener('keydown', function (e) { if (e.key === 'Enter') { doStockSearch(); } });
			doStockSearch();
		}

		function doStockSearch() {
			var q = $('#ce-stock-q').value.trim();
			var provider = $('#ce-stock-provider').value;
			var out = $('#ce-stock-out');
			if (!q) { return; }
			out.innerHTML = '<div class="ce-loading">Buscando imagens</div>';
			api('stock_search', { provider: provider, query: q }).then(function (r) {
				if (!r.results.length) { out.innerHTML = '<p class="ce-sub">Nenhum resultado. Tente outro termo ou provedor.</p>'; return; }
				out.innerHTML = '<p class="ce-sub" style="margin:0 0 10px">Resultados via ' + providerBadge(r.provider) + '</p>' +
					'<div class="ce-imggrid">' + r.results.map(function (img, i) {
						return '<div class="ce-imgcard" style="cursor:pointer" data-stockpick="' + i + '">' +
							'<img class="ce-imgcard-thumb" src="' + esc(img.thumb) + '" alt="">' +
							'<div class="ce-imgcard-body"><p class="ce-imgcard-title" style="margin:0">' + esc(img.credit || '') + '</p></div>' +
						'</div>';
					}).join('') + '</div>';
				$$('[data-stockpick]', out).forEach(function (c) {
					c.addEventListener('click', function () {
						var img = r.results[c.dataset.stockpick];
						out.innerHTML = '<div class="ce-loading">Aplicando imagem</div>';
						api('stock_apply', { post_id: pid, image: JSON.stringify(img) }).then(function (ap) {
							toast('Imagem aplicada (' + providerBadge(img.provider).replace(/<[^>]+>/g, '') + ')');
							updateImageCard(pid, ap.url, ap.thumb);
							closeModal();
						}).catch(function (e) {
							out.innerHTML = '<p class="ce-sub">' + esc(e.message) + '</p>';
						});
					});
				});
			}).catch(function (e) {
				out.innerHTML = '<p class="ce-sub">' + esc(e.message) +
					(e.message.indexOf('chave') > -1 ? ' <a href="' + esc(CE61.pages.settings) + '&ce-tab=integrations">Configurar</a>' : '') + '</p>';
			});
		}

		function showAi() {
			$('#ce-imgmode-body').innerHTML = '<div class="ce-loading">Montando o prompt com os dados do post</div>';
			if (!requireImageKey()) { return; }
			var provider = CE61.settings.image_provider || 'openai';
			var models = CE61.imageCatalog[provider] || {};
			var defaultModel = CE61.settings.image_model && models[CE61.settings.image_model] ? CE61.settings.image_model : Object.keys(models)[0];
			var quickControls =
				'<div class="ce-grid" style="grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">' +
					'<div class="ce-field" style="margin:0"><label>Modelo</label><select class="ce-select" id="ce-img-quick-model">' +
						Object.keys(models).map(function (k) { return '<option value="' + k + '"' + (k === defaultModel ? ' selected' : '') + '>' + esc(models[k].label) + '</option>'; }).join('') +
					'</select></div>' +
					'<div class="ce-field" style="margin:0"><label>Proporção</label><select class="ce-select" id="ce-img-quick-aspect">' +
						Object.keys(CE61.imageAspectRatios).map(function (k) { return '<option value="' + k + '"' + (k === CE61.settings.image_aspect ? ' selected' : '') + '>' + esc(CE61.imageAspectRatios[k].label) + '</option>'; }).join('') +
					'</select></div>' +
				'</div>' +
				'<div class="ce-field" style="margin-bottom:10px"><label>Estilo para esta imagem</label><div class="ce-grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:2px 12px">' +
					Object.keys(CE61.imageStylePresets).map(function (k) {
						var checked = CE61.settings.image_style_presets.indexOf(k) > -1 ? ' checked' : '';
						return '<label class="ce-check"><input type="checkbox" class="ce-img-quick-preset" value="' + k + '"' + checked + '> ' + esc(CE61.imageStylePresets[k].label) + '</label>';
					}).join('') +
				'</div></div>';

			function refreshPrompt() {
				var presets = $$('.ce-img-quick-preset:checked').map(function (c) { return c.value; });
				return api('image_prompt', { post_id: pid, style_presets: JSON.stringify(presets), aspect: $('#ce-img-quick-aspect').value });
			}

			refreshPrompt().then(function (d) {
				$('#ce-imgmode-body').innerHTML =
					quickControls +
					'<p class="ce-sub">O prompt já inclui o modelo, proporção e estilos marcados acima — ajuste e gere, ou edite o texto livremente.</p>' +
					'<div class="ce-field"><textarea class="ce-textarea" id="ce-img-prompt" style="min-height:150px">' + esc(d.prompt) + '</textarea></div>' +
					'<p><button class="ce-btn ce-btn-ghost" id="ce-img-refresh-prompt" type="button">↻ Atualizar prompt com essas opções</button></p>' +
					'<p><button class="ce-btn ce-btn-primary" id="ce-img-go">✦ Gerar imagem</button></p>' +
					'<div id="ce-img-out"></div>';

				$('#ce-img-refresh-prompt').addEventListener('click', function () {
					this.disabled = true;
					refreshPrompt().then(function (d2) {
						$('#ce-img-prompt').value = d2.prompt;
						$('#ce-img-refresh-prompt').disabled = false;
					}).catch(function (e) { toast(e.message, true); $('#ce-img-refresh-prompt').disabled = false; });
				});

				$('#ce-img-go').addEventListener('click', function () {
					var out = $('#ce-img-out');
					var btn = $('#ce-img-go');
					btn.disabled = true;
					var ctrl = new AbortController();
					_activeController = ctrl;
					out.innerHTML =
						'<div class="ce-loading">Gerando imagem, pode levar até 1 min\u2026</div>' +
						'<p style="margin-top:8px"><button class="ce-btn ce-btn-sm ce-btn-ghost" id="ce-img-cancel">✕ Cancelar</button></p>';
					$('#ce-img-cancel').addEventListener('click', function () { ctrl.abort(); });
					api('image_generate', {
						post_id: pid,
						prompt: $('#ce-img-prompt').value,
						model: $('#ce-img-quick-model').value,
						aspect: $('#ce-img-quick-aspect').value
					}, { signal: ctrl.signal, timeout: 90000 }).then(function (r) {
						if (_activeController !== ctrl || !$('#ce-img-out')) { return; }
						_activeController = null;
						out.innerHTML =
							'<p><span class="ce-chip ce-chip-green">Imagem aplicada como destacada</span></p>' +
							'<img src="' + esc(r.url) + '" alt="" style="max-width:100%;border-radius:12px;border:1px solid var(--ce-line)">' +
							'<p style="margin-top:10px"><button class="ce-btn ce-btn-sm" id="ce-img-retry">↻ Gerar outra versão</button></p>';
						toast('Imagem destacada atualizada');
						updateImageCard(pid, r.url, r.thumb);
						$('#ce-img-retry').addEventListener('click', function () {
							var goBtn = $('#ce-img-go');
							if (goBtn) { goBtn.disabled = false; }
							out.innerHTML = '';
						});
					}).catch(function (e) {
						if (_activeController !== ctrl) { return; }
						_activeController = null;
						var goBtn = $('#ce-img-go');
						if (goBtn) { goBtn.disabled = false; }
						var outEl = $('#ce-img-out');
						if (!outEl) { return; }
						outEl.innerHTML = '<p>' + esc(e.message) + '</p>';
					});
				});
			}).catch(function (e) {
				$('#ce-imgmode-body').innerHTML = '<p class="ce-sub">' + esc(e.message) + '</p>';
			});
		}

		modal(shell(defaultSource));
		$('#ce-imgmode-stock').addEventListener('click', function () {
			this.classList.add('is-active'); $('#ce-imgmode-ai').classList.remove('is-active');
			showStock();
		});
		$('#ce-imgmode-ai').addEventListener('click', function () {
			this.classList.add('is-active'); $('#ce-imgmode-stock').classList.remove('is-active');
			showAi();
		});
		if ('ai' === defaultSource) { showAi(); } else { showStock(); }
	}

	/* ---------- AI & Prompts ---------- */
	function renderAI() {
		var el = $('#ce-panel-ai');
		var vars = ['site_name', 'site_url', 'today', 'title', 'url', 'excerpt', 'content', 'first_paragraph', 'keyword', 'keywords_list', 'category', 'author_name', 'author_bio', 'publish_date', 'gap_topic', 'cluster_name', 'pillar_title', 'pillar_url', 'pillar_keyword', 'custom_instructions', 'internal_links_list', 'source_paragraph', 'target_title', 'target_keyword', 'title_b', 'content_b', 'url_b', 'cluster_posts', 'site_context', 'cluster_description', 'count', 'headings_list', 'report_data', 'user_prompt'];
		var chips = vars.map(function (v) { return '<code title="Clique para copiar">{{' + v + '}}</code>'; }).join('');
		var fields = Object.keys(CE61.prompts).map(function (k) {
			return '<div class="ce-field"><label>' + esc(CE61.prompts[k].label) +
				' <button class="ce-btn ce-btn-sm ce-btn-ghost" type="button" data-reset-prompt="' + k + '" title="Restaura o texto padrão de fábrica desta ação">↺ Restaurar padrão</button></label>' +
				'<textarea class="ce-textarea" data-prompt="' + k + '">' + esc(CE61.prompts[k].prompt) + '</textarea></div>';
		}).join('');
		el.innerHTML =
			'<div class="ce-section"><h2 class="ce-h2">Biblioteca de prompts</h2>' +
			'<p class="ce-sub">Cada ação do plugin usa um destes prompts. As variáveis abaixo são resolvidas em tempo real com dados do próprio WordPress; clique para copiar.</p>' +
			'<div class="ce-card"><p class="ce-kpi-label">Variáveis disponíveis</p><div class="ce-vars">' + chips + '</div></div></div>' +
			'<div class="ce-card">' + fields +
			'<p><button class="ce-btn ce-btn-primary" id="ce-save-prompts">Salvar prompts</button></p></div>';
		$$('.ce-vars code', el).forEach(function (c) {
			c.addEventListener('click', function () {
				navigator.clipboard.writeText(c.textContent).then(function () { toast('Variável copiada'); });
			});
		});
		$$('[data-reset-prompt]', el).forEach(function (b) {
			b.addEventListener('click', function () {
				if (!window.confirm('Restaurar o texto padrão de fábrica desta ação? Suas edições neste campo serão perdidas.')) { return; }
				api('reset_prompt', { key: b.dataset.resetPrompt }).then(function (r) {
					var ta = el.querySelector('[data-prompt="' + b.dataset.resetPrompt + '"]');
					if (ta) { ta.value = r.prompt; }
					if (CE61.prompts[b.dataset.resetPrompt]) { CE61.prompts[b.dataset.resetPrompt].prompt = r.prompt; }
					toast('Prompt restaurado ao padrão');
				}).catch(function (e) { toast(e.message, true); });
			});
		});
		$('#ce-save-prompts').addEventListener('click', function () {
			var prompts = {};
			$$('[data-prompt]', el).forEach(function (t) { prompts[t.dataset.prompt] = t.value; });
			api('save_prompts', { prompts: prompts }).then(function () {
				Object.keys(prompts).forEach(function (k) { if (CE61.prompts[k]) { CE61.prompts[k].prompt = prompts[k]; } });
				toast('Prompts salvos');
			}).catch(function (e) { toast(e.message, true); });
		});
	}

	/* ---------- Criação de Conteúdo (novos clusters + geração) ---------- */
	function topicRow(c, t, i) {
		var done = t.status === 'done';
		var hasBrief = (t.faqs && t.faqs.length) || (t.subtopics && t.subtopics.length);
		var briefChips = (t.word_count ? '<span class="ce-chip ce-chip-kw">~' + t.word_count + ' palavras</span> ' : '') +
			(t.h2_count ? '<span class="ce-chip ce-chip-kw">' + t.h2_count + ' H2</span> ' : '') +
			(t.faqs && t.faqs.length ? '<span class="ce-chip ce-chip-kw">' + t.faqs.length + ' FAQs</span>' : '');
		return '<tr>' +
			'<td style="width:24px">' + (done ? '✓' : '<input type="checkbox" class="ce-topic-check" data-i="' + i + '">') + '</td>' +
			'<td>' + (done && t.edit ? '<a href="' + esc(t.edit) + '" target="_blank" rel="noopener">' + esc(t.title) + '</a>' : esc(t.title)) +
				(t.type === 'pillar' ? ' <span class="ce-chip">pilar</span>' : '') +
				(done ? ' <span class="ce-chip ce-chip-green">rascunho criado</span>' : '') +
				(briefChips ? '<br>' + briefChips : '') + '</td>' +
			'<td class="ce-sub">' + esc(t.keyword || '') + '</td>' +
			'<td style="white-space:nowrap;text-align:right">' +
				(hasBrief ? '<button class="ce-btn ce-btn-sm ce-btn-ghost" data-viewbrief="' + i + '" title="Ver briefing editorial">📋 Briefing</button> ' : '') +
				(!done ? '<button class="ce-btn ce-btn-sm" data-gennow="' + i + '">✦ Gerar agora</button> ' : '') +
				'<button class="ce-btn ce-btn-sm ce-btn-ghost" data-deltopic="' + i + '" title="Remover do plano">✕</button>' +
			'</td></tr>';
	}

	function openBriefModal(t) {
		var faqs = (t.faqs || []).map(function (q) { return '<li>' + esc(q) + '</li>'; }).join('');
		var subs = (t.subtopics || []).map(function (s) { return '<li>' + esc(s) + '</li>'; }).join('');
		modal(
			'<h3 class="ce-h2">Briefing — ' + esc(t.title) + '</h3>' +
			'<p class="ce-sub">Sugerido pela IA com base no próprio conhecimento dela sobre o tema (não é busca ao vivo no Google) — use como ponto de partida e ajuste se souber de algo mais específico do seu público.</p>' +
			'<p>' + (t.word_count ? '<span class="ce-chip ce-chip-kw">~' + t.word_count + ' palavras</span> ' : '') + (t.h2_count ? '<span class="ce-chip ce-chip-kw">' + t.h2_count + ' subtítulos H2</span>' : '') + '</p>' +
			(subs ? '<p class="ce-kpi-label" style="margin-top:14px">Subtemas a cobrir</p><ul class="ce-eeat-list">' + subs + '</ul>' : '') +
			(faqs ? '<p class="ce-kpi-label" style="margin-top:14px">Perguntas frequentes sugeridas</p><ul class="ce-eeat-list">' + faqs + '</ul>' : '')
		);
	}

	/* Modal de configuração de profundidade antes de planejar um cluster com IA. */
	function openPlanConfigModal(clusterId, onGo) {
		modal(
			'<h3 class="ce-h2">Planejar conteúdo com IA</h3>' +
			'<p class="ce-sub">A IA sugere os artigos, e para cada um monta um briefing com FAQs e subtemas (baseado no conhecimento dela — não é busca ao vivo no Google).</p>' +
			'<div class="ce-field"><label>Número de artigos</label><input class="ce-input" type="number" min="1" max="12" value="6" id="ce-plan-count"></div>' +
			'<div class="ce-field"><label>Profundidade de cada artigo</label><select class="ce-select" id="ce-plan-depth">' +
				'<option value="800:4">Padrão (~800 palavras, 4 H2)</option>' +
				'<option value="1200:6" selected>Aprofundado (~1200 palavras, 6 H2)</option>' +
				'<option value="2000:8">Completo (~2000 palavras, 8 H2)</option>' +
				'<option value="3000:10">Pilar extenso (~3000 palavras, 10 H2)</option>' +
			'</select></div>' +
			'<p><button class="ce-btn ce-btn-primary" id="ce-plan-go">✦ Planejar com estes parâmetros</button></p>'
		);
		$('#ce-plan-go').addEventListener('click', function () {
			var parts = $('#ce-plan-depth').value.split(':');
			closeModal();
			onGo({
				count: $('#ce-plan-count').value,
				word_count_target: parts[0],
				h2_count_target: parts[1]
			});
		});
	}

	function clusterCard(c) {
		var terms = (c.top_terms || []).slice(0, 5).map(function (t) { return '<span class="ce-chip ce-chip-kw">' + esc(t) + '</span>'; }).join(' ');
		var plan = c.planned || [];
		var planHtml = plan.length
			? '<table class="ce-table" style="margin-top:10px"><tbody>' + plan.map(function (t, i) { return topicRow(c, t, i); }).join('') + '</tbody></table>' +
				'<p style="margin:10px 0 0">' +
				'<button class="ce-btn ce-btn-sm" data-selall="1">Selecionar todos</button> ' +
				'<button class="ce-btn ce-btn-sm ce-btn-primary" data-queue="1">⧗ Gerar selecionados em massa (fila)</button>' +
				'</p>'
			: '<p class="ce-sub" style="margin:10px 0 0">Nenhum tópico planejado ainda. Use “Planejar conteúdo (IA)” para a IA sugerir artigos com base no que o site já publicou.</p>';
		return '<div class="ce-card" style="margin-bottom:14px" data-cluster="' + c.id + '">' +
			'<div class="ce-cluster-head">' +
				'<h3 style="font-family:var(--ce-display);margin:0">' + esc(c.name) + '</h3>' +
				(c.is_custom == 1 ? '<span class="ce-chip">criado manualmente</span>' : '<span class="ce-chip">detectado no scan</span>') +
				'<span class="ce-chip ce-chip-kw">' + (c.post_count || 0) + ' posts publicados</span>' +
			'</div>' +
			(c.description ? '<p class="ce-sub" style="margin:6px 0 0">' + esc(c.description) + '</p>' : '') +
			(c.pillar_title ? '<p class="ce-sub" style="margin:4px 0 0">Pilar: ' + esc(c.pillar_title) + '</p>' : '') +
			(terms ? '<p style="margin:8px 0 0">' + terms + '</p>' : '') +
			'<p style="margin:12px 0 0">' +
				'<button class="ce-btn ce-btn-sm" data-plan="' + c.id + '">✍ Planejar conteúdo (IA)</button> ' +
				(plan.length ? '<button class="ce-btn ce-btn-sm ce-btn-ghost" data-plan="' + c.id + '" data-more="1">+ Incrementar plano</button> ' : '') +
				(c.is_custom == 1 ? '<button class="ce-btn ce-btn-sm ce-btn-ghost" data-delcluster="' + c.id + '">Excluir cluster</button>' : '') +
			'</p>' +
			'<div class="ce-cluster-plan">' + planHtml + '</div>' +
		'</div>';
	}

	/* ---------- E-E-A-T e status de publicação (compartilhado) ---------- */
	function scoresBlock(scores) {
		if (!scores) { return ''; }
		function one(key, title, hint) {
			var s = scores[key];
			if (!s) { return ''; }
			var issues = (s.issues || []).map(function (i) {
				return '<li>' + esc(i.label) + ' <span class="ce-sub">(−' + i.weight + ' pts)</span></li>';
			}).join('');
			return '<div class="ce-eeat-col">' +
				'<p class="ce-kpi-label" style="margin-bottom:6px">' + title + '</p>' +
				scoreBar(s.score) +
				'<p class="ce-sub" style="margin:6px 0 0">' + hint + '</p>' +
				(issues ? '<ul class="ce-eeat-list">' + issues + '</ul>' : '<p class="ce-sub" style="margin:8px 0 0">Nenhum ajuste crítico. 🎯</p>') +
				'</div>';
		}
		return '<div class="ce-eeat">' +
			'<div class="ce-eeat-grid">' +
				one('eeat', 'E-E-A-T', 'Experiência, Expertise, Autoridade, Confiança') +
				one('aeo', 'AEO', 'Prontidão para respostas diretas e People Also Ask') +
				one('geo', 'GEO', 'Prontidão para ser citado por IA generativa') +
			'</div></div>';
	}

	function statusChip(status, date) {
		var map = {
			draft:   ['Rascunho', 'ce-chip'],
			publish: ['Publicado', 'ce-chip ce-chip-green'],
			future:  ['Agendado', 'ce-chip ce-chip-amber']
		};
		var m = map[status] || [status, 'ce-chip'];
		return '<span class="' + m[1] + '">' + m[0] + '</span>' + (date ? ' <span class="ce-sub">' + esc(date) + '</span>' : '');
	}

	/**
	 * Modal para publicar agora ou agendar um post (reaproveitado no card de
	 * geração avulsa e na lista de artigos gerados por IA).
	 */
	function openPublishModal(postId, onDone) {
		modal(
			'<h3 class="ce-h2">Publicação do artigo</h3>' +
			'<div class="ce-field"><label>O que fazer</label><select class="ce-select" id="ce-pub-mode">' +
				'<option value="draft">Manter como rascunho</option>' +
				'<option value="publish">Publicar agora</option>' +
				'<option value="schedule">Agendar</option>' +
			'</select></div>' +
			'<div class="ce-field" id="ce-pub-when-wrap" hidden><label>Data e hora</label><input class="ce-input" type="datetime-local" id="ce-pub-when"></div>' +
			'<p><button class="ce-btn ce-btn-primary" id="ce-pub-go">Confirmar</button></p>' +
			'<div id="ce-pub-out"></div>'
		);
		$('#ce-pub-mode').addEventListener('change', function () {
			$('#ce-pub-when-wrap').hidden = (this.value !== 'schedule');
		});
		$('#ce-pub-go').addEventListener('click', function () {
			var mode = $('#ce-pub-mode').value;
			var when = $('#ce-pub-when').value ? $('#ce-pub-when').value.replace('T', ' ') + ':00' : '';
			if ('schedule' === mode && !when) { toast('Escolha data e hora para agendar', true); return; }
			var out = $('#ce-pub-out');
			$('#ce-pub-go').disabled = true;
			out.innerHTML = '<div class="ce-loading">Aplicando</div>';
			api('set_publish', { post_id: postId, publish: mode, schedule_at: when }).then(function (r) {
				out.innerHTML = '<p class="ce-sub">Status atualizado: ' + statusChip(r.status, when) + '</p>';
				toast('Publicação atualizada');
				if (onDone) { onDone(r.status); }
				setTimeout(closeModal, 900);
			}).catch(function (e) {
				$('#ce-pub-go').disabled = false;
				out.innerHTML = '<p class="ce-sub">' + esc(e.message) + '</p>';
			});
		});
	}

	function renderCreator() {
		var el = $('#ce-panel-creator');
		el.innerHTML = '<div class="ce-loading">Carregando clusters</div>';
		api('creator_data', {}).then(function (d) {
			var custom = d.clusters.filter(function (c) { return c.is_custom == 1; });
			var auto = d.clusters.filter(function (c) { return c.is_custom != 1; });
			el.innerHTML =
				'<div class="ce-section">' +
					'<h2 class="ce-h2">Gerar artigo avulso</h2>' +
					'<p class="ce-sub">Escreva o título e a palavra-chave foco; se quiser, escreva instruções extras para a IA — ou deixe em branco e use "Melhorar prompt" para gerar um roteiro melhor automaticamente.</p>' +
					'<div class="ce-card">' +
						'<div class="ce-grid" style="grid-template-columns:2fr 2fr;gap:10px">' +
							'<div class="ce-field" style="margin:0"><label>Título do artigo</label><input class="ce-input" id="ce-gen-title" placeholder="ex.: Como reduzir faltas em clínicas com lembretes automáticos"></div>' +
							'<div class="ce-field" style="margin:0"><label>Palavra-chave foco</label><input class="ce-input" id="ce-gen-kw" placeholder="ex.: lembrete automático clínica"></div>' +
						'</div>' +
						'<div class="ce-field"><label>Cluster (opcional)</label><select class="ce-select" id="ce-gen-cluster"><option value="0">Nenhum / avulso</option>' +
							d.clusters.map(function (c) { return '<option value="' + c.id + '">' + esc(c.name) + '</option>'; }).join('') +
						'</select></div>' +
						'<div class="ce-field"><label>Prompt / instruções para a IA</label>' +
							'<textarea class="ce-textarea" id="ce-gen-prompt" style="min-height:110px" placeholder="Deixe em branco para usar as instruções padrão, ou escreva o que este artigo precisa cobrir, tom de voz, exemplos a incluir…"></textarea>' +
							'<p class="ce-hint"><button class="ce-btn ce-btn-sm ce-btn-ghost" id="ce-gen-improve" type="button">✍ Melhorar prompt com IA</button></p>' +
						'</div>' +
						'<div class="ce-grid" style="grid-template-columns:1fr 1fr;gap:10px;align-items:start">' +
							'<div class="ce-field" style="margin:0"><label>Publicação</label><select class="ce-select" id="ce-gen-publish">' +
								'<option value="draft">Salvar como rascunho</option>' +
								'<option value="publish">Publicar imediatamente</option>' +
								'<option value="schedule">Agendar</option>' +
							'</select></div>' +
							'<div class="ce-field" style="margin:0" id="ce-gen-schedule-wrap" hidden><label>Data e hora</label><input class="ce-input" type="datetime-local" id="ce-gen-schedule"></div>' +
						'</div>' +
						'<p><button class="ce-btn ce-btn-primary" id="ce-gen-go">✦ Gerar artigo</button></p>' +
						'<div id="ce-gen-out"></div>' +
					'</div>' +
				'</div>' +
				'<div class="ce-section">' +
					'<h2 class="ce-h2">Criar novos clusters</h2>' +
					'<p class="ce-sub">O plugin lê o que o site já publicou (clusters, keywords e títulos) para a IA entender o assunto e sugerir novos temas coerentes. Você também pode criar um cluster manualmente.</p>' +
					(d.indexed ? '' : '<div class="ce-card"><p style="margin:0">⚠ O site ainda não foi escaneado. <a href="' + esc(CE61.pages.main) + '">Escaneie o site primeiro</a> para a IA conhecer o conteúdo existente.</p></div>') +
					'<div class="ce-card">' +
						'<div class="ce-grid" style="grid-template-columns:2fr 3fr 2fr auto;align-items:end;gap:10px">' +
							'<div class="ce-field" style="margin:0"><label>Nome do cluster</label><input class="ce-input" id="ce-nc-name" placeholder="ex.: Marketing para clínicas"></div>' +
							'<div class="ce-field" style="margin:0"><label>Descrição (o que o cluster cobre)</label><input class="ce-input" id="ce-nc-desc" placeholder="opcional"></div>' +
							'<div class="ce-field" style="margin:0"><label>Keyword do pilar</label><input class="ce-input" id="ce-nc-kw" placeholder="opcional"></div>' +
							'<button class="ce-btn ce-btn-primary" id="ce-nc-create">+ Criar cluster</button>' +
						'</div>' +
						'<p style="margin:14px 0 0"><button class="ce-btn" id="ce-suggest-btn">✦ Sugerir novos clusters com IA (com base no site)</button></p>' +
						'<div id="ce-suggest-out"></div>' +
					'</div>' +
				'</div>' +
				'<div class="ce-section"><h2 class="ce-h2">Seus clusters e planos de conteúdo</h2>' +
				'<p class="ce-sub">Para cada cluster, a IA planeja artigos que faltam (pilar e satélites), sempre com base no conteúdo real do site. Gere um por vez ou envie vários para a fila de geração em massa.</p>' +
				'<div id="ce-creator-list">' +
					(d.clusters.length ? custom.concat(auto).map(clusterCard).join('') : '<div class="ce-empty"><p>Nenhum cluster ainda. Crie um acima ou escaneie o site.</p></div>') +
				'</div></div>' +
				'<div class="ce-section"><h2 class="ce-h2">Artigos gerados por IA</h2>' +
				'<p class="ce-sub">Todo artigo criado pelo Cluster Engine aparece aqui, com status e nota de E-E-A-T.</p>' +
				'<div id="ce-ai-posts"><div class="ce-loading">Carregando artigos gerados</div></div></div>';

			$('#ce-nc-create').addEventListener('click', function () {
				var name = $('#ce-nc-name').value.trim();
				if (!name) { toast('Informe o nome do cluster', true); return; }
				api('create_cluster', { name: name, description: $('#ce-nc-desc').value, keyword: $('#ce-nc-kw').value }).then(function () {
					toast('Cluster criado');
					loadPanel('creator', true);
				}).catch(function (e) { toast(e.message, true); });
			});

			$('#ce-suggest-btn').addEventListener('click', function () {
				if (!requireKey()) { return; }
				var btn = $('#ce-suggest-btn'), out = $('#ce-suggest-out');
				btn.disabled = true;
				out.innerHTML = '<div class="ce-loading">A IA está analisando o conteúdo do site</div>';
				api('suggest_clusters', { count: 5 }).then(function (r) {
					btn.disabled = false;
					if (!r.suggestions.length) { out.innerHTML = '<p class="ce-sub">Nenhuma sugestão retornada. Tente novamente.</p>'; return; }
					out.innerHTML = '<div class="ce-grid" style="grid-template-columns:repeat(auto-fill,minmax(280px,1fr));margin-top:14px">' +
						r.suggestions.map(function (sg, i) {
							return '<div class="ce-card">' +
								'<h3 style="font-family:var(--ce-display);margin:0 0 6px">' + esc(sg.name) + '</h3>' +
								'<p class="ce-sub" style="margin:0 0 6px">' + esc(sg.description) + '</p>' +
								(sg.keyword ? '<p style="margin:0 0 6px"><span class="ce-chip ce-chip-kw">' + esc(sg.keyword) + '</span></p>' : '') +
								(sg.rationale ? '<p class="ce-sub" style="margin:0 0 10px;font-style:italic">' + esc(sg.rationale) + '</p>' : '') +
								'<button class="ce-btn ce-btn-sm ce-btn-primary" data-accept="' + i + '">+ Criar este cluster</button>' +
							'</div>';
						}).join('') + '</div>';
					$$('[data-accept]', out).forEach(function (b) {
						b.addEventListener('click', function () {
							var sg = r.suggestions[b.dataset.accept];
							b.disabled = true;
							api('create_cluster', { name: sg.name, description: sg.description, keyword: sg.keyword }).then(function () {
								toast('Cluster “' + sg.name + '” criado');
								loadPanel('creator', true);
							}).catch(function (e) { b.disabled = false; toast(e.message, true); });
						});
					});
				}).catch(function (e) {
					btn.disabled = false;
					out.innerHTML = '<p class="ce-sub">' + esc(e.message) + '</p>';
				});
			});

			var schedSel = $('#ce-gen-publish');
			if (schedSel) {
				schedSel.addEventListener('change', function () {
					$('#ce-gen-schedule-wrap').hidden = (this.value !== 'schedule');
				});
			}
			var improveBtn = $('#ce-gen-improve');
			if (improveBtn) {
				improveBtn.addEventListener('click', function () {
					if (!requireKey()) { return; }
					var title = $('#ce-gen-title').value.trim();
					if (!title) { toast('Escreva o título do artigo primeiro', true); return; }
					improveBtn.disabled = true;
					improveBtn.textContent = 'Melhorando…';
					api('improve_prompt', { title: title, keyword: $('#ce-gen-kw').value.trim(), prompt: $('#ce-gen-prompt').value.trim() }).then(function (r) {
						improveBtn.disabled = false;
						improveBtn.textContent = '✍ Melhorar prompt com IA';
						$('#ce-gen-prompt').value = r.prompt;
						toast('Prompt melhorado — revise antes de gerar');
					}).catch(function (e) {
						improveBtn.disabled = false;
						improveBtn.textContent = '✍ Melhorar prompt com IA';
						toast(e.message, true);
					});
				});
			}
			var genBtn = $('#ce-gen-go');
			if (genBtn) {
				genBtn.addEventListener('click', function () {
					if (!requireKey()) { return; }
					var title = $('#ce-gen-title').value.trim();
					if (!title) { toast('Escreva o título do artigo', true); return; }
					var publish = $('#ce-gen-publish').value;
					var schedRaw = $('#ce-gen-schedule').value;
					if ('schedule' === publish && !schedRaw) { toast('Escolha data e hora para agendar', true); return; }
					var out = $('#ce-gen-out');
					genBtn.disabled = true;
					out.innerHTML = '<div class="ce-loading">Gerando o artigo (até 2 min)</div>';
					api('generate_now', {
						cluster_id: $('#ce-gen-cluster').value,
						title: title,
						keyword: $('#ce-gen-kw').value.trim(),
						custom_prompt: $('#ce-gen-prompt').value.trim(),
						publish: publish,
						schedule_at: schedRaw ? schedRaw.replace('T', ' ') + ':00' : ''
					}).then(function (r) {
						genBtn.disabled = false;
						out.innerHTML =
							'<div class="ce-card" style="margin-top:14px">' +
								'<p style="margin:0 0 10px">' + statusChip(r.status, '') + ' <b style="font-family:var(--ce-display)">' + esc(r.title) + '</b></p>' +
								'<p><a class="ce-btn ce-btn-sm" href="' + esc(r.edit) + '" target="_blank" rel="noopener">Abrir no editor</a></p>' +
								scoresBlock(r.scores) +
							'</div>';
						toast('Artigo gerado');
						loadAiPosts();
					}).catch(function (e) {
						genBtn.disabled = false;
						out.innerHTML = '<p class="ce-sub">' + esc(e.message) + '</p>';
					});
				});
			}

			bindClusterCards(d);
			loadAiPosts();
		}).catch(function (e) { el.innerHTML = '<div class="ce-empty"><p>' + esc(e.message) + '</p></div>'; });
	}

	function loadAiPosts() {
		var box = $('#ce-ai-posts');
		if (!box) { return; }
		api('ai_posts_list', {}).then(function (d) {
			if (!d.posts.length) {
				box.innerHTML = '<div class="ce-empty" style="padding:34px"><p>Nenhum artigo gerado ainda.</p></div>';
				return;
			}
			var rows = d.posts.map(function (p) {
				return '<tr>' +
					'<td><a href="' + esc(p.edit) + '" target="_blank" rel="noopener">' + esc(p.title) + '</a>' +
						(p.cluster_name ? '<br><span class="ce-chip ce-chip-kw">' + esc(p.cluster_name) + '</span>' : '') + '</td>' +
					'<td>' + statusChip(p.status, p.status === 'future' ? p.date : '') + '</td>' +
					'<td>' + scoresRow(p.scores) + '</td>' +
					'<td><span class="ce-chip ce-chip-kw">' + esc(p.keyword || '—') + '</span></td>' +
					'<td style="white-space:nowrap">' +
						'<button class="ce-btn ce-btn-sm ce-btn-ghost" data-eeatinfo="' + p.post_id + '">Ver ajustes</button> ' +
						'<button class="ce-btn ce-btn-sm ce-btn-ghost" data-pubctl="' + p.post_id + '">Publicar/Agendar</button> ' +
						'<button class="ce-btn ce-btn-sm ce-btn-ghost" data-kwctl="' + p.post_id + '" data-kwcur="' + esc(p.keyword || '') + '">⌖ Keyword</button> ' +
						'<a class="ce-btn ce-btn-sm ce-btn-ghost" href="' + esc(p.view) + '" target="_blank" rel="noopener">Ver</a>' +
					'</td>' +
				'</tr>';
			}).join('');
			box.innerHTML = '<div class="ce-card ce-table-wrap"><table class="ce-table"><thead><tr>' +
				'<th>Artigo</th><th>Status</th><th>Notas (E-E-A-T · AEO · GEO)</th><th>Keyword</th><th></th>' +
				'</tr></thead><tbody>' + rows + '</tbody></table></div>';

			$$('[data-eeatinfo]', box).forEach(function (b) {
				b.addEventListener('click', function () {
					var p = d.posts.filter(function (x) { return String(x.post_id) === b.dataset.eeatinfo; })[0];
					modal('<h3 class="ce-h2">' + esc(p.title) + '</h3>' + scoresBlock(p.scores) +
						'<p style="margin-top:14px"><button class="ce-btn ce-btn-sm" id="ce-eeat-recalc">↻ Recalcular</button></p>');
					$('#ce-eeat-recalc').addEventListener('click', function () {
						api('post_eeat', { post_id: p.post_id }).then(function (r) {
							modal('<h3 class="ce-h2">' + esc(p.title) + '</h3>' + scoresBlock(r.scores));
							loadAiPosts();
						});
					});
				});
			});
			$$('[data-pubctl]', box).forEach(function (b) {
				b.addEventListener('click', function () {
					openPublishModal(b.dataset.pubctl, function () { loadAiPosts(); });
				});
			});
			$$('[data-kwctl]', box).forEach(function (b) {
				b.addEventListener('click', function () {
					openKeywordModal(b.dataset.kwctl, b.dataset.kwcur, function () { loadAiPosts(); });
				});
			});
		}).catch(function (e) { box.innerHTML = '<div class="ce-empty"><p>' + esc(e.message) + '</p></div>'; });
	}

	function bindClusterCards(d) {
		var list = $('#ce-creator-list');
		if (!list) { return; }

		$$('[data-plan]', list).forEach(function (b) {
			b.addEventListener('click', function () {
				if (!requireKey()) { return; }
				openPlanConfigModal(b.dataset.plan, function (cfg) {
					b.disabled = true;
					b.textContent = 'Planejando…';
					api('cluster_plan', {
						cluster_id: b.dataset.plan,
						count: cfg.count,
						word_count_target: cfg.word_count_target,
						h2_count_target: cfg.h2_count_target
					}).then(function () {
						toast('Plano de conteúdo atualizado, com briefing por artigo');
						loadPanel('creator', true);
					}).catch(function (e) {
						b.disabled = false;
						b.textContent = '✍ Planejar conteúdo (IA)';
						toast(e.message, true);
					});
				});
			});
		});

		$$('[data-delcluster]', list).forEach(function (b) {
			b.addEventListener('click', function () {
				if (!window.confirm('Excluir este cluster e o plano de conteúdo dele? Os rascunhos já criados não são apagados.')) { return; }
				api('delete_cluster', { cluster_id: b.dataset.delcluster }).then(function () {
					toast('Cluster excluído');
					loadPanel('creator', true);
				}).catch(function (e) { toast(e.message, true); });
			});
		});

		$$('.ce-card[data-cluster]', list).forEach(function (card) {
			var cid = card.dataset.cluster;
			var cluster = null;
			d.clusters.some(function (c) { if (String(c.id) === cid) { cluster = c; return true; } return false; });
			if (!cluster) { return; }

			$$('[data-gennow]', card).forEach(function (b) {
				b.addEventListener('click', function () {
					if (!requireKey()) { return; }
					var t = cluster.planned[b.dataset.gennow];
					b.disabled = true;
					b.textContent = 'Gerando… (até 2 min)';
					api('generate_now', { cluster_id: cid, title: t.title, keyword: t.keyword || '' }).then(function (r) {
						toast('Rascunho criado');
						modal('<h3 class="ce-h2">Artigo gerado</h3><p class="ce-sub">“' + esc(r.title) + '” foi salvo com keyword foco, meta title e description preenchidos.</p>' +
							'<p>' + statusChip(r.status, '') + ' <a class="ce-btn ce-btn-sm" href="' + esc(r.edit) + '" target="_blank" rel="noopener">Abrir no editor</a> ' +
							'<button class="ce-btn ce-btn-sm ce-btn-ghost" id="ce-gennow-pub">Publicar/Agendar</button></p>' +
							scoresBlock(r.scores));
						var pubBtn = $('#ce-gennow-pub');
						if (pubBtn) {
							pubBtn.addEventListener('click', function () { openPublishModal(r.post_id, function () { loadAiPosts(); }); });
						}
						loadPanel('creator', true);
					}).catch(function (e) {
						b.disabled = false;
						b.textContent = '✦ Gerar agora';
						toast(e.message, true);
					});
				});
			});

			$$('[data-deltopic]', card).forEach(function (b) {
				b.addEventListener('click', function () {
					var t = cluster.planned[b.dataset.deltopic];
					api('remove_topic', { cluster_id: cid, title: t.title }).then(function () {
						loadPanel('creator', true);
					}).catch(function (e) { toast(e.message, true); });
				});
			});

			$$('[data-viewbrief]', card).forEach(function (b) {
				b.addEventListener('click', function () {
					openBriefModal(cluster.planned[b.dataset.viewbrief]);
				});
			});

			var selall = card.querySelector('[data-selall]');
			if (selall) {
				selall.addEventListener('click', function () {
					$$('.ce-topic-check', card).forEach(function (c) { c.checked = true; });
				});
			}

			var qbtn = card.querySelector('[data-queue]');
			if (qbtn) {
				qbtn.addEventListener('click', function () {
					if (!requireKey()) { return; }
					var topics = $$('.ce-topic-check', card).filter(function (c) { return c.checked; }).map(function (c) {
						var t = cluster.planned[c.dataset.i];
						return { title: t.title, keyword: t.keyword || '' };
					});
					if (!topics.length) { toast('Marque pelo menos um tópico', true); return; }
					qbtn.disabled = true;
					api('queue_add', { cluster_id: cid, topics: JSON.stringify(topics) }).then(function (r) {
						toast(r.added + ' artigo(s) na fila de geração');
						gotoTab('queue');
					}).catch(function (e) { qbtn.disabled = false; toast(e.message, true); });
				});
			}
		});
	}

	/* ---------- Fila de geração (WP-Cron) ---------- */
	var queueTimer = null;

	function queueStatusChip(st) {
		var map = {
			pending: ['Aguardando', 'ce-chip'],
			running: ['Executando…', 'ce-chip ce-chip-amber'],
			done: ['Concluído', 'ce-chip ce-chip-green'],
			error: ['Erro', 'ce-chip ce-chip-red'],
			cancelled: ['Cancelado', 'ce-chip']
		};
		var m = map[st] || [st, 'ce-chip'];
		return '<span class="' + m[1] + '">' + m[0] + '</span>';
	}

	function scoreMini(label, val) {
		val = parseInt(val, 10) || 0;
		var cls = val >= 75 ? 'ce-chip-green' : (val >= 45 ? 'ce-chip-amber' : 'ce-chip-red');
		return '<span class="ce-chip ' + cls + '" title="Escala 0-100">' + label + ' ' + val + '</span>';
	}

	function scoresRow(scores) {
		if (!scores) { return ''; }
		return scoreMini('E-E-A-T', scores.eeat ? scores.eeat.score : 0) + ' ' +
			scoreMini('AEO', scores.aeo ? scores.aeo.score : 0) + ' ' +
			scoreMini('GEO', scores.geo ? scores.geo.score : 0);
	}

	/* Botão pequeno para editar a keyword foco sem sair da tela (usado na fila e na lista de posts gerados). */
	function openKeywordModal(postId, current, onDone) {
		modal(
			'<h3 class="ce-h2">Palavra-chave foco</h3>' +
			'<div class="ce-field"><input class="ce-input" id="ce-kw-edit" value="' + esc(current || '') + '"></div>' +
			'<p><button class="ce-btn ce-btn-primary" id="ce-kw-go">Salvar</button> ' +
			'<button class="ce-btn ce-btn-ghost" id="ce-kw-ai" type="button">✦ Sugerir com IA</button></p>' +
			'<div id="ce-kw-out"></div>'
		);
		$('#ce-kw-go').addEventListener('click', function () {
			var v = $('#ce-kw-edit').value.trim();
			api('apply_meta', { post_id: postId, field: 'keyword', value: v }).then(function () {
				toast('Keyword atualizada');
				if (onDone) { onDone(v); }
				closeModal();
			}).catch(function (e) { toast(e.message, true); });
		});
		$('#ce-kw-ai').addEventListener('click', function () {
			if (!requireKey()) { return; }
			var out = $('#ce-kw-out');
			out.innerHTML = '<div class="ce-loading">Consultando o modelo</div>';
			api('ai_run', { ai_action: 'suggest_keyword', post_id: postId }).then(function (d) {
				var opts = d.result.split(/\n+/).map(function (l) { return l.replace(/^\s*\d+[\).\-]\s*/, '').trim(); }).filter(Boolean);
				out.innerHTML = opts.map(function (o) { return '<div class="ce-option" data-kwpick="' + esc(o) + '"><p>' + esc(o) + '</p></div>'; }).join('');
				$$('[data-kwpick]', out).forEach(function (op) {
					op.addEventListener('click', function () { $('#ce-kw-edit').value = op.dataset.kwpick; });
				});
			}).catch(function (e) { out.innerHTML = '<p class="ce-sub">' + esc(e.message) + '</p>'; });
		});
	}

	function renderQueue() {
		var el = $('#ce-panel-queue');
		el.innerHTML = '<div class="ce-loading">Carregando fila</div>';
		fetchQueue(el, true);
	}

	function fetchQueue(el, first) {
		api('queue_list', {}).then(function (d) {
			var c = d.counts;
			var active = c.pending + c.running;
			var rows = d.jobs.map(function (j) {
				var isImage = 'generate_image' === j.job_type;
				var title = j.payload && j.payload.title ? j.payload.title
					: (isImage && j.payload && j.payload.query ? j.payload.query : ('Job #' + j.id));
				var res = '';
				if (j.status === 'done' && j.result && isImage) {
					res = '<img src="' + esc(j.result.url) + '" alt="" style="width:56px;height:32px;object-fit:cover;border-radius:4px;vertical-align:middle;margin-right:6px">' +
						'<span class="ce-chip ce-chip-kw">' + esc(j.result.source || '') + '</span>';
				} else if (j.status === 'done' && j.result && j.result.edit) {
					var pid = j.result.post_id;
					res = '<a class="ce-btn ce-btn-sm ce-btn-ghost" href="' + esc(j.result.edit) + '" target="_blank" rel="noopener">Editar</a> ' +
						(j.result.view ? '<a class="ce-btn ce-btn-sm ce-btn-ghost" href="' + esc(j.result.view) + '" target="_blank" rel="noopener">Ver</a> ' : '') +
						'<button class="ce-btn ce-btn-sm" data-qpub="' + pid + '">Publicar/Agendar</button> ' +
						'<button class="ce-btn ce-btn-sm ce-btn-ghost" data-qkw="' + pid + '" data-kwcur="' + esc(j.result.keyword || '') + '">⌖ Keyword</button>';
				} else if (j.status === 'error') {
					res = '<button class="ce-btn ce-btn-sm" data-retry="' + j.id + '">↻ Tentar de novo</button>';
				}
				var cancel = (j.status === 'pending' || j.status === 'error')
					? ' <button class="ce-btn ce-btn-sm ce-btn-ghost" data-cancel="' + j.id + '">✕</button>' : '';
				var scores = ( j.status === 'done' && j.result && j.result.scores ) ? '<br>' + scoresRow(j.result.scores) : '';
				return '<tr>' +
					'<td style="width:40px" class="ce-sub">#' + j.id + '</td>' +
					'<td>' + (isImage ? '🖼 ' : '') + esc(title) + scores + (j.error ? '<br><span class="ce-sub" style="color:var(--ce-red,#c0392b)">' + esc(j.error) + '</span>' : '') + '</td>' +
					'<td style="width:130px">' + queueStatusChip(j.status) + '</td>' +
					'<td class="ce-sub" style="width:150px">' + esc(j.created_at || '') + '</td>' +
					'<td style="white-space:nowrap;text-align:right">' + res + cancel + '</td>' +
				'</tr>';
			}).join('');

			el.innerHTML =
				'<div class="ce-section">' +
					'<h2 class="ce-h2">Fila de geração em massa</h2>' +
					'<p class="ce-sub">Os artigos enfileirados são gerados em segundo plano pelo cron do WordPress (a cada minuto, 2 por vez). Pode fechar esta tela; os rascunhos aparecem em Posts conforme ficam prontos.</p>' +
					(d.cron_off ? '<div class="ce-card" style="margin-bottom:12px"><p style="margin:0">⚠ <b>DISABLE_WP_CRON está ativo</b> neste site. A fila só andará se houver um cron real do servidor chamando wp-cron.php, ou use o botão “Processar próximo agora”.</p></div>' : '') +
					'<div class="ce-grid ce-grid-kpi" style="margin-bottom:14px">' +
						'<div class="ce-card"><p class="ce-kpi-label">Aguardando</p><p class="ce-kpi-value">' + c.pending + '</p></div>' +
						'<div class="ce-card"><p class="ce-kpi-label">Executando</p><p class="ce-kpi-value">' + c.running + '</p></div>' +
						'<div class="ce-card"><p class="ce-kpi-label">Concluídos</p><p class="ce-kpi-value">' + c.done + '</p></div>' +
						'<div class="ce-card"><p class="ce-kpi-label">Erros</p><p class="ce-kpi-value">' + c.error + '</p></div>' +
					'</div>' +
					'<p style="margin:0 0 14px">' +
						'<button class="ce-btn" id="ce-q-runnow"' + (active ? '' : ' disabled') + '>⚡ Processar próximo agora</button> ' +
						'<button class="ce-btn ce-btn-ghost" id="ce-q-clear">Limpar finalizados</button> ' +
						'<button class="ce-btn ce-btn-ghost" id="ce-q-refresh">↻ Atualizar</button>' +
						(d.next_tick !== null && active ? ' <span class="ce-sub">próxima execução do cron em ~' + Math.max(0, d.next_tick) + 's</span>' : '') +
					'</p>' +
					(d.jobs.length
						? '<div class="ce-card" style="padding:0"><table class="ce-table"><tbody>' + rows + '</tbody></table></div>'
						: '<div class="ce-empty"><p>Fila vazia. Vá em “Novos clusters &amp; conteúdo”, planeje tópicos e envie para geração em massa.</p></div>') +
				'</div>';

			$('#ce-q-refresh').addEventListener('click', function () { fetchQueue(el); });
			$('#ce-q-clear').addEventListener('click', function () {
				api('queue_clear', {}).then(function (r) { toast(r.removed + ' job(s) removidos'); fetchQueue(el); });
			});
			$('#ce-q-runnow').addEventListener('click', function () {
				var b = $('#ce-q-runnow');
				b.disabled = true;
				b.textContent = 'Processando… (até 2 min)';
				api('queue_run_now', {}).then(function () { fetchQueue(el); }).catch(function (e) { toast(e.message, true); fetchQueue(el); });
			});
			$$('[data-cancel]', el).forEach(function (b) {
				b.addEventListener('click', function () { api('queue_cancel', { job_id: b.dataset.cancel }).then(function () { fetchQueue(el); }); });
			});
			$$('[data-retry]', el).forEach(function (b) {
				b.addEventListener('click', function () { api('queue_retry', { job_id: b.dataset.retry }).then(function () { toast('Job reenviado'); fetchQueue(el); }); });
			});
			$$('[data-qpub]', el).forEach(function (b) {
				b.addEventListener('click', function () { openPublishModal(b.dataset.qpub, function () { fetchQueue(el); }); });
			});
			$$('[data-qkw]', el).forEach(function (b) {
				b.addEventListener('click', function () { openKeywordModal(b.dataset.qkw, b.dataset.kwcur, function () { fetchQueue(el); }); });
			});

			// Auto-atualiza a cada 6s enquanto houver jobs ativos e a aba estiver visível.
			clearTimeout(queueTimer);
			if (active && $('#ce-panel-queue').classList.contains('is-active')) {
				queueTimer = setTimeout(function () { fetchQueue(el); }, 6000);
			}
		}).catch(function (e) {
			el.innerHTML = '<div class="ce-empty"><p>' + esc(e.message) + '</p></div>';
		});
	}

	/* ---------- Settings ---------- */
	function renderSettings() {
		var el = $('#ce-panel-settings');
		var s = CE61.settings;
		var pts = CE61.postTypes.map(function (pt) {
			var checked = s.post_types.indexOf(pt.name) > -1 ? ' checked' : '';
			return '<label class="ce-check"><input type="checkbox" name="pt" value="' + esc(pt.name) + '"' + checked + '> ' + esc(pt.label) + '</label>';
		}).join('');
		function keyField(prov, label) {
			var has = s.has_key[prov] ? ' placeholder="•••••••• (chave salva)"' : ' placeholder="Cole a chave de API"';
			return '<div class="ce-field"><label>' + label + (s.has_key[prov] ? ' <span class="ce-chip ce-chip-green">salva</span>' : '') + '</label>' +
				'<input class="ce-input" type="password" id="ce-key-' + prov + '"' + has + ' autocomplete="off">' +
				'<p class="ce-hint">Deixe em branco para manter a chave atual.' +
				(s.has_key[prov] ? ' <a href="#" data-clearkey="' + prov + '">Remover chave salva</a>' : '') + '</p></div>';
		}
		el.innerHTML =
			'<div class="ce-grid" style="grid-template-columns:1fr 1fr;align-items:start">' +
			'<div class="ce-card">' +
				'<h2 class="ce-h2">Conteúdo analisado</h2><p class="ce-sub">Tipos de post incluídos no scan.</p>' + pts +
				'<h2 class="ce-h2" style="margin-top:22px">Limiares de análise</h2>' +
				'<div class="ce-field"><label>Similaridade mínima para sugerir link</label><input class="ce-input" id="ce-sim-link" type="number" step="0.01" min="0" max="1" value="' + s.sim_link + '"><p class="ce-hint">Padrão 0.22. Menor = mais sugestões.</p></div>' +
				'<div class="ce-field"><label>Similaridade abaixo da qual um link existente “não faz sentido”</label><input class="ce-input" id="ce-sim-weak" type="number" step="0.01" min="0" max="1" value="' + s.sim_weak + '"></div>' +
				'<div class="ce-field"><label>Similaridade que indica canibalização</label><input class="ce-input" id="ce-sim-cannibal" type="number" step="0.01" min="0" max="1" value="' + s.sim_cannibal + '"></div>' +
				'<div class="ce-field"><label>Mínimo de palavras (conteúdo fino)</label><input class="ce-input" id="ce-min-words" type="number" value="' + s.min_words + '"></div>' +
				'<div class="ce-field"><label>Meses até considerar desatualizado</label><input class="ce-input" id="ce-stale" type="number" value="' + s.stale_months + '"></div>' +
			'</div>' +
			'<div class="ce-card">' +
				'<h2 class="ce-h2">Provedor de IA</h2><p class="ce-sub">Suas chaves ficam salvas apenas no seu banco de dados. Se o provedor selecionado não tiver chave, o plugin usa automaticamente outro que tenha.</p>' +
				'<div class="ce-field"><label>Provedor ativo</label><select class="ce-select" id="ce-provider">' +
					'<option value="anthropic"' + (s.provider === 'anthropic' ? ' selected' : '') + '>Anthropic (Claude)</option>' +
					'<option value="openai"' + (s.provider === 'openai' ? ' selected' : '') + '>OpenAI (GPT)</option>' +
					'<option value="gemini"' + (s.provider === 'gemini' ? ' selected' : '') + '>Google (Gemini)</option>' +
				'</select></div>' +
				keyField('anthropic', 'Chave Anthropic') +
				keyField('openai', 'Chave OpenAI') +
				keyField('gemini', 'Chave Gemini') +
				'<div class="ce-field"><label>Modelo (opcional)</label><input class="ce-input" id="ce-model" value="' + esc(s.model_light) + '" placeholder="Vazio = padrão do provedor"></div>' +
				'<div class="ce-field"><label>Prompt global do site (identidade)</label>' +
				'<textarea class="ce-textarea" id="ce-global">' + esc(s.global_prompt) + '</textarea>' +
				'<p class="ce-hint">Injetado como instrução de sistema em toda ação de IA. Aceita variáveis como {{site_name}}.</p></div>' +
			'</div>' +
			'</div>' +
			'<div class="ce-card" style="grid-column:1/-1">' +
				'<h2 class="ce-h2">Geração de imagens destacadas</h2>' +
				'<p class="ce-sub">Usado pela aba Imagens quando a fonte é IA. A Anthropic não gera imagens; escolha OpenAI ou Google.</p>' +
				'<div class="ce-grid" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr))">' +
					'<div class="ce-field"><label>Provedor de imagem</label><select class="ce-select" id="ce-img-provider">' +
						'<option value="openai"' + (s.image_provider === 'openai' ? ' selected' : '') + '>OpenAI (GPT Image)</option>' +
						'<option value="gemini"' + (s.image_provider === 'gemini' ? ' selected' : '') + '>Google (Imagen / Gemini)</option>' +
					'</select></div>' +
					'<div class="ce-field"><label>Modelo</label><select class="ce-select" id="ce-img-model"></select>' +
					'<p class="ce-hint" id="ce-img-model-note"></p></div>' +
					'<div class="ce-field"><label>Proporção padrão</label><select class="ce-select" id="ce-img-aspect">' +
						Object.keys(CE61.imageAspectRatios).map(function (k) {
							return '<option value="' + k + '"' + (s.image_aspect === k ? ' selected' : '') + '>' + esc(CE61.imageAspectRatios[k].label) + '</option>';
						}).join('') +
					'</select></div>' +
					'<div class="ce-field"><label>Cores da marca</label><input class="ce-input" id="ce-img-colors" value="' + esc(s.image_colors) + '" placeholder="ex.: azul #2547F4, branco e dourado"></div>' +
				'</div>' +
				'<div class="ce-field"><label>Estilos padrão (marcados entram sempre no prompt)</label><div class="ce-grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:4px 14px">' +
					Object.keys(CE61.imageStylePresets).map(function (k) {
						var checked = s.image_style_presets.indexOf(k) > -1 ? ' checked' : '';
						return '<label class="ce-check"><input type="checkbox" class="ce-img-preset" value="' + k + '"' + checked + '> ' + esc(CE61.imageStylePresets[k].label) + '</label>';
					}).join('') +
				'</div></div>' +
				'<div class="ce-field"><label>Notas de estilo (livre)</label><textarea class="ce-textarea" id="ce-img-style" style="min-height:60px" placeholder="ex.: público de clínicas médicas; visual clean e confiável; evitar clichês de estetoscópio">' + esc(s.image_style) + '</textarea></div>' +

				'<div class="ce-field"><label>Watermark</label>' +
					'<div style="display:flex;gap:16px;margin-bottom:8px">' +
						'<label class="ce-check"><input type="radio" name="ce-wm-type" value="text"' + (s.image_watermark_type !== 'image' ? ' checked' : '') + '> Texto</label>' +
						'<label class="ce-check"><input type="radio" name="ce-wm-type" value="image"' + (s.image_watermark_type === 'image' ? ' checked' : '') + '> Imagem/logo</label>' +
					'</div>' +
					'<div id="ce-wm-text-wrap"' + (s.image_watermark_type === 'image' ? ' hidden' : '') + '>' +
						'<input class="ce-input" id="ce-img-watermark" value="' + esc(s.image_watermark) + '" placeholder="ex.: seusite.com.br">' +
						'<p class="ce-hint">Aplicado como texto no canto inferior direito.</p>' +
					'</div>' +
					'<div id="ce-wm-image-wrap"' + (s.image_watermark_type !== 'image' ? ' hidden' : '') + '>' +
						'<p style="margin:0 0 8px">' +
							'<button class="ce-btn ce-btn-sm" id="ce-wm-pick-media" type="button">🖼 Escolher da Biblioteca de Mídia</button> ' +
							(s.image_watermark_media_url ? '<img src="' + esc(s.image_watermark_media_url) + '" alt="" style="height:36px;vertical-align:middle;margin-left:8px;border-radius:4px;border:1px solid var(--ce-line)" id="ce-wm-preview">' : '<img id="ce-wm-preview" alt="" style="height:36px;vertical-align:middle;margin-left:8px;border-radius:4px;display:none">') +
						'</p>' +
						'<input type="hidden" id="ce-wm-media-id" value="' + (s.image_watermark_media_id || '') + '">' +
						'<input class="ce-input" id="ce-img-watermark-url" value="' + esc(s.image_watermark_url) + '" placeholder="ou cole a URL de uma imagem (PNG com fundo transparente funciona melhor)">' +
						'<p class="ce-hint">Sobreposto no canto inferior direito, redimensionado automaticamente. A Biblioteca de Mídia tem prioridade sobre a URL.</p>' +
					'</div>' +
				'</div>' +

				'<div class="ce-field"><label>Prompt mestre de imagem</label>' +
				'<textarea class="ce-textarea" id="ce-img-prompt-master" style="min-height:150px">' + esc(s.image_prompt) + '</textarea>' +
				'<p class="ce-hint">Variáveis disponíveis: {{title}}, {{keyword}}, {{site_name}}, {{cluster_name}}, {{colors}}, {{style_notes}}. Estilos e proporção marcados acima são adicionados automaticamente — não precisa repetir aqui.</p></div>' +
			'</div>' +
			'<p style="margin-top:18px"><button class="ce-btn ce-btn-primary" id="ce-save-settings">Salvar configurações</button> ' +
			'<button class="ce-btn" id="ce-test-ai">⚡ Testar conexão IA</button></p>';

		function renderModelOptions() {
			var provider = $('#ce-img-provider').value;
			var models = CE61.imageCatalog[provider] || {};
			var sel = $('#ce-img-model');
			var current = s.image_model && models[s.image_model] ? s.image_model : Object.keys(models)[0];
			sel.innerHTML = Object.keys(models).map(function (k) {
				return '<option value="' + k + '"' + (k === current ? ' selected' : '') + '>' + esc(models[k].label) + '</option>';
			}).join('');
			var note = $('#ce-img-model-note');
			if (note && models[current]) { note.textContent = models[current].note || ''; }
			sel.onchange = function () { var m = models[this.value]; if (note) { note.textContent = m ? (m.note || '') : ''; } };
		}
		renderModelOptions();
		$('#ce-img-provider').addEventListener('change', renderModelOptions);

		$$('input[name="ce-wm-type"]', el).forEach(function (r) {
			r.addEventListener('change', function () {
				$('#ce-wm-text-wrap').hidden = (this.value !== 'text');
				$('#ce-wm-image-wrap').hidden = (this.value !== 'image');
			});
		});
		var pickBtn = $('#ce-wm-pick-media');
		if (pickBtn) {
			pickBtn.addEventListener('click', function (e) {
				e.preventDefault();
				if (!window.wp || !wp.media) { toast('Biblioteca de mídia indisponível nesta tela', true); return; }
				var frame = wp.media({ title: 'Escolher imagem de watermark', multiple: false, library: { type: 'image' } });
				frame.on('select', function () {
					var att = frame.state().get('selection').first().toJSON();
					$('#ce-wm-media-id').value = att.id;
					var prev = $('#ce-wm-preview');
					prev.src = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
					prev.style.display = 'inline-block';
				});
				frame.open();
			});
		}

		$('#ce-test-ai').addEventListener('click', function () {
			var b = $('#ce-test-ai');
			b.disabled = true;
			b.textContent = 'Testando…';
			api('test_ai', {}).then(function (d) {
				b.disabled = false;
				b.textContent = '⚡ Testar conexão IA';
				if (d.fallback) {
					modal('<h3 class="ce-h2">Conexão OK — com um detalhe</h3>' +
						'<p class="ce-sub">O provedor selecionado é <b>' + esc(d.selected) + '</b>, mas ele não tem chave salva. O plugin respondeu via <b>' + esc(d.provider) + '</b> (fallback automático). Para usar o selecionado, cole a chave dele e salve.</p>');
				} else {
					toast('Conexão OK via ' + d.provider);
				}
			}).catch(function (e) {
				b.disabled = false;
				b.textContent = '⚡ Testar conexão IA';
				modal('<h3 class="ce-h2">Falha na conexão</h3><p class="ce-sub">' + esc(e.message) + '</p><p class="ce-sub">Verifique se a chave foi colada completa, se foi salva, e se a conta do provedor tem créditos ativos.</p>');
			});
		});

		$$('[data-clearkey]', el).forEach(function (a) {
			a.addEventListener('click', function (e) {
				e.preventDefault();
				var prov = a.dataset.clearkey;
				var input = $('#ce-key-' + prov);
				input.dataset.clear = '1';
				input.value = '';
				input.placeholder = 'Será removida ao salvar';
				a.textContent = 'Remoção marcada — clique em Salvar';
			});
		});

		$('#ce-save-settings').addEventListener('click', function () {
			var settings = {
				post_types: $$('input[name="pt"]:checked', el).map(function (i) { return i.value; }),
				provider: $('#ce-provider').value,
				model_light: $('#ce-model').value,
				global_prompt: $('#ce-global').value,
				sim_link: $('#ce-sim-link').value,
				sim_weak: $('#ce-sim-weak').value,
				sim_cannibal: $('#ce-sim-cannibal').value,
				min_words: $('#ce-min-words').value,
				stale_months: $('#ce-stale').value,
				image_provider: $('#ce-img-provider').value,
				image_model: $('#ce-img-model').value,
				image_aspect: $('#ce-img-aspect').value,
				image_style_presets: $$('.ce-img-preset:checked', el).map(function (c) { return c.value; }),
				image_colors: $('#ce-img-colors').value,
				image_style: $('#ce-img-style').value,
				image_prompt: $('#ce-img-prompt-master').value,
				image_watermark_type: $('input[name="ce-wm-type"]:checked', el) ? $('input[name="ce-wm-type"]:checked', el).value : 'text',
				image_watermark: $('#ce-img-watermark').value,
				image_watermark_url: $('#ce-img-watermark-url').value,
				image_watermark_media_id: $('#ce-wm-media-id').value || 0
			};
			['anthropic', 'openai', 'gemini'].forEach(function (p) {
				var input = $('#ce-key-' + p);
				var v = input.value.trim();
				if (input.dataset.clear === '1' && !v) {
					settings['api_key_' + p] = '__CLEAR__';
				} else if (v) {
					settings['api_key_' + p] = v;
				}
				// Campo vazio sem pedido de remoção: a chave salva é mantida no servidor.
			});
			api('save_settings', { settings: settings }).then(function () {
				toast('Configurações salvas');
				CE61.settings.provider = settings.provider;
				CE61.settings.image_provider = settings.image_provider;
				CE61.settings.image_prompt = settings.image_prompt;
				CE61.settings.image_model = settings.image_model;
				CE61.settings.image_aspect = settings.image_aspect;
				CE61.settings.image_style_presets = settings.image_style_presets;
				CE61.settings.image_watermark_type = settings.image_watermark_type;
				CE61.settings.image_watermark_url = settings.image_watermark_url;
				CE61.settings.image_watermark_media_id = settings.image_watermark_media_id;
				['anthropic', 'openai', 'gemini'].forEach(function (p) {
					if (settings['api_key_' + p] === '__CLEAR__') {
						CE61.settings.has_key[p] = false;
					} else if (settings['api_key_' + p]) {
						CE61.settings.has_key[p] = true;
					}
				});
				loadPanel('settings', true);
			}).catch(function (e) { toast(e.message, true); });
		});
	}

	/* ---------- Integrações (SERP APIs + Google Search Console / GA4) ---------- */
	function renderIntegrations() {
		var el = $('#ce-panel-integrations');
		var s = CE61.settings;
		var g = CE61.google || {};
		var serpNames = { serper: 'Serper.dev', serpapi: 'SerpApi', valueserp: 'ValueSERP' };

		function serpKeyField(prov, label, hint, signupUrl) {
			var has = s.has_key[prov];
			var ph = has ? '•••••••• (chave salva)' : 'Cole a chave de API';
			return '<div class="ce-field"><label>' + label + (has ? ' <span class="ce-chip ce-chip-green">salva</span>' : '') + '</label>' +
				'<input class="ce-input" type="password" id="ce-serpkey-' + prov + '" placeholder="' + ph + '" autocomplete="off">' +
				'<p class="ce-hint"><a href="' + signupUrl + '" target="_blank" rel="noopener">↗ ' + hint + '</a>' + (has ? ' · <a href="#" data-clearserp="' + prov + '">Remover chave salva</a>' : '') + '</p></div>';
		}

		el.innerHTML =
			'<div class="ce-grid" style="grid-template-columns:1fr 1fr;align-items:start">' +
			'<div class="ce-card">' +
				'<h2 class="ce-h2">API de posicionamento (SERP)</h2>' +
				'<p class="ce-sub">Usada na página Desempenho para checar em qual posição do Google cada post aparece pela sua keyword foco. Padrão: Serper.dev (2.500 buscas grátis no cadastro, sem cartão).</p>' +
				'<div class="ce-field"><label>Provedor ativo</label><select class="ce-select" id="ce-serp-provider">' +
					Object.keys(serpNames).map(function (k) {
						return '<option value="' + k + '"' + (s.serp_provider === k ? ' selected' : '') + '>' + serpNames[k] + '</option>';
					}).join('') +
				'</select></div>' +
				serpKeyField('serper', 'Chave Serper.dev', 'serper.dev — cadastro grátis, 2.500 créditos', 'https://serper.dev') +
				serpKeyField('serpapi', 'Chave SerpApi', 'serpapi.com — 100 buscas/mês grátis', 'https://serpapi.com/users/sign_up') +
				serpKeyField('valueserp', 'Chave ValueSERP', 'valueserp.com — trial com ~100 requisições', 'https://app.valueserp.com/users/sign_up') +
				'<p><button class="ce-btn ce-btn-primary" id="ce-save-serp">Salvar API de SERP</button> <button class="ce-btn" id="ce-test-serp">⚡ Testar posição</button></p>' +
			'</div>' +
			'<div class="ce-card">' +
				'<h2 class="ce-h2">Google Search Console &amp; Analytics (GA4)</h2>' +
				'<p class="ce-sub">Conecta via OAuth para trazer cliques, impressões, posição média e sessões reais de cada página. Crie um OAuth Client tipo “Web application” no Google Cloud Console.</p>' +
				'<p>' +
					'<a class="ce-btn ce-btn-sm" href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">↗ Abrir Google Cloud Console</a> ' +
					'<button class="ce-btn ce-btn-sm ce-btn-ghost" id="ce-google-guide" type="button">📘 Ver passo a passo do credenciamento</button>' +
				'</p>' +
				'<div class="ce-field"><label>Redirect URI a cadastrar no Google Cloud</label>' +
					'<input class="ce-input" readonly value="' + esc(g.redirect_uri || '') + '" onclick="this.select()"></div>' +
				'<div class="ce-field"><label>Client ID</label><input class="ce-input" id="ce-google-cid" value="' + esc(g.client_id || '') + '" placeholder="xxxx.apps.googleusercontent.com"></div>' +
				'<div class="ce-field"><label>Client Secret' + (g.has_secret ? ' <span class="ce-chip ce-chip-green">salvo</span>' : '') + '</label>' +
					'<input class="ce-input" type="password" id="ce-google-secret" placeholder="' + (g.has_secret ? '•••••••• (salvo)' : 'Cole o client secret') + '" autocomplete="off">' +
					(g.has_secret ? '<p class="ce-hint"><a href="#" data-clearsecret="1">Remover secret salvo</a></p>' : '') + '</div>' +
				'<p><button class="ce-btn ce-btn-primary" id="ce-save-google-client">Salvar credenciais</button></p>' +
				'<hr style="border:none;border-top:1px solid var(--ce-line);margin:14px 0">' +
				(g.connected
					? '<p><span class="ce-chip ce-chip-green">Conectado ao Google</span> <button class="ce-btn ce-btn-sm ce-btn-ghost" id="ce-google-disconnect">Desconectar</button></p>'
					: '<p><span class="ce-chip ce-chip-amber">Não conectado</span></p><p><a class="ce-btn ce-btn-primary" href="' + esc(g.connect_url || '#') + '">Conectar com o Google</a></p>') +
				'<div class="ce-field"><label>Propriedade do Search Console</label>' +
					'<input class="ce-input" id="ce-gsc-site" value="' + esc(g.gsc_site_url || '') + '" placeholder="https://seusite.com.br/ ou sc-domain:seusite.com.br">' +
					'<p class="ce-hint">' +
						'<button class="ce-btn ce-btn-sm ce-btn-ghost" id="ce-gsc-list" type="button">Listar minhas propriedades</button> ' +
						'<button class="ce-btn ce-btn-sm ce-btn-ghost" id="ce-gsc-test" type="button">⚡ Testar Search Console</button>' +
					'</p></div>' +
				'<div class="ce-field"><label>ID de propriedade do GA4</label>' +
					'<input class="ce-input" id="ce-ga4-prop" value="' + esc(g.ga4_property_id || '') + '" placeholder="ex.: 123456789 (Admin → Detalhes da propriedade no GA4)">' +
					'<p class="ce-hint">' +
						'<button class="ce-btn ce-btn-sm ce-btn-ghost" id="ce-ga4-list" type="button">Listar minhas propriedades GA4</button> ' +
						'<button class="ce-btn ce-btn-sm ce-btn-ghost" id="ce-ga4-test" type="button">⚡ Testar GA4</button>' +
					'</p></div>' +
				'<p><button class="ce-btn ce-btn-primary" id="ce-save-google-props">Salvar propriedades</button></p>' +
			'</div>' +
			'<div class="ce-card" style="grid-column:1/-1">' +
				'<h2 class="ce-h2">Automação da coleta diária</h2>' +
				'<p class="ce-sub">Todo dia, no horário abaixo, o plugin grava um retrato do Search Console + GA4 e checa a posição de um lote de posts no Google, formando o histórico usado nos gráficos da página Desempenho. Isso não sobrescreve nada — cada dia vira uma linha nova.</p>' +
				'<div class="ce-grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">' +
					'<div class="ce-field" style="margin:0"><label>Horário da coleta diária</label><input class="ce-input" type="time" id="ce-perf-cron-time" value="' + esc(s.perf_cron_time || '03:00') + '"><p class="ce-hint">Horário local do site. Escolha um momento de baixo tráfego.</p></div>' +
					'<div class="ce-field" style="margin:0"><label>Posts checados no Google por dia</label><input class="ce-input" type="number" min="0" max="500" id="ce-serp-budget" value="' + (s.serp_daily_budget || 20) + '"><p class="ce-hint">Protege a cota grátis da API de SERP — os posts mais atrasados são checados primeiro, cobrindo o site aos poucos.</p></div>' +
				'</div>' +
				(s.last_daily_snapshot ? '<p class="ce-sub" style="margin:10px 0 0">Última coleta automática: ' + esc(s.last_daily_snapshot) + '</p>' : '<p class="ce-sub" style="margin:10px 0 0">Ainda não rodou nenhuma coleta automática — a primeira acontece no próximo horário configurado.</p>') +
				'<p style="margin-top:12px"><button class="ce-btn ce-btn-primary" id="ce-save-automation">Salvar automação</button></p>' +
			'</div>' +
			'<div class="ce-card" style="grid-column:1/-1">' +
				'<h2 class="ce-h2">Bancos de imagens gratuitos</h2>' +
				'<p class="ce-sub">Fonte de imagem destacada preferida ao site — busca fotos reais respeitando a cota grátis de cada API, antes de recorrer à geração por IA. Openverse não precisa de chave.</p>' +
				'<div class="ce-field"><label>Fonte padrão ao gerar imagem</label><select class="ce-select" id="ce-img-source-default" style="max-width:260px">' +
					'<option value="stock"' + (s.image_source_default !== 'ai' ? ' selected' : '') + '>Banco de imagens primeiro</option>' +
					'<option value="ai"' + (s.image_source_default === 'ai' ? ' selected' : '') + '>Gerar com IA primeiro</option>' +
				'</select></div>' +
				'<div id="ce-stock-cards"></div>' +
				'<div class="ce-field" style="margin-top:6px"><label class="ce-check"><input type="checkbox" id="ce-stock-caption"' + (s.stock_credit_caption ? ' checked' : '') + '> Incluir o crédito do banco de imagens na legenda da mídia</label>' +
				'<p class="ce-hint">Recomendado para Unsplash e obrigatório para a maioria das licenças do Openverse (CC-BY etc.). Pexels e Pixabay não exigem, mas é boa prática manter.</p></div>' +
				'<p style="margin-top:10px"><button class="ce-btn ce-btn-primary" id="ce-save-stock">Salvar bancos de imagens</button></p>' +
			'</div>' +
			'</div>';

		renderStockCards();

		function renderStockCards() {
			var box = $('#ce-stock-cards');
			if (!box) { return; }
			box.innerHTML = '<div class="ce-loading">Carregando uso das cotas</div>';
			api('stock_status', {}).then(function (u) {
				var order = s.stock_priority && s.stock_priority.length ? s.stock_priority : ['pexels', 'pixabay', 'unsplash', 'openverse'];
				var providers = CE61.stockProviders || {};
				var signup = { unsplash: 'https://unsplash.com/developers', pexels: 'https://www.pexels.com/api/', pixabay: 'https://pixabay.com/api/docs/', openverse: 'https://api.openverse.org/v1/' };
				box.innerHTML = '<div class="ce-grid" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr));margin:12px 0">' +
					order.map(function (p, idx) {
						var info = providers[p] || {};
						var usage = u.usage[p] || { used: 0, limit: 100, remaining: 100 };
						var pct = usage.limit ? Math.min(100, Math.round((usage.used / usage.limit) * 100)) : 0;
						var needsKey = info.needs_key;
						var has = s.has_key[p];
						return '<div class="ce-card" style="padding:14px 16px">' +
							'<p style="margin:0 0 6px;display:flex;justify-content:space-between;align-items:center">' +
								'<b style="font-family:var(--ce-display)">' + (idx + 1) + '. ' + esc(info.label || p) + '</b>' +
								(needsKey ? (has ? '<span class="ce-chip ce-chip-green">chave salva</span>' : '<span class="ce-chip ce-chip-amber">sem chave</span>') : '<span class="ce-chip ce-chip-green">sem chave necessária</span>') +
							'</p>' +
							(needsKey ? '<input class="ce-input" type="password" id="ce-stockkey-' + p + '" placeholder="' + (has ? '•••••••• (chave salva)' : 'Cole a chave de API') + '" autocomplete="off" style="margin-bottom:8px">' : '') +
							'<div class="ce-score" style="width:100%;margin-bottom:6px"><span class="ce-score-track"><span class="ce-score-fill' + (pct > 85 ? ' is-bad' : (pct > 60 ? ' is-warn' : ' is-good')) + '" data-w="' + pct + '"></span></span><b>' + usage.used + '/' + usage.limit + '</b></div>' +
							'<p class="ce-hint" style="margin:0 0 8px">janela de ' + (usage.window >= 3600 ? Math.round(usage.window / 3600) + 'h' : usage.window + 's') + (usage.resets_in ? ' · libera em ~' + usage.resets_in + 's' : '') + '</p>' +
							'<p style="margin:0"><a href="' + signup[p] + '" target="_blank" rel="noopener" class="ce-hint">↗ ' + (needsKey ? 'obter chave' : 'documentação') + '</a> · ' +
								'<label class="ce-hint">limite/janela: <input class="ce-input" type="number" min="0" style="width:70px;display:inline-block" id="ce-stocklimit-' + p + '" value="' + usage.limit + '"></label>' +
							'</p>' +
						'</div>';
					}).join('') +
				'</div>' +
				'<p class="ce-hint">Ordem acima = prioridade no modo "Automático" (tenta o 1º; se sem cota ou sem chave, tenta o próximo).</p>';
			}).catch(function (e) { box.innerHTML = '<p class="ce-sub">' + esc(e.message) + '</p>'; });
		}

		var saveStockBtn = $('#ce-save-stock');
		if (saveStockBtn) {
			saveStockBtn.addEventListener('click', function () {
				var payload = {
					image_source_default: $('#ce-img-source-default').value,
					stock_credit_caption: $('#ce-stock-caption').checked ? 1 : 0
				};
				['unsplash', 'pexels', 'pixabay'].forEach(function (p) {
					var input = $('#ce-stockkey-' + p);
					if (input) {
						var v = input.value.trim();
						if (v) { payload['stock_key_' + p] = v; }
					}
					var lim = $('#ce-stocklimit-' + p);
					if (lim && lim.value) { payload['stock_limit_' + p] = lim.value; }
				});
				var limOv = $('#ce-stocklimit-openverse');
				if (limOv && limOv.value) { payload.stock_limit_openverse = limOv.value; }
				api('save_settings', { settings: payload }).then(function () {
					toast('Bancos de imagens salvos');
					CE61.settings.image_source_default = payload.image_source_default;
					CE61.settings.stock_credit_caption = !!payload.stock_credit_caption;
					['unsplash', 'pexels', 'pixabay'].forEach(function (p) { if (payload['stock_key_' + p]) { CE61.settings.has_key[p] = true; } });
					renderStockCards();
				}).catch(function (e) { toast(e.message, true); });
			});
		}
		$('#ce-save-serp').addEventListener('click', function () {
			var payload = { serp_provider: $('#ce-serp-provider').value };
			['serper', 'serpapi', 'valueserp'].forEach(function (p) {
				var input = $('#ce-serpkey-' + p);
				var v = input.value.trim();
				if (input.dataset.clear === '1' && !v) {
					payload['serp_key_' + p] = '__CLEAR__';
				} else if (v) {
					payload['serp_key_' + p] = v;
				}
			});
			api('save_settings', { settings: payload }).then(function () {
				toast('API de SERP salva');
				CE61.settings.serp_provider = payload.serp_provider;
				['serper', 'serpapi', 'valueserp'].forEach(function (p) {
					if (payload['serp_key_' + p] === '__CLEAR__') { CE61.settings.has_key[p] = false; }
					else if (payload['serp_key_' + p]) { CE61.settings.has_key[p] = true; }
				});
				loadPanel('integrations', true);
			}).catch(function (e) { toast(e.message, true); });
		});
		$$('[data-clearserp]', el).forEach(function (a) {
			a.addEventListener('click', function (e) {
				e.preventDefault();
				var input = $('#ce-serpkey-' + a.dataset.clearserp);
				input.dataset.clear = '1'; input.value = ''; input.placeholder = 'Será removida ao salvar';
				a.textContent = 'Remoção marcada — clique em Salvar';
			});
		});
		$('#ce-test-serp').addEventListener('click', function () {
			var b = $('#ce-test-serp');
			b.disabled = true; b.textContent = 'Testando…';
			api('performance_serp_one', { post_id: 0, keyword: 'wordpress' }).then(function () {
				b.disabled = false; b.textContent = '⚡ Testar posição';
				toast('Conexão com a API de SERP funcionando');
			}).catch(function (e) {
				b.disabled = false; b.textContent = '⚡ Testar posição';
				modal('<h3 class="ce-h2">Falha na API de SERP</h3><p class="ce-sub">' + esc(e.message) + '</p>');
			});
		});

		$('#ce-save-google-client').addEventListener('click', function () {
			var payload = { google_client_id: $('#ce-google-cid').value.trim() };
			var secretInput = $('#ce-google-secret');
			var v = secretInput.value.trim();
			if (secretInput.dataset.clear === '1' && !v) { payload.google_client_secret = '__CLEAR__'; }
			else if (v) { payload.google_client_secret = v; }
			api('save_settings', { settings: payload }).then(function () {
				toast('Credenciais do Google salvas');
				loadPanel('integrations', true);
			}).catch(function (e) { toast(e.message, true); });
		});
		var clearSecret = $('[data-clearsecret]', el);
		if (clearSecret) {
			clearSecret.addEventListener('click', function (e) {
				e.preventDefault();
				var input = $('#ce-google-secret');
				input.dataset.clear = '1'; input.value = ''; input.placeholder = 'Será removido ao salvar';
				clearSecret.textContent = 'Remoção marcada — clique em Salvar credenciais';
			});
		}
		$('#ce-save-google-props').addEventListener('click', function () {
			api('save_settings', { settings: { gsc_site_url: $('#ce-gsc-site').value.trim(), ga4_property_id: $('#ce-ga4-prop').value.trim() } }).then(function () {
				toast('Propriedades salvas');
			}).catch(function (e) { toast(e.message, true); });
		});
		var autoBtn = $('#ce-save-automation');
		if (autoBtn) {
			autoBtn.addEventListener('click', function () {
				var time = $('#ce-perf-cron-time').value || '03:00';
				var budget = $('#ce-serp-budget').value;
				api('save_settings', { settings: { perf_cron_time: time, serp_daily_budget: budget } }).then(function () {
					CE61.settings.perf_cron_time = time;
					CE61.settings.serp_daily_budget = parseInt(budget, 10) || 0;
					toast('Automação salva — próxima coleta às ' + time);
				}).catch(function (e) { toast(e.message, true); });
			});
		}
		var disc = $('#ce-google-disconnect');
		if (disc) {
			disc.addEventListener('click', function () {
				if (!window.confirm('Desconectar a conta do Google? Você pode reconectar quando quiser.')) { return; }
				api('google_disconnect', {}).then(function () { toast('Google desconectado'); loadPanel('integrations', true); });
			});
		}
		var listBtn = $('#ce-gsc-list');
		if (listBtn) {
			listBtn.addEventListener('click', function () {
				listBtn.disabled = true;
				api('google_list_sites', {}).then(function (d) {
					listBtn.disabled = false;
					if (!d.sites.length) { toast('Nenhuma propriedade encontrada nesta conta', true); return; }
					modal('<h3 class="ce-h2">Escolha a propriedade</h3>' +
						d.sites.map(function (s2) { return '<div class="ce-option" data-siteval="' + esc(s2) + '"><p>' + esc(s2) + '</p></div>'; }).join(''));
					$$('[data-siteval]').forEach(function (op) {
						op.addEventListener('click', function () {
							$('#ce-gsc-site').value = op.dataset.siteval;
							closeModal();
						});
					});
				}).catch(function (e) {
					listBtn.disabled = false;
					modal('<h3 class="ce-h2">Não foi possível listar</h3><p class="ce-sub">' + esc(e.message) + '</p><p class="ce-sub">Conecte-se ao Google primeiro (o botão acima) e confirme que a conta escolhida tem acesso ao Search Console.</p>');
				});
			});
		}
		var ga4ListBtn = $('#ce-ga4-list');
		if (ga4ListBtn) {
			ga4ListBtn.addEventListener('click', function () {
				ga4ListBtn.disabled = true;
				api('google_list_ga4', {}).then(function (d) {
					ga4ListBtn.disabled = false;
					modal('<h3 class="ce-h2">Escolha a propriedade GA4</h3>' +
						d.properties.map(function (p) { return '<div class="ce-option" data-ga4val="' + esc(p.id) + '"><p>' + esc(p.label) + '</p><small>ID ' + esc(p.id) + '</small></div>'; }).join(''));
					$$('[data-ga4val]').forEach(function (op) {
						op.addEventListener('click', function () {
							$('#ce-ga4-prop').value = op.dataset.ga4val;
							closeModal();
						});
					});
				}).catch(function (e) {
					ga4ListBtn.disabled = false;
					modal('<h3 class="ce-h2">Não foi possível listar</h3><p class="ce-sub">' + esc(e.message) + '</p><p class="ce-sub">Conecte-se ao Google primeiro e confirme que a conta escolhida tem acesso a alguma propriedade GA4 (não apenas Universal Analytics).</p>');
				});
			});
		}
		// Os botões de teste salvam o valor atual do campo antes de testar,
		// para nunca testar uma propriedade diferente da que está na tela.
		var gscTest = $('#ce-gsc-test');
		if (gscTest) {
			gscTest.addEventListener('click', function () {
				var v = $('#ce-gsc-site').value.trim();
				if (!v) { toast('Preencha a propriedade do Search Console primeiro', true); return; }
				gscTest.disabled = true;
				api('save_settings', { settings: { gsc_site_url: v } }).then(function () {
					return api('google_test_gsc', {});
				}).then(function (d) {
					gscTest.disabled = false;
					toast('Search Console conectado — ' + d.rows + ' páginas com dados nos últimos 7 dias');
				}).catch(function (e) {
					gscTest.disabled = false;
					modal('<h3 class="ce-h2">Falha ao testar o Search Console</h3><p class="ce-sub">' + esc(e.message) + '</p>' +
						'<p class="ce-sub">Confira: a propriedade precisa estar EXATAMENTE como aparece no Search Console (com "sc-domain:" ou a URL completa com barra final), e a conta Google conectada precisa ter acesso a ela.</p>');
				});
			});
		}
		var ga4Test = $('#ce-ga4-test');
		if (ga4Test) {
			ga4Test.addEventListener('click', function () {
				var v = $('#ce-ga4-prop').value.trim();
				if (!v) { toast('Preencha o ID de propriedade do GA4 primeiro', true); return; }
				ga4Test.disabled = true;
				api('save_settings', { settings: { ga4_property_id: v } }).then(function () {
					return api('google_test_ga4', {});
				}).then(function (d) {
					ga4Test.disabled = false;
					toast('GA4 conectado — ' + d.rows + ' páginas com sessões nos últimos 7 dias');
				}).catch(function (e) {
					ga4Test.disabled = false;
					modal('<h3 class="ce-h2">Falha ao testar o GA4</h3><p class="ce-sub">' + esc(e.message) + '</p>' +
						'<p class="ce-sub">Confira: o ID deve ser só o número da propriedade (ex.: 123456789, sem "properties/" e sem o "G-" da measurement ID), e a conta Google conectada precisa ter acesso a essa propriedade GA4.</p>');
				});
			});
		}

		var guideBtn = $('#ce-google-guide');
		if (guideBtn) {
			guideBtn.addEventListener('click', function () {
				var redirect = g.redirect_uri || '';
				modal(
					'<h3 class="ce-h2">Passo a passo: credenciar o Google</h3>' +
					'<p class="ce-sub">Leva uns 5 minutos. Faça na ordem — cada link abre a página certa do Google Cloud Console.</p>' +

					'<div class="ce-option" style="cursor:default;display:block">' +
						'<p><b>1. Crie (ou escolha) um projeto</b></p>' +
						'<p class="ce-sub">No Google Cloud Console, crie um projeto novo só para este site (ou use um existente).</p>' +
						'<p><a class="ce-btn ce-btn-sm" href="https://console.cloud.google.com/projectcreate" target="_blank" rel="noopener">↗ Criar projeto</a></p>' +
					'</div>' +

					'<div class="ce-option" style="cursor:default;display:block">' +
						'<p><b>2. Ative as APIs necessárias</b></p>' +
						'<p class="ce-sub">Clique em "Ativar" em cada uma das três (com o projeto certo selecionado no topo):</p>' +
						'<p>' +
							'<a class="ce-btn ce-btn-sm" href="https://console.cloud.google.com/apis/library/searchconsole.googleapis.com" target="_blank" rel="noopener">↗ Search Console API</a> ' +
							'<a class="ce-btn ce-btn-sm" href="https://console.cloud.google.com/apis/library/analyticsdata.googleapis.com" target="_blank" rel="noopener">↗ Google Analytics Data API</a> ' +
							'<a class="ce-btn ce-btn-sm" href="https://console.cloud.google.com/apis/library/analyticsadmin.googleapis.com" target="_blank" rel="noopener">↗ Google Analytics Admin API</a>' +
						'</p>' +
					'</div>' +

					'<div class="ce-option" style="cursor:default;display:block">' +
						'<p><b>3. Configure a tela de consentimento OAuth</b></p>' +
						'<p class="ce-sub">Tipo de usuário "Externo", preencha nome do app e e-mail de suporte. Em "Escopos", adicione <code>.../auth/webmasters.readonly</code> e <code>.../auth/analytics.readonly</code>. Enquanto o app estiver em modo "Teste", adicione sua própria conta Google em "Usuários de teste" — sem isso o Google bloqueia o login.</p>' +
						'<p><a class="ce-btn ce-btn-sm" href="https://console.cloud.google.com/apis/credentials/consent" target="_blank" rel="noopener">↗ Tela de consentimento</a></p>' +
					'</div>' +

					'<div class="ce-option" style="cursor:default;display:block">' +
						'<p><b>4. Crie o Client ID (OAuth)</b></p>' +
						'<p class="ce-sub">Em "Credenciais" → "Criar credenciais" → "ID do cliente OAuth". Tipo de aplicativo: <b>Aplicativo da Web</b>. Em "URIs de redirecionamento autorizados", cole exatamente esta URL:</p>' +
						'<p><input class="ce-input" readonly value="' + esc(redirect) + '" onclick="this.select()" style="font-family:var(--ce-mono);font-size:12px"></p>' +
						'<p><a class="ce-btn ce-btn-sm" href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">↗ Criar credenciais</a></p>' +
					'</div>' +

					'<div class="ce-option" style="cursor:default;display:block">' +
						'<p><b>5. Copie o Client ID e o Client Secret</b></p>' +
						'<p class="ce-sub">O Google mostra os dois na hora da criação (e depois, clicando no client criado na lista). Cole nos campos desta aba e clique em "Salvar credenciais".</p>' +
					'</div>' +

					'<div class="ce-option" style="cursor:default;display:block">' +
						'<p><b>6. Conecte</b></p>' +
						'<p class="ce-sub">Clique em "Conectar com o Google", escolha a conta e autorize. Depois use "Listar minhas propriedades" para preencher o Search Console e o GA4 sem precisar digitar nada.</p>' +
					'</div>'
				);
			});
		}
	}



	/* ---------- Desempenho das URLs (SERP + Search Console + GA4) ---------- */
	function trendArrow(pos) {
		if (pos === null || pos === undefined) { return '<span class="ce-sub">—</span>'; }
		var cls = pos <= 10 ? 'ce-chip-green' : (pos <= 20 ? 'ce-chip-amber' : 'ce-chip-red');
		return '<span class="ce-chip ' + cls + '">#' + pos + '</span>';
	}

	var perfData = null; // dataset cru desta carga, para ordenar/filtrar no cliente sem nova chamada.

	function perfRowsHtml(list, hasSerp) {
		if (!list.length) { return '<tr><td colspan="9" class="ce-sub">Nenhum resultado para esta busca.</td></tr>'; }
		return list.map(function (p) {
			var serp = p.serp
				? trendArrow(p.serp.position) + ' <small class="ce-sub">' + esc(p.serp.checked_at || '') + '</small>'
				: (hasSerp ? '<span class="ce-sub">não checado</span>' : '<span class="ce-sub">sem API</span>');
			var gsc = p.gsc || {};
			return '<tr>' +
				'<td><a href="' + esc(p.edit) + '" target="_blank" rel="noopener">' + esc(p.title) + '</a><br><span class="ce-chip ce-chip-kw">' + esc(p.keyword || '—') + '</span></td>' +
				'<td>' + serp + ' <button class="ce-btn ce-btn-sm ce-btn-ghost" data-serpcheck="' + p.post_id + '" data-kw="' + esc(p.keyword || '') + '" title="Reconsultar posição">↻</button></td>' +
				'<td>' + (p.gsc ? gsc.clicks : '<span class="ce-sub">—</span>') + '</td>' +
				'<td>' + (p.gsc ? gsc.impressions : '<span class="ce-sub">—</span>') + '</td>' +
				'<td>' + (p.gsc ? gsc.ctr + '%' : '<span class="ce-sub">—</span>') + '</td>' +
				'<td>' + (p.gsc ? gsc.position : '<span class="ce-sub">—</span>') + '</td>' +
				'<td>' + (null !== p.ga4_sessions ? p.ga4_sessions : '<span class="ce-sub">—</span>') + '</td>' +
				'<td style="white-space:nowrap">' +
					'<button class="ce-btn ce-btn-sm ce-btn-ghost" data-perfchart="' + p.post_id + '" data-title="' + esc(p.title) + '" title="Ver evolução no tempo">📈</button> ' +
					'<button class="ce-btn ce-btn-sm ce-btn-ghost" data-perfinsight="' + p.post_id + '" data-title="' + esc(p.title) + '" title="Insight de IA sobre este post">✦</button> ' +
					'<a class="ce-btn ce-btn-sm ce-btn-ghost" href="' + esc(p.view) + '" target="_blank" rel="noopener">Ver</a>' +
				'</td>' +
			'</tr>';
		}).join('');
	}

	var perfSorters = {
		title_asc:    function (a, b) { return a.title.localeCompare(b.title, 'pt-BR'); },
		title_desc:   function (a, b) { return b.title.localeCompare(a.title, 'pt-BR'); },
		clicks_desc:  function (a, b) { return (b.gsc ? b.gsc.clicks : -1) - (a.gsc ? a.gsc.clicks : -1); },
		clicks_asc:   function (a, b) { return (a.gsc ? a.gsc.clicks : 1e9) - (b.gsc ? b.gsc.clicks : 1e9); },
		sessions_desc: function (a, b) { return (b.ga4_sessions || -1) - (a.ga4_sessions || -1); },
		sessions_asc:  function (a, b) { return (a.ga4_sessions === null ? 1e9 : a.ga4_sessions) - (b.ga4_sessions === null ? 1e9 : b.ga4_sessions); },
		serp_asc:     function (a, b) { return (a.serp && a.serp.position ? a.serp.position : 999) - (b.serp && b.serp.position ? b.serp.position : 999); },
		serp_desc:    function (a, b) { return (b.serp && b.serp.position ? b.serp.position : 0) - (a.serp && a.serp.position ? a.serp.position : 0); }
	};

	function applyPerfView() {
		if (!perfData) { return; }
		var term = ($('#ce-perf-search') ? $('#ce-perf-search').value.trim().toLowerCase() : '');
		var sortKey = $('#ce-perf-sort') ? $('#ce-perf-sort').value : 'clicks_desc';
		var list = perfData.posts.filter(function (p) {
			if (!term) { return true; }
			return p.title.toLowerCase().indexOf(term) > -1 || (p.keyword && p.keyword.toLowerCase().indexOf(term) > -1);
		});
		list = list.slice().sort(perfSorters[sortKey] || perfSorters.clicks_desc);
		var tbody = $('#ce-perf-tbody');
		if (tbody) { tbody.innerHTML = perfRowsHtml(list, perfData.has_serp); bindPerfRowActions(); }
	}

	function bindPerfRowActions() {
		var el = $('#ce-panel-performance');
		$$('[data-serpcheck]', el).forEach(function (b) {
			b.addEventListener('click', function () {
				b.disabled = true;
				api('performance_serp_one', { post_id: b.dataset.serpcheck, keyword: b.dataset.kw }).then(function (r) {
					toast(r.serp.position ? ('Posição atual: #' + r.serp.position) : 'Não apareceu no top 10');
					loadPanel('performance', true);
				}).catch(function (e) {
					b.disabled = false;
					toast(e.message, true);
				});
			});
		});
		$$('[data-perfchart]', el).forEach(function (b) {
			b.addEventListener('click', function () { openPerfChart(b.dataset.perfchart, b.dataset.title); });
		});
		$$('[data-perfinsight]', el).forEach(function (b) {
			b.addEventListener('click', function () { openPerfInsight(b.dataset.perfinsight, b.dataset.title); });
		});
	}

	/* Gráfico SVG simples (sem libs externas) com marcadores de atualização de conteúdo. */
	function renderPerfSvg(points, updates, metricKey) {
		var W = 640, H = 220, padL = 42, padR = 12, padT = 14, padB = 26;
		var vals = points.map(function (p) { return p[metricKey]; }).filter(function (v) { return v !== null && v !== undefined; });
		if (!vals.length) { return '<p class="ce-sub" style="padding:30px 0;text-align:center">Ainda não há dados suficientes nesta janela. A coleta diária vai preenchendo o histórico a partir de hoje.</p>'; }
		var invert = (metricKey === 'gsc_position' || metricKey === 'serp_position'); // posição: menor é melhor, eixo invertido visualmente
		var min = Math.min.apply(null, vals), max = Math.max.apply(null, vals);
		if (min === max) { min -= 1; max += 1; }
		var n = points.length;
		var x = function (i) { return padL + (n <= 1 ? 0 : (i / (n - 1)) * (W - padL - padR)); };
		var y = function (v) {
			var t = (v - min) / (max - min);
			if (invert) { t = 1 - t; }
			return padT + (1 - t) * (H - padT - padB);
		};
		var pathParts = [];
		var dots = [];
		points.forEach(function (p, i) {
			var v = p[metricKey];
			if (v === null || v === undefined) { return; }
			var px = x(i), py = y(v);
			pathParts.push((pathParts.length ? 'L' : 'M') + px.toFixed(1) + ',' + py.toFixed(1));
			dots.push('<circle cx="' + px.toFixed(1) + '" cy="' + py.toFixed(1) + '" r="2.6" fill="#2547F4"><title>' + esc(p.snap_date) + ': ' + esc(String(v)) + '</title></circle>');
		});
		var markers = (updates || []).map(function (d) {
			var idx = points.findIndex(function (p) { return p.snap_date === d; });
			if (idx < 0) { return ''; }
			var px = x(idx);
			return '<line x1="' + px.toFixed(1) + '" y1="' + padT + '" x2="' + px.toFixed(1) + '" y2="' + (H - padB) + '" stroke="#C77800" stroke-width="1.5" stroke-dasharray="4 3"><title>Post atualizado em ' + esc(d) + '</title></line>';
		}).join('');
		var yTop = invert ? min : max, yBot = invert ? max : min;
		return '<svg viewBox="0 0 ' + W + ' ' + H + '" style="width:100%;height:auto;font-family:var(--ce-mono)">' +
			'<line x1="' + padL + '" y1="' + (H - padB) + '" x2="' + (W - padR) + '" y2="' + (H - padB) + '" stroke="var(--ce-line)"/>' +
			'<text x="4" y="' + (padT + 10) + '" font-size="10" fill="var(--ce-ink-3)">' + esc(String(Math.round(yTop))) + '</text>' +
			'<text x="4" y="' + (H - padB) + '" font-size="10" fill="var(--ce-ink-3)">' + esc(String(Math.round(yBot))) + '</text>' +
			markers +
			'<path d="' + pathParts.join(' ') + '" fill="none" stroke="#2547F4" stroke-width="2"/>' +
			dots.join('') +
			'<text x="' + padL + '" y="' + (H - 6) + '" font-size="10" fill="var(--ce-ink-3)">' + esc(points[0].snap_date) + '</text>' +
			'<text x="' + (W - padR) + '" y="' + (H - 6) + '" font-size="10" fill="var(--ce-ink-3)" text-anchor="end">' + esc(points[n - 1].snap_date) + '</text>' +
			'</svg>';
	}

	function openPerfChart(postId, title) {
		var metric = 'clicks';
		var days = 30;
		modal(
			'<h3 class="ce-h2">Evolução — ' + esc(title) + '</h3>' +
			'<p class="ce-sub" id="ce-perf-chart-meta">Carregando…</p>' +
			'<div class="ce-net-toolbar" style="margin-bottom:10px">' +
				'<select class="ce-select" id="ce-chart-metric" style="max-width:220px">' +
					'<option value="clicks">Cliques (GSC)</option>' +
					'<option value="impressions">Impressões (GSC)</option>' +
					'<option value="gsc_position">Posição média (GSC)</option>' +
					'<option value="serp_position">Posição no Google (SERP)</option>' +
					'<option value="ga4_sessions">Sessões (GA4)</option>' +
				'</select>' +
				'<span style="display:inline-flex;gap:4px" id="ce-chart-periods">' +
					[3, 7, 15, 30, 90, 120].map(function (d) { return '<button class="ce-btn ce-btn-sm' + (d === 30 ? ' ce-btn-primary' : ' ce-btn-ghost') + '" data-period="' + d + '">' + d + 'd</button>'; }).join('') +
				'</span>' +
			'</div>' +
			'<div id="ce-chart-out"><div class="ce-loading">Carregando o histórico</div></div>' +
			'<p class="ce-sub" style="margin-top:8px"><span style="color:#C77800">┊</span> linha tracejada = data em que o conteúdo foi atualizado</p>'
		);
		function load() {
			var out = $('#ce-chart-out');
			out.innerHTML = '<div class="ce-loading">Carregando o histórico</div>';
			api('performance_history', { post_id: postId, days: days }).then(function (d) {
				var meta = $('#ce-perf-chart-meta');
				if (meta) { meta.textContent = d.points.length + ' dias com dado nos últimos ' + days + ' · ' + d.updates.length + ' atualização(ões) de conteúdo na janela'; }
				out.innerHTML = renderPerfSvg(d.points, d.updates, metric);
			}).catch(function (e) { out.innerHTML = '<p class="ce-sub">' + esc(e.message) + '</p>'; });
		}
		$('#ce-chart-metric').addEventListener('change', function () { metric = this.value; load(); });
		$$('[data-period]', $('#ce-chart-periods')).forEach(function (b) {
			b.addEventListener('click', function () {
				days = parseInt(b.dataset.period, 10);
				$$('[data-period]', $('#ce-chart-periods')).forEach(function (x) { x.className = 'ce-btn ce-btn-sm ce-btn-ghost'; });
				b.className = 'ce-btn ce-btn-sm ce-btn-primary';
				load();
			});
		});
		load();
	}

	/* Métricas atuais de um post (mesmas exibidas na tabela) para anexar ao salvar um insight. */
	function currentMetricsFor(postId) {
		if (!perfData) { return {}; }
		var p = null;
		perfData.posts.some(function (x) { if (String(x.post_id) === String(postId)) { p = x; return true; } return false; });
		if (!p) { return {}; }
		return {
			clicks: p.gsc ? p.gsc.clicks : null,
			impressions: p.gsc ? p.gsc.impressions : null,
			ctr: p.gsc ? p.gsc.ctr : null,
			gsc_position: p.gsc ? p.gsc.position : null,
			serp_position: p.serp ? p.serp.position : null,
			ga4_sessions: p.ga4_sessions
		};
	}

	function metricsChips(m) {
		if (!m) { return ''; }
		var out = [];
		if (m.clicks !== null && m.clicks !== undefined) { out.push('<span class="ce-chip ce-chip-kw">Cliques ' + m.clicks + '</span>'); }
		if (m.gsc_position !== null && m.gsc_position !== undefined) { out.push('<span class="ce-chip ce-chip-kw">Pos. GSC ' + m.gsc_position + '</span>'); }
		if (m.serp_position !== null && m.serp_position !== undefined) { out.push('<span class="ce-chip ce-chip-kw">Pos. Google #' + m.serp_position + '</span>'); }
		if (m.ga4_sessions !== null && m.ga4_sessions !== undefined) { out.push('<span class="ce-chip ce-chip-kw">Sessões ' + m.ga4_sessions + '</span>'); }
		return out.join(' ') || '<span class="ce-sub">sem métricas registradas neste ponto</span>';
	}

	function openPerfInsight(postId, title) {
		if (!requireKey()) { return; }
		modal('<h3 class="ce-h2">Insight de IA — ' + esc(title) + '</h3><div class="ce-loading">Cruzando histórico, atualizações e diagnóstico do post</div>');
		api('performance_insight', { post_id: postId }).then(function (d) {
			modal(
				'<h3 class="ce-h2">Insight de IA — ' + esc(title) + '</h3>' +
				'<div class="ce-pre" style="background:var(--ce-paper);color:var(--ce-ink);white-space:pre-wrap">' + esc(d.insight) + '</div>' +
				'<p style="margin-top:14px">' +
					'<button class="ce-btn ce-btn-primary" id="ce-insight-save">💾 Salvar este insight</button> ' +
					'<button class="ce-btn ce-btn-ghost" id="ce-insight-hist">🗒 Ver histórico salvo</button>' +
				'</p>' +
				'<div id="ce-insight-save-out"></div>'
			);
			$('#ce-insight-save').addEventListener('click', function () {
				var btn = $('#ce-insight-save');
				btn.disabled = true;
				api('performance_insight_save', {
					post_id: postId,
					text: d.insight,
					metrics: JSON.stringify(currentMetricsFor(postId))
				}).then(function (r) {
					$('#ce-insight-save-out').innerHTML = '<p class="ce-sub" style="margin-top:10px">Salvo com a foto das métricas de hoje — ' + r.count + ' nota(s) guardada(s) para este post. Volte aqui mais adiante para comparar.</p>';
					toast('Insight salvo');
				}).catch(function (e) {
					btn.disabled = false;
					toast(e.message, true);
				});
			});
			$('#ce-insight-hist').addEventListener('click', function () { openPerfInsightHistory(postId, title); });
		}).catch(function (e) {
			modal('<h3 class="ce-h2">Não foi possível gerar</h3><p class="ce-sub">' + esc(e.message) + '</p>');
		});
	}

	function openPerfInsightHistory(postId, title) {
		modal('<h3 class="ce-h2">Histórico de insights — ' + esc(title) + '</h3><div class="ce-loading">Carregando notas salvas</div>');
		api('performance_insight_list', { post_id: postId }).then(function (d) {
			if (!d.notes.length) {
				modal('<h3 class="ce-h2">Histórico de insights — ' + esc(title) + '</h3>' +
					'<div class="ce-empty" style="padding:34px"><p>Nenhum insight salvo ainda para este post.</p></div>');
				return;
			}
			var now = metricsChips(currentMetricsFor(postId));
			var items = d.notes.map(function (n, i) {
				return '<div class="ce-option" style="cursor:default;display:block">' +
					'<p class="ce-sub" style="margin:0 0 6px">' + esc(n.date) + ' · métricas no momento: ' + metricsChips(n.metrics) + '</p>' +
					'<p style="margin:0 0 8px;white-space:pre-wrap">' + esc(n.text) + '</p>' +
					'<button class="ce-btn ce-btn-sm ce-btn-ghost" data-delinsight="' + i + '">Excluir esta nota</button>' +
				'</div>';
			}).join('');
			modal(
				'<h3 class="ce-h2">Histórico de insights — ' + esc(title) + '</h3>' +
				'<p class="ce-sub" style="margin:0 0 12px">Métricas de agora, para comparar: ' + now + '</p>' +
				items
			);
			$$('[data-delinsight]').forEach(function (b) {
				b.addEventListener('click', function () {
					api('performance_insight_delete', { post_id: postId, index: b.dataset.delinsight }).then(function () {
						toast('Nota removida');
						openPerfInsightHistory(postId, title);
					});
				});
			});
		}).catch(function (e) {
			modal('<h3 class="ce-h2">Erro</h3><p class="ce-sub">' + esc(e.message) + '</p>');
		});
	}

	function renderPerformance() {
		var el = $('#ce-panel-performance');
		el.innerHTML = '<div class="ce-loading">Carregando dados de desempenho</div>';
		api('performance_data', {}).then(function (d) {
			perfData = d;
			var hasGoogle = d.google_connected;
			var hasSerp = d.has_serp;

			el.innerHTML =
				'<div class="ce-section">' +
					'<h2 class="ce-h2">Desempenho das URLs</h2>' +
					'<p class="ce-sub">Posição no Google (' + esc(d.serp_provider) + '), e — quando o Google estiver conectado — cliques, impressões, CTR e posição média do Search Console (28 dias) mais sessões do GA4. O histórico diário alimenta os gráficos (ícone 📈) e os insights de IA (ícone ✦).</p>' +
					(hasSerp ? '' : '<div class="ce-card" style="margin-bottom:12px"><p style="margin:0">⚠ Nenhuma API de SERP configurada. <a href="' + esc(CE61.pages.settings) + '&ce-tab=integrations">Configure em Integrações</a> para ver posições no Google.</p></div>') +
					(hasGoogle ? '' : '<div class="ce-card" style="margin-bottom:12px"><p style="margin:0">⚠ Google Search Console / GA4 não conectado. <a href="' + esc(CE61.pages.settings) + '&ce-tab=integrations">Conectar em Integrações</a> para ver cliques, impressões e sessões reais.</p></div>') +
					'<p>' +
						'<button class="ce-btn ce-btn-primary" id="ce-perf-refresh">↻ Atualizar dados</button> ' +
						(d.gsc_updated ? '<span class="ce-sub">Search Console: ' + esc(d.gsc_updated) + '</span> ' : '') +
						(d.ga4_updated ? '<span class="ce-sub"> · GA4: ' + esc(d.ga4_updated) + '</span>' : '') +
						'<span class="ce-sub"> · <a href="' + esc(CE61.pages.settings) + '&ce-tab=integrations">configurar coleta diária automática</a></span>' +
					'</p>' +
					'<div class="ce-scanbar" id="ce-perf-bar" hidden><div class="ce-scanbar-track"><div class="ce-scanbar-fill" id="ce-perf-fill"></div></div><p id="ce-perf-msg"></p></div>' +
				'</div>' +
				'<div class="ce-net-toolbar" style="margin-bottom:10px">' +
					'<input class="ce-input" id="ce-perf-search" placeholder="Buscar por título ou keyword…" style="max-width:280px">' +
					'<select class="ce-select" id="ce-perf-sort" style="max-width:240px">' +
						'<option value="clicks_desc">Cliques — maior para menor</option>' +
						'<option value="clicks_asc">Cliques — menor para maior</option>' +
						'<option value="title_asc">Título — A a Z</option>' +
						'<option value="title_desc">Título — Z a A</option>' +
						'<option value="sessions_desc">Sessões GA4 — maior para menor</option>' +
						'<option value="sessions_asc">Sessões GA4 — menor para maior</option>' +
						'<option value="serp_asc">Posição no Google — melhor primeiro</option>' +
						'<option value="serp_desc">Posição no Google — pior primeiro</option>' +
					'</select>' +
				'</div>' +
				'<div class="ce-card ce-table-wrap"><table class="ce-table"><thead><tr>' +
					'<th>Página</th><th>Posição Google</th><th>Cliques</th><th>Impressões</th><th>CTR</th><th>Posição média</th><th>Sessões (GA4)</th><th></th>' +
				'</tr></thead><tbody id="ce-perf-tbody">' + perfRowsHtml(d.posts, hasSerp) + '</tbody></table></div>';

			$('#ce-perf-search').addEventListener('input', applyPerfView);
			$('#ce-perf-sort').addEventListener('change', applyPerfView);
			bindPerfRowActions();

			$('#ce-perf-refresh').addEventListener('click', function () {
				var btn = $('#ce-perf-refresh'), bar = $('#ce-perf-bar'), fill = $('#ce-perf-fill'), msg = $('#ce-perf-msg');
				btn.disabled = true; bar.hidden = false;
				(function step(offset) {
					api('performance_refresh_batch', { offset: offset }).then(function (r) {
						var pct = r.total ? (r.done / r.total) * 100 : 100;
						fill.style.width = pct + '%';
						msg.textContent = 'Verificando páginas ' + r.done + '/' + r.total + (r.has_serp ? '' : ' (sem API de SERP configurada)');
						if (r.done < r.total) { step(r.done); } else {
							msg.textContent = 'Atualização concluída.';
							btn.disabled = false;
							if (r.notes && r.notes.length) { toast(r.notes[0], true); }
							setTimeout(function () { bar.hidden = true; }, 800);
							loadPanel('performance', true);
						}
					}).catch(function (e) {
						btn.disabled = false; bar.hidden = true;
						toast(e.message, true);
					});
				})(0);
			});
		}).catch(function (e) { el.innerHTML = '<div class="ce-empty"><p>' + esc(e.message) + '</p></div>'; });
	}

	/* ---------- Rede de Palavras-chave (canvas, estética de rede neural) ---------- */
	var netState = null; // estado vivo do grafo enquanto a aba está ativa.

	function clusterColor(cid) {
		// Hash simples e estável -> matiz distribuído no círculo de cor.
		var h = 0;
		var s = String(cid || 0);
		for (var i = 0; i < s.length; i++) { h = (h * 31 + s.charCodeAt(i)) % 360; }
		if (!cid) { h = 220; } // sem cluster: azul neutro.
		return h;
	}

	function renderNetwork() {
		var el = $('#ce-panel-network');
		el.innerHTML = '<div class="ce-loading">Carregando a rede de conteúdo</div>';
		api('keyword_network', {}).then(function (d) {
			if (netState) { netState.stop(); netState = null; }
			if (!d.nodes.length) {
				el.innerHTML = '<div class="ce-empty"><h3>Nenhum post indexado</h3><p>Rode o scan no Painel para o plugin mapear os posts e a linkagem interna.</p></div>';
				return;
			}
			el.innerHTML =
				'<div class="ce-net-toolbar">' +
					'<input class="ce-input" id="ce-net-search" placeholder="Buscar por título ou keyword…" style="max-width:280px">' +
					'<span class="ce-net-legend"><i class="ce-net-dot" style="background:#5B8CFF"></i> tamanho = links recebidos · anel brilhante = pilar do cluster</span>' +
					'<span class="ce-net-count">' + d.nodes.length + ' artigos · ' + d.edges.length + ' links</span>' +
				'</div>' +
				'<div class="ce-net-wrap" id="ce-net-wrap">' +
					'<canvas id="ce-net-canvas"></canvas>' +
					'<div class="ce-net-tip" id="ce-net-tip" hidden></div>' +
					'<div class="ce-net-zoom" role="group" aria-label="Zoom da rede">' +
						'<button type="button" class="ce-net-zbtn" id="ce-net-zin" aria-label="Aproximar" title="Aproximar">+</button>' +
						'<button type="button" class="ce-net-zbtn" id="ce-net-zout" aria-label="Afastar" title="Afastar">\u2212</button>' +
						'<button type="button" class="ce-net-zbtn" id="ce-net-zfit" aria-label="Ajustar à tela" title="Ajustar à tela">\u2922</button>' +
					'</div>' +
					'<div class="ce-net-hint" id="ce-net-hint">Ctrl + scroll para zoom · arraste para mover</div>' +
				'</div>';
			netState = initNetwork(d.nodes, d.edges);
		}).catch(function (e) { el.innerHTML = '<div class="ce-empty"><p>' + esc(e.message) + '</p></div>'; });
	}

	function initNetwork(rawNodes, rawEdges) {
		var wrap = $('#ce-net-wrap');
		var canvas = $('#ce-net-canvas');
		var tip = $('#ce-net-tip');
		var ctx = canvas.getContext('2d');
		var dpr = Math.min(2, window.devicePixelRatio || 1);
		var W = 0, H = 0;

		function resize() {
			W = wrap.clientWidth;
			H = wrap.clientHeight;
			canvas.width = W * dpr;
			canvas.height = H * dpr;
			canvas.style.width = W + 'px';
			canvas.style.height = H + 'px';
			ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
		}
		resize();

		var byId = {};
		var nodes = rawNodes.map(function (n) {
			var angle = Math.random() * Math.PI * 2;
			var r = Math.random() * Math.min(W, H) * 0.35;
			var o = {
				id: n.id, title: n.title, keyword: n.keyword, cluster: n.cluster, cname: n.cname,
				inbound: n.inbound, pillar: n.pillar, edit: n.edit, view: n.view,
				x: W / 2 + Math.cos(angle) * r, y: H / 2 + Math.sin(angle) * r,
				vx: 0, vy: 0, fx: null, fy: null,
				radius: Math.max(6, Math.min(22, 6 + n.inbound * 1.4)),
				hue: clusterColor(n.cluster),
				phase: Math.random() * Math.PI * 2
			};
			byId[n.id] = o;
			return o;
		});
		var edges = rawEdges.map(function (e) { return { a: byId[e.a], b: byId[e.b], w: e.w }; })
			.filter(function (e) { return e.a && e.b; });

		// View transform (zoom/pan).
		var view = { x: 0, y: 0, scale: 1 };
		var hovered = null;
		var dragging = null;
		var panning = false;
		var panStart = null;
		var searchTerm = '';
		var running = true;
		var settleTicks = 0;
		var hasFitted = false;

		function toWorld(px, py) {
			return { x: (px - view.x) / view.scale, y: (py - view.y) / view.scale };
		}

		// Auto-fit: enquadra e centraliza todos os nós na área visível.
		function fitView() {
			if (!nodes.length) { return; }
			var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
			nodes.forEach(function (nd) {
				var r = nd.radius + 6;
				if (nd.x - r < minX) { minX = nd.x - r; }
				if (nd.y - r < minY) { minY = nd.y - r; }
				if (nd.x + r > maxX) { maxX = nd.x + r; }
				if (nd.y + r > maxY) { maxY = nd.y + r; }
			});
			var pad = 48;
			var bw = (maxX - minX) || 1, bh = (maxY - minY) || 1;
			var scale = Math.min((W - pad * 2) / bw, (H - pad * 2) / bh);
			scale = Math.max(0.25, Math.min(3, scale));
			view.scale = scale;
			view.x = (W - (minX + maxX) * scale) / 2;
			view.y = (H - (minY + maxY) * scale) / 2;
		}

		function step() {
			var n = nodes.length;
			// Repulsão entre todos os pares (Coulomb aproximado).
			for (var i = 0; i < n; i++) {
				var a = nodes[i];
				for (var j = i + 1; j < n; j++) {
					var b = nodes[j];
					var dx = a.x - b.x, dy = a.y - b.y;
					var d2 = dx * dx + dy * dy || 0.01;
					var d = Math.sqrt(d2);
					var force = 2600 / d2;
					var fx = (dx / d) * force, fy = (dy / d) * force;
					a.vx += fx; a.vy += fy;
					b.vx -= fx; b.vy -= fy;
				}
			}
			// Atração nas arestas (mola).
			edges.forEach(function (e) {
				var dx = e.b.x - e.a.x, dy = e.b.y - e.a.y;
				var d = Math.sqrt(dx * dx + dy * dy) || 0.01;
				var target = 110;
				var force = (d - target) * 0.02 * e.w;
				var fx = (dx / d) * force, fy = (dy / d) * force;
				e.a.vx += fx; e.a.vy += fy;
				e.b.vx -= fx; e.b.vy -= fy;
			});
			// Centralização suave + damping + integração.
			nodes.forEach(function (a) {
				a.vx += (W / 2 - a.x) * 0.0025;
				a.vy += (H / 2 - a.y) * 0.0025;
				if (a.fx !== null) { a.x = a.fx; a.y = a.fy; a.vx = 0; a.vy = 0; return; }
				a.vx *= 0.82; a.vy *= 0.82;
				a.x += a.vx; a.y += a.vy;
			});
		}

		function draw(time) {
			ctx.clearRect(0, 0, W, H);
			ctx.save();
			ctx.translate(view.x, view.y);
			ctx.scale(view.scale, view.scale);

			var connected = {};
			if (hovered) {
				edges.forEach(function (e) {
					if (e.a === hovered || e.b === hovered) { connected[e.a.id] = true; connected[e.b.id] = true; }
				});
			}

			// Arestas.
			edges.forEach(function (e) {
				var hi = hovered && (e.a === hovered || e.b === hovered);
				var dim = hovered && !hi;
				var grad = ctx.createLinearGradient(e.a.x, e.a.y, e.b.x, e.b.y);
				grad.addColorStop(0, 'hsla(' + e.a.hue + ',85%,65%,' + (dim ? 0.06 : (hi ? 0.55 : 0.16)) + ')');
				grad.addColorStop(1, 'hsla(' + e.b.hue + ',85%,65%,' + (dim ? 0.06 : (hi ? 0.55 : 0.16)) + ')');
				ctx.strokeStyle = grad;
				ctx.lineWidth = (e.w > 1 ? 1.6 : 0.9) * (hi ? 1.8 : 1);
				ctx.beginPath();
				ctx.moveTo(e.a.x, e.a.y);
				ctx.lineTo(e.b.x, e.b.y);
				ctx.stroke();
			});

			// Nós.
			nodes.forEach(function (nd) {
				var dim = hovered && hovered !== nd && !connected[nd.id];
				var match = !searchTerm || (nd.title.toLowerCase().indexOf(searchTerm) > -1) || (nd.keyword && nd.keyword.toLowerCase().indexOf(searchTerm) > -1);
				var alpha = (dim ? 0.18 : 1) * (match ? 1 : 0.12);
				var pulse = 0.85 + Math.sin(time / 900 + nd.phase) * 0.15;
				var r = nd.radius * (nd === hovered ? 1.35 : 1);

				var glow = ctx.createRadialGradient(nd.x, nd.y, 0, nd.x, nd.y, r * 2.6);
				glow.addColorStop(0, 'hsla(' + nd.hue + ',90%,65%,' + (0.35 * alpha * pulse) + ')');
				glow.addColorStop(1, 'hsla(' + nd.hue + ',90%,65%,0)');
				ctx.fillStyle = glow;
				ctx.beginPath();
				ctx.arc(nd.x, nd.y, r * 2.6, 0, Math.PI * 2);
				ctx.fill();

				if (nd.pillar) {
					ctx.strokeStyle = 'hsla(' + nd.hue + ',95%,75%,' + (0.8 * alpha) + ')';
					ctx.lineWidth = 2;
					ctx.beginPath();
					ctx.arc(nd.x, nd.y, r + 4, 0, Math.PI * 2);
					ctx.stroke();
				}
				ctx.fillStyle = 'hsla(' + nd.hue + ',85%,62%,' + alpha + ')';
				ctx.beginPath();
				ctx.arc(nd.x, nd.y, r, 0, Math.PI * 2);
				ctx.fill();
				ctx.fillStyle = 'rgba(255,255,255,' + (0.9 * alpha) + ')';
				ctx.beginPath();
				ctx.arc(nd.x - r * 0.3, nd.y - r * 0.3, r * 0.28, 0, Math.PI * 2);
				ctx.fill();
			});

			ctx.restore();
		}

		function loop(time) {
			if (!running) { return; }
			if (settleTicks < 220) { step(); settleTicks++; } else { step(); } // continua leve para permanecer "vivo".
			if (!hasFitted && settleTicks >= 200) { fitView(); hasFitted = true; } // enquadra assim que o layout estabiliza.
			draw(time || 0);
			raf = requestAnimationFrame(loop);
		}
		var raf = requestAnimationFrame(loop);

		function nodeAt(px, py) {
			var pt = toWorld(px, py);
			var best = null, bestD = 0;
			nodes.forEach(function (nd) {
				var dx = nd.x - pt.x, dy = nd.y - pt.y;
				var d = Math.sqrt(dx * dx + dy * dy);
				if (d <= nd.radius + 6 && (!best || d < bestD)) { best = nd; bestD = d; }
			});
			return best;
		}

		function onMove(e) {
			var rect = canvas.getBoundingClientRect();
			var px = e.clientX - rect.left, py = e.clientY - rect.top;
			if (dragging) {
				var pt = toWorld(px, py);
				dragging.fx = pt.x; dragging.fy = pt.y;
				return;
			}
			if (panning) {
				view.x = panStart.vx + (e.clientX - panStart.x);
				view.y = panStart.vy + (e.clientY - panStart.y);
				return;
			}
			var nd = nodeAt(px, py);
			hovered = nd;
			if (nd) {
				tip.hidden = false;
				tip.textContent = nd.title;
				tip.style.left = Math.min(W - 20, px + 16) + 'px';
				tip.style.top = Math.max(0, py - 10) + 'px';
				canvas.style.cursor = 'pointer';
			} else {
				tip.hidden = true;
				canvas.style.cursor = panning ? 'grabbing' : 'grab';
			}
		}
		function onDown(e) {
			var rect = canvas.getBoundingClientRect();
			var px = e.clientX - rect.left, py = e.clientY - rect.top;
			var nd = nodeAt(px, py);
			if (nd) {
				dragging = nd;
			} else {
				panning = true;
				panStart = { x: e.clientX, y: e.clientY, vx: view.x, vy: view.y };
			}
		}
		function onUp(e) {
			var rect = canvas.getBoundingClientRect();
			var px = e.clientX - rect.left, py = e.clientY - rect.top;
			if (dragging && !movedSinceDown) {
				openNodeModal(dragging);
			}
			if (dragging) { dragging.fx = null; dragging.fy = null; dragging = null; }
			panning = false;
			movedSinceDown = false;
		}
		var movedSinceDown = false;
		function onDownWrap(e) { movedSinceDown = false; onDown(e); }
		function onMoveWrap(e) { movedSinceDown = true; onMove(e); }

		// Zoom mantendo estável o ponto (px, py) em coordenadas do canvas.
		function zoomAt(factor, px, py) {
			var before = toWorld(px, py);
			view.scale = Math.max(0.25, Math.min(3, view.scale * factor));
			var after = toWorld(px, py);
			view.x += (after.x - before.x) * view.scale;
			view.y += (after.y - before.y) * view.scale;
		}

		function onWheel(e) {
			// Só dá zoom com Ctrl/⌘ segurado; caso contrário deixa a página rolar normalmente.
			if (!(e.ctrlKey || e.metaKey)) { return; }
			e.preventDefault();
			var rect = canvas.getBoundingClientRect();
			// Fator exponencial proporcional ao delta: suave no wheel e no trackpad.
			var factor = Math.pow(1.0015, -e.deltaY);
			zoomAt(factor, e.clientX - rect.left, e.clientY - rect.top);
		}

		function openNodeModal(nd) {
			modal(
				'<h3 class="ce-h2">' + esc(nd.title) + '</h3>' +
				(nd.cname ? '<p class="ce-sub" style="margin:0 0 6px">Cluster: ' + esc(nd.cname) + '</p>' : '') +
				(nd.keyword ? '<p style="margin:0 0 10px"><span class="ce-chip ce-chip-kw">' + esc(nd.keyword) + '</span>' + (nd.pillar ? ' <span class="ce-chip ce-chip-pillar">PILAR</span>' : '') + '</p>' : '') +
				'<p class="ce-sub">' + nd.inbound + ' links internos de entrada</p>' +
				'<p style="margin-top:14px"><a class="ce-btn ce-btn-primary" href="' + esc(nd.edit) + '" target="_blank" rel="noopener">Editar</a> ' +
				'<a class="ce-btn ce-btn-ghost" href="' + esc(nd.view) + '" target="_blank" rel="noopener">Visualizar</a></p>'
			);
		}

		canvas.addEventListener('mousemove', onMoveWrap);
		canvas.addEventListener('mousedown', onDownWrap);
		window.addEventListener('mouseup', onUp);
		canvas.addEventListener('wheel', onWheel, { passive: false });
		canvas.addEventListener('mouseleave', function () { hovered = null; tip.hidden = true; });

		// Botões de zoom na tela (aproximam/afastam a partir do centro do canvas).
		var zin = $('#ce-net-zin'), zout = $('#ce-net-zout'), zfit = $('#ce-net-zfit');
		if (zin) { zin.addEventListener('click', function () { zoomAt(1.2, W / 2, H / 2); }); }
		if (zout) { zout.addEventListener('click', function () { zoomAt(1 / 1.2, W / 2, H / 2); }); }
		if (zfit) { zfit.addEventListener('click', function () { fitView(); }); }

		var searchInput = $('#ce-net-search');
		if (searchInput) {
			searchInput.addEventListener('input', function () { searchTerm = this.value.trim().toLowerCase(); });
		}
		var onResize = function () { resize(); };
		window.addEventListener('resize', onResize);

		return {
			stop: function () {
				running = false;
				cancelAnimationFrame(raf);
				canvas.removeEventListener('mousemove', onMoveWrap);
				canvas.removeEventListener('mousedown', onDownWrap);
				window.removeEventListener('mouseup', onUp);
				window.removeEventListener('resize', onResize);
			}
		};
	}

	/* ---------- CPTs (Custom Post Types) ---------- */
	function cptSourceBadge(src) {
		return src === 'jetengine'
			? '<span class="ce-seo-badge" title="Registrado pelo JetEngine">◈ JetEngine</span>'
			: '<span class="ce-seo-badge" title="Post type nativo">◈ Nativo</span>';
	}

	function renderCptManage() {
		var el = $('#ce-panel-cpt_manage');
		el.innerHTML = '<div class="ce-loading">Lendo os Custom Post Types do site</div>';
		api('cpt_list', {}).then(function (d) {
			var jeNote = d.jetengine
				? ''
				: '<div class="ce-card" style="margin-bottom:12px"><p style="margin:0">O JetEngine não está ativo. Os CPTs nativos continuam funcionando normalmente; a leitura de campos personalizados do JetEngine fica indisponível.</p></div>';
			if (!d.cpts.length) {
				el.innerHTML = jeNote + '<div class="ce-empty"><h3>Nenhum Custom Post Type encontrado</h3><p>Este site só tem os tipos nativos (posts e páginas). Crie um CPT (por exemplo no JetEngine) para integrá-lo aqui.</p></div>';
				return;
			}
			el.innerHTML =
				'<div class="ce-section">' +
					'<h2 class="ce-h2">CPTs do site</h2>' +
					'<p class="ce-sub">Ative um CPT para o Cluster Engine tratá-lo igual aos posts: indexação, clusters, linkagem interna e diagnóstico. Depois de ativar, rode <b>Escanear site</b> no Painel para montar as relações e clusters.</p>' +
					jeNote +
					'<div class="ce-grid" style="grid-template-columns:repeat(auto-fill,minmax(320px,1fr))">' +
					d.cpts.map(cptCard).join('') +
					'</div>' +
				'</div>';
			bindCptCards(el);
		}).catch(function (e) { el.innerHTML = '<div class="ce-empty"><p>' + esc(e.message) + '</p></div>'; });
	}

	function cptCard(c) {
		var fields = c.fields && c.fields.length
			? '<p class="ce-sub" style="margin:6px 0 0">' + c.fields.length + ' campo(s): ' + c.fields.slice(0, 8).map(function (f) { return esc(f.title || f.name); }).join(', ') + (c.fields.length > 8 ? '…' : '') + '</p>'
			: '<p class="ce-sub" style="margin:6px 0 0">Sem campos personalizados detectados.</p>';
		return '<div class="ce-card">' +
			'<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">' +
				'<h3 style="font-family:var(--ce-display);margin:0">' + esc(c.label) + '</h3>' +
				cptSourceBadge(c.source) +
			'</div>' +
			'<p class="ce-sub" style="margin:4px 0 0"><code>' + esc(c.name) + '</code> · ' + c.count + ' publicado(s) · ' + c.indexed + ' indexado(s)</p>' +
			fields +
			'<p style="margin:12px 0 0">' +
				'<button class="ce-btn ce-btn-sm ' + (c.enabled ? '' : 'ce-btn-primary') + '" data-cpt-toggle="' + esc(c.name) + '" data-on="' + (c.enabled ? '0' : '1') + '">' +
					(c.enabled ? '✓ Ativado — desativar' : '+ Ativar no Cluster Engine') +
				'</button>' +
			'</p>' +
		'</div>';
	}

	function bindCptCards(scope) {
		$$('[data-cpt-toggle]', scope).forEach(function (b) {
			b.addEventListener('click', function () {
				b.disabled = true;
				api('cpt_toggle', { post_type: b.dataset.cptToggle, enabled: b.dataset.on }).then(function () {
					toast(b.dataset.on === '1' ? 'CPT ativado' : 'CPT desativado');
					loadPanel('cpt_manage', true);
				}).catch(function (e) { b.disabled = false; toast(e.message, true); });
			});
		});
	}

	function renderCptContent() {
		var el = $('#ce-panel-cpt_content');
		el.innerHTML = '<div class="ce-loading">Carregando CPTs e clusters</div>';
		api('cpt_list', {}).then(function (d) {
			var enabled = d.cpts.filter(function (c) { return c.enabled; });
			if (!enabled.length) {
				el.innerHTML = '<div class="ce-empty"><h3>Nenhum CPT ativado ainda</h3><p>Vá na aba <b>CPTs existentes</b> e ative pelo menos um CPT para gerar conteúdo para ele.</p></div>';
				return;
			}
			api('creator_data', {}).then(function (cd) {
				var clusters = cd.clusters || [];
				el.innerHTML =
					'<div class="ce-section">' +
						'<h2 class="ce-h2">Gerar conteúdo para um CPT</h2>' +
						'<p class="ce-sub">O item é criado no tipo escolhido, com a mesma estratégia de cluster e linkagem interna dos posts. Se o CPT tiver campos personalizados, a IA também os preenche.</p>' +
						'<div class="ce-card">' +
							'<div class="ce-grid" style="grid-template-columns:1fr 1fr;gap:10px">' +
								'<div class="ce-field" style="margin:0"><label>Tipo de conteúdo (CPT)</label><select class="ce-select" id="ce-cpt-type">' +
									enabled.map(function (c) { return '<option value="' + esc(c.name) + '">' + esc(c.label) + '</option>'; }).join('') +
								'</select></div>' +
								'<div class="ce-field" style="margin:0"><label>Cluster (opcional)</label><select class="ce-select" id="ce-cpt-cluster"><option value="0">Nenhum / avulso</option>' +
									clusters.map(function (c) { return '<option value="' + c.id + '">' + esc(c.name) + '</option>'; }).join('') +
								'</select></div>' +
							'</div>' +
							'<div class="ce-grid" style="grid-template-columns:2fr 2fr;gap:10px">' +
								'<div class="ce-field" style="margin:0"><label>Título</label><input class="ce-input" id="ce-cpt-title" placeholder="ex.: Título do item"></div>' +
								'<div class="ce-field" style="margin:0"><label>Palavra-chave foco</label><input class="ce-input" id="ce-cpt-kw" placeholder="opcional"></div>' +
							'</div>' +
							'<div id="ce-cpt-fields"></div>' +
							'<div class="ce-field"><label>Prompt / instruções para a IA</label>' +
								'<textarea class="ce-textarea" id="ce-cpt-prompt" style="min-height:100px" placeholder="Deixe em branco para instruções padrão, ou descreva o que o conteúdo precisa cobrir…"></textarea>' +
							'</div>' +
							'<div class="ce-grid" style="grid-template-columns:1fr 1fr;gap:10px;align-items:start">' +
								'<div class="ce-field" style="margin:0"><label>Publicação</label><select class="ce-select" id="ce-cpt-publish">' +
									'<option value="draft">Salvar como rascunho</option>' +
									'<option value="publish">Publicar imediatamente</option>' +
									'<option value="schedule">Agendar</option>' +
								'</select></div>' +
								'<div class="ce-field" style="margin:0" id="ce-cpt-schedule-wrap" hidden><label>Data e hora</label><input class="ce-input" type="datetime-local" id="ce-cpt-schedule"></div>' +
							'</div>' +
							'<p><button class="ce-btn ce-btn-primary" id="ce-cpt-go">✦ Gerar conteúdo</button></p>' +
							'<div id="ce-cpt-out"></div>' +
						'</div>' +
					'</div>';

				function fieldsPreview() {
					var cur = enabled.filter(function (c) { return c.name === $('#ce-cpt-type').value; })[0];
					var box = $('#ce-cpt-fields');
					if (cur && cur.fields && cur.fields.length) {
						box.innerHTML = '<p class="ce-hint" style="margin:2px 0 8px">Campos que a IA vai preencher: ' +
							cur.fields.map(function (f) { return '<span class="ce-chip">' + esc(f.title || f.name) + '</span>'; }).join(' ') + '</p>';
					} else {
						box.innerHTML = '';
					}
				}
				fieldsPreview();
				$('#ce-cpt-type').addEventListener('change', fieldsPreview);
				$('#ce-cpt-publish').addEventListener('change', function () {
					$('#ce-cpt-schedule-wrap').hidden = (this.value !== 'schedule');
				});

				$('#ce-cpt-go').addEventListener('click', function () {
					if (!requireKey()) { return; }
					var title = $('#ce-cpt-title').value.trim();
					if (!title) { toast('Escreva o título', true); return; }
					var publish = $('#ce-cpt-publish').value;
					var schedRaw = $('#ce-cpt-schedule').value;
					if ('schedule' === publish && !schedRaw) { toast('Escolha data e hora para agendar', true); return; }
					var btn = $('#ce-cpt-go'), out = $('#ce-cpt-out');
					btn.disabled = true;
					out.innerHTML = '<div class="ce-loading">Gerando o conteúdo (até 2 min)</div>';
					api('cpt_generate', {
						post_type: $('#ce-cpt-type').value,
						cluster_id: $('#ce-cpt-cluster').value,
						title: title,
						keyword: $('#ce-cpt-kw').value.trim(),
						custom_prompt: $('#ce-cpt-prompt').value.trim(),
						publish: publish,
						schedule_at: schedRaw ? schedRaw.replace('T', ' ') + ':00' : ''
					}).then(function (r) {
						btn.disabled = false;
						out.innerHTML =
							'<div class="ce-card" style="margin-top:14px">' +
								'<p style="margin:0 0 10px">' + statusChip(r.status, '') + ' <b style="font-family:var(--ce-display)">' + esc(r.title) + '</b></p>' +
								'<p><a class="ce-btn ce-btn-sm" href="' + esc(r.edit) + '" target="_blank" rel="noopener">Abrir no editor</a></p>' +
								scoresBlock(r.scores) +
							'</div>';
						toast('Conteúdo gerado');
					}).catch(function (e) {
						btn.disabled = false;
						out.innerHTML = '<p class="ce-sub">' + esc(e.message) + '</p>';
					});
				});
			}).catch(function (e) { el.innerHTML = '<div class="ce-empty"><p>' + esc(e.message) + '</p></div>'; });
		}).catch(function (e) { el.innerHTML = '<div class="ce-empty"><p>' + esc(e.message) + '</p></div>'; });
	}

	/* ---------- Boot ---------- */
	if ($('#ce-seo-plugin')) { $('#ce-seo-plugin').textContent = CE61.seoPlugin; }
	(function boot() {
		var qs = new URLSearchParams(window.location.search);
		var g = qs.get('ce61_google');
		if (g) {
			var msgs = {
				connected: ['Google conectado com sucesso', false],
				denied: ['Autorização do Google cancelada', true],
				bad_state: ['Sessão de autorização expirou, tente novamente', true],
				no_code: ['O Google não retornou um código de autorização', true],
				no_client: ['Configure o Client ID/Secret antes de conectar', true],
				error: [qs.get('msg') ? decodeURIComponent(qs.get('msg')) : 'Falha ao conectar com o Google', true]
			};
			var m = msgs[g] || ['Retorno do Google: ' + g, true];
			setTimeout(function () { toast(m[0], m[1]); }, 300);
			var clean = window.location.pathname + '?' + qs.toString().replace(/&?ce61_google=[^&]*/, '').replace(/&?msg=[^&]*/, '');
			window.history.replaceState({}, '', clean.replace(/[?&]$/, ''));
		}
		var want = qs.get('ce-tab');
		if (want && $('.ce-tab[data-tab="' + want + '"]')) {
			$('.ce-tab[data-tab="' + want + '"]').click();
			return;
		}
		var active = $('.ce-tab.is-active');
		if (active) { loadPanel(active.dataset.tab); }
	})();
})();
