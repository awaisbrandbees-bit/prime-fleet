/**
 * Slot offer accept page.
 */
(function () {
	'use strict';
	var cfg = window.primeFleetOffer || {};
	var root = document.getElementById('prime-fleet-offer');
	if (!root || !cfg.restUrl) {
		return;
	}
	var i18n = cfg.i18n || {};
	var token = cfg.token || '';
	var homeUrl = cfg.homeUrl || '/';
	var accepting = false;

	function escapeHtml(str) {
		return String(str == null ? '' : str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function formatDt(raw) {
		if (!raw) {
			return '—';
		}
		var s = String(raw).replace('T', ' ');
		var d = new Date(s.replace(/-/g, '/'));
		if (isNaN(d.getTime())) {
			return s;
		}
		try {
			return d.toLocaleString(undefined, {
				weekday: 'short',
				month: 'short',
				day: 'numeric',
				year: 'numeric',
				hour: 'numeric',
				minute: '2-digit',
			});
		} catch (e) {
			return s;
		}
	}

	function api(path, options) {
		var opts = options || {};
		var headers = Object.assign({ Accept: 'application/json' }, opts.headers || {});
		if (cfg.nonce) {
			headers['X-WP-Nonce'] = cfg.nonce;
		}
		if (opts.body && typeof opts.body === 'object') {
			headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(opts.body);
		}
		return fetch(String(cfg.restUrl).replace(/\/$/, '') + path, Object.assign({}, opts, { headers: headers })).then(
			function (res) {
				return res.json().then(function (data) {
					if (!res.ok) {
						throw new Error((data && data.message) || i18n.fail || 'Failed');
					}
					return data;
				});
			}
		);
	}

	function renderBoot(label) {
		root.classList.remove('is-accepting');
		root.innerHTML =
			'<div class="prime-fleet-offer__card prime-fleet-offer__card--boot">' +
			'<span class="prime-fleet__spinner" aria-hidden="true"></span>' +
			'<p class="prime-fleet-offer__boot-text">' +
			escapeHtml(label || i18n.loading || 'Loading offer…') +
			'</p></div>';
	}

	function renderError(msg) {
		root.classList.remove('is-accepting');
		root.innerHTML =
			'<div class="prime-fleet-offer__card">' +
			'<p class="prime-fleet__brand prime-fleet__brand--center"><span class="prime-fleet__brand-mark" aria-hidden="true">PF</span><span>Prime Fleet</span></p>' +
			'<h1 class="prime-fleet-offer__title">' +
			escapeHtml(i18n.unavailable || 'Offer unavailable') +
			'</h1>' +
			'<p class="prime-fleet-offer__copy" role="alert">' +
			escapeHtml(msg || i18n.fail || 'This offer is not available.') +
			'</p>' +
			'<div class="prime-fleet__success-actions">' +
			'<a class="prime-fleet__btn prime-fleet__btn--primary" href="' +
			escapeHtml(homeUrl) +
			'">' +
			escapeHtml(i18n.goHome || 'Back to home') +
			'</a></div></div>';
	}

	function renderOffer(data) {
		root.classList.remove('is-accepting');
		root.innerHTML =
			'<div class="prime-fleet-offer__card">' +
			'<p class="prime-fleet__brand"><span class="prime-fleet__brand-mark" aria-hidden="true">PF</span><span>Prime Fleet</span></p>' +
			'<h1 class="prime-fleet-offer__title">' +
			escapeHtml(i18n.title || 'Alternative pickup time') +
			'</h1>' +
			'<p class="prime-fleet-offer__copy">' +
			escapeHtml(
				i18n.intro ||
					'Your requested window was full. Accept this alternative pickup if it works for you.'
			) +
			'</p>' +
			'<dl class="prime-fleet-offer__meta">' +
			'<div><dt>' +
			escapeHtml(i18n.reference || 'Reference') +
			'</dt><dd class="prime-fleet-offer__ref">' +
			escapeHtml(data.reference || '—') +
			'</dd></div>' +
			'<div><dt>' +
			escapeHtml(i18n.current || 'Current pickup') +
			'</dt><dd>' +
			escapeHtml(formatDt(data.current_pickup)) +
			'</dd></div>' +
			'<div class="prime-fleet-offer__meta-highlight"><dt>' +
			escapeHtml(i18n.proposed || 'Proposed pickup') +
			'</dt><dd>' +
			escapeHtml(formatDt(data.proposed_datetime)) +
			'</dd></div>' +
			'</dl>' +
			'<div class="prime-fleet__actions prime-fleet-offer__actions">' +
			'<button type="button" class="prime-fleet__btn prime-fleet__btn--primary" data-action="accept">' +
			escapeHtml(i18n.accept || 'Accept this time') +
			'</button></div></div>';
	}

	function renderAccepting() {
		root.classList.add('is-accepting');
		root.innerHTML =
			'<div class="prime-fleet-offer__card prime-fleet-offer__card--boot">' +
			'<span class="prime-fleet__spinner" aria-hidden="true"></span>' +
			'<p class="prime-fleet-offer__boot-text">' +
			escapeHtml(i18n.accepting || 'Accepting offer…') +
			'</p></div>';
	}

	function renderSuccess(data) {
		root.classList.remove('is-accepting');
		var ref = data.reference || '';
		root.innerHTML =
			'<div class="prime-fleet-offer__card prime-fleet-offer__card--success">' +
			'<p class="prime-fleet__brand prime-fleet__brand--center"><span class="prime-fleet__brand-mark" aria-hidden="true">PF</span><span>Prime Fleet</span></p>' +
			'<p class="prime-fleet__success-eyebrow">' +
			escapeHtml(i18n.acceptedEyebrow || 'Offer accepted') +
			'</p>' +
			'<h1 class="prime-fleet__success-title">' +
			escapeHtml(i18n.acceptedTitle || "You're all set") +
			'</h1>' +
			'<div class="prime-fleet__ref-block">' +
			'<p class="prime-fleet__ref-label">' +
			escapeHtml(i18n.reference || 'Reference') +
			'</p>' +
			'<p class="prime-fleet__ref">' +
			escapeHtml(ref) +
			'</p></div>' +
			'<p class="prime-fleet__success-copy">' +
			escapeHtml(
				data.message ||
					i18n.acceptedCopy ||
					'Thank you — your new pickup time is confirmed pending Prime Fleet final review.'
			) +
			'</p>' +
			'<div class="prime-fleet-offer__pickup-chip">' +
			'<span>' +
			escapeHtml(i18n.newPickup || 'New pickup') +
			'</span><strong>' +
			escapeHtml(formatDt(data.pickup_datetime)) +
			'</strong></div>' +
			'<div class="prime-fleet__success-actions">' +
			'<a class="prime-fleet__btn prime-fleet__btn--primary" href="' +
			escapeHtml(homeUrl) +
			'">' +
			escapeHtml(i18n.goHome || 'Back to home') +
			'</a>' +
			(cfg.lookupUrl
				? '<a class="prime-fleet__btn prime-fleet__btn--ghost" href="' +
				  escapeHtml(cfg.lookupUrl) +
				  '">' +
				  escapeHtml(i18n.checkStatus || 'Check booking status') +
				  '</a>'
				: '') +
			'</div>' +
			'<p class="prime-fleet__success-hint">' +
			escapeHtml(i18n.keepRef || 'Keep your reference handy for updates.') +
			'</p></div>';
	}

	if (!token) {
		renderError(i18n.fail || 'Offer not found.');
		return;
	}

	renderBoot(i18n.loading || 'Loading offer…');

	api('/offers/' + encodeURIComponent(token))
		.then(renderOffer)
		.catch(function (err) {
			renderError(err.message || i18n.fail);
		});

	root.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-action="accept"]');
		if (!btn || accepting) {
			return;
		}
		accepting = true;
		renderAccepting();
		api('/offers/' + encodeURIComponent(token), { method: 'POST', body: { action: 'accept' } })
			.then(function (data) {
				accepting = false;
				renderSuccess(data);
			})
			.catch(function (err) {
				accepting = false;
				renderError(err.message || i18n.fail);
			});
	});
})();
