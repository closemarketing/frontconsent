# FrontConsent Repository for WordPress Plugin

Repository for FrontConsent, a WordPress plugin providing a GDPR/ePrivacy-compliant cookie consent banner, following the Spanish AEPD guide.

## Functionalities

- **Cookie Notice** — configurable cookie consent banner (bar/box/popup) that gates Google Tag Manager, GA4, and supported tracking integrations (Clientify, Brevo, ChatGPT Ads) behind consent, with Google Consent Mode v2 support for compatibility with Google Site Kit and other analytics plugins.
- **Cookie preferences panel** — a "Customize cookie settings" button (left of Reject/Accept) and the persistent reopen trigger both open a dialog with Accept all / Reject all / Save changes and a static "Strictly necessary" section, all recording the exact same consent cookie/Consent Mode update/logging as the binary Accept/Reject buttons. Extensible via the `frcn_cookie_preferences_categories` action and `frcn_cookie_consent_categories` filter so FrontConsent PRO can add per-category consent (Analytics, Marketing, etc.) without forking markup — see [docs/preferences-panel-hooks.md](docs/preferences-panel-hooks.md).
- **Acceptance stats** — aggregate accepted/rejected counter on the settings page.
- **Migration from FrontBlocks** — automatically imports settings and stats from FrontBlocks Site Tools' bundled Cookie Notice module, which FrontConsent replaces.
- **Extensible settings tabs** — the settings page renders as a tab shell (Settings, plus a PRO upsell tab by default). A companion plugin such as FrontConsent PRO can add its own tab via the `frontconsent_settings_tabs` filter and render its panel on the `frontconsent_settings_form_tab_panels` action (fields saved through the main form) or `frontconsent_settings_tab_panels` action (a panel with its own form, e.g. a License tab).

See [docs/plan.md](docs/plan.md) for the product plan (market, Free/Pro split, roadmap) and [AGENTS.md](AGENTS.md) for guidelines for AI coding agents working on this plugin.
