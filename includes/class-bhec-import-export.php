<?php
/**
 * Import / Export functionality for plugin settings, segments, templates, and subscriptions.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Import_Export {

	/**
	 * Option keys that are exported and may be imported.
	 *
	 * @var string[]
	 */
	private static $option_keys = array(
		'bhec_optin_mode',
		'bhec_checkout_default_checked',
		'bhec_double_optin',
		'bhec_cart_capture_enabled',
		'bhec_captcha_provider',
		'bhec_captcha_site_key',
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
	);

	/**
	 * Secrets that never go into a file and are never taken from one, even
	 * if a later change lists them above (HANDOFF M8).
	 *
	 * @var string[]
	 */
	private static $secret_keys = array(
		'bhec_captcha_secret_key',
		'bhec_license_key',
	);

	/**
	 * Option keys that move between stores.
	 *
	 * @return string[]
	 */
	private static function movable_keys() {
		return array_values( array_diff( self::$option_keys, self::$secret_keys ) );
	}

	/**
	 * Build the export data array.
	 *
	 * @param bool $include_subscriptions Whether to include subscription records.
	 * @return array Associative array ready for json_encode.
	 */
	public static function export( $include_subscriptions = false ) {
		global $wpdb;

		// Settings.
		$settings = array();
		foreach ( self::movable_keys() as $key ) {
			$value = get_option( $key );
			if ( false !== $value ) {
				$settings[ $key ] = $value;
			}
		}

		// Segments.
		$segments_table = $wpdb->prefix . 'bhec_segments';
		$segments       = $wpdb->get_results( "SELECT * FROM $segments_table", ARRAY_A );
		if ( ! is_array( $segments ) ) {
			$segments = array();
		}

		// Templates.
		$templates_table = $wpdb->prefix . 'bhec_templates';
		$templates       = $wpdb->get_results( "SELECT * FROM $templates_table", ARRAY_A );
		if ( ! is_array( $templates ) ) {
			$templates = array();
		}

		$data = array(
			'plugin_version' => BHEC_VERSION,
			'exported_at'    => current_time( 'mysql' ),
			'settings'       => $settings,
			'segments'       => $segments,
			'templates'      => $templates,
		);

		// Subscriptions (optional).
		if ( $include_subscriptions ) {
			$subs_table          = $wpdb->prefix . 'bhec_subscriptions';
			$subscriptions       = $wpdb->get_results( "SELECT * FROM $subs_table", ARRAY_A );
			$data['subscriptions'] = is_array( $subscriptions ) ? $subscriptions : array();
		}

		return $data;
	}

	/**
	 * Import data from a decoded JSON array.
	 *
	 * @param array $data                 The decoded export data.
	 * @param bool  $overwrite            Whether to overwrite existing settings.
	 * @param bool  $import_subscriptions Whether to import subscription records.
	 * @return array Summary counts: settings_count, segments_count, templates_count, subscriptions_count.
	 */
	public static function import( $data, $overwrite = true, $import_subscriptions = false ) {
		global $wpdb;

		$summary = array(
			'settings_count'      => 0,
			'segments_count'      => 0,
			'templates_count'     => 0,
			'subscriptions_count' => 0,
		);

		// Import settings.
		if ( ! empty( $data['settings'] ) && is_array( $data['settings'] ) ) {
			foreach ( $data['settings'] as $key => $value ) {
				// Only known option keys, never secrets.
				if ( ! in_array( $key, self::movable_keys(), true ) ) {
					continue;
				}

				$value = self::sanitize_option( $key, $value );

				if ( $overwrite ) {
					update_option( $key, $value );
					$summary['settings_count']++;
				} else {
					// Only set if the option does not already exist.
					if ( false === get_option( $key ) ) {
						update_option( $key, $value );
						$summary['settings_count']++;
					}
				}
			}
		}

		// Import segments.
		if ( ! empty( $data['segments'] ) && is_array( $data['segments'] ) ) {
			$segments_table = $wpdb->prefix . 'bhec_segments';
			foreach ( $data['segments'] as $segment ) {
				$name    = isset( $segment['name'] ) ? sanitize_text_field( $segment['name'] ) : '';
				$filters = isset( $segment['filters'] ) ? sanitize_text_field( $segment['filters'] ) : '{}';

				if ( empty( $name ) ) {
					continue;
				}

				$inserted = $wpdb->insert(
					$segments_table,
					array(
						'name'       => $name,
						'filters'    => $filters,
						'created_by' => get_current_user_id(),
					),
					array( '%s', '%s', '%d' )
				);

				if ( $inserted ) {
					$summary['segments_count']++;
				}
			}
		}

		// Import templates.
		if ( ! empty( $data['templates'] ) && is_array( $data['templates'] ) ) {
			$templates_table = $wpdb->prefix . 'bhec_templates';
			foreach ( $data['templates'] as $template ) {
				$name = isset( $template['name'] ) ? sanitize_text_field( $template['name'] ) : '';
				$body = isset( $template['body'] ) ? wp_kses_post( $template['body'] ) : '';

				if ( empty( $name ) ) {
					continue;
				}

				$inserted = $wpdb->insert(
					$templates_table,
					array(
						'name' => $name,
						'body' => $body,
					),
					array( '%s', '%s' )
				);

				if ( $inserted ) {
					$summary['templates_count']++;
				}
			}
		}

		// Import subscriptions.
		if ( $import_subscriptions && ! empty( $data['subscriptions'] ) && is_array( $data['subscriptions'] ) ) {
			foreach ( $data['subscriptions'] as $sub ) {
				$email  = isset( $sub['email'] ) ? sanitize_email( $sub['email'] ) : '';
				$status = isset( $sub['status'] ) ? sanitize_text_field( $sub['status'] ) : '';

				if ( ! is_email( $email ) || ! in_array( $status, array( 'subscribed', 'unsubscribed' ), true ) ) {
					continue;
				}

				// Check existing local status — never re-subscribe someone who opted out locally.
				$local = BHEC_Subscription::get_status( $email );

				if ( $local && 'unsubscribed' === $local->status ) {
					// Don't re-subscribe a locally opted-out email.
					continue;
				}

				if ( 'subscribed' === $status ) {
					$customer_id = isset( $sub['customer_id'] ) ? absint( $sub['customer_id'] ) : 0;
					$source      = 'import'; // Never the file's own source: 'myaccount' or 'admin' would skip consent rules.
					BHEC_Subscription::subscribe( $email, $customer_id, $source );
				} else {
					BHEC_Subscription::unsubscribe( $email );
				}

				$summary['subscriptions_count']++;
			}
		}

		return $summary;
	}

	/**
	 * Sanitize an option value based on its key.
	 *
	 * @param string $key   Option key.
	 * @param mixed  $value Raw value.
	 * @return mixed Sanitized value.
	 */
	private static function sanitize_option( $key, $value ) {
		// Integer options.
		$int_keys = array(
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
			'bhec_conversion_window_days',
			'bhec_coupon_auto_delete_days',
			'bhec_log_retention_days',
		);

		if ( in_array( $key, $int_keys, true ) ) {
			return absint( $value );
		}

		// Email option.
		if ( 'bhec_from_email' === $key ) {
			return sanitize_email( $value );
		}

		// Select / enum options.
		$enums = array(
			'bhec_optin_mode'       => array( 'auto', 'explicit' ),
			'bhec_logging_mode'     => array( 'off', 'meta', 'full' ),
			'bhec_captcha_provider' => array( 'none', 'recaptcha_v3', 'turnstile' ),
		);

		if ( isset( $enums[ $key ] ) ) {
			return in_array( $value, $enums[ $key ], true ) ? $value : $enums[ $key ][0];
		}

		// Checkbox options (yes/no).
		$checkbox_keys = array(
			'bhec_checkout_default_checked',
			'bhec_double_optin',
			'bhec_cart_capture_enabled',
			'bhec_tracking_enabled',
			'bhec_utm_enabled',
		);

		if ( in_array( $key, $checkbox_keys, true ) ) {
			return in_array( $value, array( 'yes', 'no' ), true ) ? $value : 'yes';
		}

		// Default: sanitize as text.
		return sanitize_text_field( $value );
	}
}
