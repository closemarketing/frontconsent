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
	});
})();
