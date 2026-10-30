<?php
/**
 * Tests for the frcn_cookie_consent_categories filter — the PRO extension
 * point that lets an add-on persist/read a per-category consent map
 * alongside the binary accepted/rejected decision recorded by
 * CookieNotice::log_consent_callback() (see GitHub issue #11).
 *
 * @package FrontConsent
 */

use FrontConsent\Frontend\CookieNotice;
use Yoast\WPTestUtils\WPIntegration\TestCase;

class CookieNoticeConsentCategoriesTest extends TestCase {

	/**
	 * @var CookieNotice
	 */
	private $cookie_notice;

	public function set_up() {
		parent::set_up();
		$this->cookie_notice = new CookieNotice();

		update_option( 'frontconsent_settings', array( 'enable_cookie_notice' => true ) );

		// wp_send_json_success() only routes through the interceptable
		// wp_die() below when the request is treated as an Ajax one.
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'get_die_handler' ) );

		$_POST['nonce'] = wp_create_nonce( CookieNotice::NONCE_ACTION );
	}

	public function tear_down() {
		remove_filter( 'wp_die_ajax_handler', array( $this, 'get_die_handler' ) );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		unset( $_POST['nonce'], $_POST['decision'], $_POST['categories'] );
		delete_option( 'frontconsent_settings' );
		parent::tear_down();
	}

	/**
	 * @return callable
	 */
	public function get_die_handler() {
		return static function ( $message ) {
			throw new Exception( is_scalar( $message ) ? (string) $message : 'die' );
		};
	}

	/**
	 * Invoke log_consent_callback() and decode its JSON response.
	 *
	 * @return array
	 */
	private function log_consent() {
		ob_start();

		try {
			$this->cookie_notice->log_consent_callback();
		} catch ( Exception $e ) {
			unset( $e );
		}

		$output = ob_get_clean();

		return json_decode( $output, true );
	}

	/**
	 * With nothing hooked in, the response still carries an (empty) array —
	 * the binary decision itself never depends on this.
	 */
	public function test_categories_default_to_an_empty_array() {
		$_POST['decision'] = 'accepted';

		$response = $this->log_consent();

		$this->assertTrue( $response['success'] );
		$this->assertSame( array(), $response['data']['categories'] );
	}

	/**
	 * A submitted categories JSON payload is decoded, sanitized to booleans
	 * keyed by a sanitized slug, and handed to the filter.
	 */
	public function test_submitted_categories_are_decoded_and_sanitized() {
		$_POST['decision']   = 'accepted';
		$_POST['categories'] = wp_json_encode(
			array(
				'Analytics!' => true,
				'marketing'  => false,
			)
		);

		$response = $this->log_consent();

		$this->assertSame(
			array(
				'analytics' => true,
				'marketing' => false,
			),
			$response['data']['categories']
		);
	}

	/**
	 * The frcn_cookie_consent_categories filter must actually run around the
	 * categories payload, receiving the decision alongside it, and its
	 * return value must be what reaches the response — this is the concrete
	 * extension point a PRO-like add-on uses to persist/read its own
	 * per-category state.
	 */
	public function test_consent_categories_filter_is_applied_and_observable_in_the_response() {
		$_POST['decision'] = 'rejected';

		$captured_decision = null;

		add_filter(
			'frcn_cookie_consent_categories',
			static function ( $categories, $decision ) use ( &$captured_decision ) {
				$captured_decision = $decision;
				$categories['marketing'] = false;
				$categories['analytics'] = true;
				return $categories;
			},
			10,
			2
		);

		$response = $this->log_consent();

		$this->assertSame( 'rejected', $captured_decision );
		$this->assertSame(
			array(
				'marketing' => false,
				'analytics' => true,
			),
			$response['data']['categories']
		);
	}

	/**
	 * The binary accepted/rejected cookie/logging flow must keep working
	 * completely unmodified when no categories are submitted at all — the
	 * category map is purely additive.
	 */
	public function test_binary_decision_is_recorded_without_any_categories_payload() {
		$_POST['decision'] = 'accepted';

		$response = $this->log_consent();

		$this->assertTrue( $response['success'] );
	}
}
