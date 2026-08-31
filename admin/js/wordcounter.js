/**
 * Cluster Engine — Word Counter.
 *
 * Análise detalhada de texto ao vivo no editor: contagem de palavras/caracteres,
 * frases, parágrafos, tempo de leitura e de fala, legibilidade (escala Flesch
 * adaptada ao PT-BR) e densidade de palavras-chave.
 *
 * Sem dependências: lê o conteúdo do editor de blocos (via wp.data) ou do editor
 * clássico (TinyMCE / textarea) e recalcula conforme o texto muda.
 */
(function () {
	'use strict';

	var CFG = window.CE61_WC || {};
	var I18N = CFG.i18n || {};
	var READING_WPM = CFG.readingWpm || 200;
	var SPEAKING_WPM = CFG.speakingWpm || 130;
	var TOP_KEYWORDS = CFG.topKeywords || 8;
	var STOP = {};
	(CFG.stopwords || []).forEach(function (w) { STOP[w] = 1; });

	var box, lastHtml = '';

	function t(key, fallback) { return I18N[key] || fallback || key; }

	function esc(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	/* ---------- Aquisição do conteúdo (bloco ou clássico) ---------- */

	function getHtml() {
		// Editor de blocos (Gutenberg).
		if (window.wp && wp.data && typeof wp.data.select === 'function') {
			var ed = wp.data.select('core/editor');
			if (ed && typeof ed.getEditedPostContent === 'function') {
				try { return ed.getEditedPostContent() || ''; } catch (e) {}
			}
		}
		// Editor clássico — TinyMCE ativo (modo visual).
		if (window.tinymce) {
			var tm = window.tinymce.get('content');
			if (tm && !tm.isHidden()) {
				try { return tm.getContent({ format: 'html' }) || ''; } catch (e) {}
			}
		}
		// Editor clássico — modo texto (textarea).
		var ta = document.getElementById('content');
		if (ta && typeof ta.value === 'string') { return ta.value; }
		return '';
	}

	/* ---------- Texto puro a partir do HTML ---------- */

	function htmlToText(html) {
		// Remove comentários de bloco do Gutenberg (<!-- wp:... -->) e shortcodes.
		var clean = html.replace(/<!--[\s\S]*?-->/g, ' ').replace(/\[[^\]]+\]/g, ' ');
		var div = document.createElement('div');
		div.innerHTML = clean;
		var text = div.textContent || div.innerText || '';
		return text.replace(/\u00a0/g, ' ');
	}

	function countParagraphs(html, text) {
		var m = html.match(/<\/(p|h[1-6]|li|blockquote|pre|figcaption|td|th|dd|dt)>/gi);
		if (m && m.length) { return m.length; }
		var parts = text.split(/\n{2,}/).filter(function (p) { return p.trim().length; });
		return parts.length;
	}

	/* ---------- Tokenização ---------- */

	function words(text) {
		var m = text.toLowerCase().match(/[\p{L}\p{N}]+(?:['’\-][\p{L}\p{N}]+)*/gu);
		return m || [];
	}

	function sentences(text) {
		var parts = text.split(/[.!?…]+(?:\s|$)/).filter(function (s) { return s.trim().length; });
		return parts.length;
	}

	/* ---------- Sílabas (estimativa PT-BR por grupos de vogais) ---------- */

	function syllables(word) {
		var w = word.toLowerCase().replace(/[^a-zà-ÿ]/g, '');
		if (!w) { return 0; }
		var groups = w.match(/[aeiouáéíóúâêôàãõäëïöüy]+/g);
		var n = groups ? groups.length : 0;
		return n < 1 ? 1 : n;
	}

	/* ---------- Densidade de palavras-chave (unigramas + bigramas) ---------- */

	function keywordDensity(wordList) {
		var total = wordList.length;
		if (!total) { return { total: 0, items: [] }; }
		var clean = wordList.map(function (w) {
			if (w.length < 3 || STOP[w] || /^\p{N}+$/u.test(w)) { return null; }
			return w;
		});
		var counts = {};
		clean.forEach(function (w) { if (w) { counts[w] = (counts[w] || 0) + 1; } });
		for (var i = 0; i < clean.length - 1; i++) {
			if (clean[i] && clean[i + 1]) {
				var bg = clean[i] + ' ' + clean[i + 1];
				counts[bg] = (counts[bg] || 0) + 1;
			}
		}
		var items = Object.keys(counts).map(function (k) {
			return { term: k, count: counts[k], pct: (counts[k] / total) * 100 };
		}).filter(function (it) { return it.count > 1 || it.term.indexOf(' ') === -1; });
		items.sort(function (a, b) {
			if (b.count !== a.count) { return b.count - a.count; }
			return b.term.length - a.term.length;
		});
		return { total: total, items: items.slice(0, TOP_KEYWORDS) };
	}

	/* ---------- Legibilidade: Flesch adaptado ao PT-BR ---------- */

	function readability(wordList, sentenceCount) {
		var nWords = wordList.length;
		if (!nWords || !sentenceCount) { return null; }
		var syl = 0;
		wordList.forEach(function (w) { syl += syllables(w); });
		var asl = nWords / sentenceCount;      // average sentence length (palavras/frase)
		var asw = syl / nWords;                // average syllables per word
		// Constante 248.835 = adaptação de Martins et al. da fórmula de Flesch ao PT-BR.
		var score = 248.835 - (1.015 * asl) - (84.6 * asw);
		score = Math.max(0, Math.min(100, score));
		var label, cls;
		if (score >= 80) { label = t('readVeryEasy'); cls = 'vgood'; }
		else if (score >= 60) { label = t('readEasy'); cls = 'good'; }
		else if (score >= 40) { label = t('readMedium'); cls = 'ok'; }
		else if (score >= 20) { label = t('readHard'); cls = 'warn'; }
		else { label = t('readVeryHard'); cls = 'bad'; }
		return { score: Math.round(score), label: label, cls: cls };
	}

	/* ---------- Formatação de tempo ---------- */

	function fmtTime(totalWords, wpm) {
		var secs = wpm > 0 ? Math.round((totalWords / wpm) * 60) : 0;
		if (secs < 60) { return secs + ' ' + t('sec', 's'); }
		var min = Math.floor(secs / 60);
		var rem = secs % 60;
		return rem ? (min + ' ' + t('min', 'min') + ' ' + rem + ' ' + t('sec', 's')) : (min + ' ' + t('min', 'min'));
	}

	/* ---------- Render ---------- */

	function statRow(label, value) {
		return '<div class="ce61-wc-row"><span class="ce61-wc-k">' + esc(label) + '</span>' +
			'<span class="ce61-wc-v">' + esc(value) + '</span></div>';
	}

	function render(data) {
		if (!data.words) {
			box.innerHTML = '<p class="ce61-wc-empty">' + esc(t('empty')) + '</p>';
			return;
		}
		var html = '';

		// Contagem.
		html += '<div class="ce61-wc-sec">' +
			'<h4 class="ce61-wc-h">' + esc(t('basicTitle')) + '</h4>' +
			statRow(t('words'), data.words.toLocaleString('pt-BR')) +
			statRow(t('chars'), data.chars.toLocaleString('pt-BR')) +
			statRow(t('charsNoSpaces'), data.charsNoSpaces.toLocaleString('pt-BR')) +
			statRow(t('sentences'), data.sentences.toLocaleString('pt-BR')) +
			statRow(t('paragraphs'), data.paragraphs.toLocaleString('pt-BR')) +
			statRow(t('avgWordsSent'), data.avgWordsSent) +
			'</div>';

		// Tempo estimado.
		html += '<div class="ce61-wc-sec">' +
			'<h4 class="ce61-wc-h">' + esc(t('timeTitle')) + '</h4>' +
			statRow(t('readingTime'), data.readingTime) +
			statRow(t('speakingTime'), data.speakingTime) +
			'</div>';

		// Legibilidade.
		if (data.readability) {
			var r = data.readability;
			html += '<div class="ce61-wc-sec">' +
				'<h4 class="ce61-wc-h">' + esc(t('readability')) + '</h4>' +
				'<div class="ce61-wc-read ce61-wc-' + r.cls + '">' +
					'<span class="ce61-wc-score">' + r.score + '</span>' +
					'<span class="ce61-wc-read-label">' + esc(r.label) + '</span>' +
				'</div>' +
				'<div class="ce61-wc-bar"><i class="ce61-wc-' + r.cls + '" style="width:' + r.score + '%"></i></div>' +
			'</div>';
		}

		// Densidade de palavras-chave.
		html += '<div class="ce61-wc-sec">' +
			'<h4 class="ce61-wc-h">' + esc(t('density')) + '</h4>';
		if (data.density.items.length) {
			html += '<ul class="ce61-wc-kw">';
			data.density.items.forEach(function (it) {
				html += '<li><span class="ce61-wc-term">' + esc(it.term) + '</span>' +
					'<span class="ce61-wc-kwmeta">' + it.count + '× · ' + it.pct.toFixed(1) + '%</span></li>';
			});
			html += '</ul>';
		} else {
			html += '<p class="ce61-wc-empty">' + esc(t('densityEmpty')) + '</p>';
		}
		html += '</div>';

		box.innerHTML = html;
	}

	/* ---------- Cálculo ---------- */

	function analyze() {
		var html = getHtml();
		if (html === lastHtml) { return; }
		lastHtml = html;

		var text = htmlToText(html).replace(/\s+/g, ' ').trim();
		var rawText = htmlToText(html);
		var wl = words(text);
		var sentCount = sentences(text);
		var paraCount = countParagraphs(html, rawText);
		var charsWith = text.length;
		var charsNo = text.replace(/\s+/g, '').length;

		render({
			words: wl.length,
			chars: charsWith,
			charsNoSpaces: charsNo,
			sentences: sentCount,
			paragraphs: paraCount,
			avgWordsSent: sentCount ? (wl.length / sentCount).toFixed(1) : '0',
			readingTime: fmtTime(wl.length, READING_WPM),
			speakingTime: fmtTime(wl.length, SPEAKING_WPM),
			readability: readability(wl, sentCount),
			density: keywordDensity(wl)
		});
	}

	/* ---------- Bootstrap ---------- */

	function start() {
		box = document.getElementById('ce61-wc');
		if (!box) { return; }
		analyze();
		// Atualização instantânea no editor de blocos.
		if (window.wp && wp.data && typeof wp.data.subscribe === 'function') {
			var scheduled = false;
			wp.data.subscribe(function () {
				if (scheduled) { return; }
				scheduled = true;
				setTimeout(function () { scheduled = false; analyze(); }, 400);
			});
		}
		// Fallback robusto que cobre o editor clássico (TinyMCE e textarea).
		setInterval(analyze, 800);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
