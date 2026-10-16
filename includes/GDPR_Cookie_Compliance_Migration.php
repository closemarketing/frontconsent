<?php
/**
 * Settings importer for the GDPR Cookie Compliance plugin.
 *
 * @package    FrontConsent
 * @author     Closemarketing
 * @copyright  2026 Closemarketing
 * @version    1.0
 */

namespace FrontConsent;

defined( 'ABSPATH' ) || exit;

/**
 * GDPR_Cookie_Compliance_Migration class.
 *
 * Imports the settings of "GDPR Cookie Compliance" (Moove Agency, slug
 * `gdpr-cookie-compliance`) into FrontConsent's own `frontconsent_settings`
 * option, with the Integrations tab as the priority.
 *
 * Unlike Migration (FrontBlocks, automatic on first run) this importer only
 * runs on an explicit admin action, never overwrites anything FrontConsent
 * already has configured, and never writes to the source plugin's data.
 *
 * @since 1.1.0
 */
class GDPR_Cookie_Compliance_Migration {

	/**
	 * Option name used by GDPR Cookie Compliance for all of its settings.
	 *
	 * @var string
	 */
	const SOURCE_OPTION = 'moove_gdpr_plugin_settings';

	/**
	 * Option name flagging that the import was already run on this site.
	 *
	 * @var string
	 */
	const DONE_FLAG = 'frcn_imported_gdpr_cookie_compliance';

	/**
	 * Report statuses.
	 */
	const STATUS_MIGRATED    = 'migrated';
	const STATUS_EXISTING    = 'skipped_existing';
	const STATUS_UNSUPPORTED = 'unsupported';
	const STATUS_REVIEW      = 'needs_review';

	/**
	 * Source integration slugs mapped to a FrontConsent integration type.
	 * `gtmc2` is GTM running with Consent Mode v2, which FrontConsent already
	 * handles natively, so it maps to `gtm` but is flagged for manual review.
	 *
	 * @var string[]
	 */
	const INTEGRATION_MAP = array(
		'ga4'   => 'ga4',
		'gtm'   => 'gtm',
		'gtmc2' => 'gtm',
	);

	/**
	 * Source integrations FrontConsent has no equivalent for, with a label.
	 *
	 * @return string[]
	 */
	public static function get_unsupported_integrations() {
		return array(
			'gadc'      => __( 'Google Ads', 'frontconsent' ),
			'fbp'       => __( 'Meta Pixel', 'frontconsent' ),
			'muet'      => __( 'Microsoft Advertising (UET)', 'frontconsent' ),
			'clarity'   => __( 'Microsoft Clarity', 'frontconsent' ),
			'gtm4wp'    => __( 'GTM4WP (Google Tag Manager for WordPress)', 'frontconsent' ),
			'recaptcha' => __( 'Google reCAPTCHA blocker', 'frontconsent' ),
		);
	}

	/**
	 * Whether the source plugin's settings exist on this site.
	 *
	 * @return bool
	 */
	public static function has_source_settings() {
		return is_array( get_option( self::SOURCE_OPTION, null ) );
	}

	/**
	 * Run the import against the live options and save the result.
	 *
	 * @return array|null Report, or null when there is nothing to import.
	 */
	public static function run() {
		$source = get_option( self::SOURCE_OPTION, null );
		if ( ! is_array( $source ) ) {
			return null;
		}

		$current = get_option( 'frontconsent_settings', array() );
		$current = is_array( $current ) ? $current : array();

		$result = self::build( $source, $current );

		update_option( 'frontconsent_settings', $result['settings'] );

		// Leave the import available for a retry when the source data could
		// not be read, so the admin can repair it and run it again.
		if ( $result['complete'] ) {
			update_option( self::DONE_FLAG, true, false );
		}

		return $result['report'];
	}

	/**
	 * Build the merged FrontConsent settings from the source option value.
	 * Pure function (no database access) so it can be tested with fixtures.
	 *
	 * @param array $source  Value of the source plugin's option.
	 * @param array $current Current `frontconsent_settings` value.
	 * @return array{settings: array, report: array, complete: bool} Merged settings,
	 *                         a report of `{item, status, note}` rows, and whether
	 *                         the source data was fully readable.
	 */
	public static function build( array $source, array $current ) {
		$report = array();
		$before = $current;

		$complete = self::map_integrations( $source, $current, $report );
		self::map_banner_settings( $source, $current, $report );

		// A fresh FrontConsent install has the banner disabled; enable it when
		// something was actually imported so removing the source plugin never
		// leaves the site without a consent banner. An explicit saved choice
		// is kept.
		if ( $current !== $before && ! array_key_exists( 'enable_cookie_notice', $current ) ) {
			$current['enable_cookie_notice'] = true;
		}

		return array(
			'settings' => $current,
			'report'   => $report,
			'complete' => $complete,
		);
	}

	/**
	 * Map the Integrations tab (`gdin_values`) into
	 * `cookie_notice_tracking_integrations`.
	 *
	 * @param array $source  Source option value.
	 * @param array $current FrontConsent settings, by reference.
	 * @param array $report  Report rows, by reference.
	 * @return bool False when the integrations data could not be decoded.
	 */
	private static function map_integrations( array $source, array &$current, array &$report ) {
		$values   = array();
		$readable = true;
		if ( isset( $source['gdin_values'] ) && is_string( $source['gdin_values'] ) ) {
			$decoded = json_decode( $source['gdin_values'], true );
			$values  = is_array( $decoded ) ? $decoded : array();

			if ( ! is_array( $decoded ) && '' !== trim( $source['gdin_values'] ) ) {
				$readable = false;
				$report[] = array(
					'item'   => __( 'Integrations', 'frontconsent' ),
					'status' => self::STATUS_REVIEW,
					'note'   => __( 'The integrations data of GDPR Cookie Compliance could not be read. Re-save its settings and run the import again.', 'frontconsent' ),
				);
			}
		}

		$integrations = Frontend\CookieNotice::get_tracking_integrations( $current, true );
		$unsupported  = self::get_unsupported_integrations();

		foreach ( $values as $slug => $value ) {
			// Only the plain `{slug}` keys mark an integration as enabled;
			// `{slug}_id` rows hold its tracking ID.
			if ( ! is_string( $slug ) || '_id' === substr( $slug, -3 ) ) {
				continue;
			}

			// A falsy selector means the integration is disabled in the source.
			if ( empty( $value ) ) {
				continue;
			}

			if ( isset( $unsupported[ $slug ] ) ) {
				$report[] = array(
					'item'   => $unsupported[ $slug ],
					'status' => self::STATUS_UNSUPPORTED,
					'note'   => __( 'FrontConsent has no equivalent integration yet and it was not imported. Do not hard-code its script (it would run before consent); load it through Google Tag Manager with consent-aware triggers instead.', 'frontconsent' ),
				);
				continue;
			}

			if ( ! isset( self::INTEGRATION_MAP[ $slug ] ) ) {
				// Never drop an enabled integration silently: it may still be
				// injecting its script once the source plugin is deactivated.
				$report[] = array(
					'item'   => sanitize_key( $slug ),
					'status' => self::STATUS_UNSUPPORTED,
					'note'   => __( 'Unrecognized integration, not imported. Check whether it still loads on your site after deactivating GDPR Cookie Compliance.', 'frontconsent' ),
				);
				continue;
			}

			$id   = strtoupper( trim( sanitize_text_field( (string) ( $values[ $slug . '_id' ] ?? '' ) ) ) );
			$type = self::INTEGRATION_MAP[ $slug ];

			// The source plugin accepts GA4 measurement IDs in its GTM field.
			if ( 'gtm' === $type && 0 === strpos( $id, 'G-' ) ) {
				$type = 'ga4';
			}

			// Google tag IDs added in source plugin 5.2 have no FrontConsent type.
			if ( 1 === preg_match( '/^(GT|AW)-[A-Z0-9]+$/', $id ) ) {
				$report[] = array(
					'item'   => $id,
					'status' => self::STATUS_UNSUPPORTED,
					'note'   => __( 'Google tag (GT-/AW-) IDs are not supported by FrontConsent and were not imported. Load them through Google Tag Manager with consent-aware triggers instead.', 'frontconsent' ),
				);
				continue;
			}

			$label = 'ga4' === $type ? 'GA4' : 'Google Tag Manager';
			$valid = 'ga4' === $type ? 1 === preg_match( '/^G-[A-Z0-9]+$/', $id ) : 1 === preg_match( '/^GTM-[A-Z0-9]+$/', $id );

			if ( ! $valid ) {
				$report[] = array(
					'item'   => $label,
					'status' => self::STATUS_REVIEW,
					'note'   => __( 'The tracking ID is missing or has an unexpected format, so it was not imported.', 'frontconsent' ),
				);
				continue;
			}

			if ( self::has_integration_type( $integrations, $type ) ) {
				$report[] = array(
					'item'   => $label,
					'status' => self::STATUS_EXISTING,
					'note'   => __( 'FrontConsent already has this integration configured.', 'frontconsent' ),
				);
				continue;
			}

			$integrations[] = array(
				'type' => $type,
				'id'   => $id,
			);

			$note = '';
			if ( 'gtmc2' === $slug ) {
				$note = __( 'Imported as Google Tag Manager. Review your Consent Mode v2 setup.', 'frontconsent' );
			}

			$report[] = array(
				'item'   => $label . ' (' . $id . ')',
				'status' => 'gtmc2' === $slug ? self::STATUS_REVIEW : self::STATUS_MIGRATED,
				'note'   => $note,
			);
		}

		if ( $integrations ) {
			$current['cookie_notice_tracking_integrations'] = array_values( $integrations );
		}

		return $readable;
	}

	/**
	 * Map the banner texts, consent expiration and position, filling only
	 * the keys FrontConsent has not configured yet.
	 *
	 * @param array $source  Source option value.
	 * @param array $current FrontConsent settings, by reference.
	 * @param array $report  Report rows, by reference.
	 * @return void
	 */
	private static function map_banner_settings( array $source, array &$current, array &$report ) {
		$candidates = array();

		$text_keys = array(
			'moove_gdpr_info_bar_content'            => 'cookie_notice_message',
			'moove_gdpr_infobar_accept_button_label' => 'cookie_notice_accept_label',
			'moove_gdpr_infobar_reject_button_label' => 'cookie_notice_reject_label',
		);

		foreach ( $text_keys as $source_key => $target_key ) {
			if ( ! isset( $source[ $source_key ] ) || ! is_string( $source[ $source_key ] ) ) {
				continue;
			}

			// Anchors lose their URL when flattened to plain text: keep a
			// local policy page link through the policy-page setting, and
			// ask for a manual review otherwise.
			if ( 'cookie_notice_message' === $target_key && 1 === preg_match( '/<a\s[^>]*href=["\']([^"\']+)["\']/i', $source[ $source_key ], $link ) ) {
				$page_id = (int) url_to_postid( esc_url_raw( $link[1] ) );
				if ( $page_id > 0 ) {
					$candidates['cookie_notice_policy_page_id'] = $page_id;
				} else {
					$report[] = array(
						'item'   => __( 'Banner message link', 'frontconsent' ),
						'status' => self::STATUS_REVIEW,
						'note'   => __( 'The banner message contained a link that is not a page of this site and was removed. Set the policy page in FrontConsent if needed.', 'frontconsent' ),
					);
				}
			}
			$text = trim( sanitize_textarea_field( wp_strip_all_tags( preg_replace( '#</(p|div|li|h[1-6])>|<br\s*/?>#i', "\n", $source[ $source_key ] ) ) ) );
			if ( '' !== $text ) {
				$candidates[ $target_key ] = $text;
			}
		}

		if ( isset( $source['moove_gdpr_consent_expiration'] ) ) {
			$days = absint( $source['moove_gdpr_consent_expiration'] );
			if ( $days > 0 ) {
				$candidates['cookie_notice_expiration_days'] = min( $days, 730 );
			}
		}

		if ( isset( $source['moove_gdpr_infobar_position'] ) ) {
			$position = $source['moove_gdpr_infobar_position'];
			if ( in_array( $position, array( 'left', 'right' ), true ) ) {
				$candidates['cookie_notice_position'] = 'left' === $position ? 'bottom-left' : 'bottom-right';
				// Left/right positioning only applies to the box layout.
				$candidates['cookie_notice_layout'] = 'box';
			} else {
				$report[] = array(
					'item'   => __( 'Banner position', 'frontconsent' ),
					'status' => self::STATUS_REVIEW,
					'note'   => __( 'FrontConsent cannot reproduce this position (only bottom-left and bottom-right boxes). Choose a layout manually.', 'frontconsent' ),
				);
			}
		}

		$migrated = 0;
		$existing = 0;
		foreach ( $candidates as $key => $value ) {
			if ( array_key_exists( $key, $current ) ) {
				++$existing;
				continue;
			}
			$current[ $key ] = $value;
			++$migrated;
		}

		if ( $migrated > 0 ) {
			$report[] = array(
				'item'   => __( 'Banner texts and options', 'frontconsent' ),
				'status' => self::STATUS_MIGRATED,
				/* translators: %d: number of imported settings. */
				'note'   => sprintf( _n( '%d setting imported.', '%d settings imported.', $migrated, 'frontconsent' ), $migrated ),
			);
		}

		if ( $existing > 0 ) {
			$report[] = array(
				'item'   => __( 'Banner texts and options', 'frontconsent' ),
				'status' => self::STATUS_EXISTING,
				/* translators: %d: number of settings left untouched. */
				'note'   => sprintf( _n( '%d setting was already configured in FrontConsent and was kept.', '%d settings were already configured in FrontConsent and were kept.', $existing, 'frontconsent' ), $existing ),
			);
		}
	}

	/**
	 * Whether an integration list already contains a type.
	 *
	 * @param array  $integrations List of `{type, id}` rows.
	 * @param string $type         Integration type.
	 * @return bool
	 */
	private static function has_integration_type( array $integrations, $type ) {
		foreach ( $integrations as $integration ) {
			if ( $type === $integration['type'] ) {
				return true;
			}
		}

		return false;
	}
}
