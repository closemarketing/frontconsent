const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const scriptPath = path.resolve(__dirname, '../../assets/admin/scanner.js');
const scannerScript = fs.readFileSync(scriptPath, 'utf8');

/**
 * Minimal fake element supporting what scanner.js uses.
 */
function createElement(attrs = {}) {
	const listeners = {};
	const classes = new Set((attrs.class || '').split(' ').filter(Boolean));
	return {
		attrs,
		textContent: '',
		innerHTML: '',
		disabled: false,
		children: {},
		classList: {
			contains(name) { return classes.has(name); },
			toggle(name, force) {
				if (force) {
					classes.add(name);
				} else {
					classes.delete(name);
				}
			}
		},
		hasAttribute(name) { return Object.prototype.hasOwnProperty.call(attrs, name); },
		addEventListener(event, callback) { listeners[event] = callback; },
		click() { listeners.click(); },
		querySelector(selector) { return this.children[selector] || null; }
	};
}

function setUp(responses) {
	const root = createElement({ 'data-frcn-scanner': '' });
	const button = createElement();
	const message = createElement();
	const result = createElement();
	root.children = {
		'[data-frcn-scanner-start]': button,
		'[data-frcn-scanner-message]': message,
		'[data-frcn-scanner-result]': result
	};

	let ready;
	const requests = [];
	const context = {
		window: {
			frcnScanner: {
				ajaxUrl: '/wp-admin/admin-ajax.php',
				nonce: 'n',
				maxPolls: 40,
				i18n: { running: 'Analyzing…', error: 'Error', timeout: 'Timeout' }
			},
			setTimeout() {}
		},
		document: {
			addEventListener(event, callback) { ready = callback; },
			querySelectorAll() { return [root]; }
		},
		FormData: class {
			append(key, value) { this[key] = value; }
		},
		fetch(url, options) {
			requests.push(options.body.action);
			const json = responses.shift();
			return Promise.resolve({ json: () => Promise.resolve(json) });
		}
	};
	context.window.window = context.window;

	vm.runInNewContext(scannerScript, context);
	ready();

	return { button, message, result, requests };
}

const flush = () => new Promise((resolve) => setImmediate(resolve));

test('shows the running message only until the pending result is rendered', async () => {
	const { button, message, result, requests } = setUp([
		{ success: true, data: { pending: true, pollAfterSeconds: 5, html: '<p>Analyzing…</p>', compactHtml: '' } }
	]);

	button.click();
	assert.equal(message.textContent, 'Analyzing…');
	assert.equal(button.disabled, true);

	await flush();

	assert.deepEqual(requests, ['frcn_scanner_start']);
	assert.equal(result.innerHTML, '<p>Analyzing…</p>');
	assert.equal(message.textContent, '', 'the pending result already says it is analyzing');
	assert.equal(button.disabled, true);
});

test('shows the API error and re-enables the button', async () => {
	const { button, message } = setUp([
		{ success: false, data: { message: 'Esa dirección no parece válida.' } }
	]);

	button.click();
	await flush();

	assert.equal(message.textContent, 'Esa dirección no parece válida.');
	assert.equal(message.classList.contains('frcn-scanner__message--error'), true);
	assert.equal(button.disabled, false);
});
