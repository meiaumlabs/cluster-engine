/**
 * Cluster Engine — extensão no editor.
 * Modal "melhorar conteúdo com IA" em 2 colunas: à esquerda o diagnóstico
 * (notas, desempenho, melhorias sugeridas e histórico com reverter); à direita
 * o prompt e os botões de geração. O rascunho é mostrado para aprovação antes
 * de atualizar/publicar. Também: botão de gerar imagem destacada com IA.
 * Autossuficiente, sem dependência do app SPA.
 */
(function () {
	'use strict';

	var CFG = window.CE61_EDITOR || null;
	if (!CFG || !CFG.postId) {
		return;
	}
	var T = CFG.i18n || {};

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	/* AJAX form-encoded para admin-ajax. */
	function api(action, data) {
		var body = 'action=ce61_' + encodeURIComponent(action) + '&nonce=' + encodeURIComponent(CFG.nonce);
		data = data || {};
		Object.keys(data).forEach(function (k) {
			body += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(data[k]);
		});
		return fetch(CFG.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body
		}).then(function (r) { return r.json(); }).then(function (j) {
			if (!j || !j.success) {
				var msg = j && j.data && j.data.message ? j.data.message : (T.error || 'Erro');
				throw new Error(msg);
			}
			return j.data;
		});
	}

	/* ---------- Formatação / render de partes ---------- */

	function num(v) {
		if (v === null || v === undefined || v === '') { return '—'; }
		var n = Number(v);
		if (isNaN(n)) { return esc(v); }
		return (Math.round(n * 100) / 100).toLocaleString('pt-BR');
	}

	function scoreClass(v) {
		v = Number(v) || 0;
		if (v >= 80) { return 'is-good'; }
		if (v >= 50) { return 'is-mid'; }
		return 'is-low';
	}

	function bar(label, v) {
		v = Math.max(0, Math.min(100, Number(v) || 0));
		return '<div class="ce61-score">' +
			'<div class="ce61-score-head"><span>' + esc(label) + '</span><b>' + v + '</b></div>' +
			'<div class="ce61-score-track"><i class="' + scoreClass(v) + '" style="width:' + v + '%"></i></div>' +
			'</div>';
	}

	// Conjunto de keys com correção automática (vem do servidor em d.autofix).
	function autofixSet(d) {
		var set = {};
		(d && d.autofix ? d.autofix : ['no_faq', 'no_answer_capsule', 'no_meta_desc', 'no_image']).forEach(function (k) { set[k] = 1; });
		return set;
	}

	// Pendências estruturadas: { grp, label, key } deduplicadas por grupo+rótulo.
	function collectIssueItems(d) {
		var out = [];
		var s = d.scores || {};
		[['eeat', 'E-E-A-T'], ['aeo', 'AEO'], ['geo', 'GEO']].forEach(function (pair) {
			var grp = s[pair[0]];
			if (grp && grp.issues && grp.issues.length) {
				grp.issues.forEach(function (it) {
					if (it && it.label) { out.push({ grp: pair[1], label: it.label, key: it.key || '' }); }
				});
			}
		});
		if (d.index && d.index.issues && d.index.issues.length) {
			d.index.issues.forEach(function (it) { out.push({ grp: 'SEO', label: it, key: '' }); });
		}
		var seen = {}, uniq = [];
		out.forEach(function (x) { var k = x.grp + '|' + x.label; if (!seen[k]) { seen[k] = 1; uniq.push(x); } });
		return uniq;
	}

	function issuesListHtml(d) {
		var items = collectIssueItems(d);
		if (!items.length) {
			return '<p class="ce61-muted">' + esc(T.issuesNone || '') + '</p>';
		}
		var fix = autofixSet(d);
		return '<ul class="ce61-issues">' + items.map(function (it) {
			var action = (it.key && fix[it.key])
				? '<button type="button" class="ce61-issue-fix" data-issue="' + esc(it.key) + '">' + esc(T.fixBtn || 'Corrigir') + '</button>'
				: '<span class="ce61-issue-manual" title="' + esc(T.fixManual || '') + '">' + esc(T.fixManualShort || '') + '</span>';
			return '<li class="ce61-issue">' +
				'<span class="ce61-issue-txt"><b>[' + esc(it.grp) + ']</b> ' + esc(it.label) + '</span>' +
				action + '</li>';
		}).join('') + '</ul>';
	}

	function metaBlock(d) {
		var m = d.meta || {};
		function field(f, label, isArea, val, max) {
			var input = isArea
				? '<textarea class="ce61-meta-input" rows="3" maxlength="' + max + '">' + esc(val || '') + '</textarea>'
				: '<input type="text" class="ce61-meta-input" maxlength="' + max + '" value="' + esc(val || '') + '">';
			return '<div class="ce61-meta-field" data-metafield="' + f + '">' +
				'<label>' + esc(label) + '</label>' + input +
				'<div class="ce61-meta-row">' +
					'<button type="button" class="button ce61-meta-gen" data-field="' + f + '">' + esc(T.metaGen || '') + '</button>' +
					'<button type="button" class="button button-primary ce61-meta-save" data-field="' + f + '">' + esc(T.metaSave || '') + '</button>' +
				'</div>' +
				'<div class="ce61-meta-opts" hidden></div>' +
			'</div>';
		}
		return '<div class="ce61-diag-block ce61-meta-block"><h4>' + esc(T.metaTitle || '') + '</h4>' +
			'<p class="ce61-muted ce61-meta-hint">' + esc(T.metaHint || '') + '</p>' +
			field('title', T.metaTitleLabel || 'Meta título', false, m.title, 70) +
			field('desc', T.metaDescLabel || 'Meta descrição', true, m.desc, 170) +
			'</div>';
	}

	function perfRow(label, latest, delta, invert) {
		if (latest === null || latest === undefined || latest === '') { return ''; }
		var d = '';
		if (delta !== null && delta !== undefined && delta !== '' && Number(delta) !== 0) {
			var up = Number(delta) > 0;
			var good = invert ? !up : up;
			d = '<span class="ce61-delta ' + (good ? 'is-up' : 'is-down') + '">' +
				(up ? '▲' : '▼') + ' ' + num(Math.abs(delta)) + '</span>';
		}
		return '<div class="ce61-metric"><span>' + esc(label) + '</span><b>' + num(latest) + '</b>' + d + '</div>';
	}

	function renderPerf(p) {
		if (!p || !p.has_data || !p.latest) {
			return '<p class="ce61-muted">' + esc(T.perfNone || '') + '</p>';
		}
		var l = p.latest, dl = p.delta || {};
		return '<div class="ce61-metrics">' +
			perfRow(T.metClicks, l.clicks, dl.clicks, false) +
			perfRow(T.metImpr, l.impressions, dl.impressions, false) +
			perfRow(T.metGsc, l.gsc_position, dl.gsc_position, true) +
			perfRow(T.metSerp, l.serp_position, dl.serp_position, true) +
			perfRow(T.metGa4, l.ga4_sessions, dl.ga4_sessions, false) +
			perfRow(T.metCtr, l.ctr, null, false) +
			'</div>';
	}

	function modeLabel(m) {
		if (m === 'diagnostic') { return T.genDiag || 'Diagnóstico'; }
		if (m === 'combined') { return T.genCombined || 'Diagnóstico + prompt'; }
		if (m === 'pre_revert') { return T.revert || 'Reverter'; }
		return '';
	}

	function renderLog(entries) {
		if (!entries || !entries.length) {
			return '<p class="ce61-muted">' + esc(T.logNone || '') + '</p>';
		}
		var rows = entries.map(function (e) {
			var badge = e.published ? '<span class="ce61-tag is-pub">' + esc(T.published || '') + '</span>' : '';
			var by = e.by ? ' · ' + esc(e.by) : '';
			return '<li class="ce61-log-item">' +
				'<div class="ce61-log-meta"><b>' + esc(e.summary || '') + '</b>' + badge + '</div>' +
				'<div class="ce61-log-sub">' + esc(e.at || '') + by + ' · ' + num(e.chars) + ' car.</div>' +
				'<button type="button" class="button button-small ce61-log-revert" data-entry="' + esc(e.id) + '">' + esc(T.revert || 'Reverter') + '</button>' +
				'</li>';
		}).join('');
		return '<ul class="ce61-log">' + rows + '</ul>';
	}

	function renderDiagColumn(d) {
		var s = d.scores || {};
		var scoreVal = function (k) { return s[k] && typeof s[k].score !== 'undefined' ? s[k].score : 0; };

		var scanHtml = '';
		if (d.index) {
			scanHtml = '<div class="ce61-diag-block"><h4>' + esc(T.scanTitle || '') + '</h4>' +
				'<div class="ce61-scan"><span>SEO <b>' + (d.index.seo | 0) + '</b></span>' +
				'<span>AEO/GEO <b>' + (d.index.aeo | 0) + '</b></span></div></div>';
		}

		return '<div class="ce61-diag-msg" aria-live="polite" hidden></div>' +
			'<div class="ce61-diag-block"><h4>' + esc(T.scoresTitle || '') + '</h4>' +
				bar('E-E-A-T', scoreVal('eeat')) + bar('AEO', scoreVal('aeo')) + bar('GEO', scoreVal('geo')) +
			'</div>' +
			scanHtml +
			metaBlock(d) +
			'<div class="ce61-diag-block"><h4>' + esc(T.perfTitle || '') + '</h4>' + renderPerf(d.performance) + '</div>' +
			'<div class="ce61-diag-block"><h4>' + esc(T.issuesTitle || '') + '</h4>' + issuesListHtml(d) + '</div>' +
			'<div class="ce61-diag-block ce61-log-block"><h4>' + esc(T.logTitle || '') + '</h4>' +
				'<div class="ce61-log-wrap">' + renderLog(d.changelog) + '</div></div>';
	}

	// Re-renderiza a coluna de diagnóstico com o diagData atual (após salvar meta
	// ou corrigir uma pendência) e, opcionalmente, mostra uma mensagem no topo.
	function refreshDiag(msg, kind) {
		var col = overlay && overlay.querySelector('.ce61-col-diag');
		if (!col) { return; }
		col.innerHTML = '<h3>' + esc(T.colDiag || '') + '</h3>' + renderDiagColumn(diagData || {});
		if (msg) { diagMsg(msg, kind); }
	}

	function diagMsg(text, kind) {
		var el = overlay && overlay.querySelector('.ce61-diag-msg');
		if (!el) { return; }
		el.hidden = false;
		el.className = 'ce61-diag-msg' + (kind ? ' is-' + kind : '');
		el.textContent = text;
	}

	/* ---------- Meta título / meta descrição ---------- */

	function genMeta(field) {
		var wrap = overlay.querySelector('.ce61-meta-field[data-metafield="' + field + '"]');
		if (!wrap) { return; }
		var btn = wrap.querySelector('.ce61-meta-gen');
		var opts = wrap.querySelector('.ce61-meta-opts');
		var prev = btn.textContent;
		btn.disabled = true;
		btn.textContent = T.metaGenning || '…';
		api('editor_fix', { post_id: CFG.postId, task: field === 'title' ? 'gen_title' : 'gen_desc' }).then(function (d) {
			btn.disabled = false;
			btn.textContent = prev;
			var list = d.options || [];
			if (!list.length) { diagMsg(T.metaNoOpts || '', 'error'); return; }
			opts.hidden = false;
			opts.innerHTML = '<span class="ce61-meta-opts-hint">' + esc(T.metaOptsHint || '') + '</span>' +
				list.map(function (o) { return '<button type="button" class="ce61-meta-opt">' + esc(o) + '</button>'; }).join('');
		}).catch(function (e) {
			btn.disabled = false;
			btn.textContent = prev;
			diagMsg(e.message || (T.error || 'Erro'), 'error');
		});
	}

	function saveMeta(field) {
		var wrap = overlay.querySelector('.ce61-meta-field[data-metafield="' + field + '"]');
		if (!wrap) { return; }
		var input = wrap.querySelector('.ce61-meta-input');
		var value = input ? input.value.trim() : '';
		if (!value) { diagMsg(T.metaEmpty || '', 'error'); input && input.focus(); return; }
		var btn = wrap.querySelector('.ce61-meta-save');
		var prev = btn.textContent;
		btn.disabled = true;
		btn.textContent = T.metaSaving || '…';
		api('editor_fix', { post_id: CFG.postId, task: 'save_meta', field: field, value: value }).then(function (d) {
			if (d.diag) { diagData = d.diag; }
			refreshDiag((field === 'title' ? T.metaTitleSaved : T.metaDescSaved) || '', 'ok');
		}).catch(function (e) {
			btn.disabled = false;
			btn.textContent = prev;
			diagMsg(e.message || (T.error || 'Erro'), 'error');
		});
	}

	function fixIssue(key, btn) {
		var prev = btn.textContent;
		btn.disabled = true;
		btn.textContent = T.fixing || '…';
		api('editor_fix', { post_id: CFG.postId, task: 'issue', key: key }).then(function (d) {
			if (d.diag) { diagData = d.diag; }
			refreshDiag(d.message || T.fixed || '', 'ok');
		}).catch(function (e) {
			btn.disabled = false;
			btn.textContent = prev;
			diagMsg(e.message || (T.error || 'Erro'), 'error');
		});
	}

	/* ---------- Modal ---------- */

	var overlay = null;
	var diagData = null;   // último diagnóstico carregado
	var lastDraft = '';    // rascunho gerado aguardando aprovação
	var lastMode = '';
	var lastPrompt = '';   // último texto digitado (preservado ao refazer)
	var lastH2 = 0;        // último nº de H2 pedido (preservado ao refazer)
	var lastApproval = null; // última resposta de rascunho (p/ voltar do ajuste)

	function closeModal() {
		if (overlay) {
			overlay.parentNode && overlay.parentNode.removeChild(overlay);
			overlay = null;
			document.removeEventListener('keydown', onKey);
		}
	}

	function onKey(e) {
		if (e.key === 'Escape') { closeModal(); }
	}

	function shell(bodyHtml) {
		return '<div class="ce61-ed-modal" role="dialog" aria-modal="true" aria-label="' + esc(T.modalTitle || '') + '">' +
			'<button type="button" class="ce61-ed-close" aria-label="Fechar">&times;</button>' +
			'<h2 class="ce61-ed-title">' + esc(T.modalTitle || '') + '</h2>' +
			'<div class="ce61-ed-body">' + bodyHtml + '</div>' +
			'</div>';
	}

	function mount(bodyHtml) {
		if (!overlay) {
			overlay = document.createElement('div');
			overlay.className = 'ce61-ed-overlay';
			document.body.appendChild(overlay);
			document.addEventListener('keydown', onKey);
			overlay.addEventListener('click', function (e) {
				if (e.target === overlay) { closeModal(); }
			});
		}
		overlay.innerHTML = shell(bodyHtml);
		overlay.querySelector('.ce61-ed-close').addEventListener('click', closeModal);
	}

	function openModal() {
		if (overlay) { return; }
		mount('<p class="ce61-ed-hint">' + esc(T.loadingDiag || '') + '</p>');
		api('editor_diagnostics', { post_id: CFG.postId }).then(function (d) {
			diagData = d;
			renderForm();
		}).catch(function (e) {
			mount('<p class="ce61-ed-msg is-error">' + esc(e.message || T.noDiag || '') + '</p>');
		});
	}

	function renderForm() {
		var d = diagData || {};
		var body =
			'<p class="ce61-ed-hint">' + esc(T.modalHint || '') + '</p>' +
			'<div class="ce61-cols">' +
				'<div class="ce61-col ce61-col-diag"><h3>' + esc(T.colDiag || '') + '</h3>' + renderDiagColumn(d) + '</div>' +
				'<div class="ce61-col ce61-col-prompt"><h3>' + esc(T.colPrompt || '') + '</h3>' +
					'<textarea class="ce61-ed-text" rows="8" placeholder="' + esc(T.placeholder || '') + '">' + esc(lastPrompt) + '</textarea>' +
					'<label class="ce61-h2-field"><span>' + esc(T.h2Label || '') + '</span>' +
						'<input type="number" class="ce61-h2" min="0" max="12" step="1" placeholder="0" value="' + (lastH2 ? lastH2 : '') + '"></label>' +
					'<div class="ce61-ed-msg" aria-live="polite" hidden></div>' +
					'<div class="ce61-ed-actions">' +
						'<button type="button" class="button ce61-gen" data-mode="diagnostic">' + esc(T.genDiag || '') + '</button>' +
						'<button type="button" class="button button-primary ce61-gen" data-mode="combined">' + esc(T.genCombined || '') + '</button>' +
					'</div>' +
				'</div>' +
			'</div>';
		mount(body);
		var ta = overlay.querySelector('.ce61-ed-text');
		setTimeout(function () { ta && ta.focus(); }, 30);
	}

	function setMsg(text, kind) {
		var msg = overlay && overlay.querySelector('.ce61-ed-msg');
		if (!msg) { return; }
		msg.hidden = false;
		msg.className = 'ce61-ed-msg' + (kind ? ' is-' + kind : '');
		msg.textContent = text;
	}

	function readH2(sel) {
		var el = overlay.querySelector(sel);
		var v = el ? parseInt(el.value, 10) : 0;
		if (isNaN(v) || v < 0) { v = 0; }
		if (v > 12) { v = 12; }
		return v;
	}

	// Núcleo da geração: dispara editor_improve e mostra o rascunho para aprovação.
	function runImprove(payload, disableSel) {
		var buttons = overlay.querySelectorAll(disableSel);
		buttons.forEach(function (b) { b.disabled = true; });
		setMsg(T.working || 'Trabalhando…', 'working');
		var data = { post_id: CFG.postId, mode: payload.mode, instructions: payload.instructions, h2: payload.h2 };
		if (payload.base) { data.base = payload.base; }
		api('editor_improve', data).then(function (d) {
			lastDraft = d.draft || '';
			lastMode = payload.mode;
			lastApproval = d;
			renderApproval(d);
		}).catch(function (e) {
			buttons.forEach(function (b) { b.disabled = false; });
			setMsg(e.message || (T.error || 'Erro'), 'error');
		});
	}

	function generate(mode) {
		var ta = overlay.querySelector('.ce61-ed-text');
		var instructions = ta ? ta.value.trim() : '';
		lastPrompt = instructions;
		lastH2 = readH2('.ce61-h2');
		if (mode === 'combined' && !instructions) {
			setMsg(T.emptyCombined || '', 'error');
			ta && ta.focus();
			return;
		}
		if (ta) { ta.disabled = true; }
		runImprove({ mode: mode, instructions: instructions, h2: lastH2 }, '.ce61-gen');
	}

	function refineGenerate() {
		var ta = overlay.querySelector('.ce61-refine-text');
		var instructions = ta ? ta.value.trim() : '';
		var h2 = readH2('.ce61-refine-h2');
		if (!instructions) {
			setMsg(T.refineEmpty || '', 'error');
			ta && ta.focus();
			return;
		}
		lastH2 = h2;
		if (ta) { ta.disabled = true; }
		runImprove({ mode: 'refine', instructions: instructions, h2: h2, base: lastDraft }, '.ce61-refine-gen, .ce61-back');
	}

	function renderApproval(d) {
		var s = d.scores || {};
		var sv = function (k) { return s[k] && typeof s[k].score !== 'undefined' ? s[k].score : 0; };
		var canPub = !!d.can_publish && !d.is_published;
		var pubBtn = canPub
			? '<button type="button" class="button button-primary ce61-approve" data-publish="1">' + esc(T.approvePublish || '') + '</button>'
			: '';
		var body =
			'<p class="ce61-ed-hint">' + esc(T.draftHint || '') + '</p>' +
			'<div class="ce61-approve-scores">' +
				bar('E-E-A-T', sv('eeat')) + bar('AEO', sv('aeo')) + bar('GEO', sv('geo')) +
			'</div>' +
			'<h3>' + esc(T.draftTitle || '') + '</h3>' +
			'<div class="ce61-draft" tabindex="0">' + (d.preview || '') + '</div>' +
			'<div class="ce61-ed-msg" aria-live="polite" hidden></div>' +
			'<div class="ce61-ed-actions">' +
				'<button type="button" class="button ce61-refine">' + esc(T.refineBtn || '') + '</button>' +
				'<button type="button" class="button ce61-redo">' + esc(T.redo || '') + '</button>' +
				'<button type="button" class="button ce61-approve" data-publish="0">' + esc(T.approveDraft || '') + '</button>' +
				pubBtn +
			'</div>';
		mount(body);
	}

	// Tela de ajuste: refina o rascunho gerado com um novo pedido (mode 'refine').
	function renderRefine() {
		var prev = lastApproval && lastApproval.preview ? lastApproval.preview : '';
		var body =
			'<p class="ce61-ed-hint">' + esc(T.refineHint || '') + '</p>' +
			'<h3>' + esc(T.draftTitle || '') + '</h3>' +
			'<div class="ce61-draft is-compact" tabindex="0">' + prev + '</div>' +
			'<textarea class="ce61-refine-text" rows="4" placeholder="' + esc(T.refinePlaceholder || '') + '"></textarea>' +
			'<label class="ce61-h2-field"><span>' + esc(T.h2Label || '') + '</span>' +
				'<input type="number" class="ce61-refine-h2" min="0" max="12" step="1" placeholder="0" value="' + (lastH2 ? lastH2 : '') + '"></label>' +
			'<div class="ce61-ed-msg" aria-live="polite" hidden></div>' +
			'<div class="ce61-ed-actions">' +
				'<button type="button" class="button ce61-back">' + esc(T.backDraft || '') + '</button>' +
				'<button type="button" class="button button-primary ce61-refine-gen">' + esc(T.refineGen || '') + '</button>' +
			'</div>';
		mount(body);
		var ta = overlay.querySelector('.ce61-refine-text');
		setTimeout(function () { ta && ta.focus(); }, 30);
	}

	function applyDraft(publish) {
		var buttons = overlay.querySelectorAll('.ce61-approve, .ce61-redo');
		buttons.forEach(function (b) { b.disabled = true; });
		setMsg(T.applying || 'Aplicando…', 'working');
		api('editor_apply', { post_id: CFG.postId, content: lastDraft, publish: publish ? 1 : 0, mode: lastMode }).then(function (d) {
			setMsg(d.message || (publish ? T.appliedPub : T.applied), 'ok');
			var actions = overlay.querySelector('.ce61-ed-actions');
			actions.innerHTML = '<button type="button" class="button button-primary ce61-reload">' + esc(T.reload || 'Recarregar') + '</button>';
			actions.querySelector('.ce61-reload').addEventListener('click', function () { location.reload(); });
		}).catch(function (e) {
			buttons.forEach(function (b) { b.disabled = false; });
			setMsg(e.message || (T.error || 'Erro'), 'error');
		});
	}

	function revertEntry(entryId, btn) {
		if (!window.confirm(T.revertConfirm || '')) { return; }
		btn.disabled = true;
		btn.textContent = T.reverting || '…';
		api('editor_revert', { post_id: CFG.postId, entry_id: entryId }).then(function (d) {
			// Atualiza o histórico e as notas na coluna de diagnóstico.
			if (diagData) {
				diagData.changelog = d.changelog || [];
				diagData.scores = d.scores || diagData.scores;
			}
			var wrap = overlay.querySelector('.ce61-log-wrap');
			if (wrap) { wrap.innerHTML = renderLog(d.changelog); }
			var reloadNote = overlay.querySelector('.ce61-log-block .ce61-reverted');
			if (!reloadNote) {
				var block = overlay.querySelector('.ce61-log-block');
				if (block) {
					var p = document.createElement('p');
					p.className = 'ce61-ed-msg is-ok ce61-reverted';
					p.textContent = (T.reverted || 'Revertido.') + ' ' + (T.reload || '');
					block.appendChild(p);
				}
			}
		}).catch(function (e) {
			btn.disabled = false;
			btn.textContent = T.revert || 'Reverter';
			window.alert(e.message || (T.error || 'Erro'));
		});
	}

	/* ---------- Bindings ---------- */

	document.addEventListener('click', function (e) {
		var openBtn = e.target.closest ? e.target.closest('[data-ce61-open]') : null;
		if (openBtn) { e.preventDefault(); openModal(); return; }

		var abNode = e.target.closest ? e.target.closest('#wp-admin-bar-ce61-ai a') : null;
		if (abNode) { e.preventDefault(); openModal(); return; }

		var imgBtn = e.target.closest ? e.target.closest('[data-ce61-image]') : null;
		if (imgBtn) { e.preventDefault(); generateFeatured(imgBtn); return; }

		if (!overlay) { return; }

		var gen = e.target.closest ? e.target.closest('.ce61-gen') : null;
		if (gen) { e.preventDefault(); generate(gen.getAttribute('data-mode')); return; }

		var approve = e.target.closest ? e.target.closest('.ce61-approve') : null;
		if (approve) { e.preventDefault(); applyDraft(approve.getAttribute('data-publish') === '1'); return; }

		var redo = e.target.closest ? e.target.closest('.ce61-redo') : null;
		if (redo) { e.preventDefault(); renderForm(); return; }

		var refine = e.target.closest ? e.target.closest('.ce61-refine') : null;
		if (refine) { e.preventDefault(); renderRefine(); return; }

		var refineGen = e.target.closest ? e.target.closest('.ce61-refine-gen') : null;
		if (refineGen) { e.preventDefault(); refineGenerate(); return; }

		var back = e.target.closest ? e.target.closest('.ce61-back') : null;
		if (back) { e.preventDefault(); if (lastApproval) { renderApproval(lastApproval); } else { renderForm(); } return; }

		var rev = e.target.closest ? e.target.closest('.ce61-log-revert') : null;
		if (rev) { e.preventDefault(); revertEntry(rev.getAttribute('data-entry'), rev); return; }

		var metaGen = e.target.closest ? e.target.closest('.ce61-meta-gen') : null;
		if (metaGen) { e.preventDefault(); genMeta(metaGen.getAttribute('data-field')); return; }

		var metaSave = e.target.closest ? e.target.closest('.ce61-meta-save') : null;
		if (metaSave) { e.preventDefault(); saveMeta(metaSave.getAttribute('data-field')); return; }

		var metaOpt = e.target.closest ? e.target.closest('.ce61-meta-opt') : null;
		if (metaOpt) {
			e.preventDefault();
			var f = metaOpt.closest('.ce61-meta-field');
			var inp = f && f.querySelector('.ce61-meta-input');
			if (inp) { inp.value = metaOpt.textContent; inp.focus(); }
			var wrap = metaOpt.closest('.ce61-meta-opts');
			if (wrap) { wrap.hidden = true; }
			return;
		}

		var issueFix = e.target.closest ? e.target.closest('.ce61-issue-fix') : null;
		if (issueFix) { e.preventDefault(); fixIssue(issueFix.getAttribute('data-issue'), issueFix); return; }
	});

	/* ---------- Imagem destacada ---------- */

	function generateFeatured(btn) {
		var status = document.querySelector('.ce61-featured-status');
		function setStatus(text, kind) {
			if (!status) { return; }
			status.textContent = text;
			status.className = 'ce61-featured-status' + (kind ? ' is-' + kind : '');
		}
		if (!CFG.canImage) {
			setStatus(T.imgNoKey || '', 'error');
			return;
		}
		btn.disabled = true;
		setStatus(T.imgWorking || 'Gerando…', 'working');
		api('image_generate', { post_id: CFG.postId, aspect: CFG.imageAspect || 'wide' }).then(function (d) {
			btn.disabled = false;
			setStatus(T.imgDone || 'Pronto.', 'ok');
			if (d && d.attachment_id && window.wp && wp.media && wp.media.featuredImage && typeof wp.media.featuredImage.set === 'function') {
				wp.media.featuredImage.set(d.attachment_id);
			} else if (status) {
				var img = d && (d.thumb || d.url);
				status.innerHTML = esc(T.imgDone || '') + (img ? '<br><img src="' + esc(img) + '" alt="" style="max-width:100%;height:auto;margin-top:6px;border-radius:6px">' : '');
			}
		}).catch(function (e) {
			btn.disabled = false;
			setStatus(e.message || (T.error || 'Erro'), 'error');
		});
	}
})();
