<?php
/**
 * Tests that migration runs before CookieNotice reads its enablement state,
 * so a freshly migrated site's very first request doesn't lose its frontend
 * hooks (banner, Consent Mode default, tracking bootstrap).
 *
 * @package FrontConsent
 */

use FrontConsent\Frontend\CookieNotice;
use FrontConsent\Migration;
use Yoast\WPTestUtils\WPIntegration\TestCase;

class PluginInitOrderTest extends TestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Migration::DONE_FLAG );
		delete_option( 'frontconsent_settings' );
		delete_option( 'frontblocks_settings' );
	}

	public function tear_down() {
		$this->remove_cookie_notice_hooks();
		delete_option( Migration::DONE_FLAG );
		delete_option( 'frontconsent_settings' );
		delete_option( 'frontblocks_settings' );
		parent::tear_down();
	}

	/**
	 * Remove every hook CookieNotice::__construct() could have registered,
	 * so one test's instance never leaks into the next.
	 *
	 * @return void
	 */
	private function remove_cookie_notice_hooks() {
		remove_all_actions( 'wp_head' );
		remove_all_actions( 'wp_footer' );
		remove_all_actions( 'wp_enqueue_scripts' );
	}

	/**
	 * Migration must run, and its write to frontconsent_settings must be
	 * complete, before CookieNotice is ever constructed — otherwise its
	 * constructor's one-time is_enabled() check sees the pre-migration
	 * (disabled) state and never registers the frontend hooks for that
	 * request at all, even though migration enables Cookie Notice moments
	 * later. This mirrors what Plugin_Main::init() actually does, in order.
	 */
	public function test_cookie_notice_registers_frontend_hooks_when_constructed_after_migration() {
		update_option( 'frontblocks_settings', array( 'enable_cookie_notice' => true ) );

		// This is the fix under test: Migration::maybe_run() must be called
		// before `new CookieNotice()`, not after.
		Migration::maybe_run();

		$this->assertTrue( (bool) ( get_option( 'frontconsent_settings' )['enable_cookie_notice'] ?? false ) );

		$cookie_notice = new CookieNotice();

		$this->assertNotFalse( has_action( 'wp_footer', array( $cookie_notice, 'render_banner' ) ) );
		$this->assertNotFalse( has_action( 'wp_enqueue_scripts', array( $cookie_notice, 'enqueue_assets' ) ) );
	}

	/**
	 * Constructing CookieNotice before migration runs (the regression this
	 * guards against) must NOT register the frontend hooks, demonstrating
	 * why the ordering in Plugin_Main::init() matters.
	 */
	public function test_cookie_notice_constructed_before_migration_misses_the_frontend_hooks() {
		update_option( 'frontblocks_settings', array( 'enable_cookie_notice' => true ) );

		// Deliberately wrong order, to document the regression.
		$cookie_notice = new CookieNotice();
		Migration::maybe_run();

		$this->assertFalse( has_action( 'wp_footer', array( $cookie_notice, 'render_banner' ) ) );
		$this->assertFalse( has_action( 'wp_enqueue_scripts', array( $cookie_notice, 'enqueue_assets' ) ) );
	}
}
