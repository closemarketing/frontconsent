const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const cookieNoticeCssPath = path.resolve(__dirname, '../../assets/cookie-notice/frontconsent-cookie-notice.css');

/**
 * Regression test for a real bug: an element rendered with the `hidden`
 * HTML attribute (see CookieNotice::render_reopen_trigger() and
 * ::render_preferences_panel()) only actually stays hidden if the plugin's
 * own CSS doesn't set `display` on it without also handling `[hidden]` —
 * an author `display: flex`/`block` rule always wins over the browser's
 * built-in `[hidden] { display: none }` rule regardless of selector
 * specificity, so every selector that sets `display` on one of these
 * cache-neutral, hidden-by-default elements must carry a matching
 * `[hidden] { display: none }` override.
 */
test('every hidden-by-default cookie-notice element has a matching [hidden] override', () => {
	const css = fs.readFileSync(cookieNoticeCssPath, 'utf8');

	assert.match(css, /\.frcn-cookie-reopen\[hidden\]\s*\{[^}]*display:\s*none/);
	assert.match(css, /\.frcn-cookie-preferences\[hidden\]\s*\{[^}]*display:\s*none/);
});
