const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const scriptPath = path.resolve(__dirname, '../../assets/cookie-notice/frontconsent-script-blocker.js');
const scriptBlockerScript = fs.readFileSync(scriptPath, 'utf8');

function makeParent() {
	const inserted = [];
	const removed = [];

	return {
		inserted,
		removed,
		insertBefore(newNode, referenceNode) {
			inserted.push({ newNode, referenceNode });
		},
		removeChild(node) {
			removed.push(node);
		}
	};
}

function makeElement(tagName, attrs) {
	const values = Object.assign({}, attrs);

	const element = {
		tagName: tagName.toUpperCase(),
		parentNode: null,
		src: undefined,
		get attributes() {
			return Object.keys(values).map(function (name) {
				return { name: name, value: values[name] };
			});
		},
		getAttribute(name) {
			return Object.prototype.hasOwnProperty.call(values, name) ? values[name] : null;
		},
		setAttribute(name, value) {
			values[name] = String(value);
		},
		removeAttribute(name) {
			delete values[name];
		}
	};

	// Mimic the real DOM's src attribute/property reflection: an element
	// created from markup with a src="..." attribute also has a matching
	// .src property, which is what reviveIframe() reads/writes directly.
	if (Object.prototype.hasOwnProperty.call(values, 'src')) {
		element.src = values.src;
	}

	return element;
}

function createEnvironment(options) {
	options = options || {};

	const placeholders = options.placeholders || [];
	const createdScripts = [];
	const listeners = {};

	const document = {
		cookie: options.cookie || '',
		readyState: 'loading',
		addEventListener(event, callback) {
			listeners[event] = callback;
		},
		querySelectorAll(selector) {
			assert.equal(selector, 'script[data-frcn-category], iframe[data-frcn-category]');
			return placeholders;
		},
		createElement(tagName) {
			const script = makeElement(tagName, {});
			createdScripts.push(script);
			return script;
		}
	};

	const context = { Array, document, window: {} };

	if (Object.prototype.hasOwnProperty.call(options, 'frcnCookieNotice')) {
		context.frcnCookieNotice = options.frcnCookieNotice;
	}

	if (options.isCategoryAllowed) {
		context.window.frcnCookieNoticeIsCategoryAllowed = options.isCategoryAllowed;
	}

	vm.createContext(context);
	vm.runInContext(scriptBlockerScript, context);
	listeners.DOMContentLoaded();

	return { context, createdScripts, listeners, placeholders };
}

test('a placeholder script for an accepted category is revived into a real, freshly-created script', () => {
	const parent = makeParent();
	const placeholder = makeElement('script', {
		type: 'text/plain',
		'data-frcn-category': 'marketing',
		'data-frcn-src': 'https://tracker.example.com/pixel.js',
		id: 'my-tracker',
		async: ''
	});
	placeholder.parentNode = parent;

	const environment = createEnvironment({
		cookie: 'frcn_cookie_consent=accepted',
		frcnCookieNotice: { cookieName: 'frcn_cookie_consent' },
		placeholders: [placeholder]
	});

	assert.equal(environment.createdScripts.length, 1);

	const revived = environment.createdScripts[0];
	assert.equal(revived.src, 'https://tracker.example.com/pixel.js');
	assert.equal(revived.getAttribute('id'), 'my-tracker');
	assert.equal(revived.getAttribute('async'), '');
	// The 'type' attribute (and the data-frcn-* bookkeeping attributes) must
	// NOT be copied onto the new element — a text/plain script would still
	// never execute, even freshly created.
	assert.equal(revived.getAttribute('type'), null);
	assert.equal(revived.getAttribute('data-frcn-category'), null);
	assert.equal(revived.getAttribute('data-frcn-src'), null);

	assert.equal(parent.inserted.length, 1);
	assert.equal(parent.inserted[0].newNode, revived);
	assert.equal(parent.inserted[0].referenceNode, placeholder);
	assert.equal(parent.removed.length, 1);
	assert.equal(parent.removed[0], placeholder);
});

test("an accepted iframe's src is restored from data-frcn-src and its bookkeeping attributes are cleaned up", () => {
	const placeholder = makeElement('iframe', {
		'data-frcn-category': 'marketing',
		'data-frcn-src': 'https://www.google.com/maps/embed?pb=1',
		src: 'about:blank',
		width: '600'
	});
	placeholder.parentNode = makeParent();

	createEnvironment({
		cookie: 'frcn_cookie_consent=accepted',
		frcnCookieNotice: { cookieName: 'frcn_cookie_consent' },
		placeholders: [placeholder]
	});

	assert.equal(placeholder.src, 'https://www.google.com/maps/embed?pb=1');
	assert.equal(placeholder.getAttribute('data-frcn-src'), null);
	assert.equal(placeholder.getAttribute('data-frcn-category'), null);
	assert.equal(placeholder.getAttribute('width'), '600');
});

test('a placeholder for a still-rejected category is left completely alone', () => {
	const parent = makeParent();
	const placeholder = makeElement('script', {
		type: 'text/plain',
		'data-frcn-category': 'marketing',
		'data-frcn-src': 'https://tracker.example.com/pixel.js'
	});
	placeholder.parentNode = parent;

	const environment = createEnvironment({
		cookie: 'frcn_cookie_consent=rejected',
		frcnCookieNotice: { cookieName: 'frcn_cookie_consent' },
		placeholders: [placeholder]
	});

	assert.equal(environment.createdScripts.length, 0);
	assert.equal(parent.inserted.length, 0);
	assert.equal(parent.removed.length, 0);
	assert.equal(placeholder.getAttribute('data-frcn-src'), 'https://tracker.example.com/pixel.js');
});

test('an undecided visitor (no consent cookie yet) leaves placeholders alone', () => {
	const placeholder = makeElement('iframe', {
		'data-frcn-category': 'marketing',
		'data-frcn-src': 'https://www.google.com/maps/embed?pb=1',
		src: 'about:blank'
	});
	placeholder.parentNode = makeParent();

	createEnvironment({
		cookie: '',
		frcnCookieNotice: { cookieName: 'frcn_cookie_consent' },
		placeholders: [placeholder]
	});

	assert.equal(placeholder.src, 'about:blank');
});

test('a per-category override (window.frcnCookieNoticeIsCategoryAllowed) is consulted instead of the binary cookie', () => {
	const marketingPlaceholder = makeElement('iframe', {
		'data-frcn-category': 'marketing',
		'data-frcn-src': 'https://www.google.com/maps/embed?pb=1',
		src: 'about:blank'
	});
	marketingPlaceholder.parentNode = makeParent();

	const analyticsPlaceholder = makeElement('iframe', {
		'data-frcn-category': 'analytics',
		'data-frcn-src': 'https://tracker.example.com/embed',
		src: 'about:blank'
	});
	analyticsPlaceholder.parentNode = makeParent();

	createEnvironment({
		// The binary cookie says 'rejected' — without the override, nothing
		// would be revived; the override says 'analytics' is allowed anyway.
		cookie: 'frcn_cookie_consent=rejected',
		isCategoryAllowed: (category) => category === 'analytics',
		placeholders: [marketingPlaceholder, analyticsPlaceholder]
	});

	assert.equal(marketingPlaceholder.src, 'about:blank');
	assert.equal(analyticsPlaceholder.src, 'https://tracker.example.com/embed');
});

test('revives newly-accepted placeholders on the frcnCookieConsent event', () => {
	const placeholder = makeElement('iframe', {
		'data-frcn-category': 'marketing',
		'data-frcn-src': 'https://www.google.com/maps/embed?pb=1',
		src: 'about:blank'
	});
	placeholder.parentNode = makeParent();

	const environment = createEnvironment({
		cookie: '',
		frcnCookieNotice: { cookieName: 'frcn_cookie_consent' },
		placeholders: [placeholder]
	});

	assert.equal(placeholder.src, 'about:blank');

	// A visitor decides to accept: frontconsent-cookie-notice.js sets the
	// cookie first, then dispatches this same event.
	environment.context.document.cookie = 'frcn_cookie_consent=accepted';
	environment.listeners.frcnCookieConsent({ detail: { consent: 'accepted' } });

	assert.equal(placeholder.src, 'https://www.google.com/maps/embed?pb=1');
});
