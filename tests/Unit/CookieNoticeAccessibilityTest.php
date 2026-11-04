<?php
/**
 * Tests for the accessibility semantics of the rendered banner markup:
 * correct roles/ARIA per layout, an accessible name/description, and the
 * always-present live region used to announce banner visibility and
 * consent-decision changes to screen reader users (see
 * CookieNotice::render_status_announcer() and frontconsent-cookie-notice.js's
 * announce() helper).
 *
 * @package FrontConsent
 */

use FrontConsent\Frontend\CookieNotice;
use Yoast\WPTestUtils\WPIntegration\TestCase;

class CookieNoticeAccessibilityTest extends TestCase {

	/**
	 * @var CookieNotice
	 */
	private $cookie_notice;

	public function set_up() {
		parent::set_up();
		$this->cookie_notice = new CookieNotice();
	}

	public function tear_down() {
		delete_option( 'frontconsent_settings' );
		parent::tear_down();
	}

	/**
	 * Render the full wp_footer output (banner markup + reopen trigger +
	 * status announcer), the same way WordPress itself would for a request.
	 *
	 * @return string
	 */
	private function render_banner_html() {
		ob_start();
		$this->cookie_notice->render_banner();
		return ob_get_clean();
	}

	/**
	 * The default 'bar' layout does not block the rest of the page, so it
	 * must use role="region", not a modal dialog role — a modal role there
	 * would misrepresent it to assistive technology as something that traps
	 * interaction with the rest of the page, which it doesn't.
	 */
	public function test_bar_layout_uses_region_role_not_dialog() {
		update_option(
			'frontconsent_settings',
			array(
				'enable_cookie_notice' => true,
				'cookie_notice_layout' => 'bar',
			)
		);

		$html = $this->render_banner_html();

		// Scoped to the banner element itself: the cookie preferences panel
		// (a separate, always-present dialog — see
		// CookieNotice::render_preferences_panel()) legitimately carries
		// role="dialog"/aria-modal regardless of the banner's own layout, so
		// asserting over the whole wp_footer output would wrongly fail here.
		$banner_end  = strpos( $html, 'id="frcn-cookie-reopen"' );
		$banner_html = substr( $html, 0, $banner_end );

		$this->assertStringContainsString( 'role="region"', $banner_html );
		$this->assertStringNotContainsString( 'role="dialog"', $banner_html );
		$this->assertStringNotContainsString( 'aria-modal', $banner_html );
	}

	/**
	 * The 'popup' layout is a true modal (it covers the rest of the page
	 * behind a backdrop), so it must be marked up as a dialog with
	 * aria-modal="true" — this is what tells a screen reader the rest of the
	 * page is temporarily unavailable and focus should stay within it.
	 */
	public function test_popup_layout_uses_dialog_role_and_aria_modal() {
		update_option(
			'frontconsent_settings',
			array(
				'enable_cookie_notice' => true,
				'cookie_notice_layout' => 'popup',
			)
		);

		$html = $this->render_banner_html();

		$this->assertStringContainsString( 'role="dialog"', $html );
		$this->assertStringContainsString( 'aria-modal="true"', $html );
	}

	/**
	 * Every layout needs an accessible name (aria-label) and an accessible
	 * description (aria-describedby, pointing at the actual message text) —
	 * without both, a screen reader announces only "region" or "dialog" with
	 * no indication of what it's actually about.
	 */
	public function test_banner_has_accessible_name_and_description() {
		update_option(
			'frontconsent_settings',
			array(
				'enable_cookie_notice' => true,
				'cookie_notice_layout' => 'bar',
			)
		);

		$html = $this->render_banner_html();

		$this->assertStringContainsString( 'aria-label="Cookie consent"', $html );
		$this->assertStringContainsString( 'aria-describedby="frcn-cookie-notice-message"', $html );
		$this->assertStringContainsString( 'id="frcn-cookie-notice-message"', $html );
	}

	/**
	 * Every interactive control in the banner must be a native, keyboard
	 * operable element — a non-native clickable <div>/<span> would need
	 * hand-rolled keydown handling and role/tabindex plumbing to be operable
	 * at all, and is easy to get wrong.
	 */
	public function test_accept_and_reject_are_native_buttons() {
		update_option( 'frontconsent_settings', array( 'enable_cookie_notice' => true ) );

		$html = $this->render_banner_html();

		$this->assertStringContainsString( '<button', $html );
		$this->assertMatchesRegularExpression( '/<button[^>]*data-frcn-cookie-action="accept"/', $html );
		$this->assertMatchesRegularExpression( '/<button[^>]*data-frcn-cookie-action="reject"/', $html );
		$this->assertMatchesRegularExpression( '/<button\s+type="button"[^>]*id="frcn-cookie-reopen"/', $html );
	}

	/**
	 * The icon is purely decorative (the buttons/message already convey the
	 * same information in text), so it must be hidden from assistive
	 * technology rather than announced as an unlabelled image/glyph.
	 */
	public function test_decorative_icon_is_hidden_from_assistive_technology() {
		update_option( 'frontconsent_settings', array( 'enable_cookie_notice' => true ) );

		$html = $this->render_banner_html();

		$this->assertStringContainsString( 'aria-hidden="true"', $html );
	}

	/**
	 * The live region used to announce the banner appearing and later
	 * consent-decision changes must always be present (so JS can find and
	 * fill it in — see frontconsent-cookie-notice.js's announce() helper),
	 * regardless of layout or whether a decision was already made.
	 */
	public function test_status_announcer_live_region_is_always_present() {
		update_option( 'frontconsent_settings', array( 'enable_cookie_notice' => true ) );

		$html = $this->render_banner_html();

		$this->assertStringContainsString( 'id="frcn-cookie-notice-announcer"', $html );
		$this->assertStringContainsString( 'aria-live="polite"', $html );
		$this->assertMatchesRegularExpression( '/role="status"[^>]*aria-live="polite"|aria-live="polite"[^>]*role="status"/', $html );
	}

	/**
	 * The status announcer must still be printed on the configured cookie
	 * policy page, exactly like the reopen trigger — an add-on's own
	 * decision UI on that page still needs somewhere to announce state
	 * changes to.
	 */
	public function test_status_announcer_is_present_even_on_the_policy_page() {
		$policy_page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		update_option(
			'frontconsent_settings',
			array(
				'enable_cookie_notice'         => true,
				'cookie_notice_policy_page_id' => $policy_page_id,
			)
		);

		$this->go_to( get_permalink( $policy_page_id ) );

		$html = $this->render_banner_html();

		$this->assertStringNotContainsString( 'id="frcn-cookie-notice"', $html );
		$this->assertStringContainsString( 'id="frcn-cookie-notice-announcer"', $html );
	}

	/**
	 * The reopen trigger is icon-only by design (a small recognizable
	 * affordance, not a text pill competing for attention on every page), so
	 * its accessible name must come entirely from aria-label — it must not
	 * also carry visible text content.
	 */
	public function test_reopen_trigger_is_icon_only_with_an_aria_label() {
		update_option( 'frontconsent_settings', array( 'enable_cookie_notice' => true ) );

		$html = $this->render_banner_html();

		$this->assertMatchesRegularExpression(
			'/<button[^>]*id="frcn-cookie-reopen"[^>]*aria-label="Cookie preferences"[^>]*>\s*<span[^>]*aria-hidden="true"[^>]*><\/span>\s*<\/button>/',
			$html
		);
		$this->assertStringNotContainsString( '>Cookie preferences<', $html );
	}
}
