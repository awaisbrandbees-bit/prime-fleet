/**
 * Prime Fleet admin — nav helpers (no GSAP / no View Transitions).
 * Instant active state only — fancy motion was delaying AJAX panel loads.
 */
(function (window, document) {
	'use strict';

	function activePrimaryTab(root) {
		root = root || document;
		return root.querySelector('.prime-fleet-app__nav-scroll .prime-fleet-tab.is-active[data-pf-panel]');
	}

	function snapToActive(app) {
		/* No sliding pill — CSS :is-active is enough. */
		String(app);
		String(activePrimaryTab(app));
	}

	function revealContent(update) {
		return Promise.resolve(update());
	}

	window.primeFleetNavMotion = {
		init: function () {},
		movePillTo: function () {},
		snapTo: function () {},
		snapToActive: snapToActive,
		revealContent: revealContent,
		isPrimaryTab: function (el) {
			return !!(
				el &&
				el.classList &&
				el.classList.contains('prime-fleet-tab') &&
				el.hasAttribute('data-pf-panel') &&
				!el.classList.contains('prime-fleet-more__toggle') &&
				el.closest('.prime-fleet-app__nav-scroll')
			);
		},
	};
})(window, document);
