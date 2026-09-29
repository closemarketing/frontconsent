<?php
/**
 * Plugin Main
 *
 * @package    FrontConsent
 * @author     Closemarketing
 * @copyright  2026 Closemarketing
 * @version    1.0.0
 */

namespace FrontConsent;

defined( 'ABSPATH' ) || exit;

/**
 * Main FrontConsent class.
 *
 * @since 1.0.0
 */
class Plugin_Main {
	/**
	 * Plugin instance.
	 *
	 * @var Plugin_Main
	 */
	private static $instance = null;

	/**
	 * Get plugin instance.
	 *
	 * @return Plugin_Main
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->init();
	}

	/**
	 * Initialize the plugin.
	 *
	 * @return void
	 */
	private function init() {
		// The plugin ships no runtime Composer dependencies — vendor/autoload.php
		// is only ever present in a local dev checkout (PHPStan, PHPCS,
		// PHPUnit's own dependencies) and is never bundled in an installable
		// plugin zip. Every runtime class is therefore require_once'd here
		// directly, the same guarded pattern FrontBlocks uses, instead of
		// relying on the PSR-4 autoloader being present.
		if ( ! class_exists( 'FrontConsent\Frontend\CookieNotice' ) ) {
			require_once FRCN_PLUGIN_PATH . 'includes/Frontend/CookieNotice.php';
		}
		if ( ! class_exists( 'FrontConsent\Frontend\ScriptBlocker' ) ) {
			require_once FRCN_PLUGIN_PATH . 'includes/Frontend/ScriptBlocker.php';
		}
		if ( ! class_exists( 'FrontConsent\Migration' ) ) {
			require_once FRCN_PLUGIN_PATH . 'includes/Migration.php';
		}

		// Migration must run before CookieNotice is constructed: its
		// constructor reads is_enabled() once, synchronously, to decide
		// whether to register the frontend hooks (wp_head, wp_footer, etc.)
		// at all — on the very first request after activation, constructing
		// it first would see the pre-migration (disabled) state and skip
		// those hooks for that entire request, even though migration enables
		// Cookie Notice a moment later. Migration itself calls
		// CookieNotice::handle_settings_changed() directly for its own write,
		// so the cache-purge/frcn_cookie_notice_settings_updated side of this
		// doesn't depend on CookieNotice's hooks being registered yet either.
		Migration::maybe_run();

		new Frontend\CookieNotice();
		new Frontend\ScriptBlocker();

		$this->load_modules();
	}

	/**
	 * Load the remaining plugin modules — the frontend Cookie Notice module
	 * itself is already loaded in init(), before Migration ran (see there).
	 *
	 * @return void
	 */
	private function load_modules() {
		if ( is_admin() ) {
			if ( ! class_exists( 'FrontConsent\Admin\Settings' ) ) {
				require_once FRCN_PLUGIN_PATH . 'includes/Admin/Settings.php';
			}
			new Admin\Settings();
		}
	}
}
