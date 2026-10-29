<?php
/**
 * Tests for holding analytics/attribution scripts back until consent and for
 * suppressing GTM4WP's own Consent Mode default.
 *
 * @package FrontConsent
 */

use FrontConsent\Frontend\CookieNotice;
use Yoast\WPTestUtils\WPIntegration\TestCase;

class CookieNoticeGatedScriptsTest extends TestCase {

	/**
	 * @var CookieNotice
	 */
	private $cookie_notice;

	public function set_up() {
		parent::set_up();
		$this->cookie_notice = new CookieNotice();
	}

	public function test_order_attribution_scripts_are_made_inert() {
		$tag = "<script type='text/javascript' src='https://example.org/sourcebuster.min.js?ver=1' id='sourcebuster-js'></script>\n";

		$gated = $this->cookie_notice->gate_analytics_scripts( $tag, 'sourcebuster-js' );

		$this->assertStringStartsWith( '<script type="text/plain" data-frcn-consent="analytics"', $gated );
		$this->assertStringNotContainsString( 'text/javascript', $gated );
		$this->assertStringContainsString( "src='https://example.org/sourcebuster.min.js?ver=1'", $gated );
	}

	public function test_other_scripts_are_left_alone() {
		$tag = "<script src='https://example.org/app.js' id='app-js'></script>\n";

		$this->assertSame( $tag, $this->cookie_notice->gate_analytics_scripts( $tag, 'app-js' ) );
	}

	public function test_gated_handles_can_be_emptied_by_filter() {
		add_filter( 'frcn_cookie_notice_gated_script_handles', '__return_empty_array' );

		$tag = "<script src='https://example.org/x.js'></script>";
		$this->assertSame( $tag, $this->cookie_notice->gate_analytics_scripts( $tag, 'sourcebuster-js' ) );

		remove_filter( 'frcn_cookie_notice_gated_script_handles', '__return_empty_array' );
	}

	public function test_gtm4wp_consent_default_is_suppressed_unless_opted_out() {
		$this->assertFalse( $this->cookie_notice->filter_gtm4wp_consent_default( true ) );

		add_filter( 'frcn_cookie_notice_suppress_gtm4wp_consent_default', '__return_false' );
		$this->assertTrue( $this->cookie_notice->filter_gtm4wp_consent_default( true ) );
		remove_filter( 'frcn_cookie_notice_suppress_gtm4wp_consent_default', '__return_false' );
	}
}
