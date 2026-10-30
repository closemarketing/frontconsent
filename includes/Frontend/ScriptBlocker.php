<?php
/**
 * Generic script/iframe blocking module for FrontConsent.
 *
 * @package    FrontConsent
 * @author     Closemarketing
 * @copyright  2026 Closemarketing
 * @version    1.0
 */

namespace FrontConsent\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * ScriptBlocker class.
 *
 * Holds back arbitrary third-party `<script src="...">` and `<iframe
 * src="...">` embeds (YouTube, Maps, social widgets, custom tracking
 * snippets, etc.) until a visitor accepts the matching consent category —
 * a generic complement to CookieNotice's own Google Consent Mode default-
 * state signaling, which only affects Consent-Mode-aware tags.
 *
 * Site admins configure a list of {match_pattern, category} rules (see
 * Settings::sanitize_settings()) under the 'script_blocker_rules' option
 * key. Each rule's match_pattern is a plain, case-insensitive substring
 * matched against a tag's `src` attribute — not a full regular expression —
 * so a typo in a site admin's pattern can never turn into a broken or
 * catastrophic regex.
 *
 * Rewriting happens by buffering the entire frontend HTML output (via
 * ob_start()) and running a conservative, well-anchored search/replace over
 * matching `<script ...src="...">` and `<iframe ...src="...">` opening tags.
 * WordPress has no built-in safe HTML-tag-rewrite utility for this precise
 * case (rewriting one attribute of a specific tag while preserving every
 * other attribute, in any order/quoting style), so this is deliberately
 * narrow in scope: it only ever touches the two attributes below, and never
 * touches anything that isn't a <script>/<iframe> opening tag with a src
 * attribute matching a configured rule.
 *
 * @since 1.2.0
 */
class ScriptBlocker {

	/**
	 * Option name storing all FrontConsent settings.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'frontconsent_settings';

	/**
	 * Settings key storing the list of blocking rules.
	 *
	 * @var string
	 */
	const RULES_OPTION_KEY = 'script_blocker_rules';

	/**
	 * Consent categories a rule can be assigned to — the same 'analytics'/
	 * 'marketing' slugs CookieNotice::get_integration_default_category()
	 * already uses for tracking integrations, reused here rather than
	 * inventing a separate set of category slugs for this generic mechanism.
	 *
	 * @var string[]
	 */
	const CATEGORIES = array( 'analytics', 'marketing' );

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( is_admin() ) {
			return;
		}

		// Priority 0, before CookieNotice's own wp_head hooks: this only
		// registers the output buffer, it does not print anything, so
		// ordering relative to those doesn't matter — kept at the default
		// template_redirect priority is fine.
		add_action( 'template_redirect', array( $this, 'maybe_start_output_buffer' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Enqueue the client-side placeholder-revival script, but only when
	 * there is actually at least one configured rule — a site that never
	 * uses this feature never pays even the tiny cost of an extra enqueued
	 * file.
	 *
	 * @return void
	 */
	public function maybe_enqueue_assets() {
		if ( empty( self::get_rules() ) ) {
			return;
		}

		wp_enqueue_script(
			'frontconsent-script-blocker',
			FRCN_PLUGIN_URL . 'assets/cookie-notice/frontconsent-script-blocker.js',
			array(),
			FRCN_VERSION,
			true
		);
	}

	/**
	 * Start buffering the frontend HTML output, but only when there is
	 * actually at least one configured rule to apply — an admin/AJAX/REST
	 * request, or a site with no rules configured, never pays the cost of
	 * ob_start() or the regex scan at all.
	 *
	 * @return void
	 */
	public function maybe_start_output_buffer() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return;
		}

		$rules = self::get_rules();

		if ( empty( $rules ) ) {
			return;
		}

		ob_start( array( $this, 'rewrite_buffer' ) );
	}

	/**
	 * Ob_start() callback: rewrite the buffered HTML.
	 *
	 * @param string $html Buffered HTML output.
	 * @return string Rewritten HTML.
	 */
	public function rewrite_buffer( $html ) {
		return self::rewrite_html( $html, self::get_rules() );
	}

	/**
	 * Get the configured blocking rules from settings.
	 *
	 * @return array[] List of ['pattern' => string, 'category' => string] rules.
	 */
	public static function get_rules() {
		$options = get_option( self::OPTION_NAME, array() );
		$rules   = $options[ self::RULES_OPTION_KEY ] ?? array();

		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * Render the stored rules as the one-rule-per-line textarea format the
	 * settings screen edits (`pattern|category`) — the inverse of
	 * parse_rules_text().
	 *
	 * @param array[] $rules List of ['pattern' => string, 'category' => string] rules.
	 * @return string Textarea contents.
	 */
	public static function rules_to_text( $rules ) {
		$lines = array();

		foreach ( $rules as $rule ) {
			$pattern = trim( (string) ( $rule['pattern'] ?? '' ) );

			if ( '' === $pattern ) {
				continue;
			}

			$category = (string) ( $rule['category'] ?? 'marketing' );
			$lines[]  = $pattern . '|' . $category;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Parse the one-rule-per-line textarea format (`pattern|category`) the
	 * settings screen accepts back into a validated rules array — the
	 * inverse of rules_to_text(). Blank lines and lines with an empty
	 * pattern are dropped; an unrecognized or missing category falls back
	 * to 'marketing'.
	 *
	 * @param string $text Raw textarea contents.
	 * @return array[] List of ['pattern' => string, 'category' => string] rules.
	 */
	public static function parse_rules_text( $text ) {
		$rules = array();

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			$parts    = explode( '|', $line, 2 );
			$pattern  = trim( $parts[0] );
			$category = isset( $parts[1] ) ? sanitize_key( trim( $parts[1] ) ) : '';

			if ( '' === $pattern ) {
				continue;
			}

			if ( ! in_array( $category, self::CATEGORIES, true ) ) {
				$category = 'marketing';
			}

			$rules[] = array(
				'pattern'  => $pattern,
				'category' => $category,
			);
		}

		return $rules;
	}

	/**
	 * Rewrite matching `<script src="...">` and `<iframe src="...">` opening
	 * tags in the given HTML to inert placeholders, given a list of rules.
	 *
	 * Public static so it's directly unit-testable without needing to go
	 * through the output-buffering machinery at all.
	 *
	 * @param string $html  HTML to scan.
	 * @param array  $rules List of ['pattern' => string, 'category' => string] rules.
	 * @return string Rewritten HTML.
	 */
	public static function rewrite_html( $html, $rules ) {
		// stripos(), not strpos(): a case-insensitive quick check, since the
		// tag/attribute matching below is itself case-insensitive (an
		// uppercase <SCRIPT SRC="..."> is still valid HTML) — this is only a
		// cheap short-circuit for the common case of no <script>/<iframe>
		// tags with a src attribute at all, never a substitute for the real
		// per-rule matching in match_category().
		if ( empty( $rules ) || '' === $html || false === stripos( $html, 'src' ) ) {
			return $html;
		}

		$html = self::rewrite_tags( $html, 'script', $rules );
		$html = self::rewrite_tags( $html, 'iframe', $rules );

		return $html;
	}

	/**
	 * Rewrite every matching opening tag of the given tag name.
	 *
	 * The attribute-boundary pattern `(?:[^>"\']|"[^"]*"|'[^']*')*` is what
	 * lets the tag-matching regex safely stop at the opening tag's actual
	 * closing '>' even when a quoted attribute value itself contains a '>'
	 * character (e.g. a stray one in a title/aria-label) — a plain `[^>]*`
	 * would stop too early there.
	 *
	 * @param string $html     HTML to scan.
	 * @param string $tag_name 'script' or 'iframe'.
	 * @param array  $rules    List of ['pattern' => string, 'category' => string] rules.
	 * @return string Rewritten HTML.
	 */
	private static function rewrite_tags( $html, $tag_name, $rules ) {
		$pattern = '/<' . preg_quote( $tag_name, '/' ) . '\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/i';

		return preg_replace_callback(
			$pattern,
			function ( $matches ) use ( $tag_name, $rules ) {
				return self::rewrite_opening_tag( $tag_name, $matches[0], $matches[1], $rules );
			},
			$html
		);
	}

	/**
	 * Rewrite a single opening tag if its src attribute matches a rule.
	 *
	 * @param string $tag_name    'script' or 'iframe'.
	 * @param string $full_tag    The full matched opening tag, e.g. '<script src="...">'.
	 * @param string $attrs       The tag's raw attribute string (between the tag name and '>').
	 * @param array  $rules       List of ['pattern' => string, 'category' => string] rules.
	 * @return string The original tag (untouched) or the rewritten placeholder tag.
	 */
	private static function rewrite_opening_tag( $tag_name, $full_tag, $attrs, $rules ) {
		$src = self::extract_attribute( $attrs, 'src' );

		if ( null === $src || '' === $src ) {
			// No src attribute at all (e.g. an inline <script> with no src) —
			// nothing for this generic mechanism to hold back.
			return $full_tag;
		}

		$category = self::match_category( $src, $rules );

		if ( null === $category ) {
			return $full_tag;
		}

		// Self-closing tags (mostly relevant for <iframe src="..." />) keep
		// their trailing slash exactly where a browser expects it — at the
		// very end of the tag — rather than in the middle of the newly
		// appended attributes.
		$self_closing = (bool) preg_match( '/\/\s*$/', $attrs );
		$attrs        = preg_replace( '/\/\s*$/', '', $attrs );

		// Remove the existing src (and, defensively, any existing type or
		// data-frcn-* attributes) from the attribute string; the placeholder
		// attributes are appended fresh below instead of trying to patch
		// them in place, which would only re-introduce the same
		// order/quoting ambiguity this method exists to avoid.
		$attrs = self::remove_attribute( $attrs, 'src' );
		$attrs = self::remove_attribute( $attrs, 'type' );
		$attrs = self::remove_attribute( $attrs, 'data-frcn-category' );
		$attrs = self::remove_attribute( $attrs, 'data-frcn-src' );

		$placeholder_attrs = ' data-frcn-category="' . esc_attr( $category ) . '" data-frcn-src="' . esc_attr( $src ) . '"';

		if ( 'script' === $tag_name ) {
			// A script whose type isn't a supported JavaScript MIME type is
			// never fetched or executed by the browser, per the HTML spec's
			// "prepare the script element" algorithm — removing src (renamed
			// to data-frcn-src above) makes that doubly certain regardless.
			$new_tag = '<script' . $attrs . ' type="text/plain"' . $placeholder_attrs . '>';
		} else {
			// about:blank, not simply omitting src, so the iframe never sits
			// with no src at all (which some browsers treat as loading the
			// parent document itself) and never keeps the real tracking src
			// live in any form.
			$new_tag = '<iframe' . $attrs . $placeholder_attrs . ' src="about:blank">';
		}

		if ( $self_closing ) {
			$new_tag = substr( $new_tag, 0, -1 ) . ' />';
		}

		return $new_tag;
	}

	/**
	 * Check whether a src value matches any configured rule, returning the
	 * first match's category.
	 *
	 * @param string $src   The tag's src attribute value.
	 * @param array  $rules List of ['pattern' => string, 'category' => string] rules.
	 * @return string|null The matching category, or null when no rule matches.
	 */
	private static function match_category( $src, $rules ) {
		foreach ( $rules as $rule ) {
			$pattern = (string) ( $rule['pattern'] ?? '' );

			if ( '' === $pattern ) {
				continue;
			}

			if ( false !== stripos( $src, $pattern ) ) {
				return (string) ( $rule['category'] ?? 'marketing' );
			}
		}

		return null;
	}

	/**
	 * Extract an attribute's value from a raw attribute string.
	 *
	 * @param string $attrs         Raw attribute string.
	 * @param string $attribute_name Attribute name, e.g. 'src'.
	 * @return string|null The attribute's value (unescaped), or null if absent.
	 */
	private static function extract_attribute( $attrs, $attribute_name ) {
		// (?<![\w-]) instead of a plain \b: a plain \b would also match right
		// after a hyphen (e.g. wrongly matching 'src' inside a real, common
		// 'data-src' lazy-load attribute — '-' is a non-word character, so a
		// word boundary exists between it and the 's' that follows). This
		// requires the attribute name to start a fresh token instead.
		$pattern = '/(?<![\w-])' . preg_quote( $attribute_name, '/' ) . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i';

		if ( ! preg_match( $pattern, $attrs, $matches ) ) {
			return null;
		}

		if ( isset( $matches[1] ) && '' !== $matches[1] ) {
			return html_entity_decode( $matches[1], ENT_QUOTES );
		}

		if ( isset( $matches[2] ) && '' !== $matches[2] ) {
			return html_entity_decode( $matches[2], ENT_QUOTES );
		}

		return isset( $matches[3] ) ? html_entity_decode( $matches[3], ENT_QUOTES ) : '';
	}

	/**
	 * Remove an attribute (any quoting style, or unquoted) from a raw
	 * attribute string.
	 *
	 * @param string $attrs          Raw attribute string.
	 * @param string $attribute_name Attribute name, e.g. 'src'.
	 * @return string The attribute string with that attribute removed.
	 */
	private static function remove_attribute( $attrs, $attribute_name ) {
		// Uses the same negative-lookbehind technique extract_attribute() uses above, for the same reason.
		$pattern = '/\s*(?<![\w-])' . preg_quote( $attribute_name, '/' ) . '\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'>]+)/i';

		return (string) preg_replace( $pattern, '', $attrs );
	}
}
