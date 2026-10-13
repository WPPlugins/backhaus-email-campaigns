<?php
/**
 * Campaign sending actions (pause, resume, stop, retry, held emails, send
 * one) and the live numbers on a campaign's page.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Admin_Queue {

	/**
	 * AJAX: Get queue status for active campaigns.
	 */
	public function ajax_queue_status() {
		check_ajax_referer( 'bhec_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'backhaus-email-campaigns' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bhec_campaigns';

		$campaigns = $wpdb->get_results(
			"SELECT * FROM $table WHERE status IN ('queued', 'sending', 'paused', 'completed', 'failed')
			ORDER BY FIELD(status, 'sending', 'queued', 'paused', 'failed', 'completed'), updated_at DESC
			LIMIT 20"
		);

		$queue = new BHEC_Queue();
		$data  = array();

		foreach ( $campaigns as $campaign ) {
			$progress     = $queue->get_progress( $campaign->id );
			$data[] = array(
				'id'              => $campaign->id,
				'title'           => $campaign->title,
				'status'          => $campaign->status,
				'recipient_count' => (int) $campaign->recipient_count,
				'progress'        => $progress,
				'started_at'      => $campaign->started_at,
				'completed_at'    => $campaign->completed_at,
			);
		}

		wp_send_json_success( $data );
	}

	/**
	 * AJAX: Pause a campaign.
	 */
	public function ajax_pause_campaign() {
		check_ajax_referer( 'bhec_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'backhaus-email-campaigns' ) );
		}

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
		$campaign    = BHEC_Campaign::get( $campaign_id );

		if ( ! $campaign || ! in_array( $campaign->status, array( 'sending', 'queued' ), true ) ) {
			wp_send_json_error( __( 'Campaign cannot be paused.', 'backhaus-email-campaigns' ) );
		}

		BHEC_Campaign::update( $campaign_id, array( 'status' => 'paused' ) );
		wp_send_json_success( __( 'Campaign paused.', 'backhaus-email-campaigns' ) );
	}

	/**
	 * AJAX: Stop a campaign completely — cancel all pending items.
	 */
	public function ajax_stop_campaign() {
		check_ajax_referer( 'bhec_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'backhaus-email-campaigns' ) );
		}

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
		$campaign    = BHEC_Campaign::get( $campaign_id );

		if ( ! $campaign || ! in_array( $campaign->status, array( 'sending', 'queued', 'paused' ), true ) ) {
			wp_send_json_error( __( 'Campaign cannot be stopped.', 'backhaus-email-campaigns' ) );
		}

		global $wpdb;
		$queue_table = $wpdb->prefix . 'bhec_queue';

		// Cancel all pending and sending items.
		$cancelled = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE $queue_table SET status = 'skipped' WHERE campaign_id = %d AND status IN ('pending', 'sending', 'held')",
				$campaign_id
			)
		);

		BHEC_Campaign::update( $campaign_id, array(
			'status'       => 'completed',
			'completed_at' => current_time( 'mysql' ),
		) );

		wp_send_json_success( sprintf(
			/* translators: %d: number of cancelled items */
			__( 'Campaign stopped. %d pending emails cancelled.', 'backhaus-email-campaigns' ),
			$cancelled
		) );
	}

	/**
	 * AJAX: Resume a paused campaign.
	 */
	public function ajax_resume_campaign() {
		check_ajax_referer( 'bhec_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'backhaus-email-campaigns' ) );
		}

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
		$campaign    = BHEC_Campaign::get( $campaign_id );

		if ( ! $campaign || 'paused' !== $campaign->status ) {
			wp_send_json_error( __( 'Campaign cannot be resumed.', 'backhaus-email-campaigns' ) );
		}

		BHEC_Campaign::update( $campaign_id, array( 'status' => 'sending' ) );

		// Ensure cron is running.
		if ( ! wp_next_scheduled( 'bhec_process_queue' ) ) {
			wp_schedule_event( time(), 'bhec_every_minute', 'bhec_process_queue' );
		}

		wp_send_json_success( __( 'Campaign resumed.', 'backhaus-email-campaigns' ) );
	}

	/**
	 * AJAX: Retry failed queue items.
	 */
	public function ajax_retry_failed() {
		check_ajax_referer( 'bhec_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'backhaus-email-campaigns' ) );
		}

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
		$queue       = new BHEC_Queue();
		$count       = $queue->retry_failed( $campaign_id );

		if ( $count > 0 ) {
			// Ensure campaign is in sending state.
			$campaign = BHEC_Campaign::get( $campaign_id );
			if ( $campaign && in_array( $campaign->status, array( 'completed', 'failed' ), true ) ) {
				BHEC_Campaign::update( $campaign_id, array( 'status' => 'sending' ) );
			}
		}

		wp_send_json_success( sprintf(
			/* translators: %d: number of items retried */
			__( '%d items queued for retry.', 'backhaus-email-campaigns' ),
			$count
		) );
	}

	/**
	 * AJAX: End a sending pause now ('provider' or 'automatic').
	 */
	public function ajax_resume_sending() {
		check_ajax_referer( 'bhec_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'backhaus-email-campaigns' ) );
		}

		$type = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';
		if ( 'automatic' === $type ) {
			BHEC_Send_Control::resume_automatic();
		} else {
			BHEC_Send_Control::resume_provider();
		}
		wp_send_json_success( __( 'Sending resumed.', 'backhaus-email-campaigns' ) );
	}

	/**
	 * AJAX: Send a campaign's held emails anyway, or discard them.
	 */
	public function ajax_resolve_held() {
		check_ajax_referer( 'bhec_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'backhaus-email-campaigns' ) );
		}

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
		$send        = isset( $_POST['decision'] ) && 'send' === $_POST['decision'];
		$count       = ( new BHEC_Queue() )->resolve_held( $campaign_id, $send );

		if ( $send && $count ) {
			// Reopen the campaign so the queue sends them.
			global $wpdb;
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}bhec_campaigns SET status = 'sending', completed_at = NULL WHERE id = %d AND status = 'completed'",
					$campaign_id
				)
			);
			/* translators: %d: number of emails */
			wp_send_json_success( sprintf( _n( '%d held email will be sent.', '%d held emails will be sent.', $count, 'backhaus-email-campaigns' ), $count ) );
		}
		/* translators: %d: number of emails */
		wp_send_json_success( sprintf( _n( '%d held email discarded.', '%d held emails discarded.', $count, 'backhaus-email-campaigns' ), $count ) );
	}

	/**
	 * AJAX: Force-send a specific queue item immediately.
	 */
	public function ajax_force_send() {
		check_ajax_referer( 'bhec_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'backhaus-email-campaigns' ) );
		}

		$queue_id = isset( $_POST['queue_id'] ) ? absint( $_POST['queue_id'] ) : 0;

		global $wpdb;
		$queue_table = $wpdb->prefix . 'bhec_queue';
		$item        = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM $queue_table WHERE id = %d", $queue_id ),
			ARRAY_A
		);

		if ( ! $item ) {
			wp_send_json_error( __( 'Queue item not found.', 'backhaus-email-campaigns' ) );
		}

		$campaign = BHEC_Campaign::get( $item['campaign_id'] );
		if ( ! $campaign ) {
			wp_send_json_error( __( 'Campaign not found.', 'backhaus-email-campaigns' ) );
		}

		if ( BHEC_Send_Control::provider_pause() ) {
			wp_send_json_error( __( 'Sending is paused because your mail provider asked us to slow down. Try again when it resumes.', 'backhaus-email-campaigns' ) );
		}

		// An admin sending to one person on purpose: past the over-mailing guard.
		$item['guard_override'] = 1;

		$sender  = new BHEC_Sender();
		$outcome = $sender->send_email( $item, $campaign );
		$email   = $item['customer_email'];

		switch ( $outcome ) {
			case 'sent':
				/* translators: %s: email address */
				wp_send_json_success( sprintf( __( 'Email sent to %s.', 'backhaus-email-campaigns' ), $email ) );
				break;
			case 'skipped':
				/* translators: %s: email address */
				wp_send_json_error( sprintf( __( 'Not sent: %s has unsubscribed.', 'backhaus-email-campaigns' ), $email ) );
				break;
			case 'deferred':
				wp_send_json_error( __( 'Not sent: your mail provider asked us to slow down. Sending pauses briefly and this email goes out when it resumes.', 'backhaus-email-campaigns' ) );
				break;
			default:
				/* translators: %s: email address */
				wp_send_json_error( sprintf( __( 'Failed to send to %s. See Logs for the reason.', 'backhaus-email-campaigns' ), $email ) );
		}
	}
}
