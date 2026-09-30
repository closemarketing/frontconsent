<?php
/**
 * Cookie Notice module for FrontConsent.
 *
 * @package    FrontConsent
 * @author     Closemarketing
 * @copyright  2026 Closemarketing
 * @version    1.0
 */

namespace FrontConsent\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * CookieNotice class.
 *
 * Displays a configurable cookie consent banner on the frontend, conditionally
 * loads Google Tag Manager / GA4 only after consent is granted, and keeps a
 * lightweight aggregate acceptance-rate counter.
 *
 * The banner markup and assets are always enqueued/rendered (never gated by
 * the visitor's own consent cookie) so that a full-page cache serves the exact
 * same HTML to every visitor of a given URL. All consent-specific behavior —
 * hiding the banner, and loading GTM/GA4 — happens client-side instead.
 *
 * @since 1.0.0
 */
class CookieNotice {

	/**
	 * Option name storing the aggregate accepted counter.
	 *
	 * @var string
	 */
	const STATS_OPTION_ACCEPTED = 'frontconsent_cookie_notice_accepted_count';

	/**
	 * Option name storing the aggregate rejected counter.
	 *
	 * @var string
	 */
	const STATS_OPTION_REJECTED = 'frontconsent_cookie_notice_rejected_count';

	/**
	 * Nonce action used to protect the consent-logging AJAX endpoint.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'frcn_cookie_notice_nonce';

	/**
	 * Tracking tools detectable from a pasted snippet or plain ID (see
	 * detect_tracking_snippet()) and storable as {type, id} records in the
	 * shared cookie_notice_tracking_integrations list. 'gtm' and 'ga4' are
	 * FrontConsent's own native types; the rest are additional tools.
	 *
	 * @var string[]
	 */
	const TRACKING_TYPES = array( 'gtm', 'ga4', 'clientify_analytics_plus', 'clientify_analytics_classic', 'brevo', 'openai_chatgpt_ads' );

	/**
	 * Constructor.
	 */
	public function __construct() {
		// These listeners must be available outside wp-admin for cron and integrations.
		add_action( 'update_option_frontconsent_settings', array( $this, 'handle_frontconsent_settings_updated' ), 10, 3 );
		add_action( 'add_option_frontconsent_settings', array( $this, 'handle_frontconsent_settings_added' ), 10, 2 );

		if ( ! is_admin() && $this->is_enabled() ) {
			// Priority 1: must run before any analytics/ads tag (Google Site Kit,
			// a manually pasted GTM/gtag snippet, etc.) reads its consent defaults —
			// Google Consent Mode only holds those tags back if 'default' is queued
			// on the page's dataLayer before they call gtag('config', ...).
			add_action( 'wp_head', array( $this, 'render_consent_mode_default' ), 1 );
			// Also early (wp_head, not wp_footer): for an already-accepted visitor
			// this is what actually requests GTM/GA4, so it needs to run long
			// before a slow page finishes loading — a footer-only bootstrap risks
			// missing an early interaction or a request that never reaches the
			// footer at all, silently undercounting analytics.
			add_action( 'wp_head', array( $this, 'render_consent_bootstrap_script' ), 2 );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			add_action( 'wp_footer', array( $this, 'render_banner' ) );
		}

		// Registered unconditionally (unlike the block above): render_banner_markup()
		// itself only ever runs while the module is enabled, so gating this on
		// is_enabled() here too would be redundant — and would wrongly stay
		// unregistered for the rest of the request if a test (or an early
		// integration) constructs this class before the option is saved.
		// Prints the "Customize cookie settings" button through the same
		// extension point a PRO add-on would use, so Free and PRO share one
		// code path — see render_banner_markup()'s own docblock for
		// frcn_cookie_notice_before_actions.
		add_action( 'frcn_cookie_notice_before_actions', array( $this, 'render_customize_button' ) );

		// The endpoints must stay available for logged-out and logged-in visitors alike.
		add_action( 'wp_ajax_frcn_log_cookie_consent', array( $this, 'log_consent_callback' ) );
		add_action( 'wp_ajax_nopriv_frcn_log_cookie_consent', array( $this, 'log_consent_callback' ) );
		add_action( 'wp_ajax_frcn_get_cookie_notice_config', array( $this, 'get_config_callback' ) );
		add_action( 'wp_ajax_nopriv_frcn_get_cookie_notice_config', array( $this, 'get_config_callback' ) );
		add_action( 'wp_ajax_frcn_get_cookie_notice_log_nonce', array( $this, 'get_log_nonce_callback' ) );
		add_action( 'wp_ajax_nopriv_frcn_get_cookie_notice_log_nonce', array( $this, 'get_log_nonce_callback' ) );

		// The no-JS <form> fallback (see render_banner_markup()) submits here
		// directly, bypassing AJAX entirely — JS intercepts the same buttons'
		// click events and never lets this form actually submit when it can
		// run, so this handler only ever runs for a visitor without JavaScript.
		add_action( 'admin_post_frcn_log_cookie_decision', array( $this, 'log_consent_form_callback' ) );
		add_action( 'admin_post_nopriv_frcn_log_cookie_decision', array( $this, 'log_consent_form_callback' ) );
	}

	/**
	 * Handle a saved FrontConsent settings option.
	 *
	 * @param mixed  $old_value Previous option value.
	 * @param mixed  $new_value New option value.
	 * @param string $option_name Option name.
	 * @return void
	 */
	public function handle_frontconsent_settings_updated( $old_value, $new_value, $option_name ) {
		if ( 'frontconsent_settings' !== $option_name || ! is_array( $old_value ) || ! is_array( $new_value ) || ! self::settings_changed( $old_value, $new_value ) ) {
			return;
		}

		self::handle_settings_changed( $old_value, $new_value );
	}

	/**
	 * Handle the first save of the FrontConsent settings option.
	 *
	 * @param string $option_name Option name.
	 * @param mixed  $new_value New option value.
	 * @return void
	 */
	public function handle_frontconsent_settings_added( $option_name, $new_value ) {
		if ( ! is_array( $new_value ) || ! self::settings_changed( array(), $new_value ) ) {
			return;
		}

		self::handle_settings_changed( array(), $new_value );
	}

	/**
	 * Invalidate page caches after Cookie Notice settings change.
	 *
	 * Public static — Migration calls this directly right after its own
	 * first write to 'frontconsent_settings', since that write happens
	 * before this class is even constructed (and its
	 * update_option_frontconsent_settings/add_option_frontconsent_settings
	 * hooks registered), so the generic option hooks alone would miss it.
	 *
	 * @param array $old_options Previous settings.
	 * @param array $new_options New settings.
	 * @return void
	 */
	public static function handle_settings_changed( $old_options, $new_options ) {
		$cache_was_purged = false;

		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$cache_was_purged = true;
		}

		/**
		 * Fires after Cookie Notice settings affecting frontend output have changed.
		 *
		 * Cache integrations can use this action to invalidate cached pages.
		 *
		 * @param array $old_options Previous FrontConsent settings.
		 * @param array $new_options New FrontConsent settings.
		 */
		do_action( 'frcn_cookie_notice_settings_updated', $old_options, $new_options );

		$user_id = get_current_user_id();
		if ( $user_id ) {
			set_transient( 'frcn_cookie_notice_cache_notice_' . $user_id, $cache_was_purged ? 'wp-rocket' : 'manual', MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Check whether Cookie Notice settings changed from their frontend defaults.
	 *
	 * @param array $old_options Previous settings.
	 * @param array $new_options New settings.
	 * @return bool
	 */
	public static function settings_changed( $old_options, $new_options ) {
		$defaults = array(
			'enable_cookie_notice'                => false,
			'cookie_notice_message'               => '',
			'cookie_notice_accept_label'          => '',
			'cookie_notice_reject_label'          => '',
			'cookie_notice_policy_page_id'        => 0,
			'cookie_notice_layout'                => 'bar',
			'cookie_notice_position'              => 'bottom-right',
			'cookie_notice_color'                 => '#687df9',
			'cookie_notice_bg_color'              => '#ffffff',
			'cookie_notice_radius'                => 'small',
			'cookie_notice_expiration_days'       => 365,
			'cookie_notice_tracking_integrations' => array(),
		);

		foreach ( $defaults as $key => $default ) {
			$old_value = array_key_exists( $key, $old_options ) ? $old_options[ $key ] : $default;
			$new_value = array_key_exists( $key, $new_options ) ? $new_options[ $key ] : $default;

			if ( $old_value !== $new_value ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if the Cookie Notice module is enabled.
	 *
	 * @return bool
	 */
	private function is_enabled() {
		$options = get_option( 'frontconsent_settings', array() );
		return (bool) ( $options['enable_cookie_notice'] ?? false );
	}

	/**
	 * Name of the cookie storing the visitor's consent decision.
	 *
	 * On multisite, COOKIEPATH alone can't isolate the root site from its
	 * subsites (the root site's path is '/', which every subsite path sits
	 * under), so the blog ID is folded into the cookie name itself instead.
	 *
	 * @return string
	 */
	private function get_cookie_name() {
		if ( is_multisite() ) {
			return 'frcn_cookie_consent_' . get_current_blog_id();
		}

		return 'frcn_cookie_consent';
	}

	/**
	 * Get the admin-ajax.php URL, forced onto the frontend's own scheme and host.
	 *
	 * The admin_url() function can point at a different scheme (e.g.
	 * FORCE_SSL_ADMIN on an http frontend) and even a different host (when
	 * WP_HOME and WP_SITEURL are configured separately) than the page that's
	 * about to fetch() it. 'credentials: same-origin' then omits the consent
	 * cookie, and the browser's CORS check blocks the response regardless —
	 * so only the admin-ajax.php path is taken from admin_url(); the scheme
	 * and host always come from the current request and home_url() instead,
	 * keeping the AJAX call same-origin with the frontend.
	 *
	 * @return string
	 */
	private function get_ajax_url() {
		return $this->get_frontend_origin_admin_url( 'admin-ajax.php' );
	}

	/**
	 * Get an admin-*.php URL, forced onto the frontend's own scheme and host —
	 * used by get_ajax_url() (admin-ajax.php) and the no-JS <form> fallback's
	 * action attribute (admin-post.php). Both need the same fix: on an install
	 * where WP_HOME and WP_SITEURL use different hosts, admin_url()'s own host
	 * would set the consent cookie under the *backend* host, and a browser
	 * redirected back to the *frontend* host would never see that cookie again
	 * — same underlying problem as get_ajax_url()'s CORS/same-origin concern,
	 * just surfacing as a cookie-host mismatch instead for a plain form POST.
	 *
	 * @param string $admin_script E.g. 'admin-ajax.php' or 'admin-post.php'.
	 * @return string
	 */
	private function get_frontend_origin_admin_url( $admin_script ) {
		$home_parts = wp_parse_url( home_url() );
		$admin_path = (string) wp_parse_url( admin_url( $admin_script ), PHP_URL_PATH );

		$scheme = is_ssl() ? 'https' : 'http';
		$host   = $home_parts['host'] ?? '';
		$port   = isset( $home_parts['port'] ) ? ':' . $home_parts['port'] : '';

		return $scheme . '://' . $host . $port . $admin_path;
	}

	/**
	 * Get the visitor's current page URL, for the no-JS <form> fallback's
	 * redirect-back-here field. wp_validate_redirect() (checked before it's
	 * ever used to actually redirect, in log_consent_form_callback()) is
	 * what makes an untrusted, visitor-controlled value here safe.
	 *
	 * @return string
	 */
	private function get_current_url() {
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : wp_parse_url( home_url(), PHP_URL_HOST );
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$scheme = is_ssl() ? 'https' : 'http';

		return $scheme . '://' . $host . $uri;
	}

	/**
	 * Get the visitor's current consent decision from the cookie.
	 *
	 * @return string 'accepted', 'rejected', or '' when the visitor has not decided yet.
	 */
	private function get_consent() {
		$cookie_name = $this->get_cookie_name();

		if ( ! isset( $_COOKIE[ $cookie_name ] ) ) {
			return '';
		}

		$consent = sanitize_key( wp_unslash( $_COOKIE[ $cookie_name ] ) );

		return in_array( $consent, array( 'accepted', 'rejected' ), true ) ? $consent : '';
	}

	/**
	 * Check whether the current request is for the configured cookie policy page.
	 *
	 * Used to suppress the banner there so visitors can read the policy before
	 * deciding — otherwise, with the popup layout, the notice would immediately
	 * cover the policy content on that same page.
	 *
	 * @return bool
	 */
	private function is_policy_page() {
		$options        = get_option( 'frontconsent_settings', array() );
		$policy_page_id = (int) ( $options['cookie_notice_policy_page_id'] ?? 0 );

		if ( ! $policy_page_id ) {
			return false;
		}

		return get_queried_object_id() === $policy_page_id;
	}

	/**
	 * Enqueue the frontend banner assets.
	 *
	 * Always enqueued, on every page including the configured policy page —
	 * never gated by the visitor's consent cookie, so a full-page cache can
	 * safely serve one cached HTML response to every visitor of a URL. The
	 * policy page only suppresses the visible banner markup (see
	 * render_banner()); it still needs these assets so an accepted visitor
	 * keeps getting tracking scripts there too.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		$options = get_option( 'frontconsent_settings', array() );
		$days    = (int) ( $options['cookie_notice_expiration_days'] ?? 365 );

		wp_enqueue_style(
			'frontconsent-cookie-notice',
			$this->get_asset_url( 'assets/cookie-notice/frontconsent-cookie-notice.css' ),
			array(),
			FRCN_VERSION
		);

		wp_enqueue_script(
			'frontconsent-cookie-notice',
			$this->get_asset_url( 'assets/cookie-notice/frontconsent-cookie-notice.js' ),
			array(),
			FRCN_VERSION,
			// Printed in <head>, not the footer: this is what lets the script's
			// own CSP-fallback Consent Mode default (see its top-level
			// setConsentModeDefault() call) run before any independently
			// enqueued, Consent Mode-aware tag placed later in <head> — a
			// footer placement would run far too late to matter.
			false
		);

		wp_localize_script(
			'frontconsent-cookie-notice',
			'frcnCookieNotice',
			array(
				'ajaxUrl'        => $this->get_ajax_url(),
				'cookieName'     => $this->get_cookie_name(),
				// Always '/', not COOKIEPATH: COOKIEPATH is derived from the
				// Home URL's own path, but this cookie must also be sent to
				// the admin-ajax.php request in get_ajax_url(), which lives
				// under the Site URL's path — on an install where Home URL
				// and Site URL have different paths, COOKIEPATH would scope
				// the cookie to a path admin-ajax.php falls outside of, and
				// the browser would silently omit it from that request.
				'cookiePath'     => '/',
				'expirationDays' => $days > 0 ? $days : 365,
				// The reopen trigger's banner never renders on the policy page
				// (see render_banner()) — reloading in place there would leave
				// the visitor with no controls at all, so JS instead sends them
				// home, where the banner is guaranteed to render.
				'isPolicyPage'   => $this->is_policy_page(),
				'homeUrl'        => home_url( '/' ),
			)
		);

		// Separate localized object (not merged into frcnCookieNotice above)
		// so an add-on overriding one doesn't have to know about the other —
		// these are purely the strings the live region announcer in
		// frontconsent-cookie-notice.js reads (see render_status_announcer()).
		wp_localize_script(
			'frontconsent-cookie-notice',
			'frcnCookieNoticeA11y',
			array(
				'bannerOpened' => __( 'Cookie consent banner opened.', 'frontconsent' ),
				'accepted'     => __( 'Cookies accepted.', 'frontconsent' ),
				'rejected'     => __( 'Cookies rejected.', 'frontconsent' ),
			)
		);
	}

	/**
	 * Build the URL for a plugin-relative CSS/JS asset, preferring its
	 * minified `.min.css`/`.min.js` build artifact when one exists and
	 * SCRIPT_DEBUG isn't forcing unminified assets — mirrors WordPress
	 * core's own suffix convention. The `.min.*` files are release build
	 * artifacts (see bin/build-assets.js, run by the deploy workflow), never
	 * committed to `main` and never required for local development: a fresh
	 * checkout that hasn't run the build simply falls back to the plain file.
	 *
	 * @param string $relative_path Plugin-relative path to the plain asset, e.g. 'assets/cookie-notice/frontconsent-cookie-notice.js'.
	 * @return string Absolute URL to whichever asset should be enqueued.
	 */
	private function get_asset_url( $relative_path ) {
		$use_minified = ! $this->is_script_debug();

		if ( $use_minified ) {
			$minified_relative_path = preg_replace( '/\.(css|js)$/', '.min.$1', $relative_path );

			if ( file_exists( FRCN_PLUGIN_PATH . $minified_relative_path ) ) {
				return FRCN_PLUGIN_URL . $minified_relative_path;
			}
		}

		return FRCN_PLUGIN_URL . $relative_path;
	}

	/**
	 * Whether SCRIPT_DEBUG is on. Split out of get_asset_url() only so
	 * tests can override this one method (e.g. via an anonymous subclass)
	 * to exercise the SCRIPT_DEBUG=true branch — SCRIPT_DEBUG is a global
	 * constant that, once defined, can't be undefined again for the rest
	 * of the test process, which would otherwise leak into every other
	 * test.
	 *
	 * @return bool
	 */
	protected function is_script_debug() {
		return defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG;
	}

	/**
	 * Render the visible consent banner markup in the footer.
	 *
	 * Always rendered the same way for every visitor of a given URL — never
	 * gated by the visitor's own consent cookie — so a full-page cache stays
	 * safe; render_consent_bootstrap_script() (hooked much earlier, on
	 * wp_head) hides it immediately client-side when a decision cookie already
	 * exists, so a returning visitor never sees it flash.
	 *
	 * Suppressed on the configured cookie policy page so a popup layout can't
	 * block that page's own content — the bootstrap script's tracking pickup
	 * still runs there regardless, since it's on wp_head, not this method.
	 *
	 * @return void
	 */
	public function render_banner() {
		if ( ! $this->is_policy_page() ) {
			$this->render_banner_markup();
		}

		$this->render_reopen_trigger();
		$this->render_preferences_panel();
		$this->render_status_announcer();
	}

	/**
	 * Render an always-present, visually hidden live region used to announce
	 * banner visibility and consent-decision state changes to screen reader
	 * users (see frontconsent-cookie-notice.js's announce() helper) — a purely
	 * visual class/opacity change (how the banner itself is shown/hidden)
	 * conveys nothing to assistive technology on its own.
	 *
	 * Printed empty and unconditionally, exactly like render_reopen_trigger():
	 * JS fills in its text at the moments that matter, keeping this cache-neutral.
	 *
	 * @return void
	 */
	private function render_status_announcer() {
		?>
		<div
			id="frcn-cookie-notice-announcer"
			class="frcn-cookie-notice-sr-only"
			role="status"
			aria-live="polite"
			aria-atomic="true"
		></div>
		<?php
	}

	/**
	 * Render the persistent cookie-preferences trigger that lets a visitor
	 * who already decided open the banner again to change their mind — the
	 * only way to withdraw acceptance or replace a rejection without deleting
	 * the consent cookie by hand. Always printed (including on the policy
	 * page, and identically for every visitor of a cached page): JS shows it
	 * only once a decision cookie actually exists, the same cache-neutral
	 * approach render_banner_markup() itself uses.
	 *
	 * Icon-only by design: it reuses the exact same cookie glyph shown inside
	 * the banner itself (.frcn-cookie-notice__icon), rather than a text label,
	 * so it reads as a small recognizable affordance rather than a chunk of
	 * text competing for attention on every page. The accessible name comes
	 * entirely from aria-label.
	 *
	 * @return void
	 */
	private function render_reopen_trigger() {
		$options = get_option( 'frontconsent_settings', array() );
		$color   = (string) ( $options['cookie_notice_color'] ?? '#687df9' );
		$style   = sprintf(
			'--frcn-cookie-accent: %1$s; --frcn-cookie-accent-contrast: %2$s; --frcn-cookie-icon-url: url(%3$s);',
			esc_attr( $color ),
			esc_attr( $this->get_readable_text_color( $color ) ),
			esc_attr( FRCN_PLUGIN_URL . 'assets/cookie-notice/cookie-icon.svg' )
		);
		?>
		<button
			type="button"
			id="frcn-cookie-reopen"
			class="frcn-cookie-reopen"
			style="<?php echo esc_attr( $style ); ?>"
			aria-label="<?php echo esc_attr__( 'Cookie preferences', 'frontconsent' ); ?>"
			hidden
		>
			<span class="frcn-cookie-notice__icon" aria-hidden="true"></span>
		</button>
		<?php
	}

	/**
	 * Render the "Customize cookie settings" button, printed on the
	 * 'frcn_cookie_notice_before_actions' action — the very same extension
	 * point a PRO add-on would use to print its own trigger, so this button
	 * never needs any markup PRO couldn't also produce itself. It always
	 * shows the cookie icon (reusing the same CSS-mask technique as
	 * .frcn-cookie-notice__icon) and is positioned to the left of
	 * Reject/Accept purely by DOM order (see render_banner_markup()).
	 *
	 * Clicking it doesn't decide anything by itself — frontconsent-cookie-notice.js
	 * intercepts data-frcn-cookie-action="customize" and opens the preferences
	 * panel rendered by render_preferences_panel().
	 *
	 * @param array $options The 'frontconsent_settings' option array.
	 * @return void
	 */
	public function render_customize_button( $options ) {
		/**
		 * Filters whether the "Customize cookie settings" button is shown at all.
		 *
		 * Return false to hide it entirely — e.g. a PRO tier that replaces it with
		 * its own trigger printed on the same 'frcn_cookie_notice_before_actions'
		 * action.
		 *
		 * @param bool  $enabled Whether to show the button. Default true.
		 * @param array $options The 'frontconsent_settings' option array.
		 */
		$enabled = (bool) apply_filters( 'frcn_cookie_customize_button_enabled', true, $options );

		if ( ! $enabled ) {
			return;
		}

		/**
		 * Filters the "Customize cookie settings" button label.
		 *
		 * @param string $label   The default, translatable button label.
		 * @param array  $options The 'frontconsent_settings' option array.
		 */
		$label = (string) apply_filters( 'frcn_cookie_customize_button_label', __( 'Customize cookie settings', 'frontconsent' ), $options );
		?>
		<button
			type="button"
			class="frcn-cookie-notice__button frcn-cookie-notice__button--customize"
			data-frcn-cookie-action="customize"
			aria-haspopup="dialog"
			aria-controls="frcn-cookie-preferences"
		>
			<span class="frcn-cookie-notice__button-icon" aria-hidden="true"></span>
			<?php echo esc_html( $label ); ?>
		</button>
		<?php
	}

	/**
	 * Render the cookie preferences panel: a dialog offering Accept all /
	 * Reject all / Save changes, plus a static "Strictly necessary" section.
	 *
	 * Always printed — including on the policy page, and identically for
	 * every visitor of a cached page — and hidden by default via the
	 * `hidden` attribute; frontconsent-cookie-notice.js is what actually
	 * opens it (from the Customize button or the persistent reopen trigger)
	 * and traps focus inside it, keeping this cache-neutral like the rest of
	 * the banner.
	 *
	 * Every action inside fires the exact same client-side decision flow the
	 * main Accept/Reject buttons use (see frontconsent-cookie-notice.js's
	 * handleDecision()) — there is no parallel consent-recording logic here.
	 *
	 * @return void
	 */
	private function render_preferences_panel() {
		$options        = get_option( 'frontconsent_settings', array() );
		$message        = trim( (string) ( $options['cookie_notice_message'] ?? '' ) );
		$policy_page_id = (int) ( $options['cookie_notice_policy_page_id'] ?? 0 );
		$policy_url     = $policy_page_id ? (string) get_permalink( $policy_page_id ) : '';
		$color          = (string) ( $options['cookie_notice_color'] ?? '#687df9' );
		$bg_color       = (string) ( $options['cookie_notice_bg_color'] ?? '#ffffff' );
		$radius         = (string) ( $options['cookie_notice_radius'] ?? 'small' );

		if ( '' === $message ) {
			$message = __( 'We use cookies to improve your experience on our website. Please choose whether to accept or reject them.', 'frontconsent' );
		}

		$accent_text = $this->get_readable_text_color( $color );
		$accent_link = $this->get_readable_on_white_color( $color, $bg_color );
		$panel_text  = $this->get_readable_text_color( $bg_color );
		$style       = sprintf(
			'--frcn-cookie-accent: %1$s; --frcn-cookie-accent-contrast: %2$s; --frcn-cookie-accent-on-light: %3$s; --frcn-cookie-bg: %4$s; --frcn-cookie-text: %5$s; --frcn-cookie-radius: %6$s; --frcn-cookie-icon-url: url(%7$s);',
			esc_attr( $color ),
			esc_attr( $accent_text ),
			esc_attr( $accent_link ),
			esc_attr( $bg_color ),
			esc_attr( $panel_text ),
			esc_attr( $this->get_radius_value( $radius ) ),
			esc_attr( FRCN_PLUGIN_URL . 'assets/cookie-notice/cookie-icon.svg' )
		);
		?>
		<div
			id="frcn-cookie-preferences"
			class="frcn-cookie-preferences"
			style="<?php echo esc_attr( $style ); ?>"
			role="dialog"
			aria-modal="true"
			aria-labelledby="frcn-cookie-preferences-title"
			aria-describedby="frcn-cookie-preferences-message"
			hidden
		>
			<div class="frcn-cookie-preferences__panel">
				<div class="frcn-cookie-preferences__header">
					<h2 id="frcn-cookie-preferences-title" class="frcn-cookie-preferences__title">
						<?php esc_html_e( 'Cookie preferences', 'frontconsent' ); ?>
					</h2>
					<button
						type="button"
						class="frcn-cookie-preferences__close"
						data-frcn-cookie-action="close-preferences"
						aria-label="<?php echo esc_attr__( 'Close', 'frontconsent' ); ?>"
					>
						<span aria-hidden="true">&times;</span>
					</button>
				</div>
				<p id="frcn-cookie-preferences-message" class="frcn-cookie-preferences__message">
					<?php
					echo esc_html( $message );

					if ( $policy_url ) {
						$policy_title = $policy_page_id ? get_the_title( $policy_page_id ) : '';
						$link_text    = '' !== $policy_title ? $policy_title : __( 'Learn more', 'frontconsent' );

						echo ' <a href="' . esc_url( $policy_url ) . '" class="frcn-cookie-notice__link" target="_blank" rel="noopener noreferrer">' . esc_html( $link_text ) . '</a>';
					}
					?>
				</p>
				<div class="frcn-cookie-preferences__category frcn-cookie-preferences__category--necessary">
					<div class="frcn-cookie-preferences__category-header">
						<span class="frcn-cookie-preferences__category-title">
							<?php esc_html_e( 'Strictly necessary', 'frontconsent' ); ?>
						</span>
						<span class="frcn-cookie-preferences__category-badge">
							<?php esc_html_e( 'Always active', 'frontconsent' ); ?>
						</span>
					</div>
					<p class="frcn-cookie-preferences__category-description">
						<?php esc_html_e( 'These cookies are required for the site to function (e.g. remembering your consent choice) and cannot be switched off.', 'frontconsent' ); ?>
					</p>
				</div>
				<?php
				/*
				 * The Free tier's own binary decision (accepted/rejected) is
				 * still the single source of truth for what gets recorded and
				 * for the tracking-integration gate below — this toggle is
				 * presentation, not a second storage location. It exists so a
				 * visitor gets visible confirmation of what Accept all/Reject
				 * all actually did (and can flip it back off and Save changes
				 * to return to only "Strictly necessary" being active) instead
				 * of a panel that looks identical no matter what they clicked.
				 * frontconsent-cookie-notice.js syncs its checked state from the
				 * current consent cookie every time the panel opens, and reads
				 * it back (via data-frcn-category) on Save changes / Accept all.
				 */
				?>
				<div class="frcn-cookie-preferences__category">
					<div class="frcn-cookie-preferences__category-header">
						<label class="frcn-cookie-preferences__category-title" for="frcn-cookie-preferences-optional">
							<?php esc_html_e( 'Analytics & Marketing', 'frontconsent' ); ?>
						</label>
						<input
							type="checkbox"
							id="frcn-cookie-preferences-optional"
							class="frcn-cookie-preferences__category-toggle"
							data-frcn-category="optional"
						/>
					</div>
					<p class="frcn-cookie-preferences__category-description">
						<?php esc_html_e( 'Cookies used to understand how visitors use the site and to show relevant marketing. Only active after you accept them.', 'frontconsent' ); ?>
					</p>
				</div>
				<?php
				/**
				 * Fires inside the cookie preferences panel, right after the
				 * always-on "Strictly necessary" section and before the
				 * Reject all / Save changes / Accept all actions.
				 *
				 * FrontConsent PRO hooks here to render its own per-category
				 * toggles (e.g. Preferences, Analytics, Marketing), each with a
				 * name/data attribute it can read back from the submitted form —
				 * see frcn_cookie_consent_categories for how that per-category
				 * state reaches the consent-recording AJAX endpoint. Nothing is
				 * rendered here in the Free tier: without PRO active, this
				 * simply prints nothing and only the static "Strictly necessary"
				 * block above is shown.
				 *
				 * @param array $options The 'frontconsent_settings' option array.
				 */
				do_action( 'frcn_cookie_preferences_categories', $options );
				?>
				<div class="frcn-cookie-preferences__actions">
					<button
						type="button"
						class="frcn-cookie-notice__button frcn-cookie-notice__button--reject"
						data-frcn-cookie-action="reject"
					>
						<?php esc_html_e( 'Reject all', 'frontconsent' ); ?>
					</button>
					<button
						type="button"
						class="frcn-cookie-notice__button frcn-cookie-notice__button--save"
						data-frcn-cookie-action="save"
					>
						<?php esc_html_e( 'Save changes', 'frontconsent' ); ?>
					</button>
					<button
						type="button"
						class="frcn-cookie-notice__button frcn-cookie-notice__button--accept"
						data-frcn-cookie-action="accept"
					>
						<?php esc_html_e( 'Accept all', 'frontconsent' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the visible banner markup.
	 *
	 * @return void
	 */
	private function render_banner_markup() {
		$options = get_option( 'frontconsent_settings', array() );

		$message        = trim( (string) ( $options['cookie_notice_message'] ?? '' ) );
		$accept_label   = trim( (string) ( $options['cookie_notice_accept_label'] ?? '' ) );
		$reject_label   = trim( (string) ( $options['cookie_notice_reject_label'] ?? '' ) );
		$policy_page_id = (int) ( $options['cookie_notice_policy_page_id'] ?? 0 );
		$policy_url     = $policy_page_id ? (string) get_permalink( $policy_page_id ) : '';
		$layout         = (string) ( $options['cookie_notice_layout'] ?? 'bar' );
		$position       = (string) ( $options['cookie_notice_position'] ?? 'bottom-right' );
		$color          = (string) ( $options['cookie_notice_color'] ?? '#687df9' );
		$bg_color       = (string) ( $options['cookie_notice_bg_color'] ?? '#ffffff' );
		$radius         = (string) ( $options['cookie_notice_radius'] ?? 'small' );

		if ( '' === $message ) {
			$message = __( 'We use cookies to improve your experience on our website. Please choose whether to accept or reject them.', 'frontconsent' );
		}

		if ( '' === $accept_label ) {
			// Filterable so an add-on that relabels the binary choice as "accept all" /
			// "reject non-essential" (once it introduces per-category consent) doesn't
			// need the site admin to retype the button copy themselves.
			$accept_label = apply_filters( 'frcn_cookie_notice_default_accept_label', __( 'Accept', 'frontconsent' ) );
		}

		if ( '' === $reject_label ) {
			$reject_label = apply_filters( 'frcn_cookie_notice_default_reject_label', __( 'Reject', 'frontconsent' ) );
		}

		if ( ! in_array( $layout, array( 'bar', 'box', 'popup' ), true ) ) {
			$layout = 'bar';
		}

		// '--init' starts the banner invisible/off-screen: it's what keeps an
		// already-decided visitor from ever seeing it flash into view before
		// frontconsent-cookie-notice.js hides it, and doubles as the "from" state
		// of the entrance animation for a visitor who still needs to decide (the
		// script removes it once that's determined). See the noscript style
		// below for the no-JS fallback.
		$classes       = array( 'frcn-cookie-notice', 'frcn-cookie-notice--' . $layout, 'frcn-cookie-notice--init' );
		$content_width = function_exists( 'generate_get_option' ) ? absint( generate_get_option( 'container_width' ) ) : 0;

		if ( $content_width > 0 ) {
			$classes[] = 'frcn-cookie-notice--generatepress';
		}

		if ( 'box' === $layout ) {
			$classes[] = 'bottom-left' === $position ? 'frcn-cookie-notice--left' : 'frcn-cookie-notice--right';
		}

		$is_modal    = 'popup' === $layout;
		$accent_text = $this->get_readable_text_color( $color );
		$accent_link = $this->get_readable_on_white_color( $color, $bg_color );
		$panel_text  = $this->get_readable_text_color( $bg_color );
		$style       = sprintf(
			'--frcn-cookie-accent: %1$s; --frcn-cookie-accent-contrast: %2$s; --frcn-cookie-accent-on-light: %3$s; --frcn-cookie-bg: %4$s; --frcn-cookie-text: %5$s; --frcn-cookie-radius: %6$s; --frcn-cookie-icon-url: url(%7$s);',
			esc_attr( $color ),
			esc_attr( $accent_text ),
			esc_attr( $accent_link ),
			esc_attr( $bg_color ),
			esc_attr( $panel_text ),
			esc_attr( $this->get_radius_value( $radius ) ),
			esc_attr( FRCN_PLUGIN_URL . 'assets/cookie-notice/cookie-icon.svg' )
		);

		if ( $content_width > 0 ) {
			$style .= sprintf( ' --frcn-cookie-content-width: %dpx;', $content_width );
		}
		?>
		<div
			id="frcn-cookie-notice"
			class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
			style="<?php echo esc_attr( $style ); ?>"
			role="<?php echo $is_modal ? 'dialog' : 'region'; ?>"
			<?php echo $is_modal ? 'aria-modal="true"' : ''; ?>
			aria-label="<?php echo esc_attr__( 'Cookie consent', 'frontconsent' ); ?>"
			aria-describedby="frcn-cookie-notice-message"
		>
			<div class="frcn-cookie-notice__panel">
				<span class="frcn-cookie-notice__icon" aria-hidden="true"></span>
				<p class="frcn-cookie-notice__message" id="frcn-cookie-notice-message">
					<?php
					echo esc_html( $message );

					if ( $policy_url ) {
						$policy_title = $policy_page_id ? get_the_title( $policy_page_id ) : '';
						$link_text    = '' !== $policy_title ? $policy_title : __( 'Learn more', 'frontconsent' );

						echo ' <a href="' . esc_url( $policy_url ) . '" class="frcn-cookie-notice__link" target="_blank" rel="noopener noreferrer">' . esc_html( $link_text ) . '</a>';
					}
					?>
				</p>
				<div class="frcn-cookie-notice__actions">
					<?php
					/**
					 * Fires right before the reject/accept buttons, inside the same actions
					 * row. Lets an add-on (e.g. per-category consent) insert its own button —
					 * a "Customize" trigger — without forking this markup.
					 *
					 * @param array $options The 'frontconsent_settings' option array.
					 */
					do_action( 'frcn_cookie_notice_before_actions', $options );
					?>
					<button
						type="submit"
						form="frcn-cookie-notice-form"
						name="frcn_decision"
						value="rejected"
						class="frcn-cookie-notice__button frcn-cookie-notice__button--reject"
						data-frcn-cookie-action="reject"
					>
						<?php echo esc_html( $reject_label ); ?>
					</button>
					<button
						type="submit"
						form="frcn-cookie-notice-form"
						name="frcn_decision"
						value="accepted"
						class="frcn-cookie-notice__button frcn-cookie-notice__button--accept"
						data-frcn-cookie-action="accept"
					>
						<?php echo esc_html( $accept_label ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
		// A real <form>, outside the banner markup above (a <form> can't be a
		// descendant of the buttons that reference it via the form="..."
		// attribute, but it can be a sibling), submitting to admin-post.php —
		// this is what makes Accept/Reject actually work without JavaScript:
		// JS intercepts the buttons' click events and never lets this form
		// submit in the first place (see frontconsent-cookie-notice.js), but a
		// no-JS visitor's click submits it for real, and
		// log_consent_form_submission() sets the same consent cookie
		// server-side and redirects back to this page.
		?>
		<form id="frcn-cookie-notice-form" method="post" action="<?php echo esc_url( $this->get_frontend_origin_admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="frcn_log_cookie_decision" />
			<input type="hidden" name="frcn_redirect" value="<?php echo esc_url( $this->get_current_url() ); ?>" />
		</form>
		<?php
		// Without JS, nothing would ever remove '--init' (see the class list
		// above), so the banner would stay invisible forever — this resets it
		// back to plain visible/static for a no-JS visitor.
		$noscript_css = '#frcn-cookie-notice.frcn-cookie-notice--init { opacity: 1; pointer-events: auto; transform: none; }';

		if ( $is_modal ) {
			$noscript_css .= ' .frcn-cookie-notice--popup { position: static; display: block; overflow: visible; background-color: transparent; padding: 0; } .frcn-cookie-notice--popup .frcn-cookie-notice__panel { max-width: none; box-shadow: none; }';
		}

		// The "Customize cookie settings" button and the panel it opens are
		// both entirely JS-driven (opening/trapping/closing a dialog needs a
		// script) — without JS there is nothing useful the button could do,
		// so it's hidden rather than left sitting there dead. Accept/Reject
		// keep working as always via the <form> fallback above.
		$noscript_css .= ' .frcn-cookie-notice__button--customize { display: none; }';

		$this->print_noscript_style( $noscript_css );

		/**
		 * Fires right after the banner markup, still inside the same wp_footer
		 * output. Lets an add-on print extra markup that belongs to the same
		 * consent flow — e.g. a "customize categories" dialog — right next to it.
		 *
		 * @param array $options The 'frontconsent_settings' option array.
		 */
		do_action( 'frcn_cookie_notice_after_banner', $options );
	}

	/**
	 * Print the Google Consent Mode default state, before any other script.
	 *
	 * This is what actually blocks analytics/ads tags that read Consent Mode
	 * (Google Site Kit's own gtag snippet, a manually pasted GTM container,
	 * etc.) from firing before the visitor decides — the banner markup and its
	 * own accept/reject buttons only control what *this plugin* loads via the
	 * GTM/GA4 ID fields below; they have no effect on tags any other plugin
	 * injects independently. Consent Mode is the standard way to reach those
	 * too, because gtag() queues commands on window.dataLayer regardless of
	 * which plugin's script eventually processes them — as long as this runs
	 * first, it doesn't matter which plugin's gtag.js loads second.
	 *
	 * Only the cookie *name* (a fixed string) is embedded server-side — the
	 * decision itself is read from document.cookie client-side, in the browser,
	 * exactly like render_consent_bootstrap_script() below. This keeps the
	 * printed HTML identical for every visitor of a given URL, so a full-page
	 * cache stays safe; an earlier version of this method embedded the
	 * granted/denied value directly, which a full-page cache could then have
	 * served to the wrong visitor.
	 *
	 * @return void
	 */
	public function render_consent_mode_default() {
		$cookie_name = $this->get_cookie_name();

		$code = "
		window.dataLayer = window.dataLayer || [];
		function gtag(){ window.dataLayer.push( arguments ); }
		( function () {
			var cookieMatch = document.cookie.match( new RegExp( '(?:^|; )" . esc_js( $cookie_name ) . "=([^;]*)' ) );
			var consent = '';

			if ( cookieMatch ) {
				try {
					consent = decodeURIComponent( cookieMatch[ 1 ] );
				} catch ( e ) {
					// Malformed percent-encoding: treat it the same as no cookie at all, same
					// as the bootstrap script below — a thrown, uncaught error here would
					// abort before gtag('consent', 'default', ...) ever runs.
					consent = '';
				}
			}

			var granted = 'accepted' === consent ? 'granted' : 'denied';
			var state = {
				ad_storage: granted,
				ad_user_data: granted,
				ad_personalization: granted,
				analytics_storage: granted
			};

			// An add-on tracking per-category consent (analytics vs. marketing)
			// can define this — reading its own cookie the same way, client-side —
			// to send the granular signals Consent Mode actually expects instead
			// of this binary default. Must be defined by the time this script
			// runs, i.e. at an earlier wp_head priority than this method's own.
			if ( typeof window.frcnCookieNoticeConsentModeState === 'function' ) {
				var overrideState = window.frcnCookieNoticeConsentModeState();

				if ( overrideState ) {
					state = overrideState;
				}
			}

			// An add-on reporting its own per-category consent as stale (see
			// window.frcnCookieNoticeIsConsentStale, already used to keep the
			// banner/tracking bootstrap from trusting stale consent) means a
			// fresh decision is needed — so deny by default here too, instead
			// of falling back to this plugin's own (possibly still 'accepted')
			// binary cookie, which would let an independently loaded, Consent
			// Mode-aware tag (e.g. Site Kit) run before the visitor re-decides.
			if ( typeof window.frcnCookieNoticeIsConsentStale === 'function' && window.frcnCookieNoticeIsConsentStale() ) {
				state = {
					ad_storage: 'denied',
					ad_user_data: 'denied',
					ad_personalization: 'denied',
					analytics_storage: 'denied'
				};
			}

			gtag( 'consent', 'default', state );
		} )();
		";

		$this->print_inline_bootstrap_script( 'frontconsent-consent-mode-default', $code );
	}

	/**
	 * Print the inline bootstrap script: hides the banner immediately when a
	 * decision cookie already exists, and — for an accepted visitor — fetches
	 * and injects the tracking scripts. Hooked on wp_head (not wp_footer,
	 * where the banner markup itself renders) precisely so an already-accepted
	 * visitor's tracking request fires as early as possible, on every page
	 * including the policy page (where render_banner_markup() is skipped but
	 * this still runs).
	 *
	 * This is an optimization, not the only implementation: it sets
	 * window.frcnCookieNoticeBootstrapped so the registered
	 * frontconsent-cookie-notice.js file (enqueued in enqueue_assets()) knows
	 * this already ran and skips redoing it. On a site whose Content Security
	 * Policy blocks unnonced inline scripts, this one is simply never executed
	 * by the browser, and that registered script performs the same bootstrap
	 * itself instead — banner hiding and tracking still work there, just
	 * without the no-flash guarantee this inline copy provides.
	 *
	 * @return void
	 */
	public function render_consent_bootstrap_script() {
		$cookie_name = $this->get_cookie_name();

		$code = "
		( function () {
			// This runs on wp_head, before '#frcn-cookie-notice' exists in the DOM
			// (it's printed later, in wp_footer) — so, unlike the registered
			// frontconsent-cookie-notice.js file, it can only handle the tracking
			// side of an already-decided visitor, not hiding the banner itself.
			var cookieMatch = document.cookie.match( new RegExp( '(?:^|; )" . esc_js( $cookie_name ) . "=([^;]*)' ) );
			var consent     = '';

			if ( cookieMatch ) {
				try {
					consent = decodeURIComponent( cookieMatch[ 1 ] );
				} catch ( e ) {
					// Malformed percent-encoding: treat it the same as no cookie at all.
					consent = '';
				}
			}

			window.frcnCookieNoticeInject = window.frcnCookieNoticeInject || function ( gtmId, ga4Id, trackingIntegrations, allowedCategories ) {
				var allowsCategory = function ( category ) {
					return ! allowedCategories || !! allowedCategories[ category ];
				};

				if ( gtmId && allowsCategory( 'analytics' ) ) {
					window.dataLayer = window.dataLayer || [];
					window.dataLayer.push( { 'gtm.start': new Date().getTime(), event: 'gtm.js' } );

					var gtmScript = document.createElement( 'script' );
					gtmScript.async = true;
					gtmScript.src = 'https://www.googletagmanager.com/gtm.js?id=' + encodeURIComponent( gtmId );
					document.head.appendChild( gtmScript );
				}

				if ( ga4Id && allowsCategory( 'analytics' ) ) {
					var ga4Script = document.createElement( 'script' );
					ga4Script.async = true;
					ga4Script.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent( ga4Id );
					document.head.appendChild( ga4Script );

					window.dataLayer = window.dataLayer || [];
					window.gtag = window.gtag || function () {
						window.dataLayer.push( arguments );
					};
					window.gtag( 'js', new Date() );
					window.gtag( 'config', ga4Id );
				}

				if ( ! Array.isArray( trackingIntegrations ) ) {
					trackingIntegrations = [];
				}

				trackingIntegrations.forEach( function ( integration ) {
					var trackingType = integration && integration.type ? integration.type : '';
					var trackingId = integration && integration.id ? integration.id : '';
					var trackingCategory = integration && integration.category ? integration.category : 'marketing';

					if ( ! trackingId || ! allowsCategory( trackingCategory ) ) {
						return;
					}

				if ( 'clientify_analytics_plus' === trackingType ) {
					var clientifyPixel = document.createElement( 'script' );
					clientifyPixel.defer = true;
					clientifyPixel.src = 'https://analyticsplusdev.clientify.net/analytics_plus/pixel/' + encodeURIComponent( trackingId );
					document.head.appendChild( clientifyPixel );
				} else if ( 'clientify_analytics_classic' === trackingType ) {
					( function ( d, w, u, o ) {
						w[ o ] = w[ o ] || function () {
							( w[ o ].q = w[ o ].q || [] ).push( arguments );
						};
						var a = d.createElement( 'script' ),
							m = d.getElementsByTagName( 'script' )[ 0 ];
						a.async = 1; a.src = u;
						m.parentNode.insertBefore( a, m );
					} )( document, window, 'https://analytics.clientify.net/tracker.js', 'ana' );
					window.ana( 'setTrackerUrl', 'https://analytics.clientify.net' );
					window.ana( 'setTrackingCode', trackingId );
					window.ana( 'trackPageview' );
				} else if ( 'brevo' === trackingType ) {
					var brevoScript = document.createElement( 'script' );
					brevoScript.async = true;
					brevoScript.src = 'https://cdn.brevo.com/js/sdk-loader.js';
					document.head.appendChild( brevoScript );

					window.Brevo = window.Brevo || [];
					window.Brevo.push( [ 'init', { client_key: trackingId } ] );
				} else if ( 'openai_chatgpt_ads' === trackingType ) {
					if ( ! window.oaiq ) {
						window.oaiq = function () {
							window.oaiq.q.push( arguments );
						};
						window.oaiq.q = [];

						var openaiScript = document.createElement( 'script' );
						openaiScript.async = true;
						openaiScript.src = 'https://bzrcdn.openai.com/sdk/oaiq.min.js';
						document.head.appendChild( openaiScript );
					}

					window.oaiq( 'init', { pixelId: trackingId, debug: true } );
				} else if ( typeof window.frcnCookieNoticeInjectIntegration === 'function' ) {
					window.frcnCookieNoticeInjectIntegration( integration );
				} else {
					window.frcnCookieNoticePendingIntegrations = window.frcnCookieNoticePendingIntegrations || [];
					window.frcnCookieNoticePendingIntegrations.push( integration );
					}
				} );
			};

			// An add-on tracking per-category consent can define this (printed
			// earlier than this script, at a lower wp_head priority) to say the
			// stored consent is stale — e.g. the site admin just added a new
			// integration — so tracking shouldn't start yet either, not just the
			// banner staying hidden.
			var isStale = typeof window.frcnCookieNoticeIsConsentStale === 'function' && window.frcnCookieNoticeIsConsentStale();

			if ( 'accepted' === consent && ! isStale ) {
				var formData = new FormData();
				formData.append( 'action', 'frcn_get_cookie_notice_config' );

				fetch( '" . esc_url( $this->get_ajax_url() ) . "', {
					method: 'POST',
					credentials: 'same-origin',
					body: formData
				} )
					.then( function ( response ) { return response.json(); } )
					.then( function ( response ) {
						if ( response && response.success && response.data ) {
							window.frcnCookieNoticeInject( response.data.gtmId, response.data.ga4Id, response.data.trackingIntegrations, response.data.allowedCategories );
						}
					} )
					.catch( function () {} );
			}

			window.frcnCookieNoticeBootstrapped = true;
		} )();
		";

		$this->print_inline_bootstrap_script( 'frontconsent-consent-bootstrap', $code );
	}

	/**
	 * Register (if needed) a source-less script handle and print the given
	 * JS as its inline script, right where this is called — used by
	 * render_consent_mode_default() and render_consent_bootstrap_script(),
	 * both hooked on wp_head at a specific priority so they run before any
	 * independently loaded analytics/ads tag. wp_add_inline_script() needs a
	 * registered handle to attach to; registering it with an empty src is
	 * the standard way to get WordPress to print only the inline script
	 * itself, no external file, wrapped in a normal <script> tag WordPress
	 * controls (so plugins filtering/nonce'ing script output still see it).
	 *
	 * @param string $handle Script handle to register/use.
	 * @param string $code   Raw JS to print inline.
	 * @return void
	 */
	private function print_inline_bootstrap_script( $handle, $code ) {
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			wp_register_script( $handle, '', array(), FRCN_VERSION, false );
		}

		wp_add_inline_script( $handle, $code );
		wp_print_scripts( $handle );
	}

	/**
	 * Print static CSS wrapped in <noscript>, via a source-less registered
	 * style handle instead of a raw <style> tag — the same wp_add_inline_style()
	 * technique print_inline_bootstrap_script() uses for JS. <noscript> content
	 * is only ever rendered by a browser with JS disabled, which is exactly the
	 * visitor this CSS is for: it resets the banner's initial hidden/off-screen
	 * state (see render_banner_markup()) back to plain visible, since nothing
	 * would otherwise ever remove that state for them.
	 *
	 * @param string $css Static CSS rules (no dynamic data).
	 * @return void
	 */
	private function print_noscript_style( $css ) {
		$handle = 'frontconsent-noscript-fallback';

		if ( ! wp_style_is( $handle, 'registered' ) ) {
			wp_register_style( $handle, false, array(), FRCN_VERSION );
		}

		wp_add_inline_style( $handle, $css );

		echo '<noscript>';
		wp_print_styles( $handle );
		echo '</noscript>';
	}

	/**
	 * Map a corner-rounding preset to its CSS value.
	 *
	 * Public static — also used by the admin settings preview so it renders the
	 * exact same rounding the frontend does.
	 *
	 * @param string $preset 'none', 'small', or 'large'.
	 * @return string CSS length, e.g. '12px'.
	 */
	public static function get_radius_value( $preset ) {
		$radii = array(
			'none'  => '0px',
			'small' => '12px',
			'large' => '24px',
		);

		return $radii[ $preset ] ?? $radii['small'];
	}

	/**
	 * Pick black or white text, whichever has the higher actual WCAG contrast
	 * ratio against a background color (not just whichever "looks" darker/lighter).
	 *
	 * Public static — pure color math with no instance state, also used by the
	 * admin settings preview to show the same contrast the frontend actually renders.
	 *
	 * @param string $hex_color Background color, e.g. '#687df9'.
	 * @return string '#ffffff' or '#000000'.
	 */
	public static function get_readable_text_color( $hex_color ) {
		$bg_luminance = self::get_relative_luminance( self::hex_to_rgb( $hex_color ) );

		$white_contrast = self::get_contrast_ratio( $bg_luminance, 1 );
		$black_contrast = self::get_contrast_ratio( $bg_luminance, 0 );

		// Pure black, not a lighter dark neutral: whichever of black/white has
		// the lower contrast against any background is guaranteed to still
		// reach ~4.58:1 at that background's worst-case luminance (~0.179),
		// clearing the 4.5:1 button-text requirement for every allowed accent.
		return $white_contrast >= $black_contrast ? '#ffffff' : '#000000';
	}

	/**
	 * Ensure a color stays legible when used as text/link color on the
	 * banner's panel — accent colors that don't reach a 4.5:1 contrast ratio
	 * against the panel's actual configured background fall back to a dark
	 * or light neutral instead (whichever contrasts better against that
	 * background), rather than always assuming a white panel. A dark panel
	 * with a light accent (e.g. black background, white accent) needs a
	 * light fallback, not the '#111827' dark neutral that only makes sense
	 * against a light/white background.
	 *
	 * Public static — pure color math with no instance state, also used by the
	 * admin settings preview to show the same contrast the frontend actually renders.
	 *
	 * @param string $hex_color Requested accent color, e.g. '#687df9'.
	 * @param string $bg_color  The panel's actual background color, e.g. '#ffffff'.
	 * @return string A color safe to use as text/link color on that background.
	 */
	public static function get_readable_on_white_color( $hex_color, $bg_color = '#ffffff' ) {
		$bg_luminance = self::get_relative_luminance( self::hex_to_rgb( $bg_color ) );
		$luminance    = self::get_relative_luminance( self::hex_to_rgb( $hex_color ) );
		$contrast     = self::get_contrast_ratio( $luminance, $bg_luminance );

		if ( $contrast >= 4.5 ) {
			return $hex_color;
		}

		// Neither neutral is pure black/white: '#111827' reads as a softer
		// dark neutral than '#000000' against a light panel, so it's kept as
		// the light-background fallback; '#ffffff' is its light counterpart
		// for a dark panel. Whichever contrasts better against the actual
		// background wins.
		$dark_neutral_contrast  = self::get_contrast_ratio( self::get_relative_luminance( self::hex_to_rgb( '#111827' ) ), $bg_luminance );
		$light_neutral_contrast = self::get_contrast_ratio( 1, $bg_luminance );

		return $light_neutral_contrast >= $dark_neutral_contrast ? '#ffffff' : '#111827';
	}

	/**
	 * WCAG relative luminance of an sRGB color.
	 *
	 * @param int[] $rgb Three-item [r, g, b] array, each 0-255.
	 * @return float Relative luminance between 0 (black) and 1 (white).
	 */
	private static function get_relative_luminance( $rgb ) {
		$channels = array();

		foreach ( $rgb as $channel ) {
			$channel    = $channel / 255;
			$channels[] = $channel <= 0.03928 ? $channel / 12.92 : ( ( $channel + 0.055 ) / 1.055 ) ** 2.4;
		}

		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}

	/**
	 * WCAG contrast ratio between two relative luminances.
	 *
	 * @param float $luminance_a First relative luminance (0-1).
	 * @param float $luminance_b Second relative luminance (0-1).
	 * @return float Contrast ratio, from 1 (no contrast) to 21 (black on white).
	 */
	private static function get_contrast_ratio( $luminance_a, $luminance_b ) {
		$lighter = max( $luminance_a, $luminance_b );
		$darker  = min( $luminance_a, $luminance_b );

		return ( $lighter + 0.05 ) / ( $darker + 0.05 );
	}

	/**
	 * Convert a hex color (3 or 6 digits, with or without '#') to an [r, g, b] triple.
	 *
	 * @param string $hex_color Hex color string.
	 * @return int[] Three-item array of 0-255 RGB values; black if the input is invalid.
	 */
	private static function hex_to_rgb( $hex_color ) {
		$hex = ltrim( (string) $hex_color, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return array( 0, 0, 0 );
		}

		return array(
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * AJAX callback: returns the GTM/GA4 identifiers, but only when the requesting
	 * browser's own consent cookie says 'accepted'.
	 *
	 * Deliberately unauthenticated: it's read-only, never touches the aggregate
	 * counters, and only ever echoes back non-secret IDs that are already public
	 * once GTM/GA4 loads. A nonce would have to be embedded in the cache-neutral
	 * HTML this module renders, and would go stale on any page a full-page cache
	 * keeps for longer than a WordPress nonce's lifetime — breaking tracking for
	 * every visitor of that cached page until it expires from the cache.
	 *
	 * @return void
	 */
	public function get_config_callback() {
		$response = array(
			'gtmId'                => '',
			'ga4Id'                => '',
			'trackingIntegrations' => array(),
			'allowedCategories'    => null,
		);

		$has_tracking_consent = 'accepted' === $this->get_consent();

		/**
		 * Filters whether the current visitor has granted consent for tracking.
		 *
		 * Add-ons with per-category consent can allow this endpoint when at least
		 * one tracking category is accepted. They must pass the allowed categories
		 * to frcnCookieNoticeInject() so only matching integrations are loaded.
		 *
		 * @param bool $has_tracking_consent Whether binary consent is accepted.
		 */
		$has_tracking_consent          = (bool) apply_filters( 'frcn_cookie_notice_has_tracking_consent', $has_tracking_consent );
		$response['allowedCategories'] = apply_filters( 'frcn_cookie_notice_allowed_tracking_categories', null );

		if ( $this->is_enabled() && $has_tracking_consent ) {
			$options       = get_option( 'frontconsent_settings', array() );
			$site_kit_tags = $this->get_google_site_kit_managed_tags();
			$integrations  = self::get_tracking_integrations( $options );
			$other_types   = array();
			$gtm_id        = '';
			$ga4_id        = '';

			foreach ( $integrations as $integration ) {
				if ( 'gtm' === $integration['type'] ) {
					$gtm_id = $integration['id'];
				} elseif ( 'ga4' === $integration['type'] ) {
					$ga4_id = $integration['id'];
				} else {
					$other_types[] = $integration;
				}
			}

			// GTM/GA4 are surfaced through their own dedicated response keys
			// (consumed directly by the inline bootstrap script and the
			// registered frontconsent-cookie-notice.js fallback), not through
			// the generic trackingIntegrations dispatch used by add-ons.
			// Only an identical Site Kit ID suppresses the configured one — a
			// different container/measurement ID must still load, since Site
			// Kit managing its own tag doesn't mean it's managing this one.
			$gtm_id                           = $this->sanitize_gtm_id( $gtm_id );
			$ga4_id                           = $this->sanitize_ga4_id( $ga4_id );
			$response['gtmId']                = ( '' !== $gtm_id && $gtm_id === $site_kit_tags['gtm'] ) ? '' : $gtm_id;
			$response['ga4Id']                = ( '' !== $ga4_id && $ga4_id === $site_kit_tags['ga4'] ) ? '' : $ga4_id;
			$response['trackingIntegrations'] = array_map(
				function ( $integration ) {
					$integration['category'] = self::get_integration_default_category( $integration['type'] );
					return $integration;
				},
				$other_types
			);
		}

		wp_send_json_success( $response );
	}

	/**
	 * Get the Google tag IDs that Site Kit is configured to place.
	 *
	 * Site Kit may be active without placing a tag. Only suppress the matching
	 * FrontConsent ID when its Site Kit module has both an identifier and snippet
	 * placement enabled, avoiding duplicated tags without disabling tracking on
	 * partially configured Site Kit installations. Returning the actual ID
	 * (rather than a bare bool) is what lets the caller suppress only the
	 * identical container/measurement ID — a different one configured in
	 * FrontConsent must still load.
	 *
	 * @return array{gtm: string, ga4: string}
	 */
	private function get_google_site_kit_managed_tags() {
		$tags = array(
			'gtm' => '',
			'ga4' => '',
		);

		if ( ! defined( 'GOOGLESITEKIT_VERSION' ) && ! class_exists( '\\Google\\Site_Kit\\Plugin' ) ) {
			return $tags;
		}

		$tag_manager_settings = get_option( 'googlesitekit_tagmanager_settings', array() );
		if ( is_array( $tag_manager_settings ) && ! empty( $tag_manager_settings['containerID'] ) && ( ! isset( $tag_manager_settings['useSnippet'] ) || $tag_manager_settings['useSnippet'] ) ) {
			$tags['gtm'] = strtoupper( (string) $tag_manager_settings['containerID'] );
		}

		$analytics_settings = get_option( 'googlesitekit_analytics-4_settings', array() );
		if ( is_array( $analytics_settings ) && ! empty( $analytics_settings['measurementID'] ) && ( ! isset( $analytics_settings['useSnippet'] ) || $analytics_settings['useSnippet'] ) ) {
			$tags['ga4'] = strtoupper( (string) $analytics_settings['measurementID'] );
		}

		return $tags;
	}

	/**
	 * AJAX callback: returns a fresh nonce for the logging endpoint.
	 *
	 * Fetched live at the moment a visitor actually decides, instead of being
	 * embedded in the cache-neutral HTML this module renders — a nonce baked
	 * into that HTML would go stale on any page a full-page cache keeps around
	 * longer than a WordPress nonce's lifetime, silently dropping every decision
	 * logged from that cached response. Generating a nonce isn't a sensitive
	 * action in itself (the same thing any login form does for a logged-out
	 * visitor), so this endpoint needs no authentication of its own.
	 *
	 * @return void
	 */
	public function get_log_nonce_callback() {
		wp_send_json_success( array( 'nonce' => wp_create_nonce( self::NONCE_ACTION ) ) );
	}

	/**
	 * AJAX callback: logs the visitor's decision in the aggregate accepted/rejected counters.
	 *
	 * This is a best-effort, lightweight aggregate stat, not a precise per-visitor
	 * metering system — the module explicitly renders cache-neutral HTML (see
	 * render_banner()), so there is no page-embedded value this endpoint could use
	 * to deduplicate a replayed request without also breaking under a full-page
	 * cache, the same way a one-time token would. The nonce itself is fetched
	 * fresh via get_log_nonce_callback() right before this call, so it stays
	 * valid regardless of how long a cache keeps the page that triggered it.
	 *
	 * @return void
	 */
	public function log_consent_callback() {
		if ( ! $this->is_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'Cookie Notice is disabled.', 'frontconsent' ) ), 403 );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'frontconsent' ) ), 403 );
		}

		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';

		if ( ! in_array( $decision, array( 'accepted', 'rejected' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid decision.', 'frontconsent' ) ), 400 );
		}

		$this->maybe_increment_stat( $decision );

		wp_send_json_success( array( 'categories' => $this->get_consent_categories_payload( $decision ) ) );
	}

	/**
	 * Build the (optional, additive) per-category consent map for the current
	 * request, applying the frcn_cookie_consent_categories filter around it.
	 *
	 * The Free tier never populates this itself — the stored binary
	 * accepted/rejected cookie stays the single source of truth for Free, and
	 * this method only exists so FrontConsent PRO's per-category consent
	 * (Analytics, Marketing, etc.) has a single, well-defined place to read
	 * the raw category selection submitted by the preferences panel (see
	 * render_preferences_panel()'s frcn_cookie_preferences_categories action)
	 * and to persist/return its own per-category decision alongside the
	 * binary one. Never required for the binary flow to keep working.
	 *
	 * @param string $decision 'accepted' or 'rejected'.
	 * @return array<string, mixed> Category slug => state, empty unless something extends it.
	 */
	private function get_consent_categories_payload( $decision ) {
		$categories = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the surrounding log_consent_callback() already verified the nonce above.
		if ( isset( $_POST['categories'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw JSON can't be run through a string sanitizer without corrupting it; every decoded key/value is sanitized individually below (sanitize_key()/rest_sanitize_boolean()) before it's ever used.
			$decoded = json_decode( (string) wp_unslash( $_POST['categories'] ), true );

			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $key => $value ) {
					$categories[ sanitize_key( (string) $key ) ] = rest_sanitize_boolean( $value );
				}
			}
		}

		/**
		 * Filters the per-category consent map recorded alongside a binary
		 * accept/reject decision.
		 *
		 * FrontConsent PRO hooks here to persist (e.g. in its own cookie/option)
		 * and/or normalize the category selection a visitor made in the
		 * preferences panel (see frcn_cookie_preferences_categories), and to
		 * read back any category state it needs when this fires again on a
		 * later request. The Free tier's own stored consent format never
		 * depends on this value — it is optional and purely additive.
		 *
		 * @param array<string, mixed> $categories Category slug => state, decoded from the request.
		 * @param string               $decision   The binary decision being recorded ('accepted' or 'rejected').
		 */
		return apply_filters( 'frcn_cookie_consent_categories', $categories, $decision );
	}

	/**
	 * Admin-post.php callback: the no-JS <form> fallback (see
	 * render_banner_markup()) submits here directly. Sets the same consent
	 * cookie server-side (setcookie(), since there is no client-side JS to
	 * do it via document.cookie here), logs the decision the same way
	 * log_consent_callback() does, then redirects back to the page the
	 * visitor was on.
	 *
	 * @return void
	 */
	public function log_consent_form_callback() {
		$redirect = $this->process_consent_form_submission();

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Validate the no-JS <form> submission, set the consent cookie and log
	 * the decision if valid, and return the redirect target — split out from
	 * log_consent_form_callback() so the validation/cookie-setting logic is
	 * directly testable without the process-terminating exit() around it.
	 *
	 * Deliberately unauthenticated with no nonce check, the same way
	 * get_config_callback() is: the banner markup this form is embedded in
	 * is cache-neutral (see render_banner()), printed identically for every
	 * visitor of a URL, so any nonce baked into it would go stale the moment
	 * a full-page cache keeps that page around longer than a WordPress
	 * nonce's lifetime — and unlike the AJAX path, a no-JS visitor has no way
	 * to fetch a fresh one first. The worst a forged submission can do is
	 * flip the submitter's own consent cookie or add one to a shared
	 * accepted/rejected counter, the same acceptable risk already taken for
	 * get_config_callback() and log_consent_callback()'s own nonce (fetched
	 * fresh only because JS can do that; this can't).
	 *
	 * @return string Validated redirect URL.
	 */
	public function process_consent_form_submission() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- deliberately unauthenticated, see this method's own docblock above.
		$redirect = isset( $_POST['frcn_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['frcn_redirect'] ) ) : home_url( '/' );
		$redirect = wp_validate_redirect( $redirect, home_url( '/' ) );

		if ( ! $this->is_enabled() ) {
			return $redirect;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- deliberately unauthenticated, see this method's own docblock above.
		$decision = isset( $_POST['frcn_decision'] ) ? sanitize_key( wp_unslash( $_POST['frcn_decision'] ) ) : '';

		if ( in_array( $decision, array( 'accepted', 'rejected' ), true ) ) {
			$days = (int) ( get_option( 'frontconsent_settings', array() )['cookie_notice_expiration_days'] ?? 365 );
			$days = $days > 0 ? $days : 365;

			$this->set_consent_cookie_header( $decision, time() + ( $days * DAY_IN_SECONDS ) );

			$this->maybe_increment_stat( $decision );
		}

		return $redirect;
	}

	/**
	 * Set the consent cookie from the server side (the no-JS <form>
	 * fallback's only way to do it, since there is no client-side JS here to
	 * set it via document.cookie).
	 *
	 * Setcookie()'s single-array-of-options signature (letting SameSite be
	 * set directly) only exists from PHP 7.3 — this plugin's own declared
	 * minimum is PHP 7.0 (see frontconsent.php), so that form can't be used
	 * unconditionally. header() is used instead of the older positional
	 * setcookie() signature (which has no SameSite parameter at all before
	 * PHP 7.3) so the exact same Set-Cookie header, SameSite included, is
	 * sent on every supported PHP version.
	 *
	 * @param string $decision 'accepted' or 'rejected'.
	 * @param int    $expires  Unix timestamp the cookie expires at.
	 * @return void
	 */
	private function set_consent_cookie_header( $decision, $expires ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.header_header, WordPress.PHP.NoSilencedErrors.Discouraged -- admin-post.php runs this before any output in production; the error is silenced only so this stays directly callable outside that request lifecycle (e.g. under a test suite, where headers are already sent by the test bootstrap itself and header() would otherwise warn).
		@header( 'Set-Cookie: ' . $this->build_consent_cookie_header_value( $decision, $expires ), false );
	}

	/**
	 * Build the Set-Cookie header value for a consent decision — split out
	 * from set_consent_cookie_header() so the actual header string is
	 * directly unit-testable without depending on header()/xdebug_get_headers()
	 * working in whatever environment the test happens to run in.
	 *
	 * @param string $decision 'accepted' or 'rejected'.
	 * @param int    $expires  Unix timestamp the cookie expires at.
	 * @return string
	 */
	public function build_consent_cookie_header_value( $decision, $expires ) {
		$parts = array(
			rawurlencode( $this->get_cookie_name() ) . '=' . rawurlencode( $decision ),
			'expires=' . gmdate( 'D, d-M-Y H:i:s T', $expires ),
			'path=/',
			'SameSite=Lax',
		);

		if ( is_ssl() ) {
			$parts[] = 'Secure';
		}

		return implode( '; ', $parts );
	}

	/**
	 * Increment the aggregate accepted/rejected counter for a decision.
	 *
	 * Logged-in administrators are excluded so testing the banner doesn't skew stats.
	 *
	 * @param string $decision 'accepted' or 'rejected'.
	 * @return void
	 */
	private function maybe_increment_stat( $decision ) {
		if ( current_user_can( 'manage_options' ) ) {
			return;
		}

		$option_name = 'accepted' === $decision ? self::STATS_OPTION_ACCEPTED : self::STATS_OPTION_REJECTED;

		$this->increment_option_atomically( $option_name );
	}

	/**
	 * Increment an integer option by 1 directly in the database.
	 *
	 * A plain get_option()/update_option() round trip races under concurrent
	 * requests — two visitors deciding at the same moment can both read the same
	 * value and one increment gets overwritten. A single UPDATE ... SET value = value + 1
	 * lets the database serialize concurrent increments instead.
	 *
	 * @param string $option_name Option name storing a plain integer.
	 * @return void
	 */
	private function increment_option_atomically( $option_name ) {
		global $wpdb;

		$sql = $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s", $option_name );

		$updated = $wpdb->query( $sql );

		if ( ! $updated ) {
			// First time this counter is created. add_option() returns false if another
			// request created the row first — in that case fall back to the atomic UPDATE
			// so this increment isn't silently dropped.
			if ( ! add_option( $option_name, 1, '', 'no' ) ) {
				$wpdb->query( $sql );
			}
		}

		wp_cache_delete( $option_name, 'options' );
	}

	/**
	 * Validate a Google Tag Manager container ID (e.g. GTM-XXXXXXX).
	 *
	 * @param string $value Raw value.
	 * @return string Sanitized ID, or an empty string when it doesn't match the expected format.
	 */
	private function sanitize_gtm_id( $value ) {
		$value = strtoupper( trim( (string) $value ) );

		return preg_match( '/^GTM-[A-Z0-9]+$/', $value ) ? $value : '';
	}

	/**
	 * Validate a GA4 Measurement ID (e.g. G-XXXXXXXXXX).
	 *
	 * @param string $value Raw value.
	 * @return string Sanitized ID, or an empty string when it doesn't match the expected format.
	 */
	private function sanitize_ga4_id( $value ) {
		$value = strtoupper( trim( (string) $value ) );

		return preg_match( '/^G-[A-Z0-9]+$/', $value ) ? $value : '';
	}

	/**
	 * Validate a stored tracking integration type against the ones this
	 * plugin actually knows how to inject.
	 *
	 * @param string $value Raw stored value.
	 * @return string One of TRACKING_TYPES, or '' if unrecognized.
	 */
	private function sanitize_tracking_type( $value ) {
		return in_array( $value, self::get_tracking_types(), true ) ? $value : '';
	}

	/**
	 * Return the tracking integration types supported by FrontConsent and add-ons.
	 *
	 * @return string[] Tracking integration type slugs.
	 */
	public static function get_tracking_types() {
		/**
		 * Filters the tracking integration types accepted by Cookie Notice.
		 *
		 * @param string[] $types Built-in tracking integration type slugs.
		 */
		return array_values( array_unique( apply_filters( 'frcn_cookie_notice_tracking_types', self::TRACKING_TYPES ) ) );
	}

	/**
	 * Detect which supported tool a pasted tracking snippet belongs to, and
	 * pull out the single id/code it needs to be rebuilt later.
	 *
	 * The admin settings field only asks for "paste your tracking snippet" —
	 * it deliberately doesn't ask which tool or product it's from, so this is
	 * what tells them apart. Order matters: Clientify's two products are only
	 * distinguishable by which loader URL they reference, so both are checked
	 * before falling through to Brevo.
	 *
	 * Public static — also used by the admin settings page to detect what was
	 * just pasted and by the settings sanitizer to decide what to store.
	 *
	 * @param string $raw Raw snippet as pasted by the admin.
	 * @return array{type: string, id: string}|null The detected type/id pair, or null if unrecognized.
	 */
	public static function detect_tracking_snippet( $raw ) {
		$raw = (string) $raw;

		if ( '' === trim( $raw ) ) {
			return null;
		}

		// A bare container ID, or Google Tag Manager's own install snippet —
		// which passes the container ID as the IIFE's last literal argument,
		// not embedded in the gtm.js URL itself.
		if ( preg_match( '/^GTM-[A-Za-z0-9]+$/i', trim( $raw ) ) ) {
			return array(
				'type' => 'gtm',
				'id'   => strtoupper( trim( $raw ) ),
			);
		}

		if ( false !== strpos( $raw, 'googletagmanager.com/gtm.js' ) && preg_match( '/[\'"](GTM-[A-Za-z0-9]+)[\'"]/i', $raw, $matches ) ) {
			return array(
				'type' => 'gtm',
				'id'   => strtoupper( $matches[1] ),
			);
		}

		// A bare measurement ID, or gtag.js's own install snippet — which does
		// embed the measurement ID directly in its src URL.
		if ( preg_match( '/^G-[A-Za-z0-9]+$/i', trim( $raw ) )
			|| preg_match( '#googletagmanager\.com/gtag/js\?id=(G-[A-Za-z0-9]+)#i', $raw, $matches )
		) {
			return array(
				'type' => 'ga4',
				'id'   => strtoupper( isset( $matches[1] ) ? $matches[1] : trim( $raw ) ),
			);
		}

		if ( preg_match( '#analyticsplusdev\.clientify\.net/analytics_plus/pixel/([A-Za-z0-9_-]+)#', $raw, $matches ) ) {
			return array(
				'type' => 'clientify_analytics_plus',
				'id'   => $matches[1],
			);
		}

		// The classic snippet calls a generic ana(...) dispatcher with the
		// method name as its first string argument — e.g.
		// ana('setTrackingCode', 'CF-12345-12345-ABCDE') — not a
		// setTrackingCode(...) call itself.
		if ( false !== strpos( $raw, 'analytics.clientify.net/tracker.js' )
			&& preg_match( '#ana\(\s*[\'"]setTrackingCode[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]#', $raw, $matches )
		) {
			return array(
				'type' => 'clientify_analytics_classic',
				'id'   => $matches[1],
			);
		}

		if ( false !== strpos( $raw, 'cdn.brevo.com/js/sdk-loader.js' )
			&& preg_match( '#client_key\s*:\s*[\'"]([^\'"]+)[\'"]#', $raw, $matches )
		) {
			return array(
				'type' => 'brevo',
				'id'   => $matches[1],
			);
		}

		if ( false !== strpos( $raw, 'bzrcdn.openai.com/sdk/oaiq.min.js' )
			&& preg_match( '#pixelId\s*:\s*[\'\"]([A-Za-z0-9_-]+)[\'\"]#', $raw, $matches )
		) {
			return array(
				'type' => 'openai_chatgpt_ads',
				'id'   => $matches[1],
			);
		}

		if ( preg_match( '/^[A-Za-z0-9]{22}$/', trim( $raw ) ) ) {
			return array(
				'type' => 'openai_chatgpt_ads',
				'id'   => trim( $raw ),
			);
		}

		/**
		 * Filters a pasted tracking snippet that FrontConsent does not detect.
		 *
		 * Add-ons can return a type/ID pair after registering their type through
		 * frcn_cookie_notice_tracking_types.
		 *
		 * @param array|null $detected Detected type/ID pair, or null.
		 * @param string     $raw      Pasted tracking snippet.
		 */
		$detected = apply_filters( 'frcn_cookie_notice_detect_tracking_snippet', null, $raw );
		if ( is_array( $detected ) && isset( $detected['type'], $detected['id'] ) && in_array( $detected['type'], self::get_tracking_types(), true ) ) {
			return array(
				'type' => $detected['type'],
				'id'   => sanitize_text_field( $detected['id'] ),
			);
		}

		return null;
	}

	/**
	 * Return the safe, normalized integration records stored in the settings.
	 *
	 * Raw tracking code is never persisted. The legacy single-integration
	 * options are read only as a migration path and are converted on the next
	 * settings save.
	 *
	 * @param array $options FrontConsent settings.
	 * @param bool  $include_unknown Whether to preserve records registered by an inactive add-on.
	 * @return array<int, array{type: string, id: string}> Integration records.
	 */
	public static function get_tracking_integrations( $options, $include_unknown = false ) {
		if ( ! is_array( $options ) ) {
			return array();
		}

		$stored = array_key_exists( 'cookie_notice_tracking_integrations', $options ) ? $options['cookie_notice_tracking_integrations'] : null;
		if ( null === $stored ) {
			$legacy_type = $options['cookie_notice_tracking_type'] ?? '';
			$legacy_id   = $options['cookie_notice_tracking_id'] ?? '';
			$stored      = array(
				array(
					'type' => $legacy_type,
					'id'   => $legacy_id,
				),
			);
		}

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$integrations = array();
		foreach ( $stored as $integration ) {
			if ( ! is_array( $integration ) ) {
				continue;
			}

			$type = sanitize_key( $integration['type'] ?? '' );
			$id   = sanitize_text_field( $integration['id'] ?? '' );
			if ( ( in_array( $type, self::get_tracking_types(), true ) || $include_unknown ) && '' !== $type && '' !== $id ) {
				$integrations[ $type ] = array(
					'type' => $type,
					'id'   => $id,
				);
			}
		}

		return array_values( $integrations );
	}

	/**
	 * The consent category an integration falls under by default, for
	 * FrontConsent PRO's Advanced Cookie Management to key its per-category
	 * gating on — this plugin's own gating stays a plain accept/reject
	 * binary regardless of category.
	 *
	 * @param string $type Integration type: 'gtm', 'ga4', or one of TRACKING_TYPES.
	 * @return string Category slug, e.g. 'analytics' or 'marketing'.
	 */
	public static function get_integration_default_category( $type ) {
		$categories = array(
			'gtm'                         => 'analytics',
			'ga4'                         => 'analytics',
			'clientify_analytics_plus'    => 'marketing',
			'clientify_analytics_classic' => 'marketing',
			'brevo'                       => 'marketing',
			'openai_chatgpt_ads'          => 'marketing',
		);

		$category = $categories[ $type ] ?? 'marketing';

		/**
		 * Filters which consent category an integration defaults to.
		 *
		 * FrontConsent PRO's Advanced Cookie Management reads this to decide
		 * which category gate (e.g. "Analytics" vs "Marketing") an
		 * integration falls under when the visitor granted only some
		 * categories, instead of this plugin's own binary accept/reject.
		 *
		 * @param string $category Default category slug ('analytics' or 'marketing').
		 * @param string $type     Integration type ('gtm', 'ga4', 'clientify_analytics_plus', 'clientify_analytics_classic', 'brevo').
		 */
		return apply_filters( 'frcn_cookie_notice_integration_category', $category, $type );
	}
}
