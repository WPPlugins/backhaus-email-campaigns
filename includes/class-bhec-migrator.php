<?php
/**
 * Database migration system.
 *
 * Brings the schema up to date when the plugin version changes, and recreates
 * missing tables on admin and cron requests. Uses dbDelta, which is safe to repeat.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Migrator {

	/**
	 * Map of version => migration method name.
	 *
	 * @var array
	 */
	private static $migrations = array(
		'1.1.0' => 'migrate_1_1_0',
		'1.1.1' => 'migrate_1_1_1',
		'1.1.2' => 'migrate_1_1_2',
		'1.2.0' => 'migrate_1_2_0',
		'1.4.0' => 'migrate_1_4_0',
	);

	/**
	 * Expected tables (without prefix).
	 *
	 * @var array
	 */
	private static $expected_tables = array(
		'bhec_campaigns',
		'bhec_queue',
		'bhec_subscriptions',
		'bhec_tracking_events',
		'bhec_tracking_links',
		'bhec_segments',
		'bhec_templates',
		'bhec_logs',
		'bhec_triggered_rules',
		'bhec_triggered_queue',
		'bhec_cart_captures',
		'bhec_stock_alerts',
	);

	/**
	 * Check current DB version and run any pending migrations.
	 * Also verifies all expected tables exist.
	 */
	public static function check_and_migrate() {
		if ( ! self::needs_upgrade( get_option( 'bhec_db_version', '0.0.0' ) ) ) {
			return;
		}

		// Requests that load a new version at the same moment would each add the
		// same columns (HANDOFF L12). One upgrades; the others wait for it, then
		// find the work done.
		if ( ! BHEC_Send_Control::lock( 'migrate', 15 ) ) {
			return;
		}
		try {
			$current = self::stored_version();
			if ( ! self::needs_upgrade( $current ) ) {
				return;
			}

			require_once BHEC_PLUGIN_DIR . 'includes/class-bhec-activator.php';
			BHEC_Activator::activate();

			// Run version-specific migrations.
			foreach ( self::$migrations as $version => $method ) {
				if ( version_compare( $current, $version, '<' ) ) {
					call_user_func( array( __CLASS__, $method ) );
				}
			}

			update_option( 'bhec_db_version', BHEC_VERSION );
		} finally {
			BHEC_Send_Control::unlock( 'migrate' );
		}
	}

	/**
	 * Whether the schema needs work: an older version, or (on admin and cron
	 * requests) a missing table. The table check costs 11 queries, so it only
	 * runs where the tables are about to be needed, not on storefront pages.
	 *
	 * @param string $version Schema version on record.
	 * @return bool
	 */
	private static function needs_upgrade( $version ) {
		if ( version_compare( $version, BHEC_VERSION, '<' ) ) {
			return true;
		}
		return ( is_admin() || wp_doing_cron() ) && self::has_missing_tables();
	}

	/**
	 * The schema version straight from the database, past any object cache,
	 * so a request that waited for another's upgrade sees its result.
	 *
	 * @return string
	 */
	private static function stored_version() {
		global $wpdb;
		$version = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'bhec_db_version' ) );
		return $version ? (string) $version : '0.0.0';
	}

	/**
	 * Check if any expected tables are missing.
	 *
	 * @return bool True if tables are missing.
	 */
	private static function has_missing_tables() {
		global $wpdb;

		foreach ( self::$expected_tables as $table ) {
			$full_name = $wpdb->prefix . $table;
			$exists    = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $full_name )
			);
			if ( ! $exists ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Migration 1.1.0: Add columns that dbDelta may not handle on existing tables.
	 */
	private static function migrate_1_1_0() {
		global $wpdb;

		// Add coupon_settings to triggered_rules if missing.
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}bhec_triggered_rules", 0 );
		if ( is_array( $cols ) && ! in_array( 'coupon_settings', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$wpdb->prefix}bhec_triggered_rules ADD COLUMN coupon_settings longtext AFTER layout_template" );
		}

		// Add layout_template to campaigns if missing.
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}bhec_campaigns", 0 );
		if ( is_array( $cols ) && ! in_array( 'layout_template', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$wpdb->prefix}bhec_campaigns ADD COLUMN layout_template varchar(50) DEFAULT 'default' AFTER template_id" );
		}
		if ( is_array( $cols ) && ! in_array( 'coupon_settings', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$wpdb->prefix}bhec_campaigns ADD COLUMN coupon_settings longtext AFTER attachments" );
		}
	}

	/**
	 * Migration 1.1.1: claim columns for the campaign and triggered queues, in
	 * case dbDelta skipped them.
	 */
	private static function migrate_1_1_1() {
		global $wpdb;

		$queue = $wpdb->prefix . 'bhec_queue';
		$cols  = $wpdb->get_col( "SHOW COLUMNS FROM $queue", 0 );
		if ( is_array( $cols ) && ! in_array( 'claim_token', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE $queue ADD COLUMN claim_token varchar(32) DEFAULT NULL AFTER last_error, ADD KEY idx_claim_token (claim_token)" );
		}
		if ( is_array( $cols ) && ! in_array( 'claimed_at', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE $queue ADD COLUMN claimed_at datetime DEFAULT NULL AFTER claim_token" );
		}

		$triggered = $wpdb->prefix . 'bhec_triggered_queue';
		$cols      = $wpdb->get_col( "SHOW COLUMNS FROM $triggered", 0 );
		if ( is_array( $cols ) && ! in_array( 'claimed_at', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE $triggered ADD COLUMN claimed_at datetime DEFAULT NULL AFTER send_at" );
		}
	}

	/**
	 * Migration 1.1.2: retry timing and over-mailing guard columns, in case
	 * dbDelta skipped them.
	 */
	private static function migrate_1_1_2() {
		global $wpdb;

		$add = array(
			'bhec_queue'           => array(
				'next_attempt_at' => 'datetime DEFAULT NULL',
				'deferrals'       => 'tinyint(3) unsigned NOT NULL DEFAULT 0',
				'held_reason'     => 'varchar(40) DEFAULT NULL',
				'guard_override'  => 'tinyint(1) unsigned NOT NULL DEFAULT 0',
			),
			'bhec_triggered_queue' => array(
				'attempts'       => 'tinyint(3) unsigned NOT NULL DEFAULT 0',
				'deferrals'      => 'tinyint(3) unsigned NOT NULL DEFAULT 0',
				'last_error'     => 'text',
				'held_reason'    => 'varchar(40) DEFAULT NULL',
				'guard_override' => 'tinyint(1) unsigned NOT NULL DEFAULT 0',
			),
		);
		foreach ( $add as $table => $columns ) {
			$table    = $wpdb->prefix . $table;
			$existing = $wpdb->get_col( "SHOW COLUMNS FROM $table", 0 );
			if ( ! is_array( $existing ) ) {
				continue;
			}
			foreach ( $columns as $name => $definition ) {
				if ( ! in_array( $name, $existing, true ) ) {
					$wpdb->query( "ALTER TABLE $table ADD COLUMN $name $definition" );
				}
			}
			// The sending caps count sent emails by time every minute.
			if ( ! $wpdb->get_var( "SHOW INDEX FROM $table WHERE Key_name = 'idx_status_sent'" ) ) {
				$wpdb->query( "ALTER TABLE $table ADD KEY idx_status_sent (status, sent_at)" );
			}
		}
	}

	/**
	 * Migration 1.2.0: automatic emails are tracked under minus their rule id,
	 * which unsigned columns turned into 0 or refused (HANDOFF L1).
	 */
	private static function migrate_1_2_0() {
		global $wpdb;

		foreach ( array( 'bhec_tracking_events', 'bhec_tracking_links' ) as $name ) {
			$table = $wpdb->prefix . $name;
			$type  = $wpdb->get_var( "SHOW COLUMNS FROM $table LIKE 'campaign_id'", 1 );
			if ( $type && false !== stripos( $type, 'unsigned' ) ) {
				$wpdb->query( "ALTER TABLE $table MODIFY campaign_id bigint(20) NOT NULL" );
			}
		}

		self::repair_automatic_links();
	}

	/**
	 * Migration 1.4.0: drop the bhec_automations table from before 1.1, which
	 * nothing uses (HANDOFF L10), as long as it holds nothing. The log's new
	 * rule_id column comes from the activator.
	 */
	private static function migrate_1_4_0() {
		global $wpdb;
		$table = $wpdb->prefix . 'bhec_automations';
		if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) )
			&& 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$wpdb->query( "DROP TABLE $table" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
	}

	/**
	 * Where the unsigned column stored automatic emails' links as campaign 0,
	 * give each back its rule: a link's id is made from its campaign id and
	 * URL, so the rule whose id reproduces it is the one it belongs to. Links
	 * no rule matches stay as they are.
	 */
	public static function repair_automatic_links() {
		global $wpdb;
		$links = $wpdb->get_results( "SELECT id, original_url, hash FROM {$wpdb->prefix}bhec_tracking_links WHERE campaign_id = 0" );
		if ( ! $links || ! class_exists( 'BHEC_Tracker' ) ) {
			return;
		}
		$rules = $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}bhec_triggered_rules" );
		foreach ( $links as $link ) {
			foreach ( $rules as $rule_id ) {
				if ( hash_equals( BHEC_Tracker::link_hash( -(int) $rule_id, $link->original_url ), $link->hash ) ) {
					$wpdb->update( $wpdb->prefix . 'bhec_tracking_links', array( 'campaign_id' => -(int) $rule_id ), array( 'id' => $link->id ), array( '%d' ), array( '%d' ) );
					break;
				}
			}
		}
	}
}
