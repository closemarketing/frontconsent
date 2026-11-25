<?php
/**
 * Tests for the filterable banner layouts and per-layout positions
 * (frcn_cookie_notice_layouts / frcn_cookie_notice_layout_positions), and
 * that Settings::sanitize_settings() validates against them.
 *
 * @package FrontConsent
 */

use FrontConsent\Admin\Settings;
use FrontConsent\Frontend\CookieNotice;
use Yoast\WPTestUtils\WPIntegration\TestCase;

class CookieNoticeLayoutsTest extends TestCase {

	/**
	 * @var Settings
	 */
	private $settings;

	public function set_up() {
		parent::set_up();
		$this->settings = new Settings();
	}

	public function tear_down() {
		remove_all_filters( 'frcn_cookie_notice_layouts' );
		remove_all_filters( 'frcn_cookie_notice_layout_positions' );
		delete_option( Settings::OPTION_NAME );
		parent::tear_down();
	}

	/**
	 * Register a 'compact' layout with three positions, like an add-on would.
	 */
	private function add_compact_layout() {
		add_filter(
			'frcn_cookie_notice_layouts',
			static function ( $layouts ) {
				$layouts['compact'] = 'Compact';
				return $layouts;
			}
		);
		add_filter(
			'frcn_cookie_notice_layout_positions',
			static function ( $positions ) {
				$positions['compact'] = array(
					'bottom-center' => 'Bottom center',
					'bottom-left'   => 'Bottom left',
					'bottom-right'  => 'Bottom right',
				);
				return $positions;
			}
		);
	}

	public function test_built_in_layouts_and_positions() {
		$this->assertSame( array( 'bar', 'box', 'popup' ), array_keys( CookieNotice::get_layouts() ) );
		$this->assertSame( array( 'box' ), array_keys( CookieNotice::get_layout_positions() ) );
	}

	public function test_a_filtered_layout_is_offered_and_saved() {
		$this->add_compact_layout();

		$this->assertArrayHasKey( 'compact', CookieNotice::get_layouts() );

		$result = $this->settings->sanitize_settings(
			array(
				'cookie_notice_layout'   => 'compact',
				'cookie_notice_position' => 'bottom-center',
			)
		);

		$this->assertSame( 'compact', $result['cookie_notice_layout'] );
		$this->assertSame( 'bottom-center', $result['cookie_notice_position'] );
	}

	public function test_an_unregistered_layout_falls_back_to_bar() {
		$result = $this->settings->sanitize_settings( array( 'cookie_notice_layout' => 'compact' ) );

		$this->assertSame( 'bar', $result['cookie_notice_layout'] );
	}

	public function test_a_position_not_supported_by_the_layout_falls_back_to_its_first_position() {
		$result = $this->settings->sanitize_settings(
			array(
				'cookie_notice_layout'   => 'box',
				'cookie_notice_position' => 'bottom-center',
			)
		);

		$this->assertSame( 'bottom-right', $result['cookie_notice_position'] );
	}

	public function test_a_layout_without_positions_keeps_a_valid_box_position() {
		$result = $this->settings->sanitize_settings(
			array(
				'cookie_notice_layout'   => 'bar',
				'cookie_notice_position' => 'bottom-left',
			)
		);

		$this->assertSame( 'bottom-left', $result['cookie_notice_position'] );
	}

	public function test_a_non_array_filter_result_is_ignored() {
		add_filter( 'frcn_cookie_notice_layouts', '__return_false' );

		$this->assertSame( array( 'bar', 'box', 'popup' ), array_keys( CookieNotice::get_layouts() ) );
	}
}
