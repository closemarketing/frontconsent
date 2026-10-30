<?php
/**
 * Tests for ScriptBlocker's output-rewriting logic.
 *
 * @package FrontConsent
 */

use FrontConsent\Frontend\ScriptBlocker;
use Yoast\WPTestUtils\WPIntegration\TestCase;

class ScriptBlockerTest extends TestCase {

	/**
	 * A matching <script src="..."> tag becomes an inert placeholder: type
	 * switched to text/plain, src moved to data-frcn-src, and the matching
	 * rule's category recorded in data-frcn-category.
	 */
	public function test_matching_script_is_rewritten_to_placeholder() {
		$html  = '<script src="https://www.youtube.com/embed/abc123"></script>';
		$rules = array( array( 'pattern' => 'youtube.com/embed', 'category' => 'marketing' ) );

		$result = ScriptBlocker::rewrite_html( $html, $rules );

		// A leading space, not a bare substring check: 'data-frcn-src="..."'
		// itself contains the substring 'src="...."' too, which would make a
		// naive check pass even if the *live* src attribute were still there.
		$this->assertStringNotContainsString( ' src="https://www.youtube.com/embed/abc123"', $result );
		$this->assertStringContainsString( 'type="text/plain"', $result );
		$this->assertStringContainsString( 'data-frcn-category="marketing"', $result );
		$this->assertStringContainsString( 'data-frcn-src="https://www.youtube.com/embed/abc123"', $result );
		$this->assertStringContainsString( '</script>', $result );
	}

	/**
	 * A matching <iframe src="..."> tag gets its src swapped to about:blank
	 * (never left live, never simply omitted) with the real URL moved to
	 * data-frcn-src.
	 */
	public function test_matching_iframe_is_rewritten_to_placeholder() {
		$html  = '<iframe src="https://www.google.com/maps/embed?pb=123" width="600" height="450"></iframe>';
		$rules = array( array( 'pattern' => 'google.com/maps', 'category' => 'marketing' ) );

		$result = ScriptBlocker::rewrite_html( $html, $rules );

		$this->assertStringContainsString( 'src="about:blank"', $result );
		$this->assertStringContainsString( 'data-frcn-category="marketing"', $result );
		$this->assertStringContainsString( 'data-frcn-src="https://www.google.com/maps/embed?pb=123"', $result );
		// Unrelated attributes must survive the rewrite untouched.
		$this->assertStringContainsString( 'width="600"', $result );
		$this->assertStringContainsString( 'height="450"', $result );
	}

	/**
	 * A self-closing <iframe ... /> tag keeps its trailing slash at the end
	 * of the tag, not in the middle of the newly appended attributes.
	 */
	public function test_self_closing_iframe_keeps_trailing_slash_at_the_end() {
		$html  = '<iframe src="https://www.google.com/maps/embed?pb=1" />';
		$rules = array( array( 'pattern' => 'google.com/maps', 'category' => 'marketing' ) );

		$result = ScriptBlocker::rewrite_html( $html, $rules );

		$this->assertMatchesRegularExpression( '/\/>\s*$/', $result );
		$this->assertStringNotContainsString( '/ data-frcn', $result );
	}

	/**
	 * A non-matching script tag is left completely untouched.
	 */
	public function test_non_matching_script_is_left_untouched() {
		$html  = '<script src="https://example.com/app.js" id="my-app"></script>';
		$rules = array( array( 'pattern' => 'youtube.com/embed', 'category' => 'marketing' ) );

		$result = ScriptBlocker::rewrite_html( $html, $rules );

		$this->assertSame( $html, $result );
	}

	/**
	 * Surrounding unrelated markup (a plain <div>, a script with no src)
	 * must not be mangled by the rewrite.
	 */
	public function test_unrelated_markup_is_untouched() {
		$html  = '<div class="wrapper"><script>var a = 1 > 0;</script><p>Hello &gt; world</p></div>';
		$rules = array( array( 'pattern' => 'youtube.com/embed', 'category' => 'marketing' ) );

		$result = ScriptBlocker::rewrite_html( $html, $rules );

		$this->assertSame( $html, $result );
	}

	/**
	 * No rules configured at all means the HTML is returned completely
	 * unmodified — this is also what lets ScriptBlocker::maybe_start_output_buffer()
	 * skip calling ob_start() entirely on a site that doesn't use this feature.
	 */
	public function test_no_rules_means_no_rewriting() {
		$html = '<script src="https://www.youtube.com/embed/abc123"></script>';

		$this->assertSame( $html, ScriptBlocker::rewrite_html( $html, array() ) );
	}

	/**
	 * Attribute order must not matter: a src attribute appearing after other
	 * attributes is still found and rewritten.
	 */
	public function test_attribute_order_does_not_matter() {
		$html  = '<script id="my-tracker" async src="https://tracker.example.com/pixel.js" defer></script>';
		$rules = array( array( 'pattern' => 'tracker.example.com', 'category' => 'analytics' ) );

		$result = ScriptBlocker::rewrite_html( $html, $rules );

		$this->assertStringContainsString( 'id="my-tracker"', $result );
		$this->assertStringContainsString( 'async', $result );
		$this->assertStringContainsString( 'defer', $result );
		$this->assertStringContainsString( 'data-frcn-category="analytics"', $result );
		$this->assertStringContainsString( 'data-frcn-src="https://tracker.example.com/pixel.js"', $result );
		$this->assertStringNotContainsString( ' src="https://tracker.example.com/pixel.js"', $result );
	}

	/**
	 * Single-quoted attribute values must be handled the same as
	 * double-quoted ones.
	 */
	public function test_single_quoted_src_is_handled() {
		$html  = "<script src='https://tracker.example.com/pixel.js'></script>";
		$rules = array( array( 'pattern' => 'tracker.example.com', 'category' => 'analytics' ) );

		$result = ScriptBlocker::rewrite_html( $html, $rules );

		$this->assertStringContainsString( 'data-frcn-src="https://tracker.example.com/pixel.js"', $result );
		$this->assertStringContainsString( 'type="text/plain"', $result );
	}

	/**
	 * Extra/irregular whitespace around the '=' in the src attribute must
	 * not prevent a match.
	 */
	public function test_extra_whitespace_around_src_attribute_is_handled() {
		$html  = '<script src  =  "https://tracker.example.com/pixel.js" ></script>';
		$rules = array( array( 'pattern' => 'tracker.example.com', 'category' => 'analytics' ) );

		$result = ScriptBlocker::rewrite_html( $html, $rules );

		$this->assertStringContainsString( 'data-frcn-src="https://tracker.example.com/pixel.js"', $result );
	}

	/**
	 * A real-world 'data-src' lazy-load attribute (used by lazysizes and
	 * similar libraries) must not be confused with the actual 'src'
	 * attribute — both contain the literal substring 'src=', so a naive
	 * `\bsrc\b` regex would wrongly match inside 'data-src' too, since '-'
	 * is a non-word character and creates a word boundary right before the
	 * 's'.
	 */
	public function test_data_src_attribute_is_not_confused_with_src() {
		$html  = '<script data-src="https://lazy.example.com/other.js" src="https://tracker.example.com/pixel.js" data-lazy="true"></script>';
		$rules = array( array( 'pattern' => 'tracker.example.com', 'category' => 'analytics' ) );

		$result = ScriptBlocker::rewrite_html( $html, $rules );

		$this->assertStringContainsString( 'data-frcn-src="https://tracker.example.com/pixel.js"', $result );
		$this->assertStringContainsString( 'data-src="https://lazy.example.com/other.js"', $result );
		$this->assertStringContainsString( 'data-lazy="true"', $result );
	}

	/**
	 * Multiple matching and non-matching tags on the same page are each
	 * handled independently and correctly — a shared regression guard
	 * against a naive greedy regex that jumps from one tag's opening '<'
	 * all the way to a later tag's closing '>'.
	 */
	public function test_multiple_tags_are_each_handled_independently() {
		$html = '<script src="https://www.youtube.com/embed/one"></script>'
			. '<script src="https://example.com/app.js"></script>'
			. '<iframe src="https://www.google.com/maps/embed?pb=2"></iframe>'
			. '<iframe src="https://example.com/other-embed"></iframe>';

		$rules = array(
			array( 'pattern' => 'youtube.com/embed', 'category' => 'marketing' ),
			array( 'pattern' => 'google.com/maps', 'category' => 'marketing' ),
		);

		$result = ScriptBlocker::rewrite_html( $html, $rules );

		$this->assertStringContainsString( 'data-frcn-src="https://www.youtube.com/embed/one"', $result );
		$this->assertStringContainsString( 'data-frcn-src="https://www.google.com/maps/embed?pb=2"', $result );
		$this->assertStringContainsString( 'src="https://example.com/app.js"', $result );
		$this->assertStringContainsString( 'src="https://example.com/other-embed"', $result );
		$this->assertStringNotContainsString( 'data-frcn-src="https://example.com/app.js"', $result );
		$this->assertStringNotContainsString( 'data-frcn-src="https://example.com/other-embed"', $result );
	}

	/**
	 * A rule with an unrecognized/missing category falls back to
	 * 'marketing', matching Settings::parse_rules_text()'s own fallback.
	 */
	public function test_rule_with_missing_category_falls_back_to_marketing() {
		$html  = '<script src="https://tracker.example.com/pixel.js"></script>';
		$rules = array( array( 'pattern' => 'tracker.example.com' ) );

		$result = ScriptBlocker::rewrite_html( $html, $rules );

		$this->assertStringContainsString( 'data-frcn-category="marketing"', $result );
	}

	/**
	 * Matching is case-insensitive, both for the pattern against the src,
	 * and regardless of the tag name's own casing.
	 */
	public function test_matching_is_case_insensitive() {
		$html  = '<SCRIPT SRC="https://www.YouTube.com/Embed/abc"></SCRIPT>';
		$rules = array( array( 'pattern' => 'youtube.com/embed', 'category' => 'marketing' ) );

		$result = ScriptBlocker::rewrite_html( $html, $rules );

		$this->assertStringContainsString( 'data-frcn-src="https://www.YouTube.com/Embed/abc"', $result );
	}

	/**
	 * ScriptBlocker::parse_rules_text() and rules_to_text() round-trip the
	 * settings screen's one-rule-per-line textarea format.
	 */
	public function test_parse_rules_text_round_trips_with_rules_to_text() {
		$text = "youtube.com/embed|marketing\ntracker.example.com|analytics";

		$rules = ScriptBlocker::parse_rules_text( $text );

		$this->assertSame(
			array(
				array( 'pattern' => 'youtube.com/embed', 'category' => 'marketing' ),
				array( 'pattern' => 'tracker.example.com', 'category' => 'analytics' ),
			),
			$rules
		);
		$this->assertSame( $text, ScriptBlocker::rules_to_text( $rules ) );
	}

	/**
	 * Blank lines and a missing/unrecognized category in the textarea
	 * format are handled gracefully rather than producing a broken rule.
	 */
	public function test_parse_rules_text_handles_blank_lines_and_unknown_category() {
		$rules = ScriptBlocker::parse_rules_text( "\nyoutube.com/embed|not-a-real-category\n\n  \n" );

		$this->assertSame(
			array( array( 'pattern' => 'youtube.com/embed', 'category' => 'marketing' ) ),
			$rules
		);
	}
}
