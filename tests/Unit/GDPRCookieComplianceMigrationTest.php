<?php
/**
 * Tests for the GDPR Cookie Compliance settings importer.
 *
 * @package FrontConsent
 */

use FrontConsent\GDPR_Cookie_Compliance_Migration as Importer;
use Yoast\WPTestUtils\WPIntegration\TestCase;

class GDPRCookieComplianceMigrationTest extends TestCase {

	/**
	 * Build a source option value the way GDPR Cookie Compliance stores it.
	 *
	 * @param array $gdin Integration values.
	 * @param array $more Extra source keys.
	 * @return array
	 */
	private function source( array $gdin, array $more = array() ) {
		return array_merge( array( 'gdin_values' => wp_json_encode( $gdin ) ), $more );
	}

	private function statuses( array $report ) {
		return wp_list_pluck( $report, 'status' );
	}

	public function test_ga4_and_gtm_are_migrated() {
		$result = Importer::build(
			$this->source(
				array(
					'ga4'    => 2,
					'ga4_id' => 'G-ABC123',
					'gtm'    => 3,
					'gtm_id' => 'GTM-ABCD12',
				)
			),
			array()
		);

		$this->assertSame(
			array(
				array(
					'type' => 'ga4',
					'id'   => 'G-ABC123',
				),
				array(
					'type' => 'gtm',
					'id'   => 'GTM-ABCD12',
				),
			),
			$result['settings']['cookie_notice_tracking_integrations']
		);
		$this->assertSame( array( 'migrated', 'migrated' ), $this->statuses( $result['report'] ) );
	}

	public function test_running_twice_does_not_duplicate_integrations() {
		$source = $this->source(
			array(
				'ga4'    => 2,
				'ga4_id' => 'G-ABC123',
			)
		);

		$first  = Importer::build( $source, array() );
		$second = Importer::build( $source, $first['settings'] );

		$this->assertCount( 1, $second['settings']['cookie_notice_tracking_integrations'] );
		$this->assertSame( array( 'skipped_existing' ), $this->statuses( $second['report'] ) );
	}

	public function test_existing_frontconsent_integration_is_not_overwritten() {
		$current = array(
			'cookie_notice_tracking_integrations' => array(
				array(
					'type' => 'gtm',
					'id'   => 'GTM-KEEPME1',
				),
			),
		);

		$result = Importer::build(
			$this->source(
				array(
					'gtm'    => 2,
					'gtm_id' => 'GTM-OTHER99',
				)
			),
			$current
		);

		$this->assertSame( 'GTM-KEEPME1', $result['settings']['cookie_notice_tracking_integrations'][0]['id'] );
	}

	public function test_ga4_id_in_the_gtm_field_becomes_ga4() {
		$result = Importer::build(
			$this->source(
				array(
					'gtm'    => 2,
					'gtm_id' => 'G-XYZ789',
				)
			),
			array()
		);

		$this->assertSame( 'ga4', $result['settings']['cookie_notice_tracking_integrations'][0]['type'] );
	}

	public function test_consent_mode_gtm_is_migrated_but_flagged_for_review() {
		$result = Importer::build(
			$this->source(
				array(
					'gtmc2'    => 2,
					'gtmc2_id' => 'GTM-CMV2AB',
				)
			),
			array()
		);

		$this->assertSame( 'gtm', $result['settings']['cookie_notice_tracking_integrations'][0]['type'] );
		$this->assertSame( array( 'needs_review' ), $this->statuses( $result['report'] ) );
	}

	public function test_unsupported_integrations_are_reported_not_dropped_silently() {
		$result = Importer::build(
			$this->source(
				array(
					'fbp'     => 3,
					'fbp_id'  => '123456789012345',
					'gadc'    => 3,
					'gadc_id' => 'AW-1',
				)
			),
			array()
		);

		$this->assertArrayNotHasKey( 'cookie_notice_tracking_integrations', $result['settings'] );
		$this->assertSame( array( 'unsupported', 'unsupported' ), $this->statuses( $result['report'] ) );
	}

	public function test_invalid_id_is_flagged_and_not_imported() {
		$result = Importer::build(
			$this->source(
				array(
					'ga4'    => 2,
					'ga4_id' => 'not-an-id',
				)
			),
			array()
		);

		$this->assertArrayNotHasKey( 'cookie_notice_tracking_integrations', $result['settings'] );
		$this->assertSame( array( 'needs_review' ), $this->statuses( $result['report'] ) );
	}

	public function test_malformed_gdin_values_is_ignored() {
		$result = Importer::build( array( 'gdin_values' => '{not json' ), array() );

		$this->assertSame( array(), $result['report'] );
		$this->assertSame( array(), $result['settings'] );
	}

	public function test_banner_settings_fill_only_missing_keys() {
		$result = Importer::build(
			array(
				'moove_gdpr_info_bar_content'            => '<p>We use <b>cookies</b>.</p>',
				'moove_gdpr_infobar_accept_button_label' => 'Accept all',
				'moove_gdpr_consent_expiration'          => '90',
				'moove_gdpr_infobar_position'            => 'left',
			),
			array( 'cookie_notice_accept_label' => 'OK' )
		);

		$this->assertSame( 'We use cookies.', $result['settings']['cookie_notice_message'] );
		$this->assertSame( 'OK', $result['settings']['cookie_notice_accept_label'] );
		$this->assertSame( 90, $result['settings']['cookie_notice_expiration_days'] );
		$this->assertSame( 'bottom-left', $result['settings']['cookie_notice_position'] );
	}

	public function test_run_returns_null_without_source_and_never_writes_source() {
		delete_option( Importer::SOURCE_OPTION );
		$this->assertNull( Importer::run() );

		$source = $this->source(
			array(
				'ga4'    => 2,
				'ga4_id' => 'G-ABC123',
			)
		);
		update_option( Importer::SOURCE_OPTION, $source );
		delete_option( 'frontconsent_settings' );

		Importer::run();

		$this->assertSame( $source, get_option( Importer::SOURCE_OPTION ) );
		$this->assertSame( 'G-ABC123', get_option( 'frontconsent_settings' )['cookie_notice_tracking_integrations'][0]['id'] );

		delete_option( Importer::SOURCE_OPTION );
		delete_option( Importer::DONE_FLAG );
		delete_option( 'frontconsent_settings' );
	}
}
