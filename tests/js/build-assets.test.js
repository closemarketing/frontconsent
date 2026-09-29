const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const { minifyJs, minifyCss, toMinifiedPath } = require('../../bin/build-assets.js');

const cookieNoticeJsPath = path.resolve(__dirname, '../../assets/cookie-notice/frontconsent-cookie-notice.js');
const cookieNoticeCssPath = path.resolve(__dirname, '../../assets/cookie-notice/frontconsent-cookie-notice.css');
const settingsJsPath = path.resolve(__dirname, '../../assets/admin/settings.js');
const settingsCssPath = path.resolve(__dirname, '../../assets/admin/settings.css');

/**
 * Smoke-tests the minifiers programmatically against the real source files —
 * this is not meant to re-verify banner/settings *behavior* (the existing
 * cookie-notice-injection.test.js and settings-tabs.test.js already exercise
 * that against the plain files), only that bin/build-assets.js produces
 * syntactically valid, non-empty output that still contains a few
 * behavior-relevant tokens from the source.
 */

test('minifyJs produces syntactically valid, non-empty output for the cookie notice script', async () => {
	const source = fs.readFileSync(cookieNoticeJsPath, 'utf8');
	const minified = await minifyJs(source);

	assert.equal(typeof minified, 'string');
	assert.ok(minified.length > 0, 'minified output must not be empty');
	assert.ok(minified.length < source.length, 'minified output must be smaller than the source');

	// Must parse without a syntax error.
	assert.doesNotThrow(() => new vm.Script(minified));

	// A handful of tokens the runtime behavior actually depends on must survive.
	assert.ok(minified.includes('frcnCookieNotice'), 'must still reference the localized config global');
	assert.ok(minified.includes('data-frcn-cookie-action'), 'must still reference the accept/reject selector');
});

test('minifyCss produces syntactically valid, non-empty output for the cookie notice stylesheet', () => {
	const source = fs.readFileSync(cookieNoticeCssPath, 'utf8');
	const minified = minifyCss(source);

	assert.equal(typeof minified, 'string');
	assert.ok(minified.length > 0, 'minified output must not be empty');
	assert.ok(minified.length < source.length, 'minified output must be smaller than the source');

	// No unclosed rules/comments left behind.
	assert.equal((minified.match(/{/g) || []).length, (minified.match(/}/g) || []).length);
	assert.ok(!minified.includes('/*'), 'clean-css must strip comments at level 2');

	// Key selectors the JS toggles at runtime must survive.
	assert.ok(minified.includes('.frcn-cookie-notice'));
	assert.ok(minified.includes('.frcn-cookie-notice--hidden'));
});

test('minifyJs produces syntactically valid, non-empty output for the settings script', async () => {
	const source = fs.readFileSync(settingsJsPath, 'utf8');
	const minified = await minifyJs(source);

	assert.ok(minified.length > 0);
	assert.ok(minified.length < source.length);
	assert.doesNotThrow(() => new vm.Script(minified));
	assert.ok(minified.includes('data-tab-target'), 'must still reference the tab button selector');
});

test('minifyCss produces syntactically valid, non-empty output for the settings stylesheet', () => {
	const source = fs.readFileSync(settingsCssPath, 'utf8');
	const minified = minifyCss(source);

	assert.ok(minified.length > 0);
	assert.ok(minified.length < source.length);
	assert.equal((minified.match(/{/g) || []).length, (minified.match(/}/g) || []).length);
	assert.ok(minified.includes('.frcn-settings-wrapper'));
});

test('toMinifiedPath appends .min before the .js/.css extension', () => {
	assert.equal(
		toMinifiedPath('assets/cookie-notice/frontconsent-cookie-notice.js'),
		'assets/cookie-notice/frontconsent-cookie-notice.min.js'
	);
	assert.equal(
		toMinifiedPath('assets/admin/settings.css'),
		'assets/admin/settings.min.css'
	);
});
