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
		if ( ! class_exists( 'FrontConsent\Migration' ) ) {
			require_once FRCN_PLUGIN_PATH . 'includes/Migration.php';
		}

		// Instantiated before Migration::maybe_run(): its constructor registers
		// the update_option_frontconsent_settings/add_option_frontconsent_settings
		// hooks that purge full-page caches and fire frcn_cookie_notice_settings_updated
		// — those must already be registered when migration writes the option
		// for the very first time, on the first request after activation, or
		// a cache that doesn't independently purge on plugin activation would
		// keep serving stale, pre-migration banner HTML until it expires.
		new Frontend\CookieNotice();

		Migration::maybe_run();

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
