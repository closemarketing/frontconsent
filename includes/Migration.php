<?php
/**
 * One-time migration from FrontBlocks' bundled Cookie Notice module.
 *
 * @package    FrontConsent
 * @author     Closemarketing
 * @copyright  2026 Closemarketing
 * @version    1.0
 */

namespace FrontConsent;

defined( 'ABSPATH' ) || exit;

/**
 * Migration class.
 *
 * FrontConsent replaces the Cookie Notice module that used to live inside
 * FrontBlocks. Sites upgrading from that module keep their existing settings
 * and stats — this class copies them from FrontBlocks' `frontblocks_settings`
 * option (and its two stat options) into FrontConsent's own `frontconsent_settings`
 * option the first time FrontConsent runs, then tells FrontBlocks its Cookie
 * Notice module is now redundant so it stops rendering its own banner.
 *
 * @since 1.0.0
 */
class Migration {

	/**
	 * Option name flagging that the one-time migration already ran.
	 *
	 * @var string
	 */
	const DONE_FLAG = 'frcn_migrated_from_frontblocks';

	/**
	 * Keys copied verbatim from FrontBlocks' `frontblocks_settings` option
	 * into FrontConsent's own settings, when present.
	 *
	 * @var string[]
	 */
	const MIGRATED_KEYS = array(
		'enable_cookie_notice',
		'cookie_notice_message',
		'cookie_notice_accept_label',
		'cookie_notice_reject_label',
		'cookie_notice_policy_page_id',
		'cookie_notice_layout',
		'cookie_notice_position',
		'cookie_notice_color',
		'cookie_notice_bg_color',
		'cookie_notice_radius',
		'cookie_notice_expiration_days',
		'cookie_notice_tracking_integrations',
		'cookie_notice_tracking_type',
		'cookie_notice_tracking_id',
		'cookie_notice_gtm_id',
		'cookie_notice_ga4_id',
	);

	/**
	 * Run the one-time migration if it hasn't run yet on this site.
	 *
	 * Safe to call on every 'plugins_loaded': guarded by DONE_FLAG so the
	 * actual copy only ever happens once, and does nothing at all when
	 * FrontBlocks was never installed on this site.
	 *
	 * The guard itself is claimed atomically via add_option(), which fails
	 * (returns false) if another request already inserted the row first —
	 * a plain get_option()/update_option() check-then-act pair would let two
	 * concurrent first requests both see the flag missing and both run the
	 * migration, double-counting migrate_stats()'s additive counters.
	 *
	 * @return void
	 */
	public static function maybe_run() {
		if ( ! add_option( self::DONE_FLAG, true, '', 'no' ) ) {
			return;
		}

		$legacy_settings = get_option( 'frontblocks_settings', null );

		if ( is_array( $legacy_settings ) ) {
			self::migrate_settings( $legacy_settings );
			self::migrate_stats();
			self::disable_frontblocks_cookie_notice();
		}
	}

	/**
	 * Copy the Cookie Notice-related keys from FrontBlocks' settings option
	 * into FrontConsent's own, without overwriting anything FrontConsent
	 * already has configured (e.g. a site that installed FrontConsent first
	 * and configured it before ever having FrontBlocks' module enabled).
	 *
	 * @param array $legacy_settings FrontBlocks' `frontblocks_settings` option value.
	 * @return void
	 */
	private static function migrate_settings( $legacy_settings ) {
		$previous = get_option( 'frontconsent_settings', array() );
		$previous = is_array( $previous ) ? $previous : array();
		$current  = $previous;

		foreach ( self::MIGRATED_KEYS as $key ) {
			if ( array_key_exists( $key, $legacy_settings ) && ! array_key_exists( $key, $current ) ) {
				$current[ $key ] = $legacy_settings[ $key ];
			}
		}

		// Cookie Notice was enabled inside FrontBlocks: keep it enabled here so
		// the banner never silently disappears from the site during the switch.
		if ( ! empty( $legacy_settings['enable_cookie_notice'] ) ) {
			$current['enable_cookie_notice'] = true;
		}

		self::migrate_legacy_gtm_ga4_keys( $legacy_settings, $current );

		update_option( 'frontconsent_settings', $current );

		// This runs before Frontend\CookieNotice is ever constructed (see
		// Plugin_Main::init()), so its own update_option_frontconsent_settings/
		// add_option_frontconsent_settings hooks aren't registered yet to catch
		// the write above — call the same cache-purge logic directly instead of
		// depending on hook registration order.
		if ( Frontend\CookieNotice::settings_changed( $previous, $current ) ) {
			Frontend\CookieNotice::handle_settings_changed( $previous, $current );
		}
	}

	/**
	 * Convert FrontBlocks' retired dedicated `cookie_notice_gtm_id` /
	 * `cookie_notice_ga4_id` fields into the shared
	 * `cookie_notice_tracking_integrations` list, which is the only place
	 * CookieNotice::get_tracking_integrations() actually reads GTM/GA4 ids
	 * from. Copying those two keys verbatim (as MIGRATED_KEYS does above)
	 * leaves them dead — this is what makes the migrated tracking ID actually
	 * load again.
	 *
	 * @param array $legacy_settings FrontBlocks' `frontblocks_settings` option value.
	 * @param array $current         FrontConsent settings being built, passed by reference.
	 * @return void
	 */
	private static function migrate_legacy_gtm_ga4_keys( $legacy_settings, array &$current ) {
		$legacy = array(
			'cookie_notice_gtm_id' => 'gtm',
			'cookie_notice_ga4_id' => 'ga4',
		);

		$integrations = Frontend\CookieNotice::get_tracking_integrations( $current, true );

		foreach ( $legacy as $option_key => $type ) {
			$legacy_id = trim( (string) ( $legacy_settings[ $option_key ] ?? '' ) );
			if ( '' === $legacy_id ) {
				continue;
			}

			$already_present = false;
			foreach ( $integrations as $integration ) {
				if ( $type === $integration['type'] ) {
					$already_present = true;
					break;
				}
			}

			if ( ! $already_present ) {
				$integrations[] = array(
					'type' => $type,
					'id'   => sanitize_text_field( $legacy_id ),
				);
			}
		}

		if ( $integrations ) {
			$current['cookie_notice_tracking_integrations'] = array_values( $integrations );
		}

		unset( $current['cookie_notice_gtm_id'], $current['cookie_notice_ga4_id'] );
	}

	/**
	 * Copy the aggregate accepted/rejected counters, added instead of replacing
	 * anything FrontConsent may have already counted on its own.
	 *
	 * @return void
	 */
	private static function migrate_stats() {
		$legacy_accepted = (int) get_option( 'frontblocks_cookie_notice_accepted_count', 0 );
		$legacy_rejected = (int) get_option( 'frontblocks_cookie_notice_rejected_count', 0 );

		if ( $legacy_accepted > 0 ) {
			self::add_to_stat_atomically( Frontend\CookieNotice::STATS_OPTION_ACCEPTED, $legacy_accepted );
		}

		if ( $legacy_rejected > 0 ) {
			self::add_to_stat_atomically( Frontend\CookieNotice::STATS_OPTION_REJECTED, $legacy_rejected );
		}
	}

	/**
	 * Add an amount to an integer stat option directly in the database.
	 *
	 * A plain get_option()/update_option() round trip (the guard added in
	 * maybe_run() only serializes which request runs the migration, not
	 * concurrent writes to this same counter from a visitor's own decision
	 * being logged at the same moment via CookieNotice::log_consent_callback())
	 * would let this migration's addition silently overwrite a decision
	 * logged in between the read and the write. A single
	 * UPDATE ... SET value = value + N lets the database serialize the two
	 * instead — the same technique CookieNotice::increment_option_atomically()
	 * already uses for a plain +1.
	 *
	 * @param string $option_name Option name storing a plain integer.
	 * @param int    $amount      Amount to add.
	 * @return void
	 */
	private static function add_to_stat_atomically( $option_name, $amount ) {
		global $wpdb;

		$sql = $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = option_value + %d WHERE option_name = %s", $amount, $option_name );

		$updated = $wpdb->query( $sql );

		if ( ! $updated ) {
			// Counter doesn't exist yet. add_option() returns false if another
			// request created the row first — in that case fall back to the
			// atomic UPDATE so this addition isn't silently dropped.
			if ( ! add_option( $option_name, $amount, '', 'no' ) ) {
				$wpdb->query( $sql );
			}
		}

		wp_cache_delete( $option_name, 'options' );
	}

	/**
	 * Turn off FrontBlocks' own Cookie Notice module so the two plugins don't
	 * both render a banner. FrontBlocks' module reads its `enable_cookie_notice`
	 * flag from its own `frontblocks_settings` option on every request, so
	 * flipping it here is enough — no coordination beyond this option is needed.
	 *
	 * @return void
	 */
	private static function disable_frontblocks_cookie_notice() {
		$legacy_settings = get_option( 'frontblocks_settings', array() );

		if ( ! is_array( $legacy_settings ) || empty( $legacy_settings['enable_cookie_notice'] ) ) {
			return;
		}

		$legacy_settings['enable_cookie_notice'] = false;
		update_option( 'frontblocks_settings', $legacy_settings );
	}
}
