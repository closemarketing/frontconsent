# FrontConsent Repository for WordPress Plugin

Repository for FrontConsent, a WordPress plugin providing a GDPR/ePrivacy-compliant cookie consent banner, following the Spanish AEPD guide.

## Functionalities

- **Cookie Notice** — configurable cookie consent banner (bar/box/popup) that gates Google Tag Manager, GA4, and supported tracking integrations (Clientify, Brevo, ChatGPT Ads) behind consent, with Google Consent Mode v2 support for compatibility with Google Site Kit and other analytics plugins.
- **Acceptance stats** — aggregate accepted/rejected counter on the settings page.
- **Script & Iframe Blocking** — a settings-configured list of match-pattern/category rules holds back any matching `<script src="...">` or `<iframe src="...">` (YouTube, Maps, social widgets, custom tracking snippets, etc.) until the visitor accepts, via output-buffered HTML rewriting server-side and placeholder revival client-side.
- **Migration from FrontBlocks** — automatically imports settings and stats from FrontBlocks Site Tools' bundled Cookie Notice module, which FrontConsent replaces.
- **Extensible settings tabs** — the settings page renders as a tab shell (Settings, plus a PRO upsell tab by default). A companion plugin such as FrontConsent PRO can add its own tab via the `frontconsent_settings_tabs` filter and render its panel on the `frontconsent_settings_form_tab_panels` action (fields saved through the main form) or `frontconsent_settings_tab_panels` action (a panel with its own form, e.g. a License tab).

See [docs/plan.md](docs/plan.md) for the product plan (market, Free/Pro split, roadmap) and [AGENTS.md](AGENTS.md) for guidelines for AI coding agents working on this plugin.
