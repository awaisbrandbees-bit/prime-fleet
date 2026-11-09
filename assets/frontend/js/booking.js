/**
 * Prime Fleet booking wizard — premium config-driven UI.
 */
(function () {
	'use strict';

	var cfg = window.primeFleetBooking || {};
	var root = document.getElementById('prime-fleet');
	if (!root || !cfg.restUrl) {
		return;
	}
	if (document.body) {
		document.body.classList.add('prime-fleet-has-booking');
	}

	function applyFormTheme(theme) {
		var t = theme === 'light' ? 'light' : 'dark';
		root.setAttribute('data-theme', t);
		if (t === 'light') {
			root.style.background = '#ffffff';
			root.style.color = '#0b1220';
		} else {
			root.style.background = '#0b1220';
			root.style.color = '#f8fafc';
		}
	}
	applyFormTheme(cfg.formTheme || 'dark');

	function prefersReducedMotion() {
		return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	}

	function rippleLayer() {
		var layer = document.querySelector('.prime-fleet__ripple-layer');
		if (!layer) {
			layer = document.createElement('div');
			layer.className = 'prime-fleet__ripple-layer';
			layer.setAttribute('aria-hidden', 'true');
			document.body.appendChild(layer);
		}
		return layer;
	}

	function spawnRipple(e) {
		if (prefersReducedMotion() || !e || typeof e.clientX !== 'number') {
			return;
		}
		var span = document.createElement('span');
		span.className = 'prime-fleet__ripple';
		span.style.left = e.clientX + 'px';
		span.style.top = e.clientY + 'px';
		rippleLayer().appendChild(span);
		window.setTimeout(function () {
			if (span.parentNode) {
				span.parentNode.removeChild(span);
			}
		}, 480);
	}

	function swapMain(runPaint) {
		var mainEl = root.querySelector('[data-pf-main]');
		if (!mainEl || prefersReducedMotion()) {
			runPaint();
			return;
		}
		mainEl.classList.remove('is-enter');
		mainEl.classList.add('is-exit');
		window.setTimeout(function () {
			runPaint();
			mainEl.classList.remove('is-exit');
			void mainEl.offsetWidth;
			mainEl.classList.add('is-enter');
		}, 200);
	}

	var STEPS = [
		{ key: 'service', label: 'Service' },
		{ key: 'trip', label: 'Trip' },
		{ key: 'schedule', label: 'Schedule' },
		{ key: 'passengers', label: 'Guests' },
		{ key: 'vehicle', label: 'Vehicle' },
		{ key: 'addons', label: 'Add-ons' },
		{ key: 'contact', label: 'Contact' },
		{ key: 'review', label: 'Review' },
	];
	var DRAFT_KEY = 'prime_fleet_booking_draft_v1';
	var URL_STEP = 'pf_step';
	var URL_REF = 'pf_ref';

	var SERVICE_GROUPS = [
		{
			id: 'airport',
			label: 'Airport & aviation',
			match: function (s) {
				var k = (s.slug + ' ' + s.name).toLowerCase();
				return (
					s.booking_type === 'route' &&
					(/airport|fbo|aviation|marina/.test(k))
				);
			},
		},
		{
			id: 'transfer',
			label: 'Private transfers',
			match: function (s) {
				var k = (s.slug + ' ' + s.name).toLowerCase();
				return (
					s.booking_type === 'route' &&
					!(/airport|fbo|aviation|marina/.test(k))
				);
			},
		},
		{
			id: 'hourly',
			label: 'Hourly chauffeur',
			match: function (s) {
				return s.booking_type === 'hourly';
			},
		},
		{
			id: 'experience',
			label: 'Experiences',
			match: function (s) {
				return s.booking_type === 'package';
			},
		},
		{
			id: 'custom',
			label: 'Custom',
			match: function (s) {
				return s.booking_type === 'quote';
			},
		},
	];

	var BLURBS = {
		airport: 'PLS / FBO · meet & greet included',
		'airport-vip-transfer': 'PLS / FBO · meet & greet included',
		'point-to-point': 'Resort, villa, marina, dinner',
		'point-to-point-private-transfer': 'Resort, villa, marina, dinner',
		'standard-vip-chauffeur': 'Hourly · minimum applies',
		'premium-vip-chauffeur': 'Hourly · elevated chauffeur',
		'wedding-event-standby': 'Event standby · custom hours',
		'explore-provo': 'Half-day island experience',
		'island-lifestyle-experience': 'Curated lifestyle day',
		'ultimate-full-day-vip': 'Full-day VIP itinerary',
		nightlife: 'Late evening transport',
		'nightlife-transportation': 'Late evening transport',
		dinner: 'Restaurant transfers',
		'dinner-transportation': 'Restaurant transfers',
		marina: 'Blue Haven, Turtle Cove',
		'marina-transfer': 'Blue Haven, Turtle Cove',
		fbo: 'Private aviation / FBO',
		'private-aviation-fbo-transfer': 'Private aviation / FBO',
		excursion: 'Guided excursion transport',
		custom: 'Wedding, nightlife, multi-stop',
		'custom-transportation-request': 'Wedding, nightlife, multi-stop',
	};

	var state = {
		config: null,
		serviceGroup: '',
		step: 0,
		maxStep: 0,
		serviceId: 0,
		tripType: 'one_way',
		originZoneId: 0,
		destZoneId: 0,
		pickupDate: '',
		pickupTime: '',
		passengers: 1,
		checkedBags: 0,
		carryOnBags: 0,
		vehicleId: 0,
		addons: {},
		customerName: '',
		customerEmail: '',
		customerPhone: '',
		customerWhatsapp: '',
		termsAccepted: false,
		couponCode: '',
		coupon: null,
		couponError: '',
		couponBusy: false,
		quote: null,
		quoteTimer: null,
		availTimer: null,
		_quoteSeq: 0,
		_lastFareKey: '',
		bookingToken: '',
		idempotencyKey: '',
		busy: false,
		submitting: false,
		result: null,
		error: '',
		stepAttempted: {},
	};

	var i18n = cfg.i18n || {};

	function money(amount, currency) {
		if (amount === null || typeof amount === 'undefined') {
			return '—';
		}
		try {
			return new Intl.NumberFormat(undefined, {
				style: 'currency',
				currency: currency || 'USD',
			}).format(Number(amount));
		} catch (e) {
			return (currency || 'USD') + ' ' + Number(amount).toFixed(2);
		}
	}

	function escapeHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
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
		var url = String(cfg.restUrl).replace(/\/$/, '') + path;
		return fetch(url, Object.assign({}, opts, { headers: headers })).then(function (res) {
			return res.json().then(function (data) {
				if (!res.ok) {
					var err = new Error((data && data.message) || i18n.error || 'Request failed');
					err.status = res.status;
					err.data = data;
					throw err;
				}
				return data;
			});
		});
	}

	function selectedService() {
		if (!state.config || !state.serviceId) {
			return null;
		}
		var list = state.config.services || [];
		for (var i = 0; i < list.length; i++) {
			if (Number(list[i].id) === Number(state.serviceId)) {
				return list[i];
			}
		}
		return null;
	}

	function serviceBlurb(s) {
		var desc = (s.description || '').trim();
		if (desc && desc.toLowerCase() !== String(s.name || '').toLowerCase()) {
			return desc;
		}
		var slug = String(s.slug || '').toLowerCase();
		if (BLURBS[slug]) {
			return BLURBS[slug];
		}
		var type = String(s.booking_type || '');
		if (type === 'hourly') {
			return 'Hourly chauffeur service';
		}
		if (type === 'package') {
			return 'Fixed package experience';
		}
		if (type === 'quote') {
			return 'Custom request · quoted after review';
		}
		return 'Private VIP transfer';
	}

	function applicableAddons() {
		var all = (state.config && state.config.addons) || [];
		return all.filter(function (a) {
			if (Number(a.included) === 1) {
				return false;
			}
			var ids = a.service_ids || [];
			if (!ids.length) {
				return true;
			}
			return ids.indexOf(Number(state.serviceId)) !== -1;
		});
	}

	function quotePayload() {
		var addons = [];
		Object.keys(state.addons).forEach(function (id) {
			var qty = Number(state.addons[id]) || 0;
			if (qty > 0) {
				addons.push({ id: Number(id), qty: qty });
			}
		});
		return {
			service_id: state.serviceId,
			trip_type: state.tripType,
			origin_zone_id: state.originZoneId,
			dest_zone_id: state.destZoneId,
			pickup_date: state.pickupDate,
			pickup_time: state.pickupTime,
			passengers: state.passengers,
			checked_bags: state.checkedBags,
			carry_on_bags: state.carryOnBags,
			vehicle_id: state.vehicleId || 0,
			addons: addons,
		};
	}

	/** Fields that can change the dollar total (not vehicle / not time-only). */
	function fareInputKey() {
		var addons = [];
		Object.keys(state.addons)
			.sort()
			.forEach(function (id) {
				var qty = Number(state.addons[id]) || 0;
				if (qty > 0) {
					addons.push(id + ':' + qty);
				}
			});
		return [
			state.serviceId,
			state.tripType,
			state.originZoneId,
			state.destZoneId,
			state.pickupDate || '',
			state.passengers,
			state.checkedBags,
			state.carryOnBags,
			addons.join(','),
		].join('|');
	}

	/**
	 * @param {object} [opts]
	 * @param {boolean} [opts.quiet] Skip rail "Calculating…" flash.
	 * @param {boolean} [opts.force] Always hit /quote.
	 */
	function scheduleQuote(opts) {
		opts = opts || {};
		if (state.quoteTimer) {
			clearTimeout(state.quoteTimer);
		}
		state.quoteTimer = setTimeout(function () {
			fetchQuote(opts);
		}, opts.quiet ? 180 : 250);
	}

	/**
	 * Schedule time-only: refresh occupancy without re-pricing (vehicle pick never quotes).
	 */
	function refreshAvailabilityOnly() {
		if (!state.pickupDate || !state.pickupTime) {
			return;
		}
		if (!state.quote || state.quote.manual_quote) {
			scheduleQuote({ quiet: true });
			return;
		}
		if (state.availTimer) {
			clearTimeout(state.availTimer);
		}
		state.availTimer = setTimeout(function () {
			var join = String(cfg.restUrl || '').indexOf('?') >= 0 ? '&' : '?';
			var q =
				'/availability' +
				join +
				'pickup_date=' +
				encodeURIComponent(state.pickupDate) +
				'&pickup_time=' +
				encodeURIComponent(state.pickupTime) +
				(state.vehicleId ? '&vehicle_id=' + encodeURIComponent(String(state.vehicleId)) : '');
			api(q)
				.then(function (avail) {
					if (!state.quote) {
						return;
					}
					state.quote.availability = avail;
					paint({ main: true, rail: true });
				})
				.catch(function () {
					/* soft-fail: keep prior rail */
				});
		}, 160);
	}

	function hasPricedQuote() {
		return !!(
			state.quote &&
			!state.quote.manual_quote &&
			!state.quote.quote_error &&
			state.quote.total !== null &&
			typeof state.quote.total !== 'undefined'
		);
	}

	function clearQuoteTimers() {
		if (state.quoteTimer) {
			clearTimeout(state.quoteTimer);
			state.quoteTimer = null;
		}
		if (state.availTimer) {
			clearTimeout(state.availTimer);
			state.availTimer = null;
		}
	}

	/** Drop quote + cancel in-flight /quote so restart / service swaps can’t resurrect a stale fare. */
	function invalidateQuotes() {
		clearQuoteTimers();
		state._quoteSeq += 1;
		state.quote = null;
		state._lastFareKey = '';
		state.busy = false;
	}

	/** True when we have enough inputs to call /quote for the active service. */
	function quoteInputsReady() {
		var svc = selectedService();
		if (!svc) {
			return false;
		}
		if (svc.booking_type === 'route') {
			return !!(
				state.originZoneId &&
				state.destZoneId &&
				Number(state.originZoneId) !== Number(state.destZoneId)
			);
		}
		return true;
	}

	function fetchQuote(opts) {
		opts = opts || {};
		var quiet = !!opts.quiet;
		var svc = selectedService();
		if (!svc) {
			invalidateQuotes();
			paint({ rail: true });
			return;
		}
		if (!quoteInputsReady()) {
			/* Bump seq so a prior in-flight package/route response can’t repaint the rail. */
			clearQuoteTimers();
			state._quoteSeq += 1;
			state.quote = null;
			state._lastFareKey = '';
			state.busy = false;
			paint({ rail: true });
			return;
		}
		var key = fareInputKey();
		/*
		 * Skip only a confirmed priced fare for this key. Always re-POST when
		 * the rail shows manual/custom/error so a raced soft failure can recover.
		 */
		if (!opts.force && hasPricedQuote() && state._lastFareKey === key) {
			if (state.pickupDate && state.pickupTime) {
				refreshAvailabilityOnly();
			}
			if (state.busy) {
				state.busy = false;
				paint({ rail: true });
			}
			return;
		}
		if (!quiet) {
			state.busy = true;
			paint({ rail: true });
		}
		var seq = ++state._quoteSeq;
		var payload = quotePayload();
		api('/quote', { method: 'POST', body: payload })
			.then(function (quote) {
				if (seq !== state._quoteSeq || fareInputKey() !== key) {
					return;
				}
				state.quote = quote;
				state._lastFareKey = key;
				if (state.vehicleId && quote.vehicles) {
					var ok = quote.vehicles.some(function (v) {
						return Number(v.id) === Number(state.vehicleId) && v.status !== 'not_selectable';
					});
					if (!ok) {
						state.vehicleId = 0;
						paint({ main: true, rail: true });
						return;
					}
				}
				paint({ rail: true });
			})
			.catch(function (err) {
				if (seq !== state._quoteSeq || fareInputKey() !== key) {
					return;
				}
				/* Keep a good fare if a refresh glitched — don’t flash Custom quote. */
				if (hasPricedQuote() && state._lastFareKey === key) {
					return;
				}
				var msg = (err && err.message) || i18n.quoteFail || 'Could not calculate fare.';
				var code = (err && err.data && err.data.code) || '';
				/* Only true pricing gaps → custom quote. Never treat REST 404/"not found" as one. */
				var soft =
					/prime_fleet_no_hourly|prime_fleet_no_package|prime_fleet_no_rate/i.test(String(code)) ||
					/no published rate|no hourly rate configured|no package/i.test(String(msg));
				state.quote = {
					manual_quote: soft,
					total: null,
					message: soft
						? (state.config && state.config.copy && state.config.copy.custom_quote_message) ||
						  'This request needs a custom quotation. You can continue — Prime Fleet will confirm pricing.'
						: msg,
					currency: (state.config && state.config.currency) || 'USD',
					vehicles: [],
					explanation: soft ? [msg] : [],
					quote_error: !soft,
				};
				/* Don’t lock fare key on soft/network misses — allow retry to recover a real rate. */
				state._lastFareKey = '';
				paint({ rail: true });
			})
			.finally(function () {
				if (seq !== state._quoteSeq) {
					return;
				}
				state.busy = false;
				paint({ rail: true });
				saveDraft();
			});
	}

	function saveDraft() {
		try {
			localStorage.setItem(
				DRAFT_KEY,
				JSON.stringify({
					step: state.step,
					maxStep: state.maxStep,
					serviceId: state.serviceId,
					tripType: state.tripType,
					originZoneId: state.originZoneId,
					destZoneId: state.destZoneId,
					pickupDate: state.pickupDate,
					pickupTime: state.pickupTime,
					passengers: state.passengers,
					checkedBags: state.checkedBags,
					carryOnBags: state.carryOnBags,
					vehicleId: state.vehicleId,
					addons: state.addons,
					customerName: state.customerName,
					customerEmail: state.customerEmail,
					customerPhone: state.customerPhone,
					customerWhatsapp: state.customerWhatsapp,
				})
			);
		} catch (e) {}
	}

	function loadDraft() {
		try {
			var raw = localStorage.getItem(DRAFT_KEY);
			if (!raw) {
				return;
			}
			var d = JSON.parse(raw);
			Object.keys(d).forEach(function (k) {
				if (typeof state[k] !== 'undefined') {
					state[k] = d[k];
				}
			});
		} catch (e) {}
	}

	function clearDraft() {
		try {
			localStorage.removeItem(DRAFT_KEY);
		} catch (e) {}
	}

	function serviceGroupId(s) {
		for (var i = 0; i < SERVICE_GROUPS.length; i++) {
			if (SERVICE_GROUPS[i].match(s)) {
				return SERVICE_GROUPS[i].id;
			}
		}
		return 'transfer';
	}

	function activeGroups() {
		var services = (state.config && state.config.services) || [];
		var used = {};
		services.forEach(function (s) {
			used[serviceGroupId(s)] = true;
		});
		return SERVICE_GROUPS.filter(function (g) {
			return used[g.id];
		});
	}

	function ensureServiceGroup() {
		var groups = activeGroups();
		if (!groups.length) {
			state.serviceGroup = '';
			return;
		}
		if (!state.serviceGroup || !groups.some(function (g) { return g.id === state.serviceGroup; })) {
			if (state.serviceId) {
				var svc = selectedService();
				state.serviceGroup = svc ? serviceGroupId(svc) : groups[0].id;
			} else {
				state.serviceGroup = groups[0].id;
			}
		}
	}

	function zonesForService(role) {
		var all = (state.config && state.config.zones) || [];
		var svc = selectedService();
		if (!svc || !svc.route_zones) {
			return all;
		}
		var ids = role === 'dropoff' ? svc.route_zones.dropoff : svc.route_zones.pickup;
		if (!ids || !ids.length) {
			return all;
		}
		var set = {};
		ids.forEach(function (id) {
			set[Number(id)] = true;
		});
		return all.filter(function (z) {
			return set[Number(z.id)];
		});
	}

	function sanitizeZonesForService() {
		var pickup = zonesForService('pickup');
		var drop = zonesForService('dropoff');
		var okPick = pickup.some(function (z) {
			return Number(z.id) === Number(state.originZoneId);
		});
		var okDrop = drop.some(function (z) {
			return Number(z.id) === Number(state.destZoneId);
		});
		if (state.originZoneId && !okPick) {
			state.originZoneId = 0;
		}
		if (state.destZoneId && !okDrop) {
			state.destZoneId = 0;
		}
		if (
			state.originZoneId &&
			state.destZoneId &&
			Number(state.originZoneId) === Number(state.destZoneId)
		) {
			state.destZoneId = 0;
		}
	}

	function zoneOptionList(selected, role, excludeId) {
		var zones = zonesForService(role || 'pickup');
		var exclude = Number(excludeId) || 0;
		var opts = [{ value: '', label: i18n.selectPlaceholder || 'Select…' }];
		for (var i = 0; i < zones.length; i++) {
			var z = zones[i];
			if (exclude && Number(z.id) === exclude) {
				continue;
			}
			opts.push({ value: String(z.id), label: z.name });
		}
		return opts;
	}

	/** Custom branded select — keeps a hidden native <select> for change events. */
	function renderFancySelect(cfg) {
		var id = cfg.id || '';
		var name = cfg.name || '';
		var selected = cfg.selected == null ? '' : String(cfg.selected);
		var opts = cfg.options || [];
		var placeholder = cfg.placeholder || i18n.selectPlaceholder || 'Select…';
		var searchable = cfg.searchable != null ? !!cfg.searchable : opts.length > 6;
		var selectedLabel = placeholder;
		var native = '';
		var items = '';
		var hasValue = false;
		opts.forEach(function (o) {
			var val = String(o.value);
			var lab = String(o.label);
			var isSel = selected !== '' && selected === val;
			if (isSel) {
				selectedLabel = lab;
				hasValue = true;
			}
			native +=
				'<option value="' +
				escapeHtml(val) +
				'"' +
				(isSel ? ' selected' : '') +
				'>' +
				escapeHtml(lab) +
				'</option>';
			items +=
				'<li class="prime-fleet__select-option' +
				(isSel ? ' is-selected' : '') +
				(val === '' ? ' is-placeholder' : '') +
				'" role="option" data-pf-select-option data-value="' +
				escapeHtml(val) +
				'" data-label="' +
				escapeHtml(lab.toLowerCase()) +
				'" aria-selected="' +
				(isSel ? 'true' : 'false') +
				'">' +
				escapeHtml(lab) +
				'</li>';
		});
		return (
			'<div class="prime-fleet__select' +
			(searchable ? ' has-search' : '') +
			'" data-pf-select>' +
			'<select class="prime-fleet__select-native" id="' +
			escapeHtml(id) +
			'" name="' +
			escapeHtml(name) +
			'" tabindex="-1" aria-hidden="true">' +
			native +
			'</select>' +
			'<button type="button" class="prime-fleet__select-trigger" aria-haspopup="listbox" aria-expanded="false"' +
			(id ? ' aria-controls="' + escapeHtml(id) + '-panel"' : '') +
			'>' +
			'<span class="prime-fleet__select-value' +
			(hasValue ? '' : ' is-placeholder') +
			'">' +
			escapeHtml(selectedLabel) +
			'</span>' +
			'<span class="prime-fleet__select-chevron" aria-hidden="true"></span>' +
			'</button>' +
			'<div class="prime-fleet__select-panel" hidden' +
			(id ? ' id="' + escapeHtml(id) + '-panel"' : '') +
			'>' +
			(searchable
				? '<div class="prime-fleet__select-search-wrap">' +
				  '<input type="search" class="prime-fleet__select-search" placeholder="' +
				  escapeHtml(i18n.searchZones || 'Search…') +
				  '" autocomplete="off" aria-label="' +
				  escapeHtml(i18n.searchZones || 'Search options') +
				  '" />' +
				  '</div>'
				: '') +
			'<ul class="prime-fleet__select-menu" role="listbox">' +
			items +
			'</ul>' +
			'<p class="prime-fleet__select-empty" hidden>' +
			escapeHtml(i18n.noMatches || 'No matches') +
			'</p></div></div>'
		);
	}

	function closeAllFancySelects(except) {
		root.querySelectorAll('[data-pf-select].is-open').forEach(function (el) {
			if (except && el === except) {
				return;
			}
			el.classList.remove('is-open');
			var btn = el.querySelector('.prime-fleet__select-trigger');
			var panel = el.querySelector('.prime-fleet__select-panel');
			var search = el.querySelector('.prime-fleet__select-search');
			if (btn) {
				btn.setAttribute('aria-expanded', 'false');
			}
			if (panel) {
				panel.hidden = true;
			}
			if (search) {
				search.value = '';
				filterFancySelect(el, '');
			}
		});
		closeAllDatePickers();
	}

	function filterFancySelect(wrap, query) {
		if (!wrap) {
			return;
		}
		var q = String(query || '')
			.trim()
			.toLowerCase();
		var visible = 0;
		wrap.querySelectorAll('[data-pf-select-option]').forEach(function (opt) {
			var label = opt.getAttribute('data-label') || '';
			var show = !q || label.indexOf(q) !== -1;
			opt.hidden = !show;
			if (show) {
				visible += 1;
			}
		});
		var empty = wrap.querySelector('.prime-fleet__select-empty');
		var menu = wrap.querySelector('.prime-fleet__select-menu');
		if (empty) {
			empty.hidden = visible > 0;
		}
		if (menu) {
			menu.hidden = visible === 0;
		}
	}

	function toggleFancySelect(wrap) {
		if (!wrap) {
			return;
		}
		var open = wrap.classList.contains('is-open');
		closeAllFancySelects();
		if (open) {
			return;
		}
		wrap.classList.add('is-open');
		var btn = wrap.querySelector('.prime-fleet__select-trigger');
		var panel = wrap.querySelector('.prime-fleet__select-panel');
		var search = wrap.querySelector('.prime-fleet__select-search');
		if (btn) {
			btn.setAttribute('aria-expanded', 'true');
		}
		if (panel) {
			panel.hidden = false;
		}
		var sel = wrap.querySelector('.prime-fleet__select-option.is-selected');
		if (sel && !sel.hidden && sel.scrollIntoView) {
			sel.scrollIntoView({ block: 'nearest' });
		}
		if (search) {
			window.setTimeout(function () {
				search.focus();
			}, 20);
		}
	}

	function commitFancySelect(wrap, value) {
		var native = wrap.querySelector('.prime-fleet__select-native');
		if (!native) {
			return;
		}
		native.value = value;
		closeAllFancySelects();
		native.dispatchEvent(new Event('change', { bubbles: true }));
	}

	var MONTH_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
	var DOW_SHORT = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

	function parseYmd(s) {
		var p = String(s || '').split('-');
		if (p.length !== 3) {
			return null;
		}
		var y = Number(p[0]);
		var m = Number(p[1]);
		var d = Number(p[2]);
		if (!y || !m || !d) {
			return null;
		}
		return { y: y, m: m, d: d };
	}

	function formatYmd(y, m, d) {
		return y + '-' + pad2(m) + '-' + pad2(d);
	}

	function formatDateLabel(ymd) {
		var p = parseYmd(ymd);
		if (!p) {
			return i18n.selectDate || 'Select date';
		}
		return MONTH_SHORT[p.m - 1] + ' ' + p.d + ', ' + p.y;
	}

	function daysInMonth(y, m) {
		return new Date(y, m, 0).getDate();
	}

	function renderCalendarDays(viewY, viewM, selected, min) {
		var firstDow = new Date(viewY, viewM - 1, 1).getDay();
		var total = daysInMonth(viewY, viewM);
		var todayYmd = '';
		try {
			todayYmd = new Date().toISOString().slice(0, 10);
		} catch (err) {
			todayYmd = '';
		}
		var html = '';
		var i;
		for (i = 0; i < firstDow; i++) {
			html += '<span class="prime-fleet__cal-day is-empty" aria-hidden="true"></span>';
		}
		for (i = 1; i <= total; i++) {
			var ymd = formatYmd(viewY, viewM, i);
			var disabled = !!(min && ymd < min);
			var isSel = selected && selected === ymd;
			var isToday = !!(todayYmd && ymd === todayYmd);
			html +=
				'<button type="button" class="prime-fleet__cal-day' +
				(isSel ? ' is-selected' : '') +
				(disabled ? ' is-disabled' : '') +
				(isToday ? ' is-today' : '') +
				'" data-pf-cal-day="' +
				escapeHtml(ymd) +
				'"' +
				(disabled ? ' disabled aria-disabled="true"' : '') +
				(isSel ? ' aria-current="date"' : '') +
				(isToday ? ' aria-label="' + i + ', today"' : '') +
				'>' +
				i +
				'</button>';
		}
		return html;
	}

	function renderFancyDate(cfg) {
		var id = cfg.id || 'pf-date';
		var name = cfg.name || 'pickup_date';
		var value = cfg.value || '';
		var min = cfg.min || '';
		var seed = parseYmd(value) || parseYmd(min) || parseYmd(new Date().toISOString().slice(0, 10));
		var viewY = seed ? seed.y : new Date().getFullYear();
		var viewM = seed ? seed.m : new Date().getMonth() + 1;
		var hasValue = !!value;
		return (
			'<div class="prime-fleet__date" data-pf-date data-min="' +
			escapeHtml(min) +
			'" data-view-y="' +
			viewY +
			'" data-view-m="' +
			viewM +
			'">' +
			'<input type="hidden" class="prime-fleet__date-native" id="' +
			escapeHtml(id) +
			'" name="' +
			escapeHtml(name) +
			'" value="' +
			escapeHtml(value) +
			'" />' +
			'<button type="button" class="prime-fleet__date-trigger" aria-haspopup="dialog" aria-expanded="false">' +
			'<span class="prime-fleet__date-value' +
			(hasValue ? '' : ' is-placeholder') +
			'">' +
			escapeHtml(hasValue ? formatDateLabel(value) : i18n.selectDate || 'Select date') +
			'</span>' +
			'<span class="prime-fleet__date-icon" aria-hidden="true"></span>' +
			'</button>' +
			'<div class="prime-fleet__date-panel" hidden role="dialog" aria-label="' +
			escapeHtml(i18n.pickupDate || 'Pickup date') +
			'">' +
			'<div class="prime-fleet__cal-head">' +
			'<button type="button" class="prime-fleet__cal-nav" data-pf-cal-nav="prev" aria-label="Previous month">‹</button>' +
			'<p class="prime-fleet__cal-title" data-pf-cal-title>' +
			escapeHtml(MONTH_SHORT[viewM - 1] + ' ' + viewY) +
			'</p>' +
			'<button type="button" class="prime-fleet__cal-nav" data-pf-cal-nav="next" aria-label="Next month">›</button>' +
			'</div>' +
			'<div class="prime-fleet__cal-dow" aria-hidden="true">' +
			DOW_SHORT.map(function (d) {
				return '<span>' + d + '</span>';
			}).join('') +
			'</div>' +
			'<div class="prime-fleet__cal-grid" data-pf-cal-grid>' +
			renderCalendarDays(viewY, viewM, value, min) +
			'</div></div></div>'
		);
	}

	function closeAllDatePickers(except) {
		root.querySelectorAll('[data-pf-date].is-open').forEach(function (el) {
			if (except && el === except) {
				return;
			}
			el.classList.remove('is-open');
			el.classList.remove('is-up');
			var btn = el.querySelector('.prime-fleet__date-trigger');
			var panel = el.querySelector('.prime-fleet__date-panel');
			if (btn) {
				btn.setAttribute('aria-expanded', 'false');
			}
			if (panel) {
				panel.hidden = true;
				panel.style.visibility = '';
			}
		});
	}

	function paintDateCalendar(wrap) {
		if (!wrap) {
			return;
		}
		var y = Number(wrap.getAttribute('data-view-y'));
		var m = Number(wrap.getAttribute('data-view-m'));
		var min = wrap.getAttribute('data-min') || '';
		var native = wrap.querySelector('.prime-fleet__date-native');
		var selected = native ? native.value : '';
		var title = wrap.querySelector('[data-pf-cal-title]');
		var grid = wrap.querySelector('[data-pf-cal-grid]');
		if (title) {
			title.textContent = MONTH_SHORT[m - 1] + ' ' + y;
		}
		if (grid) {
			grid.innerHTML = renderCalendarDays(y, m, selected, min);
		}
	}

	function positionFancyDate(wrap) {
		if (!wrap) {
			return;
		}
		var panel = wrap.querySelector('.prime-fleet__date-panel');
		var btn = wrap.querySelector('.prime-fleet__date-trigger');
		if (!panel || !btn) {
			return;
		}
		wrap.classList.remove('is-up');
		panel.style.visibility = 'hidden';
		panel.hidden = false;
		var btnRect = btn.getBoundingClientRect();
		var panelH = panel.offsetHeight || 300;
		var spaceBelow = window.innerHeight - btnRect.bottom;
		var spaceAbove = btnRect.top;
		if (spaceBelow < panelH + 12 && spaceAbove > spaceBelow) {
			wrap.classList.add('is-up');
		}
		panel.style.visibility = '';
		try {
			btn.scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'smooth' });
		} catch (err) {
			/* ignore */
		}
	}

	function toggleFancyDate(wrap) {
		if (!wrap) {
			return;
		}
		var open = wrap.classList.contains('is-open');
		closeAllFancySelects();
		closeAllDatePickers();
		if (open) {
			return;
		}
		wrap.classList.add('is-open');
		var btn = wrap.querySelector('.prime-fleet__date-trigger');
		var panel = wrap.querySelector('.prime-fleet__date-panel');
		if (btn) {
			btn.setAttribute('aria-expanded', 'true');
		}
		paintDateCalendar(wrap);
		if (panel) {
			panel.hidden = false;
			positionFancyDate(wrap);
		}
	}

	function commitFancyDate(wrap, value) {
		var native = wrap.querySelector('.prime-fleet__date-native');
		if (!native) {
			return;
		}
		native.value = value || '';
		closeAllDatePickers();
		native.dispatchEvent(new Event('change', { bubbles: true }));
	}

	function shiftCalMonth(wrap, delta) {
		var y = Number(wrap.getAttribute('data-view-y'));
		var m = Number(wrap.getAttribute('data-view-m')) + delta;
		while (m < 1) {
			m += 12;
			y -= 1;
		}
		while (m > 12) {
			m -= 12;
			y += 1;
		}
		wrap.setAttribute('data-view-y', String(y));
		wrap.setAttribute('data-view-m', String(m));
		paintDateCalendar(wrap);
	}

	function zonesConflict() {
		return !!(
			state.originZoneId &&
			state.destZoneId &&
			Number(state.originZoneId) === Number(state.destZoneId)
		);
	}

	function stepKeyAt(idx) {
		return STEPS[idx] ? STEPS[idx].key : 'service';
	}

	function stepIndexFromKey(key) {
		var k = String(key || '');
		for (var i = 0; i < STEPS.length; i++) {
			if (STEPS[i].key === k) {
				return i;
			}
		}
		return -1;
	}

	function readUrlState() {
		try {
			var u = new URL(window.location.href);
			return {
				stepKey: u.searchParams.get(URL_STEP) || '',
				ref: u.searchParams.get(URL_REF) || '',
			};
		} catch (e) {
			return { stepKey: '', ref: '' };
		}
	}

	function syncUrl(opts) {
		opts = opts || {};
		try {
			var u = new URL(window.location.href);
			if (state.result && state.result.reference) {
				u.searchParams.delete(URL_STEP);
				u.searchParams.set(URL_REF, String(state.result.reference));
			} else {
				u.searchParams.delete(URL_REF);
				u.searchParams.set(URL_STEP, stepKeyAt(state.step));
			}
			var next = u.pathname + u.search + (u.hash || '');
			var cur = window.location.pathname + window.location.search + window.location.hash;
			if (next === cur) {
				return;
			}
			var payload = { primeFleet: 1, step: state.step, ref: state.result ? state.result.reference : '' };
			if (opts.push) {
				history.pushState(payload, '', next);
			} else {
				history.replaceState(payload, '', next);
			}
		} catch (e) {}
	}

	function applyUrlState() {
		var url = readUrlState();
		if (url.ref && !state.result) {
			state.result = {
				reference: url.ref,
				message:
					i18n.pending ||
					'Your transfer request is Pending Confirmation. Prime Fleet will verify vehicle availability and contact you shortly.',
			};
			return;
		}
		var fromUrl = stepIndexFromKey(url.stepKey);
		if (fromUrl < 0) {
			return;
		}
		// Resume only within steps already reached (draft maxStep).
		if (fromUrl <= state.maxStep) {
			state.step = fromUrl;
		}
	}

	function goToStep(idx, opts) {
		opts = opts || {};
		idx = Math.max(0, Math.min(STEPS.length - 1, Number(idx) || 0));
		var changed = idx !== state.step;
		state.step = idx;
		if (idx > state.maxStep) {
			state.maxStep = idx;
		}
		state.error = '';
		if (opts.skipUrl) {
			return;
		}
		syncUrl({ push: changed && opts.push !== false });
	}

	function railEmpty(status, detail) {
		return (
			'<div class="prime-fleet__rail-empty">' +
			'<p class="prime-fleet__rail-amount is-empty" aria-hidden="true">—</p>' +
			'<p class="prime-fleet__rail-status">' +
			escapeHtml(status) +
			'</p>' +
			(detail
				? '<p class="prime-fleet__rail-detail">' + escapeHtml(detail) + '</p>'
				: '') +
			'</div>'
		);
	}

	function renderRailBody() {
		var body = '';
		var lines = '';
		var q = state.quote;
		var svc = selectedService();
		var needsZones = svc && svc.booking_type === 'route';
		var zonesReady = !needsZones || (state.originZoneId && state.destZoneId);
		var mode = 'empty';

		if (!state.serviceId) {
			mode = 'empty';
			body = railEmpty(
				i18n.fareEmpty || 'No service selected',
				i18n.fareEmptyDetail || 'Choose a service to begin your quote.'
			);
		} else if (!zonesReady) {
			mode = 'pending';
			body = railEmpty(
				i18n.fareBuild || 'Trip details needed',
				i18n.fareBuildDetail || 'Add pickup and drop-off to unlock an estimate.'
			);
		} else if (!q && state.busy) {
			mode = 'loading';
			body =
				'<div class="prime-fleet__rail-estimating" role="status">' +
				'<div class="prime-fleet__rail-meter" aria-hidden="true"><span></span></div>' +
				'<p class="prime-fleet__rail-status">' +
				escapeHtml(i18n.fareCalc || 'Calculating estimate…') +
				'</p>' +
				'<p class="prime-fleet__rail-detail">' +
				escapeHtml(i18n.fareCalcDetail || 'Checking rates for this route.') +
				'</p></div>';
		} else if (!q) {
			mode = 'pending';
			body = railEmpty(
				i18n.farePending || 'Estimate pending',
				i18n.farePendingDetail || 'Finish schedule details and your estimate will appear here.'
			);
		} else if (q.quote_error) {
			mode = 'error';
			body = railEmpty(
				i18n.quoteFail || 'Estimate unavailable',
				q.message || i18n.quoteFailDetail || 'Try adjusting trip details, or continue for a custom quote.'
			);
		} else if (q.manual_quote) {
			mode = 'custom';
			var rateNote =
				q.explanation && q.explanation.length ? String(q.explanation[0]) : '';
			body = railEmpty(
				i18n.customQuote || 'Custom quote',
				/no published rate/i.test(rateNote)
					? rateNote
					: q.message ||
							(state.config && state.config.copy && state.config.copy.custom_quote_message) ||
							(i18n.customQuoteDetail || 'We’ll review this trip and send a written quotation.')
			);
		} else if (q.total === null || typeof q.total === 'undefined') {
			mode = 'pending';
			body = railEmpty(
				i18n.farePending || 'Estimate pending',
				i18n.farePendingDetail || 'Finish schedule details and your estimate will appear here.'
			);
		} else {
			mode = 'priced';
			var displayTotal = q.total;
			if (state.coupon && typeof state.coupon.fare_after !== 'undefined') {
				displayTotal = state.coupon.fare_after;
			}
			body =
				'<p class="prime-fleet__rail-total">' +
				escapeHtml(money(displayTotal, q.currency || (state.config && state.config.currency))) +
				'</p>';
			if (state.busy) {
				body =
					'<div class="prime-fleet__rail-estimating is-refresh" role="status">' +
					'<div class="prime-fleet__rail-meter" aria-hidden="true"><span></span></div>' +
					'<p class="prime-fleet__rail-status">' +
					escapeHtml(money(displayTotal, q.currency || (state.config && state.config.currency))) +
					'</p>' +
					'<p class="prime-fleet__rail-detail">' +
					escapeHtml(i18n.fareUpdating || 'Updating estimate…') +
					'</p></div>';
			} else if (state.couponBusy) {
				body +=
					'<p class="prime-fleet__rail-msg">' +
					escapeHtml(i18n.couponApplying || 'Applying coupon…') +
					'</p>';
			} else if (state.coupon && (state.coupon.code || state.coupon.message)) {
				var couponLabel =
					state.coupon.message ||
					(i18n.couponApplied || 'Coupon applied.') +
						(typeof state.coupon.discount !== 'undefined'
							? ' −' + Number(state.coupon.discount).toFixed(2)
							: '');
				body +=
					'<div class="prime-fleet__rail-coupon">' +
					'<p class="prime-fleet__rail-msg">' +
					escapeHtml(couponLabel) +
					(state.coupon.code ? ' (' + escapeHtml(state.coupon.code) + ')' : '') +
					'</p>' +
					'<button type="button" class="prime-fleet__rail-coupon-remove" data-action="remove-coupon" aria-label="' +
					escapeHtml(i18n.removeCoupon || 'Remove coupon') +
					'">×</button></div>';
			}
		}

		if (q && !state.busy && !state.couponBusy && !q.quote_error) {
			var vehicleNote = vehicleMatchNote(q);
			if (vehicleNote) {
				body += '<p class="prime-fleet__rail-msg">' + escapeHtml(vehicleNote) + '</p>';
			}
			if (q.availability && q.availability.available === false && !q.availability.manual) {
				body +=
					'<p class="prime-fleet__rail-msg">' +
					escapeHtml(q.availability.message || i18n.availFull || 'This time looks full.') +
					'</p>';
			} else if (q.availability && q.availability.available && !q.manual_quote) {
				body +=
					'<p class="prime-fleet__rail-msg">' +
					escapeHtml(i18n.availOk || 'Vehicle window looks open.') +
					'</p>';
			}
			if (q.explanation && q.explanation.length && !q.manual_quote) {
				lines = '<ul class="prime-fleet__rail-lines">';
				q.explanation.slice(0, 4).forEach(function (line) {
					lines += '<li>' + escapeHtml(String(line)) + '</li>';
				});
				lines += '</ul>';
			}
		}

		return { body: body, lines: lines, mode: mode };
	}

	function vehicleMatchNote(q) {
		var vehicles = (q && q.vehicles) || [];
		if (!vehicles.length || state.passengers < 2) {
			return '';
		}
		var suitable = vehicles.filter(function (v) {
			return v.status === 'suitable' || v.status === 'review';
		});
		var blocked = vehicles.filter(function (v) {
			return v.status === 'not_selectable';
		});
		if (blocked.length && suitable.length === 1) {
			return (
				(i18n.needsLarger || 'Party size needs') +
				' ' +
				(suitable[0].name || 'a larger vehicle') +
				'.'
			);
		}
		if (blocked.length && !suitable.length) {
			return (
				i18n.partyReview ||
				'Party size exceeds listed vehicles — staff will confirm the right fleet.'
			);
		}
		if (blocked.length) {
			return (
				i18n.partyFilter ||
				'Some vehicles are too small for this party — options update on the Vehicle step.'
			);
		}
		return '';
	}

	function renderRail() {
		var parts = renderRailBody();
		var busyClass = state.busy || state.couponBusy ? ' is-busy' : '';
		var modeClass = parts.mode ? ' is-' + parts.mode : '';
		var label =
			parts.mode === 'priced'
				? i18n.fare || 'Estimated fare'
				: parts.mode === 'custom'
					? i18n.customQuote || 'Custom quote'
					: parts.mode === 'loading'
						? i18n.fareCalcShort || 'Estimating'
						: i18n.quoteLabel || 'Your quote';
		var note =
			parts.mode === 'priced'
				? i18n.fareHint || 'Estimate only — final amount confirmed after review.'
				: parts.mode === 'custom'
					? i18n.customQuoteNote || 'No published rate for this route yet.'
					: i18n.quoteHint || 'Your estimate updates as you build the trip.';
		return (
			'<aside class="prime-fleet__rail' +
			busyClass +
			modeClass +
			'" aria-live="polite">' +
			'<div class="prime-fleet__rail-head">' +
			'<p class="prime-fleet__rail-label">' +
			escapeHtml(label) +
			'</p></div>' +
			'<div class="prime-fleet__rail-body">' +
			parts.body +
			parts.lines +
			'</div>' +
			'<p class="prime-fleet__rail-note">' +
			escapeHtml(note) +
			'</p></aside>'
		);
	}

	function actions(canNext, nextLabel) {
		var back =
			state.step > 0
				? '<button type="button" class="prime-fleet__btn prime-fleet__btn--ghost" data-action="back"' +
				  (state.submitting ? ' disabled' : '') +
				  '>' +
				  escapeHtml(i18n.back || 'Back') +
				  '</button>'
				: '';
		var onReview = STEPS[state.step] && STEPS[state.step].key === 'review';
		var submitting = state.submitting && onReview;
		var label = submitting
			? i18n.submitting || 'Submitting…'
			: nextLabel || i18n.continue || 'Continue';
		var spin = submitting
			? '<span class="prime-fleet__spinner" aria-hidden="true"></span>'
			: '';
		return (
			'<div class="prime-fleet__actions">' +
			back +
			'<button type="button" class="prime-fleet__btn prime-fleet__btn--primary' +
			(canNext && !state.submitting ? '' : ' is-disabled') +
			(submitting ? ' is-loading' : '') +
			'" data-action="next" aria-disabled="' +
			(canNext && !state.submitting ? 'false' : 'true') +
			'"' +
			(submitting ? ' aria-busy="true"' : '') +
			'>' +
			spin +
			escapeHtml(label) +
			'</button></div>'
		);
	}

	function renderServiceListItems() {
		var services = (state.config && state.config.services) || [];
		return services
			.filter(function (s) {
				return serviceGroupId(s) === state.serviceGroup;
			})
			.map(function (s, idx) {
				var selected = Number(state.serviceId) === Number(s.id);
				var num = idx + 1 < 10 ? '0' + (idx + 1) : String(idx + 1);
				return (
					'<li class="prime-fleet__service-item" style="--pf-i:' +
					idx +
					'"><button type="button" class="prime-fleet__service' +
					(selected ? ' is-selected' : '') +
					'" data-service-id="' +
					escapeHtml(String(s.id)) +
					'" aria-pressed="' +
					(selected ? 'true' : 'false') +
					'"><span class="prime-fleet__service-index" aria-hidden="true">' +
					num +
					'</span><span class="prime-fleet__service-body"><span class="prime-fleet__service-name">' +
					escapeHtml(s.name) +
					'</span><span class="prime-fleet__service-desc">' +
					escapeHtml(serviceBlurb(s)) +
					'</span></span><span class="prime-fleet__service-check" aria-hidden="true"></span></button></li>'
				);
			})
			.join('');
	}

	function renderServiceStep() {
		var services = (state.config && state.config.services) || [];
		ensureServiceGroup();
		var groups = activeGroups();
		var tabs = groups
			.map(function (g) {
				var count = services.filter(function (s) {
					return serviceGroupId(s) === g.id;
				}).length;
				return (
					'<span role="button" tabindex="0" class="prime-fleet__cat' +
					(state.serviceGroup === g.id ? ' is-active' : '') +
					'" data-service-group="' +
					escapeHtml(g.id) +
					'">' +
					escapeHtml(g.label) +
					'<span class="prime-fleet__cat-count">' +
					escapeHtml(String(count)) +
					'</span></span>'
				);
			})
			.join('');
		return (
			'<div class="prime-fleet__panel"><h2>' +
			escapeHtml(i18n.whatNeed || 'Choose a service') +
			'</h2><p class="prime-fleet__hint">' +
			escapeHtml(i18n.selectSvc || 'Filter by type, then pick one option to continue.') +
			'</p>' +
			(state.stepAttempted && state.stepAttempted.service && !state.serviceId
				? '<p class="prime-fleet__field-error" role="alert">' +
				  escapeHtml(i18n.pickService || 'Please choose a service first.') +
				  '</p>'
				: '') +
			'<div class="prime-fleet__cats" role="tablist">' +
			tabs +
			'</div><ul class="prime-fleet__services prime-fleet__services--stack" data-pf-service-list>' +
			renderServiceListItems() +
			'</ul>' +
			actions(!!state.serviceId) +
			'</div>'
		);
	}

	function renderTripStep() {
		var svc = selectedService();
		var needsZones = !svc || svc.booking_type === 'route';
		var allowOw = !svc || !svc.settings || Number(svc.settings.allow_one_way) === 1;
		var allowRt = svc && svc.settings && Number(svc.settings.allow_round_trip) === 1;
		var tripChoices = '';
		if (needsZones && (allowOw || allowRt)) {
			tripChoices = '<div class="prime-fleet__segment" role="tablist" aria-label="Trip type">';
			if (allowOw) {
				tripChoices +=
					'<button type="button" role="tab" class="prime-fleet__segment-btn' +
					(state.tripType === 'one_way' ? ' is-active' : '') +
					'" data-trip-type="one_way" aria-selected="' +
					(state.tripType === 'one_way' ? 'true' : 'false') +
					'">' +
					escapeHtml(i18n.oneWay || 'One way') +
					'</button>';
			}
			if (allowRt) {
				tripChoices +=
					'<button type="button" role="tab" class="prime-fleet__segment-btn' +
					(state.tripType === 'round_trip' ? ' is-active' : '') +
					'" data-trip-type="round_trip" aria-selected="' +
					(state.tripType === 'round_trip' ? 'true' : 'false') +
					'">' +
					escapeHtml(i18n.roundTrip || 'Round trip') +
					'</button>';
			}
			tripChoices += '</div>';
		}
		if (!needsZones) {
			var typeHint =
				svc.booking_type === 'hourly'
					? i18n.tripHourlyHint || 'Hourly chauffeur — zones aren’t required for this estimate.'
					: svc.booking_type === 'package'
						? i18n.tripPackageHint || 'Fixed package — continue to set your schedule.'
						: i18n.tripQuoteHint || 'Custom request — continue to share schedule and contact details.';
			return (
				'<div class="prime-fleet__panel prime-fleet__panel--trip"><h2>Trip details</h2><p class="prime-fleet__hint">' +
				escapeHtml(typeHint) +
				'</p>' +
				actions(true) +
				'</div>'
			);
		}
		var sameZone = zonesConflict();
		var can = !!(state.originZoneId && state.destZoneId && !sameZone);
		var attempted = !!(state.stepAttempted && state.stepAttempted.trip);
		var zoneWarn = '';
		if (sameZone) {
			zoneWarn =
				'<p class="prime-fleet__field-error" role="alert">' +
				escapeHtml(i18n.sameZones || 'Pickup and destination must be different.') +
				'</p>';
		} else if (attempted && !can) {
			zoneWarn =
				'<p class="prime-fleet__field-error" role="alert">' +
				escapeHtml(i18n.pickZones || 'Select pickup and destination zones first.') +
				'</p>';
		}
		return (
			'<div class="prime-fleet__panel prime-fleet__panel--trip"><h2>Trip details</h2><p class="prime-fleet__hint">Pickup and destination zones for this transfer.</p>' +
			(tripChoices ? '<div class="prime-fleet__field prime-fleet__field--trip-type">' + tripChoices + '</div>' : '') +
			'<div class="prime-fleet__grid is-2"><div class="prime-fleet__field"><label for="pf-origin">Pickup zone</label>' +
			renderFancySelect({
				id: 'pf-origin',
				name: 'origin_zone_id',
				selected: state.originZoneId || '',
				options: zoneOptionList(state.originZoneId, 'pickup', state.destZoneId),
				searchable: true,
			}) +
			'</div><div class="prime-fleet__field"><label for="pf-dest">Destination zone</label>' +
			renderFancySelect({
				id: 'pf-dest',
				name: 'dest_zone_id',
				selected: state.destZoneId || '',
				options: zoneOptionList(state.destZoneId, 'dropoff', state.originZoneId),
				searchable: true,
			}) +
			'</div></div>' +
			zoneWarn +
			actions(!!can) +
			'</div>'
		);
	}

	function renderScheduleStep() {
		var alts = '';
		if (
			state.quote &&
			state.quote.availability &&
			state.quote.availability.available === false &&
			state.quote.availability.alternatives &&
			state.quote.availability.alternatives.length
		) {
			alts =
				'<div class="prime-fleet__alts"><p class="prime-fleet__hint">' +
				escapeHtml(i18n.availFull || 'This time looks full. Try an alternative:') +
				'</p><div class="prime-fleet__alt-list">';
			state.quote.availability.alternatives.forEach(function (a) {
				alts +=
					'<button type="button" class="prime-fleet__btn prime-fleet__btn--ghost" data-alt-dt="' +
					escapeHtml(a.datetime) +
					'">' +
					escapeHtml(a.label || a.datetime) +
					'</button>';
			});
			alts += '</div></div>';
		}
		var schedErr = scheduleInlineError();
		var minDate = scheduleMinDate();
		var timeOpts = state.pickupDate ? scheduleTimeOptions(state.pickupDate) : [];
		var minTime = scheduleMinTimeForDate(state.pickupDate);
		var hours = (pickupRules().min_advance_hours != null ? pickupRules().min_advance_hours : 2);
		var earliestHint = '';
		if (state.pickupDate && minTime) {
			earliestHint =
				'<p class="prime-fleet__hint">' +
				escapeHtml(
					(i18n.earliestToday || 'Earliest available today: %s').replace('%s', formatTimeLabel(minTime))
				) +
				'</p>';
		} else if (!state.pickupDate) {
			earliestHint =
				'<p class="prime-fleet__hint">' +
				escapeHtml(
					(i18n.scheduleLead || 'Same-day pickups need at least %s hours notice.').replace('%s', String(hours))
				) +
				'</p>';
		}
		var timeField;
		if (!state.pickupDate) {
			timeField =
				'<input type="time" id="pf-time" name="pickup_time" value="" disabled aria-disabled="true" />';
		} else if (!timeOpts.length) {
			timeField =
				'<p class="prime-fleet__field-error" role="alert">' +
				escapeHtml(i18n.noTimesToday || 'No times left today — please choose another date.') +
				'</p>';
		} else {
			var timeChoices = [{ value: '', label: i18n.pickTime || 'Select time' }];
			timeOpts.forEach(function (t) {
				timeChoices.push({ value: t, label: formatTimeLabel(t) });
			});
			timeField = renderFancySelect({
				id: 'pf-time',
				name: 'pickup_time',
				selected: state.pickupTime || '',
				options: timeChoices,
				placeholder: i18n.pickTime || 'Select time',
			});
		}
		var hint =
			'<p class="prime-fleet__hint">When should we meet you?</p>' +
			earliestHint +
			(schedErr ? '<p class="prime-fleet__field-error" role="alert">' + escapeHtml(schedErr) + '</p>' : '');
		return (
			'<div class="prime-fleet__panel"><h2>Schedule</h2>' +
			hint +
			'<div class="prime-fleet__grid is-2"><div class="prime-fleet__field"><label for="pf-date">Pickup date</label>' +
			renderFancyDate({
				id: 'pf-date',
				name: 'pickup_date',
				value: state.pickupDate || '',
				min: minDate || '',
			}) +
			'</div><div class="prime-fleet__field"><label for="pf-time">Pickup time</label>' +
			timeField +
			'</div></div>' +
			alts +
			actions(scheduleReady()) +
			'</div>'
		);
	}

	function guestFieldMeta(maxPax) {
		return [
			{
				key: 'passengers',
				name: 'Passengers',
				hint: 'Guests in the vehicle',
				min: 1,
				max: maxPax,
				value: state.passengers || 1,
			},
			{
				key: 'checked_bags',
				name: 'Checked bags',
				hint: 'Suitcases in the hold',
				min: 0,
				max: 50,
				value: state.checkedBags || 0,
			},
			{
				key: 'carry_on_bags',
				name: 'Carry-on bags',
				hint: 'Bags in the cabin',
				min: 0,
				max: 50,
				value: state.carryOnBags || 0,
			},
		];
	}

	function renderGuestCounterRow(item) {
		var qty = Number(item.value) || 0;
		var active = qty > 0;
		return (
			'<div class="prime-fleet__addon' +
			(active ? ' is-active' : '') +
			'" data-guest-row="' +
			escapeHtml(item.key) +
			'">' +
			'<div class="prime-fleet__addon-copy">' +
			'<span class="prime-fleet__addon-name">' +
			escapeHtml(item.name) +
			'</span>' +
			'<span class="prime-fleet__addon-desc">' +
			escapeHtml(item.hint) +
			'</span>' +
			'</div>' +
			'<div class="prime-fleet__qty" role="group" aria-label="' +
			escapeHtml(item.name) +
			'">' +
			'<button type="button" class="prime-fleet__qty-btn" data-guest-dec="' +
			escapeHtml(item.key) +
			'" aria-label="Decrease"' +
			(qty <= item.min ? ' disabled' : '') +
			'>−</button>' +
			'<span class="prime-fleet__qty-val" data-guest-val="' +
			escapeHtml(item.key) +
			'">' +
			escapeHtml(String(qty)) +
			'</span>' +
			'<button type="button" class="prime-fleet__qty-btn" data-guest-inc="' +
			escapeHtml(item.key) +
			'" data-guest-min="' +
			escapeHtml(String(item.min)) +
			'" data-guest-max="' +
			escapeHtml(String(item.max)) +
			'" aria-label="Increase"' +
			(qty >= item.max ? ' disabled' : '') +
			'>+</button>' +
			'</div></div>'
		);
	}

	function renderPassengersStep() {
		var svc = selectedService();
		var maxPax = 50;
		if (svc && svc.slug === 'airport-vip-transfer') {
			maxPax = 13;
		}
		if (state.passengers > maxPax) {
			state.passengers = maxPax;
		}
		var rows = guestFieldMeta(maxPax)
			.map(function (item) {
				return renderGuestCounterRow(item);
			})
			.join('');
		return (
			'<div class="prime-fleet__panel prime-fleet__panel--guests"><h2>Guests & luggage</h2><p class="prime-fleet__hint">' +
			escapeHtml(
				maxPax === 13
					? i18n.paxHintSprinter ||
							'Up to 13 guests (Mercedes-Benz Sprinter). Larger parties use Custom Transportation Request.'
					: i18n.paxHint || 'We match vehicles to your party.'
			) +
			'</p><div class="prime-fleet__addons prime-fleet__addons--guests">' +
			rows +
			'</div>' +
			actions(state.passengers >= 1) +
			'</div>'
		);
	}

	function setGuestQty(key, qty) {
		var svc = selectedService();
		var maxPax = svc && svc.slug === 'airport-vip-transfer' ? 13 : 50;
		var meta = null;
		guestFieldMeta(maxPax).forEach(function (item) {
			if (item.key === key) {
				meta = item;
			}
		});
		if (!meta) {
			return;
		}
		qty = Math.max(meta.min, Math.min(meta.max, Number(qty) || 0));
		if (key === 'passengers') {
			state.passengers = qty;
		} else if (key === 'checked_bags') {
			state.checkedBags = qty;
		} else if (key === 'carry_on_bags') {
			state.carryOnBags = qty;
		}
		var row = root.querySelector('[data-guest-row="' + key + '"]');
		if (row) {
			row.classList.toggle('is-active', qty > 0);
			var val = row.querySelector('[data-guest-val="' + key + '"]');
			var dec = row.querySelector('[data-guest-dec="' + key + '"]');
			var inc = row.querySelector('[data-guest-inc="' + key + '"]');
			if (val) {
				val.textContent = String(qty);
			}
			if (dec) {
				dec.disabled = qty <= meta.min;
			}
			if (inc) {
				inc.disabled = qty >= meta.max;
			}
		}
		if (key === 'passengers') {
			scheduleQuote();
			syncNextButton();
		} else {
			scheduleQuote();
		}
		saveDraft();
	}

	function renderVehicleStep() {
		var vehicles = (state.quote && state.quote.vehicles) || [];
		var svc = selectedService();
		var choice = svc && svc.settings ? svc.settings.customer_vehicle_choice : 'recommend';
		var html = '';
		var selectable = [];
		vehicles.forEach(function (v) {
			if (v.status !== 'not_selectable') {
				selectable.push(v);
			}
		});
		if (!selectable.length) {
			html =
				'<p class="prime-fleet__hint">' +
				escapeHtml(
					i18n.vehicleAssign ||
						'No listed vehicle fits this party — Prime Fleet will confirm the right fleet after review. Fare still applies where published.'
				) +
				'</p>';
		} else {
			html = '<ul class="prime-fleet__services prime-fleet__services--stack">';
			selectable.forEach(function (v, idx) {
				var selected = Number(state.vehicleId) === Number(v.id);
				var num = idx + 1 < 10 ? '0' + (idx + 1) : String(idx + 1);
				var cap =
					v.passenger_capacity
						? escapeHtml(
								(i18n.upToPax || 'Up to %s passengers').replace('%s', String(v.passenger_capacity))
						  )
						: '';
				var notes = v.notes && v.notes.length ? escapeHtml(v.notes.join(' ')) : '';
				var desc = [cap, notes].filter(Boolean).join(' · ');
				html +=
					'<li class="prime-fleet__service-item" style="--pf-i:' +
					idx +
					'"><button type="button" class="prime-fleet__service' +
					(selected ? ' is-selected' : '') +
					'" data-vehicle-id="' +
					escapeHtml(String(v.id)) +
					'" aria-pressed="' +
					(selected ? 'true' : 'false') +
					'"><span class="prime-fleet__service-index" aria-hidden="true">' +
					num +
					'</span><span class="prime-fleet__service-body"><span class="prime-fleet__service-name">' +
					escapeHtml(v.name) +
					'</span>' +
					(desc ? '<span class="prime-fleet__service-desc">' + desc + '</span>' : '') +
					'</span><span class="prime-fleet__service-check" aria-hidden="true"></span></button></li>';
			});
			html += '</ul>';
		}
		var needPick = choice === 'required';
		var can = !needPick || !!state.vehicleId || !selectable.length;
		var hint =
			choice === 'optional' || choice === 'required'
				? escapeHtml(i18n.pickVehicleHint || 'Choose a vehicle that fits your party (Escalade or Sprinter).')
				: escapeHtml(i18n.vehicleHint || 'Suitable options for your party size.');
		return (
			'<div class="prime-fleet__panel"><h2>Vehicle</h2><p class="prime-fleet__hint">' +
			hint +
			'</p>' +
			html +
			actions(can) +
			'</div>'
		);
	}

	function renderAddonsStep() {
		var list = applicableAddons();
		var html = '';
		if (!list.length) {
			html = '<p class="prime-fleet__hint">No optional add-ons for this service.</p>';
		} else {
			html = '<div class="prime-fleet__addons">';
			list.forEach(function (a) {
				var qty = Number(state.addons[a.id]) || 0;
				var max = Number(a.max_qty) || 10;
				var price =
					escapeHtml(money(a.price, state.config.currency)) +
					(a.unit_label ? ' / ' + escapeHtml(a.unit_label) : '');
				html +=
					'<div class="prime-fleet__addon' +
					(qty > 0 ? ' is-active' : '') +
					'" data-addon-row="' +
					escapeHtml(String(a.id)) +
					'">' +
					'<div class="prime-fleet__addon-copy">' +
					'<span class="prime-fleet__addon-name">' +
					escapeHtml(a.name) +
					'</span>' +
					'<span class="prime-fleet__addon-price">' +
					price +
					'</span>' +
					(a.description
						? '<span class="prime-fleet__addon-desc">' + escapeHtml(a.description) + '</span>'
						: '') +
					'</div>' +
					'<div class="prime-fleet__qty" role="group" aria-label="' +
					escapeHtml(a.name) +
					' quantity">' +
					'<button type="button" class="prime-fleet__qty-btn" data-addon-dec="' +
					escapeHtml(String(a.id)) +
					'" aria-label="Decrease"' +
					(qty <= 0 ? ' disabled' : '') +
					'>−</button>' +
					'<span class="prime-fleet__qty-val" data-addon-val="' +
					escapeHtml(String(a.id)) +
					'">' +
					escapeHtml(String(qty)) +
					'</span>' +
					'<button type="button" class="prime-fleet__qty-btn" data-addon-inc="' +
					escapeHtml(String(a.id)) +
					'" data-addon-max="' +
					escapeHtml(String(max)) +
					'" aria-label="Increase"' +
					(qty >= max ? ' disabled' : '') +
					'>+</button>' +
					'</div></div>';
			});
			html += '</div>';
		}
		return (
			'<div class="prime-fleet__panel prime-fleet__panel--addons"><h2>Add-ons</h2><p class="prime-fleet__hint">Optional extras. Meet &amp; Greet stays included on Airport VIP.</p>' +
			html +
			actions(true) +
			'</div>'
		);
	}

	function setAddonQty(id, qty) {
		var list = applicableAddons();
		var max = 10;
		list.forEach(function (a) {
			if (String(a.id) === String(id)) {
				max = Number(a.max_qty) || 10;
			}
		});
		qty = Math.max(0, Math.min(max, Number(qty) || 0));
		state.addons[id] = qty;
		var row = root.querySelector('[data-addon-row="' + id + '"]');
		if (row) {
			row.classList.toggle('is-active', qty > 0);
			var val = row.querySelector('[data-addon-val="' + id + '"]');
			var dec = row.querySelector('[data-addon-dec="' + id + '"]');
			var inc = row.querySelector('[data-addon-inc="' + id + '"]');
			if (val) {
				val.textContent = String(qty);
			}
			if (dec) {
				dec.disabled = qty <= 0;
			}
			if (inc) {
				inc.disabled = qty >= max;
			}
		}
		scheduleQuote();
		saveDraft();
		paint({ rail: true });
	}

	function sanitizePhoneInput(value) {
		var raw = String(value || '');
		var hasPlus = raw.charAt(0) === '+';
		var digits = raw.replace(/\D/g, '');
		return (hasPlus ? '+' : '') + digits;
	}

	function renderContactStep() {
		var attempted = !!(state.stepAttempted && state.stepAttempted.contact);
		var phoneOk = phoneDigits(state.customerPhone).length >= 7;
		var phoneHint = '';
		if (state.customerPhone && !phoneOk) {
			phoneHint =
				'<p class="prime-fleet__field-error" role="alert">' +
				escapeHtml(i18n.pickPhone || 'Enter a valid phone number (at least 7 digits).') +
				'</p>';
		} else if (attempted && !contactReady()) {
			phoneHint =
				'<p class="prime-fleet__field-error" role="alert">' +
				escapeHtml(i18n.pickContact || 'Enter your name, email, and phone first.') +
				'</p>';
		}
		return (
			'<div class="prime-fleet__panel prime-fleet__panel--contact"><h2>Contact</h2><p class="prime-fleet__hint">We use this to confirm your transfer.</p><div class="prime-fleet__grid is-2"><div class="prime-fleet__field"><label for="pf-name">Full name</label><input type="text" id="pf-name" name="customer_name" autocomplete="name" value="' +
			escapeHtml(state.customerName) +
			'" /></div><div class="prime-fleet__field"><label for="pf-email">Email</label><input type="email" id="pf-email" name="customer_email" autocomplete="email" value="' +
			escapeHtml(state.customerEmail) +
			'" /></div><div class="prime-fleet__field"><label for="pf-phone">Phone</label><input type="tel" id="pf-phone" name="customer_phone" inputmode="numeric" autocomplete="tel" pattern="[0-9+]*" maxlength="20" value="' +
			escapeHtml(state.customerPhone) +
			'" />' +
			phoneHint +
			'</div><div class="prime-fleet__field"><label for="pf-wa">WhatsApp</label><input type="tel" id="pf-wa" name="customer_whatsapp" inputmode="numeric" pattern="[0-9+]*" maxlength="20" value="' +
			escapeHtml(state.customerWhatsapp) +
			'" /></div></div><div class="prime-fleet__hp" aria-hidden="true"><label>Company website<input type="text" name="company_website" tabindex="-1" autocomplete="off" value="" /></label></div>' +
			actions(contactReady()) +
			'</div>'
		);
	}

	function renderReviewStep() {
		var svc = selectedService();
		var err = state.error ? '<p class="prime-fleet__error" role="alert">' + escapeHtml(state.error) + '</p>' : '';
		var couponErr = state.couponError
			? '<p class="prime-fleet__field-error" role="alert">' + escapeHtml(state.couponError) + '</p>'
			: '';
		var couponApplied = !!(state.coupon && state.coupon.code);
		var couponOk = couponApplied
			? '<p class="prime-fleet__hint">' +
			  escapeHtml(state.coupon.message || i18n.couponApplied || 'Coupon applied.') +
			  '</p>'
			: '';
		var applyDisabled = couponApplied || state.couponBusy || state.submitting;
		var applyLabel = state.couponBusy
			? i18n.couponApplying || 'Applying…'
			: i18n.apply || 'Apply';
		var applySpin = state.couponBusy
			? '<span class="prime-fleet__spinner" aria-hidden="true"></span>'
			: '';
		var applyClasses =
			'prime-fleet__btn prime-fleet__btn--ghost' +
			(applyDisabled ? ' is-disabled' : '') +
			(state.couponBusy ? ' is-loading' : '');
		return (
			'<div class="prime-fleet__panel"><h2>Review &amp; submit</h2><p class="prime-fleet__hint">Requests default to Pending Confirmation.</p><ul class="prime-fleet__review"><li><strong>Service</strong>' +
			escapeHtml(svc ? svc.name : '') +
			'</li><li><strong>When</strong>' +
			escapeHtml(state.pickupDate + ' · ' + state.pickupTime) +
			'</li><li><strong>Contact</strong>' +
			escapeHtml(state.customerName + ' · ' + state.customerEmail) +
			'</li></ul>' +
			'<div class="prime-fleet__coupon"><div class="prime-fleet__field"><label for="pf-coupon">' +
			escapeHtml(i18n.coupon || 'Coupon code') +
			'</label><div class="prime-fleet__coupon-row"><input type="text" id="pf-coupon" name="coupon_code" value="' +
			escapeHtml(state.couponCode) +
			'" autocomplete="off"' +
			(couponApplied || state.couponBusy ? ' readonly' : '') +
			' /><button type="button" class="' +
			applyClasses +
			'" data-action="apply-coupon"' +
			(applyDisabled ? ' aria-disabled="true" disabled' : '') +
			'>' +
			applySpin +
			escapeHtml(applyLabel) +
			'</button>' +
			(couponApplied
				? '<button type="button" class="prime-fleet__btn prime-fleet__btn--ghost" data-action="remove-coupon">' +
				  escapeHtml(i18n.removeCoupon || 'Remove') +
				  '</button>'
				: '') +
			'</div></div>' +
			couponErr +
			couponOk +
			'</div>' +
			'<label class="prime-fleet__terms"><input type="checkbox" name="terms_accepted"' +
			(state.termsAccepted ? ' checked' : '') +
			'> ' +
			escapeHtml(
				i18n.terms ||
					'I agree to the booking terms and understand this request is Pending Confirmation.'
			) +
			'</label>' +
			err +
			actions(state.termsAccepted && !state.submitting, i18n.submit || 'Submit request') +
			'</div>'
		);
	}

	function panelBody() {
		switch (STEPS[state.step].key) {
			case 'service':
				return renderServiceStep();
			case 'trip':
				return renderTripStep();
			case 'schedule':
				return renderScheduleStep();
			case 'passengers':
				return renderPassengersStep();
			case 'vehicle':
				return renderVehicleStep();
			case 'addons':
				return renderAddonsStep();
			case 'contact':
				return renderContactStep();
			default:
				return renderReviewStep();
		}
	}

	function renderSteps() {
		var pct = STEPS.length > 1 ? Math.round((state.step / (STEPS.length - 1)) * 100) : 0;
		var html =
			'<div class="prime-fleet__progress-bar" aria-hidden="true"><span style="width:' +
			pct +
			'%"></span></div>' +
			'<ol class="prime-fleet__steps">';
		STEPS.forEach(function (step, i) {
			var cls = 'prime-fleet__step';
			var locked = i > state.maxStep;
			if (i === state.step) {
				cls += ' is-active';
			} else if (i <= state.maxStep) {
				cls += ' is-done is-clickable';
			} else {
				cls += ' is-locked';
			}
			var num = i + 1 < 10 ? '0' + (i + 1) : String(i + 1);
			html +=
				'<li class="prime-fleet__step-item"><span role="button" tabindex="' +
				(locked ? '-1' : '0') +
				'" class="' +
				cls +
				'" data-step-index="' +
				i +
				'"' +
				(i === state.step ? ' aria-current="step"' : '') +
				(locked ? ' aria-disabled="true"' : '') +
				'><span class="prime-fleet__step-num">' +
				num +
				'</span><span class="prime-fleet__step-label">' +
				escapeHtml(step.label) +
				'</span></span></li>';
		});
		html += '</ol>';
		return html;
	}

	function renderBoot() {
		return (
			'<div class="prime-fleet__boot prime-fleet__boot--simple" role="status" aria-label="Loading">' +
			'<span class="prime-fleet__loader" aria-hidden="true"><span class="prime-fleet__loader-core"></span></span>' +
			'</div>'
		);
	}

	function showToast(message) {
		var host = root.querySelector('[data-pf-toast]');
		if (!host) {
			host = document.createElement('div');
			host.className = 'prime-fleet__toasts';
			host.setAttribute('data-pf-toast', '1');
			root.appendChild(host);
		}
		var el = document.createElement('div');
		el.className = 'prime-fleet__toast';
		el.setAttribute('role', 'status');
		el.textContent = message;
		host.appendChild(el);
		requestAnimationFrame(function () {
			el.classList.add('is-in');
		});
		setTimeout(function () {
			el.classList.remove('is-in');
			el.classList.add('is-out');
			setTimeout(function () {
				if (el.parentNode) {
					el.parentNode.removeChild(el);
				}
			}, 280);
		}, 2800);
	}

	function ensureShell() {
		if (root.querySelector('[data-pf-shell="1"]')) {
			return;
		}
		root.innerHTML =
			'<div data-pf-shell="1" class="prime-fleet__shell">' +
			'<header class="prime-fleet__header">' +
			'<div class="prime-fleet__brand-row">' +
			'<span class="prime-fleet__brand-mark" aria-hidden="true">PF</span>' +
			'<span class="prime-fleet__brand-name">Prime Fleet</span>' +
			'</div>' +
			'<p class="prime-fleet__kicker">' +
			escapeHtml(i18n.kicker || 'Private fleet booking') +
			'</p>' +
			'<h1 class="prime-fleet__title">' +
			escapeHtml(i18n.title || 'Book a drive') +
			'</h1>' +
			'<p class="prime-fleet__subtitle">' +
			escapeHtml(i18n.subtitle || 'Pick a service, confirm details, and we’ll take it from there.') +
			'</p></header>' +
			'<nav class="prime-fleet__progress" data-pf-steps aria-label="Booking progress"></nav>' +
			'<div class="prime-fleet__layout">' +
			'<div class="prime-fleet__main" data-pf-main></div>' +
			'<div class="prime-fleet__rail-slot" data-pf-rail-wrap></div>' +
			'</div>' +
			'<div class="prime-fleet__toasts" data-pf-toast="1" aria-live="polite"></div>' +
			'</div>';
	}

	function paintRailHtml() {
		return renderRail();
	}

	function renderSubmitOverlay() {
		var theme = root.getAttribute('data-theme') || 'dark';
		return (
			'<div class="prime-fleet__submit-overlay" data-theme="' +
			escapeHtml(theme) +
			'" role="alertdialog" aria-modal="true" aria-busy="true" aria-live="assertive" aria-labelledby="pf-submit-title" aria-describedby="pf-submit-detail">' +
			'<div class="prime-fleet__submit-card">' +
			'<div class="prime-fleet__loader prime-fleet__loader--submit" aria-hidden="true"><span class="prime-fleet__loader-core"></span></div>' +
			'<p class="prime-fleet__submit-title" id="pf-submit-title">' +
			escapeHtml(i18n.submittingTitle || 'Sending your request') +
			'</p>' +
			'<p class="prime-fleet__submit-detail" id="pf-submit-detail">' +
			escapeHtml(
				i18n.submittingDetail || 'Hang tight — we’re confirming details with dispatch.'
			) +
			'</p>' +
			'<div class="prime-fleet__submit-meter" aria-hidden="true"><span></span></div>' +
			'</div></div>'
		);
	}

	function syncSubmitOverlay() {
		var existing = document.querySelector('.prime-fleet__submit-overlay');
		if (state.submitting) {
			root.classList.add('is-submitting');
			document.documentElement.classList.add('prime-fleet-submitting');
			if (!existing) {
				document.body.insertAdjacentHTML('beforeend', renderSubmitOverlay());
			} else {
				existing.setAttribute('data-theme', root.getAttribute('data-theme') || 'dark');
			}
		} else {
			root.classList.remove('is-submitting');
			document.documentElement.classList.remove('prime-fleet-submitting');
			if (existing) {
				existing.remove();
			}
		}
	}

	function paint(parts) {
		parts = parts || { shell: true, steps: true, main: true, rail: true };
		if (state.result) {
			state.submitting = false;
			root.classList.remove('is-submitting');
			renderSuccess();
			syncUrl({ push: false });
			return;
		}
		if (!state.config) {
			root.innerHTML = renderBoot();
			return;
		}
		ensureShell();
		if (parts.steps) {
			var stepsEl = root.querySelector('[data-pf-steps]');
			if (stepsEl) {
				stepsEl.innerHTML = renderSteps();
			}
		}
		if (parts.main) {
			var mainEl = root.querySelector('[data-pf-main]');
			if (mainEl) {
				// Replace panel HTML only — enter animation is owned by swapMain (step changes).
				mainEl.innerHTML = panelBody();
			}
		}
		if (parts.rail) {
			var railWrap = root.querySelector('[data-pf-rail-wrap]');
			if (railWrap) {
				railWrap.innerHTML = paintRailHtml();
			}
		}
		syncSubmitOverlay();
	}

	/** In-step updates: refresh service list + cat active state without remounting the panel chrome. */
	function paintServiceList() {
		var list = root.querySelector('[data-pf-service-list]');
		if (!list) {
			paint({ main: true });
			return;
		}
		root.querySelectorAll('.prime-fleet__cat[data-service-group]').forEach(function (el) {
			var on = el.getAttribute('data-service-group') === state.serviceGroup;
			el.classList.toggle('is-active', on);
		});
		list.innerHTML = renderServiceListItems();
		syncNextButton();
	}

	/** Toggle selected card in-place (service or vehicle) — no panel remount. */
	function paintSelection(attr, id) {
		var sel = '[' + attr + ']';
		root.querySelectorAll(sel).forEach(function (el) {
			var on = String(el.getAttribute(attr)) === String(id);
			el.classList.toggle('is-selected', on);
			el.setAttribute('aria-pressed', on ? 'true' : 'false');
		});
		var err = root.querySelector('.prime-fleet__panel > .prime-fleet__field-error');
		if (err && err.parentNode) {
			err.parentNode.removeChild(err);
		}
		syncNextButton();
	}

	function renderSuccess() {
		var r = state.result || {};
		var ref = r.reference || '';
		root.innerHTML =
			'<div class="prime-fleet__success">' +
			'<div class="prime-fleet__success-inner">' +
			'<p class="prime-fleet__brand prime-fleet__brand--center"><span class="prime-fleet__brand-mark" aria-hidden="true">PF</span><span>Prime Fleet</span></p>' +
			'<p class="prime-fleet__success-eyebrow">' +
			escapeHtml(i18n.pendingShort || 'Pending confirmation') +
			'</p>' +
			'<h2 class="prime-fleet__success-title">' +
			escapeHtml(i18n.thanks || 'Request received') +
			'</h2>' +
			'<div class="prime-fleet__ref-block">' +
			'<p class="prime-fleet__ref-label">' +
			escapeHtml(i18n.yourRef || 'Your reference') +
			'</p>' +
			'<p class="prime-fleet__ref" id="prime-fleet-ref">' +
			escapeHtml(ref) +
			'</p>' +
			'</div>' +
			'<p class="prime-fleet__success-copy">' +
			escapeHtml(
				r.message ||
					i18n.pending ||
					'Your transfer request is Pending Confirmation. Prime Fleet will verify vehicle availability and contact you shortly.'
			) +
			'</p>' +
			'<div class="prime-fleet__success-actions">' +
			'<button type="button" class="prime-fleet__btn prime-fleet__btn--primary" data-action="restart">' +
			escapeHtml(i18n.bookAnother || 'Book another transfer') +
			'</button>' +
			'<button type="button" class="prime-fleet__btn prime-fleet__btn--ghost" data-action="copy-ref"' +
			(ref ? '' : ' disabled') +
			'>' +
			escapeHtml(i18n.copyRef || 'Copy reference') +
			'</button>' +
			'</div>' +
			'<p class="prime-fleet__success-hint">' +
			escapeHtml(i18n.lookupHint || 'Use your reference and booking email anytime with Check booking status.') +
			'</p>' +
			'</div></div>';
	}

	function restartBooking() {
		state.result = null;
		state.error = '';
		state.step = 0;
		state.maxStep = 0;
		state.serviceId = 0;
		state.serviceGroup = '';
		state.tripType = 'one_way';
		state.originZoneId = 0;
		state.destZoneId = 0;
		state.pickupDate = '';
		state.pickupTime = '';
		state.passengers = 1;
		state.checkedBags = 0;
		state.carryOnBags = 0;
		state.vehicleId = 0;
		state.addons = {};
		state.customerName = '';
		state.customerEmail = '';
		state.customerPhone = '';
		state.customerWhatsapp = '';
		state.termsAccepted = false;
		state.couponCode = '';
		state.coupon = null;
		state.couponError = '';
		state.couponBusy = false;
		invalidateQuotes();
		state.stepAttempted = {};
		state.submitting = false;
		syncSubmitOverlay();
		state.idempotencyKey =
			'pf_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10);
		clearDraft();
		ensureServiceGroup();
		syncUrl({ push: true });
		paint({ steps: true, main: true, rail: true });
		issueToken();
		try {
			root.scrollIntoView({ behavior: 'smooth', block: 'start' });
		} catch (e) {}
	}

	function copyReference() {
		var ref = (state.result && state.result.reference) || '';
		if (!ref) {
			return;
		}
		var done = function () {
			showToast(i18n.copied || 'Reference copied.');
		};
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(ref).then(done).catch(function () {
				fallbackCopy(ref, done);
			});
		} else {
			fallbackCopy(ref, done);
		}
	}

	function fallbackCopy(text, done) {
		var ta = document.createElement('textarea');
		ta.value = text;
		ta.setAttribute('readonly', '');
		ta.style.position = 'absolute';
		ta.style.left = '-9999px';
		root.appendChild(ta);
		ta.select();
		try {
			document.execCommand('copy');
			done();
		} catch (e) {}
		root.removeChild(ta);
	}

	/** @deprecated use paint — kept as alias for any leftover calls */
	function render(parts) {
		if (!parts || parts === true) {
			paint({ steps: true, main: true, rail: true });
			return;
		}
		paint(parts);
	}

	function phoneDigits(value) {
		return String(value || '').replace(/\D/g, '');
	}

	function pickupRules() {
		return (state.config && state.config.pickup) || {};
	}

	function earliestPickupParts() {
		var rules = pickupRules();
		var raw = String(rules.earliest_pickup || '');
		var m = raw.match(/^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2})/);
		if (!m) {
			var today = rules.min_date || rules.today || '';
			return { date: today, time: '00:00' };
		}
		var y = Number(m[1].slice(0, 4));
		var mo = Number(m[1].slice(5, 7));
		var d = Number(m[1].slice(8, 10));
		var hh = Number(m[2].slice(0, 2));
		var mm = Number(m[2].slice(3, 5));
		var total = hh * 60 + mm;
		// Keep window honest if the page stayed open.
		if (state._configFetchedAt) {
			total += Math.max(0, Math.floor((Date.now() - state._configFetchedAt) / 60000));
		}
		var dayAdd = Math.floor(total / (24 * 60));
		total = total % (24 * 60);
		var dateObj = new Date(Date.UTC(y, mo - 1, d));
		if (dayAdd > 0) {
			dateObj.setUTCDate(dateObj.getUTCDate() + dayAdd);
		}
		return {
			date:
				dateObj.getUTCFullYear() +
				'-' +
				pad2(dateObj.getUTCMonth() + 1) +
				'-' +
				pad2(dateObj.getUTCDate()),
			time: pad2(Math.floor(total / 60)) + ':' + pad2(total % 60),
		};
	}

	function scheduleMinDate() {
		var rules = pickupRules();
		var early = earliestPickupParts();
		var minDate = rules.min_date || rules.today || '';
		if (early.date && (!minDate || early.date > minDate)) {
			return early.date;
		}
		return minDate;
	}

	function scheduleMinTimeForDate(date) {
		var early = earliestPickupParts();
		var rules = pickupRules();
		if (!date) {
			return '';
		}
		// Same calendar day as earliest allowed pickup → enforce min time.
		if (early.date && date === early.date) {
			return early.time;
		}
		// Today still before earliest spills to tomorrow is handled by min_date.
		if (rules.today && date === rules.today && early.date && date < early.date) {
			return '23:59';
		}
		return '';
	}

	function pad2(n) {
		return (n < 10 ? '0' : '') + n;
	}

	function formatTimeLabel(hhmm) {
		var p = String(hhmm || '').split(':');
		var h = Number(p[0]);
		var m = Number(p[1]) || 0;
		if (isNaN(h)) {
			return hhmm;
		}
		var ampm = h >= 12 ? 'PM' : 'AM';
		var h12 = h % 12;
		if (h12 === 0) {
			h12 = 12;
		}
		return h12 + ':' + pad2(m) + ' ' + ampm;
	}

	/**
	 * Build selectable pickup times for a date. Same-day slots before now+2h are omitted.
	 */
	function scheduleTimeOptions(date) {
		var rules = pickupRules();
		var inc = Number(rules.slot_increment) || 30;
		if (inc < 5) {
			inc = 30;
		}
		var minTime = scheduleMinTimeForDate(date);
		var options = [];
		var i;
		for (i = 0; i < 24 * 60; i += inc) {
			var hh = pad2(Math.floor(i / 60));
			var mm = pad2(i % 60);
			var t = hh + ':' + mm;
			if (minTime && t < minTime) {
				continue;
			}
			options.push(t);
		}
		// Ensure the exact earliest minute is offered (e.g. 08:07 when slots are :00/:30).
		if (minTime && options.indexOf(minTime) === -1) {
			options.push(minTime);
			options.sort();
		}
		return options;
	}

	function scheduleConstraintError() {
		var rules = pickupRules();
		if (!state.pickupDate || !state.pickupTime) {
			return '';
		}
		var minDate = scheduleMinDate();
		if (minDate && state.pickupDate < minDate) {
			return i18n.pickupPast || 'Pickup date cannot be in the past.';
		}
		if (rules.same_day_allowed === false || rules.same_day_allowed === 0) {
			if (rules.today && state.pickupDate === rules.today) {
				return i18n.pickupSameDay || 'Same-day bookings are not available. Please choose a later date.';
			}
		}
		var early = earliestPickupParts();
		if (early.date && state.pickupDate + ' ' + state.pickupTime < early.date + ' ' + early.time) {
			var hours = rules.min_advance_hours != null ? rules.min_advance_hours : 2;
			var tpl = i18n.pickupAdvance || 'Pickup must be at least %s hours from now.';
			return tpl.replace('%s', String(hours));
		}
		return '';
	}

	function scheduleError() {
		if (!state.pickupDate || !state.pickupTime) {
			return i18n.pickSchedule || 'Choose a pickup date and time first.';
		}
		return scheduleConstraintError();
	}

	function scheduleInlineError() {
		var attempted = !!(state.stepAttempted && state.stepAttempted.schedule);
		if (!state.pickupDate || !state.pickupTime) {
			return attempted ? scheduleError() : '';
		}
		return scheduleConstraintError();
	}

	function scheduleReady() {
		return !!(state.pickupDate && state.pickupTime && !scheduleConstraintError());
	}

	function sanitizeScheduleSelection() {
		var minDate = scheduleMinDate();
		var changed = false;
		if (state.pickupDate && minDate && state.pickupDate < minDate) {
			state.pickupDate = '';
			state.pickupTime = '';
			changed = true;
		}
		if (state.pickupDate && state.pickupTime) {
			var allowed = scheduleTimeOptions(state.pickupDate);
			if (allowed.indexOf(state.pickupTime) === -1) {
				state.pickupTime = '';
				changed = true;
			}
		}
		return changed;
	}

	function markStepAttempted() {
		var key = STEPS[state.step] && STEPS[state.step].key;
		if (!key) {
			return;
		}
		if (!state.stepAttempted) {
			state.stepAttempted = {};
		}
		state.stepAttempted[key] = true;
	}

	/**
	 * Blocked Continue / locked step: toast + optional inline error.
	 * Never remounts the panel (avoids service-list flash).
	 */
	function notifyStepBlocked(message) {
		markStepAttempted();
		var msg = message || advanceBlockedMessage();
		var panel = root.querySelector('.prime-fleet__panel');
		if (panel) {
			var key = STEPS[state.step] && STEPS[state.step].key;
			var inline = null;
			if (key === 'service' && !state.serviceId) {
				inline = i18n.pickService || 'Please choose a service first.';
			}
			if (inline) {
				var err = panel.querySelector(':scope > .prime-fleet__field-error');
				if (!err) {
					err = document.createElement('p');
					err.className = 'prime-fleet__field-error';
					err.setAttribute('role', 'alert');
					var hint = panel.querySelector('.prime-fleet__hint');
					if (hint && hint.nextSibling) {
						panel.insertBefore(err, hint.nextSibling);
					} else if (hint) {
						hint.after(err);
					} else {
						panel.insertBefore(err, panel.firstChild);
					}
				}
				err.textContent = inline;
			}
		}
		showToast(msg);
	}

	function clearStepAttempted(key) {
		if (state.stepAttempted && key) {
			delete state.stepAttempted[key];
		}
	}

	function stepReadyAt(idx) {
		var key = STEPS[idx] && STEPS[idx].key;
		if (!key) {
			return true;
		}
		if (key === 'service') {
			return !!state.serviceId;
		}
		if (key === 'trip') {
			var svc = selectedService();
			if (svc && svc.booking_type === 'route') {
				return !!(state.originZoneId && state.destZoneId && state.originZoneId !== state.destZoneId);
			}
			return true;
		}
		if (key === 'schedule') {
			return scheduleReady();
		}
		if (key === 'passengers') {
			return state.passengers >= 1;
		}
		if (key === 'vehicle') {
			var vehicles = (state.quote && state.quote.vehicles) || [];
			var s = selectedService();
			var choice = s && s.settings ? s.settings.customer_vehicle_choice : 'recommend';
			return choice !== 'required' || !!state.vehicleId || !vehicles.length;
		}
		if (key === 'addons') {
			return true;
		}
		if (key === 'contact') {
			return contactReady();
		}
		if (key === 'review') {
			return !!state.termsAccepted;
		}
		return true;
	}

	function firstIncompleteStep() {
		var i;
		for (i = 0; i < STEPS.length; i++) {
			if (!stepReadyAt(i)) {
				return i;
			}
		}
		return STEPS.length - 1;
	}

	function contactReady() {
		return (
			state.customerName.trim().length > 1 &&
			state.customerEmail.indexOf('@') > 0 &&
			phoneDigits(state.customerPhone).length >= 7
		);
	}

	/**
	 * Changing service invalidates trip / schedule / vehicle / add-ons / quote.
	 * Contact details are kept.
	 *
	 * @param {number} nextId New service id.
	 */
	function resetAfterServiceChange(nextId) {
		state.serviceId = nextId;
		state.step = 0;
		state.maxStep = 0;
		state.tripType = 'one_way';
		state.originZoneId = 0;
		state.destZoneId = 0;
		state.pickupDate = '';
		state.pickupTime = '';
		state.passengers = 1;
		state.checkedBags = 0;
		state.carryOnBags = 0;
		state.vehicleId = 0;
		state.addons = {};
		invalidateQuotes();
		state.coupon = null;
		state.couponCode = '';
		state.couponError = '';
		state.couponBusy = false;
		state.error = '';
		sanitizeZonesForService();
		ensureServiceGroup();
	}

	function canAdvance() {
		return stepReadyAt(state.step);
	}

	function advanceBlockedMessage() {
		var key = STEPS[state.step].key;
		if (key === 'service') {
			return i18n.pickService || 'Please choose a service first.';
		}
		if (key === 'trip') {
			if (zonesConflict()) {
				return i18n.sameZones || 'Pickup and destination must be different.';
			}
			return i18n.pickZones || 'Select pickup and destination zones first.';
		}
		if (key === 'schedule') {
			return scheduleError() || i18n.pickSchedule || 'Choose a pickup date and time first.';
		}
		if (key === 'contact') {
			if (phoneDigits(state.customerPhone).length > 0 && phoneDigits(state.customerPhone).length < 7) {
				return i18n.pickPhone || 'Enter a valid phone number (at least 7 digits).';
			}
			return i18n.pickContact || 'Enter your name, email, and phone first.';
		}
		if (key === 'review') {
			return i18n.pickTerms || 'Please accept the booking terms to continue.';
		}
		if (key === 'vehicle') {
			return i18n.pickVehicle || 'Please choose a vehicle to continue.';
		}
		return i18n.completeStep || 'Complete this step before continuing.';
	}

	function submitBooking() {
		if (state.submitting) {
			return;
		}
		var incomplete = firstIncompleteStep();
		if (!stepReadyAt(STEPS.length - 1) || incomplete < STEPS.length - 1) {
			var target = incomplete;
			goToStep(target);
			if (!state.stepAttempted) {
				state.stepAttempted = {};
			}
			state.stepAttempted[STEPS[target].key] = true;
			paint({ steps: true, main: true, rail: true });
			showToast(advanceBlockedMessage());
			return;
		}
		state.submitting = true;
		state.error = '';
		paint({ main: true, rail: true });
		syncSubmitOverlay();

		var body = Object.assign(quotePayload(), {
			booking_token: state.bookingToken,
			idempotency_key: state.idempotencyKey,
			vehicle_id: state.vehicleId,
			customer_name: state.customerName,
			customer_email: state.customerEmail,
			customer_phone: state.customerPhone,
			customer_whatsapp: state.customerWhatsapp || state.customerPhone,
			terms_accepted: true,
			company_website: '',
			coupon_code: state.couponCode || '',
		});
		var hp = root.querySelector('[name="company_website"]');
		if (hp) {
			body.company_website = hp.value;
		}

		api('/bookings', {
			method: 'POST',
			headers: { 'X-Idempotency-Key': state.idempotencyKey },
			body: body,
		})
			.then(function (res) {
				state.result = res;
				clearDraft();
			})
			.catch(function (err) {
				state.error = (err && err.message) || i18n.submitFail || 'Could not submit booking.';
				if (err && (err.status === 403 || err.status === 409)) {
					issueToken();
				}
			})
			.finally(function () {
				state.submitting = false;
				syncSubmitOverlay();
				paint({ steps: true, main: true, rail: true });
			});
	}

	function issueToken() {
		return api('/booking-token', { method: 'POST', body: {} })
			.then(function (data) {
				state.bookingToken = data.token || '';
			})
			.catch(function () {
				state.bookingToken = '';
			});
	}

	function onClick(e) {
		if (e.target.closest('.prime-fleet__select-search')) {
			return;
		}
		var calDay = e.target.closest('[data-pf-cal-day]');
		if (calDay && !calDay.disabled) {
			var dateWrap = calDay.closest('[data-pf-date]');
			if (dateWrap) {
				commitFancyDate(dateWrap, calDay.getAttribute('data-pf-cal-day') || '');
			}
			return;
		}
		var calNav = e.target.closest('[data-pf-cal-nav]');
		if (calNav) {
			e.preventDefault();
			shiftCalMonth(calNav.closest('[data-pf-date]'), calNav.getAttribute('data-pf-cal-nav') === 'prev' ? -1 : 1);
			return;
		}
		var dateTrigger = e.target.closest('.prime-fleet__date-trigger');
		if (dateTrigger) {
			e.preventDefault();
			toggleFancyDate(dateTrigger.closest('[data-pf-date]'));
			return;
		}
		var selectOpt = e.target.closest('[data-pf-select-option]');
		if (selectOpt) {
			var optWrap = selectOpt.closest('[data-pf-select]');
			if (optWrap) {
				commitFancySelect(optWrap, selectOpt.getAttribute('data-value') || '');
			}
			return;
		}
		var selectTrigger = e.target.closest('.prime-fleet__select-trigger');
		if (selectTrigger) {
			e.preventDefault();
			toggleFancySelect(selectTrigger.closest('[data-pf-select]'));
			return;
		}
		if (!e.target.closest('[data-pf-select]') && !e.target.closest('[data-pf-date]')) {
			closeAllFancySelects();
			closeAllDatePickers();
		}

		var tripTab = e.target.closest('[data-trip-type]');
		if (tripTab) {
			var nextTrip = tripTab.getAttribute('data-trip-type') === 'round_trip' ? 'round_trip' : 'one_way';
			if (nextTrip !== state.tripType) {
				state.tripType = nextTrip;
				root.querySelectorAll('[data-trip-type]').forEach(function (btn) {
					var on = btn.getAttribute('data-trip-type') === nextTrip;
					btn.classList.toggle('is-active', on);
					btn.setAttribute('aria-selected', on ? 'true' : 'false');
				});
				scheduleQuote();
				saveDraft();
			}
			return;
		}

		var guestDec = e.target.closest('[data-guest-dec]');
		if (guestDec) {
			e.preventDefault();
			var gDecKey = guestDec.getAttribute('data-guest-dec');
			var gDecCur =
				gDecKey === 'passengers'
					? state.passengers
					: gDecKey === 'checked_bags'
						? state.checkedBags
						: state.carryOnBags;
			setGuestQty(gDecKey, (Number(gDecCur) || 0) - 1);
			return;
		}
		var guestInc = e.target.closest('[data-guest-inc]');
		if (guestInc) {
			e.preventDefault();
			var gIncKey = guestInc.getAttribute('data-guest-inc');
			var gIncCur =
				gIncKey === 'passengers'
					? state.passengers
					: gIncKey === 'checked_bags'
						? state.checkedBags
						: state.carryOnBags;
			setGuestQty(gIncKey, (Number(gIncCur) || 0) + 1);
			return;
		}

		var addonDec = e.target.closest('[data-addon-dec]');
		if (addonDec) {
			e.preventDefault();
			var decId = addonDec.getAttribute('data-addon-dec');
			setAddonQty(decId, (Number(state.addons[decId]) || 0) - 1);
			return;
		}
		var addonInc = e.target.closest('[data-addon-inc]');
		if (addonInc) {
			e.preventDefault();
			var incId = addonInc.getAttribute('data-addon-inc');
			setAddonQty(incId, (Number(state.addons[incId]) || 0) + 1);
			return;
		}

		var altBtn = e.target.closest('[data-alt-dt]');
		if (altBtn) {
			var dt = altBtn.getAttribute('data-alt-dt') || '';
			var m = dt.match(/^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2})/);
			if (m) {
				state.pickupDate = m[1];
				state.pickupTime = m[2];
				state.coupon = null;
				paint({ main: true });
				scheduleQuote({ quiet: true });
				saveDraft();
			}
			return;
		}

		var stepBtn = e.target.closest('[data-step-index]');
		if (stepBtn) {
			var idx = Number(stepBtn.getAttribute('data-step-index'));
			if (idx > state.maxStep) {
				notifyStepBlocked(
					!state.serviceId
						? i18n.pickService || 'Please choose a service first.'
						: i18n.completeStep || 'Complete this step before continuing.'
				);
				return;
			}
			if (idx > state.step) {
				var incomplete = firstIncompleteStep();
				if (incomplete < idx) {
					markStepAttempted();
					goToStep(incomplete);
					state.stepAttempted[STEPS[incomplete].key] = true;
					paint({ steps: true, main: true, rail: true });
					showToast(advanceBlockedMessage());
					return;
				}
			}
			if (idx >= 0 && idx <= state.maxStep) {
				goToStep(idx);
				swapMain(function () {
					paint({ steps: true, main: true, rail: true });
				});
				saveDraft();
			}
			return;
		}

		var catBtn = e.target.closest('[data-service-group]');
		if (catBtn) {
			var nextGroup = catBtn.getAttribute('data-service-group') || '';
			if (nextGroup === state.serviceGroup) {
				return;
			}
			state.serviceGroup = nextGroup;
			paintServiceList();
			return;
		}

		var svcBtn = e.target.closest('[data-service-id]');
		if (svcBtn) {
			var nextId = Number(svcBtn.getAttribute('data-service-id'));
			if (nextId !== Number(state.serviceId)) {
				resetAfterServiceChange(nextId);
			} else {
				state.serviceId = nextId;
			}
			paintSelection('data-service-id', state.serviceId);
			paint({ steps: true, rail: true });
			saveDraft();
			return;
		}

		var vehBtn = e.target.closest('[data-vehicle-id]');
		if (vehBtn) {
			state.vehicleId = Number(vehBtn.getAttribute('data-vehicle-id'));
			paintSelection('data-vehicle-id', state.vehicleId);
			paint({ steps: true, rail: true });
			saveDraft();
			return;
		}

		var actionBtn = e.target.closest('[data-action]');
		if (!actionBtn) {
			return;
		}
		var action = actionBtn.getAttribute('data-action');
		if (action === 'apply-coupon') {
			if (actionBtn.classList.contains('is-disabled') || actionBtn.disabled) {
				return;
			}
			applyCoupon();
			return;
		}
		if (action === 'remove-coupon') {
			removeCoupon();
			return;
		}
		if (action === 'restart') {
			restartBooking();
			return;
		}
		if (action === 'copy-ref') {
			copyReference();
			return;
		}
		if (action === 'back' && state.step > 0) {
			goToStep(state.step - 1);
			swapMain(function () {
				paint({ steps: true, main: true, rail: true });
			});
			saveDraft();
			return;
		}
		if (action === 'next') {
			if (actionBtn.classList.contains('is-disabled') || actionBtn.getAttribute('aria-disabled') === 'true') {
				notifyStepBlocked();
				return;
			}
			if (!canAdvance()) {
				notifyStepBlocked();
				return;
			}
			clearStepAttempted(STEPS[state.step].key);
			if (STEPS[state.step].key === 'review') {
				submitBooking();
				return;
			}
			if (state.step < STEPS.length - 1) {
				goToStep(state.step + 1);
				swapMain(function () {
					paint({ steps: true, main: true, rail: true });
				});
				saveDraft();
				var key = STEPS[state.step].key;
				// Recalc only when inputs are ready — never estimate a route with empty zones.
				if (key === 'trip' || key === 'passengers' || key === 'addons') {
					if (quoteInputsReady()) {
						scheduleQuote({ quiet: hasPricedQuote() });
					} else {
						invalidateQuotes();
						paint({ rail: true });
					}
				}
			}
		}
	}

	function applyCoupon() {
		var input = root.querySelector('[name="coupon_code"]');
		state.couponCode = input ? String(input.value || '').trim() : state.couponCode;
		state.couponError = '';
		if (state.coupon && state.coupon.code) {
			return;
		}
		if (!state.couponCode) {
			state.couponError = 'Enter a coupon code.';
			paint({ main: true });
			return;
		}
		if (!state.quote || state.quote.manual_quote || state.quote.total == null) {
			state.couponError = 'Calculate a fare before applying a coupon.';
			paint({ main: true });
			return;
		}
		state.couponBusy = true;
		paint({ main: true, rail: true });
		api('/coupons/validate', {
			method: 'POST',
			body: {
				code: state.couponCode,
				service_id: state.serviceId,
				fare_total: state.quote.total,
				email: state.customerEmail,
			},
		})
			.then(function (data) {
				state.coupon = data;
				state.couponCode = data.code || state.couponCode;
				state.couponError = '';
			})
			.catch(function (err) {
				state.coupon = null;
				state.couponError = (err && err.message) || 'Invalid coupon.';
			})
			.finally(function () {
				state.couponBusy = false;
				paint({ main: true, rail: true });
				saveDraft();
			});
	}

	function removeCoupon() {
		state.coupon = null;
		state.couponCode = '';
		state.couponError = '';
		state.couponBusy = false;
		paint({ main: true, rail: true });
		saveDraft();
	}

	function onChange(e) {
		var t = e.target;
		if (!t) {
			return;
		}
		if (t.name === 'origin_zone_id') {
			state.originZoneId = Number(t.value) || 0;
			if (Number(state.originZoneId) === Number(state.destZoneId)) {
				state.destZoneId = 0;
			}
			saveDraft();
			paint({ main: true });
			if (quoteInputsReady()) {
				scheduleQuote({ force: true });
			} else {
				invalidateQuotes();
				paint({ rail: true });
			}
			return;
		}
		if (t.name === 'dest_zone_id') {
			state.destZoneId = Number(t.value) || 0;
			if (Number(state.originZoneId) === Number(state.destZoneId)) {
				state.originZoneId = 0;
			}
			saveDraft();
			paint({ main: true });
			if (quoteInputsReady()) {
				scheduleQuote({ force: true });
			} else {
				invalidateQuotes();
				paint({ rail: true });
			}
			return;
		}
		if (t.name === 'pf_trip_type') {
			state.tripType = t.value === 'round_trip' ? 'round_trip' : 'one_way';
			scheduleQuote();
			saveDraft();
			return;
		}
		if (t.name === 'pickup_date') {
			state.pickupDate = t.value;
			sanitizeScheduleSelection();
			// Date can affect surcharges — refresh quietly (no fare flash).
			scheduleQuote({ quiet: true });
			saveDraft();
			paint({ main: true, rail: true });
			return;
		}
		if (t.name === 'pickup_time') {
			state.pickupTime = t.value;
			sanitizeScheduleSelection();
			saveDraft();
			paint({ main: true, rail: true });
			// Time does not change published $ — occupancy only.
			refreshAvailabilityOnly();
			return;
		}
		if (t.name === 'passengers') {
			state.passengers = Math.max(1, Number(t.value) || 1);
			scheduleQuote();
			saveDraft();
			return;
		}
		if (t.name === 'checked_bags') {
			state.checkedBags = Math.max(0, Number(t.value) || 0);
			scheduleQuote();
			saveDraft();
			return;
		}
		if (t.name === 'carry_on_bags') {
			state.carryOnBags = Math.max(0, Number(t.value) || 0);
			scheduleQuote();
			saveDraft();
			return;
		}
		if (t.getAttribute('data-addon-id')) {
			state.addons[t.getAttribute('data-addon-id')] = Math.max(0, Number(t.value) || 0);
			scheduleQuote();
			saveDraft();
			return;
		}
		if (t.name === 'customer_name') {
			state.customerName = t.value;
			saveDraft();
			return;
		}
		if (t.name === 'customer_email') {
			state.customerEmail = t.value;
			saveDraft();
			return;
		}
		if (t.name === 'customer_phone') {
			var phoneClean = sanitizePhoneInput(t.value);
			if (phoneClean !== t.value) {
				t.value = phoneClean;
			}
			state.customerPhone = phoneClean;
			saveDraft();
			syncNextButton();
			return;
		}
		if (t.name === 'customer_whatsapp') {
			var waClean = sanitizePhoneInput(t.value);
			if (waClean !== t.value) {
				t.value = waClean;
			}
			state.customerWhatsapp = waClean;
			saveDraft();
			return;
		}
		if (t.name === 'terms_accepted' || t.name === 'terms') {
			state.termsAccepted = !!t.checked;
			paint({ main: true });
			saveDraft();
			return;
		}
		if (t.name === 'coupon_code') {
			state.couponCode = t.value;
			state.coupon = null;
			state.couponError = '';
		}
	}

	function syncNextButton() {
		var btn = root.querySelector('[data-action="next"]');
		if (!btn) {
			return;
		}
		var ok = canAdvance() && !state.submitting;
		btn.classList.toggle('is-disabled', !ok);
		btn.setAttribute('aria-disabled', ok ? 'false' : 'true');
	}

	root.addEventListener(
		'pointerdown',
		function (e) {
			if (!e.target || !root.contains(e.target)) {
				return;
			}
			var hit = e.target.closest(
				'.prime-fleet__btn, .prime-fleet__step, .prime-fleet__cat, .prime-fleet__service'
			);
			if (!hit) {
				return;
			}
			spawnRipple(e);
		},
		{ passive: true }
	);
	root.addEventListener('keydown', function (e) {
		var t = e.target;
		if (!t || !t.classList) {
			return;
		}
		if (e.key === 'Escape') {
			closeAllFancySelects();
			closeAllDatePickers();
		}
		if (!t.classList.contains('prime-fleet__step') && !t.classList.contains('prime-fleet__cat')) {
			return;
		}
		if (e.key !== 'Enter' && e.key !== ' ') {
			return;
		}
		e.preventDefault();
		t.click();
	});
	root.addEventListener('click', onClick);
	root.addEventListener('change', onChange);
	root.addEventListener('input', function (e) {
		var t = e.target;
		if (!t) {
			return;
		}
		if (t.classList && t.classList.contains('prime-fleet__select-search')) {
			filterFancySelect(t.closest('[data-pf-select]'), t.value);
			return;
		}
		if (
			t.name === 'customer_name' ||
			t.name === 'customer_email' ||
			t.name === 'customer_phone' ||
			t.name === 'customer_whatsapp' ||
			t.name === 'pickup_date' ||
			t.name === 'pickup_time' ||
			t.getAttribute('data-addon-id')
		) {
			onChange(e);
			syncNextButton();
		}
	});

	state.idempotencyKey =
		'pf_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10);
	loadDraft();
	applyUrlState();
	paint();

	window.addEventListener('popstate', function () {
		if (!state.config) {
			return;
		}
		var url = readUrlState();
		if (url.ref) {
			state.result = {
				reference: url.ref,
				message:
					i18n.pending ||
					'Your transfer request is Pending Confirmation. Prime Fleet will verify vehicle availability and contact you shortly.',
			};
			paint();
			return;
		}
		state.result = null;
		var idx = stepIndexFromKey(url.stepKey);
		if (idx < 0) {
			idx = 0;
		}
		if (idx > state.maxStep) {
			idx = state.maxStep;
		}
		goToStep(idx, { skipUrl: true });
		paint({ steps: true, main: true, rail: true });
	});

	Promise.all([api('/config'), issueToken()])
		.then(function (results) {
			state.config = results[0];
			state._configFetchedAt = Date.now();
			if (state.config.currency) {
				cfg.currency = state.config.currency;
			}
			if (state.config.theme) {
				applyFormTheme(state.config.theme);
			}
			if (state.serviceId && !selectedService()) {
				state.serviceId = 0;
				state.step = 0;
				state.maxStep = 0;
			}
			if (typeof state.maxStep !== 'number' || state.maxStep < state.step) {
				state.maxStep = state.step;
			}
			sanitizeZonesForService();
			ensureServiceGroup();
			sanitizeScheduleSelection();
			applyUrlState();
			syncUrl({ push: false });
			paint({ steps: true, main: true, rail: true });
			if (state.serviceId && state.originZoneId && state.destZoneId) {
				scheduleQuote();
			}
		})
		.catch(function () {
			root.innerHTML =
				'<p class="prime-fleet__error" role="alert">' +
				escapeHtml(i18n.error || 'Could not load booking configuration.') +
				'</p>';
		});
})();
