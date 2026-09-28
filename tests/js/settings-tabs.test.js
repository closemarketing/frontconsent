const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const scriptPath = path.resolve(__dirname, '../../assets/admin/settings.js');
const settingsScript = fs.readFileSync(scriptPath, 'utf8');

/**
 * Minimal fake element supporting exactly what settings.js's tab code uses:
 * attribute get/set, a boolean `hidden` property, classList add/remove/toggle,
 * addEventListener/dispatch, and a `contains()` ancestor check.
 */
function createElement(attrs = {}) {
	const listeners = {};
	const classes = new Set((attrs.class || '').split(' ').filter(Boolean));
	const element = {
		attrs: Object.assign({}, attrs),
		children: [],
		hidden: false,
		classList: {
			add(name) { classes.add(name); },
			remove(name) { classes.delete(name); },
			toggle(name, force) {
				if (force) {
					classes.add(name);
				} else {
					classes.delete(name);
				}
			},
			contains(name) { return classes.has(name); }
		},
		getAttribute(name) { return Object.prototype.hasOwnProperty.call(element.attrs, name) ? element.attrs[name] : null; },
		setAttribute(name, value) { element.attrs[name] = String(value); },
		addEventListener(event, callback) {
			listeners[event] = listeners[event] || [];
			listeners[event].push(callback);
		},
		dispatch(event, eventObject) {
			(listeners[event] || []).forEach((callback) => callback(eventObject));
		},
		contains(other) {
			return element.children.includes(other);
		},
		focus() { element.focused = true; },
		querySelector(selector) {
			const idMatch = selector.match(/^input\[name="([^"]+)"\]$/);
			if (idMatch) {
				return element.children.find((child) => child.attrs.name === idMatch[1]) || null;
			}
			return null;
		}
	};
	return element;
}

/**
 * Builds a fake document with exactly the tab bar / panel structure
 * settings.js's initTabs() queries: three tab buttons (settings, advanced,
 * license — advanced and license mimicking a companion plugin's own tab and
 * a self-contained License panel with its own <form>), matching panels, the
 * main settings form with its submit wrapper, and a second form for the
 * License panel to verify referer rewriting isn't limited to the main form.
 */
function createEnvironment(options = {}) {
	const settingsPanel = createElement({ 'data-tab-panel': 'settings' });
	const advancedPanel = createElement({ 'data-tab-panel': 'advanced' });
	const licensePanel = createElement({ 'data-tab-panel': 'license' });

	const settingsBtn = createElement({ 'data-tab-target': 'settings', class: 'frcn-tab-btn', role: 'tab' });
	const advancedBtn = createElement({ 'data-tab-target': 'advanced', class: 'frcn-tab-btn', role: 'tab' });
	const licenseBtn = createElement({ 'data-tab-target': 'license', class: 'frcn-tab-btn', role: 'tab' });
	const tabButtons = [settingsBtn, advancedBtn, licenseBtn];
	const tabPanels = [settingsPanel, advancedPanel, licensePanel];

	const mainFormReferer = createElement({ name: '_wp_http_referer' });
	mainFormReferer.value = 'https://example.test/wp-admin/options-general.php?page=frontconsent-settings';
	const mainForm = createElement({ id: 'frcn-settings-form' });
	mainForm.children = [mainFormReferer, settingsPanel, advancedPanel];

	const licenseFormReferer = createElement({ name: '_wp_http_referer' });
	licenseFormReferer.value = 'https://example.test/wp-admin/options-general.php?page=frontconsent-settings';
	const licenseForm = createElement({ id: 'frcnp-license-form' });
	licenseForm.children = [licenseFormReferer, licensePanel];

	const submitWrapper = createElement({ id: 'frcn-settings-form-submit' });

	const byId = {
		'frcn-settings-form': mainForm,
		'frcn-settings-form-submit': submitWrapper
	};

	const document = {
		readyState: 'complete',
		_domReadyCallback: null,
		addEventListener(event, callback) {
			if (event === 'DOMContentLoaded') {
				document._domReadyCallback = callback;
			}
		},
		getElementById(id) { return byId[id] || null; },
		querySelectorAll(selector) {
			if (selector === '[data-tab-target]') {
				return tabButtons;
			}
			if (selector === '[data-tab-panel]') {
				return tabPanels;
			}
			if (selector === '.frcn-settings-wrapper form') {
				return [mainForm, licenseForm];
			}
			return [];
		},
		querySelector(selector) {
			if (selector === '.frcn-tab-btn.is-active') {
				return tabButtons.find((btn) => btn.classList.contains('is-active')) || null;
			}
			const panelMatch = selector.match(/^\[data-tab-panel="([^"]+)"\]$/);
			if (panelMatch) {
				return tabPanels.find((panel) => panel.getAttribute('data-tab-panel') === panelMatch[1]) || null;
			}
			return null;
		}
	};

	const window = {
		location: { hash: options.hash || '' }
	};

	const context = { document, window, Array };
	vm.runInNewContext(settingsScript, context);
	document._domReadyCallback();

	return { advancedBtn, advancedPanel, licenseBtn, licenseForm, licensePanel, mainForm, settingsBtn, settingsPanel, submitWrapper, tabButtons, tabPanels };
}

test('activates the first tab by default and hides the others', () => {
	const env = createEnvironment();

	assert.equal(env.settingsPanel.hidden, false);
	assert.equal(env.advancedPanel.hidden, true);
	assert.equal(env.licensePanel.hidden, true);
	assert.equal(env.settingsBtn.classList.contains('is-active'), true);
	assert.equal(env.settingsBtn.getAttribute('aria-selected'), 'true');
});

test('restores the tab named in the URL hash on load', () => {
	const env = createEnvironment({ hash: '#license' });

	assert.equal(env.licensePanel.hidden, false);
	assert.equal(env.settingsPanel.hidden, true);
	assert.equal(env.licenseBtn.classList.contains('is-active'), true);
});

test('clicking a tab button activates its panel and deactivates the others', () => {
	const env = createEnvironment();

	env.advancedBtn.dispatch('click');

	assert.equal(env.advancedPanel.hidden, false);
	assert.equal(env.settingsPanel.hidden, true);
	assert.equal(env.advancedBtn.getAttribute('aria-selected'), 'true');
	assert.equal(env.settingsBtn.getAttribute('aria-selected'), 'false');
});

test('ArrowRight moves focus and activation to the next tab, wrapping at the end', () => {
	const env = createEnvironment();

	env.settingsBtn.dispatch('keydown', { key: 'ArrowRight', preventDefault() {} });
	assert.equal(env.advancedBtn.classList.contains('is-active'), true);
	assert.equal(env.advancedBtn.focused, true);

	env.advancedBtn.dispatch('keydown', { key: 'ArrowRight', preventDefault() {} });
	assert.equal(env.licenseBtn.classList.contains('is-active'), true);

	env.licenseBtn.dispatch('keydown', { key: 'ArrowRight', preventDefault() {} });
	assert.equal(env.settingsBtn.classList.contains('is-active'), true, 'ArrowRight from the last tab must wrap to the first');
});

test('Home and End jump to the first and last tab', () => {
	const env = createEnvironment();

	env.settingsBtn.dispatch('keydown', { key: 'End', preventDefault() {} });
	assert.equal(env.licenseBtn.classList.contains('is-active'), true);

	env.licenseBtn.dispatch('keydown', { key: 'Home', preventDefault() {} });
	assert.equal(env.settingsBtn.classList.contains('is-active'), true);
});

test('only the active tab button is in the default tab order', () => {
	const env = createEnvironment();

	assert.equal(env.settingsBtn.getAttribute('tabindex'), '0');
	assert.equal(env.advancedBtn.getAttribute('tabindex'), '-1');
	assert.equal(env.licenseBtn.getAttribute('tabindex'), '-1');

	env.advancedBtn.dispatch('click');

	assert.equal(env.settingsBtn.getAttribute('tabindex'), '-1');
	assert.equal(env.advancedBtn.getAttribute('tabindex'), '0');
});

test('hides the main form submit button on a tab outside the main form', () => {
	const env = createEnvironment();

	assert.equal(env.submitWrapper.hidden, false, 'Settings is an in-form tab');

	env.licenseBtn.dispatch('click');
	assert.equal(env.submitWrapper.hidden, true, 'License has its own form and must not show the main Save Changes button');

	env.advancedBtn.dispatch('click');
	assert.equal(env.submitWrapper.hidden, false, 'Advanced panel fields save through the main form');
});

test('rewrites the referer hash on submit for both the main form and a companion form', () => {
	const env = createEnvironment();
	const mainReferer = env.mainForm.querySelector('input[name="_wp_http_referer"]');
	const licenseReferer = env.licenseForm.querySelector('input[name="_wp_http_referer"]');

	env.advancedBtn.dispatch('click');
	env.mainForm.dispatch('submit');
	assert.equal(mainReferer.value, 'https://example.test/wp-admin/options-general.php?page=frontconsent-settings#advanced');

	env.licenseBtn.dispatch('click');
	env.licenseForm.dispatch('submit');
	assert.equal(licenseReferer.value, 'https://example.test/wp-admin/options-general.php?page=frontconsent-settings#license');
});
