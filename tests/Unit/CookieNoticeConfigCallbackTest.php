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
		unset( $_COOKIE['frcn_cookie_consent'] );
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
}
