<?php
/**
 * Over-mailing guard: stops a customer being emailed too often by accident,
 * such as a rule that fires on every order, a campaign sent twice, or a bug.
 *
 * Emails over a limit are held, not sent, and an admin can send them anyway.
 * "Always send" emails count toward the limits like any other. Limits are in
 * Settings; 0 turns one off.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Send_Guard {

	/** Held automatic emails within this window that trip the breaker. */
	const BREAKER_WINDOW_MINUTES = 10;

	/**
	 * Hold reason codes and their labels.
	 *
	 * @return array
	 */
	public static function reasons() {
		return array(
			'campaign_limit' => __( 'Got another campaign recently', 'backhaus-email-campaigns' ),
			'daily_limit'    => __( 'Daily email limit reached', 'backhaus-email-campaigns' ),
			'rule_cooldown'  => __( 'Got this automatic email recently', 'backhaus-email-campaigns' ),
		);
	}

	/**
	 * Why a campaign email to this address should be held, or '' to send it.
	 *
	 * @param string $email       Recipient.
	 * @param int    $campaign_id Campaign being sent.
	 * @return string Reason code.
	 */
	public static function campaign_hold_reason( $email, $campaign_id ) {
		global $wpdb;

		$days = (int) get_option( 'bhec_guard_campaign_days', 3 );
		if ( $days > 0 ) {
			$other = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}bhec_queue
					WHERE customer_email = %s AND campaign_id <> %d AND status = 'sent' AND sent_at >= %s
					LIMIT 1",
					$email,
					$campaign_id,
					wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
				)
			);
			if ( $other ) {
				return 'campaign_limit';
			}
		}

		return self::over_daily_limit( $email ) ? 'daily_limit' : '';
	}

	/**
	 * Why an automatic email to this address should be held, or '' to send it.
	 *
	 * "Always send" rules are for transactional-style reminders (a payment
	 * reminder, say), so the daily limit doesn't hold them. The repeat check
	 * still does, which is what catches a rule firing on every order.
	 *
	 * @param string $email       Recipient.
	 * @param int    $rule_id     Automatic email rule.
	 * @param bool   $always_send Whether the rule is set to always send.
	 * @return string Reason code.
	 */
	public static function automatic_hold_reason( $email, $rule_id, $always_send = false ) {
		global $wpdb;

		$hours = (int) get_option( 'bhec_guard_rule_hours', 24 );
		if ( $hours > 0 ) {
			$recent = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}bhec_triggered_queue
					WHERE customer_email = %s AND rule_id = %d AND status = 'sent' AND sent_at >= %s
					LIMIT 1",
					$email,
					$rule_id,
					wp_date( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS )
				)
			);
			if ( $recent ) {
				return 'rule_cooldown';
			}
		}

		return ( ! $always_send && self::over_daily_limit( $email ) ) ? 'daily_limit' : '';
	}

	/**
	 * Whether this address already got the daily limit of marketing emails
	 * (campaigns and automatic, not counting "always send" reminders) in the
	 * last 24 hours.
	 *
	 * @param string $email Recipient.
	 * @return bool
	 */
	private static function over_daily_limit( $email ) {
		global $wpdb;

		$limit = (int) get_option( 'bhec_guard_daily_limit', 3 );
		if ( $limit <= 0 ) {
			return false;
		}
		$since = wp_date( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$sent  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ( SELECT COUNT(*) FROM {$wpdb->prefix}bhec_queue WHERE customer_email = %s AND status = 'sent' AND sent_at >= %s )
					+ ( SELECT COUNT(*) FROM {$wpdb->prefix}bhec_triggered_queue q
						INNER JOIN {$wpdb->prefix}bhec_triggered_rules r ON r.id = q.rule_id
						WHERE q.customer_email = %s AND q.status = 'sent' AND q.sent_at >= %s AND r.ignore_optout = 0 )",
				$email,
				$since,
				$email,
				$since
			)
		);
		return $sent >= $limit;
	}

	/**
	 * After holding an automatic email: if many were held in a short time for
	 * repeating the same email, a rule is firing too often, so pause automatic
	 * emails. Holds from before the admin last resumed don't count.
	 */
	public static function after_automatic_hold() {
		global $wpdb;

		$limit = (int) get_option( 'bhec_guard_breaker_holds', 20 );
		if ( $limit <= 0 || BHEC_Send_Control::automatic_pause() ) {
			return;
		}
		$since = max( time() - self::BREAKER_WINDOW_MINUTES * MINUTE_IN_SECONDS, (int) get_option( 'bhec_automatic_resumed_at', 0 ) );
		$held  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bhec_triggered_queue
				WHERE status = 'held' AND held_reason = 'rule_cooldown' AND claimed_at >= %s",
				gmdate( 'Y-m-d H:i:s', $since )
			)
		);
		if ( $held >= $limit ) {
			BHEC_Send_Control::pause_automatic(
				sprintf(
					/* translators: 1: number of emails, 2: minutes */
					__( '%1$d automatic emails were held in %2$d minutes because the same people were about to be emailed again.', 'backhaus-email-campaigns' ),
					$held,
					self::BREAKER_WINDOW_MINUTES
				)
			);
		}
	}

	/**
	 * What to tell the admin before a campaign goes out.
	 *
	 * @param object $campaign   Campaign row.
	 * @param array  $recipients Rows with an 'email' key.
	 * @return array { total, recently_emailed, days, similar: { title, when }|null }
	 */
	public static function send_check( $campaign, $recipients ) {
		global $wpdb;

		$days     = (int) get_option( 'bhec_guard_campaign_days', 3 );
		$recently = 0;
		if ( $days > 0 && $recipients ) {
			$emailed = array_flip(
				array_map(
					'strtolower',
					$wpdb->get_col(
						$wpdb->prepare(
							"SELECT DISTINCT customer_email FROM {$wpdb->prefix}bhec_queue
							WHERE campaign_id <> %d AND status = 'sent' AND sent_at >= %s",
							(int) $campaign->id,
							wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
						)
					)
				)
			);
			foreach ( $recipients as $r ) {
				if ( isset( $emailed[ strtolower( $r['email'] ) ] ) ) {
					$recently++;
				}
			}
		}

		$similar = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT title, COALESCE(completed_at, started_at) AS sent_on FROM {$wpdb->prefix}bhec_campaigns
				WHERE id <> %d AND subject = %s AND status IN ('completed', 'sending', 'queued')
				AND COALESCE(completed_at, started_at) >= %s
				ORDER BY id DESC LIMIT 1",
				(int) $campaign->id,
				(string) $campaign->subject,
				wp_date( 'Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS )
			)
		);

		return array(
			'total'            => count( $recipients ),
			'recently_emailed' => $recently,
			'days'             => $days,
			'similar'          => $similar ? array(
				'title' => $similar->title,
				'when'  => $similar->sent_on,
			) : null,
		);
	}
}
