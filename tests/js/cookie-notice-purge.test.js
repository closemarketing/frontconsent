const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const scriptPath = path.resolve(__dirname, '../../assets/cookie-notice/frontconsent-cookie-notice.js');
const cookieNoticeScript = fs.readFileSync(scriptPath, 'utf8');

const PURGE_COOKIES = ['_ga', '_ga_*', '_gid', '_fbp', 'sbjs_*'];
const ANALYTICS_COOKIES = ['_ga', '_ga_*', '_gid'];
const CATEGORIES_COOKIE = 'frontconsent_categories';

/**
 * Minimal cookie jar keyed by name + domain, so a cookie set on
 * '.example.com' is only removed by a deletion for that same domain —
 * the case a host-only deletion silently misses in a real browser.
 */
function createCookieJar(initial) {
	const jar = new Map();
	// Browsers ignore a leading dot: 'domain=.example.com' and
	// 'domain=example.com' address the same cookie.
	const normalizeDomain = (domain) => (domain || '').replace(/^\./, '');

	initial.forEach(([name, value, domain]) => jar.set(name + '|' + normalizeDomain(domain), { name, value }));

	return {
		jar,
		get() {
			return Array.from(jar.values()).map((cookie) => cookie.name + '=' + cookie.value).join('; ');
		},
		set(raw) {
			const parts = raw.split(';').map((part) => part.trim());
			const [name, value] = parts[0].split('=');
			const domainPart = parts.find((part) => 0 === part.indexOf('domain='));
			const domain = normalizeDomain(domainPart ? domainPart.slice('domain='.length) : '');
			const key = name + '|' + domain;

			if (parts.includes('max-age=0')) {
				jar.delete(key);
			} else {
				jar.set(key, { name, value });
			}
		}
	};
}

function createEnvironment(initialCookies) {
	const cookies = createCookieJar(initialCookies);
	const listeners = {};
	const actionListeners = {};
	const reopenListeners = {};
	const banner = {
		classList: { add() {}, contains() { return false; }, remove() {} },
		parentNode: { removeChild() {} },
		querySelector(selector) {
			const actionMatch = selector.match(/^\[data-frcn-cookie-action="(accept|reject)"\]$/);
			if (actionMatch) {
				return {
					addEventListener(event, callback) {
						actionListeners[actionMatch[1]] = callback;
					}
				};
			}
			return null;
		},
		querySelectorAll() { return []; },
		style: {}
	};
	const reopenBtn = {
		hidden: true,
		addEventListener(event, callback) { reopenListeners[event] = callback; }
	};
	const panelListeners = {};
	const toggles = ['analytics', 'marketing'].map((category) => ({
		checked: false,
		getAttribute() { return category; }
	}));
	const preferencesPanel = {
		hidden: true,
		querySelector(selector) {
			const actionMatch = selector.match(/^\[data-frcn-cookie-action="([a-z-]+)"\]$/);
			if (actionMatch) {
				return {
					addEventListener(event, callback) {
						panelListeners[actionMatch[1]] = callback;
					}
				};
			}
			return null;
		},
		querySelectorAll(selector) {
			return '[data-frcn-category]' === selector ? toggles : [];
		}
	};
	const document = {
		activeElement: null,
		body: { classList: { add() {}, remove() {} } },
		get cookie() { return cookies.get(); },
		set cookie(raw) { cookies.set(raw); },
		head: { appendChild() {} },
		readyState: 'loading',
		addEventListener(event, callback) { listeners[event] = callback; },
		createElement() { return {}; },
		dispatchEvent() {},
		getElementById(id) {
			if ('frcn-cookie-reopen' === id) {
				return reopenBtn;
			}
			if ('frcn-cookie-preferences' === id) {
				return preferencesPanel;
			}
			return banner;
		},
		getElementsByTagName() { return []; },
		querySelectorAll() { return []; },
		removeEventListener() {}
	};
	const window = {
		location: { protocol: 'https:', hostname: 'www.example.com', reload() {} },
		setTimeout(callback) { callback(); }
	};

	vm.runInNewContext(cookieNoticeScript, {
		Array,
		CustomEvent: function () {},
		Date,
		FormData: function () { this.append = function () {}; },
		decodeURIComponent,
		document,
		encodeURIComponent,
		fetch() { return Promise.resolve({ json() { return Promise.resolve({}); } }); },
		frcnCookieNotice: {
			ajaxUrl: 'https://www.example.com/wp-admin/admin-ajax.php',
			cookieName: 'frcn_cookie_consent',
			categoriesCookieName: CATEGORIES_COOKIE,
			cookiePath: '/',
			expirationDays: 365,
			isPolicyPage: '',
			homeUrl: 'https://www.example.com/',
			purgeCookies: PURGE_COOKIES,
			analyticsCookies: ANALYTICS_COOKIES
		},
		window
	});
	listeners.DOMContentLoaded();

	return { actionListeners, cookieNames: () => Array.from(cookies.jar.values()).map((cookie) => cookie.name), panelListeners, reopenListeners, toggles };
}

test('rejecting deletes tracking cookies set on the host and on parent domains', () => {
	const environment = createEnvironment([
		['_ga', 'GA1.1.1', '.example.com'],
		['_ga_ABC123', 'GS1.1', '.example.com'],
		['_gid', 'GA1.1.2', ''],
		['sbjs_first', 'typ=typein', 'www.example.com'],
		['woocommerce_items_in_cart', '1', '']
	]);

	environment.actionListeners.reject({ preventDefault() {} });

	assert.deepEqual(environment.cookieNames().sort(), ['frcn_cookie_consent', CATEGORIES_COOKIE, 'woocommerce_items_in_cart']);
});

test('accepting keeps tracking cookies', () => {
	const environment = createEnvironment([['_ga', 'GA1.1.1', '.example.com']]);

	environment.actionListeners.accept({ preventDefault() {} });

	assert.ok(environment.cookieNames().includes('_ga'));
});

test('a returning rejected visitor has leftover tracking cookies cleared on page load', () => {
	const environment = createEnvironment([
		['frcn_cookie_consent', 'rejected', ''],
		['_ga', 'GA1.1.1', '.example.com']
	]);

	assert.deepEqual(environment.cookieNames(), ['frcn_cookie_consent']);
});

test('withdrawing an earlier acceptance from the preferences panel deletes tracking cookies', () => {
	const environment = createEnvironment([
		['frcn_cookie_consent', 'accepted', ''],
		['_ga', 'GA1.1.1', '.example.com'],
		['_ga_ABC123', 'GS1.1', '.example.com']
	]);

	assert.ok(environment.cookieNames().includes('_ga'));
	environment.reopenListeners.click();
	environment.panelListeners.reject();

	assert.deepEqual(environment.cookieNames().sort(), ['frcn_cookie_consent', CATEGORIES_COOKIE]);
});

test('saving Analytics only keeps analytics cookies and deletes marketing ones', () => {
	const environment = createEnvironment([
		['_ga', 'GA1.1.1', '.example.com'],
		['_fbp', 'fb.1.1', '.example.com']
	]);

	environment.toggles[0].checked = true;
	environment.panelListeners.save();

	assert.ok(environment.cookieNames().includes('_ga'));
	assert.ok(!environment.cookieNames().includes('_fbp'));
});

test('a returning Analytics-only visitor keeps _ga on page load', () => {
	const environment = createEnvironment([
		['frcn_cookie_consent', 'accepted', ''],
		[CATEGORIES_COOKIE, encodeURIComponent(JSON.stringify({ necessary: true, analytics: true, marketing: false })), ''],
		['_ga', 'GA1.1.1', '.example.com'],
		['_fbp', 'fb.1.1', '.example.com']
	]);

	assert.ok(environment.cookieNames().includes('_ga'));
	assert.ok(!environment.cookieNames().includes('_fbp'));
});
