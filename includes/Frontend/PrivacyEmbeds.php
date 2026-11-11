<?php
/**
 * Cookieless video embeds for FrontConsent.
 *
 * @package    FrontConsent
 * @author     Closemarketing
 * @copyright  2026 Closemarketing
 * @version    1.0
 */

namespace FrontConsent\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * PrivacyEmbeds class.
 *
 * Switches YouTube and Vimeo iframes to their cookieless modes before the
 * page reaches the visitor: YouTube's privacy-enhanced youtube-nocookie.com
 * domain, and Vimeo's dnt=1 (do not track) flag. Editors keep pasting normal
 * YouTube/Vimeo links; WordPress stores the regular embed URL and this class
 * rewrites it on output, so videos no longer set cookies before consent.
 *
 * The page is buffered (ob_start()) and only <iframe> opening tags are
 * touched. Every URL-carrying attribute in them is rewritten — src, plus
 * lazy-load copies such as data-src or WP Rocket's data-lazy-src — so
 * lazy-loaded embeds are covered too.
 *
 * @since 1.0.3
 */
class PrivacyEmbeds {

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( is_admin() ) {
			return;
		}

		add_action( 'template_redirect', array( $this, 'maybe_start_output_buffer' ) );
	}

	/**
	 * Whether YouTube/Vimeo iframes are switched to their cookieless modes.
	 *
	 * Only while the Cookie Notice is enabled: with it off, FrontConsent
	 * leaves the page output untouched.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$options = get_option( 'frontconsent_settings', array() );
		$enabled = (bool) ( $options['enable_cookie_notice'] ?? false );

		/**
		 * Filters whether FrontConsent rewrites YouTube embeds to
		 * youtube-nocookie.com and adds Vimeo's "dnt=1" flag.
		 *
		 * @param bool $enabled Default true while the Cookie Notice is enabled.
		 */
		return (bool) apply_filters( 'frcn_privacy_enhanced_embeds', $enabled );
	}

	/**
	 * Start buffering the frontend HTML output, only for regular page
	 * requests and only when the feature is enabled.
	 *
	 * @return void
	 */
	public function maybe_start_output_buffer() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return;
		}

		if ( ! self::is_enabled() ) {
			return;
		}

		ob_start( array( __CLASS__, 'rewrite_html' ) );
	}

	/**
	 * Switch YouTube and Vimeo iframes in the given HTML to their cookieless
	 * modes.
	 *
	 * Public static so it's directly unit-testable.
	 *
	 * @param string $html HTML to scan.
	 * @return string Rewritten HTML.
	 */
	public static function rewrite_html( $html ) {
		if ( '' === $html || false === stripos( $html, '<iframe' ) || ( false === stripos( $html, 'youtube.com/embed' ) && false === stripos( $html, 'player.vimeo.com/video' ) ) ) {
			return $html;
		}

		return (string) preg_replace_callback(
			'/<iframe\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i',
			function ( $matches ) {
				$tag = preg_replace( '#(//)(?:www\.)?youtube\.com/embed/#i', '$1www.youtube-nocookie.com/embed/', $matches[0] );

				return (string) preg_replace_callback(
					'#(player\.vimeo\.com/video/[^"\'\s>]*)#i',
					function ( $vimeo ) {
						$url = $vimeo[1];

						if ( preg_match( '/(?:\?|&amp;|&)dnt=/i', $url ) ) {
							return $url;
						}

						return $url . ( false === strpos( $url, '?' ) ? '?' : '&amp;' ) . 'dnt=1';
					},
					(string) $tag
				);
			},
			$html
		);
	}
}
