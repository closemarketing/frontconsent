<?php
/**
 * Tests for the cookieless YouTube/Vimeo embed rewrite.
 *
 * @package FrontConsent
 */

use FrontConsent\Frontend\PrivacyEmbeds;
use Yoast\WPTestUtils\WPIntegration\TestCase;

class PrivacyEmbedsTest extends TestCase {

	public function tear_down() {
		delete_option( 'frontconsent_settings' );
		parent::tear_down();
	}

	/**
	 * A youtube.com embed is switched to the privacy-enhanced
	 * youtube-nocookie.com domain, including a lazy-loaded copy of the URL.
	 */
	public function test_youtube_iframe_is_switched_to_nocookie_domain() {
		$html = '<iframe src="https://www.youtube.com/embed/abc123?rel=0" data-lazy-src="https://youtube.com/embed/abc123"></iframe>';

		$result = PrivacyEmbeds::rewrite_html( $html );

		$this->assertStringContainsString( 'src="https://www.youtube-nocookie.com/embed/abc123?rel=0"', $result );
		$this->assertStringContainsString( 'data-lazy-src="https://www.youtube-nocookie.com/embed/abc123"', $result );
		$this->assertStringNotContainsString( '//www.youtube.com/embed', $result );
	}

	/**
	 * The oEmbed markup WordPress stores for a pasted YouTube link keeps every
	 * other attribute untouched; only the domain changes.
	 */
	public function test_oembed_markup_only_changes_the_domain() {
		$html = '<iframe title="Video" width="500" height="281" src="https://www.youtube.com/embed/-O1XnBORs9w?feature=oembed" frameborder="0" allowfullscreen></iframe>';

		$this->assertSame(
			str_replace( 'www.youtube.com', 'www.youtube-nocookie.com', $html ),
			PrivacyEmbeds::rewrite_html( $html )
		);
	}

	/**
	 * A Vimeo player embed gets dnt=1, appended to an existing query string
	 * when there is one, and is left alone when it already carries it.
	 */
	public function test_vimeo_iframe_gets_do_not_track_flag_once() {
		$this->assertStringContainsString(
			'src="https://player.vimeo.com/video/123?dnt=1"',
			PrivacyEmbeds::rewrite_html( '<iframe src="https://player.vimeo.com/video/123"></iframe>' )
		);
		$this->assertStringContainsString(
			'src="https://player.vimeo.com/video/123?h=abc&amp;dnt=1"',
			PrivacyEmbeds::rewrite_html( '<iframe src="https://player.vimeo.com/video/123?h=abc"></iframe>' )
		);

		$already = '<iframe src="https://player.vimeo.com/video/123?h=abc&amp;dnt=1"></iframe>';
		$this->assertSame( $already, PrivacyEmbeds::rewrite_html( $already ) );
	}

	/**
	 * Only iframes are rewritten: a plain link to a YouTube video stays as is.
	 */
	public function test_youtube_links_outside_iframes_are_untouched() {
		$html = '<a href="https://www.youtube.com/embed/abc123">Watch</a><iframe src="https://example.com/"></iframe>';

		$this->assertSame( $html, PrivacyEmbeds::rewrite_html( $html ) );
	}

	/**
	 * The rewrite follows the Cookie Notice: off while it is disabled, on
	 * while it is enabled, and the filter can still turn it off.
	 */
	public function test_is_enabled_follows_the_cookie_notice_and_the_filter() {
		$this->assertFalse( PrivacyEmbeds::is_enabled() );

		update_option( 'frontconsent_settings', array( 'enable_cookie_notice' => true ) );
		$this->assertTrue( PrivacyEmbeds::is_enabled() );

		add_filter( 'frcn_privacy_enhanced_embeds', '__return_false' );
		$this->assertFalse( PrivacyEmbeds::is_enabled() );
		remove_filter( 'frcn_privacy_enhanced_embeds', '__return_false' );
	}
}
