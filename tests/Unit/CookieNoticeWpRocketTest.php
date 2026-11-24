<?php
/**
 * Tests for CookieNotice's WP Rocket "Delay JavaScript execution" exclusions.
 *
 * @package FrontConsent
 */

use FrontConsent\Frontend\CookieNotice;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * WP Rocket delay JS compatibility test case.
 */
class CookieNoticeWpRocketTest extends TestCase {

	/**
	 * CookieNotice instance under test.
	 *
	 * @var CookieNotice
	 */
	private $cookie_notice;

	/**
	 * Enable the banner and build the CookieNotice instance.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'frontconsent_settings', array( 'enable_cookie_notice' => true ) );
		$this->cookie_notice = new CookieNotice();
	}

	/**
	 * Reset the scripts and hooks registered by the test.
	 *
	 * @return void
	 */
	public function tear_down() {
		wp_deregister_script( 'frontconsent-cookie-notice' );
		wp_deregister_script( 'frontconsent-consent-mode-default' );
		wp_deregister_script( 'frontconsent-consent-bootstrap' );
		wp_deregister_style( 'frontconsent-cookie-notice' );

		remove_all_filters( 'rocket_delay_js_exclusions' );
		remove_all_actions( 'wp_head' );
		remove_all_actions( 'wp_footer' );
		remove_all_actions( 'wp_enqueue_scripts' );
		delete_option( 'frontconsent_settings' );

		parent::tear_down();
	}

	/**
	 * The filter is registered whenever the banner is enabled, and keeps the
	 * exclusions WP Rocket or the site admin already configured.
	 *
	 * @return void
	 */
	public function test_adds_exclusions_and_keeps_existing_ones() {
		$this->assertNotFalse( has_filter( 'rocket_delay_js_exclusions', array( $this->cookie_notice, 'exclude_from_wp_rocket_delay_js' ) ) );

		$exclusions = apply_filters( 'rocket_delay_js_exclusions', array( 'existing-pattern' ) );

		$this->assertContains( 'existing-pattern', $exclusions );
		$this->assertGreaterThan( 1, count( $exclusions ) );
	}

	/**
	 * Every <script> tag FrontConsent prints must match one of its patterns,
	 * applied the same way WP Rocket does, or WP Rocket would delay it until
	 * the visitor's first interaction.
	 *
	 * @return void
	 */
	public function test_every_frontconsent_script_tag_is_excluded() {
		$patterns = $this->cookie_notice->exclude_from_wp_rocket_delay_js( array() );
		$tags     = $this->get_script_tags( $this->render_frontconsent_scripts() );

		// Consent Mode default, bootstrap, localized data and the banner script.
		$this->assertGreaterThanOrEqual( 4, count( $tags ) );

		foreach ( $tags as $tag ) {
			$this->assertTrue( $this->is_excluded( $tag, $patterns ), 'Not excluded from WP Rocket delay JS: ' . $tag );
		}
	}

	/**
	 * The patterns must not be so broad that they exclude unrelated scripts.
	 *
	 * @return void
	 */
	public function test_unrelated_scripts_are_not_excluded() {
		$patterns = $this->cookie_notice->exclude_from_wp_rocket_delay_js( array() );

		$this->assertFalse( $this->is_excluded( '<script src="https://example.com/wp-content/plugins/other-plugin/assets/script.js"></script>', $patterns ) );
		$this->assertFalse( $this->is_excluded( '<script>window.dataLayer = window.dataLayer || [];</script>', $patterns ) );
	}

	/**
	 * Print every script FrontConsent outputs on a frontend page.
	 *
	 * @return string
	 */
	private function render_frontconsent_scripts() {
		ob_start();
		$this->cookie_notice->render_consent_mode_default();
		$this->cookie_notice->render_consent_bootstrap_script();
		$this->cookie_notice->enqueue_assets();
		wp_print_scripts( 'frontconsent-cookie-notice' );
		return ob_get_clean();
	}

	/**
	 * Extract the <script> tags with the same regex WP Rocket uses.
	 *
	 * @param string $html HTML.
	 * @return string[]
	 */
	private function get_script_tags( $html ) {
		preg_match_all( '/<\s*script(?<attr>\s*[^>]*?)?>(?<content>.*?)?<\s*\/\s*script\s*>/ims', $html, $matches );

		return $matches[0];
	}

	/**
	 * Whether WP Rocket would leave this tag alone, using its own matching:
	 * each pattern is a regex fragment tested against the whole tag.
	 *
	 * @param string   $tag      Script tag HTML.
	 * @param string[] $patterns Exclusion patterns.
	 * @return bool
	 */
	private function is_excluded( $tag, $patterns ) {
		foreach ( $patterns as $pattern ) {
			$pattern = str_replace( array( '+', '?ver', '#' ), array( '\+', '\?ver', '\#' ), $pattern );

			if ( preg_match( "#{$pattern}#i", $tag ) ) {
				return true;
			}
		}

		return false;
	}
}
