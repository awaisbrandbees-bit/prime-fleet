(function () {
	'use strict';

	function initReveal(root) {
		var nodes = root.querySelectorAll('[data-pf-reveal]');
		if (!nodes.length) {
			return;
		}
		if (!('IntersectionObserver' in window)) {
			nodes.forEach(function (n) {
				n.classList.add('is-in');
			});
			return;
		}
		var io = new IntersectionObserver(
			function (entries) {
				entries.forEach(function (entry) {
					if (entry.isIntersecting) {
						entry.target.classList.add('is-in');
						io.unobserve(entry.target);
					}
				});
			},
			{ rootMargin: '0px 0px -8% 0px', threshold: 0.12 }
		);
		nodes.forEach(function (n) {
			io.observe(n);
		});
	}

	function boot() {
		document.querySelectorAll('[data-pf-home]').forEach(initReveal);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
