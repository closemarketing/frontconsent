<?php
/**
 * Tests for the "Customize cookie settings" button and the cookie
 * preferences panel it (and the persistent reopen trigger) opens — see
 * GitHub issue #11.
 *
 * @package FrontConsent
 */

use FrontConsent\Frontend\CookieNotice;
use Yoast\WPTestUtils\WPIntegration\TestCase;

class CookieNoticePreferencesPanelTest extends TestCase {

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
		remove_all_actions( 'frcn_cookie_preferences_categories' );
		remove_all_filters( 'frcn_cookie_consent_categories' );
		remove_all_filters( 'frcn_cookie_customize_button_label' );
		remove_all_filters( 'frcn_cookie_customize_button_enabled' );
		parent::tear_down();
	}

	/**
	 * Render the full wp_footer output (banner markup + reopen trigger +
	 * preferences panel + status announcer), the same way WordPress itself
	 * would for a request.
	 *
	 * @return string
	 */
	private function render_banner_html() {
		ob_start();
		$this->cookie_notice->render_banner();
		return ob_get_clean();
	}

	/**
	 * The button must render as a real, keyboard-focusable <button>, showing
	 * the cookie icon, positioned before Reject/Accept in the actions row —
	 * in every layout.
	 */
	public function test_customize_button_renders_in_every_layout_with_the_cookie_icon() {
		foreach ( array( 'bar', 'box', 'popup' ) as $layout ) {
			update_option(
				'frontconsent_settings',
				array(
					'enable_cookie_notice' => true,
					'cookie_notice_layout' => $layout,
				)
			);

			$html = $this->render_banner_html();

			$this->assertMatchesRegularExpression(
				'/<button\s+type="button"[^>]*data-frcn-cookie-action="customize"[^>]*>/',
				$html,
				"Customize button missing in the '{$layout}' layout."
			);
			$this->assertStringContainsString( 'frcn-cookie-notice__button-icon', $html );
			$this->assertStringContainsString( 'Customize cookie settings', $html );
		}
	}

	/**
	 * The button must be positioned before Reject/Accept in the DOM, since
	 * the actions row lays them out left to right and the issue requires
	 * Customize to sit on the left.
	 */
	public function test_customize_button_appears_before_reject_and_accept() {
		update_option( 'frontconsent_settings', array( 'enable_cookie_notice' => true ) );

		$html          = $this->render_banner_html();
		$customize_pos = strpos( $html, 'data-frcn-cookie-action="customize"' );
		$reject_pos    = strpos( $html, 'data-frcn-cookie-action="reject"' );

		$this->assertNotFalse( $customize_pos );
		$this->assertNotFalse( $reject_pos );
		$this->assertLessThan( $reject_pos, $customize_pos );
	}

	/**
	 * The preferences panel dialog must carry the ARIA attributes a modal
	 * dialog needs: role="dialog", aria-modal="true", and be
	 * labelled/described by its own heading/message.
	 */
	public function test_preferences_panel_has_dialog_aria_semantics() {
		update_option( 'frontconsent_settings', array( 'enable_cookie_notice' => true ) );

		$html = $this->render_banner_html();

		$this->assertStringContainsString( 'id="frcn-cookie-preferences"', $html );
		$this->assertMatchesRegularExpression( '/id="frcn-cookie-preferences"[^>]*role="dialog"/', $html );
		$this->assertStringContainsString( 'aria-modal="true"', $html );
		$this->assertStringContainsString( 'aria-labelledby="frcn-cookie-preferences-title"', $html );
		$this->assertStringContainsString( 'aria-describedby="frcn-cookie-preferences-message"', $html );
		$this->assertStringContainsString( 'id="frcn-cookie-preferences-title"', $html );
		$this->assertStringContainsString( 'id="frcn-cookie-preferences-message"', $html );
	}

	/**
	 * Hidden by default (JS is what opens it, from either trigger) and
	 * always present — including on the policy page, exactly like the
	 * reopen trigger — so both triggers reach the same panel wherever they
	 * are printed.
	 */
	public function test_preferences_panel_is_hidden_by_default_and_present_on_the_policy_page() {
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
		$this->assertStringContainsString( 'id="frcn-cookie-preferences"', $html );
		$this->assertMatchesRegularExpression( '/id="frcn-cookie-preferences"[^>]*\bhidden\b/', $html );
	}

	/**
	 * The Free tier always shows the static, non-toggleable "Strictly
	 * necessary" section.
	 */
	public function test_preferences_panel_always_shows_the_strictly_necessary_section() {
		update_option( 'frontconsent_settings', array( 'enable_cookie_notice' => true ) );

		$html = $this->render_banner_html();

		$this->assertStringContainsString( 'Strictly necessary', $html );
		$this->assertStringContainsString( 'Always active', $html );
	}

	/**
	 * The panel offers exactly the three actions the issue specifies, each
	 * wired to the same data-frcn-cookie-action attributes the JS layer
	 * already dispatches decisions through.
	 */
	public function test_preferences_panel_has_accept_reject_and_save_actions() {
		update_option( 'frontconsent_settings', array( 'enable_cookie_notice' => true ) );

		$html = $this->render_banner_html();

		$panel_start = strpos( $html, 'id="frcn-cookie-preferences"' );
		$panel_html  = substr( $html, $panel_start );

		$this->assertMatchesRegularExpression( '/<button[^>]*data-frcn-cookie-action="accept"/', $panel_html );
		$this->assertMatchesRegularExpression( '/<button[^>]*data-frcn-cookie-action="reject"/', $panel_html );
		$this->assertMatchesRegularExpression( '/<button[^>]*data-frcn-cookie-action="save"/', $panel_html );
		$this->assertMatchesRegularExpression( '/<button[^>]*data-frcn-cookie-action="close-preferences"/', $panel_html );
	}

	/**
	 * The new frcn_cookie_preferences_categories action must actually fire
	 * while the panel renders, at the point where a PRO add-on would print
	 * its own per-category toggles — right after the static "Strictly
	 * necessary" section and before the action buttons.
	 */
	public function test_preferences_categories_action_fires_in_the_right_place() {
		update_option( 'frontconsent_settings', array( 'enable_cookie_notice' => true ) );

		add_action(
			'frcn_cookie_preferences_categories',
			static function () {
				echo '<!--TEST-MARKER-CATEGORIES-->';
			}
		);

		$html = $this->render_banner_html();

		$this->assertStringContainsString( '<!--TEST-MARKER-CATEGORIES-->', $html );

		$panel_start = strpos( $html, 'id="frcn-cookie-preferences"' );
		$panel_html  = substr( $html, $panel_start );

		$necessary_pos = strpos( $panel_html, 'Strictly necessary' );
		$marker_pos    = strpos( $panel_html, '<!--TEST-MARKER-CATEGORIES-->' );
		$actions_pos   = strpos( $panel_html, 'data-frcn-cookie-action="reject"' );

		$this->assertLessThan( $marker_pos, $necessary_pos );
		$this->assertLessThan( $actions_pos, $marker_pos );
	}

	/**
	 * With no add-on hooked in (Free, or PRO inactive), the panel shows
	 * exactly its own two built-in category toggles (analytics, marketing)
	 * and nothing else — no additional PRO-style category UI leaks through
	 * by default via the frcn_cookie_preferences_categories extension point.
	 */
	public function test_no_extra_category_ui_is_rendered_without_an_add_on() {
		update_option( 'frontconsent_settings', array( 'enable_cookie_notice' => true ) );

		$html = $this->render_banner_html();

		preg_match_all( '/data-frcn-category="([^"]+)"/', $html, $matches );

		$this->assertSame( array( 'analytics', 'marketing' ), $matches[1] );
	}

	/**
	 * The frcn_cookie_customize_button_label filter must change the
	 * rendered label.
	 */
	public function test_customize_button_label_filter_changes_the_rendered_label() {
		update_option( 'frontconsent_settings', array( 'enable_cookie_notice' => true ) );

		add_filter(
			'frcn_cookie_customize_button_label',
			static function () {
				return 'Manage my cookies';
			}
		);

		$html = $this->render_banner_html();

		$this->assertStringContainsString( 'Manage my cookies', $html );
		$this->assertStringNotContainsString( 'Customize cookie settings', $html );
	}

	/**
	 * The frcn_cookie_customize_button_enabled filter must be able to hide
	 * the button entirely.
	 */
	public function test_customize_button_enabled_filter_can_hide_the_button() {
		update_option( 'frontconsent_settings', array( 'enable_cookie_notice' => true ) );

		add_filter( 'frcn_cookie_customize_button_enabled', '__return_false' );

		$html = $this->render_banner_html();

		$this->assertStringNotContainsString( 'data-frcn-cookie-action="customize"', $html );
	}

	/**
	 * Without JavaScript there is no script to open/trap/close the
	 * preferences dialog, so the Customize button must be hidden via the
	 * existing <noscript> fallback rather than left sitting there dead —
	 * Accept/Reject keep working regardless via the no-JS <form> fallback.
	 */
	public function test_customize_button_is_hidden_in_the_noscript_fallback() {
		update_option( 'frontconsent_settings', array( 'enable_cookie_notice' => true ) );

		// print_noscript_style() reuses one fixed style handle across every
		// render_banner() call; wp_print_styles() only ever echoes a given
		// handle's inline CSS once per request and then marks it "done" —
		// harmless in production (one handle, one request), but an earlier
		// test in this same PHPUnit process may have already printed (and
		// so marked "done") this exact handle, in which case this call
		// would print an empty <noscript></noscript> instead. Resetting the
		// "done" list keeps this test's own assertions about the CSS
		// content independent of test execution order.
		wp_styles()->done = array();

		$html = $this->render_banner_html();

		$this->assertStringContainsString( '<noscript>', $html );

		$noscript_start = strpos( $html, '<noscript>' );
		$noscript_end   = strpos( $html, '</noscript>' );
		$noscript_html  = substr( $html, $noscript_start, $noscript_end - $noscript_start );

		$this->assertStringContainsString( '.frcn-cookie-notice__button--customize', $noscript_html );
		$this->assertStringContainsString( 'display: none', $noscript_html );
	}

	/**
	 * frcn_cookie_notice_before_actions is the existing extension point the
	 * button is printed through — confirms the button's own callback is
	 * actually registered on it, not on some new, parallel hook.
	 */
	public function test_customize_button_is_printed_via_the_existing_before_actions_hook() {
		update_option( 'frontconsent_settings', array( 'enable_cookie_notice' => true ) );

		// Removing every callback on the hook (rather than just this test's
		// own instance) is what proves the button has no other, parallel
		// rendering path — WP_UnitTestCase restores the original hook state
		// for every other test once this one tears down.
		remove_all_actions( 'frcn_cookie_notice_before_actions' );

		$html = $this->render_banner_html();

		$this->assertStringNotContainsString( 'data-frcn-cookie-action="customize"', $html );
	}

	/**
	 * Without real, independent toggles, the panel looks identical no matter
	 * what Accept all/Reject all/Save changes did — a visitor gets no visible
	 * confirmation of the effect of their choice, and (per the "analytics y
	 * marketing son diferentes" feedback) a single combined checkbox would
	 * misleadingly promise granularity it doesn't have. The Free tier ships
	 * two real, native, independently-checkable checkboxes —
	 * data-frcn-category="analytics" and data-frcn-category="marketing" —
	 * that frontconsent-cookie-notice.js syncs from the frontconsent_categories
	 * cookie on every open and reads back on Save changes/Accept all, so the
	 * panel always reflects what was actually recorded per category.
	 */
	public function test_panel_includes_real_analytics_and_marketing_category_toggles() {
		update_option( 'frontconsent_settings', array( 'enable_cookie_notice' => true ) );

		$html = $this->render_banner_html();

		$this->assertMatchesRegularExpression(
			'/<input[^>]*type="checkbox"[^>]*data-frcn-category="analytics"/',
			$html
		);
		$this->assertMatchesRegularExpression(
			'/<input[^>]*type="checkbox"[^>]*data-frcn-category="marketing"/',
			$html
		);
		$this->assertStringContainsString( 'Analytics', $html );
		$this->assertStringContainsString( 'Marketing', $html );
		$this->assertStringNotContainsString( 'data-frcn-category="optional"', $html );
		$this->assertStringNotContainsString( 'Analytics &amp; Marketing', $html );

		// Must appear after the static necessary block and before the PRO
		// extension point, matching where render_preferences_panel() prints it.
		$necessary_pos = strpos( $html, 'frcn-cookie-preferences__category--necessary' );
		$analytics_pos = strpos( $html, 'data-frcn-category="analytics"' );
		$marketing_pos = strpos( $html, 'data-frcn-category="marketing"' );

		$this->assertNotFalse( $necessary_pos );
		$this->assertNotFalse( $analytics_pos );
		$this->assertNotFalse( $marketing_pos );
		$this->assertGreaterThan( $necessary_pos, $analytics_pos );
		$this->assertGreaterThan( $analytics_pos, $marketing_pos );
	}
}
