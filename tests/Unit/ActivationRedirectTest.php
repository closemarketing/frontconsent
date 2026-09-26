<?php
/**
 * Tests for the deferred activation redirect (frcn_plugin_activation_redirect()).
 *
 * @package FrontConsent
 */

use Yoast\WPTestUtils\WPIntegration\TestCase;

class ActivationRedirectTest extends TestCase {

	public function tear_down() {
		delete_option( 'frcn_activation_redirect' );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		parent::tear_down();
	}

	/**
	 * admin_init also fires on admin AJAX requests (e.g. Heartbeat) that can
	 * reach the server between activation and the browser's next real admin
	 * page load — those must not consume the flag, or the activating
	 * administrator never gets redirected on their next real page load at
	 * all (an unrelated background AJAX request silently claims it instead).
	 */
	public function test_ajax_request_does_not_consume_the_redirect_flag() {
		update_option( 'frcn_activation_redirect', true );
		add_filter( 'wp_doing_ajax', '__return_true' );

		frcn_plugin_activation_redirect();

		$this->assertTrue( (bool) get_option( 'frcn_activation_redirect' ) );
	}
}
