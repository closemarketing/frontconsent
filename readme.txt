=== FrontConsent ===
Contributors: davidperez, sacrajaimez, alexcm13
Tags: cookies, consent, gdpr, cookie notice, aepd
Donate link: https://close.marketing/go/donate/
Requires at least: 5.8
Tested up to: 7.1
Stable tag: 1.0.0
Version: 1.0.0
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

== Changelog ==

= 1.0.0 =
* Initial release: cookie consent banner (full-width bar, boxed panel or centered popup) with Accept/Reject actions, custom message, accent color and expiration, extracted from FrontBlocks Site Tools' Cookie Notice module.
* Google Tag Manager and GA4 only load after consent is accepted; Google Consent Mode v2 support holds back tracking from other analytics/ads plugins (including Google Site Kit) until the visitor decides.
* Automatic migration of settings and stats from FrontBlocks' bundled Cookie Notice module.
