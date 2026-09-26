<?php
/**
 * Tests for CookieNotice's policy-page suppression and permalink resolution.
 *
 * @package FrontConsent
 */

use FrontConsent\Frontend\CookieNotice;
use Yoast\WPTestUtils\WPIntegration\TestCase;

class CookieNoticePolicyPageTest extends TestCase {

	/**
	 * @var int
	 */
	private $policy_page_id;

	/**
	 * @var CookieNotice
	 */
	private $cookie_notice;

	public function set_up() {
		parent::set_up();

		$this->policy_page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Cookie Policy',
				'post_status' => 'publish',
			)
		);

		update_option(
			'frontconsent_settings',
			array(
				'enable_cookie_notice'         => true,
				'cookie_notice_policy_page_id' => $this->policy_page_id,
			)
		);

		$this->cookie_notice = new CookieNotice();
	}

	private function render_banner_html() {
		ob_start();
		$this->cookie_notice->render_banner();
		return ob_get_clean();
	}

	/**
	 * The banner must never render on the page the admin configured as the
	 * cookie policy page — otherwise a popup layout would immediately cover
	 * the very content the visitor is trying to read before deciding. The
	 * persistent "Cookie preferences" reopen trigger is unaffected by this
	 * suppression and still renders there.
	 */
	public function test_banner_is_suppressed_on_the_configured_policy_page() {
		$this->go_to( get_permalink( $this->policy_page_id ) );

		$html = $this->render_banner_html();

		$this->assertStringNotContainsString( 'id="frcn-cookie-notice"', $html );
		$this->assertStringContainsString( 'id="frcn-cookie-reopen"', $html );
	}

	/**
	 * The banner must still render on every other page — suppression is
	 * scoped to exactly the configured page, not a global kill switch.
	 */
	public function test_banner_still_renders_on_other_pages() {
		$other_page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->go_to( get_permalink( $other_page_id ) );

		$this->assertStringContainsString( 'id="frcn-cookie-notice"', $this->render_banner_html() );
	}

	/**
	 * The "Cookie preferences" reopen trigger must render hidden by default —
	 * never gated by the visitor's own consent cookie, so a full-page cache
	 * stays safe; JS reveals it once a decision cookie actually exists.
	 */
	public function test_reopen_trigger_renders_hidden_by_default() {
		$other_page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->go_to( get_permalink( $other_page_id ) );

		$html = $this->render_banner_html();

		$this->assertMatchesRegularExpression( '/<button[^>]*id="frcn-cookie-reopen"[^>]*\bhidden\b/', $html );
	}

	/**
	 * With no policy page configured at all, nothing should ever be suppressed.
	 */
	public function test_no_policy_page_configured_means_no_suppression_anywhere() {
		update_option(
			'frontconsent_settings',
			array( 'enable_cookie_notice' => true )
		);
		$cookie_notice = new CookieNotice();

		$this->go_to( get_permalink( $this->policy_page_id ) );

		ob_start();
		$cookie_notice->render_banner();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'id="frcn-cookie-notice"', $html );
	}

	/**
	 * The banner must render already invisible/off-screen by default — the
	 * 'frcn-cookie-notice--init' class is what a returning, already-decided
	 * visitor never sees removed, avoiding the flash frontconsent-cookie-notice.js
	 * used to cause by hiding a banner that started out visible.
	 */
	public function test_banner_renders_with_the_init_class_by_default() {
		$other_page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->go_to( get_permalink( $other_page_id ) );

		$html = $this->render_banner_html();

		$this->assertMatchesRegularExpression( '/class="[^"]*\bfrcn-cookie-notice--init\b[^"]*"/', $html );
	}

	/**
	 * A no-JS visitor must still see the banner: the printed <noscript> style
	 * resets '--init' back to visible, since nothing would otherwise ever
	 * remove that class for them.
	 */
	public function test_banner_includes_a_noscript_fallback_for_the_init_state() {
		$other_page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->go_to( get_permalink( $other_page_id ) );

		$html = $this->render_banner_html();

		$this->assertStringContainsString( '<noscript>', $html );
		$this->assertStringContainsString( 'frcn-cookie-notice--init', $html );
	}

	/**
	 * The "Learn more" link in the banner message must resolve the saved
	 * page ID to its actual current permalink, not embed a stale/raw URL.
	 */
	public function test_learn_more_link_resolves_to_the_policy_page_permalink() {
		$other_page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->go_to( get_permalink( $other_page_id ) );

		$html = $this->render_banner_html();

		$this->assertStringContainsString(
			esc_url( get_permalink( $this->policy_page_id ) ),
			$html
		);
	}

	/**
	 * On the configured policy page, the reopen trigger must localize
	 * isPolicyPage=true and the home URL — otherwise clicking it there would
	 * clear the decision cookie and reload straight back onto a page where
	 * the banner never renders, leaving the visitor with no controls at all.
	 */
	public function test_localized_script_data_flags_the_policy_page_and_provides_a_home_url() {
		$this->go_to( get_permalink( $this->policy_page_id ) );

		$this->cookie_notice->enqueue_assets();

		$data = wp_scripts()->get_data( 'frontconsent-cookie-notice', 'data' );

		// wp_localize_script() casts every value to a string — true becomes
		// "1", so this asserts the JS-truthy form actually sent to the page,
		// not a JSON boolean literal.
		$this->assertStringContainsString( '"isPolicyPage":"1"', $data );
		$this->assertStringContainsString( home_url( '/' ), $data );
	}

	/**
	 * On every other page, isPolicyPage must serialize to the JS-falsy empty
	 * string — the reopen trigger there should simply reload in place.
	 */
	public function test_localized_script_data_does_not_flag_other_pages_as_the_policy_page() {
		$other_page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->go_to( get_permalink( $other_page_id ) );

		$this->cookie_notice->enqueue_assets();

		$data = wp_scripts()->get_data( 'frontconsent-cookie-notice', 'data' );

		$this->assertStringContainsString( '"isPolicyPage":""', $data );
	}
}
