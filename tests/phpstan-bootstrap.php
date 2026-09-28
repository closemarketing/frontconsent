<?php
/**
 * PHPStan Bootstrap File
 *
 * This file defines constants and functions that PHPStan needs to understand
 * but are not available during static analysis.
 */

// Define plugin constants that are used throughout the codebase.
if ( ! defined( 'FRCN_PLUGIN_URL' ) ) {
	define( 'FRCN_PLUGIN_URL', 'http://localhost/wp-content/plugins/frontconsent/' );
}

if ( ! defined( 'FRCN_PLUGIN_PATH' ) ) {
	define( 'FRCN_PLUGIN_PATH', __DIR__ . '/../' );
}

if ( ! defined( 'FRCN_VERSION' ) ) {
	define( 'FRCN_VERSION', '1.0.0' );
}

if ( ! defined( 'FRCN_PLUGIN' ) ) {
	define( 'FRCN_PLUGIN', __FILE__ );
}

// Define WordPress constants that might be missing.
if ( ! defined( 'DOING_AJAX' ) ) {
	define( 'DOING_AJAX', false );
}

if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', false );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/path/to/wordpress/' );
}
