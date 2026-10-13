<?php
/**
 * Campaign queue processor: batch sending under a database lock.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Cron {

	/**
	 * Process the email queue.
	 *
	 * Called by WP-Cron every minute and by Live Processing. Only one run holds
	 * the lock at a time; row claims in BHEC_Queue keep sends unique even if the
	 * lock is lost.
	 *
	 * @return bool False if another run holds the lock.
	 */
	public function process_queue() {
		$is_live = defined( 'BHEC_LIVE_PROCESSING' ) && BHEC_LIVE_PROCESSING;

		// Live Processing waits for a background run (at most about 50 s) to finish.
		if ( ! self::acquire_lock( $is_live ? 55 : 0 ) ) {
			return false;
		}

		try {
			$this->run( $is_live );
		} finally {
			self::release_lock();
		}

		return true;
	}

	/**
	 * Take the queue lock. MySQL releases it by itself if the process dies.
	 *
	 * @param int $wait Seconds to wait for another run to finish.
	 * @return bool
	 */
	public static function acquire_lock( $wait = 0 ) {
		return BHEC_Send_Control::lock( 'queue', $wait );
	}

	/**
	 * Release the queue lock.
	 */
	public static function release_lock() {
		BHEC_Send_Control::unlock( 'queue' );
	}

	/**
	 * Send due batches while holding the lock.
	 *
	 * @param bool $is_live Live Processing call (longer window, multiple batches).
	 */
	private function run( $is_live ) {
		$max_execution = $is_live ? 270 : 50;
		wc_set_time_limit( $max_execution + 60 );

		$queue  = new BHEC_Queue();
		$sender = new BHEC_Sender();

		$queue->recover_stuck();

		// The mail provider asked us to slow down: nothing goes out until the pause ends.
		if ( BHEC_Send_Control::provider_pause() ) {
			return;
		}

		$this->activate_scheduled_campaigns();

		// A subject test whose wait is over picks its winner, releasing the rest.
		if ( class_exists( 'BHEC_AB_Test' ) ) {
			BHEC_AB_Test::decide_due();
		}

		$campaigns = $this->get_active_campaigns();
		if ( empty( $campaigns ) ) {
			return;
		}

		BHEC_Send_Control::start_run();
		$batch_size = max( 1, (int) get_option( 'bhec_batch_size', 25 ) );
		$capacity   = BHEC_Send_Control::remaining_capacity();
		$start_time = time();
		$sent_now   = 0;
		$handled    = 0;

		foreach ( $campaigns as $campaign ) {
			if ( 'queued' === $campaign->status ) {
				BHEC_Campaign::update( $campaign->id, array(
					'status'     => 'sending',
					'started_at' => current_time( 'mysql' ),
				) );
			}

			// Background runs send one batch per campaign; Live Processing keeps
			// going until its time window closes.
			do {
				if ( $sent_now >= $capacity ) {
					break 2;
				}
				$items = $queue->get_next_batch( $campaign->id, (int) min( $batch_size, $capacity - $sent_now ) );
				if ( empty( $items ) ) {
					break;
				}

				foreach ( $items as $index => $item ) {
					// Out of time or at the hourly/daily cap: hand the rest of this
					// batch back instead of leaving it claimed.
					if ( ( time() - $start_time ) >= $max_execution || $sent_now >= $capacity ) {
						$this->release_items( array_slice( $items, $index ) );
						break 3;
					}

					// Per-minute cap and spacing; no slot before the deadline ends the run.
					if ( ! BHEC_Send_Control::wait_for_slot( $start_time + $max_execution ) ) {
						$this->release_items( array_slice( $items, $index ) );
						break 3;
					}

					if ( 'sent' === $sender->send_email( $item, $campaign ) ) {
						$sent_now++;
					}
					$handled++;

					// A slow-down, or many failures in a row, paused sending.
					if ( BHEC_Send_Control::provider_pause() ) {
						$this->release_items( array_slice( $items, $index + 1 ) );
						break 3;
					}

					// Every 250 emails, stop if an admin paused or stopped the campaign.
					if ( $is_live && 0 === $handled % 250 ) {
						$fresh = BHEC_Campaign::get( $campaign->id );
						if ( ! $fresh || ! in_array( $fresh->status, array( 'sending', 'queued' ), true ) ) {
							$this->release_items( array_slice( $items, $index + 1 ) );
							break 3;
						}
					}
				}
			} while ( $is_live && ( time() - $start_time ) < $max_execution );

			$this->maybe_complete( $campaign->id );
		}
	}

	/**
	 * Return claimed but unsent items to the queue.
	 *
	 * @param array $items Queue rows (ARRAY_A).
	 */
	private function release_items( $items ) {
		global $wpdb;
		$ids = array_values( array_map( 'absint', wp_list_pluck( $items, 'id' ) ) );
		if ( empty( $ids ) ) {
			return;
		}
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one placeholder per value.
				"UPDATE {$wpdb->prefix}bhec_queue SET status = 'pending', claim_token = NULL, claimed_at = NULL
				WHERE status = 'sending' AND id IN ( $placeholders )",
				$ids
			)
		);
	}

	/**
	 * Mark a campaign completed once nothing is pending or in flight, and email
	 * the admin. Rows another run is still sending keep the campaign open.
	 *
	 * @param int $campaign_id Campaign ID.
	 */
	private function maybe_complete( $campaign_id ) {
		global $wpdb;

		$progress = ( new BHEC_Queue() )->get_progress( $campaign_id );
		if ( $progress['pending'] > 0 || $progress['sending'] > 0 ) {
			return;
		}

		// Conditional update, so only one run completes the campaign and emails the admin.
		$completed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}bhec_campaigns SET status = 'completed', completed_at = %s
				WHERE id = %d AND status IN ('queued', 'sending')",
				current_time( 'mysql' ),
				$campaign_id
			)
		);
		if ( 1 !== (int) $completed ) {
			return;
		}

		$campaign = BHEC_Campaign::get( $campaign_id );
		wp_mail(
			get_option( 'admin_email' ),
			sprintf(
				/* translators: 1: site name, 2: campaign title */
				__( '[%1$s] Campaign "%2$s" completed', 'backhaus-email-campaigns' ),
				get_bloginfo( 'name' ),
				$campaign->title
			),
			sprintf(
				/* translators: 1: campaign title, 2: sent count, 3: failed count, 4: campaign page URL, 5: held count */
				__( "Campaign \"%1\$s\" has finished sending.\n\nSent: %2\$d\nFailed: %3\$d\nHeld by the over-mailing guard: %5\$d (you can send them anyway from the campaign's page)\n\nSee the campaign: %4\$s", 'backhaus-email-campaigns' ),
				$campaign->title,
				$progress['sent'],
				$progress['failed'],
				admin_url( 'admin.php?page=bhec-campaigns&action=view&id=' . $campaign_id ),
				$progress['held']
			)
		);
	}

	/**
	 * Get campaigns that are currently sending or queued.
	 *
	 * @return array
	 */
	private function get_active_campaigns() {
		global $wpdb;
		$table = $wpdb->prefix . 'bhec_campaigns';

		return $wpdb->get_results(
			"SELECT * FROM $table WHERE status IN ('queued', 'sending') ORDER BY id ASC"
		);
	}

	/**
	 * Queue the recipients of scheduled campaigns whose time has come.
	 *
	 * scheduled_at is store-local time, as entered in the editor. Queuing clears
	 * it, so each schedule fires once. A failed campaign can be rescheduled.
	 */
	private function activate_scheduled_campaigns() {
		global $wpdb;
		$table = $wpdb->prefix . 'bhec_campaigns';

		$due = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM $table WHERE status IN ('draft', 'failed') AND scheduled_at IS NOT NULL AND scheduled_at <= %s ORDER BY id ASC",
				current_time( 'mysql' )
			)
		);

		foreach ( $due as $campaign_id ) {
			$result = BHEC_Campaign::queue_campaign( (int) $campaign_id );
			if ( is_wp_error( $result ) ) {
				// Clear the date, or the recipient query would run again every minute.
				BHEC_Campaign::update( (int) $campaign_id, array(
					'status'       => 'failed',
					'scheduled_at' => null,
				) );
			}
		}
	}
}
