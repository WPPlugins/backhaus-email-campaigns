<?php
/**
 * Plugin activation: create database tables and set default options.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Activator {

	/**
	 * Run on plugin activation.
	 */
	public static function activate() {
		self::create_tables();
		self::set_defaults();

		// Cron events are scheduled by BHEC_Loader on init.

		// Have WordPress rebuild its rewrite rules (for the My Account endpoint) on
		// the next request, after init. Flushing here also runs during upgrades on
		// plugins_loaded, which is too early: WooCommerce's translated permalink
		// slugs aren't ready then.
		delete_option( 'rewrite_rules' );
	}

	/**
	 * Create all custom tables using dbDelta.
	 */
	private static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// 1. Campaigns.
		dbDelta( "CREATE TABLE {$prefix}bhec_campaigns (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			title varchar(255) NOT NULL,
			subject varchar(255) NOT NULL DEFAULT '',
			subject_b varchar(255) NOT NULL DEFAULT '',
			ab_test longtext,
			body longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			filters longtext,
			exclude_segment_id bigint(20) unsigned DEFAULT NULL,
			email_to varchar(255) DEFAULT '{{ customer.email }}',
			email_cc varchar(255) DEFAULT '',
			email_bcc varchar(255) DEFAULT '',
			reply_to_name varchar(255) DEFAULT '',
			reply_to_email varchar(255) DEFAULT '',
			template_id bigint(20) unsigned DEFAULT NULL,
			layout_template varchar(50) DEFAULT 'default',
			attachments longtext,
			coupon_settings longtext,
			recipient_count int(10) unsigned DEFAULT 0,
			sent_count int(10) unsigned DEFAULT 0,
			failed_count int(10) unsigned DEFAULT 0,
			opened_count int(10) unsigned DEFAULT 0,
			clicked_count int(10) unsigned DEFAULT 0,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			scheduled_at datetime DEFAULT NULL,
			started_at datetime DEFAULT NULL,
			completed_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_status (status),
			KEY idx_created_at (created_at)
		) $charset_collate;" );

		// 2. Queue.
		dbDelta( "CREATE TABLE {$prefix}bhec_queue (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			campaign_id bigint(20) unsigned NOT NULL,
			customer_email varchar(255) NOT NULL,
			customer_name varchar(255) DEFAULT '',
			customer_id bigint(20) unsigned DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			attempts tinyint(3) unsigned DEFAULT 0,
			last_error text,
			claim_token varchar(32) DEFAULT NULL,
			claimed_at datetime DEFAULT NULL,
			next_attempt_at datetime DEFAULT NULL,
			deferrals tinyint(3) unsigned NOT NULL DEFAULT 0,
			held_reason varchar(40) DEFAULT NULL,
			guard_override tinyint(1) unsigned NOT NULL DEFAULT 0,
			variant varchar(1) NOT NULL DEFAULT '',
			sent_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY unique_campaign_email (campaign_id, customer_email),
			KEY idx_campaign_status (campaign_id, status),
			KEY idx_email_campaign (customer_email, campaign_id),
			KEY idx_claim_token (claim_token),
			KEY idx_status_sent (status, sent_at)
		) $charset_collate;" );

		// 3. Subscriptions.
		dbDelta( "CREATE TABLE {$prefix}bhec_subscriptions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(255) NOT NULL,
			customer_id bigint(20) unsigned DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'subscribed',
			source varchar(50) DEFAULT 'auto',
			token varchar(64) NOT NULL,
			opted_in_at datetime DEFAULT NULL,
			opted_out_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY unique_email (email),
			KEY idx_status (status),
			KEY idx_token (token)
		) $charset_collate;" );

		// 4. Tracking events. campaign_id is signed: automatic emails use minus
		// their rule id (BHEC_Tracker).
		dbDelta( "CREATE TABLE {$prefix}bhec_tracking_events (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			campaign_id bigint(20) NOT NULL,
			queue_id bigint(20) unsigned NOT NULL,
			email varchar(255) NOT NULL,
			event_type varchar(20) NOT NULL,
			url text,
			ip_address varchar(45) DEFAULT NULL,
			user_agent text,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_campaign_event (campaign_id, event_type),
			KEY idx_queue_id (queue_id),
			KEY idx_created_at (created_at)
		) $charset_collate;" );

		// 5. Tracking links.
		dbDelta( "CREATE TABLE {$prefix}bhec_tracking_links (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			campaign_id bigint(20) NOT NULL,
			original_url text NOT NULL,
			hash varchar(64) NOT NULL,
			click_count int(10) unsigned DEFAULT 0,
			PRIMARY KEY  (id),
			KEY idx_hash (hash),
			KEY idx_campaign (campaign_id)
		) $charset_collate;" );

		// 6. Segments.
		dbDelta( "CREATE TABLE {$prefix}bhec_segments (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			filters longtext NOT NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id)
		) $charset_collate;" );

		// 7. Templates.
		dbDelta( "CREATE TABLE {$prefix}bhec_templates (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			body longtext NOT NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id)
		) $charset_collate;" );

		// 8. Email logs.
		dbDelta( "CREATE TABLE {$prefix}bhec_logs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			campaign_id bigint(20) unsigned DEFAULT NULL,
			queue_id bigint(20) unsigned DEFAULT NULL,
			rule_id bigint(20) unsigned DEFAULT NULL,
			recipient_email varchar(255) NOT NULL,
			recipient_name varchar(255) DEFAULT '',
			subject varchar(255) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'sent',
			error_message text,
			email_body longtext,
			opened_at datetime DEFAULT NULL,
			clicked_at datetime DEFAULT NULL,
			sent_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_campaign (campaign_id),
			KEY idx_rule (rule_id),
			KEY idx_email (recipient_email),
			KEY idx_sent_at (sent_at),
			KEY idx_status (status)
		) $charset_collate;" );

		// 9. Triggered email rules.
		dbDelta( "CREATE TABLE {$prefix}bhec_triggered_rules (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			trigger_type varchar(30) NOT NULL DEFAULT 'remarketing',
			delay_value int(10) unsigned DEFAULT 7,
			delay_unit varchar(10) DEFAULT 'days',
			subject varchar(255) NOT NULL DEFAULT '',
			body longtext NOT NULL,
			layout_template varchar(50) DEFAULT 'default',
			coupon_settings longtext,
			ignore_optout tinyint(1) DEFAULT 0,
			parent_rule_id bigint(20) unsigned NOT NULL DEFAULT 0,
			send_condition varchar(20) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'active',
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_trigger_type (trigger_type),
			KEY idx_parent (parent_rule_id),
			KEY idx_status (status)
		) $charset_collate;" );

		// 10. Triggered email queue. parent_id is the email a follow-up
		// follows; product_id the product a stock or arrival alert is about.
		dbDelta( "CREATE TABLE {$prefix}bhec_triggered_queue (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			rule_id bigint(20) unsigned NOT NULL,
			order_id bigint(20) unsigned DEFAULT NULL,
			customer_email varchar(255) NOT NULL,
			customer_id bigint(20) unsigned DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			send_at datetime NOT NULL,
			claimed_at datetime DEFAULT NULL,
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			deferrals tinyint(3) unsigned NOT NULL DEFAULT 0,
			last_error text,
			held_reason varchar(40) DEFAULT NULL,
			guard_override tinyint(1) unsigned NOT NULL DEFAULT 0,
			parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			sent_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_rule_status (rule_id, status),
			KEY idx_parent (parent_id),
			KEY idx_send_at (send_at),
			KEY idx_customer (customer_email, rule_id),
			KEY idx_status_sent (status, sent_at)
		) $charset_collate;" );

		// 11. Cart captures (guest email capture).
		dbDelta( "CREATE TABLE {$prefix}bhec_cart_captures (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(255) NOT NULL,
			session_id varchar(255) DEFAULT '',
			cart_hash varchar(255) DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_email_status (email, status),
			KEY idx_status_updated (status, updated_at)
		) $charset_collate;" );

		// 12. Back-in-stock requests from product pages (Pro).
		dbDelta( "CREATE TABLE {$prefix}bhec_stock_alerts (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL,
			email varchar(255) NOT NULL,
			customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'waiting',
			created_at datetime NOT NULL,
			notified_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_product_status (product_id, status),
			KEY idx_email (email)
		) $charset_collate;" );

		// bhec_db_version is left to BHEC_Migrator, which runs the version
		// migrations and then records it. Recording it here would skip them
		// whenever a newer copy is activated (the 1.2 rename, Free to Pro).
	}

	/**
	 * Set default option values.
	 */
	private static function set_defaults() {
		$defaults = array(
			'bhec_optin_mode'              => 'auto',
			'bhec_checkout_default_checked' => 'yes',
			'bhec_cart_capture_enabled'    => 'no',
			'bhec_trash_abandoned_days'   => 2,
			'bhec_captcha_provider'        => 'none',
			'bhec_captcha_site_key'        => '',
			'bhec_captcha_secret_key'      => '',
			'bhec_batch_size'              => 25,
			'bhec_batch_delay'             => 0,
			'bhec_max_retries'             => 3,
			'bhec_max_emails_per_minute'   => 0,
			'bhec_max_emails_per_hour'     => 0,
			'bhec_max_emails_per_day'      => 0,
			'bhec_guard_campaign_days'     => 3,
			'bhec_guard_daily_limit'       => 3,
			'bhec_guard_rule_hours'        => 24,
			'bhec_guard_breaker_holds'     => 20,
			'bhec_from_name'               => '',
			'bhec_from_email'              => '',
			'bhec_tracking_enabled'        => 'yes',
			'bhec_conversion_window_days'  => 7,
			'bhec_coupon_auto_delete_days' => 30,
			'bhec_logging_mode'            => 'meta',
			'bhec_log_retention_days'      => 90,
			'bhec_utm_enabled'             => 'no',
			'bhec_utm_source'              => 'wec',
			'bhec_utm_medium'              => 'email',
		);

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( $key ) ) {
				update_option( $key, $value );
			}
		}
	}
}
