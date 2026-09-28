# AGENTS.md — FrontConsent

Guidelines for AI coding agents working on this WordPress plugin.

## Project Overview

**FrontConsent** is a free, standalone WordPress plugin providing a GDPR/ePrivacy-compliant cookie consent banner, following the guide published by Spain's AEPD (Agencia Española de Protección de Datos). It was extracted from FrontBlocks Site Tools' bundled Cookie Notice module so cookie consent has its own dedicated, easy-to-find plugin on WordPress.org. See `docs/plan.md` for the full product plan (market, competition, Free/Pro split, roadmap).

FrontConsent replaces FrontBlocks' Cookie Notice module entirely — the two are not meant to run side by side long-term. FrontConsent migrates a site's existing settings and stats away from FrontBlocks automatically on activation (see `includes/Migration.php`) and disables FrontBlocks' own banner so only one is ever shown. The planned PRO companion lives at `wp-content/plugins/frontconsent-pro/`.

- **PHP minimum:** 7.0
- **WordPress minimum:** 5.8
- **Namespace:** `FrontConsent\`
- **Prefix (constants/functions):** `FRCN_` / `frcn_` / `frontconsent_`
- **Text domain:** `frontconsent`

## Language

**Everything must be written in English** — no exceptions:
- All code: variable names, function names, class names, constants.
- All comments: inline, docblocks, and `/* translators: */` notes.
- All documentation: `AGENTS.md`, `CLAUDE.md`, `/docs/`, commit messages, PR descriptions.
- User-facing strings in `__()` / `esc_html__()` etc. are in English; translations are handled by `.po`/`.mo` files in `/languages/`.
- `docs/plan.md` is the one deliberate exception (a Spanish-language product/business document) — do not "fix" its language.

## Directory Structure

```
frontconsent/
├── frontconsent.php          # Plugin entry point, constants
├── includes/
│   ├── Plugin_Main.php       # Singleton loader
│   ├── Migration.php         # One-time migration from FrontBlocks' Cookie Notice module
│   ├── Admin/                # Admin-only classes (Settings)
│   └── Frontend/             # Frontend feature classes (CookieNotice)
├── assets/
│   ├── admin/                # Settings page CSS/JS
│   └── cookie-notice/        # Frontend banner CSS/JS
├── docs/                     # Documentation, including the product plan
├── tests/
├── vendor/                   # Composer dependencies (do not edit)
└── node_modules/             # npm dependencies (do not edit), if present
```

## Code Style

### PHP
- Follow **WordPress PHP Coding Standards** (`phpcs.xml.dist` is the source of truth).
- Use **tabs** for indentation, never spaces.
- Use **Yoda conditions** always: `if ( true === $var )`.
- Inline comments: start with a capital letter, end with a period. Comment chunks of functionality, not individual lines.
- All globals (functions, constants, hooks) must use one of the registered prefixes: `FRCN_`, `frcn_`, `FrontConsent`, `frontconsent_`.
- PSR-4 autoloading is active — class file names follow PSR-4, not WordPress hyphenated conventions.

### JavaScript
- **No jQuery.** Use Vanilla JavaScript.

### CSS
- Admin settings CSS is plain CSS, scoped to `.frcn-settings-wrapper` — no build step.
- Frontend banner CSS/JS ship as plain files in `assets/cookie-notice/` — no build step.

### Naming: Brand Capitalization
- Always write **FrontConsent** — capital F and C, one word, no space — in comments, docs, and user-facing strings. Never "Frontconsent", "Front Consent", or "frontconsent" in prose.
- File names, function prefixes, text domains, and CSS classes use lowercase: `frontconsent`, `frontconsent_`, `frcn-`.

## Development Commands

```bash
# PHP linting and formatting
composer lint          # phpcs — check coding standards
composer format        # phpcbf — auto-fix coding standards
composer phpstan        # static analysis
composer test           # PHPUnit (requires the WordPress test environment)
```

Always run `composer lint` and `composer phpstan` before considering PHP work done.

## WordPress Best Practices

- Use WordPress hooks (actions/filters) — never modify core or plugin files directly.
- Use `prepare()` for all database queries via `$wpdb`.
- Sanitize all input; escape all output with the appropriate WordPress escaping function.
- Use nonces for form submissions and AJAX requests (the Settings API's own nonce covers the settings form; AJAX endpoints define their own, e.g. `CookieNotice::NONCE_ACTION`).
- Use WordPress capabilities for authorization checks.

## Architecture

### Plugin Initialization Flow

1. **`frontconsent.php`** — entry point; defines constants (`FRCN_VERSION`, `FRCN_PLUGIN`, `FRCN_PLUGIN_URL`, `FRCN_PLUGIN_PATH`), loads the Composer autoloader, hooks `plugins_loaded` → singleton `FrontConsent\Plugin_Main::get_instance()`.
2. **`includes/Plugin_Main.php`** — singleton; runs `Migration::maybe_run()` once, then `load_modules()` instantiates the frontend and admin classes.
3. **`includes/Migration.php`** — one-time copy of Cookie Notice settings/stats from FrontBlocks' `frontblocks_settings` option into FrontConsent's own `frontconsent_settings` option, guarded by the `frcn_migrated_from_frontblocks` flag so it only ever runs once per site.
4. **`includes/Frontend/CookieNotice.php`** — the banner itself: renders the frontend markup/scripts, handles the AJAX endpoints, and reads/writes the `frontconsent_settings` option.
5. **`includes/Admin/Settings.php`** — settings page at `options-general.php?page=frontconsent-settings`.

### Settings Storage

All settings live in a single option, `frontconsent_settings`, keyed by field name (e.g. `enable_cookie_notice`, `cookie_notice_message`). This mirrors FrontBlocks' own `frontblocks_settings` option shape for the same keys, which is what makes the one-time migration in `Migration.php` a straightforward per-key copy.

## Adding New Features

1. Create a class in `includes/Admin/` (admin-only) or `includes/Frontend/` (frontend).
2. Register it in `Plugin_Main.php`.
3. Add assets in the matching `assets/` subdirectory.
4. Update `readme.txt` and `readme.md` with the new feature.
5. Add documentation to `/docs/` and link it from `readme.md` if useful.

## What NOT to Do

- Do not edit files inside `vendor/` or `node_modules/`.
- Do not use jQuery.
- Do not skip `phpcs` or `phpstan` checks.
- Do not use global functions without the required prefix.
- Do not write code, comments, or documentation (other than `docs/plan.md`) in any language other than English.
- Do not remove or weaken the migration in `includes/Migration.php` without also updating FrontBlocks' own Cookie Notice deprecation notice — the two are designed as a pair during the transition period.

## Build a release

Actions for making a release:
- Update the Stable Tag in `readme.txt` and the plugin version in its header and the `FRCN_VERSION` constant.
- Finalize the `= Unreleased =` changelog entries under the released version.
- Create the matching GitHub release and tag.

## Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of PHPUnit tests needed to ensure code quality and speed. Use `composer test -- --filter=<test-name>` for focused coverage.
