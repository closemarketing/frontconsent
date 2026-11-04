const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const scriptPath = path.resolve(__dirname, '../../assets/cookie-notice/frontconsent-cookie-notice.js');
const cookieNoticeScript = fs.readFileSync(scriptPath, 'utf8');
const cookieNoticePhpPath = path.resolve(__dirname, '../../includes/Frontend/CookieNotice.php');
const cookieNoticePhp = fs.readFileSync(cookieNoticePhpPath, 'utf8');

function createEnvironment(options = {}) {
	const scripts = [];
	let fetchCalls = 0;
	const fetchActions = [];
	const listeners = {};
	const actionListeners = {};
	const reopenListeners = {};
	const banner = {
		classList: {
			add() {},
			contains() { return false; },
			remove() {}
		},
		parentNode: { removeChild() {} },
		querySelector(selector) {
			const actionMatch = selector.match(/^\[data-frcn-cookie-action="(accept|reject)"\]$/);
			if (actionMatch) {
				return {
					addEventListener(event, callback) {
						actionListeners[actionMatch[1]] = actionListeners[actionMatch[1]] || {};
						actionListeners[actionMatch[1]][event] = callback;
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
		focusCalls: 0,
		focus() { this.focusCalls += 1; },
		addEventListener(event, callback) {
			reopenListeners[event] = callback;
		}
	};
	const preferencesPanel = {
		hidden: true,
		classList: { add() {}, remove() {}, contains() { return false; } },
		querySelector() { return null; },
		querySelectorAll() { return []; }
	};
	// An accumulating cookie jar (real browsers merge each
	// `document.cookie = "name=value; attrs..."` assignment into the
	// existing set rather than replacing it wholesale) — handleDecision()
	// now writes both the binary consent cookie and the per-category cookie
	// for a single decision, and both must be readable afterwards.
	const cookieJar = {};

	(options.cookie || '').split(';').forEach((pair) => {
		const index = pair.indexOf('=');

		if (index > -1) {
			cookieJar[pair.slice(0, index).trim()] = pair.slice(index + 1).trim();
		}
	});

	const document = {
		activeElement: null,
		body: { classList: { add() {}, remove() {} } },
		get cookie() {
			return Object.keys(cookieJar).map((name) => `${name}=${cookieJar[name]}`).join('; ');
		},
		set cookie(value) {
			const index = value.indexOf('=');

			if (index === -1) {
				return;
			}

			cookieJar[value.slice(0, index).trim()] = value.slice(index + 1).split(';')[0];
		},
		head: { appendChild(script) { scripts.push(script); } },
		readyState: 'loading',
		addEventListener(event, callback) { listeners[event] = callback; },
		removeEventListener() {},
		createElement() { return {}; },
		dispatchEvent() {},
		getElementById(id) {
			if (id === 'frcn-cookie-reopen') { return reopenBtn; }
			if (id === 'frcn-cookie-preferences') { return preferencesPanel; }
			return banner;
		},
		getElementsByTagName() { return []; }
	};
	const window = {
		location: { protocol: 'https:' },
		setTimeout(callback) { callback(); }
	};
	if (options.existingOaiq) {
		window.oaiq = options.existingOaiq;
	}
	const context = {
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
		fetch(url, request) {
			fetchCalls += 1;
			fetchActions.push(request.body.values.action);
			return Promise.resolve({
				json() {
					return Promise.resolve({
						success: true,
						data: {
							gtmId: '',
							ga4Id: '',
							trackingIntegrations: options.trackingIntegrations || [ { type: 'openai_chatgpt_ads', id: 'TestChatGPTPixelId1234' } ],
							allowedCategories: options.allowedCategories
						}
					});
				}
			});
		},
		frcnCookieNotice: {
			ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
			cookieName: 'frcn_cookie_consent',
			categoriesCookieName: 'frontconsent_categories',
			cookiePath: '/',
			expirationDays: 365,
			isPolicyPage: options.isPolicyPage || '',
			homeUrl: options.homeUrl || 'https://example.test/'
		},
		window
	};
	vm.runInNewContext(cookieNoticeScript, context);
	listeners.DOMContentLoaded();

	return { actionListeners, fetchActions, fetchCalls: () => fetchCalls, preferencesPanel, reopenBtn, reopenListeners, scripts, window };
}

test('does not load ChatGPT Ads before consent and loads it after acceptance', async () => {
	const environment = createEnvironment();

	await new Promise((resolve) => setImmediate(resolve));
	assert.equal(environment.scripts.length, 0);
	assert.equal(environment.fetchCalls(), 0);
	environment.actionListeners.accept.click({ preventDefault() {} });
	await new Promise((resolve) => setImmediate(resolve));
	await new Promise((resolve) => setImmediate(resolve));

	assert.equal(environment.scripts.length, 1);
	assert.equal(environment.scripts[0].src, 'https://bzrcdn.openai.com/sdk/oaiq.min.js');
	assert.equal(environment.window.oaiq.q[0][0], 'init');
	assert.equal(environment.window.oaiq.q[0][1].pixelId, 'TestChatGPTPixelId1234');
	assert.equal(environment.window.oaiq.q[0][1].debug, true);
});

test('the complete inline bootstrap initializes ChatGPT Ads for an accepted returning visitor', async () => {
	const inlineBootstrapMatch = cookieNoticePhp.match(/public function render_consent_bootstrap_script\(\) \{[\s\S]*?\$code = "\n([\s\S]*?)\n\t{2,3}";/);
	assert.ok(inlineBootstrapMatch);

	const scripts = [];
	const document = {
		cookie: 'frcn_cookie_consent=accepted',
		head: { appendChild(script) { scripts.push(script); } },
		createElement() { return {}; },
		getElementsByTagName() { return []; }
	};
	const window = {};
	const inlineBootstrapScript = inlineBootstrapMatch[1]
		.replace(/" \. esc_js\( \$cookie_name \) \. "/g, 'frcn_cookie_consent')
		.replace(/" \. esc_url\( \$this->get_ajax_url\(\) \) \. "/g, 'https://example.test/wp-admin/admin-ajax.php');

	vm.runInNewContext(inlineBootstrapScript, {
		Array,
		Date,
		FormData: function () { this.append = function () {}; },
		decodeURIComponent,
		document,
		encodeURIComponent,
		fetch() {
			return Promise.resolve({
				json() {
					return Promise.resolve({
						success: true,
						data: {
							trackingIntegrations: [ { type: 'openai_chatgpt_ads', id: 'TestChatGPTPixelId1234' } ]
						}
					});
				}
			});
		},
		window
	});
	await new Promise((resolve) => setImmediate(resolve));
	await new Promise((resolve) => setImmediate(resolve));

	assert.equal(window.frcnCookieNoticeBootstrapped, true);
	assert.equal(scripts.length, 1);
	assert.equal(scripts[0].src, 'https://bzrcdn.openai.com/sdk/oaiq.min.js');
	assert.equal(window.oaiq.q[0][0], 'init');
	assert.equal(window.oaiq.q[0][1].pixelId, 'TestChatGPTPixelId1234');
});

test('initializes ChatGPT Ads when oaiq already exists', () => {
	const calls = [];
	const environment = createEnvironment({ existingOaiq: function () {
		calls.push(Array.from(arguments));
	} });

	environment.window.frcnCookieNoticeInject('', '', [
		{ type: 'openai_chatgpt_ads', id: 'TestChatGPTPixelId1234' }
	]);

	assert.equal(environment.scripts.length, 0);
	assert.equal(calls.length, 1);
	assert.equal(calls[0][0], 'init');
	assert.equal(calls[0][1].pixelId, 'TestChatGPTPixelId1234');
	assert.equal(calls[0][1].debug, true);
});

test('does not initialize ChatGPT Ads when marketing consent is denied', () => {
	const calls = [];
	const environment = createEnvironment({ existingOaiq: function () {
		calls.push(Array.from(arguments));
	} });

	environment.window.frcnCookieNoticeInject('', '', [
		{ type: 'openai_chatgpt_ads', id: 'TestChatGPTPixelId1234', category: 'marketing' }
	], { analytics: true, marketing: false });

	assert.equal(environment.scripts.length, 0);
	assert.equal(calls.length, 0);
});

test('does not load ChatGPT Ads after explicit rejection', async () => {
	const environment = createEnvironment();

	environment.actionListeners.reject.click({ preventDefault() {} });
	await new Promise((resolve) => setImmediate(resolve));
	await new Promise((resolve) => setImmediate(resolve));

	assert.equal(environment.fetchActions.includes('frcn_get_cookie_notice_config'), false);
	assert.equal(environment.scripts.length, 0);
	assert.equal(environment.window.oaiq, undefined);
});

test('reopen trigger stays hidden when no decision has been made yet', () => {
	const environment = createEnvironment();

	assert.equal(environment.reopenBtn.hidden, true);
	assert.equal(environment.reopenListeners.click, undefined);
});

test('reopen trigger is revealed and opens the preferences panel on click', () => {
	const environment = createEnvironment({ cookie: 'frcn_cookie_consent=accepted' });

	assert.equal(environment.reopenBtn.hidden, false);
	assert.equal(typeof environment.reopenListeners.click, 'function');
	assert.equal(environment.preferencesPanel.hidden, true);

	let reloaded = false;
	environment.window.location.reload = () => { reloaded = true; };

	environment.reopenListeners.click();

	// The panel opens in place — no reload, no navigation away.
	assert.equal(reloaded, false);
	assert.equal(environment.preferencesPanel.hidden, false);
});

test('reopen trigger opens the preferences panel on the policy page too', () => {
	const environment = createEnvironment({
		cookie: 'frcn_cookie_consent=accepted',
		isPolicyPage: '1',
		homeUrl: 'https://example.test/'
	});

	let reloaded = false;
	environment.window.location.reload = () => { reloaded = true; };

	environment.reopenListeners.click();

	// The preferences panel is rendered unconditionally (including on the
	// policy page — see CookieNotice::render_preferences_panel()), so the
	// reopen trigger no longer needs to navigate away to reach it.
	assert.equal(reloaded, false);
	assert.equal(environment.window.location.href, undefined);
	assert.equal(environment.preferencesPanel.hidden, false);
});

test('accepting sends the consent update through gtag(), not a raw dataLayer push', async () => {
	const environment = createEnvironment();

	environment.actionListeners.accept.click({ preventDefault() {} });
	await new Promise((resolve) => setImmediate(resolve));

	assert.equal(typeof environment.window.gtag, 'function');

	const consentUpdateCalls = environment.window.dataLayer.filter(
		(entry) => Array.isArray(entry) && entry[0] === 'consent' && entry[1] === 'update'
	);

	// gtag() itself pushes `arguments` (array-like, not a plain Array), so a
	// correctly routed call must NOT also appear as a plain-array push —
	// that would mean the raw dataLayer.push(['consent', 'update', ...])
	// regression is back instead of gtag('consent', 'update', ...).
	assert.equal(consentUpdateCalls.length, 0);
	assert.ok(
		environment.window.dataLayer.some((entry) => entry && entry[0] === 'consent' && entry[1] === 'update' && entry[2] && entry[2].analytics_storage === 'granted'),
		'expected a consent update entry queued via gtag()'
	);
});

test('reopen trigger is revealed immediately after an in-page decision, without a reload', async () => {
	const environment = createEnvironment();

	assert.equal(environment.reopenBtn.hidden, true);

	environment.actionListeners.accept.click({ preventDefault() {} });
	await new Promise((resolve) => setImmediate(resolve));

	assert.equal(environment.reopenBtn.hidden, false);
});

test('consent mode default runs immediately at parse time, before DOMContentLoaded fires', () => {
	// createEnvironment() always fires DOMContentLoaded itself, so this
	// inspects dataLayer right after vm.runInNewContext() but before that —
	// by re-running the same setup manually instead of via the shared helper.
	const dataLayerEntries = [];
	const context = {
		Array,
		CustomEvent: function () {},
		Date,
		FormData: function () { this.append = function () {}; },
		decodeURIComponent,
		document: {
			readyState: 'loading',
			cookie: '',
			addEventListener() {},
			getElementById() { return null; }
		},
		encodeURIComponent,
		fetch() { return Promise.resolve({ json: () => Promise.resolve({ success: false }) }); },
		frcnCookieNotice: {
			ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
			cookieName: 'frcn_cookie_consent',
			cookiePath: '/',
			expirationDays: 365,
			isPolicyPage: '',
			homeUrl: 'https://example.test/'
		},
		window: {
			get dataLayer() { return dataLayerEntries; },
			set dataLayer(value) { dataLayerEntries.length = 0; dataLayerEntries.push(...value); },
			location: { protocol: 'https:' },
			setTimeout(callback) { callback(); }
		}
	};

	vm.runInNewContext(cookieNoticeScript, context);

	// No DOMContentLoaded was fired above — if a 'consent default' entry is
	// already queued, setConsentModeDefault() ran at parse time as intended.
	assert.ok(
		dataLayerEntries.some((entry) => entry && entry[0] === 'consent' && entry[1] === 'default'),
		'expected a consent default entry queued before DOMContentLoaded'
	);
});

test('a stale-consent report overrides a granted per-category override state back to denied', () => {
	const dataLayerEntries = [];
	const context = {
		Array,
		CustomEvent: function () {},
		Date,
		FormData: function () { this.append = function () {}; },
		decodeURIComponent,
		document: {
			readyState: 'loading',
			cookie: '',
			addEventListener() {},
			getElementById() { return null; }
		},
		encodeURIComponent,
		fetch() { return Promise.resolve({ json: () => Promise.resolve({ success: false }) }); },
		frcnCookieNotice: {
			ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
			cookieName: 'frcn_cookie_consent',
			cookiePath: '/',
			expirationDays: 365,
			isPolicyPage: '',
			homeUrl: 'https://example.test/'
		},
		window: {
			get dataLayer() { return dataLayerEntries; },
			set dataLayer(value) { dataLayerEntries.length = 0; dataLayerEntries.push(...value); },
			location: { protocol: 'https:' },
			setTimeout(callback) { callback(); },
			// An add-on reporting a (stale) granted override, plus staleness.
			frcnCookieNoticeConsentModeState() {
				return { ad_storage: 'granted', ad_user_data: 'granted', ad_personalization: 'granted', analytics_storage: 'granted' };
			},
			frcnCookieNoticeIsConsentStale() {
				return true;
			}
		}
	};

	vm.runInNewContext(cookieNoticeScript, context);

	const defaultEntry = dataLayerEntries.find((entry) => entry && entry[0] === 'consent' && entry[1] === 'default');

	assert.ok(defaultEntry, 'expected a consent default entry');
	assert.equal(defaultEntry[2].analytics_storage, 'denied');
});
