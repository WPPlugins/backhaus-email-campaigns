<?php
/**
 * Queue management for bulk email sending.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Queue {

	/**
	 * Rows claimed longer ago than this are treated as abandoned by a run that
	 * died. It must exceed the longest batch (batch size x per-email delay).
	 */
	const CLAIM_TIMEOUT_MINUTES = 10;

	/**
	 * Enqueue recipients for a campaign.
	 *
	 * @param int   $campaign_id Campaign ID.
	 * @param array $recipients  Array of [email, name, customer_id].
	 * @return int Number of rows inserted.
	 */
	public function enqueue( $campaign_id, $recipients ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bhec_queue';

		$inserted = 0;
		foreach ( $recipients as $recipient ) {
			$result = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO $table (campaign_id, customer_email, customer_name, customer_id, status)
					VALUES (%d, %s, %s, %d, 'pending')",
					$campaign_id,
					$recipient['email'],
					$recipient['name'] ?? '',
					$recipient['customer_id'] ?? 0
				)
			);
			if ( $result ) {
				$inserted++;
			}
		}

		return $inserted;
	}

	/**
	 * Claim the next batch of pending items for this run and mark them 'sending'.
	 *
	 * The claim is a single UPDATE, so two runs (WP-Cron and Live Processing)
	 * can never claim the same row.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @param int $batch_size  Number of items to fetch.
	 * @return array Queue items.
	 */
	public function get_next_batch( $campaign_id, $batch_size ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bhec_queue';
		$token = wp_generate_password( 32, false );

		$now     = current_time( 'mysql', true );
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET status = 'sending', claim_token = %s, claimed_at = %s
				WHERE campaign_id = %d AND status = 'pending' AND variant <> 'W'
				AND ( next_attempt_at IS NULL OR next_attempt_at <= %s )
				ORDER BY id ASC LIMIT %d",
				$token,
				$now,
				$campaign_id,
				$now,
				$batch_size
			)
		);

		if ( ! $claimed ) {
			return array();
		}

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM $table WHERE claim_token = %s ORDER BY id ASC", $token ),
			ARRAY_A
		);
	}

	/**
	 * Mark a queue item as sent.
	 *
	 * @param int $queue_id Queue row ID.
	 */
	public function mark_sent( $queue_id ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'bhec_queue',
			array(
				'status'      => 'sent',
				'sent_at'     => current_time( 'mysql' ),
				'claim_token' => null,
			),
			array( 'id' => $queue_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Record a failed attempt. A permanent failure (the address can't receive
	 * mail) fails at once; otherwise the email is retried after a growing delay
	 * until bhec_max_retries.
	 *
	 * @param int    $queue_id  Queue row ID.
	 * @param string $error     Error message.
	 * @param bool   $permanent Whether retrying can't help.
	 * @return string The row's new status: 'failed' or 'pending' (will retry).
	 */
	public function mark_failed( $queue_id, $error = '', $permanent = false ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bhec_queue';

		$max_retries = (int) get_option( 'bhec_max_retries', 3 );

		$item = $wpdb->get_row(
			$wpdb->prepare( "SELECT attempts FROM $table WHERE id = %d", $queue_id )
		);

		$attempts = $item ? (int) $item->attempts + 1 : 1;
		$status   = ( $permanent || $attempts >= $max_retries ) ? 'failed' : 'pending';

		$wpdb->update(
			$table,
			array(
				'status'          => $status,
				'attempts'        => $attempts,
				'last_error'      => $error,
				'claim_token'     => null,
				'next_attempt_at' => 'pending' === $status ? BHEC_Send_Control::retry_at( $attempts ) : null,
			),
			array( 'id' => $queue_id ),
			array( '%s', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
		return $status;
	}

	/**
	 * Put an email back because the mail provider asked us to slow down. It
	 * isn't the recipient's fault, so it doesn't count as an attempt.
	 *
	 * @param int    $queue_id   Queue row ID.
	 * @param string $error      Provider's message.
	 * @param int    $not_before Unix time before which it isn't retried.
	 */
	public function mark_deferred( $queue_id, $error, $not_before ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}bhec_queue SET status = 'pending', last_error = %s, claim_token = NULL, deferrals = deferrals + 1, next_attempt_at = %s WHERE id = %d",
				$error,
				gmdate( 'Y-m-d H:i:s', $not_before ),
				$queue_id
			)
		);
	}

	/**
	 * Hold an email the over-mailing guard stopped (see BHEC_Send_Guard).
	 *
	 * @param int    $queue_id Queue row ID.
	 * @param string $reason   Reason code.
	 */
	public function mark_held( $queue_id, $reason ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'bhec_queue',
			array(
				'status'      => 'held',
				'held_reason' => $reason,
				'claim_token' => null,
			),
			array( 'id' => $queue_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Admin decision on a campaign's held emails: send them anyway (past the
	 * guard) or discard them.
	 *
	 * @param int  $campaign_id Campaign ID.
	 * @param bool $send        True to send anyway, false to discard.
	 * @return int Rows changed.
	 */
	public function resolve_held( $campaign_id, $send ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bhec_queue';

		if ( $send ) {
			return (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE $table SET status = 'pending', guard_override = 1, held_reason = NULL WHERE campaign_id = %d AND status = 'held'",
					$campaign_id
				)
			);
		}
		return (int) $wpdb->query(
			$wpdb->prepare( "UPDATE $table SET status = 'skipped' WHERE campaign_id = %d AND status = 'held'", $campaign_id )
		);
	}

	/**
	 * Mark a queue item as skipped (e.g. unsubscribed after queue).
	 *
	 * @param int $queue_id Queue row ID.
	 */
	public function mark_skipped( $queue_id ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'bhec_queue',
			array(
				'status'      => 'skipped',
				'claim_token' => null,
			),
			array( 'id' => $queue_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Get progress for a campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array Counts by status.
	 */
	public function get_progress( $campaign_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bhec_queue';

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) as count FROM $table WHERE campaign_id = %d GROUP BY status",
				$campaign_id
			),
			ARRAY_A
		);

		$progress = array(
			'total'   => 0,
			'pending' => 0,
			'sending' => 0,
			'sent'    => 0,
			'failed'  => 0,
			'skipped' => 0,
			'held'    => 0,
		);

		foreach ( $results as $row ) {
			$progress[ $row['status'] ] = (int) $row['count'];
			$progress['total']         += (int) $row['count'];
		}

		return $progress;
	}

	/**
	 * Retry failed items for a campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return int Number of items reset.
	 */
	public function retry_failed( $campaign_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bhec_queue';

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET status = 'pending', attempts = 0, deferrals = 0, last_error = NULL, next_attempt_at = NULL
				WHERE campaign_id = %d AND status = 'failed'",
				$campaign_id
			)
		);
	}

	/**
	 * Return rows abandoned by a run that died back to 'pending'.
	 *
	 * Only claims older than the threshold are touched, so a run that is still
	 * sending keeps its rows. Each recovery counts as an attempt, so a row that
	 * keeps killing the run ends up 'failed' instead of looping forever.
	 *
	 * @param int $minutes Claims older than this are recovered.
	 * @return int Number of items recovered.
	 */
	public function recover_stuck( $minutes = self::CLAIM_TIMEOUT_MINUTES ) {
		global $wpdb;
		$table       = $wpdb->prefix . 'bhec_queue';
		$max_retries = max( 1, (int) get_option( 'bhec_max_retries', 3 ) );
		$cutoff      = gmdate( 'Y-m-d H:i:s', time() - $minutes * MINUTE_IN_SECONDS );

		// Rows claimed before 1.1.1 have no claimed_at; treat them as abandoned.
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET
					status = IF( attempts + 1 >= %d, 'failed', 'pending' ),
					last_error = IF( attempts + 1 >= %d, %s, last_error ),
					attempts = attempts + 1,
					claim_token = NULL,
					claimed_at = NULL
				WHERE status = 'sending' AND ( claimed_at IS NULL OR claimed_at < %s )",
				$max_retries,
				$max_retries,
				__( 'The sending run stopped before this email finished.', 'backhaus-email-campaigns' ),
				$cutoff
			)
		);
	}
}
