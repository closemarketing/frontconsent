const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const scriptPath = path.resolve(__dirname, '../../assets/cookie-notice/frontconsent-cookie-notice.js');
const cookieNoticeScript = fs.readFileSync(scriptPath, 'utf8');

const PURGE_COOKIES = ['_ga', '_ga_*', '_gid', '__obref', 'oaiq_*', 'sbjs_*'];

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

function createStorage(initial) {
	const items = new Map(Object.entries(initial || {}));

	return {
		get length() { return items.size; },
		key(index) { return Array.from(items.keys())[index] || null; },
		removeItem(key) { items.delete(key); },
		keys() { return Array.from(items.keys()); }
	};
}

function createEnvironment(initialCookies, initialSessionStorage, windowProps) {
	const sessionStorage = createStorage(initialSessionStorage);
	const cookies = createCookieJar(initialCookies);
	const cookieNames = () => Array.from(cookies.jar.values()).map((cookie) => cookie.name);
	// Each dispatched document event, with the cookies present at that moment.
	const events = [];
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
	const document = {
		activeElement: null,
		body: { classList: { add() {}, remove() {} } },
		get cookie() { return cookies.get(); },
		set cookie(raw) { cookies.set(raw); },
		head: { appendChild() {} },
		readyState: 'loading',
		addEventListener(event, callback) { listeners[event] = callback; },
		createElement() { return {}; },
		dispatchEvent(event) { events.push({ type: event.type, cookies: cookieNames() }); },
		getElementById(id) { return id === 'frcn-cookie-reopen' ? reopenBtn : banner; },
		getElementsByTagName() { return []; },
		querySelectorAll() { return []; },
		removeEventListener() {}
	};
	const window = Object.assign({
		location: { protocol: 'https:', hostname: 'www.example.com', reload() {} },
		sessionStorage,
		setTimeout(callback) { callback(); }
	}, windowProps);

	vm.runInNewContext(cookieNoticeScript, {
		Array,
		CustomEvent: function (type) { this.type = type; },
		Date,
		FormData: function () { this.append = function () {}; },
		decodeURIComponent,
		document,
		encodeURIComponent,
		fetch() { return Promise.resolve({ json() { return Promise.resolve({}); } }); },
		frcnCookieNotice: {
			ajaxUrl: 'https://www.example.com/wp-admin/admin-ajax.php',
			cookieName: 'frcn_cookie_consent',
			cookiePath: '/',
			expirationDays: 365,
			isPolicyPage: '',
			homeUrl: 'https://www.example.com/',
			purgeCookies: PURGE_COOKIES
		},
		window
	});
	listeners.DOMContentLoaded();

	return { actionListeners, cookieNames, events, reopenListeners, sessionStorage, setCookie: (raw) => cookies.set(raw), window };
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

	assert.deepEqual(environment.cookieNames().sort(), ['frcn_cookie_consent', 'woocommerce_items_in_cart']);
});

test('accepting keeps tracking cookies', () => {
	const environment = createEnvironment([]);

	// Set after page load, so only the accept handler could remove it.
	environment.setCookie('_ga=GA1.1.1; domain=.example.com; path=/');
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

test('an undecided visitor has leftover tracking cookies cleared on page load', () => {
	// Google Analytics rewrites its _ga_* session cookie while the page
	// unloads for the withdrawal reload, after the reopen handler's purge.
	const environment = createEnvironment([['_ga_ABC123', 'GS2.1.s1', '.example.com']]);

	assert.deepEqual(environment.cookieNames(), []);
});

test('withdrawing an earlier acceptance deletes tracking cookies', () => {
	const environment = createEnvironment([
		['frcn_cookie_consent', 'accepted', ''],
		['_ga', 'GA1.1.1', '.example.com'],
		['_ga_ABC123', 'GS1.1', '.example.com']
	]);

	assert.ok(environment.cookieNames().includes('_ga'));
	environment.reopenListeners.click();

	assert.deepEqual(environment.cookieNames(), []);
});

test('withdrawing an earlier acceptance also deletes the ChatGPT Ads pixel cookie and storage entry', () => {
	const environment = createEnvironment(
		[
			['frcn_cookie_consent', 'accepted', ''],
			['__obref', 'abc', '']
		],
		{ 'oaiq_cs:PIXEL123': '1', unrelated: 'keep' }
	);

	environment.reopenListeners.click();

	assert.deepEqual(environment.cookieNames(), []);
	assert.deepEqual(environment.sessionStorage.keys(), ['unrelated']);
});

test('rejecting clears tracking entries kept in web storage', () => {
	const environment = createEnvironment([], { 'oaiq_cs:PIXEL123': '1', unrelated: 'keep' });

	environment.actionListeners.reject({ preventDefault() {} });

	assert.deepEqual(environment.sessionStorage.keys(), ['unrelated']);
});

test('withdrawing sends a denied Consent Mode update before deleting tracking cookies', () => {
	const updates = [];
	let environment;
	const gtag = function (command, type, params) {
		if ('consent' === command && 'update' === type) {
			updates.push({ params, cookies: environment.cookieNames() });
		}
	};

	environment = createEnvironment(
		[
			['frcn_cookie_consent', 'accepted', ''],
			['_ga_ABC123', 'GS1.1', '.example.com']
		],
		{},
		{ gtag }
	);
	environment.reopenListeners.click();

	const denied = updates.find((update) => 'denied' === update.params.analytics_storage);

	assert.ok(denied, 'No denied Consent Mode update was sent on withdrawal');
	assert.deepEqual(
		{ ...denied.params },
		{ ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'denied' }
	);
	// Sent while the cookie was still there, so Google tags stop writing
	// before the purge instead of recreating it right after.
	assert.ok(denied.cookies.includes('_ga_ABC123'));
	assert.deepEqual(environment.cookieNames(), []);
});

test('withdrawing queues the denied Consent Mode update on the dataLayer when gtag is not defined', () => {
	const environment = createEnvironment([['frcn_cookie_consent', 'accepted', '']]);

	environment.reopenListeners.click();

	const pushed = (environment.window.dataLayer || []).map((entry) => Array.from(entry));
	const update = pushed.find((args) => 'consent' === args[0] && 'update' === args[1]);

	assert.ok(update, 'No Consent Mode update was pushed to the dataLayer');
	assert.equal(update[2].analytics_storage, 'denied');
	assert.equal(update[2].ad_storage, 'denied');
});

test('withdrawing dispatches frcnCookieConsentWithdrawn after the consent cookie is gone and before the purge', () => {
	const environment = createEnvironment([
		['frcn_cookie_consent', 'accepted', ''],
		['_ga', 'GA1.1.1', '.example.com']
	]);

	environment.reopenListeners.click();

	const withdrawn = environment.events.find((event) => 'frcnCookieConsentWithdrawn' === event.type);

	assert.ok(withdrawn, 'frcnCookieConsentWithdrawn was not dispatched');
	assert.ok(!withdrawn.cookies.includes('frcn_cookie_consent'));
	assert.ok(withdrawn.cookies.includes('_ga'));
});

test('an add-on can narrow the cookies to delete with frcnCookieNoticeFilterPurgePatterns', () => {
	let received;
	const environment = createEnvironment(
		[],
		{},
		{
			frcnCookieNoticeFilterPurgePatterns(patterns) {
				received = patterns;
				// Mutating the copy it receives must not change the plugin's list.
				patterns.push('should_not_leak');
				return patterns.filter((pattern) => '_ga' !== pattern && '_ga_*' !== pattern);
			}
		}
	);

	environment.setCookie('_ga=GA1.1.1; domain=.example.com; path=/');
	environment.setCookie('_gid=GA1.1.2; path=/');
	environment.actionListeners.reject({ preventDefault() {} });

	assert.ok(received.includes('_gid'));
	assert.ok(environment.cookieNames().includes('_ga'));
	assert.ok(!environment.cookieNames().includes('_gid'));
});

test('a filter that does not return an array is ignored and every tracking cookie is deleted', () => {
	const environment = createEnvironment([], {}, { frcnCookieNoticeFilterPurgePatterns() {} });

	environment.setCookie('_ga=GA1.1.1; domain=.example.com; path=/');
	environment.actionListeners.reject({ preventDefault() {} });

	assert.ok(!environment.cookieNames().includes('_ga'));
});

test('withdrawing deletes every tracking cookie even when an add-on filter would keep some', () => {
	let calls = 0;
	const environment = createEnvironment(
		[
			['frcn_cookie_consent', 'accepted', ''],
			['_ga', 'GA1.1.1', '.example.com']
		],
		{},
		{
			frcnCookieNoticeFilterPurgePatterns(patterns) {
				calls++;
				return patterns.filter((pattern) => '_ga' !== pattern);
			}
		}
	);

	environment.reopenListeners.click();

	assert.equal(calls, 0);
	assert.deepEqual(environment.cookieNames(), []);
});

test('a filter that throws does not stop the purge', () => {
	const environment = createEnvironment(
		[],
		{},
		{
			frcnCookieNoticeFilterPurgePatterns() {
				throw new Error('broken add-on');
			}
		}
	);

	environment.setCookie('_ga=GA1.1.1; domain=.example.com; path=/');
	assert.doesNotThrow(() => environment.actionListeners.reject({ preventDefault() {} }));

	assert.ok(!environment.cookieNames().includes('_ga'));
});

test('a filter that returns non-string patterns is ignored and the page still loads', () => {
	let environment;

	assert.doesNotThrow(() => {
		environment = createEnvironment(
			[['_ga', 'GA1.1.1', '.example.com']],
			{},
			{ frcnCookieNoticeFilterPurgePatterns() { return [null, '_gid']; } }
		);
	});

	// The page-load purge used the full list, and the banner was wired.
	assert.deepEqual(environment.cookieNames(), []);
	assert.equal(typeof environment.actionListeners.reject, 'function');
});
