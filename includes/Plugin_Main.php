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
		// Migration reads/writes Frontend\CookieNotice's stat option constants
		// and get_tracking_integrations(), so that class must be loaded first.
		if ( ! class_exists( 'FrontConsent\Frontend\CookieNotice' ) ) {
			require_once FRCN_PLUGIN_PATH . 'includes/Frontend/CookieNotice.php';
		}
		if ( ! class_exists( 'FrontConsent\Migration' ) ) {
			require_once FRCN_PLUGIN_PATH . 'includes/Migration.php';
		}
		Migration::maybe_run();

		$this->load_modules();
	}

	/**
	 * Load plugin modules.
	 *
	 * The plugin ships no runtime Composer dependencies — vendor/autoload.php
	 * is only ever present in a local dev checkout (PHPStan, PHPCS,
	 * PHPUnit's own dependencies) and is never bundled in an installable
	 * plugin zip. Every runtime class is therefore require_once'd here
	 * directly, the same guarded pattern FrontBlocks uses, instead of
	 * relying on the PSR-4 autoloader being present.
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

		// Cookie Notice module — already required in init(), before Migration ran.
		new Frontend\CookieNotice();
	}
}
