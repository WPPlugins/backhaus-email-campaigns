<?php
/**
 * Settings > Logs: helpers for the tab and its AJAX (details, delete old).
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Admin_Logs {

	/**
	 * The old Logs page. Its address now redirects to the Settings tab before
	 * anything renders, so this only points the way.
	 */
	public static function render_page() {
		printf(
			'<div class="wrap"><p><a href="%s">%s</a></p></div>',
			esc_url( BHEC_Admin_Settings::url( 'logs' ) ),
			esc_html__( 'Email logs are under Settings, Logs.', 'backhaus-email-campaigns' )
		);
	}

	/**
	 * @param string $status Log status: sent, failed or skipped.
	 * @return string HTML.
	 */
	public static function result_badge( $status ) {
		$badges = array(
			'sent'    => array( 'good', __( 'Sent', 'backhaus-email-campaigns' ) ),
			'failed'  => array( 'bad', __( 'Failed', 'backhaus-email-campaigns' ) ),
			'skipped' => array( '', __( 'Skipped', 'backhaus-email-campaigns' ) ),
		);
		$badge  = $badges[ $status ] ?? array( '', $status );
		return sprintf(
			'<span class="bhec-badge%s">%s</span>',
			$badge[0] ? ' bhec-badge-' . esc_attr( $badge[0] ) : '',
			esc_html( $badge[1] )
		);
	}

	/**
	 * A campaign's name, or a stand-in when it has none or was deleted.
	 *
	 * @param int         $id    Campaign id.
	 * @param string|null $title Title, or null when the campaign is gone.
	 * @return string
	 */
	public static function campaign_name( $id, $title ) {
		if ( null === $title ) {
			/* translators: %d: campaign number */
			return sprintf( __( 'Campaign %d (deleted)', 'backhaus-email-campaigns' ), $id );
		}
		return '' !== $title ? $title : __( '(No Name)', 'backhaus-email-campaigns' );
	}

	/**
	 * A log time as the store's date and time. sent_at is filled by the
	 * database clock (UTC), unlike the queues' store-local times.
	 *
	 * @param string $datetime UTC MySQL datetime.
	 * @return string
	 */
	public static function format_logged( $datetime ) {
		return $datetime ? BHEC_Admin_Campaigns::format_local( get_date_from_gmt( BHEC_Clock::utc( $datetime ) ) ) : '';
	}

	/**
	 * How many entries "Delete Old Logs" would remove. Uses the same cut-off
	 * as BHEC_Logger::purge_old_logs(), so the number matches what goes.
	 *
	 * @return int
	 */
	public static function old_entry_count() {
		$days = (int) get_option( 'bhec_log_retention_days', 90 );
		if ( $days <= 0 ) {
			return 0;
		}
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bhec_logs WHERE sent_at < DATE_SUB( NOW(), INTERVAL %d DAY )", $days )
		);
	}

	/**
	 * A logged email ready for the sandboxed frame. Loading its open pixel or
	 * following a tracked link would count as the customer opening or
	 * clicking, so the pixel goes and links can't open.
	 *
	 * @param string $html Logged email HTML.
	 * @return string
	 */
	private static function viewable_body( $html ) {
		$html = preg_replace( '/<img\b[^>]*\b(?:bhec|wec)_track=open\b[^>]*>/i', '', $html );
		// Links then need a new window, which the sandbox doesn't allow.
		$base = '<base target="_blank">';
		$html = preg_replace( '/<head\b[^>]*>/i', '$0' . $base, $html, 1, $count );
		return $count ? $html : $base . $html;
	}

	/**
	 * Names of automatic emails, for log entries.
	 *
	 * @param int[] $ids Rule ids; empty and repeated ones are fine.
	 * @return array id => name, for rules that still exist.
	 */
	public static function rule_names( $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		global $wpdb;
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one placeholder per value.
				"SELECT id, name FROM {$wpdb->prefix}bhec_triggered_rules WHERE id IN ( $placeholders )",
				$ids
			)
		);
		return wp_list_pluck( $rows, 'name', 'id' );
	}

	/**
	 * @param int $rule_id Rule id.
	 * @return string The automatic email's editor.
	 */
	public static function rule_url( $rule_id ) {
		return admin_url( 'admin.php?page=bhec-triggered&action=edit&id=' . (int) $rule_id );
	}

	/**
	 * AJAX: one log entry, for the details dialog.
	 */
	public function ajax_get_log_detail() {
		check_ajax_referer( 'bhec_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'backhaus-email-campaigns' ) );
		}

		$log_id = isset( $_POST['log_id'] ) ? absint( $_POST['log_id'] ) : 0;
		if ( ! $log_id ) {
			wp_send_json_error( __( 'No log ID specified.', 'backhaus-email-campaigns' ) );
		}

		$log = BHEC_Logger::get( $log_id );
		if ( ! $log ) {
			wp_send_json_error( __( 'This log entry no longer exists.', 'backhaus-email-campaigns' ) );
		}

		$campaign_id = (int) $log->campaign_id;
		$source_url  = '';
		if ( $campaign_id ) {
			$campaign = BHEC_Campaign::get( $campaign_id );
			$source   = self::campaign_name( $campaign_id, $campaign ? $campaign->title : null );
			if ( $campaign ) {
				$source_url = BHEC_Admin_Campaigns::url( array( 'action' => 'view', 'id' => $campaign_id ) );
			}
		} else {
			$rule_id = (int) $log->rule_id;
			$names   = self::rule_names( array( $rule_id ) );
			$source  = $names[ $rule_id ] ?? ( $rule_id ? __( '(Deleted Automatic Email)', 'backhaus-email-campaigns' ) : __( 'Automatic email', 'backhaus-email-campaigns' ) );
			if ( isset( $names[ $rule_id ] ) ) {
				$source_url = self::rule_url( $rule_id );
			}
		}

		$data = array(
			'id'              => $log->id,
			'campaign_id'     => $log->campaign_id,
			'queue_id'        => $log->queue_id,
			'recipient_email' => $log->recipient_email,
			'recipient_name'  => $log->recipient_name,
			'subject'         => $log->subject,
			'status'          => $log->status,
			'error_message'   => $log->error_message,
			'sent_at'         => $log->sent_at,
			'when'            => self::format_logged( $log->sent_at ),
			'result'          => self::result_badge( $log->status ),
			'source'          => $source,
			'source_url'      => $source_url,
		);

		if ( ! empty( $log->email_body ) ) {
			$data['email_body'] = self::viewable_body( $log->email_body );
		}

		wp_send_json_success( $data );
	}

	/**
	 * AJAX: delete entries older than the retention period.
	 */
	public function ajax_purge_logs() {
		check_ajax_referer( 'bhec_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'backhaus-email-campaigns' ) );
		}

		wp_send_json_success( array( 'deleted' => BHEC_Logger::purge_old_logs() ) );
	}
}
