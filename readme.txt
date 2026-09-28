=== FrontConsent ===
Contributors: davidperez, sacrajaimez, alexcm13, closetechnology
Tags: cookies, consent, gdpr, cookie notice, aepd
Requires at least: 5.8
Tested up to: 7.1
Stable tag: 1.1.0
Version: 1.1.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

GDPR/ePrivacy-compliant cookie consent banner for WordPress, following the Spanish AEPD guide.

== Description ==

FrontConsent shows a lightweight, configurable cookie consent banner with Accept and Reject actions, following the guide published by Spain's AEPD (Agencia Española de Protección de Datos) as well as GDPR/ePrivacy requirements. Rejecting costs the visitor the same number of clicks as accepting, no non-essential cookie is set before the visitor decides, and the decision can be changed at any time.

Choose between a full-width bottom bar, a boxed panel (bottom-left or bottom-right) or a centered popup, and customize the message, button labels, cookie policy page, accent color and cookie expiration.

**Google Consent Mode v2:**
Google Tag Manager and/or GA4 are only requested and loaded after a visitor accepts — never before — and returning visitors who already accepted get the scripts on normal page load. Implements Google Consent Mode v2, so it also holds back tracking from other analytics/ads plugins that respect it — including **Google Site Kit** — until the visitor decides.

**Tracking integrations:**
Paste a Google Tag Manager or GA4 ID (or install snippet), or a Clientify, Brevo or ChatGPT Ads snippet, and FrontConsent automatically detects which tool it belongs to and only loads it after consent.

**Acceptance stats:**
The settings page shows a simple accepted/rejected acceptance-rate stat (site administrators are excluded from the count).

**Advanced Cookie Management (FrontConsent PRO):**
Extend Cookie Notice with separate Necessary, Analytics and Marketing preferences, a customizable preferences dialog and a reusable trigger that lets visitors update their choices later. Google Ads, Meta Pixel and Microsoft Clarity only run after the relevant category is accepted. It also supports the official Meta Pixel for WordPress plugin by holding its Pixel and Conversions API signals until Marketing consent.

More information in the [FrontConsent PRO](https://close.technology/en/wordpress-plugins/frontconsent-pro/?utm_source=WordPressORGReadme&utm_medium=link&utm_campaign=frontconsent) page.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/frontconsent` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Configure the banner under Settings → FrontConsent.

== Frequently Asked Questions ==

= I was using FrontBlocks' Cookie Notice. What happens now? =

Activating FrontConsent copies your existing Cookie Notice settings and acceptance/rejection stats from FrontBlocks into FrontConsent automatically, and turns off FrontBlocks' own banner so only one is ever shown.

= Does this store consent records for audit purposes? =

The free version keeps a simple aggregate accepted/rejected count. Per-visitor consent logging and cookie scanning are part of the planned Pro version.

== External services ==

This plugin only connects to external services that the site owner explicitly configures — none are contacted by default. All connections below happen entirely in the visitor's own browser, after that visitor has accepted the relevant cookie category; no data is ever sent from the server.

**Google Tag Manager / Google Analytics 4 (GA4)**
If a site administrator enters a Google Tag Manager container ID or a GA4 Measurement ID in the plugin settings, the visitor's browser loads the corresponding Google script (`https://www.googletagmanager.com/gtm.js` or `https://www.googletagmanager.com/gtag/js`) once the visitor accepts. This sends standard Google Tag Manager/Analytics data (e.g. page views, and any events the site's own tag configuration adds) to Google.
Service provided by Google: [Terms of Service](https://marketingplatform.google.com/about/analytics/terms/us/), [Privacy Policy](https://policies.google.com/privacy).

**Clientify Analytics (Plus or classic)**
If a site administrator adds a Clientify Analytics tracking ID, the visitor's browser loads Clientify's tracking script (`https://analyticsplusdev.clientify.net` or `https://analytics.clientify.net`) once the visitor accepts, sending page-view and visitor tracking data to Clientify.
Service provided by Clientify: [Terms of Service](https://clientify.com/terminos-y-condiciones/), [Privacy Policy](https://clientify.com/politica-de-privacidad/).

**Brevo**
If a site administrator adds a Brevo tracking snippet, the visitor's browser loads Brevo's SDK (`https://cdn.brevo.com/js/sdk-loader.js`) once the visitor accepts, sending visitor tracking data to Brevo.
Service provided by Brevo: [Terms of Service](https://www.brevo.com/legal/termsofuse/), [Privacy Policy](https://www.brevo.com/legal/privacypolicy/).

**ChatGPT Ads (OpenAI)**
If a site administrator adds a ChatGPT Ads Pixel ID, the visitor's browser loads OpenAI's ads pixel script (`https://bzrcdn.openai.com/sdk/oaiq.min.js`) once the visitor accepts, sending conversion/attribution tracking data to OpenAI.
Service provided by OpenAI: [Terms of Use](https://openai.com/policies/terms-of-use/), [Privacy Policy](https://openai.com/policies/privacy-policy/).

== Changelog ==

= 1.1.0 =
* Settings page now uses tabs, so a companion plugin can add its own tab via the new `frontconsent_settings_tabs` filter, rendering its panel on either `frontconsent_settings_form_tab_panels` (fields saved through the main settings form) or `frontconsent_settings_tab_panels` (a panel with its own form, e.g. FrontConsent PRO's License tab).
* Added a FrontConsent PRO upsell tab and an inline promo link on the Cookie Notice tab, shown only when FrontConsent PRO isn't installed.

= 1.0.0 =
* Initial release: cookie consent banner (full-width bar, boxed panel or centered popup) with Accept/Reject actions, custom message, accent color and expiration, extracted from FrontBlocks Site Tools' Cookie Notice module.
* Google Tag Manager and GA4 only load after consent is accepted; Google Consent Mode v2 support holds back tracking from other analytics/ads plugins (including Google Site Kit) until the visitor decides.
* Automatic migration of settings and stats from FrontBlocks' bundled Cookie Notice module.
