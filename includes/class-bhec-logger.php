<?php
/**
 * Email logging — records sent emails for the logs page.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Logger {

	/**
	 * Log an email send.
	 *
	 * @param array $data {
	 *     @type int    $campaign_id     Campaign ID.
	 *     @type int    $queue_id        Queue item ID.
	 *     @type string $recipient_email Recipient email.
	 *     @type string $recipient_name  Recipient name.
	 *     @type string $subject         Email subject.
	 *     @type string $status          sent|failed|skipped.
	 *     @type string $error_message   Error message (for failures).
	 *     @type string $email_body      Full HTML body (only in full logging mode).
	 * }
	 */
	public static function log( $data ) {
		$mode = get_option( 'bhec_logging_mode', 'meta' );
		if ( 'off' === $mode ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bhec_logs';

		$insert = array(
			'campaign_id'     => isset( $data['campaign_id'] ) ? absint( $data['campaign_id'] ) : null,
			'queue_id'        => isset( $data['queue_id'] ) ? absint( $data['queue_id'] ) : null,
			'rule_id'         => isset( $data['rule_id'] ) ? absint( $data['rule_id'] ) : null,
			'recipient_email' => sanitize_email( $data['recipient_email'] ?? '' ),
			'recipient_name'  => sanitize_text_field( $data['recipient_name'] ?? '' ),
			'subject'         => sanitize_text_field( $data['subject'] ?? '' ),
			'status'          => sanitize_key( $data['status'] ?? 'sent' ),
			'error_message'   => isset( $data['error_message'] ) ? sanitize_text_field( $data['error_message'] ) : null,
			'email_body'      => 'full' === $mode && isset( $data['email_body'] ) ? $data['email_body'] : null,
		);

		$wpdb->insert( $table, $insert );
	}

	/**
	 * Get logs with filtering and pagination.
	 *
	 * @param array $args {
	 *     @type int    $campaign_id Filter by campaign.
	 *     @type string $email       Search by email.
	 *     @type string $search      Search email or subject.
	 *     @type string $status      Filter by status.
	 *     @type int    $limit       Per page.
	 *     @type int    $offset      Offset.
	 * }
	 * @return array
	 */
	public static function get_logs( $args = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bhec_logs';

		// "1 = %d" keeps a value for prepare() when no filter is set.
		$where  = array( '1 = %d' );
		$params = array( 1 );

		if ( ! empty( $args['campaign_id'] ) ) {
			$where[]  = 'campaign_id = %d';
			$params[] = absint( $args['campaign_id'] );
		}

		if ( ! empty( $args['email'] ) ) {
			$where[]  = 'recipient_email = %s';
			$params[] = sanitize_email( $args['email'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(recipient_email LIKE %s OR recipient_name LIKE %s OR subject LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key( $args['status'] );
		}

		$limit  = isset( $args['limit'] ) ? absint( $args['limit'] ) : 25;
		$offset = isset( $args['offset'] ) ? absint( $args['offset'] ) : 0;

		$sql = "SELECT * FROM $table WHERE " . implode( ' AND ', $where )
			. " ORDER BY sent_at DESC LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- built above from fixed text and placeholders.
	}

	/**
	 * Count logs with optional filters.
	 *
	 * @param array $args Same filters as get_logs.
	 * @return int
	 */
	public static function count_logs( $args = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bhec_logs';

		// "1 = %d" keeps a value for prepare() when no filter is set.
		$where  = array( '1 = %d' );
		$params = array( 1 );

		if ( ! empty( $args['campaign_id'] ) ) {
			$where[]  = 'campaign_id = %d';
			$params[] = absint( $args['campaign_id'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(recipient_email LIKE %s OR recipient_name LIKE %s OR subject LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key( $args['status'] );
		}

		$sql = "SELECT COUNT(*) FROM $table WHERE " . implode( ' AND ', $where );

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- built above from fixed text and placeholders.
	}

	/**
	 * Get a single log entry.
	 *
	 * @param int $id Log ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bhec_logs WHERE id = %d", $id )
		);
	}

	/**
	 * Purge old logs based on retention setting.
	 */
	public static function purge_old_logs() {
		$days = (int) get_option( 'bhec_log_retention_days', 90 );
		if ( $days <= 0 ) {
			return 0;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bhec_logs';

		// sent_at comes from the database clock, so the cut-off does too.
		return (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM $table WHERE sent_at < DATE_SUB( NOW(), INTERVAL %d DAY )", $days ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	/**
	 * Get database size info for the logs table.
	 *
	 * @return array { rows: int, size_mb: float }
	 */
	public static function get_table_stats() {
		global $wpdb;
		$table = $wpdb->prefix . 'bhec_logs';

		$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );

		$size = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
				FROM information_schema.TABLES WHERE table_schema = %s AND table_name = %s",
				DB_NAME,
				$table
			)
		);

		return array(
			'rows'    => $rows,
			'size_mb' => $size ? (float) $size->size_mb : 0,
		);
	}
}
