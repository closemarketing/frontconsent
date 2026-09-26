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

		// The endpoints must stay available for logged-out and logged-in visitors alike.
		add_action( 'wp_ajax_frcn_log_cookie_consent', array( $this, 'log_consent_callback' ) );
		add_action( 'wp_ajax_nopriv_frcn_log_cookie_consent', array( $this, 'log_consent_callback' ) );
		add_action( 'wp_ajax_frcn_get_cookie_notice_config', array( $this, 'get_config_callback' ) );
		add_action( 'wp_ajax_nopriv_frcn_get_cookie_notice_config', array( $this, 'get_config_callback' ) );
		add_action( 'wp_ajax_frcn_get_cookie_notice_log_nonce', array( $this, 'get_log_nonce_callback' ) );
		add_action( 'wp_ajax_nopriv_frcn_get_cookie_notice_log_nonce', array( $this, 'get_log_nonce_callback' ) );
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
		if ( 'frontconsent_settings' !== $option_name || ! is_array( $old_value ) || ! is_array( $new_value ) || ! $this->settings_changed( $old_value, $new_value ) ) {
			return;
		}

		$this->handle_settings_changed( $old_value, $new_value );
	}

	/**
	 * Handle the first save of the FrontConsent settings option.
	 *
	 * @param string $option_name Option name.
	 * @param mixed  $new_value New option value.
	 * @return void
	 */
	public function handle_frontconsent_settings_added( $option_name, $new_value ) {
		if ( ! is_array( $new_value ) || ! $this->settings_changed( array(), $new_value ) ) {
			return;
		}

		$this->handle_settings_changed( array(), $new_value );
	}

	/**
	 * Invalidate page caches after Cookie Notice settings change.
	 *
	 * @param array $old_options Previous settings.
	 * @param array $new_options New settings.
	 * @return void
	 */
	private function handle_settings_changed( $old_options, $new_options ) {
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
	private function settings_changed( $old_options, $new_options ) {
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
		$home_parts = wp_parse_url( home_url() );
		$ajax_path  = (string) wp_parse_url( admin_url( 'admin-ajax.php' ), PHP_URL_PATH );

		$scheme = is_ssl() ? 'https' : 'http';
		$host   = $home_parts['host'] ?? '';
		$port   = isset( $home_parts['port'] ) ? ':' . $home_parts['port'] : '';

		return $scheme . '://' . $host . $port . $ajax_path;
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
			FRCN_PLUGIN_URL . 'assets/cookie-notice/frontconsent-cookie-notice.css',
			array(),
			FRCN_VERSION
		);

		wp_enqueue_script(
			'frontconsent-cookie-notice',
			FRCN_PLUGIN_URL . 'assets/cookie-notice/frontconsent-cookie-notice.js',
			array(),
			FRCN_VERSION,
			true
		);

		wp_localize_script(
			'frontconsent-cookie-notice',
			'frcnCookieNotice',
			array(
				'ajaxUrl'        => $this->get_ajax_url(),
				'cookieName'     => $this->get_cookie_name(),
				'cookiePath'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'expirationDays' => $days > 0 ? $days : 365,
			)
		);
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
			$message = __( 'We use cookies to improve your experience on our website. By browsing this website, you agree to our use of cookies.', 'frontconsent' );
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
		$accent_link = $this->get_readable_on_white_color( $color );
		$panel_text  = $this->get_readable_text_color( $bg_color );
		$style       = sprintf(
			'--frcn-cookie-accent: %1$s; --frcn-cookie-accent-contrast: %2$s; --frcn-cookie-accent-on-light: %3$s; --frcn-cookie-bg: %4$s; --frcn-cookie-text: %5$s; --frcn-cookie-radius: %6$s;',
			esc_attr( $color ),
			esc_attr( $accent_text ),
			esc_attr( $accent_link ),
			esc_attr( $bg_color ),
			esc_attr( $panel_text ),
			esc_attr( $this->get_radius_value( $radius ) )
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
		>
			<div class="frcn-cookie-notice__panel">
				<span class="frcn-cookie-notice__icon" aria-hidden="true">
					<?php echo $this->get_cookie_icon_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG, no dynamic data. ?>
				</span>
				<p class="frcn-cookie-notice__message">
					<?php
					echo esc_html( $message );

					if ( $policy_url ) {
						echo ' <a href="' . esc_url( $policy_url ) . '" class="frcn-cookie-notice__link" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Learn more', 'frontconsent' ) . '</a>';
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
						type="button"
						class="frcn-cookie-notice__button frcn-cookie-notice__button--reject"
						data-frcn-cookie-action="reject"
					>
						<?php echo esc_html( $reject_label ); ?>
					</button>
					<button
						type="button"
						class="frcn-cookie-notice__button frcn-cookie-notice__button--accept"
						data-frcn-cookie-action="accept"
					>
						<?php echo esc_html( $accept_label ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
		// Without JS, nothing would ever remove '--init' (see the class list
		// above), so the banner would stay invisible forever — this resets it
		// back to plain visible/static for a no-JS visitor.
		?>
		<noscript>
			<style>
				#frcn-cookie-notice.frcn-cookie-notice--init {
					opacity: 1;
					pointer-events: auto;
					transform: none;
				}
			</style>
		</noscript>
		<?php
		if ( $is_modal ) {
			?>
			<noscript>
				<style>
					.frcn-cookie-notice--popup {
						position: static;
						display: block;
						overflow: visible;
						background-color: transparent;
						padding: 0;
					}
					.frcn-cookie-notice--popup .frcn-cookie-notice__panel {
						max-width: none;
						box-shadow: none;
					}
				</style>
			</noscript>
			<?php
		}

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
		?>
		<script>
		window.dataLayer = window.dataLayer || [];
		function gtag(){ window.dataLayer.push( arguments ); }
		( function () {
			var cookieMatch = document.cookie.match( new RegExp( '(?:^|; )<?php echo esc_js( $cookie_name ); ?>=([^;]*)' ) );
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
		</script>
		<?php
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
		?>
		<script>
		( function () {
			// This runs on wp_head, before '#frcn-cookie-notice' exists in the DOM
			// (it's printed later, in wp_footer) — so, unlike the registered
			// frontconsent-cookie-notice.js file, it can only handle the tracking
			// side of an already-decided visitor, not hiding the banner itself.
			var cookieMatch = document.cookie.match( new RegExp( '(?:^|; )<?php echo esc_js( $cookie_name ); ?>=([^;]*)' ) );
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

				fetch( '<?php echo esc_url( $this->get_ajax_url() ); ?>', {
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
		</script>
		<?php
	}

	/**
	 * Inline SVG for the popup layout's icon badge.
	 *
	 * The badge's circular background comes from CSS (using the configured
	 * accent color), so this only needs the glyph itself, colored via
	 * `fill="currentColor"`. Public so the admin settings preview can reuse
	 * the exact same markup shown on the frontend.
	 *
	 * @return string Raw SVG markup.
	 */
	public static function get_cookie_icon_svg() {
		return '<svg width="242" height="242" viewBox="0 0 242 242" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M120.931 242C120.045 242 119.159 241.991 118.268 241.973C85.0089 241.264 54.2629 227.347 31.7023 202.787C-10.324 157.038 -10.4104 85.2933 31.5114 39.4584C59.026 9.38964 99.4661 -4.79939 139.638 1.44977C144.565 2.21332 148.137 6.66272 147.764 11.5712C147.155 19.761 150.128 27.7827 155.918 33.5774C158.345 35.9998 161.126 37.9268 164.171 39.3039C167.407 40.7628 169.58 43.7487 169.989 47.2892C170.689 53.6065 173.47 59.3467 178.024 63.9052C182.487 68.3637 188.404 71.2178 194.667 71.9405C198.185 72.345 201.157 74.5174 202.621 77.7488C204.002 80.812 205.92 83.5753 208.311 85.9705C214.11 91.7606 222.2 94.7057 230.317 94.1285C235.371 93.7649 239.67 97.3326 240.443 102.259C246.37 140.331 233.662 179.317 206.438 206.55C183.514 229.474 153.236 242 120.931 242ZM120.559 9.43963C89.4356 9.43963 59.7077 22.4243 38.3832 45.7394C-0.311723 88.043 -0.23447 154.266 38.5559 196.497C59.385 219.167 87.7631 232.01 118.468 232.665C149.346 233.374 178.124 221.703 199.857 199.969C224.99 174.827 236.725 138.841 231.244 103.695C220.055 104.145 209.438 100.26 201.73 92.5515C198.539 89.361 195.985 85.6705 194.14 81.5847C185.259 80.2258 177.397 76.4263 171.443 70.4907C165.462 64.5051 161.662 56.638 160.735 48.3345C156.272 45.9485 152.573 43.3852 149.346 40.1629C141.629 32.4457 137.675 21.7744 138.475 10.8758C132.484 9.91232 126.494 9.43963 120.559 9.43963ZM169.68 189.671C158.799 189.671 149.946 180.817 149.946 169.937C149.946 159.047 158.799 150.194 169.68 150.194C180.56 150.194 189.413 159.047 189.413 169.937C189.413 180.817 180.56 189.671 169.68 189.671ZM169.68 159.502C163.935 159.502 159.254 164.183 159.254 169.937C159.254 175.681 163.935 180.363 169.68 180.363C175.424 180.363 180.105 175.681 180.105 169.937C180.105 164.183 175.424 159.502 169.68 159.502ZM80.9776 179.817C66.2977 179.817 54.3539 167.873 54.3539 153.193C54.3539 138.514 66.2977 126.57 80.9776 126.57C95.6621 126.57 107.606 138.514 107.606 153.193C107.606 167.873 95.662 179.817 80.9776 179.817ZM80.9776 135.878C71.4289 135.878 63.6617 143.649 63.6617 153.193C63.6617 162.738 71.4289 170.509 80.9776 170.509C90.5264 170.509 98.2981 162.738 98.2981 153.193C98.2981 143.649 90.5263 135.878 80.9776 135.878ZM140.447 116.985C129.667 116.985 120.895 108.213 120.895 97.4326C120.895 86.6523 129.667 77.8807 140.447 77.8807C151.227 77.8807 159.999 86.6523 159.999 97.4326C159.999 108.213 151.227 116.985 140.447 116.985ZM140.447 87.1885C134.802 87.1885 130.203 91.7834 130.203 97.4326C130.203 103.082 134.802 107.677 140.447 107.677C146.092 107.677 150.691 103.082 150.691 97.4326C150.691 91.7833 146.092 87.1885 140.447 87.1885ZM68.7701 87.7021C59.7077 87.7021 52.3314 80.3258 52.3314 71.2588C52.3314 62.1963 59.7077 54.82 68.7701 54.82C77.8371 54.82 85.2134 62.1963 85.2134 71.2588C85.2134 80.3258 77.8371 87.7021 68.7701 87.7021ZM68.7701 64.1279C64.8388 64.1279 61.6393 67.3275 61.6393 71.2588C61.6393 75.1946 64.8388 78.3942 68.7701 78.3942C72.706 78.3942 75.9055 75.1946 75.9055 71.2588C75.9055 67.3275 72.706 64.1279 68.7701 64.1279Z" fill="currentColor"/></svg>';
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
	 * Ensure a color stays legible when used as text on the banner's white panel —
	 * accent colors that don't reach a 4.5:1 contrast ratio against white fall
	 * back to a dark neutral instead.
	 *
	 * Public static — pure color math with no instance state, also used by the
	 * admin settings preview to show the same contrast the frontend actually renders.
	 *
	 * @param string $hex_color Requested accent color, e.g. '#687df9'.
	 * @return string A color safe to use as text on a white background.
	 */
	public static function get_readable_on_white_color( $hex_color ) {
		$luminance = self::get_relative_luminance( self::hex_to_rgb( $hex_color ) );
		$contrast  = self::get_contrast_ratio( $luminance, 1 );

		return $contrast >= 4.5 ? $hex_color : '#111827';
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
			$response['gtmId']                = $site_kit_tags['gtm'] ? '' : $this->sanitize_gtm_id( $gtm_id );
			$response['ga4Id']                = $site_kit_tags['ga4'] ? '' : $this->sanitize_ga4_id( $ga4_id );
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
	 * Get the Google tags that Site Kit is configured to place.
	 *
	 * Site Kit may be active without placing a tag. Only suppress the matching
	 * FrontConsent ID when its Site Kit module has both an identifier and snippet
	 * placement enabled, avoiding duplicated tags without disabling tracking on
	 * partially configured Site Kit installations.
	 *
	 * @return array{gtm: bool, ga4: bool}
	 */
	private function get_google_site_kit_managed_tags() {
		$tags = array(
			'gtm' => false,
			'ga4' => false,
		);

		if ( ! defined( 'GOOGLESITEKIT_VERSION' ) && ! class_exists( '\\Google\\Site_Kit\\Plugin' ) ) {
			return $tags;
		}

		$tag_manager_settings = get_option( 'googlesitekit_tagmanager_settings', array() );
		if ( is_array( $tag_manager_settings ) && ! empty( $tag_manager_settings['containerID'] ) && ( ! isset( $tag_manager_settings['useSnippet'] ) || $tag_manager_settings['useSnippet'] ) ) {
			$tags['gtm'] = true;
		}

		$analytics_settings = get_option( 'googlesitekit_analytics-4_settings', array() );
		if ( is_array( $analytics_settings ) && ! empty( $analytics_settings['measurementID'] ) && ( ! isset( $analytics_settings['useSnippet'] ) || $analytics_settings['useSnippet'] ) ) {
			$tags['ga4'] = true;
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

		wp_send_json_success();
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
