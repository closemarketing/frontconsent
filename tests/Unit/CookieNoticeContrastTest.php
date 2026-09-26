<?php
/**
 * Tests for CookieNotice's WCAG contrast color helpers.
 *
 * @package FrontConsent
 */

use FrontConsent\Frontend\CookieNotice;
use Yoast\WPTestUtils\WPIntegration\TestCase;

class CookieNoticeContrastTest extends TestCase {

	/**
	 * The default accent (#687df9) must resolve to a dark neutral text color,
	 * not white — this was a real bug: an earlier brightness heuristic picked
	 * white here despite it only reaching ~3.57:1 contrast, below the 4.5:1
	 * WCAG AA threshold for button text.
	 */
	public function test_default_accent_resolves_to_dark_text() {
		$this->assertSame( '#000000', CookieNotice::get_readable_text_color( '#687df9' ) );
	}

	/**
	 * A near-black accent should get white text — the opposite case from the
	 * default accent, confirming the helper picks per-color, not a fixed answer.
	 */
	public function test_very_dark_accent_resolves_to_white_text() {
		$this->assertSame( '#ffffff', CookieNotice::get_readable_text_color( '#111827' ) );
	}

	/**
	 * A mid-gray accent (#767676) is a near-tie borderline case (black wins
	 * against it at 4.623:1 vs white's 4.542:1) — a good regression guard
	 * against off-by-one comparisons between "tested" and "returned" colors,
	 * or between >= and > in the white_contrast/black_contrast comparison.
	 */
	public function test_borderline_gray_accent_resolves_to_black_text() {
		$this->assertSame( '#000000', CookieNotice::get_readable_text_color( '#767676' ) );
	}

	/**
	 * The default accent (#687df9) only reaches ~3.57:1 contrast against
	 * white — below the 4.5:1 threshold — so it must fall back to the dark
	 * neutral for text/links on the white panel, not be used verbatim.
	 */
	public function test_default_accent_falls_back_to_dark_neutral_on_white() {
		$this->assertSame( '#111827', CookieNotice::get_readable_on_white_color( '#687df9' ) );
	}

	/**
	 * A very light accent (near-white) can't reach 4.5:1 against a white
	 * panel no matter what, so it must fall back to the dark neutral instead
	 * of being used verbatim as illegible link text.
	 */
	public function test_light_accent_falls_back_to_dark_neutral_on_white() {
		$this->assertSame( '#111827', CookieNotice::get_readable_on_white_color( '#f5f5f5' ) );
	}

	/**
	 * Malformed input (not a valid hex color) must not throw or warn — it
	 * should degrade to treating the color as black, same as hex_to_rgb()'s
	 * own documented fallback.
	 */
	public function test_malformed_color_does_not_throw() {
		$this->assertSame( '#ffffff', CookieNotice::get_readable_text_color( 'not-a-color' ) );
	}

	/**
	 * 3-digit shorthand hex colors must expand correctly, not be misread as
	 * 6-digit ones.
	 */
	public function test_shorthand_hex_color_is_expanded_correctly() {
		// #fff (white) should behave identically to #ffffff.
		$this->assertSame(
			CookieNotice::get_readable_text_color( '#ffffff' ),
			CookieNotice::get_readable_text_color( '#fff' )
		);
	}

	/**
	 * Each corner-rounding preset must map to its own distinct CSS length.
	 */
	public function test_radius_presets_resolve_to_their_own_css_value() {
		$this->assertSame( '0px', CookieNotice::get_radius_value( 'none' ) );
		$this->assertSame( '12px', CookieNotice::get_radius_value( 'small' ) );
		$this->assertSame( '24px', CookieNotice::get_radius_value( 'large' ) );
	}

	/**
	 * An unknown preset (e.g. stale data from before an option was renamed)
	 * must degrade to the 'small' default rather than emitting an invalid or
	 * empty CSS value.
	 */
	public function test_unknown_radius_preset_falls_back_to_small() {
		$this->assertSame( CookieNotice::get_radius_value( 'small' ), CookieNotice::get_radius_value( 'not-a-real-preset' ) );
	}
}
