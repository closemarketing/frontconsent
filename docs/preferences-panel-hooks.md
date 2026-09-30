# Cookie preferences panel: hooks and filters

This documents the extension points added for the "Customize cookie settings"
button and the cookie preferences panel it (and the persistent reopen
trigger) opens — see GitHub issue #11. They exist so **FrontConsent PRO** can
add per-category consent (Necessary, Preferences, Analytics, Marketing, …)
into the same panel without forking any markup, and so any other add-on can
do the same.

All code lives in `includes/Frontend/CookieNotice.php`.

## `frcn_cookie_notice_before_actions` (action, existing)

```php
do_action( 'frcn_cookie_notice_before_actions', $options );
```

Fires inside the banner's actions row, immediately before the Reject/Accept
buttons. This hook already existed before issue #11 (added for the reopen
trigger a11y work) and is exactly where the Free tier's own "Customize cookie
settings" button (`CookieNotice::render_customize_button()`) is printed —
registered on it unconditionally in the constructor. A PRO add-on can hook
the same action, at an earlier or later priority, to print its own trigger
here instead, or alongside it.

- **When it fires:** while `render_banner_markup()` renders the banner, once
  per page load, for every visitor (the output is cache-neutral — see the
  class docblock).
- **Data available:** `$options` — the full `frontconsent_settings` option
  array.

## `frcn_cookie_preferences_categories` (action, new)

```php
do_action( 'frcn_cookie_preferences_categories', $options );
```

Fires inside the cookie preferences panel (`#frcn-cookie-preferences`),
right after the static, always-on "Strictly necessary" section and before the
Reject all / Save changes / Accept all buttons.

FrontConsent PRO hooks here to render its own additional per-category toggles
(e.g. Preferences), each with a description and, optionally, its cookie list.
Nothing is printed on this specific action in the Free tier — the Free
tier's own two built-in toggles (Analytics, Marketing; see "The Free tier's
own built-in categories" below) are rendered directly in
`render_preferences_panel()`, before this action fires, not through it —
without PRO (or any other add-on) active, nothing *extra* is added beyond
those two, per the issue's requirement that no disabled/teaser UI clutters
the panel by default.

**Contract for anything hooked here:** any toggle intended to be read back by
"Save changes" must be a checkable control (e.g. `<input type="checkbox">`)
carrying a `data-frcn-category="<slug>"` attribute —
`frontconsent-cookie-notice.js`'s `collectPreferencesCategories()` reads every
element matching `[data-frcn-category]` inside the panel and builds the
category map from their `checked` state.

- **When it fires:** while `render_preferences_panel()` renders the panel,
  once per page load, for every visitor (also cache-neutral).
- **Data available:** `$options` — the full `frontconsent_settings` option
  array.

## `frcn_cookie_consent_categories` (filter, new)

```php
$categories = apply_filters( 'frcn_cookie_consent_categories', $categories, $decision );
```

Applied inside `log_consent_callback()` (the AJAX endpoint that records a
visitor's decision) around the per-category consent map, right before it's
returned in the JSON response as `data.categories`. This is the extension
point that lets an add-on **persist and read** per-category state alongside
the existing binary accepted/rejected cookie.

- **When it fires:** every time a decision is recorded — from the banner's
  own Accept/Reject buttons or from the preferences panel's Accept
  all/Reject all/Save changes buttons, since they all funnel through the
  same `log_consent_callback()` (no parallel consent-recording logic).
- **Data available:**
  - `$categories` (`array<string, mixed>`) — whatever the visitor's browser
    submitted in the `categories` POST field (a JSON object, e.g.
    `{"analytics": true, "marketing": false}`), decoded and sanitized to
    `sanitize_key()`'d string keys and boolean values. Empty when nothing
    was submitted (e.g. the binary Accept/Reject buttons, which never send a
    `categories` field).
  - `$decision` (`string`) — `'accepted'` or `'rejected'`, the binary
    decision being recorded alongside it.
- **Forward compatibility:** the existing binary accepted/rejected cookie and
  aggregate stat keep working completely unmodified whether or not anything
  is hooked here — the category map is optional and purely additive, never
  required.

## `frcn_cookie_customize_button_label` (filter, new)

```php
$label = apply_filters( 'frcn_cookie_customize_button_label', __( 'Customize cookie settings', 'frontconsent' ), $options );
```

Filters the "Customize cookie settings" button's visible label. Runs inside
`render_customize_button()`.

## `frcn_cookie_customize_button_enabled` (filter, new)

```php
$enabled = apply_filters( 'frcn_cookie_customize_button_enabled', true, $options );
```

Filters whether the "Customize cookie settings" button is printed at all.
Return `false` to hide it — e.g. a PRO tier that replaces it with its own
trigger printed on the same `frcn_cookie_notice_before_actions` action. Runs
inside `render_customize_button()`, before the label filter above.

## Example: a minimal PRO-style add-on

```php
// Render a single "Analytics" toggle inside the preferences panel.
add_action( 'frcn_cookie_preferences_categories', function ( $options ) {
	?>
	<div class="frcn-cookie-preferences__category">
		<label>
			<input type="checkbox" data-frcn-category="analytics" />
			<?php esc_html_e( 'Analytics', 'my-addon' ); ?>
		</label>
	</div>
	<?php
} );

// Persist the submitted category state in the add-on's own option/cookie.
add_filter( 'frcn_cookie_consent_categories', function ( $categories, $decision ) {
	my_addon_store_categories( $categories, $decision );
	return $categories;
}, 10, 2 );
```

## The Free tier's own built-in categories: Analytics and Marketing

The Free tier ships two real, independently-controllable toggles in the
panel — `data-frcn-category="analytics"` and `data-frcn-category="marketing"`
— not a single combined checkbox, because they gate genuinely different
integrations (see `CookieNotice::get_integration_default_category()`: GTM/GA4
default to `'analytics'`; Clientify/Brevo/OpenAI ads default to
`'marketing'`). Unchecking one and leaving the other checked actually changes
what loads on the next page load, not just what the panel displays.

This is wired end to end without any PRO add-on:

1. `frontconsent-cookie-notice.js`'s `collectPreferencesCategories()` reads
   both toggles' checked state on Save changes / Accept all / Reject all.
2. `setCategoriesCookie()` persists that selection into its own cookie —
   `frontconsent_categories` (or `frontconsent_categories_<blog_id>` on
   multisite — see `CookieNotice::get_categories_cookie_name()`) — as JSON,
   e.g. `{"analytics":true,"marketing":false}`. It's written with the same
   path/max-age/`SameSite=Lax` conventions as the existing binary consent
   cookie, just under a different name, and is sent to the server
   automatically via the normal cookie header (no new AJAX plumbing needed).
3. `CookieNotice::get_config_callback()` reads that cookie server-side
   (`get_allowed_categories_default()`), JSON-decodes it defensively (never
   fatals on malformed/tampered input), and uses it to build the real
   `allowedCategories` map returned to the frontend — still run through the
   existing `frcn_cookie_notice_allowed_tracking_categories` filter, so a PRO
   add-on can still override it.
4. `frcnCookieNoticeInject()` (in the registered script and its inline
   wp_head bootstrap copy) was already able to gate on a real
   `allowedCategories` map — this only had to stop being permanently `null`
   in the Free tier.

**Backward compatibility:** a visitor who already accepted before this
per-category cookie existed has no `frontconsent_categories` cookie yet.
Previously `'accepted'` meant everything loaded (`allowedCategories` was
always `null`, i.e. allow-all) — so a missing or unparseable/tampered
categories cookie falls back to allowing both known categories for an
already-accepted visitor, never regressing them to losing tracking they
already consented to. Granular blocking only starts once a visitor has
actually gone through the panel and this cookie exists with real per-category
data.

## Client-side: `frontconsent-cookie-notice.js`

The preferences panel (`#frcn-cookie-preferences`) is printed unconditionally
and hidden by default (`hidden` attribute); the script:

- Opens it from either the "Customize cookie settings" button
  (`[data-frcn-cookie-action="customize"]`) or the persistent reopen trigger
  (`#frcn-cookie-reopen`), moving focus inside and trapping Tab/Shift+Tab
  within the panel's own controls.
- Closes it on Escape or its close button
  (`[data-frcn-cookie-action="close-preferences"]`), restoring focus to
  whichever trigger opened it.
- Routes its Accept all (`[data-frcn-cookie-action="accept"]`), Reject all
  (`[data-frcn-cookie-action="reject"]`) and Save changes
  (`[data-frcn-cookie-action="save"]`) buttons through the exact same
  decision-recording function (`handleDecision()`) the banner's own
  Accept/Reject buttons use — same cookie, same Consent Mode update, same
  logging AJAX call.
- "Save changes" reads every `[data-frcn-category]` control in the panel and
  submits it as the `categories` field alongside the binary decision it
  implies (accepted if any non-necessary category is checked, rejected
  otherwise).

## No-JS behavior

Without JavaScript, opening/trapping/closing a dialog isn't possible, so the
"Customize cookie settings" button is hidden entirely via a `<noscript>`
style rule (`render_banner_markup()`'s own `print_noscript_style()` call) —
the existing no-JS `<form>` fallback still lets a visitor Accept/Reject via
the banner's own buttons regardless.
