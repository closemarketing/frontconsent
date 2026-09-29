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

function createEnvironment() {
	var announcer = createElement('frcn-cookie-notice-announcer');
	var rejectBtn = createElement('reject');
	var acceptBtn = createElement('accept');
	var linkEl = createElement('link');
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
			return null;
		},
		querySelectorAll() {
			return focusable;
		},
		style: {}
	};

	document = {
		activeElement: triggerEl,
		body: { classList: { add() {}, remove() {} } },
		cookie: '',
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
		fireModalKeydown,
		linkEl,
		modalKeydownListeners,
		rejectBtn,
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
