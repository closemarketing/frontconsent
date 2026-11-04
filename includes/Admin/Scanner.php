<?php
/**
 * Scanner: checks the site's cookie compliance through the tuwebcumple.es API.
 *
 * Adds a "Scanner" tab to the settings page and a dashboard widget with the
 * last result. Scans are only ever started by an administrator clicking the
 * button; the site's home URL is sent to tuwebcumple.es from the server and
 * the JSON result is stored locally in the `frontconsent_scan_result` option.
 *
 * @package FrontConsent
 */

namespace FrontConsent\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Scanner tab, AJAX endpoints and dashboard widget.
 */
class Scanner {

	/**
	 * Option holding the last scan (pending or finished).
	 */
	const OPTION_NAME = 'frontconsent_scan_result';

	/**
	 * Transient set when a scan is started; while it exists no new scan can
	 * be started from this site. The API has its own limits too.
	 */
	const COOLDOWN_TRANSIENT = 'frcn_scan_cooldown';

	/**
	 * Seconds between two scans started from this site.
	 */
	const COOLDOWN_SECONDS = 600;

	/**
	 * Default API base URL.
	 */
	const API_URL = 'https://tuwebcumple.es/api/v1/';

	/**
	 * Nonce action shared by both AJAX endpoints.
	 */
	const NONCE_ACTION = 'frcn_scanner';

	/**
	 * Settings page slug (see Settings::$page_slug).
	 */
	const PAGE_SLUG = 'frontconsent-settings';

	/**
	 * Check statuses the API may return, mapped to their label.
	 *
	 * @return array<string, string>
	 */
	private static function status_labels() {
		return array(
			'pass'           => __( 'Pass', 'frontconsent' ),
			'warn'           => __( 'Warning', 'frontconsent' ),
			'fail'           => __( 'Fail', 'frontconsent' ),
			'not_applicable' => __( 'Not applicable', 'frontconsent' ),
			'in_report'      => __( 'In the full report', 'frontconsent' ),
		);
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'frontconsent_settings_tabs', array( $this, 'add_tab' ) );
		add_action( 'frontconsent_settings_tab_panels', array( $this, 'render_tab_panel' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
		add_action( 'wp_ajax_frcn_scanner_start', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_frcn_scanner_status', array( $this, 'ajax_status' ) );
	}

	/**
	 * Add the "Scanner" tab right after "Settings".
	 *
	 * @param array $tabs Settings tabs.
	 * @return array
	 */
	public function add_tab( $tabs ) {
		array_splice(
			$tabs,
			1,
			0,
			array(
				array(
					'id'    => 'scanner',
					'label' => __( 'Scanner', 'frontconsent' ),
				),
			)
		);

		return $tabs;
	}

	/**
	 * Enqueue the scanner script on the settings page and the dashboard.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'settings_page_' . self::PAGE_SLUG, 'index.php' ), true ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

		foreach ( array( 'css', 'js' ) as $type ) {
			$path = 'assets/admin/scanner' . $suffix . '.' . $type;
			if ( ! file_exists( FRCN_PLUGIN_PATH . $path ) ) {
				$path = 'assets/admin/scanner.' . $type;
			}

			if ( 'css' === $type ) {
				wp_enqueue_style( 'frontconsent-scanner', FRCN_PLUGIN_URL . $path, array(), FRCN_VERSION );
			} else {
				wp_enqueue_script( 'frontconsent-scanner', FRCN_PLUGIN_URL . $path, array(), FRCN_VERSION, true );
			}
		}

		wp_localize_script(
			'frontconsent-scanner',
			'frcnScanner',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( self::NONCE_ACTION ),
				'maxPolls' => 40,
				'i18n'     => array(
					'running' => __( 'Analyzing your site… this usually takes under a minute.', 'frontconsent' ),
					'error'   => __( 'The scan could not be completed. Please try again later.', 'frontconsent' ),
					'timeout' => __( 'The scan is taking longer than expected. Reload this page in a few minutes to see the result.', 'frontconsent' ),
				),
			)
		);
	}

	/**
	 * Render the Scanner tab panel (outside the settings form).
	 *
	 * @return void
	 */
	public function render_tab_panel() {
		$scan = self::get_stored_scan();
		?>
		<div class="frcn-tab-panel" data-tab-panel="scanner" id="frcn-tabpanel-scanner" role="tabpanel" aria-labelledby="frcn-tab-scanner" hidden>
			<div class="frcn-scanner" data-frcn-scanner>
				<h2><?php esc_html_e( 'Cookie compliance scanner', 'frontconsent' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: %s: link to tuwebcumple.es */
						esc_html__( 'Analyze how your site handles cookies and consent, as seen by a first-time visitor. The scan is run by %s: your site\'s public address is sent to that service when you click the button.', 'frontconsent' ),
						'<a href="https://tuwebcumple.es/" target="_blank" rel="noopener noreferrer">tuwebcumple.es</a>'
					);
					?>
				</p>
				<p>
					<code><?php echo esc_html( self::get_site_url() ); ?></code>
				</p>
				<p>
					<button type="button" class="button button-primary" data-frcn-scanner-start<?php disabled( self::is_pending( $scan ) ); ?>>
						<?php echo $scan ? esc_html__( 'Scan again', 'frontconsent' ) : esc_html__( 'Scan my site', 'frontconsent' ); ?>
					</button>
				</p>
				<div class="frcn-scanner__message" data-frcn-scanner-message role="status" aria-live="polite"></div>
				<div data-frcn-scanner-result<?php echo self::is_pending( $scan ) ? ' data-frcn-scanner-pending' : ''; ?>>
					<?php echo self::render_result( $scan ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in render_result(). ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Register the dashboard widget for users who can run scans.
	 *
	 * @return void
	 */
	public function register_dashboard_widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'frontconsent_scanner',
			__( 'FrontConsent: cookie compliance', 'frontconsent' ),
			array( $this, 'render_dashboard_widget' )
		);
	}

	/**
	 * Render the dashboard widget: the last result in compact form.
	 *
	 * @return void
	 */
	public function render_dashboard_widget() {
		$scan = self::get_stored_scan();
		$link = admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '#scanner' );
		?>
		<div class="frcn-scanner frcn-scanner--widget" data-frcn-scanner>
			<div data-frcn-scanner-result<?php echo self::is_pending( $scan ) ? ' data-frcn-scanner-pending' : ''; ?>>
				<?php
				if ( $scan ) {
					echo self::render_result( $scan, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in render_result().
				} else {
					echo '<p>' . esc_html__( 'Your site has not been scanned yet. Check whether your cookie banner complies with the GDPR and the AEPD guide.', 'frontconsent' ) . '</p>';
				}
				?>
			</div>
			<p class="frcn-scanner__widget-link">
				<a class="button" href="<?php echo esc_url( $link ); ?>">
					<?php echo $scan ? esc_html__( 'View details', 'frontconsent' ) : esc_html__( 'Scan my site', 'frontconsent' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * AJAX: start a scan of this site.
	 *
	 * @return void
	 */
	public function ajax_start() {
		self::check_ajax_request();

		$stored = self::get_stored_scan();
		if ( self::is_pending( $stored ) ) {
			self::send_scan( $stored );
		}

		$remaining = self::cooldown_remaining();
		if ( $remaining > 0 ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %d: minutes to wait. */
						_n( 'Your site was scanned a moment ago. You can scan it again in %d minute.', 'Your site was scanned a moment ago. You can scan it again in %d minutes.', (int) ceil( $remaining / 60 ), 'frontconsent' ),
						(int) ceil( $remaining / 60 )
					),
				),
				429
			);
		}

		$response = self::request(
			'POST',
			'scans',
			array( 'url' => self::get_site_url() )
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ), (int) $response->get_error_data() );
		}

		set_transient( self::COOLDOWN_TRANSIENT, time() + self::COOLDOWN_SECONDS, self::COOLDOWN_SECONDS );
		self::send_scan( self::store_scan( $response ) );
	}

	/**
	 * AJAX: refresh the stored scan while it is in progress.
	 *
	 * Only ever asks about the scan this site started, never a uuid sent by
	 * the browser.
	 *
	 * @return void
	 */
	public function ajax_status() {
		self::check_ajax_request();

		$stored = self::get_stored_scan();
		if ( ! self::is_pending( $stored ) ) {
			self::send_scan( $stored );
		}

		$response = self::request( 'GET', 'scans/' . rawurlencode( $stored['uuid'] ) );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ), (int) $response->get_error_data() );
		}

		self::send_scan( self::store_scan( $response ) );
	}

	/**
	 * Render a scan as HTML: status while pending, error when failed, and
	 * grade, headline, counts and checks when done.
	 *
	 * @param array|null $scan    Stored scan.
	 * @param bool       $compact Dashboard widget layout (no check list).
	 * @return string
	 */
	public static function render_result( $scan, $compact = false ) {
		if ( ! $scan ) {
			return '';
		}

		if ( self::is_pending( $scan ) ) {
			return '<p class="frcn-scanner__pending"><span class="spinner is-active"></span>' . esc_html__( 'Analyzing your site… this usually takes under a minute.', 'frontconsent' ) . '</p>';
		}

		if ( 'failed' === $scan['status'] ) {
			return '<p class="frcn-scanner__error">' . esc_html__( 'The last scan could not be completed: the site could not be reached or did not load in time.', 'frontconsent' ) . '</p>';
		}

		$labels = self::status_labels();
		$grade  = strtoupper( substr( (string) $scan['grade'], 0, 1 ) );

		ob_start();
		?>
		<div class="frcn-scanner__summary">
			<div class="frcn-scanner__grade frcn-scanner__grade--<?php echo esc_attr( strtolower( $grade ) ); ?>">
				<span class="frcn-scanner__grade-letter"><?php echo esc_html( $grade ); ?></span>
				<span class="frcn-scanner__score">
					<?php
					/* translators: %d: score out of 100. */
					echo esc_html( sprintf( __( '%d/100', 'frontconsent' ), (int) $scan['score'] ) );
					?>
				</span>
			</div>
			<div>
				<p class="frcn-scanner__headline"><?php echo esc_html( $scan['headline'] ); ?></p>
				<p class="frcn-scanner__counts">
					<?php
					foreach ( array( 'fail', 'warn', 'pass' ) as $status ) {
						printf(
							'<span class="frcn-scanner__count frcn-scanner__status--%1$s"><span class="frcn-scanner__icon" aria-hidden="true"></span>%2$s: %3$d</span> ',
							esc_attr( $status ),
							esc_html( $labels[ $status ] ),
							(int) ( isset( $scan['counts'][ $status ] ) ? $scan['counts'][ $status ] : 0 )
						);
					}
					?>
				</p>
				<?php if ( ! empty( $scan['analyzed_at'] ) ) : ?>
					<p class="frcn-scanner__date">
						<?php
						/* translators: %s: date and time of the scan. */
						echo esc_html( sprintf( __( 'Analyzed on %s', 'frontconsent' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $scan['analyzed_at'] ) ) ) );
						?>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php if ( ! $compact && ! empty( $scan['checks'] ) ) : ?>
			<ul class="frcn-scanner__checks">
				<?php foreach ( $scan['checks'] as $check ) : ?>
					<?php $status = isset( $labels[ $check['status'] ] ) ? $check['status'] : 'not_applicable'; ?>
					<li class="frcn-scanner__check frcn-scanner__status--<?php echo esc_attr( $status ); ?>">
						<span class="frcn-scanner__icon" aria-hidden="true"></span>
						<div>
							<strong><?php echo esc_html( $check['label'] ); ?></strong>
							<span class="frcn-scanner__check-status">(<?php echo esc_html( $labels[ $status ] ); ?>)</span>
							<p><?php echo esc_html( $check['summary'] ); ?></p>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php if ( ! $compact && ! empty( $scan['result_url'] ) ) : ?>
			<p>
				<a href="<?php echo esc_url( $scan['result_url'] ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'View the full result on tuwebcumple.es', 'frontconsent' ); ?> →
				</a>
			</p>
		<?php endif; ?>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The last stored scan, or null.
	 *
	 * @return array|null
	 */
	public static function get_stored_scan() {
		$scan = get_option( self::OPTION_NAME );

		return is_array( $scan ) && ! empty( $scan['uuid'] ) ? $scan : null;
	}

	/**
	 * Whether a scan is still queued or running.
	 *
	 * @param array|null $scan Stored scan.
	 * @return bool
	 */
	public static function is_pending( $scan ) {
		return is_array( $scan ) && isset( $scan['status'] ) && in_array( $scan['status'], array( 'queued', 'running' ), true );
	}

	/**
	 * Seconds left before a new scan can be started, or 0.
	 *
	 * @return int
	 */
	public static function cooldown_remaining() {
		$until = (int) get_transient( self::COOLDOWN_TRANSIENT );

		return max( 0, $until - time() );
	}

	/**
	 * The address that gets scanned.
	 *
	 * @return string
	 */
	public static function get_site_url() {
		/**
		 * Filters the URL sent to the scanner (defaults to the home URL).
		 *
		 * @since 1.1.0
		 * @param string $url Site URL.
		 */
		return (string) apply_filters( 'frontconsent_scanner_site_url', home_url( '/' ) );
	}

	/**
	 * Keep only the fields the plugin displays, with their expected types,
	 * and save them.
	 *
	 * @param array $data `data` object of an API response.
	 * @return array Stored scan.
	 */
	public static function store_scan( array $data ) {
		$checks = array();
		if ( isset( $data['checks'] ) && is_array( $data['checks'] ) ) {
			foreach ( $data['checks'] as $check ) {
				if ( ! is_array( $check ) ) {
					continue;
				}
				$checks[] = array(
					'id'      => sanitize_text_field( isset( $check['id'] ) ? (string) $check['id'] : '' ),
					'status'  => sanitize_key( isset( $check['status'] ) ? (string) $check['status'] : '' ),
					'label'   => sanitize_text_field( isset( $check['label'] ) ? (string) $check['label'] : '' ),
					'summary' => sanitize_text_field( isset( $check['summary'] ) ? (string) $check['summary'] : '' ),
				);
			}
		}

		$counts = array();
		foreach ( array( 'pass', 'warn', 'fail', 'not_applicable', 'reviewed' ) as $key ) {
			$counts[ $key ] = isset( $data['counts'][ $key ] ) ? absint( $data['counts'][ $key ] ) : 0;
		}

		$scan = array(
			'uuid'               => sanitize_text_field( isset( $data['uuid'] ) ? (string) $data['uuid'] : '' ),
			'status'             => sanitize_key( isset( $data['status'] ) ? (string) $data['status'] : 'failed' ),
			'url'                => esc_url_raw( isset( $data['url'] ) ? (string) $data['url'] : '' ),
			'result_url'         => esc_url_raw( isset( $data['result_url'] ) ? (string) $data['result_url'] : '' ),
			'analyzed_at'        => sanitize_text_field( isset( $data['analyzed_at'] ) ? (string) $data['analyzed_at'] : '' ),
			'score'              => isset( $data['score'] ) ? absint( $data['score'] ) : 0,
			'grade'              => sanitize_text_field( isset( $data['grade'] ) ? (string) $data['grade'] : '' ),
			'headline'           => sanitize_text_field( isset( $data['headline'] ) ? (string) $data['headline'] : '' ),
			'counts'             => $counts,
			'checks'             => $checks,
			'poll_after_seconds' => isset( $data['poll_after_seconds'] ) ? max( 3, absint( $data['poll_after_seconds'] ) ) : 5,
		);

		update_option( self::OPTION_NAME, $scan, false );

		return $scan;
	}

	/**
	 * Call the API.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Path relative to the API base URL.
	 * @param array  $body   Request body, sent as JSON.
	 * @return array|\WP_Error The response's `data` object, or an error whose data is the HTTP status to answer with.
	 */
	private static function request( $method, $path, array $body = array() ) {
		/**
		 * Filters the base URL of the tuwebcumple.es scan API.
		 *
		 * @since 1.1.0
		 * @param string $url API base URL, ending in a slash.
		 */
		$base = trailingslashit( (string) apply_filters( 'frontconsent_scanner_api_url', self::API_URL ) );

		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array( 'Accept' => 'application/json' ),
		);
		if ( $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $base . $path, $args );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'frcn_scanner_unreachable', __( 'Could not connect to the scanning service. Please try again later.', 'frontconsent' ), 502 );
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 429 === $code ) {
			return new \WP_Error( 'frcn_scanner_rate_limited', __( 'The scanning service has received too many requests. Please try again in a while.', 'frontconsent' ), 429 );
		}

		if ( 422 === $code ) {
			$message = isset( $decoded['errors']['url'][0] ) ? sanitize_text_field( (string) $decoded['errors']['url'][0] ) : '';

			return new \WP_Error(
				'frcn_scanner_invalid_url',
				'' !== $message ? $message : __( 'This site cannot be scanned. It must be publicly reachable on the internet.', 'frontconsent' ),
				422
			);
		}

		if ( $code < 200 || $code >= 300 || ! isset( $decoded['data'] ) || ! is_array( $decoded['data'] ) || empty( $decoded['data']['uuid'] ) ) {
			return new \WP_Error( 'frcn_scanner_bad_response', __( 'The scanning service returned an unexpected response. Please try again later.', 'frontconsent' ), 502 );
		}

		return $decoded['data'];
	}

	/**
	 * Verify capability and nonce of an AJAX request.
	 *
	 * @return void
	 */
	private static function check_ajax_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'frontconsent' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
	}

	/**
	 * Send a scan to the browser with its rendered HTML.
	 *
	 * @param array|null $scan Stored scan.
	 * @return void
	 */
	private static function send_scan( $scan ) {
		wp_send_json_success(
			array(
				'status'           => $scan ? $scan['status'] : '',
				'pending'          => self::is_pending( $scan ),
				'pollAfterSeconds' => isset( $scan['poll_after_seconds'] ) ? (int) $scan['poll_after_seconds'] : 5,
				'html'             => self::render_result( $scan ),
				'compactHtml'      => self::render_result( $scan, true ),
			)
		);
	}
}
