<?php
/**
 * GDPR data export and erasure handlers.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_GDPR {

	/**
	 * Register privacy hooks.
	 */
	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_erasers' ) );
	}

	/**
	 * Register personal data exporters.
	 *
	 * @param array $exporters Existing exporters.
	 * @return array
	 */
	public static function register_exporters( $exporters ) {
		$exporters['bhec-subscription'] = array(
			'exporter_friendly_name' => __( 'Email Campaign Subscription', 'backhaus-email-campaigns' ),
			'callback'               => array( __CLASS__, 'export_subscription_data' ),
		);
		$exporters['bhec-email-logs'] = array(
			'exporter_friendly_name' => __( 'Email Campaign Logs', 'backhaus-email-campaigns' ),
			'callback'               => array( __CLASS__, 'export_email_logs' ),
		);
		$exporters['bhec-campaign-sends'] = array(
			'exporter_friendly_name' => __( 'Campaigns Sent', 'backhaus-email-campaigns' ),
			'callback'               => array( __CLASS__, 'export_campaign_sends' ),
		);
		$exporters['bhec-automatic-sends'] = array(
			'exporter_friendly_name' => __( 'Automatic Emails Sent', 'backhaus-email-campaigns' ),
			'callback'               => array( __CLASS__, 'export_automatic_sends' ),
		);
		$exporters['bhec-activity'] = array(
			'exporter_friendly_name' => __( 'Email Opens, Clicks and Orders', 'backhaus-email-campaigns' ),
			'callback'               => array( __CLASS__, 'export_activity' ),
		);
		$exporters['bhec-carts'] = array(
			'exporter_friendly_name' => __( 'Saved Carts', 'backhaus-email-campaigns' ),
			'callback'               => array( __CLASS__, 'export_saved_carts' ),
		);
		$exporters['bhec-stock-alerts'] = array(
			'exporter_friendly_name' => __( 'Back-in-Stock Requests', 'backhaus-email-campaigns' ),
			'callback'               => array( __CLASS__, 'export_stock_alerts' ),
		);
		return $exporters;
	}

	/** Rows per export page. */
	const PER_PAGE = 100;

	/**
	 * One export page from a query of rows for this address.
	 *
	 * @param string   $sql   SELECT with %s for the email; ordered, without LIMIT.
	 * @param string   $email Email address.
	 * @param int      $page  Page number.
	 * @param callable $item  Row => export item.
	 * @return array
	 */
	private static function export_page( $sql, $email, $page, callable $item ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( $sql . ' LIMIT %d OFFSET %d', $email, self::PER_PAGE, ( max( 1, (int) $page ) - 1 ) * self::PER_PAGE ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array(
			'data' => array_map( $item, $rows ),
			'done' => count( $rows ) < self::PER_PAGE,
		);
	}

	/**
	 * A campaign's or automatic email's name (automatic emails are tracked under minus their rule id).
	 *
	 * @param int $campaign_id Campaign id, or minus a rule id.
	 * @return string
	 */
	private static function email_name( $campaign_id ) {
		global $wpdb;
		static $names = array();
		$campaign_id = (int) $campaign_id;
		if ( ! isset( $names[ $campaign_id ] ) ) {
			$names[ $campaign_id ] = (string) ( $campaign_id < 0
				? $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}bhec_triggered_rules WHERE id = %d", -$campaign_id ) )
				: $wpdb->get_var( $wpdb->prepare( "SELECT title FROM {$wpdb->prefix}bhec_campaigns WHERE id = %d", $campaign_id ) ) );
		}
		return $names[ $campaign_id ];
	}

	/**
	 * Export the campaigns sent (or queued) to an address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page number.
	 * @return array
	 */
	public static function export_campaign_sends( $email, $page = 1 ) {
		global $wpdb;
		return self::export_page(
			"SELECT q.id, q.campaign_id, q.customer_name, q.status, q.sent_at FROM {$wpdb->prefix}bhec_queue q WHERE q.customer_email = %s ORDER BY q.id",
			$email,
			$page,
			static function ( $row ) {
				return array(
					'group_id'    => 'bhec_campaign_sends',
					'group_label' => __( 'Campaigns Sent', 'backhaus-email-campaigns' ),
					'item_id'     => 'campaign-send-' . $row->id,
					'data'        => array(
						array( 'name' => __( 'Campaign', 'backhaus-email-campaigns' ), 'value' => self::email_name( $row->campaign_id ) ),
						array( 'name' => __( 'Name', 'backhaus-email-campaigns' ), 'value' => (string) $row->customer_name ),
						array( 'name' => __( 'Status', 'backhaus-email-campaigns' ), 'value' => $row->status ),
						array( 'name' => __( 'Sent At', 'backhaus-email-campaigns' ), 'value' => (string) $row->sent_at ),
					),
				);
			}
		);
	}

	/**
	 * Export the automatic emails sent (or due) to an address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page number.
	 * @return array
	 */
	public static function export_automatic_sends( $email, $page = 1 ) {
		global $wpdb;
		return self::export_page(
			"SELECT id, rule_id, order_id, status, send_at, sent_at FROM {$wpdb->prefix}bhec_triggered_queue WHERE customer_email = %s ORDER BY id",
			$email,
			$page,
			static function ( $row ) {
				return array(
					'group_id'    => 'bhec_automatic_sends',
					'group_label' => __( 'Automatic Emails Sent', 'backhaus-email-campaigns' ),
					'item_id'     => 'automatic-send-' . $row->id,
					'data'        => array(
						array( 'name' => __( 'Automatic Email', 'backhaus-email-campaigns' ), 'value' => self::email_name( -(int) $row->rule_id ) ),
						array( 'name' => __( 'Order', 'backhaus-email-campaigns' ), 'value' => $row->order_id ? '#' . $row->order_id : '' ),
						array( 'name' => __( 'Status', 'backhaus-email-campaigns' ), 'value' => $row->status ),
						array( 'name' => __( 'Due', 'backhaus-email-campaigns' ), 'value' => (string) $row->send_at ),
						array( 'name' => __( 'Sent At', 'backhaus-email-campaigns' ), 'value' => (string) $row->sent_at ),
					),
				);
			}
		);
	}

	/**
	 * Export recorded opens, link clicks and orders credited to an email.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page number.
	 * @return array
	 */
	public static function export_activity( $email, $page = 1 ) {
		global $wpdb;
		$what = array(
			'open'       => __( 'Opened an email', 'backhaus-email-campaigns' ),
			'click'      => __( 'Clicked a link', 'backhaus-email-campaigns' ),
			'conversion' => __( 'Placed an order after clicking', 'backhaus-email-campaigns' ),
		);
		return self::export_page(
			"SELECT id, campaign_id, event_type, url, ip_address, user_agent, created_at FROM {$wpdb->prefix}bhec_tracking_events WHERE email = %s ORDER BY id",
			$email,
			$page,
			static function ( $row ) use ( $what ) {
				$data = array(
					array( 'name' => __( 'What', 'backhaus-email-campaigns' ), 'value' => $what[ $row->event_type ] ?? $row->event_type ),
					array( 'name' => __( 'Email', 'backhaus-email-campaigns' ), 'value' => self::email_name( $row->campaign_id ) ),
					array( 'name' => __( 'When (UTC)', 'backhaus-email-campaigns' ), 'value' => BHEC_Clock::utc( $row->created_at ) ),
				);
				if ( 'click' === $row->event_type ) {
					$data[] = array( 'name' => __( 'Link', 'backhaus-email-campaigns' ), 'value' => (string) $row->url );
				} elseif ( 'conversion' === $row->event_type ) {
					$data[] = array( 'name' => __( 'Order Total', 'backhaus-email-campaigns' ), 'value' => (string) $row->url );
				}
				if ( $row->ip_address ) {
					$data[] = array( 'name' => __( 'IP Address', 'backhaus-email-campaigns' ), 'value' => $row->ip_address );
				}
				if ( $row->user_agent ) {
					$data[] = array( 'name' => __( 'Browser', 'backhaus-email-campaigns' ), 'value' => $row->user_agent );
				}
				return array(
					'group_id'    => 'bhec_activity',
					'group_label' => __( 'Email Opens, Clicks and Orders', 'backhaus-email-campaigns' ),
					'item_id'     => 'activity-' . $row->id,
					'data'        => $data,
				);
			}
		);
	}

	/**
	 * Export back-in-stock requests made on product pages (Pro).
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page number.
	 * @return array
	 */
	public static function export_stock_alerts( $email, $page = 1 ) {
		global $wpdb;
		return self::export_page(
			"SELECT id, product_id, status, created_at, notified_at FROM {$wpdb->prefix}bhec_stock_alerts WHERE email = %s ORDER BY id",
			$email,
			$page,
			static function ( $row ) {
				$product = wc_get_product( (int) $row->product_id );
				return array(
					'group_id'    => 'bhec_stock_alerts',
					'group_label' => __( 'Back-in-Stock Requests', 'backhaus-email-campaigns' ),
					'item_id'     => 'stock-alert-' . $row->id,
					'data'        => array(
						array( 'name' => __( 'Product', 'backhaus-email-campaigns' ), 'value' => $product ? $product->get_name() : '#' . (int) $row->product_id ),
						array( 'name' => __( 'Status', 'backhaus-email-campaigns' ), 'value' => $row->status ),
						array( 'name' => __( 'Asked (UTC)', 'backhaus-email-campaigns' ), 'value' => get_gmt_from_date( $row->created_at ) ),
						array( 'name' => __( 'Emailed (UTC)', 'backhaus-email-campaigns' ), 'value' => $row->notified_at ? get_gmt_from_date( $row->notified_at ) : '' ),
					),
				);
			}
		);
	}

	/**
	 * Export guest carts saved at checkout (Pro).
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page number.
	 * @return array
	 */
	public static function export_saved_carts( $email, $page = 1 ) {
		global $wpdb;
		return self::export_page(
			"SELECT id, status, created_at, updated_at FROM {$wpdb->prefix}bhec_cart_captures WHERE email = %s ORDER BY id",
			$email,
			$page,
			static function ( $row ) {
				return array(
					'group_id'    => 'bhec_saved_carts',
					'group_label' => __( 'Saved Carts', 'backhaus-email-campaigns' ),
					'item_id'     => 'cart-' . $row->id,
					'data'        => array(
						array( 'name' => __( 'Status', 'backhaus-email-campaigns' ), 'value' => $row->status ),
						array( 'name' => __( 'Saved (UTC)', 'backhaus-email-campaigns' ), 'value' => get_gmt_from_date( $row->created_at ) ),
						array( 'name' => __( 'Updated (UTC)', 'backhaus-email-campaigns' ), 'value' => get_gmt_from_date( $row->updated_at ) ),
					),
				);
			}
		);
	}

	/**
	 * Register personal data erasers.
	 *
	 * @param array $erasers Existing erasers.
	 * @return array
	 */
	public static function register_erasers( $erasers ) {
		$erasers['bhec-data'] = array(
			'eraser_friendly_name' => __( 'Email Campaign Data', 'backhaus-email-campaigns' ),
			'callback'             => array( __CLASS__, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/**
	 * Export subscription data for a given email.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page number.
	 * @return array
	 */
	public static function export_subscription_data( $email, $page = 1 ) {
		$data = array();
		$sub  = BHEC_Subscription::get_status( $email );
		if ( $sub ) {
			$data[] = array(
				'group_id'    => 'bhec_subscription',
				'group_label' => __( 'Email Campaign Subscription', 'backhaus-email-campaigns' ),
				'item_id'     => 'subscription-' . $sub->id,
				'data'        => array(
					array( 'name' => __( 'Email', 'backhaus-email-campaigns' ), 'value' => $sub->email ),
					array( 'name' => __( 'Status', 'backhaus-email-campaigns' ), 'value' => $sub->status ),
					array( 'name' => __( 'Source', 'backhaus-email-campaigns' ), 'value' => $sub->source ),
					array( 'name' => __( 'Opted In', 'backhaus-email-campaigns' ), 'value' => $sub->opted_in_at ?: '' ),
					array( 'name' => __( 'Opted Out', 'backhaus-email-campaigns' ), 'value' => $sub->opted_out_at ?: '' ),
				),
			);
		}
		return array( 'data' => $data, 'done' => true );
	}

	/**
	 * Export email logs for a given email.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page number.
	 * @return array
	 */
	public static function export_email_logs( $email, $page = 1 ) {
		global $wpdb;
		$per_page = 50;
		$offset   = ( $page - 1 ) * $per_page;
		$logs     = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}bhec_logs WHERE recipient_email = %s ORDER BY sent_at DESC LIMIT %d OFFSET %d",
			$email, $per_page, $offset
		) );

		$data = array();
		foreach ( $logs as $log ) {
			$fields = array(
				array( 'name' => __( 'Subject', 'backhaus-email-campaigns' ), 'value' => $log->subject ),
				array( 'name' => __( 'Status', 'backhaus-email-campaigns' ), 'value' => $log->status ),
				array( 'name' => __( 'Sent At', 'backhaus-email-campaigns' ), 'value' => $log->sent_at ),
			);
			if ( ! empty( $log->recipient_name ) ) {
				$fields[] = array( 'name' => __( 'Name', 'backhaus-email-campaigns' ), 'value' => $log->recipient_name );
			}
			if ( ! empty( $log->error_message ) ) {
				$fields[] = array( 'name' => __( 'Error', 'backhaus-email-campaigns' ), 'value' => $log->error_message );
			}
			// The whole email, when the log is set to keep it.
			if ( ! empty( $log->email_body ) ) {
				$fields[] = array( 'name' => __( 'Email Content', 'backhaus-email-campaigns' ), 'value' => trim( wp_strip_all_tags( $log->email_body ) ) );
			}
			$data[] = array(
				'group_id'    => 'bhec_email_logs',
				'group_label' => __( 'Email Campaign Logs', 'backhaus-email-campaigns' ),
				'item_id'     => 'log-' . $log->id,
				'data'        => $fields,
			);
		}
		return array( 'data' => $data, 'done' => count( $logs ) < $per_page );
	}

	/**
	 * Erase personal data for a given email.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page number.
	 * @return array
	 */
	public static function erase_personal_data( $email, $page = 1 ) {
		global $wpdb;

		$wpdb->delete( $wpdb->prefix . 'bhec_tracking_events', array( 'email' => $email ), array( '%s' ) );
		$wpdb->delete( $wpdb->prefix . 'bhec_logs', array( 'recipient_email' => $email ), array( '%s' ) );
		$wpdb->delete( $wpdb->prefix . 'bhec_triggered_queue', array( 'customer_email' => $email ), array( '%s' ) );
		$wpdb->delete( $wpdb->prefix . 'bhec_queue', array( 'customer_email' => $email ), array( '%s' ) );
		$wpdb->delete( $wpdb->prefix . 'bhec_cart_captures', array( 'email' => $email ), array( '%s' ) );
		$wpdb->delete( $wpdb->prefix . 'bhec_stock_alerts', array( 'email' => $email ), array( '%s' ) );
		self::erase_coupons( $email );

		// Deleting the subscription row outright would put the person back on the
		// list: in auto mode, a customer with no row counts as subscribed, and
		// WooCommerce keeps their orders by default. Keep only the address,
		// marked unsubscribed, so they are never emailed again.
		$had_row    = (bool) BHEC_Subscription::get_status( $email );
		$has_orders = ! empty( wc_get_orders( array(
			'billing_email' => $email,
			'limit'         => 1,
			'return'        => 'ids',
		) ) );

		if ( ! $had_row && ! $has_orders ) {
			return array(
				'items_removed'  => true,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$wpdb->delete( $wpdb->prefix . 'bhec_subscriptions', array( 'email' => $email ), array( '%s' ) );
		$wpdb->insert(
			$wpdb->prefix . 'bhec_subscriptions',
			array(
				'email'        => $email,
				'customer_id'  => 0,
				'status'       => 'unsubscribed',
				'source'       => 'erasure',
				'token'        => wp_generate_password( 64, false ),
				'opted_out_at' => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		return array(
			'items_removed'  => true,
			'items_retained' => true,
			'messages'       => array(
				__( 'Email Campaigns kept this email address on its do-not-email list, with no other details, so the person is never sent marketing email again.', 'backhaus-email-campaigns' ),
			),
			'done'           => true,
		);
	}

	/**
	 * The unique coupons made for an address (Pro): an unused one is deleted,
	 * since it was only ever theirs; a used one keeps its usage count but loses
	 * the address.
	 *
	 * @param string $email Email address.
	 */
	private static function erase_coupons( $email ) {
		$ids = get_posts(
			array(
				'post_type'   => 'shop_coupon',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_key'    => '_bhec_recipient', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => $email, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		foreach ( $ids as $id ) {
			$coupon = new WC_Coupon( $id );
			if ( ! $coupon->get_usage_count() ) {
				wp_delete_post( $id, true );
				continue;
			}
			$coupon->set_email_restrictions( array_values( array_diff( $coupon->get_email_restrictions(), array( $email ) ) ) );
			$coupon->save();
			delete_post_meta( $id, '_bhec_recipient' );
			delete_post_meta( $id, '_used_by', $email );
		}
	}
}
