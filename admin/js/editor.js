/**
 * Cluster Engine — extensão no editor.
 * Modal de "melhorar conteúdo com IA" (barra superior + meta box) e botão de
 * gerar imagem destacada com IA. Autossuficiente, sem dependência do app SPA.
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

	/* ---------- Modal ---------- */

	var overlay = null;

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

	function openModal() {
		if (overlay) { return; }
		overlay = document.createElement('div');
		overlay.className = 'ce61-ed-overlay';
		overlay.innerHTML =
			'<div class="ce61-ed-modal" role="dialog" aria-modal="true" aria-label="' + esc(T.modalTitle || '') + '">' +
				'<button type="button" class="ce61-ed-close" aria-label="Fechar">&times;</button>' +
				'<h2 class="ce61-ed-title">' + esc(T.modalTitle || '') + '</h2>' +
				'<p class="ce61-ed-hint">' + esc(T.modalHint || '') + '</p>' +
				'<textarea class="ce61-ed-text" rows="6" placeholder="' + esc(T.placeholder || '') + '"></textarea>' +
				'<div class="ce61-ed-msg" aria-live="polite" hidden></div>' +
				'<div class="ce61-ed-actions">' +
					'<button type="button" class="button ce61-ed-cancel">' + esc(T.cancel || 'Cancelar') + '</button>' +
					'<button type="button" class="button button-primary ce61-ed-send">' + esc(T.send || 'Melhorar') + '</button>' +
				'</div>' +
			'</div>';
		document.body.appendChild(overlay);
		document.addEventListener('keydown', onKey);

		var ta = overlay.querySelector('.ce61-ed-text');
		var msg = overlay.querySelector('.ce61-ed-msg');
		var sendBtn = overlay.querySelector('.ce61-ed-send');
		var cancelBtn = overlay.querySelector('.ce61-ed-cancel');

		overlay.querySelector('.ce61-ed-close').addEventListener('click', closeModal);
		cancelBtn.addEventListener('click', closeModal);
		overlay.addEventListener('click', function (e) {
			if (e.target === overlay) { closeModal(); }
		});
		setTimeout(function () { ta.focus(); }, 30);

		function setMsg(text, kind) {
			msg.hidden = false;
			msg.className = 'ce61-ed-msg' + (kind ? ' is-' + kind : '');
			msg.textContent = text;
		}

		sendBtn.addEventListener('click', function () {
			var instructions = ta.value.trim();
			if (!instructions) { setMsg(T.empty || 'Descreva o que atualizar.', 'error'); ta.focus(); return; }
			sendBtn.disabled = true;
			cancelBtn.disabled = true;
			ta.disabled = true;
			setMsg(T.working || 'Trabalhando…', 'working');
			api('editor_improve', { post_id: CFG.postId, instructions: instructions }).then(function () {
				setMsg(T.done || 'Pronto.', 'ok');
				var actions = overlay.querySelector('.ce61-ed-actions');
				actions.innerHTML = '<button type="button" class="button button-primary ce61-ed-reload">' + esc(T.reload || 'Recarregar') + '</button>';
				actions.querySelector('.ce61-ed-reload').addEventListener('click', function () { location.reload(); });
			}).catch(function (e) {
				sendBtn.disabled = false;
				cancelBtn.disabled = false;
				ta.disabled = false;
				setMsg(e.message || (T.error || 'Erro'), 'error');
			});
		});
	}

	/* ---------- Bindings ---------- */

	// Botão da meta box.
	document.addEventListener('click', function (e) {
		var openBtn = e.target.closest ? e.target.closest('[data-ce61-open]') : null;
		if (openBtn) { e.preventDefault(); openModal(); return; }

		// Ícone da barra superior do admin.
		var abNode = e.target.closest ? e.target.closest('#wp-admin-bar-ce61-ai a') : null;
		if (abNode) { e.preventDefault(); openModal(); return; }

		// Botão de gerar imagem destacada com IA.
		var imgBtn = e.target.closest ? e.target.closest('[data-ce61-image]') : null;
		if (imgBtn) { e.preventDefault(); generateFeatured(imgBtn); return; }
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
				// Fallback: prévia inline + aviso para recarregar.
				var img = d && (d.thumb || d.url);
				status.innerHTML = esc(T.imgDone || '') + (img ? '<br><img src="' + esc(img) + '" alt="" style="max-width:100%;height:auto;margin-top:6px;border-radius:6px">' : '');
			}
		}).catch(function (e) {
			btn.disabled = false;
			setStatus(e.message || (T.error || 'Erro'), 'error');
		});
	}
})();
