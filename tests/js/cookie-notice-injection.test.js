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
		addEventListener(event, callback) {
			reopenListeners[event] = callback;
		}
	};
	const document = {
		activeElement: null,
		body: { classList: { add() {}, remove() {} } },
		cookie: options.cookie || '',
		head: { appendChild(script) { scripts.push(script); } },
		readyState: 'loading',
		addEventListener(event, callback) { listeners[event] = callback; },
		createElement() { return {}; },
		dispatchEvent() {},
		getElementById(id) { return id === 'frcn-cookie-reopen' ? reopenBtn : banner; },
		getElementsByTagName() { return []; },
		removeEventListener() {}
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
			cookiePath: '/',
			expirationDays: 365,
			isPolicyPage: options.isPolicyPage || '',
			homeUrl: options.homeUrl || 'https://example.test/'
		},
		window
	};
	vm.runInNewContext(cookieNoticeScript, context);
	listeners.DOMContentLoaded();

	return { actionListeners, fetchActions, fetchCalls: () => fetchCalls, reopenBtn, reopenListeners, scripts, window };
}

test('does not load ChatGPT Ads before consent and loads it after acceptance', async () => {
	const environment = createEnvironment();

	await new Promise((resolve) => setImmediate(resolve));
	assert.equal(environment.scripts.length, 0);
	assert.equal(environment.fetchCalls(), 0);
	environment.actionListeners.accept.click();
	await new Promise((resolve) => setImmediate(resolve));
	await new Promise((resolve) => setImmediate(resolve));

	assert.equal(environment.scripts.length, 1);
	assert.equal(environment.scripts[0].src, 'https://bzrcdn.openai.com/sdk/oaiq.min.js');
	assert.equal(environment.window.oaiq.q[0][0], 'init');
	assert.equal(environment.window.oaiq.q[0][1].pixelId, 'TestChatGPTPixelId1234');
	assert.equal(environment.window.oaiq.q[0][1].debug, true);
});

test('the complete inline bootstrap initializes ChatGPT Ads for an accepted returning visitor', async () => {
	const inlineBootstrapMatch = cookieNoticePhp.match(/public function render_consent_bootstrap_script\(\) \{[\s\S]*?<script>\s*([\s\S]*?)\s*<\/script>/);
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
		.replace(/<\?php echo esc_js\( \$cookie_name \); \?>/g, 'frcn_cookie_consent')
		.replace(/<\?php echo esc_url\( \$this->get_ajax_url\(\) \); \?>/g, 'https://example.test/wp-admin/admin-ajax.php');

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

	environment.actionListeners.reject.click();
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

test('reopen trigger is revealed and clears the consent cookie on click', () => {
	const environment = createEnvironment({ cookie: 'frcn_cookie_consent=accepted' });

	assert.equal(environment.reopenBtn.hidden, false);
	assert.equal(typeof environment.reopenListeners.click, 'function');

	let reloaded = false;
	environment.window.location.reload = () => { reloaded = true; };

	environment.reopenListeners.click();

	assert.equal(reloaded, true);
});

test('reopen trigger on the policy page navigates home instead of reloading in place', () => {
	const environment = createEnvironment({
		cookie: 'frcn_cookie_consent=accepted',
		isPolicyPage: '1',
		homeUrl: 'https://example.test/'
	});

	let reloaded = false;
	environment.window.location.reload = () => { reloaded = true; };

	environment.reopenListeners.click();

	assert.equal(reloaded, false);
	assert.equal(environment.window.location.href, 'https://example.test/');
});

test('accepting sends the consent update through gtag(), not a raw dataLayer push', async () => {
	const environment = createEnvironment();

	environment.actionListeners.accept.click();
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
