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
		var tabButtons = Array.prototype.slice.call(document.querySelectorAll('[data-tab-target]'));
		var tabPanels = Array.prototype.slice.call(document.querySelectorAll('[data-tab-panel]'));
		var mainForm = document.getElementById('frcn-settings-form');
		var submitWrapper = document.getElementById('frcn-settings-form-submit');

		if (!tabButtons.length || !tabPanels.length) {
			return;
		}

		function activateTab(tabId) {
			var found = false;
			var activePanel = null;

			tabPanels.forEach(function (panel) {
				var match = panel.getAttribute('data-tab-panel') === tabId;
				panel.hidden = !match;
				found = found || match;
				if (match) {
					activePanel = panel;
				}
			});

			if (!found) {
				return;
			}

			tabButtons.forEach(function (btn) {
				var active = btn.getAttribute('data-tab-target') === tabId;
				btn.classList.toggle('is-active', active);
				btn.setAttribute('aria-selected', active ? 'true' : 'false');
				// Roving tabindex: only the active tab (or the first tab,
				// before any selection) is reachable via Tab; Arrow/Home/End
				// move focus between the others without leaving the tablist.
				btn.setAttribute('tabindex', active ? '0' : '-1');
			});

			// The main form's submit button only makes sense while an
			// in-form tab (a panel that's actually a descendant of that
			// form) is active — a companion tab with its own <form> (e.g.
			// License) or the built-in upsell tab must not show it.
			if (submitWrapper && mainForm) {
				submitWrapper.hidden = !(activePanel && mainForm.contains(activePanel));
			}
		}

		function focusTab(index) {
			var target = tabButtons[index];
			if (target) {
				target.focus();
				activateTab(target.getAttribute('data-tab-target'));
			}
		}

		tabButtons.forEach(function (btn, index) {
			btn.addEventListener('click', function () {
				activateTab(btn.getAttribute('data-tab-target'));
			});

			// Arrow/Home/End keyboard support for the ARIA tablist, per the
			// standard tabs interaction pattern: Left/Right (wrapping) move
			// and activate; Home/End jump to the first/last tab.
			btn.addEventListener('keydown', function (event) {
				var lastIndex = tabButtons.length - 1;

				if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
					event.preventDefault();
					focusTab(index === lastIndex ? 0 : index + 1);
				} else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
					event.preventDefault();
					focusTab(index === 0 ? lastIndex : index - 1);
				} else if (event.key === 'Home') {
					event.preventDefault();
					focusTab(0);
				} else if (event.key === 'End') {
					event.preventDefault();
					focusTab(lastIndex);
				}
			});
		});

		var initialTab = tabButtons[0].getAttribute('data-tab-target');
		var hash = window.location.hash.replace('#', '');

		if (hash && document.querySelector('[data-tab-panel="' + hash + '"]')) {
			initialTab = hash;
		}

		activateTab(initialTab);

		// Keep the active tab across a save: WordPress redirects back to the
		// referring URL after options.php processes the form. Applied to
		// every form on the page, not just the main one — a companion tab
		// with its own form (e.g. FrontConsent PRO's License tab) needs the
		// same restoration after its own options.php round trip.
		var forms = document.querySelectorAll('.frcn-settings-wrapper form');

		forms.forEach(function (form) {
			var referer = form.querySelector('input[name="_wp_http_referer"]');

			if (!referer) {
				return;
			}

			form.addEventListener('submit', function () {
				var active = document.querySelector('.frcn-tab-btn.is-active');
				var tabId = active ? active.getAttribute('data-tab-target') : initialTab;
				var url = referer.value.split('#')[0];
				referer.value = url + '#' + tabId;
			});
		});

		// Plain links to a tab from outside the tab bar itself (e.g. a
		// "license not active" notice pointing at the License tab) — kept
		// separate from tabButtons so they don't join its roving-tabindex
		// keyboard cycle.
		document.querySelectorAll('[data-tab-link]').forEach(function (link) {
			link.addEventListener('click', function (event) {
				event.preventDefault();
				activateTab(link.getAttribute('data-tab-link'));
			});
		});
	}
})();
