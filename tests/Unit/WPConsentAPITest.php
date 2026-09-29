<?php
/**
 * Tests for the WP Consent API integration (WPConsentAPI) — bridges
 * FrontConsent's own consent decisions onto the community-standard WP
 * Consent API (wp_set_consent_type() / wp_set_consent()), guarded throughout
 * by function_exists() since that API is an optional soft dependency.
 *
 * The WP Consent API plugin/library is not installed in this test
 * environment, so function_exists( 'wp_set_consent_type' ) and
 * function_exists( 'wp_set_consent' ) are genuinely false by default here —
 * exactly the "not installed" case WPConsentAPI must no-op under. Tests that
 * need those functions to exist instead define fake ones and run in an
 * isolated child process (@runInSeparateProcess), so the fakes never leak
 * into the rest of the suite and the "not installed" tests keep testing the
 * real absence of those functions rather than a reset mock.
 *
 * @package FrontConsent
 */

use FrontConsent\Frontend\CookieNotice;
use FrontConsent\Frontend\WPConsentAPI;
use Yoast\WPTestUtils\WPIntegration\TestCase;

class WPConsentAPITest extends TestCase {

	/**
	 * @var WPConsentAPI
	 */
	private $wp_consent_api;

	/**
	 * Calls captured by the fake wp_set_consent()/wp_set_consent_type()
	 * function stubs defined at the bottom of this file. Only populated
	 * inside the @runInSeparateProcess tests that define those fakes.
	 *
	 * @var array
	 */
	public static $calls = array();

	public function set_up() {
		parent::set_up();
		self::$calls          = array();
		$this->wp_consent_api = new WPConsentAPI();
	}

	public function tear_down() {
		self::$calls = array();
		parent::tear_down();
	}

	/**
	 * With the WP Consent API not installed (the default in this test
	 * environment — see this file's own docblock), both entry points must
	 * no-op safely rather than trigger a fatal "call to undefined function"
	 * error.
	 */
	public function test_register_consent_type_does_not_error_without_the_wp_consent_api() {
		$this->assertFalse( function_exists( 'wp_set_consent_type' ), 'Precondition: the WP Consent API must not be installed in this test environment.' );

		$this->wp_consent_api->register_consent_type();

		$this->assertSame( array(), self::$calls );
	}

	public function test_sync_consent_does_not_error_without_the_wp_consent_api() {
		$this->assertFalse( function_exists( 'wp_set_consent' ), 'Precondition: the WP Consent API must not be installed in this test environment.' );

		$this->wp_consent_api->sync_consent( 'analytics', true );

		$this->assertSame( array(), self::$calls );
	}

	/**
	 * FrontConsent always blocks tracking by default until the visitor
	 * actively accepts (CookieNotice::render_consent_mode_default() defaults
	 * Google Consent Mode to 'denied'), so it must always register itself
	 * as an 'optin' consent type, never 'optout'.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_register_consent_type_reports_optin() {
		frontconsent_tests_define_wp_consent_api_fakes();

		$this->wp_consent_api->register_consent_type();

		$this->assertSame( array( array( 'wp_set_consent_type', 'optin' ) ), self::$calls );
	}

	/**
	 * FrontConsent's own 'analytics' category maps onto the WP Consent
	 * API's own standard 'statistics' category slug.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_sync_consent_maps_analytics_to_statistics_when_granted() {
		frontconsent_tests_define_wp_consent_api_fakes();

		$this->wp_consent_api->sync_consent( 'analytics', true );

		$this->assertSame( array( array( 'wp_set_consent', 'statistics', 'allow' ) ), self::$calls );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_sync_consent_maps_analytics_to_statistics_when_denied() {
		frontconsent_tests_define_wp_consent_api_fakes();

		$this->wp_consent_api->sync_consent( 'analytics', false );

		$this->assertSame( array( array( 'wp_set_consent', 'statistics', 'deny' ) ), self::$calls );
	}

	/**
	 * FrontConsent's own 'marketing' category is spelled the same on the WP
	 * Consent API's side, so it passes through unchanged.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_sync_consent_passes_marketing_through_unchanged() {
		frontconsent_tests_define_wp_consent_api_fakes();

		$this->wp_consent_api->sync_consent( 'marketing', true );

		$this->assertSame( array( array( 'wp_set_consent', 'marketing', 'allow' ) ), self::$calls );
	}

	/**
	 * An unmapped category slug (e.g. a future FrontConsent PRO category
	 * with no explicit WP Consent API equivalent yet) still passes straight
	 * through as-is, rather than being silently dropped.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_sync_consent_passes_through_an_unmapped_category() {
		frontconsent_tests_define_wp_consent_api_fakes();

		$this->wp_consent_api->sync_consent( 'preferences', true );

		$this->assertSame( array( array( 'wp_set_consent', 'preferences', 'allow' ) ), self::$calls );
	}

	/**
	 * CookieNotice::fire_consent_updated_action() fans a visitor's single
	 * accept/reject decision out into one 'frontconsent_consent_updated'
	 * action per CookieNotice::CONSENT_CATEGORIES slug. Wiring the two
	 * classes together via that action (as Plugin_Main does) must result in
	 * one wp_set_consent() call per category.
	 *
	 * Goes through process_consent_form_submission() (the no-JS <form>
	 * fallback's own decision path) rather than the AJAX log_consent_callback(),
	 * which calls wp_send_json_success() -> wp_die() and is not safe to run
	 * inside this test's @runInSeparateProcess child process. Both paths call
	 * the same fire_consent_updated_action() this test cares about.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_full_consent_decision_syncs_every_category() {
		frontconsent_tests_define_wp_consent_api_fakes();

		update_option( 'frontconsent_settings', array( 'enable_cookie_notice' => true ) );
		$cookie_notice = new CookieNotice();

		$_POST['frcn_decision'] = 'accepted';

		$cookie_notice->process_consent_form_submission();

		unset( $_POST['frcn_decision'] );
		delete_option( 'frontconsent_settings' );

		$this->assertContains( array( 'wp_set_consent', 'statistics', 'allow' ), self::$calls );
		$this->assertContains( array( 'wp_set_consent', 'marketing', 'allow' ), self::$calls );
	}
}

/**
 * Define fake wp_set_consent_type()/wp_set_consent() functions that record
 * their calls onto WPConsentAPITest::$calls — only ever called from within a
 * @runInSeparateProcess test method, so the fakes never leak into the rest
 * of the suite (see this file's own top docblock).
 *
 * @return void
 */
function frontconsent_tests_define_wp_consent_api_fakes() {
	if ( ! function_exists( 'wp_set_consent_type' ) ) {
		function wp_set_consent_type( $type ) {
			WPConsentAPITest::$calls[] = array( 'wp_set_consent_type', $type );
		}
	}

	if ( ! function_exists( 'wp_set_consent' ) ) {
		function wp_set_consent( $category, $value ) {
			WPConsentAPITest::$calls[] = array( 'wp_set_consent', $category, $value );
		}
	}
}
