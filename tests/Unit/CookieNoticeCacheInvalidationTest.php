<?php
/**
 * Tests for CookieNotice's cache-invalidation hook on settings changes.
 *
 * @package FrontConsent
 */

use FrontConsent\Frontend\CookieNotice;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Cookie Notice cache invalidation test case.
 */
class CookieNoticeCacheInvalidationTest extends TestCase {

	/**
	 * CookieNotice instance under test.
	 *
	 * @var CookieNotice
	 */
	private $cookie_notice;

	/**
	 * Set up the CookieNotice instance.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->cookie_notice = new CookieNotice();
	}

	/**
	 * Verify that cache integrations are notified after a Cookie Notice change.
	 *
	 * @return void
	 */
	public function test_cookie_notice_update_fires_extension_action() {
		$received = array();
		$callback = static function ( $old_options, $new_options ) use ( &$received ) {
			$received = array( $old_options, $new_options );
		};

		add_action( 'frcn_cookie_notice_settings_updated', $callback, 10, 2 );

		$old_options = array( 'enable_cookie_notice' => false );
		$new_options = array( 'enable_cookie_notice' => true );
		update_option( 'frontconsent_settings', $old_options );
		update_option( 'frontconsent_settings', $new_options );

		remove_action( 'frcn_cookie_notice_settings_updated', $callback, 10 );

		$this->assertSame( $old_options, $received[0] );
		$this->assertSame( $new_options, $received[1] );
	}

	/**
	 * Verify that unrelated settings do not trigger cache invalidation hooks.
	 *
	 * @return void
	 */
	public function test_unrelated_settings_update_does_not_fire_cookie_notice_action() {
		$was_called = false;
		$callback   = static function () use ( &$was_called ) {
			$was_called = true;
		};

		add_action( 'frcn_cookie_notice_settings_updated', $callback );

		$this->cookie_notice->handle_frontconsent_settings_updated(
			array( 'enable_events' => false ),
			array( 'enable_events' => true ),
			'frontconsent_settings'
		);

		remove_action( 'frcn_cookie_notice_settings_updated', $callback );

		$this->assertFalse( $was_called );
	}
}
