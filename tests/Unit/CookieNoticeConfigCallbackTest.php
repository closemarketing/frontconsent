<?php
/**
 * Tests for CookieNotice::get_config_callback() — the read-only AJAX endpoint
 * that hands an already-accepted visitor the GTM/GA4 ids and additional
 * tracking integration records to inject.
 *
 * @package FrontConsent
 */

use FrontConsent\Frontend\CookieNotice;
use Yoast\WPTestUtils\WPIntegration\TestCase;

class CookieNoticeConfigCallbackTest extends TestCase {

	/**
	 * @var CookieNotice
	 */
	private $cookie_notice;

	public function set_up() {
		parent::set_up();
		$this->cookie_notice = new CookieNotice();

		update_option( 'frontconsent_settings', array( 'enable_cookie_notice' => true ) );
		$_COOKIE['frcn_cookie_consent'] = 'accepted';

		// wp_send_json_success() only routes through the interceptable
		// wp_die() below when the request is treated as an Ajax one; filtered
		// rather than defining the DOING_AJAX constant so it cannot leak into
		// any other test running later in the same process.
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'get_die_handler' ) );
	}

	public function tear_down() {
		remove_filter( 'wp_die_ajax_handler', array( $this, 'get_die_handler' ) );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		unset( $_COOKIE['frcn_cookie_consent'], $_COOKIE['frontconsent_categories'] );
		delete_option( 'frontconsent_settings' );
		parent::tear_down();
	}

	/**
	 * A wp_die() handler that throws instead of terminating the process, the
	 * same technique WP core's own WP_Ajax_UnitTestCase uses to make
	 * wp_send_json_success()/wp_die() testable.
	 *
	 * @return callable
	 */
	public function get_die_handler() {
		return static function ( $message ) {
			throw new Exception( is_scalar( $message ) ? (string) $message : 'die' );
		};
	}

	/**
	 * Invoke get_config_callback() and decode its JSON response.
	 *
	 * @return array
	 */
	private function get_config() {
		ob_start();

		try {
			$this->cookie_notice->get_config_callback();
		} catch ( Exception $e ) {
			unset( $e );
		}

		$output = ob_get_clean();

		return json_decode( $output, true );
	}

	public function test_gtm_and_ga4_entries_are_surfaced_as_dedicated_response_keys() {
		update_option(
			'frontconsent_settings',
			array(
				'enable_cookie_notice'                => true,
				'cookie_notice_tracking_integrations' => array(
					array( 'type' => 'gtm', 'id' => 'GTM-ABC1234' ),
					array( 'type' => 'ga4', 'id' => 'G-ABC1234567' ),
				),
			)
		);

		$response = $this->get_config();

		$this->assertSame( 'GTM-ABC1234', $response['data']['gtmId'] );
		$this->assertSame( 'G-ABC1234567', $response['data']['ga4Id'] );
	}

	public function test_gtm_and_ga4_are_excluded_from_the_generic_tracking_integrations_list() {
		update_option(
			'frontconsent_settings',
			array(
				'enable_cookie_notice'                => true,
				'cookie_notice_tracking_integrations' => array(
					array( 'type' => 'gtm', 'id' => 'GTM-ABC1234' ),
					array( 'type' => 'ga4', 'id' => 'G-ABC1234567' ),
					array( 'type' => 'brevo', 'id' => 'brevo-key' ),
				),
			)
		);

		$response = $this->get_config();
		$types    = wp_list_pluck( $response['data']['trackingIntegrations'], 'type' );

		$this->assertNotContains( 'gtm', $types );
		$this->assertNotContains( 'ga4', $types );
		$this->assertContains( 'brevo', $types );
	}

	public function test_gtm_id_is_suppressed_when_google_site_kit_manages_the_tag() {
		if ( ! defined( 'GOOGLESITEKIT_VERSION' ) ) {
			define( 'GOOGLESITEKIT_VERSION', '1.0.0' );
		}

		update_option(
			'frontconsent_settings',
			array(
				'enable_cookie_notice'                => true,
				'cookie_notice_tracking_integrations' => array(
					array( 'type' => 'gtm', 'id' => 'GTM-ABC1234' ),
				),
			)
		);
		update_option(
			'googlesitekit_tagmanager_settings',
			array(
				'containerID' => 'GTM-ABC1234',
				'useSnippet'  => true,
			)
		);

		$response = $this->get_config();

		$this->assertSame( '', $response['data']['gtmId'] );

		delete_option( 'googlesitekit_tagmanager_settings' );
	}

	/**
	 * Site Kit managing a different GTM container must not suppress a
	 * distinct one the admin configured in FrontConsent — only an identical
	 * ID is a duplicate.
	 */
	public function test_gtm_id_still_loads_when_site_kit_manages_a_different_container() {
		if ( ! defined( 'GOOGLESITEKIT_VERSION' ) ) {
			define( 'GOOGLESITEKIT_VERSION', '1.0.0' );
		}

		update_option(
			'frontconsent_settings',
			array(
				'enable_cookie_notice'                => true,
				'cookie_notice_tracking_integrations' => array(
					array( 'type' => 'gtm', 'id' => 'GTM-ABC1234' ),
				),
			)
		);
		update_option(
			'googlesitekit_tagmanager_settings',
			array(
				'containerID' => 'GTM-DIFFERENT9',
				'useSnippet'  => true,
			)
		);

		$response = $this->get_config();

		$this->assertSame( 'GTM-ABC1234', $response['data']['gtmId'] );

		delete_option( 'googlesitekit_tagmanager_settings' );
	}

	/**
	 * A well-formed frontconsent_categories cookie is decoded and drives
	 * allowedCategories exactly — this is the real, functionally meaningful
	 * gate: analytics allowed, marketing denied.
	 */
	public function test_allowed_categories_reflect_a_well_formed_categories_cookie() {
		$_COOKIE['frontconsent_categories'] = wp_json_encode(
			array(
				'analytics' => true,
				'marketing' => false,
			)
		);

		$response = $this->get_config();

		$this->assertSame(
			array(
				'analytics' => true,
				'marketing' => false,
			),
			$response['data']['allowedCategories']
		);
	}

	/**
	 * Backward compatibility: a visitor who already accepted before this
	 * per-category cookie existed has no frontconsent_categories cookie at
	 * all. They must not be regressed to losing already-loaded tracking —
	 * allowedCategories falls back to allowing both known categories, the
	 * same "accepted = everything loads" reality as before this feature.
	 */
	public function test_allowed_categories_default_to_both_allowed_when_no_categories_cookie_exists_but_binary_consent_is_accepted() {
		unset( $_COOKIE['frontconsent_categories'] );

		$response = $this->get_config();

		$this->assertSame(
			array(
				'analytics' => true,
				'marketing' => true,
			),
			$response['data']['allowedCategories']
		);
	}

	/**
	 * A malformed/tampered categories cookie (invalid JSON, or valid JSON
	 * that isn't an object/array) must never fatal or warn — it falls back
	 * to the exact same safe default as no cookie at all.
	 */
	public function test_malformed_categories_cookie_falls_back_to_the_same_default_as_no_cookie() {
		$_COOKIE['frontconsent_categories'] = 'not-valid-json{{{';

		$response = $this->get_config();

		$this->assertSame(
			array(
				'analytics' => true,
				'marketing' => true,
			),
			$response['data']['allowedCategories']
		);

		$_COOKIE['frontconsent_categories'] = wp_json_encode( 'a plain string, not an object' );

		$response = $this->get_config();

		$this->assertSame(
			array(
				'analytics' => true,
				'marketing' => true,
			),
			$response['data']['allowedCategories']
		);
	}

	/**
	 * A rejected binary consent must keep short-circuiting before any of the
	 * category-reading logic even matters for the tracking payload itself —
	 * confirms the existing $has_tracking_consent gate stays intact: no GTM/
	 * GA4 id and no tracking integrations are ever returned for a rejected
	 * visitor, regardless of what the categories cookie says.
	 */
	public function test_rejected_binary_consent_still_gates_tracking_payload_regardless_of_categories_cookie() {
		$_COOKIE['frcn_cookie_consent']     = 'rejected';
		$_COOKIE['frontconsent_categories'] = wp_json_encode(
			array(
				'analytics' => true,
				'marketing' => true,
			)
		);

		update_option(
			'frontconsent_settings',
			array(
				'enable_cookie_notice'                => true,
				'cookie_notice_tracking_integrations' => array(
					array( 'type' => 'gtm', 'id' => 'GTM-ABC1234' ),
				),
			)
		);

		$response = $this->get_config();

		$this->assertSame( '', $response['data']['gtmId'] );
		$this->assertSame( array(), $response['data']['trackingIntegrations'] );
	}
}
