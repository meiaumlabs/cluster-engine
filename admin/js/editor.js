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

	function collectIssues(d) {
		var out = [];
		var s = d.scores || {};
		[['eeat', 'E-E-A-T'], ['aeo', 'AEO'], ['geo', 'GEO']].forEach(function (pair) {
			var grp = s[pair[0]];
			if (grp && grp.issues && grp.issues.length) {
				grp.issues.forEach(function (it) {
					if (it && it.label) { out.push('[' + pair[1] + '] ' + it.label); }
				});
			}
		});
		if (d.index && d.index.issues && d.index.issues.length) {
			d.index.issues.forEach(function (it) { out.push('[SEO] ' + it); });
		}
		// dedup
		var seen = {}, uniq = [];
		out.forEach(function (x) { if (!seen[x]) { seen[x] = 1; uniq.push(x); } });
		return uniq;
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
		var issues = collectIssues(d);
		var issuesHtml = issues.length
			? '<ul class="ce61-issues">' + issues.map(function (i) { return '<li>' + esc(i) + '</li>'; }).join('') + '</ul>'
			: '<p class="ce61-muted">' + esc(T.issuesNone || '') + '</p>';

		var scanHtml = '';
		if (d.index) {
			scanHtml = '<div class="ce61-diag-block"><h4>' + esc(T.scanTitle || '') + '</h4>' +
				'<div class="ce61-scan"><span>SEO <b>' + (d.index.seo | 0) + '</b></span>' +
				'<span>AEO/GEO <b>' + (d.index.aeo | 0) + '</b></span></div></div>';
		}

		return '<div class="ce61-diag-block"><h4>' + esc(T.scoresTitle || '') + '</h4>' +
				bar('E-E-A-T', scoreVal('eeat')) + bar('AEO', scoreVal('aeo')) + bar('GEO', scoreVal('geo')) +
			'</div>' +
			scanHtml +
			'<div class="ce61-diag-block"><h4>' + esc(T.perfTitle || '') + '</h4>' + renderPerf(d.performance) + '</div>' +
			'<div class="ce61-diag-block"><h4>' + esc(T.issuesTitle || '') + '</h4>' + issuesHtml + '</div>' +
			'<div class="ce61-diag-block ce61-log-block"><h4>' + esc(T.logTitle || '') + '</h4>' +
				'<div class="ce61-log-wrap">' + renderLog(d.changelog) + '</div></div>';
	}

	/* ---------- Modal ---------- */

	var overlay = null;
	var diagData = null;   // último diagnóstico carregado
	var lastDraft = '';    // rascunho gerado aguardando aprovação
	var lastMode = '';
	var lastPrompt = '';   // último texto digitado (preservado ao refazer)

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

	function generate(mode) {
		var ta = overlay.querySelector('.ce61-ed-text');
		var instructions = ta ? ta.value.trim() : '';
		lastPrompt = instructions;
		if (mode === 'combined' && !instructions) {
			setMsg(T.emptyCombined || '', 'error');
			ta && ta.focus();
			return;
		}
		var buttons = overlay.querySelectorAll('.ce61-gen');
		buttons.forEach(function (b) { b.disabled = true; });
		if (ta) { ta.disabled = true; }
		setMsg(T.working || 'Trabalhando…', 'working');
		api('editor_improve', { post_id: CFG.postId, mode: mode, instructions: instructions }).then(function (d) {
			lastDraft = d.draft || '';
			lastMode = mode;
			renderApproval(d);
		}).catch(function (e) {
			buttons.forEach(function (b) { b.disabled = false; });
			if (ta) { ta.disabled = false; }
			setMsg(e.message || (T.error || 'Erro'), 'error');
		});
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
				'<button type="button" class="button ce61-redo">' + esc(T.redo || '') + '</button>' +
				'<button type="button" class="button ce61-approve" data-publish="0">' + esc(T.approveDraft || '') + '</button>' +
				pubBtn +
			'</div>';
		mount(body);
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

		var rev = e.target.closest ? e.target.closest('.ce61-log-revert') : null;
		if (rev) { e.preventDefault(); revertEntry(rev.getAttribute('data-entry'), rev); return; }
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
