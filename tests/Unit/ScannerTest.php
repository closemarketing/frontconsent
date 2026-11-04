<?php
/**
 * Tests for the Scanner tab, its AJAX endpoints and the dashboard widget.
 * The tuwebcumple.es API is mocked with the `pre_http_request` filter.
 *
 * @package FrontConsent
 */

use FrontConsent\Admin\Scanner;
use Yoast\WPTestUtils\WPIntegration\TestCase;

class ScannerTest extends TestCase {

	/**
	 * Requests sent to the mocked API: method and URL.
	 *
	 * @var array
	 */
	private $requests = array();

	/**
	 * Next mocked response: HTTP code and decoded body.
	 *
	 * @var array
	 */
	private $next_response = array();

	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->requests = array();
		add_filter( 'pre_http_request', array( $this, 'mock_api' ), 10, 3 );
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'die_handler' ) );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'mock_api' ), 10 );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_filter( 'wp_die_ajax_handler', array( $this, 'die_handler' ) );
		delete_option( Scanner::OPTION_NAME );
		delete_transient( Scanner::COOLDOWN_TRANSIENT );
		unset( $_REQUEST['nonce'] );
		parent::tear_down();
	}

	public function mock_api( $pre, $args, $url ) {
		$this->requests[] = array(
			'method' => $args['method'],
			'url'    => $url,
			'body'   => isset( $args['body'] ) ? json_decode( $args['body'], true ) : null,
		);

		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $this->next_response['body'] ),
			'response' => array(
				'code'    => $this->next_response['code'],
				'message' => '',
			),
			'cookies'  => array(),
		);
	}

	public function die_handler() {
		return function () {
			throw new WPDieException( 'die' );
		};
	}

	/**
	 * Run an AJAX method and return the decoded JSON it sent.
	 */
	private function call_ajax( $method ) {
		$_REQUEST['nonce'] = wp_create_nonce( Scanner::NONCE_ACTION );
		$scanner           = new Scanner();

		ob_start();
		try {
			$scanner->$method();
		} catch ( WPDieException $e ) {
			unset( $e );
		}

		return json_decode( ob_get_clean(), true );
	}

	private function done_scan( array $overrides = array() ) {
		return array_merge(
			array(
				'uuid'        => 'abc-123',
				'url'         => 'https://example.org/',
				'status'      => 'done',
				'analyzed_at' => '2026-11-03T10:00:00+00:00',
				'result_url'  => 'https://tuwebcumple.es/resultado/abc-123',
				'score'       => 41,
				'grade'       => 'D',
				'headline'    => 'Tu web instala 2 cookies antes del consentimiento',
				'counts'      => array(
					'pass' => 4,
					'warn' => 1,
					'fail' => 2,
				),
				'checks'      => array(
					array(
						'id'      => 'C01',
						'status'  => 'fail',
						'label'   => 'Cookies antes del consentimiento',
						'summary' => '<script>alert(1)</script>Se instalan cookies',
					),
				),
			),
			$overrides
		);
	}

	public function test_scanner_tab_is_added_after_settings() {
		$tabs = ( new Scanner() )->add_tab(
			array(
				array(
					'id'    => 'settings',
					'label' => 'Settings',
				),
				array(
					'id'    => 'license',
					'label' => 'PRO',
				),
			)
		);

		$this->assertSame( array( 'settings', 'scanner', 'license' ), wp_list_pluck( $tabs, 'id' ) );
	}

	public function test_starting_a_scan_posts_the_home_url_and_stores_the_pending_scan() {
		$this->next_response = array(
			'code' => 202,
			'body' => array(
				'data' => array(
					'uuid'               => 'abc-123',
					'status'             => 'queued',
					'poll_after_seconds' => 5,
				),
			),
		);

		$json = $this->call_ajax( 'ajax_start' );

		$this->assertTrue( $json['success'] );
		$this->assertTrue( $json['data']['pending'] );
		$this->assertSame( 'POST', $this->requests[0]['method'] );
		$this->assertSame( 'https://tuwebcumple.es/api/v1/scans', $this->requests[0]['url'] );
		$this->assertSame( home_url( '/' ), $this->requests[0]['body']['url'] );
		$this->assertSame( 'queued', Scanner::get_stored_scan()['status'] );
		$this->assertGreaterThan( 0, Scanner::cooldown_remaining() );
	}

	public function test_a_second_scan_within_the_cooldown_does_not_call_the_api() {
		update_option( Scanner::OPTION_NAME, $this->done_scan() );
		set_transient( Scanner::COOLDOWN_TRANSIENT, time() + 300, 300 );

		$json = $this->call_ajax( 'ajax_start' );

		$this->assertFalse( $json['success'] );
		$this->assertStringContainsString( '5 minutes', $json['data']['message'] );
		$this->assertCount( 0, $this->requests );
	}

	public function test_starting_while_a_scan_is_pending_returns_it_without_calling_the_api() {
		update_option( Scanner::OPTION_NAME, $this->done_scan( array( 'status' => 'running' ) ) );

		$json = $this->call_ajax( 'ajax_start' );

		$this->assertTrue( $json['data']['pending'] );
		$this->assertCount( 0, $this->requests );
	}

	public function test_status_polls_only_the_stored_scan_and_stores_the_result() {
		update_option( Scanner::OPTION_NAME, $this->done_scan( array( 'status' => 'running' ) ) );
		$this->next_response = array(
			'code' => 200,
			'body' => array( 'data' => $this->done_scan() ),
		);

		$json = $this->call_ajax( 'ajax_status' );

		$this->assertSame( 'GET', $this->requests[0]['method'] );
		$this->assertSame( 'https://tuwebcumple.es/api/v1/scans/abc-123', $this->requests[0]['url'] );
		$this->assertFalse( $json['data']['pending'] );
		$this->assertSame( 'D', Scanner::get_stored_scan()['grade'] );
		$this->assertStringContainsString( 'frcn-scanner__grade--d', $json['data']['html'] );
	}

	public function test_status_of_a_finished_scan_does_not_call_the_api() {
		update_option( Scanner::OPTION_NAME, $this->done_scan() );

		$this->call_ajax( 'ajax_status' );

		$this->assertCount( 0, $this->requests );
	}

	public function test_api_errors_are_reported_and_do_not_start_the_cooldown() {
		$this->next_response = array(
			'code' => 422,
			'body' => array( 'errors' => array( 'url' => array( 'No podemos analizar esa dirección.' ) ) ),
		);

		$json = $this->call_ajax( 'ajax_start' );

		$this->assertFalse( $json['success'] );
		$this->assertSame( 'No podemos analizar esa dirección.', $json['data']['message'] );
		$this->assertSame( 0, Scanner::cooldown_remaining() );

		$this->next_response = array(
			'code' => 429,
			'body' => array( 'message' => 'Too Many Attempts.' ),
		);
		$json                = $this->call_ajax( 'ajax_start' );

		$this->assertStringContainsString( 'too many requests', $json['data']['message'] );
	}

	public function test_users_without_manage_options_cannot_start_scans() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$json = $this->call_ajax( 'ajax_start' );

		$this->assertFalse( $json['success'] );
		$this->assertCount( 0, $this->requests );
	}

	public function test_result_html_escapes_api_text() {
		$html = Scanner::render_result( Scanner::store_scan( $this->done_scan() ) );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'Cookies antes del consentimiento', $html );
		$this->assertStringContainsString( 'https://tuwebcumple.es/resultado/abc-123', $html );
	}

	public function test_compact_result_has_no_check_list() {
		$html = Scanner::render_result( Scanner::store_scan( $this->done_scan() ), true );

		$this->assertStringContainsString( 'frcn-scanner__grade', $html );
		$this->assertStringNotContainsString( 'frcn-scanner__checks', $html );
	}

	public function test_dashboard_widget_shows_the_last_grade_and_links_to_the_tab() {
		update_option( Scanner::OPTION_NAME, $this->done_scan() );

		ob_start();
		( new Scanner() )->render_dashboard_widget();
		$html = ob_get_clean();

		$this->assertStringContainsString( '>D<', $html );
		$this->assertStringContainsString( 'options-general.php?page=frontconsent-settings#scanner', $html );
	}

	public function test_dashboard_widget_is_only_registered_for_administrators() {
		require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		global $wp_meta_boxes;
		$wp_meta_boxes = array();
		set_current_screen( 'dashboard' );

		( new Scanner() )->register_dashboard_widget();
		$this->assertArrayHasKey( 'frontconsent_scanner', $wp_meta_boxes['dashboard']['normal']['core'] );

		$wp_meta_boxes = array();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		( new Scanner() )->register_dashboard_widget();
		$this->assertEmpty( $wp_meta_boxes );
	}
}
