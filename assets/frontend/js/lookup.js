/**
 * Guest booking status lookup.
 */
(function () {
	'use strict';
	var cfg = window.primeFleetLookup || {};
	var root = document.getElementById('prime-fleet-lookup');
	if (!root || !cfg.restUrl) {
		return;
	}
	var i18n = cfg.i18n || {};

	function escapeHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function statusLabel(code) {
		var map = {
			pending_confirmation: i18n.statusPending || 'Pending',
			confirmed: i18n.statusConfirmed || 'Confirmed',
			cancelled: i18n.statusCancelled || 'Cancelled',
			completed: i18n.statusCompleted || 'Completed',
			no_show: i18n.statusNoShow || 'No-show'
		};
		return map[String(code || '')] || code || '';
	}

	function paymentLabel(code) {
		var map = {
			unpaid: i18n.payUnpaid || 'Unpaid',
			link_sent: i18n.payLinkSent || 'Link sent',
			pending_verification: i18n.payPending || 'Pending verification',
			paid: i18n.payPaid || 'Paid',
			refunded: i18n.payRefunded || 'Refunded'
		};
		return map[String(code || '')] || code || '';
	}

	function render(formErr, result) {
		var body =
			'<header class="prime-fleet__header"><p class="prime-fleet__brand"><span class="prime-fleet__brand-mark">PF</span> ' +
			escapeHtml(i18n.brand || 'Prime Fleet') +
			'</p>' +
			'<h1 class="prime-fleet__title">' +
			escapeHtml(i18n.title || 'Check booking status') +
			'</h1></header><div class="prime-fleet__main">';

		if (result) {
			body +=
				'<ul class="prime-fleet__review">' +
				'<li><strong>' +
				escapeHtml(i18n.reference || 'Reference') +
				'</strong>' +
				escapeHtml(result.reference || '') +
				'</li>' +
				'<li><strong>' +
				escapeHtml(i18n.status || 'Status') +
				'</strong>' +
				escapeHtml(statusLabel(result.status)) +
				'</li>' +
				'<li><strong>' +
				escapeHtml(i18n.pickup || 'Pickup') +
				'</strong>' +
				escapeHtml(result.pickup_datetime || '—') +
				'</li>' +
				'<li><strong>' +
				escapeHtml(i18n.payment || 'Payment') +
				'</strong>' +
				escapeHtml(paymentLabel(result.payment_status)) +
				'</li>' +
				'<li><strong>' +
				escapeHtml(i18n.fare || 'Fare') +
				'</strong>' +
				escapeHtml(
					result.manual_quote
						? i18n.customQuote || 'Custom quote'
						: (result.currency || 'USD') +
								' ' +
								(result.fare_total != null ? Number(result.fare_total).toFixed(2) : '—')
				) +
				'</li></ul>';
		} else {
			body +=
				'<div class="prime-fleet__grid"><div class="prime-fleet__field"><label for="pf-look-ref">' +
				escapeHtml(i18n.ref || 'Booking reference') +
				'</label><input type="text" id="pf-look-ref" name="reference" autocomplete="off" /></div>' +
				'<div class="prime-fleet__field"><label for="pf-look-email">' +
				escapeHtml(i18n.email || 'Email') +
				'</label><input type="email" id="pf-look-email" name="email" autocomplete="email" /></div></div>' +
				(formErr ? '<p class="prime-fleet__error" role="alert">' + escapeHtml(formErr) + '</p>' : '') +
				'<div class="prime-fleet__actions"><button type="button" class="prime-fleet__btn prime-fleet__btn--primary" data-action="lookup">' +
				escapeHtml(i18n.submit || 'Look up') +
				'</button></div>';
		}
		body += '</div>';
		root.innerHTML = body;
	}

	root.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-action="lookup"]');
		if (!btn) {
			return;
		}
		var ref = (root.querySelector('[name="reference"]') || {}).value || '';
		var email = (root.querySelector('[name="email"]') || {}).value || '';
		btn.disabled = true;
		fetch(String(cfg.restUrl).replace(/\/$/, '') + '/lookup', {
			method: 'POST',
			headers: {
				Accept: 'application/json',
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce || '',
			},
			body: JSON.stringify({ reference: ref, email: email }),
		})
			.then(function (res) {
				return res.json().then(function (data) {
					if (!res.ok) {
						throw new Error((data && data.message) || i18n.fail || 'Not found');
					}
					return data;
				});
			})
			.then(function (data) {
				render('', data);
			})
			.catch(function (err) {
				render(err.message || i18n.fail || 'Not found');
			});
	});

	render();
})();
