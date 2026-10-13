<?php
/**
 * Campaign CRUD operations.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Campaign {

	/**
	 * Create a new campaign.
	 *
	 * @param array $data Campaign data.
	 * @return int|false Inserted ID or false.
	 */
	public static function create( $data ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bhec_campaigns';

		$defaults = array(
			'title'              => '',
			'subject'            => '',
			'subject_b'          => '',
			'ab_test'            => '',
			'body'               => '',
			'status'             => 'draft',
			'filters'            => '{}',
			'exclude_segment_id' => null,
			'email_to'           => '{{ customer.email }}',
			'email_cc'           => '',
			'email_bcc'          => '',
			'reply_to_name'      => '',
			'reply_to_email'     => '',
			'template_id'        => null,
			'layout_template'    => 'default',
			'attachments'        => '[]',
			'coupon_settings'    => '',
			'created_by'         => get_current_user_id(),
			'scheduled_at'       => null,
		);

		$data = wp_parse_args( $data, $defaults );

		$result = $wpdb->insert(
			$table,
			array(
				'title'              => sanitize_text_field( $data['title'] ),
				'subject'            => sanitize_text_field( $data['subject'] ),
				'subject_b'          => sanitize_text_field( $data['subject_b'] ),
				'ab_test'            => $data['ab_test'],
				'body'               => wp_kses_post( $data['body'] ),
				'status'             => sanitize_key( $data['status'] ),
				'filters'            => $data['filters'],
				'exclude_segment_id' => $data['exclude_segment_id'] ? absint( $data['exclude_segment_id'] ) : null,
				'email_to'           => sanitize_text_field( $data['email_to'] ),
				'email_cc'           => sanitize_text_field( $data['email_cc'] ),
				'email_bcc'          => sanitize_text_field( $data['email_bcc'] ),
				'reply_to_name'      => sanitize_text_field( $data['reply_to_name'] ),
				'reply_to_email'     => sanitize_text_field( $data['reply_to_email'] ),
				'template_id'        => $data['template_id'] ? absint( $data['template_id'] ) : null,
				'layout_template'    => sanitize_key( $data['layout_template'] ),
				'attachments'        => $data['attachments'],
				'coupon_settings'    => $data['coupon_settings'],
				'created_by'         => absint( $data['created_by'] ),
				'scheduled_at'       => $data['scheduled_at'] ?: null,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s' )
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Update a campaign.
	 *
	 * @param int   $id   Campaign ID.
	 * @param array $data Data to update.
	 * @return bool
	 */
	public static function update( $id, $data ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bhec_campaigns';

		$allowed = array(
			'title', 'subject', 'subject_b', 'ab_test', 'body', 'status', 'filters',
			'exclude_segment_id', 'email_to', 'email_cc', 'email_bcc',
			'reply_to_name', 'reply_to_email', 'template_id', 'layout_template',
			'attachments', 'coupon_settings', 'scheduled_at', 'started_at', 'completed_at',
			'recipient_count', 'sent_count', 'failed_count',
			'opened_count', 'clicked_count',
		);

		$update = array();
		$format = array();

		foreach ( $allowed as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				continue;
			}

			$value = $data[ $field ];

			switch ( $field ) {
				case 'title':
				case 'subject':
				case 'subject_b':
				case 'email_to':
				case 'email_cc':
				case 'email_bcc':
				case 'reply_to_name':
				case 'reply_to_email':
					$update[ $field ] = sanitize_text_field( $value );
					$format[]         = '%s';
					break;
				case 'body':
					$update[ $field ] = wp_kses_post( $value );
					$format[]         = '%s';
					break;
				case 'status':
				case 'layout_template':
					$update[ $field ] = sanitize_key( $value );
					$format[]         = '%s';
					break;
				case 'filters':
				case 'attachments':
				case 'coupon_settings':
				case 'ab_test':
					$update[ $field ] = $value;
					$format[]         = '%s';
					break;
				case 'exclude_segment_id':
				case 'template_id':
					$update[ $field ] = $value ? absint( $value ) : null;
					$format[]         = '%d';
					break;
				case 'scheduled_at':
				case 'started_at':
				case 'completed_at':
					$update[ $field ] = $value ?: null;
					$format[]         = '%s';
					break;
				case 'recipient_count':
				case 'sent_count':
				case 'failed_count':
				case 'opened_count':
				case 'clicked_count':
					$update[ $field ] = absint( $value );
					$format[]         = '%d';
					break;
			}
		}

		if ( empty( $update ) ) {
			return false;
		}

		return (bool) $wpdb->update( $table, $update, array( 'id' => absint( $id ) ), $format, array( '%d' ) );
	}

	/**
	 * Get a single campaign.
	 *
	 * @param int $id Campaign ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bhec_campaigns';
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id )
		);
	}

	/**
	 * Get multiple campaigns.
	 *
	 * @param array $args { status, orderby, order, limit, offset, search }.
	 * @return array
	 */
	public static function get_all( $args = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bhec_campaigns';

		$defaults = array(
			'status'  => '',
			'orderby' => 'created_at',
			'order'   => 'DESC',
			'limit'   => 20,
			'offset'  => 0,
			'search'  => '',
		);
		$args = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$params = array();

		if ( $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key( $args['status'] );
		}

		if ( $args['search'] ) {
			$where[]  = '(title LIKE %s OR subject LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$allowed_orderby = array( 'created_at', 'title', 'status', 'sent_count', 'opened_count' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$sql = "SELECT * FROM $table WHERE " . implode( ' AND ', $where )
			. " ORDER BY $orderby $order LIMIT %d OFFSET %d";
		$params[] = absint( $args['limit'] );
		$params[] = absint( $args['offset'] );

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Count campaigns, optionally filtered by status.
	 *
	 * @param string $status Optional status filter.
	 * @return int
	 */
	public static function count( $status = '' ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bhec_campaigns';

		if ( $status ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE status = %s", $status )
			);
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
	}

	/**
	 * Delete a campaign and its queue items.
	 *
	 * @param int $id Campaign ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;

		$wpdb->delete( $wpdb->prefix . 'bhec_queue', array( 'campaign_id' => $id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'bhec_tracking_events', array( 'campaign_id' => $id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'bhec_tracking_links', array( 'campaign_id' => $id ), array( '%d' ) );

		return (bool) $wpdb->delete( $wpdb->prefix . 'bhec_campaigns', array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Duplicate a campaign as a new draft.
	 *
	 * @param int $id Campaign ID to clone.
	 * @return int|false New campaign ID or false.
	 */
	public static function duplicate( $id ) {
		$campaign = self::get( $id );
		if ( ! $campaign ) {
			return false;
		}

		return self::create( array(
			'title'              => $campaign->title . ' (Copy)',
			'subject'            => $campaign->subject,
			'subject_b'          => $campaign->subject_b,
			'ab_test'            => class_exists( 'BHEC_AB_Test' ) ? BHEC_AB_Test::sanitize( BHEC_AB_Test::settings( $campaign ) ) : '',
			'body'               => $campaign->body,
			'filters'            => $campaign->filters,
			'exclude_segment_id' => $campaign->exclude_segment_id,
			'email_to'           => $campaign->email_to,
			'email_cc'           => $campaign->email_cc,
			'email_bcc'          => $campaign->email_bcc,
			'reply_to_name'      => $campaign->reply_to_name,
			'reply_to_email'     => $campaign->reply_to_email,
			'template_id'        => $campaign->template_id,
			'layout_template'    => $campaign->layout_template,
			'attachments'        => $campaign->attachments,
			'coupon_settings'    => $campaign->coupon_settings,
		) );
	}

	/**
	 * Queue a campaign for sending.
	 *
	 * @param int $id Campaign ID.
	 * @return array|WP_Error { recipient_count: int } or error.
	 */
	public static function queue_campaign( $id ) {
		$campaign = self::get( $id );
		if ( ! $campaign ) {
			return new WP_Error( 'not_found', __( 'Campaign not found.', 'backhaus-email-campaigns' ) );
		}

		if ( ! in_array( $campaign->status, array( 'draft', 'failed' ), true ) ) {
			return new WP_Error( 'invalid_status', __( 'Campaign cannot be queued in its current status.', 'backhaus-email-campaigns' ) );
		}

		$filters    = json_decode( $campaign->filters, true ) ?: array();
		$recipients = BHEC_Customer_Query::query( $filters, (int) $campaign->exclude_segment_id );

		if ( empty( $recipients ) ) {
			return new WP_Error( 'no_recipients', __( 'No recipients match the selected filters.', 'backhaus-email-campaigns' ) );
		}

		$queue = new BHEC_Queue();
		$queue->enqueue( $id, $recipients );
		if ( class_exists( 'BHEC_AB_Test' ) ) {
			BHEC_AB_Test::assign( $id );
		}

		self::update( $id, array(
			'status'          => 'queued',
			'recipient_count' => count( $recipients ),
			'sent_count'      => 0,
			'failed_count'    => 0,
			'started_at'      => null,
			'completed_at'    => null,
			'scheduled_at'    => null, // Done with; a later Reset must not re-send it.
		) );

		// Ensure cron is scheduled.
		if ( ! wp_next_scheduled( 'bhec_process_queue' ) ) {
			wp_schedule_event( time(), 'bhec_every_minute', 'bhec_process_queue' );
		}

		return array( 'recipient_count' => count( $recipients ) );
	}
}
