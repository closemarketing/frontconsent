/**
 * FrontConsent Script/Iframe Blocker
 *
 * Revives `<script data-frcn-category="..." data-frcn-src="...">` and
 * `<iframe data-frcn-category="..." data-frcn-src="...">` placeholders
 * (see includes/Frontend/ScriptBlocker.php) once their consent category is
 * accepted — turning a held-back placeholder tag into a real, executing
 * script or a real, loading iframe.
 *
 * @package FrontConsent
 * @version 1.0.0
 */

(function () {
	'use strict';

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	// Also revive on the same 'frcnCookieConsent' custom event
	// frontconsent-cookie-notice.js dispatches on document right after a
	// visitor makes a decision — this is the single mechanism both files
	// hook into, rather than this file inventing a second, parallel one.
	document.addEventListener('frcnCookieConsent', function (event) {
		var decision = event && event.detail ? event.detail.consent : '';
		reviveAcceptedPlaceholders(decision);
	});

	function readCookie(name) {
		var match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));

		if (!match) {
			return '';
		}

		try {
			return decodeURIComponent(match[1]);
		} catch (e) {
			return '';
		}
	}

	/**
	 * Whether a given consent category is currently allowed. There is no
	 * granular per-category consent in the free plugin — only a binary
	 * accepted/rejected decision — so an accepted decision allows every
	 * category here. window.frcnCookieNoticeIsCategoryAllowed is an
	 * extension point an add-on with real per-category consent (e.g.
	 * FrontConsent PRO) can define instead, the same convention
	 * frontconsent-cookie-notice.js already uses for
	 * frcnCookieNoticeConsentModeState/frcnCookieNoticeIsConsentStale.
	 *
	 * @param {string} category
	 * @returns {boolean}
	 */
	function isCategoryAllowed(category) {
		if (typeof window.frcnCookieNoticeIsCategoryAllowed === 'function') {
			return !!window.frcnCookieNoticeIsCategoryAllowed(category);
		}

		var cookieName = typeof frcnCookieNotice !== 'undefined' ? frcnCookieNotice.cookieName : 'frcn_cookie_consent';

		return readCookie(cookieName) === 'accepted';
	}

	/**
	 * Turn a placeholder <script> into a real one. Cloning the placeholder
	 * and changing its type would NOT make the browser execute it — per the
	 * HTML spec, a script element's "already started" flag is set the
	 * moment it's inserted/parsed, so only a brand-new <script> element
	 * (never previously inserted) is ever executed.
	 *
	 * @param {HTMLScriptElement} placeholder
	 */
	function reviveScript(placeholder) {
		var src = placeholder.getAttribute('data-frcn-src');

		if (!src) {
			return;
		}

		var script = document.createElement('script');

		Array.prototype.forEach.call(placeholder.attributes, function (attr) {
			if (attr.name === 'type' || attr.name === 'data-frcn-category' || attr.name === 'data-frcn-src') {
				return;
			}

			script.setAttribute(attr.name, attr.value);
		});

		script.src = src;

		if (placeholder.parentNode) {
			placeholder.parentNode.insertBefore(script, placeholder);
			placeholder.parentNode.removeChild(placeholder);
		}
	}

	/**
	 * Turn a placeholder <iframe> live by restoring its real src.
	 *
	 * @param {HTMLIFrameElement} placeholder
	 */
	function reviveIframe(placeholder) {
		var src = placeholder.getAttribute('data-frcn-src');

		if (!src) {
			return;
		}

		placeholder.src = src;
		placeholder.removeAttribute('data-frcn-src');
		placeholder.removeAttribute('data-frcn-category');
	}

	function reviveAcceptedPlaceholders() {
		var placeholders = document.querySelectorAll('script[data-frcn-category], iframe[data-frcn-category]');

		Array.prototype.forEach.call(placeholders, function (placeholder) {
			var category = placeholder.getAttribute('data-frcn-category');

			if (!isCategoryAllowed(category)) {
				return;
			}

			if (placeholder.tagName === 'SCRIPT') {
				reviveScript(placeholder);
			} else if (placeholder.tagName === 'IFRAME') {
				reviveIframe(placeholder);
			}
		});
	}

	function init() {
		reviveAcceptedPlaceholders();
	}
})();
