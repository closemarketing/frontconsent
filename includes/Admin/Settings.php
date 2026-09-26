<?php
/**
 * Admin settings page.
 *
 * @package    FrontConsent
 * @author     Closemarketing
 * @copyright  2026 Closemarketing
 * @version    1.0.0
 */

namespace FrontConsent\Admin;

use FrontConsent\Frontend\CookieNotice;

defined( 'ABSPATH' ) || exit;

/**
 * Settings class.
 *
 * Renders the FrontConsent settings page (a single Cookie Notice screen) and
 * handles saving/sanitizing its options.
 *
 * @since 1.0.0
 */
class Settings {

	/**
	 * Option name storing all FrontConsent settings.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'frontconsent_settings';

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	private $page_slug = 'frontconsent-settings';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the settings page under Settings.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_options_page(
			__( 'FrontConsent', 'frontconsent' ),
			__( 'FrontConsent', 'frontconsent' ),
			'manage_options',
			$this->page_slug,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the settings option, section and fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_NAME,
			self::OPTION_NAME,
			array(
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(),
			)
		);

		add_settings_section(
			'frontconsent_section_cookie_notice',
			__( 'Cookie Notice', 'frontconsent' ),
			array( $this, 'section_cookie_notice_callback' ),
			$this->page_slug
		);

		add_settings_field(
			'enable_cookie_notice',
			__( 'Cookie Notice', 'frontconsent' ),
			array( $this, 'field_cookie_notice' ),
			$this->page_slug,
			'frontconsent_section_cookie_notice'
		);
	}

	/**
	 * Enqueue the settings page CSS/JS, only on the FrontConsent settings screen.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'settings_page_' . $this->page_slug !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'frontconsent-cookie-notice',
			FRCN_PLUGIN_URL . 'assets/cookie-notice/frontconsent-cookie-notice.css',
			array(),
			FRCN_VERSION
		);

		wp_enqueue_style(
			'frontconsent-settings',
			FRCN_PLUGIN_URL . 'assets/admin/settings.css',
			array( 'frontconsent-cookie-notice' ),
			FRCN_VERSION
		);

		wp_enqueue_script(
			'frontconsent-settings',
			FRCN_PLUGIN_URL . 'assets/admin/settings.js',
			array(),
			FRCN_VERSION,
			true
		);
	}

	/**
	 * Section intro text.
	 *
	 * @return void
	 */
	public function section_cookie_notice_callback() {
		?>
		<p><?php echo esc_html__( 'Show a cookie consent banner and only load Google Tag Manager / GA4 after a visitor accepts.', 'frontconsent' ); ?></p>
		<?php
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->render_cache_notice();
		?>
		<div class="wrap frcn-settings-wrapper">
			<h1><?php echo esc_html__( 'FrontConsent', 'frontconsent' ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_NAME );
				do_settings_sections( $this->page_slug );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the Cookie Notice field group: enable toggle, stats, tracking
	 * integrations, message/labels, layout/colors and expiration.
	 *
	 * @return void
	 */
	public function field_cookie_notice() {
		$options               = get_option( self::OPTION_NAME, array() );
		$enabled               = (bool) ( $options['enable_cookie_notice'] ?? false );
		$message               = (string) ( $options['cookie_notice_message'] ?? '' );
		$accept_label          = (string) ( $options['cookie_notice_accept_label'] ?? '' );
		$reject_label          = (string) ( $options['cookie_notice_reject_label'] ?? '' );
		$policy_page_id        = (int) ( $options['cookie_notice_policy_page_id'] ?? 0 );
		$layout                = (string) ( $options['cookie_notice_layout'] ?? 'bar' );
		$position              = (string) ( $options['cookie_notice_position'] ?? 'bottom-right' );
		$color                 = (string) ( $options['cookie_notice_color'] ?? '#687df9' );
		$bg_color              = (string) ( $options['cookie_notice_bg_color'] ?? '#ffffff' );
		$radius                = (string) ( $options['cookie_notice_radius'] ?? 'small' );
		$expiration            = (int) ( $options['cookie_notice_expiration_days'] ?? 365 );
		$tracking_integrations = CookieNotice::get_tracking_integrations( $options );
		$site_kit_tags         = $this->get_google_site_kit_managed_tags();
		$accepted_count        = (int) get_option( CookieNotice::STATS_OPTION_ACCEPTED, 0 );
		$rejected_count        = (int) get_option( CookieNotice::STATS_OPTION_REJECTED, 0 );
		$total_count           = $accepted_count + $rejected_count;
		$acceptance_pct        = $total_count > 0 ? round( ( $accepted_count / $total_count ) * 100, 1 ) : 0;
		?>
		<div class="frcn-cookie-notice-wrapper">
			<div class="frcn-field-row frcn-field-row--toggle">
				<label for="enable_cookie_notice" class="frcn-toggle-label">
					<?php echo esc_html__( 'Enable Cookie Notice', 'frontconsent' ); ?>
				</label>
				<label class="frcn-toggle">
					<input type="checkbox"
						id="enable_cookie_notice"
						name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enable_cookie_notice]"
						value="1"
						<?php checked( true, $enabled ); ?>
					/>
					<span></span>
				</label>
			</div>

			<div id="cookie-notice-fields-wrapper" style="<?php echo $enabled ? '' : 'display: none;'; ?>">

				<?php if ( $total_count > 0 ) : ?>
					<div class="frcn-stats-row">
						<div class="frcn-stat">
							<p class="frcn-stat__label"><?php echo esc_html__( 'Acceptance rate', 'frontconsent' ); ?></p>
							<p class="frcn-stat__value"><?php echo esc_html( $acceptance_pct ); ?>%</p>
						</div>
						<div class="frcn-stat">
							<p class="frcn-stat__label"><?php echo esc_html__( 'Accepted', 'frontconsent' ); ?></p>
							<p class="frcn-stat__value"><?php echo esc_html( $accepted_count ); ?></p>
						</div>
						<div class="frcn-stat">
							<p class="frcn-stat__label"><?php echo esc_html__( 'Rejected', 'frontconsent' ); ?></p>
							<p class="frcn-stat__value"><?php echo esc_html( $rejected_count ); ?></p>
						</div>
					</div>
				<?php endif; ?>

				<div class="frcn-panel">
					<?php
					// Site Kit-managed types are hidden from the list and never
					// re-added: the "Google tag is managed by Site Kit" notice
					// below is the only UI shown for them.
					$site_kit_managed_types = array_keys(
						array_filter(
							array(
								'gtm' => $site_kit_tags['gtm'],
								'ga4' => $site_kit_tags['ga4'],
							)
						)
					);
					$visible_integrations   = array_values(
						array_filter(
							$tracking_integrations,
							static function ( $integration ) use ( $site_kit_managed_types ) {
								return ! in_array( $integration['type'], $site_kit_managed_types, true );
							}
						)
					);
					$gtm_integration        = null;
					foreach ( $tracking_integrations as $integration ) {
						if ( 'gtm' === $integration['type'] ) {
							$gtm_integration = $integration['id'];
							break;
						}
					}
					?>
					<?php if ( $site_kit_tags['gtm'] || $site_kit_tags['ga4'] ) : ?>
						<p class="frcn-hint"><?php echo esc_html__( 'Google Site Kit manages the configured Google tag. FrontConsent applies Consent Mode to it, so no duplicate ID is needed here.', 'frontconsent' ); ?></p>
					<?php endif; ?>

					<p class="frcn-hint"><?php echo esc_html__( 'Scripts are only requested after a visitor accepts — never before.', 'frontconsent' ); ?></p>

					<?php if ( $enabled && $this->is_gtm4wp_container_loading( (string) $gtm_integration ) ) : ?>
					<div class="frcn-alert" role="alert">
						<p class="frcn-alert__title"><?php echo esc_html__( 'Google Tag Manager may load twice.', 'frontconsent' ); ?></p>
						<p class="frcn-alert__body">
							<?php
							printf(
								wp_kses(
									/* translators: %s: Google Tag Manager for WordPress settings page URL. */
									__( 'The same container is enabled in Google Tag Manager for WordPress. Disable its container-code injection in <a href="%s">its settings</a> so FrontConsent can load it only after consent. Its data layer can remain enabled.', 'frontconsent' ),
									array( 'a' => array( 'href' => array() ) )
								),
								esc_url( admin_url( 'options-general.php?page=gtm4wp-settings' ) )
							);
							?>
						</p>
					</div>
					<?php endif; ?>

					<div>
						<?php
						$tracking_labels = array(
							'gtm'                         => __( 'Google Tag Manager', 'frontconsent' ),
							'ga4'                         => __( 'GA4 (Google Analytics)', 'frontconsent' ),
							'clientify_analytics_plus'    => __( 'Clientify Analytics Plus', 'frontconsent' ),
							'clientify_analytics_classic' => __( 'Clientify Analytics (classic)', 'frontconsent' ),
							'brevo'                       => __( 'Brevo', 'frontconsent' ),
							'openai_chatgpt_ads'          => __( 'ChatGPT Ads', 'frontconsent' ),
						);
						?>
						<p class="frcn-label"><?php echo esc_html__( 'Added tracking integrations', 'frontconsent' ); ?></p>
						<?php if ( $visible_integrations ) : ?>
							<ul class="frcn-integration-list">
								<?php foreach ( $visible_integrations as $integration ) : ?>
									<li class="frcn-integration-item">
										<span>
											<strong><?php echo esc_html( apply_filters( 'frcn_cookie_notice_tracking_type_label', $tracking_labels[ $integration['type'] ] ?? $integration['type'], $integration['type'] ) ); ?></strong>
											<span class="frcn-mono">(<?php echo esc_html( $integration['id'] ); ?>)</span>
										</span>
										<label class="frcn-remove">
											<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[cookie_notice_tracking_remove][]" value="<?php echo esc_attr( $integration['type'] ); ?>" />
											<?php echo esc_html__( 'Remove', 'frontconsent' ); ?>
										</label>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php else : ?>
							<p class="frcn-hint"><?php echo esc_html__( 'No additional tracking integrations have been added.', 'frontconsent' ); ?></p>
						<?php endif; ?>
						<label for="cookie_notice_tracking_integration_code" class="frcn-label">
							<?php echo esc_html__( 'Add a tracking ID or code integration', 'frontconsent' ); ?>
						</label>
						<input
							type="text"
							id="cookie_notice_tracking_integration_code"
							name="<?php echo esc_attr( self::OPTION_NAME ); ?>[cookie_notice_tracking_integration_code]"
							value=""
							placeholder="<?php echo esc_attr__( 'Paste a tracking ID (GTM-XXXXXXX, G-XXXXXXXXXX…) or code…', 'frontconsent' ); ?>"
							class="frcn-input frcn-mono"
						/>
						<p class="frcn-hint">
							<?php
							printf(
								wp_kses(
									/* translators: %s: contact page URL. */
									__( 'For security reasons, only a supported integration ID is extracted and saved; the pasted code is discarded. Need another tool supported? <a href="%s" target="_blank" rel="noopener noreferrer">Contact us</a>.', 'frontconsent' ),
									array(
										'a' => array(
											'href'   => array(),
											'target' => array(),
											'rel'    => array(),
										),
									)
								),
								esc_url( 'https://close.technology/contacto' )
							);
							?>
						</p>
					</div>
				</div>

				<div class="frcn-panel">
					<label for="cookie_notice_message" class="frcn-label">
						<?php echo esc_html__( 'Message', 'frontconsent' ); ?>
					</label>
					<textarea
						id="cookie_notice_message"
						name="<?php echo esc_attr( self::OPTION_NAME ); ?>[cookie_notice_message]"
						rows="3"
						placeholder="<?php echo esc_attr__( 'We use cookies to improve your experience on our website. By browsing this website, you agree to our use of cookies.', 'frontconsent' ); ?>"
						class="frcn-input"
					><?php echo esc_textarea( $message ); ?></textarea>

					<div class="frcn-grid-2">
						<div>
							<label for="cookie_notice_accept_label" class="frcn-label">
								<?php echo esc_html__( 'Accept button label', 'frontconsent' ); ?>
							</label>
							<input
								type="text"
								id="cookie_notice_accept_label"
								name="<?php echo esc_attr( self::OPTION_NAME ); ?>[cookie_notice_accept_label]"
								value="<?php echo esc_attr( $accept_label ); ?>"
								placeholder="<?php echo esc_attr__( 'Accept', 'frontconsent' ); ?>"
								class="frcn-input"
							/>
						</div>
						<div>
							<label for="cookie_notice_reject_label" class="frcn-label">
								<?php echo esc_html__( 'Reject button label', 'frontconsent' ); ?>
							</label>
							<input
								type="text"
								id="cookie_notice_reject_label"
								name="<?php echo esc_attr( self::OPTION_NAME ); ?>[cookie_notice_reject_label]"
								value="<?php echo esc_attr( $reject_label ); ?>"
								placeholder="<?php echo esc_attr__( 'Reject', 'frontconsent' ); ?>"
								class="frcn-input"
							/>
						</div>
					</div>

					<label for="cookie_notice_policy_page_id" class="frcn-label frcn-label--mt">
						<?php echo esc_html__( 'Cookie policy page (optional)', 'frontconsent' ); ?>
					</label>
					<select
						id="cookie_notice_policy_page_id"
						name="<?php echo esc_attr( self::OPTION_NAME ); ?>[cookie_notice_policy_page_id]"
						class="frcn-input"
					>
						<option value=""><?php echo esc_html__( '— None —', 'frontconsent' ); ?></option>
						<?php
						$pages_limit = 300;
						$pages       = get_pages(
							array(
								'sort_column' => 'post_title',
								'number'      => $pages_limit,
							)
						);

						// The saved page may fall outside the limited list above (e.g. sorted
						// past it alphabetically on a large site) — make sure it still shows up
						// and stays selected instead of silently disappearing from the dropdown.
						$selected_page_listed = 0 === $policy_page_id;
						foreach ( $pages as $page ) {
							if ( $policy_page_id === $page->ID ) {
								$selected_page_listed = true;
								break;
							}
						}

						if ( ! $selected_page_listed ) {
							$selected_page = get_post( $policy_page_id );
							if ( $selected_page instanceof \WP_Post ) {
								array_unshift( $pages, $selected_page );
							}
						}

						foreach ( $pages as $page ) {
							printf(
								'<option value="%1$d" %2$s>%3$s</option>',
								(int) $page->ID,
								selected( $policy_page_id, $page->ID, false ),
								esc_html( $page->post_title )
							);
						}
						?>
					</select>
					<?php if ( count( $pages ) >= $pages_limit ) : ?>
						<p class="frcn-hint frcn-hint--warn">
							<?php
							printf(
								/* translators: %d: number of pages shown in the dropdown. */
								esc_html__( 'Showing the first %d pages. If the page you need is missing, search for it in the Pages list to find its ID and set it via the frontconsent_settings option.', 'frontconsent' ),
								(int) $pages_limit
							);
							?>
						</p>
					<?php endif; ?>
					<p class="frcn-hint">
						<?php echo esc_html__( 'The banner is hidden on this page so visitors can read it before deciding. A "Learn more" link to it is added to the message.', 'frontconsent' ); ?>
					</p>
				</div>

				<div class="frcn-panel">
					<div class="frcn-grid-3">
						<div>
							<label for="cookie_notice_layout" class="frcn-label">
								<?php echo esc_html__( 'Layout', 'frontconsent' ); ?>
							</label>
							<select
								id="cookie_notice_layout"
								name="<?php echo esc_attr( self::OPTION_NAME ); ?>[cookie_notice_layout]"
								class="frcn-input"
							>
								<option value="bar" <?php selected( $layout, 'bar' ); ?>><?php echo esc_html__( 'Full-width bar', 'frontconsent' ); ?></option>
								<option value="box" <?php selected( $layout, 'box' ); ?>><?php echo esc_html__( 'Boxed panel', 'frontconsent' ); ?></option>
								<option value="popup" <?php selected( $layout, 'popup' ); ?>><?php echo esc_html__( 'Centered popup', 'frontconsent' ); ?></option>
							</select>
						</div>
						<div id="cookie-notice-position-wrapper" style="<?php echo 'box' === $layout ? '' : 'display: none;'; ?>">
							<label for="cookie_notice_position" class="frcn-label">
								<?php echo esc_html__( 'Position', 'frontconsent' ); ?>
							</label>
							<select
								id="cookie_notice_position"
								name="<?php echo esc_attr( self::OPTION_NAME ); ?>[cookie_notice_position]"
								class="frcn-input"
							>
								<option value="bottom-right" <?php selected( $position, 'bottom-right' ); ?>><?php echo esc_html__( 'Bottom right', 'frontconsent' ); ?></option>
								<option value="bottom-left" <?php selected( $position, 'bottom-left' ); ?>><?php echo esc_html__( 'Bottom left', 'frontconsent' ); ?></option>
							</select>
						</div>
						<div>
							<label for="cookie_notice_color" class="frcn-label">
								<?php echo esc_html__( 'Accent color', 'frontconsent' ); ?>
							</label>
							<input
								type="color"
								id="cookie_notice_color"
								name="<?php echo esc_attr( self::OPTION_NAME ); ?>[cookie_notice_color]"
								value="<?php echo esc_attr( $color ); ?>"
								class="frcn-color-input"
							/>
						</div>
					</div>

					<div class="frcn-grid-2 frcn-grid--mt">
						<div>
							<label for="cookie_notice_bg_color" class="frcn-label">
								<?php echo esc_html__( 'Background color', 'frontconsent' ); ?>
							</label>
							<input
								type="color"
								id="cookie_notice_bg_color"
								name="<?php echo esc_attr( self::OPTION_NAME ); ?>[cookie_notice_bg_color]"
								value="<?php echo esc_attr( $bg_color ); ?>"
								class="frcn-color-input"
							/>
						</div>
						<div>
							<label for="cookie_notice_radius" class="frcn-label">
								<?php echo esc_html__( 'Corner rounding', 'frontconsent' ); ?>
							</label>
							<select
								id="cookie_notice_radius"
								name="<?php echo esc_attr( self::OPTION_NAME ); ?>[cookie_notice_radius]"
								class="frcn-input"
							>
								<option value="none" <?php selected( $radius, 'none' ); ?>><?php echo esc_html__( 'None', 'frontconsent' ); ?></option>
								<option value="small" <?php selected( $radius, 'small' ); ?>><?php echo esc_html__( 'Slightly rounded', 'frontconsent' ); ?></option>
								<option value="large" <?php selected( $radius, 'large' ); ?>><?php echo esc_html__( 'Very rounded', 'frontconsent' ); ?></option>
							</select>
						</div>
					</div>

					<div class="frcn-grid--mt">
						<p class="frcn-label"><?php echo esc_html__( 'Preview', 'frontconsent' ); ?></p>
						<?php
						$preview_accent_text = CookieNotice::get_readable_text_color( $color );
						$preview_accent_link = CookieNotice::get_readable_on_white_color( $color );
						$preview_panel_text  = CookieNotice::get_readable_text_color( $bg_color );
						$preview_radius      = CookieNotice::get_radius_value( $radius );
						?>
						<div id="frcn-cookie-notice-preview-stage" class="frcn-cookie-notice-preview-stage">
							<div
								id="frcn-cookie-notice-preview"
								class="frcn-cookie-notice frcn-cookie-notice-preview frcn-cookie-notice--<?php echo esc_attr( $layout ); ?><?php echo 'box' === $layout ? ' frcn-cookie-notice--' . ( 'bottom-left' === $position ? 'left' : 'right' ) : ''; ?>"
								style="--frcn-cookie-accent: <?php echo esc_attr( $color ); ?>; --frcn-cookie-accent-contrast: <?php echo esc_attr( $preview_accent_text ); ?>; --frcn-cookie-accent-on-light: <?php echo esc_attr( $preview_accent_link ); ?>; --frcn-cookie-bg: <?php echo esc_attr( $bg_color ); ?>; --frcn-cookie-text: <?php echo esc_attr( $preview_panel_text ); ?>; --frcn-cookie-radius: <?php echo esc_attr( $preview_radius ); ?>;"
							>
								<div class="frcn-cookie-notice__panel">
									<span id="frcn-cookie-notice-preview-icon" class="frcn-cookie-notice__icon">
										<?php echo CookieNotice::get_cookie_icon_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG, no dynamic data. ?>
									</span>
									<p class="frcn-cookie-notice__message">
										<?php echo esc_html( '' !== $message ? $message : __( 'We use cookies to improve your experience on our website. By browsing this website, you agree to our use of cookies.', 'frontconsent' ) ); ?>
									</p>
									<div class="frcn-cookie-notice__actions">
										<button type="button" class="frcn-cookie-notice__button frcn-cookie-notice__button--reject" disabled>
											<?php echo esc_html( '' !== $reject_label ? $reject_label : __( 'Reject', 'frontconsent' ) ); ?>
										</button>
										<button type="button" class="frcn-cookie-notice__button frcn-cookie-notice__button--accept" disabled>
											<?php echo esc_html( '' !== $accept_label ? $accept_label : __( 'Accept', 'frontconsent' ) ); ?>
										</button>
									</div>
								</div>
							</div>
						</div>
					</div>

					<label for="cookie_notice_expiration_days" class="frcn-label frcn-label--mt">
						<?php echo esc_html__( 'Cookie expiration (days)', 'frontconsent' ); ?>
					</label>
					<input
						type="number"
						min="1"
						max="730"
						id="cookie_notice_expiration_days"
						name="<?php echo esc_attr( self::OPTION_NAME ); ?>[cookie_notice_expiration_days]"
						value="<?php echo esc_attr( $expiration ); ?>"
						class="frcn-input frcn-input--narrow"
					/>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Check whether GTM4WP is set to load the same container as Cookie Notice.
	 *
	 * @param string $frontconsent_gtm_id FrontConsent GTM container ID.
	 * @return bool
	 */
	private function is_gtm4wp_container_loading( $frontconsent_gtm_id ) {
		if ( '' === $frontconsent_gtm_id || ( ! defined( 'GTM4WP_OPTIONS' ) && ! function_exists( 'gtm4wp_the_gtm_tag' ) ) ) {
			return false;
		}

		$gtm4wp_options = get_option( 'gtm4wp-options', array() );
		if ( ! is_array( $gtm4wp_options ) ) {
			return false;
		}

		$placement_off = defined( 'GTM4WP_PLACEMENT_OFF' ) ? (int) GTM4WP_PLACEMENT_OFF : 3;
		$placement     = isset( $gtm4wp_options['gtm-code-placement'] ) ? (int) $gtm4wp_options['gtm-code-placement'] : 0;

		if ( $placement_off === $placement ) {
			return false;
		}

		$container_ids = array_filter(
			array_map(
				'trim',
				explode( ',', (string) ( $gtm4wp_options['gtm-code'] ?? '' ) )
			)
		);

		if ( isset( $gtm4wp_options['gtm-containers'] ) && is_array( $gtm4wp_options['gtm-containers'] ) ) {
			foreach ( $gtm4wp_options['gtm-containers'] as $container ) {
				if ( is_array( $container ) && ! empty( $container['id'] ) ) {
					$container_ids[] = (string) $container['id'];
				}
			}
		}

		$container_ids = array_map( 'strtoupper', $container_ids );

		return in_array( strtoupper( $frontconsent_gtm_id ), $container_ids, true );
	}

	/**
	 * Get the Google tags that Site Kit is configured to place.
	 *
	 * @return array{gtm: bool, ga4: bool}
	 */
	private function get_google_site_kit_managed_tags() {
		$tags = array(
			'gtm' => false,
			'ga4' => false,
		);

		if ( ! defined( 'GOOGLESITEKIT_VERSION' ) && ! class_exists( '\\Google\\Site_Kit\\Plugin' ) ) {
			return $tags;
		}

		$tag_manager_settings = get_option( 'googlesitekit_tagmanager_settings', array() );
		if ( is_array( $tag_manager_settings ) && ! empty( $tag_manager_settings['containerID'] ) && ( ! isset( $tag_manager_settings['useSnippet'] ) || $tag_manager_settings['useSnippet'] ) ) {
			$tags['gtm'] = true;
		}

		$analytics_settings = get_option( 'googlesitekit_analytics-4_settings', array() );
		if ( is_array( $analytics_settings ) && ! empty( $analytics_settings['measurementID'] ) && ( ! isset( $analytics_settings['useSnippet'] ) || $analytics_settings['useSnippet'] ) ) {
			$tags['ga4'] = true;
		}

		return $tags;
	}

	/**
	 * Render a one-time cache notice after Cookie Notice settings are saved.
	 *
	 * @return void
	 */
	private function render_cache_notice() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$notice = get_transient( 'frcn_cookie_notice_cache_notice_' . $user_id );
		if ( ! $notice ) {
			return;
		}

		delete_transient( 'frcn_cookie_notice_cache_notice_' . $user_id );
		?>
		<div class="notice notice-info">
			<p>
				<?php
				if ( 'wp-rocket' === $notice ) {
					esc_html_e( 'Cookie Notice settings were updated and the WP Rocket cache was cleared.', 'frontconsent' );
				} else {
					esc_html_e( 'Cookie Notice settings were updated. If you use a full-page cache, purge it now so visitors receive the new configuration.', 'frontconsent' );
				}
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Sanitize the settings option on save.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $value ) {
		if ( ! is_array( $value ) ) {
			return get_option( self::OPTION_NAME, array() );
		}

		$current_options = get_option( self::OPTION_NAME, array() );
		$sanitized       = $current_options;

		// Unchecked checkboxes are not submitted at all.
		$sanitized['enable_cookie_notice'] = ! empty( $value['enable_cookie_notice'] );

		if ( array_key_exists( 'cookie_notice_message', $value ) ) {
			$sanitized['cookie_notice_message'] = sanitize_textarea_field( $value['cookie_notice_message'] );
		}

		foreach ( array( 'cookie_notice_accept_label', 'cookie_notice_reject_label' ) as $key ) {
			if ( array_key_exists( $key, $value ) ) {
				$sanitized[ $key ] = sanitize_text_field( $value[ $key ] );
			}
		}

		if ( array_key_exists( 'cookie_notice_policy_page_id', $value ) ) {
			$sanitized['cookie_notice_policy_page_id'] = absint( $value['cookie_notice_policy_page_id'] );
		}

		if ( array_key_exists( 'cookie_notice_layout', $value ) ) {
			$sanitized['cookie_notice_layout'] = in_array( $value['cookie_notice_layout'], array( 'bar', 'box', 'popup' ), true ) ? $value['cookie_notice_layout'] : 'bar';
		}

		if ( array_key_exists( 'cookie_notice_position', $value ) ) {
			$sanitized['cookie_notice_position'] = in_array( $value['cookie_notice_position'], array( 'bottom-right', 'bottom-left' ), true ) ? $value['cookie_notice_position'] : 'bottom-right';
		}

		if ( array_key_exists( 'cookie_notice_color', $value ) ) {
			$hex_color                        = sanitize_hex_color( $value['cookie_notice_color'] );
			$sanitized['cookie_notice_color'] = $hex_color ? $hex_color : '#687df9';
		}

		if ( array_key_exists( 'cookie_notice_bg_color', $value ) ) {
			$hex_color                           = sanitize_hex_color( $value['cookie_notice_bg_color'] );
			$sanitized['cookie_notice_bg_color'] = $hex_color ? $hex_color : '#ffffff';
		}

		if ( array_key_exists( 'cookie_notice_radius', $value ) ) {
			$sanitized['cookie_notice_radius'] = in_array( $value['cookie_notice_radius'], array( 'none', 'small', 'large' ), true ) ? $value['cookie_notice_radius'] : 'small';
		}

		if ( array_key_exists( 'cookie_notice_expiration_days', $value ) ) {
			$days                                       = absint( $value['cookie_notice_expiration_days'] );
			$sanitized['cookie_notice_expiration_days'] = $days > 0 ? min( $days, 730 ) : 365;
		}

		if ( array_key_exists( 'cookie_notice_tracking_integration_code', $value ) || array_key_exists( 'cookie_notice_tracking_remove', $value ) ) {
			$tracking_integrations = CookieNotice::get_tracking_integrations( $current_options, true );
			$remove_types          = isset( $value['cookie_notice_tracking_remove'] ) && is_array( $value['cookie_notice_tracking_remove'] ) ? array_map( 'sanitize_key', $value['cookie_notice_tracking_remove'] ) : array();
			$tracking_integrations = array_values(
				array_filter(
					$tracking_integrations,
					static function ( $integration ) use ( $remove_types ) {
						return ! in_array( $integration['type'], $remove_types, true );
					}
				)
			);

			$raw_code = (string) ( $value['cookie_notice_tracking_integration_code'] ?? '' );
			$detected = CookieNotice::detect_tracking_snippet( $raw_code );

			if ( null === $detected && '' !== trim( $raw_code ) ) {
				add_settings_error(
					self::OPTION_NAME,
					'frcn_cookie_notice_tracking_unrecognized',
					sprintf(
						/* translators: %s: contact page URL. */
						esc_html__( 'The tracking code was not recognized and was not saved. Need support for this tool? Contact us at %s.', 'frontconsent' ),
						'close.technology/contacto'
					),
					'error'
				);
			}

			if ( $detected ) {
				$tracking_integrations   = array_values(
					array_filter(
						$tracking_integrations,
						static function ( $integration ) use ( $detected ) {
							return $integration['type'] !== $detected['type'];
						}
					)
				);
				$tracking_integrations[] = array(
					'type' => $detected['type'],
					'id'   => sanitize_text_field( $detected['id'] ),
				);
			}

			// Defensive re-validation for the native gtm/ga4 types: guards against a
			// malformed record ever reaching the stored array outside the normal
			// detect_tracking_snippet() path (e.g. a hand-edited option value).
			$tracking_integrations = array_values(
				array_filter(
					array_map(
						function ( $integration ) {
							if ( 'gtm' === $integration['type'] ) {
								$integration['id'] = preg_match( '/^GTM-[A-Z0-9]+$/', $integration['id'] ) ? $integration['id'] : '';
							} elseif ( 'ga4' === $integration['type'] ) {
								$integration['id'] = preg_match( '/^G-[A-Z0-9]+$/', $integration['id'] ) ? $integration['id'] : '';
							}
							return $integration;
						},
						$tracking_integrations
					),
					static function ( $integration ) {
						return '' !== $integration['id'];
					}
				)
			);

			$sanitized['cookie_notice_tracking_integrations'] = $tracking_integrations;
			unset( $sanitized['cookie_notice_tracking_type'], $sanitized['cookie_notice_tracking_id'] );
		}

		return $sanitized;
	}
}
