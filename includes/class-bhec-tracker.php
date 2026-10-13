<?php
/**
 * Email open/click/conversion tracking.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Tracker {

	/**
	 * Inject a 1x1 tracking pixel into the email HTML.
	 *
	 * @param string $html        Email HTML.
	 * @param int    $campaign_id Campaign ID.
	 * @param int    $queue_id    Queue item ID.
	 * @return string Modified HTML.
	 */
	public static function inject_tracking_pixel( $html, $campaign_id, $queue_id ) {
		$hash = self::make_hash( "open-{$campaign_id}-{$queue_id}" );
		$url  = add_query_arg(
			array(
				'bhec_track' => 'open',
				'cid'       => $campaign_id,
				'qid'       => $queue_id,
				't'         => $hash,
			),
			home_url( '/' )
		);

		$pixel = '<img src="' . esc_url( $url ) . '" width="1" height="1" style="display:none" alt="" />';

		// Insert before </body> if present, else append.
		if ( stripos( $html, '</body>' ) !== false ) {
			$html = str_ireplace( '</body>', $pixel . '</body>', $html );
		} else {
			$html .= $pixel;
		}

		return $html;
	}

	/**
	 * Rewrite links in email HTML for click tracking.
	 *
	 * @param string $html        Email HTML.
	 * @param int    $campaign_id Campaign ID.
	 * @param int    $queue_id    Queue item ID.
	 * @param string $email       Recipient email.
	 * @return string Modified HTML.
	 */
	public static function rewrite_links( $html, $campaign_id, $queue_id, $email = '' ) {
		// Match all href attributes.
		return preg_replace_callback(
			'/(<a\b[^>]*\bhref\s*=\s*["\'])([^"\']+)(["\'][^>]*>)/i',
			function ( $matches ) use ( $campaign_id, $queue_id ) {
				$original_url = html_entity_decode( $matches[2], ENT_QUOTES, 'UTF-8' );

				// Don't track unsubscribe links or anchor links.
				if ( strpos( $original_url, 'bhec_unsubscribe' ) !== false
					|| strpos( $original_url, '#' ) === 0
					|| strpos( $original_url, 'mailto:' ) === 0 ) {
					return $matches[0];
				}

				// Get or create tracking link.
				$link_hash = self::get_or_create_link( $campaign_id, $original_url );

				$tracking_url = add_query_arg(
					array(
						'bhec_track' => 'click',
						'lid'       => $link_hash,
						'qid'       => $queue_id,
						't'         => self::make_hash( "click-{$link_hash}-{$queue_id}" ),
					),
					home_url( '/' )
				);

				return $matches[1] . esc_url( $tracking_url ) . $matches[3];
			},
			$html
		);
	}

	// The open and click links come from emails, so they can't carry a nonce;
	// each is signed instead (make_hash), and record_open() and record_click()
	// check that signature before recording anything.
	// phpcs:disable WordPress.Security.NonceVerification.Recommended

	/**
	 * Handle incoming tracking requests.
	 */
	public static function handle_tracking_request() {
		$type = sanitize_key( (string) BHEC_Public::link_param( 'track' ) );

		if ( 'open' === $type ) {
			self::handle_open();
		} elseif ( 'click' === $type ) {
			self::handle_click();
		}
	}

	/**
	 * Handle open tracking request.
	 */
	private static function handle_open() {
		self::record_open(
			isset( $_GET['cid'] ) ? (int) $_GET['cid'] : 0, // Negative for automatic emails.
			isset( $_GET['qid'] ) ? absint( $_GET['qid'] ) : 0,
			isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : ''
		);
		self::serve_pixel();
	}

	/**
	 * Record an open from a genuine pixel. Campaigns are tracked under their
	 * id and their bhec_queue row; automatic emails under minus their rule id
	 * and their bhec_triggered_queue row.
	 *
	 * @param int    $campaign_id Campaign id, or minus the rule id.
	 * @param int    $queue_id    Queue row id.
	 * @param string $hash        Signature from the pixel URL.
	 * @return bool Whether an open was recorded.
	 */
	public static function record_open( $campaign_id, $queue_id, $hash ) {
		$campaign_id = (int) $campaign_id;
		$queue_id    = absint( $queue_id );
		if ( ! $campaign_id || ! $queue_id || ! hash_equals( self::make_hash( "open-{$campaign_id}-{$queue_id}" ), (string) $hash ) ) {
			return false;
		}
		$email = self::recipient( $campaign_id, $queue_id );
		if ( ! $email ) {
			return false;
		}

		self::record_event( $campaign_id, $queue_id, $email, 'open' );
		if ( $campaign_id > 0 && self::is_first( $campaign_id, $email, 'open' ) ) {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}bhec_campaigns SET opened_count = opened_count + 1 WHERE id = %d", $campaign_id ) );
		}
		return true;
	}

	/**
	 * Handle click tracking request.
	 */
	private static function handle_click() {
		$link = self::record_click(
			isset( $_GET['lid'] ) ? sanitize_text_field( wp_unslash( $_GET['lid'] ) ) : '',
			isset( $_GET['qid'] ) ? absint( $_GET['qid'] ) : 0,
			isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : ''
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! $link ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		// Redirect to original URL (decode HTML entities for legacy stored URLs).
		$redirect_url = html_entity_decode( $link->original_url, ENT_QUOTES, 'UTF-8' );

		// Fix double-scheme URLs (e.g. http://https://... → https://...) caused by
		// TinyMCE auto-prepending http:// to merge tags like {{ checkout_url }}.
		if ( preg_match( '#^https?://(https?)(?:://|//)(.+)$#', $redirect_url, $m ) ) {
			$redirect_url = $m[1] . '://' . $m[2];
		}

		wp_redirect( $redirect_url ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}

	/**
	 * Record a click from a genuine tracked link.
	 *
	 * @param string $link_hash Link id from the URL.
	 * @param int    $queue_id  Queue row id.
	 * @param string $hash      Signature from the URL.
	 * @return object|null The link (original_url, campaign_id), or null if not genuine.
	 */
	public static function record_click( $link_hash, $queue_id, $hash ) {
		global $wpdb;
		$queue_id = absint( $queue_id );
		if ( '' === (string) $link_hash || ! $queue_id || ! hash_equals( self::make_hash( "click-{$link_hash}-{$queue_id}" ), (string) $hash ) ) {
			return null;
		}
		$links_table = $wpdb->prefix . 'bhec_tracking_links';
		$link        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $links_table WHERE hash = %s", $link_hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $link ) {
			return null;
		}

		$campaign_id = (int) $link->campaign_id;
		$email       = self::recipient( $campaign_id, $queue_id );
		if ( $email ) {
			self::record_event( $campaign_id, $queue_id, $email, 'click', $link->original_url );
			$wpdb->query( $wpdb->prepare( "UPDATE $links_table SET click_count = click_count + 1 WHERE id = %d", $link->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( $campaign_id > 0 && self::is_first( $campaign_id, $email, 'click' ) ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}bhec_campaigns SET clicked_count = clicked_count + 1 WHERE id = %d", $campaign_id ) );
			}
		}
		return $link;
	}

	/**
	 * Who a tracked email went to: automatic emails (negative ids) live in
	 * their own queue, numbered separately from campaign rows.
	 *
	 * @param int $campaign_id Campaign id, or minus the rule id.
	 * @param int $queue_id    Queue row id.
	 * @return string|null
	 */
	private static function recipient( $campaign_id, $queue_id ) {
		global $wpdb;
		$table = $wpdb->prefix . ( $campaign_id < 0 ? 'bhec_triggered_queue' : 'bhec_queue' );
		return $wpdb->get_var( $wpdb->prepare( "SELECT customer_email FROM $table WHERE id = %d", $queue_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Whether the event just recorded is this person's first of its kind for
	 * the email, so the campaign's unique counters go up once.
	 *
	 * @param int    $campaign_id Campaign id.
	 * @param string $email       Recipient.
	 * @param string $type        open or click.
	 * @return bool
	 */
	private static function is_first( $campaign_id, $email, $type ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bhec_tracking_events WHERE campaign_id = %d AND email = %s AND event_type = %s",
				$campaign_id,
				$email,
				$type
			)
		) <= 1;
	}

	/**
	 * Record a tracking event.
	 *
	 * @param int    $campaign_id Campaign ID.
	 * @param int    $queue_id    Queue item ID.
	 * @param string $email       Recipient email.
	 * @param string $event_type  Event type: open|click|conversion.
	 * @param string $url         Optional URL (for clicks).
	 */
	public static function record_event( $campaign_id, $queue_id, $email, $event_type, $url = null ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bhec_tracking_events',
			array(
				'campaign_id' => $campaign_id,
				'queue_id'    => $queue_id,
				'email'       => $email,
				'event_type'  => $event_type,
				'url'         => $url,
				// Stored trimmed, like WordPress's own privacy tools do.
				'ip_address'  => self::client_ip() ? wp_privacy_anonymize_ip( self::client_ip() ) : '',
				'user_agent'  => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * A link's id: the same URL in the same email always gets the same id.
	 *
	 * @param int    $campaign_id Campaign id, or minus the rule id.
	 * @param string $url         Original URL.
	 * @return string
	 */
	public static function link_hash( $campaign_id, $url ) {
		return substr( hash( 'sha256', $campaign_id . $url . wp_salt() ), 0, 16 );
	}

	/**
	 * Get or create a tracking link record.
	 *
	 * @param int    $campaign_id  Campaign ID.
	 * @param string $original_url Original URL.
	 * @return string Link hash.
	 */
	public static function get_or_create_link( $campaign_id, $original_url ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bhec_tracking_links';

		$hash = self::link_hash( $campaign_id, $original_url );

		$existing = $wpdb->get_var(
			$wpdb->prepare( "SELECT hash FROM $table WHERE campaign_id = %d AND hash = %s", $campaign_id, $hash )
		);

		if ( ! $existing ) {
			$wpdb->insert(
				$table,
				array(
					'campaign_id'  => $campaign_id,
					'original_url' => $original_url,
					'hash'         => $hash,
				),
				array( '%d', '%s', '%s' )
			);
		}

		return $hash;
	}

	/**
	 * Get campaign analytics.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array
	 */
	public static function get_campaign_analytics( $campaign_id ) {
		global $wpdb;
		$events_table = $wpdb->prefix . 'bhec_tracking_events';
		$links_table  = $wpdb->prefix . 'bhec_tracking_links';
		$campaign     = BHEC_Campaign::get( $campaign_id );

		$analytics = array(
			'total_sent'     => $campaign ? (int) $campaign->sent_count : 0,
			'total_failed'   => $campaign ? (int) $campaign->failed_count : 0,
			'unique_opens'   => 0,
			'total_opens'    => 0,
			'unique_clicks'  => 0,
			'total_clicks'   => 0,
			'open_rate'      => 0,
			'click_rate'     => 0,
			'top_links'      => array(),
			'opens_by_date'  => array(),
			'clicks_by_date' => array(),
		);

		// Unique opens.
		$analytics['unique_opens'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT email) FROM $events_table WHERE campaign_id = %d AND event_type = 'open'",
				$campaign_id
			)
		);

		// Total opens.
		$analytics['total_opens'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $events_table WHERE campaign_id = %d AND event_type = 'open'",
				$campaign_id
			)
		);

		// Unique clicks.
		$analytics['unique_clicks'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT email) FROM $events_table WHERE campaign_id = %d AND event_type = 'click'",
				$campaign_id
			)
		);

		// Total clicks.
		$analytics['total_clicks'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $events_table WHERE campaign_id = %d AND event_type = 'click'",
				$campaign_id
			)
		);

		// Rates.
		if ( $analytics['total_sent'] > 0 ) {
			$analytics['open_rate']  = round( ( $analytics['unique_opens'] / $analytics['total_sent'] ) * 100, 1 );
			$analytics['click_rate'] = round( ( $analytics['unique_clicks'] / $analytics['total_sent'] ) * 100, 1 );
		}

		// Top links.
		$analytics['top_links'] = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT original_url, click_count FROM $links_table WHERE campaign_id = %d ORDER BY click_count DESC LIMIT 10",
				$campaign_id
			),
			ARRAY_A
		);

		// Opens by date.
		$analytics['opens_by_date'] = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) as date, COUNT(*) as count
				FROM $events_table WHERE campaign_id = %d AND event_type = 'open'
				GROUP BY DATE(created_at) ORDER BY date ASC",
				$campaign_id
			),
			ARRAY_A
		);

		// Clicks by date.
		$analytics['clicks_by_date'] = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) as date, COUNT(*) as count
				FROM $events_table WHERE campaign_id = %d AND event_type = 'click'
				GROUP BY DATE(created_at) ORDER BY date ASC",
				$campaign_id
			),
			ARRAY_A
		);

		// Revenue from conversions (stored as the url field in conversion events).
		$analytics['revenue'] = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(CAST(url AS DECIMAL(10,2))), 0) FROM $events_table
				WHERE campaign_id = %d AND event_type = 'conversion'",
				$campaign_id
			)
		);

		// Conversion count.
		$analytics['conversions'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $events_table WHERE campaign_id = %d AND event_type = 'conversion'",
				$campaign_id
			)
		);

		return $analytics;
	}

	/**
	 * Get analytics for a triggered email rule.
	 *
	 * Triggered emails use negative campaign_id (-rule_id) in tracking events.
	 *
	 * @param int $rule_id Rule ID.
	 * @return array
	 */
	public static function get_triggered_analytics( $rule_id ) {
		global $wpdb;
		$events_table  = $wpdb->prefix . 'bhec_tracking_events';
		$tq_table      = $wpdb->prefix . 'bhec_triggered_queue';
		$campaign_id   = -1 * absint( $rule_id );

		$analytics = array(
			'total_sent'    => 0,
			'total_failed'  => 0,
			'unique_opens'  => 0,
			'total_opens'   => 0,
			'unique_clicks' => 0,
			'total_clicks'  => 0,
			'open_rate'     => 0,
			'click_rate'    => 0,
			'conversions'   => 0,
			'revenue'       => 0,
		);

		// Get sent/failed counts from triggered queue.
		$analytics['total_sent'] = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM $tq_table WHERE rule_id = %d AND status = 'sent'", $rule_id )
		);
		$analytics['total_failed'] = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM $tq_table WHERE rule_id = %d AND status = 'failed'", $rule_id )
		);

		$analytics['unique_opens'] = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT email) FROM $events_table WHERE campaign_id = %d AND event_type = 'open'", $campaign_id )
		);
		$analytics['total_opens'] = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM $events_table WHERE campaign_id = %d AND event_type = 'open'", $campaign_id )
		);
		$analytics['unique_clicks'] = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT email) FROM $events_table WHERE campaign_id = %d AND event_type = 'click'", $campaign_id )
		);
		$analytics['total_clicks'] = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM $events_table WHERE campaign_id = %d AND event_type = 'click'", $campaign_id )
		);

		if ( $analytics['total_sent'] > 0 ) {
			$analytics['open_rate']  = round( ( $analytics['unique_opens'] / $analytics['total_sent'] ) * 100, 1 );
			$analytics['click_rate'] = round( ( $analytics['unique_clicks'] / $analytics['total_sent'] ) * 100, 1 );
		}

		$analytics['revenue'] = (float) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(CAST(url AS DECIMAL(10,2))), 0) FROM $events_table WHERE campaign_id = %d AND event_type = 'conversion'", $campaign_id )
		);
		$analytics['conversions'] = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM $events_table WHERE campaign_id = %d AND event_type = 'conversion'", $campaign_id )
		);

		return $analytics;
	}

	/**
	 * Generate HMAC hash for URL verification.
	 *
	 * @param string $data Data to hash.
	 * @return string
	 */
	private static function make_hash( $data ) {
		return hash_hmac( 'sha256', $data, wp_salt() );
	}

	/**
	 * Serve a 1x1 transparent GIF.
	 */
	private static function serve_pixel() {
		header( 'Content-Type: image/gif' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		// Minimal 1x1 transparent GIF.
		echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' ); // phpcs:ignore WordPress.Security.EscapeOutput -- a fixed binary image, not markup.
		exit;
	}

	/**
	 * The visitor's IP address. REMOTE_ADDR is the connection's own address,
	 * which a visitor can't forge; X-Forwarded-For can be (HANDOFF L4). A site
	 * behind a proxy or CDN hands in the real address with the bhec_client_ip
	 * filter.
	 *
	 * @return string A valid IP address, or ''.
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = (string) apply_filters( 'bhec_client_ip', $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
