<?php
/**
 * WP Consent API integration for FrontConsent.
 *
 * @package    FrontConsent
 * @author     Closemarketing
 * @copyright  2026 Closemarketing
 * @version    1.0
 */

namespace FrontConsent\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * WPConsentAPI class.
 *
 * Bridges FrontConsent's own consent decisions onto the community-standard
 * WP Consent API (https://github.com/rlankhorst/wp-consent-level-api), so
 * other plugins can query a visitor's consent via wp_has_consent() instead
 * of needing bespoke FrontConsent-specific integration code.
 *
 * The WP Consent API is an optional soft dependency — every call into it is
 * guarded by function_exists(), so FrontConsent works exactly the same,
 * with or without that plugin/library installed.
 *
 * @since 1.0.0
 */
class WPConsentAPI {

	/**
	 * Map of FrontConsent's own consent category slugs (CookieNotice::CONSENT_CATEGORIES)
	 * to the WP Consent API's own standard category slugs.
	 *
	 * 'analytics' maps to the WP Consent API's 'statistics' category —
	 * FrontConsent's own naming (shared with Google Consent Mode's
	 * 'analytics_storage') predates and differs from the WP Consent API's.
	 * 'marketing' is spelled the same on both sides, so it passes through
	 * unchanged, but is still listed here explicitly for clarity.
	 *
	 * @var array<string, string>
	 */
	const CATEGORY_MAP = array(
		'analytics' => 'statistics',
		'marketing' => 'marketing',
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		// 'init' is the earliest hook the WP Consent API itself registers its
		// own consent-type default on, so registering FrontConsent's own
		// default consent type here is guaranteed to run no earlier than the
		// API is ready for it, on every request, not just the initial decision.
		add_action( 'init', array( $this, 'register_consent_type' ) );

		// CookieNotice::fire_consent_updated_action() fires this once per
		// consent category whenever a visitor's decision is recorded, from
		// both the AJAX and no-JS form fallback paths.
		add_action( 'frontconsent_consent_updated', array( $this, 'sync_consent' ), 10, 2 );
	}

	/**
	 * Register FrontConsent's own default consent type with the WP Consent API.
	 *
	 * FrontConsent always blocks tracking by default until the visitor
	 * actively accepts (see CookieNotice::render_consent_mode_default(),
	 * which defaults Google Consent Mode to 'denied'), so it always reports
	 * itself as an 'optin' consent type — never 'optout'.
	 *
	 * No-ops when the WP Consent API isn't active.
	 *
	 * @return void
	 */
	public function register_consent_type() {
		if ( ! function_exists( 'wp_set_consent_type' ) ) {
			return;
		}

		wp_set_consent_type( 'optin' );
	}

	/**
	 * Sync a FrontConsent category decision onto the WP Consent API.
	 *
	 * No-ops when the WP Consent API isn't active.
	 *
	 * @param string $category FrontConsent's own consent category slug, e.g. 'analytics' or 'marketing'.
	 * @param bool   $status   True when consent was granted, false when denied.
	 * @return void
	 */
	public function sync_consent( $category, $status ) {
		if ( ! function_exists( 'wp_set_consent' ) ) {
			return;
		}

		$wp_consent_category = self::CATEGORY_MAP[ $category ] ?? $category;

		wp_set_consent( $wp_consent_category, $status ? 'allow' : 'deny' );
	}
}
