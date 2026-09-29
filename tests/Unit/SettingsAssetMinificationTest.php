<?php
/**
 * Tests that Settings::enqueue_assets() prefers the minified
 * settings.min.js/.min.css build artifacts (see bin/build-assets.js)
 * whenever SCRIPT_DEBUG allows it and they exist on disk, and always falls
 * back to the plain, hand-edited files otherwise — a fresh checkout that
 * never ran the release build must keep working.
 *
 * @package FrontConsent
 */

use FrontConsent\Admin\Settings;
use Yoast\WPTestUtils\WPIntegration\TestCase;

class SettingsAssetMinificationTest extends TestCase {

	const HOOK_SUFFIX = 'settings_page_frontconsent-settings';

	/**
	 * @var Settings
	 */
	private $settings;

	/**
	 * Fixture .min.* files created by a test, cleaned up in tear_down().
	 *
	 * @var string[]
	 */
	private $created_files = array();

	public function set_up() {
		parent::set_up();
		$this->settings = new Settings();
	}

	public function tear_down() {
		foreach ( $this->created_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->created_files = array();

		remove_all_actions( 'admin_menu' );
		remove_all_actions( 'admin_init' );
		remove_all_actions( 'admin_enqueue_scripts' );

		parent::tear_down();
	}

	/**
	 * @return void
	 */
	private function create_fixture_min_files() {
		$files = array(
			FRCN_PLUGIN_PATH . 'assets/admin/settings.min.js',
			FRCN_PLUGIN_PATH . 'assets/admin/settings.min.css',
		);

		foreach ( $files as $file ) {
			file_put_contents( $file, '/* fixture */' );
			$this->created_files[] = $file;
		}
	}

	/**
	 * The common production case: no SCRIPT_DEBUG override, and a .min.*
	 * build artifact is present on disk — the minified file must be
	 * enqueued instead of the plain source.
	 */
	public function test_enqueues_minified_settings_assets_when_present_and_script_debug_is_off() {
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
			$this->markTestSkipped( 'SCRIPT_DEBUG is forced on in this environment.' );
		}

		$this->create_fixture_min_files();

		$this->settings->enqueue_assets( self::HOOK_SUFFIX );

		$script_src = wp_scripts()->registered['frontconsent-settings']->src;
		$style_src  = wp_styles()->registered['frontconsent-settings']->src;

		$this->assertStringEndsWith( 'settings.min.js', $script_src );
		$this->assertStringEndsWith( 'settings.min.css', $style_src );
	}

	/**
	 * Without having run the release build, a fresh checkout has no .min.*
	 * files on disk — enqueue_assets() must fall back to the plain,
	 * hand-edited files rather than register a 404ing asset.
	 */
	public function test_falls_back_to_plain_settings_assets_when_minified_files_are_absent() {
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
			$this->markTestSkipped( 'SCRIPT_DEBUG is forced on in this environment.' );
		}

		$js      = FRCN_PLUGIN_PATH . 'assets/admin/settings.min.js';
		$css     = FRCN_PLUGIN_PATH . 'assets/admin/settings.min.css';
		$had_js  = file_exists( $js );
		$had_css = file_exists( $css );

		if ( $had_js ) {
			rename( $js, $js . '.bak' );
		}
		if ( $had_css ) {
			rename( $css, $css . '.bak' );
		}

		try {
			$this->settings->enqueue_assets( self::HOOK_SUFFIX );

			$script_src = wp_scripts()->registered['frontconsent-settings']->src;
			$style_src  = wp_styles()->registered['frontconsent-settings']->src;

			$this->assertStringEndsWith( 'settings.js', $script_src );
			$this->assertStringNotContainsString( '.min.js', $script_src );
			$this->assertStringEndsWith( 'settings.css', $style_src );
			$this->assertStringNotContainsString( '.min.css', $style_src );
		} finally {
			if ( $had_js ) {
				rename( $js . '.bak', $js );
			}
			if ( $had_css ) {
				rename( $css . '.bak', $css );
			}
		}
	}

	/**
	 * SCRIPT_DEBUG=true must force the plain files even when a .min.*
	 * build artifact exists on disk. Runs in a separate process so
	 * defining the SCRIPT_DEBUG constant here can't leak into any other
	 * test in the suite.
	 *
	 * @runInSeparateProcess
	 */
	public function test_falls_back_to_plain_settings_assets_when_script_debug_is_on_even_if_minified_files_exist() {
		if ( ! defined( 'SCRIPT_DEBUG' ) ) {
			define( 'SCRIPT_DEBUG', true );
		}

		$this->create_fixture_min_files();

		$this->settings->enqueue_assets( self::HOOK_SUFFIX );

		$script_src = wp_scripts()->registered['frontconsent-settings']->src;
		$style_src  = wp_styles()->registered['frontconsent-settings']->src;

		$this->assertStringEndsWith( 'settings.js', $script_src );
		$this->assertStringNotContainsString( '.min.js', $script_src );
		$this->assertStringEndsWith( 'settings.css', $style_src );
		$this->assertStringNotContainsString( '.min.css', $style_src );
	}
}
