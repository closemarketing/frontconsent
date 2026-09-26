<?php
/**
 * Tests for the one-time migration from FrontBlocks' Cookie Notice module.
 *
 * @package FrontConsent
 */

use FrontConsent\Migration;
use Yoast\WPTestUtils\WPIntegration\TestCase;

class MigrationTest extends TestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Migration::DONE_FLAG );
		delete_option( 'frontconsent_settings' );
		delete_option( 'frontblocks_settings' );
	}

	public function tear_down() {
		delete_option( Migration::DONE_FLAG );
		delete_option( 'frontconsent_settings' );
		delete_option( 'frontblocks_settings' );
		parent::tear_down();
	}

	/**
	 * The retired dedicated cookie_notice_gtm_id/cookie_notice_ga4_id fields
	 * must be converted into the shared cookie_notice_tracking_integrations
	 * list — that's the only place CookieNotice::get_tracking_integrations()
	 * actually reads GTM/GA4 ids from, so copying the legacy keys verbatim
	 * would leave the migrated tracking silently dead.
	 */
	public function test_legacy_gtm_and_ga4_keys_are_converted_into_tracking_integrations() {
		update_option(
			'frontblocks_settings',
			array(
				'enable_cookie_notice' => true,
				'cookie_notice_gtm_id' => 'GTM-LEGACY1',
				'cookie_notice_ga4_id' => 'G-LEGACY123',
			)
		);

		Migration::maybe_run();

		$options = get_option( 'frontconsent_settings' );

		$this->assertArrayNotHasKey( 'cookie_notice_gtm_id', $options );
		$this->assertArrayNotHasKey( 'cookie_notice_ga4_id', $options );
		$this->assertSame(
			array(
				array( 'type' => 'gtm', 'id' => 'GTM-LEGACY1' ),
				array( 'type' => 'ga4', 'id' => 'G-LEGACY123' ),
			),
			$options['cookie_notice_tracking_integrations']
		);
	}

	/**
	 * A legacy GTM id must not override one the admin already configured
	 * directly in FrontConsent's own tracking integrations list.
	 */
	public function test_legacy_gtm_id_does_not_override_an_existing_integration() {
		update_option(
			'frontblocks_settings',
			array(
				'enable_cookie_notice' => true,
				'cookie_notice_gtm_id' => 'GTM-LEGACY1',
			)
		);
		update_option(
			'frontconsent_settings',
			array(
				'cookie_notice_tracking_integrations' => array(
					array( 'type' => 'gtm', 'id' => 'GTM-CURRENT' ),
				),
			)
		);

		Migration::maybe_run();

		$options = get_option( 'frontconsent_settings' );

		$this->assertSame(
			array( array( 'type' => 'gtm', 'id' => 'GTM-CURRENT' ) ),
			$options['cookie_notice_tracking_integrations']
		);
	}

	/**
	 * A site with no legacy GTM/GA4 value must not gain an integrations key
	 * out of nowhere.
	 */
	public function test_migration_is_a_no_op_when_no_legacy_gtm_or_ga4_value_is_present() {
		update_option( 'frontblocks_settings', array( 'enable_cookie_notice' => true ) );

		Migration::maybe_run();

		$options = get_option( 'frontconsent_settings' );

		$this->assertArrayNotHasKey( 'cookie_notice_tracking_integrations', $options );
	}

	/**
	 * The migration only ever runs once per site, guarded by DONE_FLAG.
	 */
	public function test_migration_only_runs_once() {
		update_option( 'frontblocks_settings', array( 'enable_cookie_notice' => true ) );

		Migration::maybe_run();
		update_option( 'frontconsent_settings', array( 'enable_cookie_notice' => false ) );
		Migration::maybe_run();

		$options = get_option( 'frontconsent_settings' );

		$this->assertFalse( $options['enable_cookie_notice'] );
	}

	/**
	 * The guard is claimed atomically via add_option() (not a plain
	 * get_option()/update_option() check-then-act pair) so that a request
	 * finding the flag already set by another concurrent request never
	 * re-runs migrate_stats() — whose additive counters would otherwise be
	 * double-counted.
	 */
	public function test_migration_does_not_double_count_stats_when_the_flag_is_already_claimed() {
		// Simulate another concurrent request having already claimed the
		// guard and completed the migration.
		add_option( Migration::DONE_FLAG, true, '', 'no' );
		update_option(
			\FrontConsent\Frontend\CookieNotice::STATS_OPTION_ACCEPTED,
			5,
			false
		);

		update_option( 'frontblocks_settings', array( 'enable_cookie_notice' => true ) );
		update_option( 'frontblocks_cookie_notice_accepted_count', 10 );

		Migration::maybe_run();

		$this->assertSame( 5, (int) get_option( \FrontConsent\Frontend\CookieNotice::STATS_OPTION_ACCEPTED ) );

		delete_option( \FrontConsent\Frontend\CookieNotice::STATS_OPTION_ACCEPTED );
		delete_option( 'frontblocks_cookie_notice_accepted_count' );
	}

	/**
	 * migrate_stats() must add the legacy count to whatever is already in
	 * FrontConsent's own counter (e.g. a decision logged by a concurrent
	 * visitor between migration's read and write) via an atomic SQL
	 * UPDATE, not a get_option()/update_option() round trip that could
	 * overwrite that concurrent write.
	 */
	public function test_migration_adds_legacy_stats_to_an_existing_counter_atomically() {
		update_option( \FrontConsent\Frontend\CookieNotice::STATS_OPTION_ACCEPTED, 3, false );
		update_option(
			'frontblocks_settings',
			array( 'enable_cookie_notice' => true )
		);
		update_option( 'frontblocks_cookie_notice_accepted_count', 7 );

		Migration::maybe_run();

		$this->assertSame( 10, (int) get_option( \FrontConsent\Frontend\CookieNotice::STATS_OPTION_ACCEPTED ) );

		delete_option( \FrontConsent\Frontend\CookieNotice::STATS_OPTION_ACCEPTED );
		delete_option( 'frontblocks_cookie_notice_accepted_count' );
	}
}
