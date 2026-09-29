<?php
/**
 * Tests that CookieNotice::enqueue_assets() prefers the minified
 * frontconsent-cookie-notice.min.js/.min.css build artifacts (see
 * bin/build-assets.js) whenever SCRIPT_DEBUG allows it and they exist on
 * disk, and always falls back to the plain, hand-edited files otherwise —
 * a fresh checkout that never ran the release build must keep working.
 *
 * @package FrontConsent
 */

use FrontConsent\Frontend\CookieNotice;
use Yoast\WPTestUtils\WPIntegration\TestCase;

class CookieNoticeAssetMinificationTest extends TestCase {

	/**
	 * @var CookieNotice
	 */
	private $cookie_notice;

	/**
	 * Fixture .min.* files created by a test, cleaned up in tear_down().
	 *
	 * @var string[]
	 */
	private $created_files = array();

	public function set_up() {
		parent::set_up();
		update_option( 'frontconsent_settings', array( 'enable_cookie_notice' => true ) );
		$this->cookie_notice = new CookieNotice();
	}

	public function tear_down() {
		foreach ( $this->created_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->created_files = array();

		remove_all_actions( 'wp_head' );
		remove_all_actions( 'wp_footer' );
		remove_all_actions( 'wp_enqueue_scripts' );
		delete_option( 'frontconsent_settings' );

		parent::tear_down();
	}

	/**
	 * Writes throwaway .min.js/.min.css fixture files next to the real
	 * cookie-notice assets, so tests can assert on the "build has run"
	 * branch without depending on `npm run build` having actually run.
	 *
	 * @return void
	 */
	private function create_fixture_min_files() {
		$js  = FRCN_PLUGIN_PATH . 'assets/cookie-notice/frontconsent-cookie-notice.min.js';
		$css = FRCN_PLUGIN_PATH . 'assets/cookie-notice/frontconsent-cookie-notice.min.css';

		file_put_contents( $js, '/* fixture */' );
		file_put_contents( $css, '/* fixture */' );

		$this->created_files[] = $js;
		$this->created_files[] = $css;
	}

	/**
	 * The common production case: no SCRIPT_DEBUG override, and a .min.*
	 * build artifact is present on disk (as it would be in a tagged
	 * release, see .github/workflows/deploy.yml) — the minified file must
	 * be enqueued instead of the plain source.
	 */
	public function test_enqueues_minified_assets_when_present_and_script_debug_is_off() {
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
			$this->markTestSkipped( 'SCRIPT_DEBUG is forced on in this environment.' );
		}

		$this->create_fixture_min_files();

		$this->cookie_notice->enqueue_assets();

		$script_src = wp_scripts()->registered['frontconsent-cookie-notice']->src;
		$style_src  = wp_styles()->registered['frontconsent-cookie-notice']->src;

		$this->assertStringEndsWith( 'frontconsent-cookie-notice.min.js', $script_src );
		$this->assertStringEndsWith( 'frontconsent-cookie-notice.min.css', $style_src );
	}

	/**
	 * Without having run the release build, a fresh checkout has no .min.*
	 * files on disk — enqueue_assets() must fall back to the plain,
	 * hand-edited files rather than register a 404ing asset.
	 */
	public function test_falls_back_to_plain_assets_when_minified_files_are_absent() {
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
			$this->markTestSkipped( 'SCRIPT_DEBUG is forced on in this environment.' );
		}

		// Guard against a previous `npm run build` having left real build
		// artifacts checked out locally: temporarily move them aside so
		// this test genuinely exercises the "file is absent" branch.
		$js       = FRCN_PLUGIN_PATH . 'assets/cookie-notice/frontconsent-cookie-notice.min.js';
		$css      = FRCN_PLUGIN_PATH . 'assets/cookie-notice/frontconsent-cookie-notice.min.css';
		$had_js   = file_exists( $js );
		$had_css  = file_exists( $css );

		if ( $had_js ) {
			rename( $js, $js . '.bak' );
		}
		if ( $had_css ) {
			rename( $css, $css . '.bak' );
		}

		try {
			$this->cookie_notice->enqueue_assets();

			$script_src = wp_scripts()->registered['frontconsent-cookie-notice']->src;
			$style_src  = wp_styles()->registered['frontconsent-cookie-notice']->src;

			$this->assertStringEndsWith( 'frontconsent-cookie-notice.js', $script_src );
			$this->assertStringNotContainsString( '.min.js', $script_src );
			$this->assertStringEndsWith( 'frontconsent-cookie-notice.css', $style_src );
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
	 * build artifact exists on disk — mirrors WordPress core's own suffix
	 * convention. Exercised via an anonymous subclass overriding the
	 * protected is_script_debug() seam rather than actually defining the
	 * SCRIPT_DEBUG constant, which (once defined) can't be undefined again
	 * for the rest of the test process and would leak into every other test.
	 */
	public function test_falls_back_to_plain_assets_when_script_debug_is_on_even_if_minified_files_exist() {
		$this->create_fixture_min_files();

		$cookie_notice = new class() extends CookieNotice {
			protected function is_script_debug() {
				return true;
			}
		};
		$cookie_notice->enqueue_assets();

		$script_src = wp_scripts()->registered['frontconsent-cookie-notice']->src;
		$style_src  = wp_styles()->registered['frontconsent-cookie-notice']->src;

		$this->assertStringEndsWith( 'frontconsent-cookie-notice.js', $script_src );
		$this->assertStringNotContainsString( '.min.js', $script_src );
		$this->assertStringEndsWith( 'frontconsent-cookie-notice.css', $style_src );
		$this->assertStringNotContainsString( '.min.css', $style_src );
	}
}
