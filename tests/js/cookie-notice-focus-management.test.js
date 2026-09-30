const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const scriptPath = path.resolve(__dirname, '../../assets/cookie-notice/frontconsent-cookie-notice.js');
const cookieNoticeScript = fs.readFileSync(scriptPath, 'utf8');

/**
 * Builds a minimal DOM/element stub good enough to exercise the popup
 * (true-modal) layout's focus management: moving focus in on open, trapping
 * Tab/Shift+Tab within the dialog's own focusable elements, treating Escape
 * as an explicit reject, and restoring focus to whatever triggered the
 * banner once it closes.
 */
function createElement(id) {
	var listeners = {};

	return {
		id: id,
		hidden: false,
		focusCalls: 0,
		focus() {
			this.focusCalls += 1;
			document.activeElement = this;
		},
		addEventListener(event, callback) {
			listeners[event] = listeners[event] || [];
			listeners[event].push(callback);
		},
		dispatchEvent(event) {
			(listeners[event.type] || []).forEach(function (callback) {
				callback(event);
			});
		},
		classList: {
			add() {},
			remove() {},
			contains(className) {
				return className === 'frcn-cookie-notice--popup';
			}
		}
	};
}

var document; // Populated per-test inside createEnvironment() below.

function createEnvironment(options) {
	options = options || {};
	var announcer = createElement('frcn-cookie-notice-announcer');
	var rejectBtn = createElement('reject');
	var acceptBtn = createElement('accept');
	var linkEl = createElement('link');
	var customizeBtn = createElement('customize');
	var focusable = [rejectBtn, acceptBtn, linkEl];
	var triggerEl = createElement('trigger'); // The element that "opened" the banner.
	var reopenBtn = createElement('frcn-cookie-reopen');
	var modalKeydownListeners = [];

	var banner = {
		classList: {
			add() {},
			remove() {},
			contains(className) {
				return className === 'frcn-cookie-notice--popup';
			}
		},
		parentNode: { removeChild() {} },
		querySelector(selector) {
			if ('[data-frcn-cookie-action="accept"]' === selector) {
				return acceptBtn;
			}
			if ('[data-frcn-cookie-action="reject"]' === selector) {
				return rejectBtn;
			}
			if ('[data-frcn-cookie-action="customize"]' === selector) {
				return customizeBtn;
			}
			return null;
		},
		querySelectorAll() {
			return focusable;
		},
		style: {}
	};

	// The cookie preferences panel — a separate dialog the Customize button
	// and the reopen trigger both open (see openPreferencesPanel() in
	// frontconsent-cookie-notice.js). Its own focus trap and Escape handling
	// must act on this element's controls, not the banner's.
	var panelCloseBtn = createElement('panel-close');
	var panelAcceptBtn = createElement('panel-accept');
	var panelRejectBtn = createElement('panel-reject');
	var panelSaveBtn = createElement('panel-save');
	var panelFocusable = [panelCloseBtn, panelAcceptBtn, panelRejectBtn, panelSaveBtn];

	var preferencesPanel = {
		hidden: true,
		classList: { add() {}, remove() {}, contains() { return false; } },
		querySelector(selector) {
			if ('[data-frcn-cookie-action="close-preferences"]' === selector) {
				return panelCloseBtn;
			}
			if ('[data-frcn-cookie-action="accept"]' === selector) {
				return panelAcceptBtn;
			}
			if ('[data-frcn-cookie-action="reject"]' === selector) {
				return panelRejectBtn;
			}
			if ('[data-frcn-cookie-action="save"]' === selector) {
				return panelSaveBtn;
			}
			return null;
		},
		querySelectorAll(selector) {
			if ('[data-frcn-category]' === selector) {
				return [];
			}
			return panelFocusable;
		}
	};

	document = {
		activeElement: triggerEl,
		body: { classList: { add() {}, remove() {} } },
		cookie: options.cookie || '',
		head: { appendChild() {} },
		readyState: 'loading',
		_domListeners: {},
		addEventListener(event, callback) {
			if ('keydown' === event) {
				modalKeydownListeners.push(callback);
			} else {
				this._domListeners[event] = callback;
			}
		},
		removeEventListener(event, callback) {
			if ('keydown' === event) {
				var index = modalKeydownListeners.indexOf(callback);
				if (index !== -1) {
					modalKeydownListeners.splice(index, 1);
				}
			}
		},
		createElement() { return {}; },
		dispatchEvent() {},
		getElementById(id) {
			if ('frcn-cookie-notice-announcer' === id) {
				return announcer;
			}
			if ('frcn-cookie-reopen' === id) {
				return reopenBtn;
			}
			if ('frcn-cookie-preferences' === id) {
				return preferencesPanel;
			}
			return banner;
		},
		getElementsByTagName() { return []; }
	};

	var window = {
		location: { protocol: 'https:' },
		setTimeout(callback) { callback(); }
	};

	var context = {
		Array,
		CustomEvent: function () {},
		Date,
		FormData: function () {
			this.values = {};
			this.append = function (key, value) { this.values[key] = value; };
		},
		decodeURIComponent,
		document,
		encodeURIComponent,
		fetch() {
			return Promise.resolve({ json: () => Promise.resolve({ success: false }) });
		},
		frcnCookieNotice: {
			ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
			cookieName: 'frcn_cookie_consent',
			cookiePath: '/',
			expirationDays: 365,
			isPolicyPage: '',
			homeUrl: 'https://example.test/'
		},
		frcnCookieNoticeA11y: {
			bannerOpened: 'Cookie consent banner opened.',
			accepted: 'Cookies accepted.',
			rejected: 'Cookies rejected.'
		},
		window
	};

	vm.runInNewContext(cookieNoticeScript, context);
	document._domListeners.DOMContentLoaded();

	function fireModalKeydown(eventInit) {
		var event = Object.assign({
			key: '',
			shiftKey: false,
			defaultPrevented: false,
			preventDefault() { this.defaultPrevented = true; }
		}, eventInit);

		modalKeydownListeners.forEach(function (callback) {
			callback(event);
		});

		return event;
	}

	return {
		acceptBtn,
		announcer,
		context,
		customizeBtn,
		fireModalKeydown,
		linkEl,
		modalKeydownListeners,
		panelAcceptBtn,
		panelCloseBtn,
		panelRejectBtn,
		panelSaveBtn,
		preferencesPanel,
		rejectBtn,
		reopenBtn,
		triggerEl,
		window
	};
}

test('opening the popup moves focus to the accept button and announces the banner', () => {
	var env = createEnvironment();

	assert.equal(env.acceptBtn.focusCalls, 1);
	assert.equal(env.announcer.textContent, 'Cookie consent banner opened.');
	// The Tab/Escape handler must actually be bound while the modal is open.
	assert.equal(env.modalKeydownListeners.length, 1);
});

test('Tab from the last focusable element wraps focus back to the first', () => {
	var env = createEnvironment();

	document.activeElement = env.linkEl; // The last focusable element.
	var event = env.fireModalKeydown({ key: 'Tab', shiftKey: false });

	assert.equal(event.defaultPrevented, true);
	assert.equal(document.activeElement, env.rejectBtn);
});

test('Shift+Tab from the first focusable element wraps focus back to the last', () => {
	var env = createEnvironment();

	document.activeElement = env.rejectBtn; // The first focusable element.
	var event = env.fireModalKeydown({ key: 'Tab', shiftKey: true });

	assert.equal(event.defaultPrevented, true);
	assert.equal(document.activeElement, env.linkEl);
});

test('Tab in the middle of the dialog is left alone (no wrap)', () => {
	var env = createEnvironment();

	document.activeElement = env.acceptBtn; // Neither first nor last.
	var event = env.fireModalKeydown({ key: 'Tab', shiftKey: false });

	assert.equal(event.defaultPrevented, false);
	assert.equal(document.activeElement, env.acceptBtn);
});

test('Escape rejects, announces the decision, and restores focus to the triggering element', () => {
	var env = createEnvironment();

	var event = env.fireModalKeydown({ key: 'Escape' });

	assert.equal(event.defaultPrevented, true);
	assert.equal(env.announcer.textContent, 'Cookies rejected.');
	assert.equal(document.activeElement, env.triggerEl);
	// The modal keydown handler must be unbound once the dialog closes.
	assert.equal(env.modalKeydownListeners.length, 0);
});

test('accepting via the button also restores focus to the triggering element and unbinds the modal handler', () => {
	var env = createEnvironment();

	env.acceptBtn.dispatchEvent({ type: 'click', preventDefault() {} });

	assert.equal(env.announcer.textContent, 'Cookies accepted.');
	assert.equal(document.activeElement, env.triggerEl);
	assert.equal(env.modalKeydownListeners.length, 0);
});

/**
 * Tests for the cookie preferences panel (issue #11): a separate dialog the
 * "Customize cookie settings" button and the persistent reopen trigger both
 * open, with its own focus trap and Escape handling, whose Accept
 * all/Reject all/Save changes actions must funnel through the exact same
 * decision-recording code path as the banner's own Accept/Reject buttons.
 */

test('clicking the Customize button opens the preferences panel and moves focus into it', () => {
	var env = createEnvironment();

	env.customizeBtn.dispatchEvent({ type: 'click' });

	assert.equal(env.preferencesPanel.hidden, false);
	assert.equal(env.panelCloseBtn.focusCalls, 1);
	// The banner's own modal trap stays bound underneath; the panel's own is
	// now bound alongside it.
	assert.equal(env.modalKeydownListeners.length, 2);
});

test('clicking the reopen trigger also opens the preferences panel', () => {
	// Realistically, the reopen trigger only ever reveals/binds for a visitor
	// who already decided (see setUpReopenTrigger()) — simulate that here.
	var env = createEnvironment({ cookie: 'frcn_cookie_consent=accepted' });

	env.reopenBtn.dispatchEvent({ type: 'click' });

	assert.equal(env.preferencesPanel.hidden, false);
	assert.equal(env.panelCloseBtn.focusCalls, 1);
	// The visitor already decided, so the banner itself was never revealed —
	// only the panel's own keydown handler is bound.
	assert.equal(env.modalKeydownListeners.length, 1);
});

test('Tab within the preferences panel wraps among its own controls, not the banner\'s', () => {
	var env = createEnvironment({ cookie: 'frcn_cookie_consent=accepted' });

	env.reopenBtn.dispatchEvent({ type: 'click' });

	document.activeElement = env.panelSaveBtn; // The panel's own last focusable element.
	var event = env.fireModalKeydown({ key: 'Tab', shiftKey: false });

	assert.equal(event.defaultPrevented, true);
	assert.equal(document.activeElement, env.panelCloseBtn);
});

test('Escape closes the preferences panel (without also rejecting via the banner\'s own handler) and restores focus to whichever trigger opened it', () => {
	var env = createEnvironment();

	env.customizeBtn.dispatchEvent({ type: 'click' });

	var event = env.fireModalKeydown({ key: 'Escape' });

	assert.equal(event.defaultPrevented, true);
	assert.equal(env.preferencesPanel.hidden, true);
	assert.equal(document.activeElement, env.customizeBtn);
	// No decision was recorded, so the underlying banner is untouched — only
	// the panel's own keydown handler should have unbound.
	assert.equal(env.modalKeydownListeners.length, 1);
	assert.notEqual(env.announcer.textContent, 'Cookies rejected.');
});

test('the close button also closes the panel and restores focus to the reopen trigger', () => {
	var env = createEnvironment({ cookie: 'frcn_cookie_consent=accepted' });

	env.reopenBtn.dispatchEvent({ type: 'click' });
	env.panelCloseBtn.dispatchEvent({ type: 'click' });

	assert.equal(env.preferencesPanel.hidden, true);
	assert.equal(document.activeElement, env.reopenBtn);
});

test('Accept all in the panel calls the same decision-recording function as the banner\'s own Accept button', () => {
	var env = createEnvironment({ cookie: 'frcn_cookie_consent=accepted' });

	env.reopenBtn.dispatchEvent({ type: 'click' });
	env.panelAcceptBtn.dispatchEvent({ type: 'click' });

	assert.ok(/frcn_cookie_consent=accepted/.test(document.cookie));
	assert.equal(env.announcer.textContent, 'Cookies accepted.');
	assert.equal(env.preferencesPanel.hidden, true);
});

test('Reject all in the panel calls the same decision-recording function as the banner\'s own Reject button', () => {
	var env = createEnvironment({ cookie: 'frcn_cookie_consent=accepted' });

	env.reopenBtn.dispatchEvent({ type: 'click' });
	env.panelRejectBtn.dispatchEvent({ type: 'click' });

	assert.ok(/frcn_cookie_consent=rejected/.test(document.cookie));
	assert.equal(env.announcer.textContent, 'Cookies rejected.');
});

test('Save changes with nothing else to opt into records the same rejection the Reject button would', () => {
	var env = createEnvironment({ cookie: 'frcn_cookie_consent=accepted' });

	env.reopenBtn.dispatchEvent({ type: 'click' });
	env.panelSaveBtn.dispatchEvent({ type: 'click' });

	assert.ok(/frcn_cookie_consent=rejected/.test(document.cookie));
});
