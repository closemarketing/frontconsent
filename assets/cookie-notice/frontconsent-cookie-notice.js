/**
 * FrontConsent Cookie Notice
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

	function readCookie(name) {
		var match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));

		if (!match) {
			return '';
		}

		try {
			return decodeURIComponent(match[1]);
		} catch (e) {
			// Malformed percent-encoding: treat it the same as no cookie at all.
			return '';
		}
	}

	function defineInjectHelper() {
		window.frcnCookieNoticeInject = window.frcnCookieNoticeInject || function (gtmId, ga4Id, trackingIntegrations, allowedCategories) {
			var allowsCategory = function (category) {
				return !allowedCategories || !!allowedCategories[category];
			};

			if (gtmId && allowsCategory('analytics')) {
				window.dataLayer = window.dataLayer || [];
				window.dataLayer.push({ 'gtm.start': new Date().getTime(), event: 'gtm.js' });

				var gtmScript = document.createElement('script');
				gtmScript.async = true;
				gtmScript.src = 'https://www.googletagmanager.com/gtm.js?id=' + encodeURIComponent(gtmId);
				document.head.appendChild(gtmScript);
			}

			if (ga4Id && allowsCategory('analytics')) {
				var ga4Script = document.createElement('script');
				ga4Script.async = true;
				ga4Script.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(ga4Id);
				document.head.appendChild(ga4Script);

				window.dataLayer = window.dataLayer || [];
				window.gtag = window.gtag || function () {
					window.dataLayer.push(arguments);
				};
				window.gtag('js', new Date());
				window.gtag('config', ga4Id);
			}

			if (!Array.isArray(trackingIntegrations)) {
				trackingIntegrations = [];
			}

			trackingIntegrations.forEach(function (integration) {
				var trackingType = integration && integration.type ? integration.type : '';
				var trackingId = integration && integration.id ? integration.id : '';
				var trackingCategory = integration && integration.category ? integration.category : 'marketing';

				if (!trackingId || !allowsCategory(trackingCategory)) {
					return;
				}

			if (trackingType === 'clientify_analytics_plus') {
				var clientifyPixel = document.createElement('script');
				clientifyPixel.defer = true;
				clientifyPixel.src = 'https://analyticsplusdev.clientify.net/analytics_plus/pixel/' + encodeURIComponent(trackingId);
				document.head.appendChild(clientifyPixel);
			} else if (trackingType === 'clientify_analytics_classic') {
				(function (d, w, u, o) {
					w[o] = w[o] || function () {
						(w[o].q = w[o].q || []).push(arguments);
					};
					var a = d.createElement('script'),
						m = d.getElementsByTagName('script')[0];
					a.async = 1; a.src = u;
					m.parentNode.insertBefore(a, m);
				})(document, window, 'https://analytics.clientify.net/tracker.js', 'ana');
				window.ana('setTrackerUrl', 'https://analytics.clientify.net');
				window.ana('setTrackingCode', trackingId);
				window.ana('trackPageview');
			} else if (trackingType === 'brevo') {
				var brevoScript = document.createElement('script');
				brevoScript.async = true;
				brevoScript.src = 'https://cdn.brevo.com/js/sdk-loader.js';
				document.head.appendChild(brevoScript);

				window.Brevo = window.Brevo || [];
				window.Brevo.push(['init', { client_key: trackingId }]);
			} else if (trackingType === 'openai_chatgpt_ads') {
				if (!window.oaiq) {
					window.oaiq = function () {
						window.oaiq.q.push(arguments);
					};
					window.oaiq.q = [];

					var openaiScript = document.createElement('script');
					openaiScript.async = true;
					openaiScript.src = 'https://bzrcdn.openai.com/sdk/oaiq.min.js';
					document.head.appendChild(openaiScript);
				}

				window.oaiq('init', { pixelId: trackingId, debug: true });
			} else if (typeof window.frcnCookieNoticeInjectIntegration === 'function') {
				window.frcnCookieNoticeInjectIntegration(integration);
			} else {
				window.frcnCookieNoticePendingIntegrations = window.frcnCookieNoticePendingIntegrations || [];
				window.frcnCookieNoticePendingIntegrations.push(integration);
			}
			});
		};
	}

	function fetchAndInjectScripts() {
		var formData = new FormData();
		formData.append('action', 'frcn_get_cookie_notice_config');

		fetch(frcnCookieNotice.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (response) {
				if (response && response.success && response.data && window.frcnCookieNoticeInject) {
					window.frcnCookieNoticeInject(response.data.gtmId, response.data.ga4Id, response.data.trackingIntegrations, response.data.allowedCategories);
				}
			})
			.catch(function () {
				// Network hiccup: consent is already stored locally; nothing else to do here.
			});
	}

	/**
	 * For an already-decided visitor, request the tracking scripts (accepted)
	 * or simply do nothing further (rejected). Normally an inline bootstrap
	 * script printed on wp_head already does this as early as possible, well
	 * before this file even loads — this is the fallback for sites whose
	 * Content Security Policy blocks that unnonced inline script, so tracking
	 * still starts there too, just later.
	 *
	 * Deliberately separate from hiding the banner (see hideBannerIfDecided()):
	 * that inline copy runs on wp_head, before '#frcn-cookie-notice' exists in
	 * the DOM at all, so it can never do the hiding itself — only this file,
	 * running once the DOM is ready, can.
	 */
	/**
	 * Set Google Consent Mode's default state. Normally the inline script
	 * printed on wp_head (render_consent_mode_default()) already does this,
	 * as early as possible, before this registered file even loads. This is
	 * the fallback for a site whose Content Security Policy blocks that
	 * unnonced inline script: without it, a Consent Mode-aware tag loaded
	 * independently (e.g. Google Site Kit) would run with Google's own
	 * default (granted) instead of denied, since nothing would ever have
	 * told it otherwise.
	 */
	function setConsentModeDefault() {
		window.dataLayer = window.dataLayer || [];
		window.gtag = window.gtag || function () {
			window.dataLayer.push(arguments);
		};

		var isStale = typeof window.frcnCookieNoticeIsConsentStale === 'function' && window.frcnCookieNoticeIsConsentStale();
		var granted = !isStale && readCookie(frcnCookieNotice.cookieName) === 'accepted' ? 'granted' : 'denied';
		var state = {
			ad_storage: granted,
			ad_user_data: granted,
			ad_personalization: granted,
			analytics_storage: granted
		};

		if (typeof window.frcnCookieNoticeConsentModeState === 'function') {
			var overrideState = window.frcnCookieNoticeConsentModeState();

			if (overrideState) {
				state = overrideState;
			}
		}

		window.gtag('consent', 'default', state);
	}

	function requestTrackingIfNeeded() {
		if (window.frcnCookieNoticeBootstrapped) {
			return;
		}

		setConsentModeDefault();
		defineInjectHelper();

		var isStale = typeof window.frcnCookieNoticeIsConsentStale === 'function' && window.frcnCookieNoticeIsConsentStale();

		if (readCookie(frcnCookieNotice.cookieName) === 'accepted' && !isStale) {
			fetchAndInjectScripts();
		}

		window.frcnCookieNoticeBootstrapped = true;
	}

	function hideBannerIfDecided(banner) {
		var consent = readCookie(frcnCookieNotice.cookieName);

		// An add-on tracking per-category consent (analytics vs. marketing) can
		// define this to say "the categories cookie is stale — e.g. the site
		// admin just added a new integration — so re-prompt even though the
		// legacy accepted/rejected cookie here still looks decided."
		if (typeof window.frcnCookieNoticeIsConsentStale === 'function' && window.frcnCookieNoticeIsConsentStale()) {
			return false;
		}

		if (banner && (consent === 'accepted' || consent === 'rejected')) {
			banner.style.display = 'none';
			return true;
		}

		return false;
	}

	/**
	 * Reveal the persistent "Cookie preferences" trigger once a decision
	 * cookie exists, and wire it to let the visitor withdraw an acceptance or
	 * replace a rejection at any time — the only way to do so short of
	 * deleting the cookie by hand. Reloading the page after clearing the
	 * cookie is deliberately simple: it lets the server render a fresh,
	 * undecided banner exactly the way a first-time visitor gets one,
	 * instead of duplicating that logic client-side.
	 */
	function setUpReopenTrigger() {
		var reopenBtn = document.getElementById('frcn-cookie-reopen');

		if (!reopenBtn) {
			return;
		}

		var consent = readCookie(frcnCookieNotice.cookieName);

		if (consent !== 'accepted' && consent !== 'rejected') {
			return;
		}

		reopenBtn.hidden = false;
		reopenBtn.addEventListener('click', function () {
			document.cookie = frcnCookieNotice.cookieName + '=; path=' + frcnCookieNotice.cookiePath + '; max-age=0; SameSite=Lax';

			if (frcnCookieNotice.isPolicyPage && frcnCookieNotice.homeUrl) {
				window.location.href = frcnCookieNotice.homeUrl;
			} else {
				window.location.reload();
			}
		});
	}

	function init() {
		if (typeof frcnCookieNotice === 'undefined') {
			return;
		}

		requestTrackingIfNeeded();
		setUpReopenTrigger();

		var banner = document.getElementById('frcn-cookie-notice');

		if (!banner) {
			return;
		}

		if (hideBannerIfDecided(banner)) {
			// Already decided: nothing left to wire up.
			return;
		}

		var acceptBtn = banner.querySelector('[data-frcn-cookie-action="accept"]');
		var rejectBtn = banner.querySelector('[data-frcn-cookie-action="reject"]');
		var isPopup = banner.classList.contains('frcn-cookie-notice--popup');
		var previouslyFocused = document.activeElement;

		revealBanner();

		if (acceptBtn) {
			acceptBtn.addEventListener('click', function () {
				handleDecision('accepted');
			});
		}

		if (rejectBtn) {
			rejectBtn.addEventListener('click', function () {
				handleDecision('rejected');
			});
		}

		var customizeBtn = banner.querySelector('[data-frcn-cookie-action="customize"]');

		if (customizeBtn) {
			// Deliberately not routed through handleDecision(): clicking it isn't a
			// decision by itself, just a request to see more detail. An add-on
			// listens for this to open its own categories dialog.
			customizeBtn.addEventListener('click', function () {
				var event;

				try {
					event = new CustomEvent('frcnCookieNoticeCustomize');
				} catch (e) {
					event = document.createEvent('CustomEvent');
					event.initCustomEvent('frcnCookieNoticeCustomize', true, true, null);
				}

				document.dispatchEvent(event);
			});
		}

		/**
		 * Reveal the banner: removes the '--init' class printed by PHP so the
		 * CSS transition animates it in (slide up for the bar, slide in from
		 * its anchored edge for the box, scale/fade in for the popup). The
		 * popup gets a short extra delay first, per its layout's own request,
		 * so it doesn't feel jarring the instant the page loads.
		 */
		function revealBanner() {
			var delay = isPopup ? 150 : 20;

			window.setTimeout(function () {
				banner.classList.remove('frcn-cookie-notice--init');

				if (isPopup) {
					document.body.classList.add('frcn-cookie-notice-lock-scroll');

					if (acceptBtn) {
						acceptBtn.focus({ preventScroll: true });
					}

					document.addEventListener('keydown', trapFocus);
				}
			}, delay);
		}

		function trapFocus(event) {
			if (event.key !== 'Tab') {
				return;
			}

			var focusable = Array.prototype.slice.call(
				banner.querySelectorAll('a[href], button')
			);

			if (!focusable.length) {
				return;
			}

			var first = focusable[0];
			var last = focusable[focusable.length - 1];

			if (event.shiftKey && document.activeElement === first) {
				event.preventDefault();
				last.focus();
			} else if (!event.shiftKey && document.activeElement === last) {
				event.preventDefault();
				first.focus();
			}
		}

		var decided = false;

		function handleDecision(decision) {
			if (decided) {
				return;
			}

			decided = true;

			setConsentCookie(decision);
			updateConsentMode(decision);
			hideBanner();
			dispatchConsentEvent(decision);
			logDecision(decision);

			if (decision === 'accepted') {
				fetchAndInjectScripts();
			}
		}

		function updateConsentMode(decision) {
			var granted = decision === 'accepted' ? 'granted' : 'denied';

			window.dataLayer = window.dataLayer || [];
			window.gtag = window.gtag || function () {
				window.dataLayer.push(arguments);
			};
			window.gtag('consent', 'update', {
				ad_storage: granted,
				ad_user_data: granted,
				ad_personalization: granted,
				analytics_storage: granted
			});
		}

		function setConsentCookie(decision) {
			var maxAge = parseInt(frcnCookieNotice.expirationDays, 10) * 24 * 60 * 60;
			var secure = window.location.protocol === 'https:' ? '; Secure' : '';

			document.cookie = frcnCookieNotice.cookieName + '=' + decision +
				'; path=' + frcnCookieNotice.cookiePath + '; max-age=' + maxAge + '; SameSite=Lax' + secure;
		}

		function hideBanner() {
			banner.classList.add('frcn-cookie-notice--hidden');
			document.body.classList.remove('frcn-cookie-notice-lock-scroll');
			document.removeEventListener('keydown', trapFocus);

			if (isPopup && previouslyFocused && typeof previouslyFocused.focus === 'function') {
				previouslyFocused.focus({ preventScroll: true });
			}

			window.setTimeout(function () {
				if (banner.parentNode) {
					banner.parentNode.removeChild(banner);
				}
			}, 300);
		}

		function dispatchConsentEvent(decision) {
			var event;

			try {
				event = new CustomEvent('frcnCookieConsent', { detail: { consent: decision } });
			} catch (e) {
				event = document.createEvent('CustomEvent');
				event.initCustomEvent('frcnCookieConsent', true, true, { consent: decision });
			}

			document.dispatchEvent(event);
		}

		function logDecision(decision) {
			var nonceForm = new FormData();
			nonceForm.append('action', 'frcn_get_cookie_notice_log_nonce');

			fetch(frcnCookieNotice.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: nonceForm
			})
				.then(function (response) {
					return response.json();
				})
				.then(function (response) {
					if (!response || !response.success || !response.data) {
						return;
					}

					var formData = new FormData();
					formData.append('action', 'frcn_log_cookie_consent');
					formData.append('nonce', response.data.nonce);
					formData.append('decision', decision);

					return fetch(frcnCookieNotice.ajaxUrl, {
						method: 'POST',
						credentials: 'same-origin',
						body: formData
					});
				})
				.catch(function () {
					// Best-effort: the aggregate stat is not critical to the consent flow.
				});
		}
	}
})();
