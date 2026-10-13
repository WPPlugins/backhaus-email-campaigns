<?php
/**
 * Removes what the plugin stored, when the last copy is deleted (uninstall.php).
 * Split into parts so tests can check the ones that don't drop tables.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Uninstaller {

	/** Custom tables, without the prefix. bhec_automations is from before 1.1. */
	const TABLES = array(
		'bhec_campaigns',
		'bhec_queue',
		'bhec_subscriptions',
		'bhec_tracking_events',
		'bhec_tracking_links',
		'bhec_segments',
		'bhec_templates',
		'bhec_logs',
		'bhec_automations',
		'bhec_triggered_rules',
		'bhec_triggered_queue',
		'bhec_cart_captures',
		'bhec_stock_alerts',
	);

	const OPTIONS = array(
		'bhec_optin_mode',
		'bhec_checkout_default_checked',
		'bhec_batch_size',
		'bhec_batch_delay',
		'bhec_max_retries',
		'bhec_max_emails_per_hour',
		'bhec_max_emails_per_day',
		'bhec_max_emails_per_minute',
		'bhec_guard_campaign_days',
		'bhec_guard_daily_limit',
		'bhec_guard_rule_hours',
		'bhec_guard_breaker_holds',
		'bhec_from_name',
		'bhec_from_email',
		'bhec_tracking_enabled',
		'bhec_conversion_window_days',
		'bhec_coupon_auto_delete_days',
		'bhec_logging_mode',
		'bhec_log_retention_days',
		'bhec_utm_enabled',
		'bhec_utm_source',
		'bhec_utm_medium',
		'bhec_double_optin',
		'bhec_cart_capture_enabled',
		'bhec_trash_abandoned_days',
		'bhec_captcha_provider',
		'bhec_captcha_site_key',
		'bhec_captcha_secret_key',
		'bhec_db_version',
		'bhec_rewrite_flushed',
		'bhec_license_key',
		'bhec_license_status',
		'bhec_provider_pause',
		'bhec_provider_pause_step',
		'bhec_automatic_pause',
		'bhec_automatic_resumed_at',
		'bhec_show_credit',
	);

	const TRANSIENTS = array(
		'bhec_license_status_cache',
		'bhec_winback_checked',
		'bhec_import_notice',
		'bhec_free_deactivated_notice',
	);

	/** Per-address rate limits and the Status tab's DNS answers, one transient per hash. */
	const TRANSIENT_FAMILIES = array( 'bhec_cart_ip_', 'bhec_signup_rate_', 'bhec_stock_ip_', 'bhec_dns_' );

	const ORDER_META = array( '_bhec_conversion_revenue', '_bhec_conversion_campaign_id', '_bhec_conversion_tracked' );

	const COUPON_META = array( '_bhec_campaign_id', '_bhec_generated', '_bhec_recipient' );

	/**
	 * Remove everything.
	 */
	public static function run() {
		wp_unschedule_hook( 'bhec_new_arrival' );
		self::tidy_coupons();
		self::delete_order_meta();
		self::delete_options();
		self::drop_tables();
	}

	public static function drop_tables() {
		global $wpdb;
		foreach ( self::TABLES as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
	}

	public static function delete_options() {
		global $wpdb;
		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}
		foreach ( self::TRANSIENTS as $transient ) {
			delete_transient( $transient );
		}
		delete_site_transient( 'bhec_update_info' );

		foreach ( self::TRANSIENT_FAMILIES as $family ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
					$wpdb->esc_like( '_transient_' . $family ) . '%',
					$wpdb->esc_like( '_transient_timeout_' . $family ) . '%'
				)
			);
		}
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * Coupons the plugin made. Expired ones nobody used are deleted. Used ones
	 * stay with the orders they discounted, and ones a customer can still use
	 * stay because they were promised; both lose the plugin's note of who
	 * they were for.
	 */
	public static function tidy_coupons() {
		global $wpdb;
		$ids = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_bhec_generated' AND meta_value = 'yes'" );

		foreach ( array_map( 'intval', $ids ) as $id ) {
			$expires = (int) get_post_meta( $id, 'date_expires', true );
			$used    = (int) get_post_meta( $id, 'usage_count', true );
			if ( $expires && $expires < time() && ! $used ) {
				wp_delete_post( $id, true );
				continue;
			}
			foreach ( self::COUPON_META as $key ) {
				delete_post_meta( $id, $key );
			}
		}
	}

	/**
	 * The conversion notes on orders, in both order storages, and the note on
	 * products that their arrival was announced.
	 */
	public static function delete_order_meta() {
		global $wpdb;
		$in = implode( ', ', array_fill( 0, count( self::ORDER_META ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ($in)", self::ORDER_META ) );
		$hpos_meta = $wpdb->prefix . 'wc_orders_meta';
		if ( $hpos_meta === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $hpos_meta ) ) ) ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM $hpos_meta WHERE meta_key IN ($in)", self::ORDER_META ) );
		}
		delete_post_meta_by_key( '_bhec_arrival_announced' );
		// phpcs:enable
	}
}
