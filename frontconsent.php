<?php
/**
 * Plugin Name: FrontConsent
 * Plugin URI:  https://wordpress.org/plugins/frontconsent/
 * Description: GDPR/ePrivacy-compliant cookie consent banner for WordPress, following the AEPD guide. Configurable banner, Google Consent Mode v2, and tracking integrations that only load after consent.
 * Version:     1.0.0
 * Author:      CloseTechnology
 * Author URI:  https://close.technology
 * Text Domain: frontconsent
 * Domain Path: /languages
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 *
 * Requires at least: 5.8
 * Requires PHP: 7.0
 *
 * @package     FrontConsent
 * @author      CloseTechnology
 * @copyright   2026 CloseTechnology
 * @license     GPL-2.0+
 *
 * @wordpress-plugin
 *
 * Prefix:      frcn_
 */

defined( 'ABSPATH' ) || die( 'No script kiddies please!' );

define( 'FRCN_VERSION', '1.0.0' );
define( 'FRCN_PLUGIN', __FILE__ );
define( 'FRCN_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FRCN_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );

// Load Composer autoloader.
if ( file_exists( FRCN_PLUGIN_PATH . 'vendor/autoload.php' ) ) {
	require_once FRCN_PLUGIN_PATH . 'vendor/autoload.php';
}

require_once FRCN_PLUGIN_PATH . 'includes/Plugin_Main.php';

add_action(
	'plugins_loaded',
	function () {
		FrontConsent\Plugin_Main::get_instance();
	}
);

/**
 * Redirect to settings page on plugin activation.
 *
 * @return void
 */
function frcn_plugin_activation_redirect() {
	// admin_init also fires on admin AJAX requests (e.g. Heartbeat) that can
	// reach the server between activation and the browser's next real admin
	// page load — bail without touching or consuming the flag there, so the
	// activating administrator still gets redirected on that next real page
	// load instead of the flag being silently claimed by an AJAX request
	// that can't act on a redirect at all.
	if ( wp_doing_ajax() ) {
		return;
	}

	// Bail if activating from network or bulk activation — but the flag was
	// already set by register_activation_hook() regardless (it fires on
	// every activation, bulk included), so it must still be cleared here.
	// Otherwise a later, unrelated admin_init request would find it still
	// set and redirect the administrator unexpectedly.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checking WordPress activation parameter, not processing form data.
	if ( is_network_admin() || isset( $_GET['activate-multi'] ) ) {
		delete_option( 'frcn_activation_redirect' );
		return;
	}

	if ( get_option( 'frcn_activation_redirect', false ) ) {
		delete_option( 'frcn_activation_redirect' );
		wp_safe_redirect( admin_url( 'options-general.php?page=frontconsent-settings' ) );
		exit;
	}
}
add_action( 'admin_init', 'frcn_plugin_activation_redirect' );

/**
 * Set redirect flag on plugin activation.
 *
 * @return void
 */
function frcn_set_activation_redirect() {
	add_option( 'frcn_activation_redirect', true );
}
register_activation_hook( __FILE__, 'frcn_set_activation_redirect' );
