/**
 * FrontConsent Settings Page
 *
 * @package FrontConsent
 * @version 1.0.0
 */

(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var cookieCheckbox = document.getElementById('enable_cookie_notice');
		var cookieWrapper = document.getElementById('cookie-notice-fields-wrapper');

		if (cookieCheckbox && cookieWrapper) {
			cookieCheckbox.addEventListener('change', function () {
				cookieWrapper.style.display = cookieCheckbox.checked ? 'block' : 'none';
			});
		}

		var layoutSelect = document.getElementById('cookie_notice_layout');
		var positionSelect = document.getElementById('cookie_notice_position');
		var radiusSelect = document.getElementById('cookie_notice_radius');
		var positionWrapper = document.getElementById('cookie-notice-position-wrapper');
		var preview = document.getElementById('frcn-cookie-notice-preview');

		if (layoutSelect && positionWrapper) {
			layoutSelect.addEventListener('change', function () {
				positionWrapper.style.display = layoutSelect.value === 'box' ? 'block' : 'none';
			});
		}

		function updatePreviewLayout() {
			if (!preview) {
				return;
			}

			var layout = layoutSelect ? layoutSelect.value : 'bar';
			var position = positionSelect ? positionSelect.value : 'bottom-right';

			preview.className = 'frcn-cookie-notice frcn-cookie-notice-preview frcn-cookie-notice--' + layout;

			if (layout === 'box') {
				preview.className += ' frcn-cookie-notice--' + (position === 'bottom-left' ? 'left' : 'right');
			}

			if (radiusSelect) {
				var radii = { none: '0', small: '12px', large: '24px' };
				preview.style.setProperty('--frcn-cookie-radius', radii[radiusSelect.value] || radii.small);
			}
		}

		if (layoutSelect) {
			layoutSelect.addEventListener('change', updatePreviewLayout);
		}

		if (positionSelect) {
			positionSelect.addEventListener('change', updatePreviewLayout);
		}

		if (radiusSelect) {
			radiusSelect.addEventListener('change', updatePreviewLayout);
		}

		initTabs();
	});

	/**
	 * Tab bar / tab panel switching for the settings page shell. A companion
	 * PRO plugin's tab button and panel are just more [data-tab-target] /
	 * [data-tab-panel] elements — no special-casing needed here.
	 */
	function initTabs() {
		var tabButtons = document.querySelectorAll('[data-tab-target]');
		var tabPanels = document.querySelectorAll('[data-tab-panel]');

		if (!tabButtons.length || !tabPanels.length) {
			return;
		}

		function activateTab(tabId) {
			var found = false;

			tabPanels.forEach(function (panel) {
				var match = panel.getAttribute('data-tab-panel') === tabId;
				panel.hidden = !match;
				found = found || match;
			});

			if (!found) {
				return;
			}

			tabButtons.forEach(function (btn) {
				var active = btn.getAttribute('data-tab-target') === tabId;
				btn.classList.toggle('is-active', active);
				btn.setAttribute('aria-selected', active ? 'true' : 'false');
			});
		}

		tabButtons.forEach(function (btn) {
			btn.addEventListener('click', function () {
				activateTab(btn.getAttribute('data-tab-target'));
			});
		});

		var initialTab = tabButtons[0].getAttribute('data-tab-target');
		var hash = window.location.hash.replace('#', '');

		if (hash && document.querySelector('[data-tab-panel="' + hash + '"]')) {
			initialTab = hash;
		}

		activateTab(initialTab);

		// Keep the active tab across a save: WordPress redirects back to the
		// referring URL after options.php processes the form.
		var form = document.getElementById('frcn-settings-form');
		var referer = form ? form.querySelector('input[name="_wp_http_referer"]') : null;

		if (form && referer) {
			form.addEventListener('submit', function () {
				var active = document.querySelector('.frcn-tab-btn.is-active');
				var tabId = active ? active.getAttribute('data-tab-target') : initialTab;
				var url = referer.value.split('#')[0];
				referer.value = url + '#' + tabId;
			});
		}
	}
})();
