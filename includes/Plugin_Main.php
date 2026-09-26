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
		Migration::maybe_run();

		$this->load_modules();
	}

	/**
	 * Load plugin modules.
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

		// Cookie Notice module.
		new Frontend\CookieNotice();
	}
}
