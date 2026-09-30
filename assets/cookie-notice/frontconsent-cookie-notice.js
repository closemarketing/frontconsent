/**
 * FrontConsent Cookie Notice
 *
 * @package FrontConsent
 * @version 1.0.0
 */

(function () {
	'use strict';

	// Run immediately, at parse time — not gated by DOMContentLoaded. This is
	// the CSP fallback for the inline wp_head script (render_consent_mode_default()):
	// on a site whose Content Security Policy blocks that unnonced inline
	// script, this is what actually establishes Consent Mode's default state
	// before any independently loaded, Consent Mode-aware tag (e.g. Google
	// Site Kit) reads it. That only works if this file is enqueued to print
	// in <head> (in_footer: false — see enqueue_assets()) and this call runs
	// before deferring to DOMContentLoaded; waiting for the DOM to be ready
	// would be too late; a tag placed earlier in <head> could already have
	// initialized with Google's own default (granted) by then.
	if (typeof frcnCookieNotice !== 'undefined') {
		setConsentModeDefault();
	}

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

		var granted = readCookie(frcnCookieNotice.cookieName) === 'accepted' ? 'granted' : 'denied';
		var state = {
			ad_storage: granted,
			ad_user_data: granted,
			ad_personalization: granted,
			analytics_storage: granted
		};

		// An add-on tracking per-category consent (analytics vs. marketing)
		// can define this — reading its own cookie the same way — to send the
		// granular signals Consent Mode actually expects instead of this
		// binary default.
		if (typeof window.frcnCookieNoticeConsentModeState === 'function') {
			var overrideState = window.frcnCookieNoticeConsentModeState();

			if (overrideState) {
				state = overrideState;
			}
		}

		// Applied last, after any override: an add-on reporting its own
		// per-category consent as stale (e.g. the site admin just added a
		// new integration) means a fresh decision is needed, so deny by
		// default regardless of what the override state said — otherwise a
		// stale "granted" from before the new integration was added would
		// leak through while the visitor is being re-prompted.
		if (typeof window.frcnCookieNoticeIsConsentStale === 'function' && window.frcnCookieNoticeIsConsentStale()) {
			state = {
				ad_storage: 'denied',
				ad_user_data: 'denied',
				ad_personalization: 'denied',
				analytics_storage: 'denied'
			};
		}

		window.gtag('consent', 'default', state);
	}

	function requestTrackingIfNeeded() {
		if (window.frcnCookieNoticeBootstrapped) {
			return;
		}

		defineInjectHelper();

		var isStale = typeof window.frcnCookieNoticeIsConsentStale === 'function' && window.frcnCookieNoticeIsConsentStale();

		if (readCookie(frcnCookieNotice.cookieName) === 'accepted' && !isStale) {
			fetchAndInjectScripts();
		}

		window.frcnCookieNoticeBootstrapped = true;
	}

	/**
	 * Reveal the persistent "Cookie preferences" trigger once a decision
	 * cookie exists, and wire it to open the same preferences panel the
	 * "Customize cookie settings" button opens — the only way to reach it
	 * again once the original banner is gone. Bound once per page load
	 * (reopenTriggerWired), same as before; only reveal is repeated when this
	 * is called again right after an in-page decision.
	 */
	var reopenTriggerWired = false;

	function setUpReopenTrigger(openPreferences) {
		var reopenBtn = document.getElementById('frcn-cookie-reopen');

		if (!reopenBtn) {
			return;
		}

		var consent = readCookie(frcnCookieNotice.cookieName);

		if (consent !== 'accepted' && consent !== 'rejected') {
			return;
		}

		reopenBtn.hidden = false;

		if (reopenTriggerWired) {
			// Already bound on the initial DOMContentLoaded pass — this second
			// call (from handleDecision(), right after a same-page decision)
			// only needed to reveal the button, not rebind its click handler.
			return;
		}

		reopenTriggerWired = true;
		reopenBtn.addEventListener('click', function () {
			if (typeof openPreferences === 'function') {
				openPreferences(reopenBtn);
			}
		});
	}

	function init() {
		if (typeof frcnCookieNotice === 'undefined') {
			return;
		}

		// Falls back to plain English if the localized strings object is
		// missing for any reason (e.g. an add-on/theme prints its own copy of
		// this file without going through enqueue_assets()) — the live
		// region should still say *something* rather than stay silent.
		var i18n = typeof frcnCookieNoticeA11y !== 'undefined' ? frcnCookieNoticeA11y : {
			bannerOpened: 'Cookie consent banner opened.',
			accepted: 'Cookies accepted.',
			rejected: 'Cookies rejected.'
		};

		var announcer = document.getElementById('frcn-cookie-notice-announcer');

		/**
		 * Push a message into the always-present live region (see
		 * CookieNotice::render_status_announcer()) so a screen reader user
		 * hears the banner appearing and any later consent-decision state
		 * change — a purely visual reveal/hide, or the accepted/rejected
		 * cookie being set, otherwise conveys nothing to them.
		 */
		function announce(message) {
			if (announcer) {
				announcer.textContent = message;
			}
		}

		// Hoisted out of any "if (banner) { ... }" gate: the preferences panel
		// (and the reopen trigger that opens it) must keep working even when
		// the main banner markup isn't rendered at all — e.g. on the
		// configured cookie policy page — so the decision-recording flow
		// below can't depend on the banner existing.
		var decided = false;

		function setConsentCookie(decision) {
			var maxAge = parseInt(frcnCookieNotice.expirationDays, 10) * 24 * 60 * 60;
			var secure = window.location.protocol === 'https:' ? '; Secure' : '';

			document.cookie = frcnCookieNotice.cookieName + '=' + decision +
				'; path=' + frcnCookieNotice.cookiePath + '; max-age=' + maxAge + '; SameSite=Lax' + secure;
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

		/**
		 * Records a decision through the exact same AJAX endpoint regardless
		 * of which control triggered it (Accept/Reject in the banner, or
		 * Accept all/Reject all/Save changes in the preferences panel) — the
		 * optional `categories` map is only ever additive: log_consent_callback()
		 * runs it through the frcn_cookie_consent_categories filter and never
		 * requires it for the binary accepted/rejected cookie to keep working.
		 */
		function logDecision(decision, categories) {
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

					if (categories) {
						formData.append('categories', JSON.stringify(categories));
					}

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

		/**
		 * The single decision-recording code path: sets the consent cookie,
		 * updates Google Consent Mode, announces the outcome, hides the
		 * banner and preferences panel (whichever are open), reveals the
		 * reopen trigger, and logs the decision — used identically by the
		 * banner's own Accept/Reject buttons and by the preferences panel's
		 * Accept all/Reject all/Save changes actions, per the "no parallel
		 * consent logic" requirement.
		 *
		 * @param {string} decision   'accepted' or 'rejected'.
		 * @param {Object} categories Optional per-category map (PRO extension point).
		 */
		function handleDecision(decision, categories) {
			if (decided) {
				return;
			}

			decided = true;

			setConsentCookie(decision);
			updateConsentMode(decision);
			announce(decision === 'accepted' ? i18n.accepted : i18n.rejected);
			hideBannerIfPresent();
			closePreferencesPanel();
			setUpReopenTrigger(openPreferencesPanel);
			dispatchConsentEvent(decision);
			logDecision(decision, categories);

			if (decision === 'accepted') {
				fetchAndInjectScripts();
			}
		}

		var banner = document.getElementById('frcn-cookie-notice');
		var isPopup = !!banner && banner.classList.contains('frcn-cookie-notice--popup');
		var bannerPreviouslyFocused = document.activeElement;
		var bannerModalKeydownBound = false;

		function getFocusableElements(container) {
			return Array.prototype.slice.call(
				container.querySelectorAll('a[href], button, input, [tabindex]:not([tabindex="-1"])')
			);
		}

		function trapFocus(event) {
			var focusable = getFocusableElements(banner);

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

		/**
		 * Keydown handler bound only while the popup (true modal) layout is
		 * open: Tab/Shift+Tab loop within the dialog's own focusable elements
		 * (trapFocus()) and Escape is treated as an explicit reject — a valid,
		 * equivalent consent decision, not a silent dismissal — so a keyboard
		 * user always has a way out that doesn't just abandon the dialog
		 * without a decision being recorded (see handleDecision()).
		 *
		 * Deliberately a no-op while the preferences panel is open on top of
		 * it — that dialog owns Tab/Escape while it's the visible one; see
		 * isPreferencesPanelOpen().
		 */
		function handleModalKeydown(event) {
			if (isPreferencesPanelOpen()) {
				return;
			}

			if (event.key === 'Tab') {
				trapFocus(event);
			} else if (event.key === 'Escape' || event.key === 'Esc') {
				event.preventDefault();
				handleDecision('rejected');
			}
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

				// Non-modal layouts (bar/box) deliberately never move focus —
				// per WAI-ARIA Authoring Practices, an unsolicited focus grab
				// on every single page load is more disruptive to a screen
				// reader/keyboard user than helpful; the live-region
				// announcement below is what tells them it appeared instead,
				// leaving focus exactly where they left it.
				announce(i18n.bannerOpened);

				if (isPopup) {
					document.body.classList.add('frcn-cookie-notice-lock-scroll');

					var acceptBtnForFocus = banner.querySelector('[data-frcn-cookie-action="accept"]');

					// A true modal (the popup layout blocks the rest of the
					// page) must move focus into itself on open — the first
					// focusable control is the reject button in DOM order,
					// but the accept button is used here to keep the
					// pre-existing default behavior/tests unchanged.
					if (acceptBtnForFocus) {
						acceptBtnForFocus.focus({ preventScroll: true });
					}

					document.addEventListener('keydown', handleModalKeydown);
					bannerModalKeydownBound = true;
				}
			}, delay);
		}

		function hideBannerIfPresent() {
			if (!banner) {
				return;
			}

			banner.classList.add('frcn-cookie-notice--hidden');
			document.body.classList.remove('frcn-cookie-notice-lock-scroll');

			if (bannerModalKeydownBound) {
				document.removeEventListener('keydown', handleModalKeydown);
				bannerModalKeydownBound = false;
			}

			if (isPopup && bannerPreviouslyFocused && typeof bannerPreviouslyFocused.focus === 'function') {
				bannerPreviouslyFocused.focus({ preventScroll: true });
			}

			window.setTimeout(function () {
				if (banner.parentNode) {
					banner.parentNode.removeChild(banner);
				}
			}, 300);
		}

		// --- Preferences panel: dialog opened from the "Customize cookie
		// settings" button (in the banner) or the persistent reopen trigger.
		// Always looked up (even when the main banner didn't render, e.g. on
		// the policy page) since CookieNotice::render_preferences_panel()
		// prints it unconditionally, independently of the banner markup.
		var preferencesPanel = document.getElementById('frcn-cookie-preferences');
		var preferencesPanelOpener = null;
		var preferencesPanelKeydownBound = false;

		function isPreferencesPanelOpen() {
			return !!preferencesPanel && !preferencesPanel.hidden;
		}

		function getPreferencesFocusable() {
			return getFocusableElements(preferencesPanel);
		}

		function trapPreferencesFocus(event) {
			var focusable = getPreferencesFocusable();

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

		function handlePreferencesKeydown(event) {
			if (event.key === 'Tab') {
				trapPreferencesFocus(event);
			} else if (event.key === 'Escape' || event.key === 'Esc') {
				event.preventDefault();
				closePreferencesPanel();
			}
		}

		function openPreferencesPanel(trigger) {
			if (!preferencesPanel) {
				return;
			}

			// A fresh session of "changing their mind": lets a visitor who
			// already decided make (and log) a new decision from the
			// reopened panel instead of being silently blocked by the
			// same-page debounce above.
			decided = false;
			preferencesPanelOpener = trigger || document.activeElement;
			preferencesPanel.hidden = false;
			document.body.classList.add('frcn-cookie-notice-lock-scroll');

			var focusable = getPreferencesFocusable();

			if (focusable.length) {
				focusable[0].focus({ preventScroll: true });
			}

			if (!preferencesPanelKeydownBound) {
				document.addEventListener('keydown', handlePreferencesKeydown);
				preferencesPanelKeydownBound = true;
			}

			announce(i18n.bannerOpened);
		}

		function closePreferencesPanel() {
			if (!preferencesPanel || preferencesPanel.hidden) {
				return;
			}

			preferencesPanel.hidden = true;

			if (!isPopup || !banner || banner.classList.contains('frcn-cookie-notice--hidden')) {
				document.body.classList.remove('frcn-cookie-notice-lock-scroll');
			}

			if (preferencesPanelKeydownBound) {
				document.removeEventListener('keydown', handlePreferencesKeydown);
				preferencesPanelKeydownBound = false;
			}

			if (preferencesPanelOpener && typeof preferencesPanelOpener.focus === 'function') {
				preferencesPanelOpener.focus({ preventScroll: true });
			}

			preferencesPanelOpener = null;
		}

		/**
		 * Every non-necessary category toggle a PRO add-on rendered on
		 * frcn_cookie_preferences_categories (see render_preferences_panel())
		 * is expected to carry a `data-frcn-category="<slug>"` attribute on a
		 * checkable control — read back here so "Save changes" can compute
		 * both the categories map and the binary decision it implies. The
		 * Free tier renders none, so this is always just { necessary: true }
		 * until an add-on extends the panel.
		 */
		function collectPreferencesCategories() {
			var categories = { necessary: true };

			if (!preferencesPanel) {
				return categories;
			}

			var toggles = preferencesPanel.querySelectorAll('[data-frcn-category]');

			Array.prototype.forEach.call(toggles, function (toggle) {
				categories[toggle.getAttribute('data-frcn-category')] = !!toggle.checked;
			});

			return categories;
		}

		if (preferencesPanel) {
			var closeBtn = preferencesPanel.querySelector('[data-frcn-cookie-action="close-preferences"]');
			var panelAcceptBtn = preferencesPanel.querySelector('[data-frcn-cookie-action="accept"]');
			var panelRejectBtn = preferencesPanel.querySelector('[data-frcn-cookie-action="reject"]');
			var panelSaveBtn = preferencesPanel.querySelector('[data-frcn-cookie-action="save"]');

			if (closeBtn) {
				closeBtn.addEventListener('click', function () {
					closePreferencesPanel();
				});
			}

			if (panelAcceptBtn) {
				panelAcceptBtn.addEventListener('click', function () {
					var categories = collectPreferencesCategories();

					Object.keys(categories).forEach(function (key) {
						categories[key] = true;
					});

					handleDecision('accepted', categories);
				});
			}

			if (panelRejectBtn) {
				panelRejectBtn.addEventListener('click', function () {
					handleDecision('rejected', { necessary: true });
				});
			}

			if (panelSaveBtn) {
				panelSaveBtn.addEventListener('click', function () {
					var categories = collectPreferencesCategories();
					var hasOptionalConsent = Object.keys(categories).some(function (key) {
						return key !== 'necessary' && categories[key];
					});

					handleDecision(hasOptionalConsent ? 'accepted' : 'rejected', categories);
				});
			}
		}

		requestTrackingIfNeeded();
		setUpReopenTrigger(openPreferencesPanel);

		if (!banner) {
			return;
		}

		if (hideBannerIfDecided()) {
			// Already decided: nothing left to wire up on the banner itself
			// (the preferences panel and reopen trigger stay available).
			return;
		}

		var acceptBtn = banner.querySelector('[data-frcn-cookie-action="accept"]');
		var rejectBtn = banner.querySelector('[data-frcn-cookie-action="reject"]');
		var customizeBtn = banner.querySelector('[data-frcn-cookie-action="customize"]');

		function hideBannerIfDecided() {
			var consent = readCookie(frcnCookieNotice.cookieName);

			// An add-on tracking per-category consent (analytics vs. marketing) can
			// define this to say "the categories cookie is stale — e.g. the site
			// admin just added a new integration — so re-prompt even though the
			// legacy accepted/rejected cookie here still looks decided."
			if (typeof window.frcnCookieNoticeIsConsentStale === 'function' && window.frcnCookieNoticeIsConsentStale()) {
				return false;
			}

			if (consent === 'accepted' || consent === 'rejected') {
				banner.style.display = 'none';
				return true;
			}

			return false;
		}

		revealBanner();

		if (acceptBtn) {
			// preventDefault() is what stops the button's form="..." submit
			// attribute (the no-JS fallback — see render_banner_markup() and
			// log_consent_form_callback()) from actually navigating the page
			// away when JavaScript can run: this handles the decision instead,
			// entirely client-side.
			acceptBtn.addEventListener('click', function (event) {
				event.preventDefault();
				handleDecision('accepted');
			});
		}

		if (rejectBtn) {
			rejectBtn.addEventListener('click', function (event) {
				event.preventDefault();
				handleDecision('rejected');
			});
		}

		if (customizeBtn) {
			// Opens the same preferences panel the reopen trigger uses — not
			// routed through handleDecision(): clicking it isn't a decision by
			// itself, just a request to see more detail. A CustomEvent is
			// still dispatched afterwards so any existing listener bound to it
			// keeps working.
			customizeBtn.addEventListener('click', function () {
				openPreferencesPanel(customizeBtn);

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
	}
})();
