<?php
/**
 * Who a campaign went to, on the campaign's page: one row per recipient,
 * filtered by what happened, with whether they opened, clicked and ordered.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class BHEC_Campaign_Recipients_List_Table extends WP_List_Table {

	/** @var object Campaign row. */
	private $campaign;

	/** @var array Count per queue status. */
	private $counts;

	/** @var array queue id => opened, clicked, revenue, for the rows shown. */
	private $engagement = array();

	/**
	 * @param object $campaign Campaign row.
	 * @param array  $counts   From BHEC_Queue::get_progress().
	 */
	public function __construct( $campaign, $counts ) {
		$this->campaign = $campaign;
		$this->counts   = $counts;
		parent::__construct(
			array(
				'singular' => 'recipient',
				'plural'   => 'recipients',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Views: label and the queue statuses each covers.
	 *
	 * @return array
	 */
	private function view_defs() {
		return array(
			'sent'    => array( __( 'Sent', 'backhaus-email-campaigns' ), array( 'sent' ) ),
			'waiting' => array( __( 'Waiting', 'backhaus-email-campaigns' ), array( 'pending', 'sending' ) ),
			'held'    => array( __( 'Held', 'backhaus-email-campaigns' ), array( 'held' ) ),
			'failed'  => array( __( 'Failed', 'backhaus-email-campaigns' ), array( 'failed' ) ),
			'skipped' => array( __( 'Skipped', 'backhaus-email-campaigns' ), array( 'skipped' ) ),
		);
	}

	private function current_view() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$view = isset( $_GET['who'] ) ? sanitize_key( $_GET['who'] ) : '';
		return isset( $this->view_defs()[ $view ] ) ? $view : '';
	}

	public function prepare_items() {
		global $wpdb;
		$table = $wpdb->prefix . 'bhec_queue';

		$where  = array( 'campaign_id = %d' );
		$params = array( (int) $this->campaign->id );
		$view   = $this->current_view();
		if ( $view ) {
			$statuses = $this->view_defs()[ $view ][1];
			$where[]  = 'status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
			$params   = array_merge( $params, $statuses );
		}
		// phpcs:ignore WordPress.Security.NonceVerification
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(customer_email LIKE %s OR customer_name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}
		$where = implode( ' AND ', $where );

		$per_page = 25;
		$total    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE $where", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$this->items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, customer_email, customer_name, customer_id, status, sent_at, last_error, held_reason, next_attempt_at
				FROM $table WHERE $where ORDER BY (sent_at IS NULL), sent_at DESC, id ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL
				array_merge( $params, array( $per_page, ( $this->get_pagenum() - 1 ) * $per_page ) )
			)
		);

		// What each person on this page did with the email: one query per page.
		$ids = array_values( array_map( 'intval', wp_list_pluck( $this->items, 'id' ) ) );
		if ( $ids ) {
			$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
			$rows         = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one placeholder per value.
					"SELECT queue_id,
						MAX(event_type = 'open') AS opened,
						MAX(event_type = 'click') AS clicked,
						SUM(CASE WHEN event_type = 'conversion' THEN CAST(url AS DECIMAL(10,2)) ELSE 0 END) AS revenue,
						MAX(event_type = 'conversion') AS ordered
					FROM {$wpdb->prefix}bhec_tracking_events
					WHERE campaign_id = %d AND queue_id IN ( $placeholders )
					GROUP BY queue_id",
					array_merge( array( (int) $this->campaign->id ), $ids )
				)
			);
			foreach ( $rows as $row ) {
				$this->engagement[ (int) $row->queue_id ] = $row;
			}
		}

		$this->_column_headers = array( $this->get_columns(), array(), array(), 'email' );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
			)
		);
	}

	public function get_columns() {
		return array(
			'email'  => __( 'Recipient', 'backhaus-email-campaigns' ),
			'status' => __( 'What Happened', 'backhaus-email-campaigns' ),
			'when'   => __( 'When', 'backhaus-email-campaigns' ),
		);
	}

	protected function get_views() {
		$base    = BHEC_Admin_Campaigns::url( array( 'action' => 'view', 'id' => $this->campaign->id ) );
		$current = $this->current_view();
		$views   = array(
			'all' => sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( $base . '#recipients' ),
				'' === $current ? ' class="current" aria-current="page"' : '',
				esc_html__( 'All', 'backhaus-email-campaigns' ),
				number_format_i18n( $this->counts['total'] )
			),
		);
		foreach ( $this->view_defs() as $view => $def ) {
			$count = 0;
			foreach ( $def[1] as $status ) {
				$count += (int) ( $this->counts[ $status ] ?? 0 );
			}
			if ( ! $count ) {
				continue;
			}
			$views[ $view ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( add_query_arg( 'who', $view, $base ) . '#recipients' ),
				$view === $current ? ' class="current" aria-current="page"' : '',
				esc_html( $def[0] ),
				number_format_i18n( $count )
			);
		}
		return $views;
	}

	public function column_email( $item ) {
		$out = '<strong>' . esc_html( $item->customer_email ) . '</strong>';
		if ( trim( (string) $item->customer_name ) !== '' ) {
			$out .= '<span class="bhec-row-subject">' . esc_html( $item->customer_name ) . '</span>';
		}

		$actions = array();
		if ( 'held' === $item->status ) {
			$actions['send'] = sprintf( '<button type="button" class="button-link bhec-send-one" data-id="%d">%s</button>', (int) $item->id, esc_html__( 'Send Anyway', 'backhaus-email-campaigns' ) );
		} elseif ( 'failed' === $item->status ) {
			$actions['send'] = sprintf( '<button type="button" class="button-link bhec-send-one" data-id="%d">%s</button>', (int) $item->id, esc_html__( 'Try Again', 'backhaus-email-campaigns' ) );
		}
		if ( $item->customer_id ) {
			$actions['customer'] = sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'user-edit.php?user_id=' . (int) $item->customer_id ) ), esc_html__( 'Customer Profile', 'backhaus-email-campaigns' ) );
		}
		return $out . $this->row_actions( $actions );
	}

	public function column_status( $item ) {
		$labels = array(
			'sent'    => __( 'Sent', 'backhaus-email-campaigns' ),
			'pending' => __( 'Waiting', 'backhaus-email-campaigns' ),
			'sending' => __( 'Sending', 'backhaus-email-campaigns' ),
			'held'    => __( 'Held', 'backhaus-email-campaigns' ),
			'failed'  => __( 'Failed', 'backhaus-email-campaigns' ),
			'skipped' => __( 'Skipped', 'backhaus-email-campaigns' ),
		);
		$out    = esc_html( $labels[ $item->status ] ?? $item->status );
		$why    = '';
		if ( 'held' === $item->status ) {
			$why = BHEC_Send_Guard::reasons()[ $item->held_reason ] ?? '';
		} elseif ( 'skipped' === $item->status ) {
			$why = $item->last_error ? $item->last_error : __( 'Unsubscribed, or the campaign was stopped', 'backhaus-email-campaigns' );
		} elseif ( in_array( $item->status, array( 'failed', 'pending' ), true ) && $item->last_error ) {
			$why = $item->last_error;
		} elseif ( 'sent' === $item->status && isset( $this->engagement[ (int) $item->id ] ) ) {
			$why = self::engagement_text( $this->engagement[ (int) $item->id ] );
		}
		if ( '' !== $why ) {
			$out .= '<span class="bhec-status-detail">' . esc_html( $why ) . '</span>';
		}
		return $out;
	}

	/**
	 * "Opened, clicked and ordered ($45.80)" and the shorter forms. A click can
	 * come without a recorded open when the mail app blocks images.
	 *
	 * @param object $row opened, clicked, ordered, revenue.
	 * @return string
	 */
	public static function engagement_text( $row ) {
		$did = array();
		if ( (int) $row->opened ) {
			$did[] = __( 'opened', 'backhaus-email-campaigns' );
		}
		if ( (int) $row->clicked ) {
			$did[] = __( 'clicked', 'backhaus-email-campaigns' );
		}
		if ( (int) $row->ordered ) {
			/* translators: %s: order total */
			$did[] = sprintf( __( 'ordered (%s)', 'backhaus-email-campaigns' ), html_entity_decode( wp_strip_all_tags( wc_price( (float) $row->revenue ) ), ENT_QUOTES, 'UTF-8' ) );
		}
		if ( ! $did ) {
			return '';
		}
		$last = array_pop( $did );
		/* translators: 1: earlier actions, such as "opened, clicked", 2: the last one */
		$text = $did ? sprintf( __( '%1$s and %2$s', 'backhaus-email-campaigns' ), implode( ', ', $did ), $last ) : $last;
		return ucfirst( $text );
	}

	public function column_when( $item ) {
		if ( $item->sent_at ) {
			return esc_html( BHEC_Admin_Campaigns::format_local( $item->sent_at ) );
		}
		if ( 'pending' === $item->status && $item->next_attempt_at ) {
			$at = strtotime( $item->next_attempt_at . ' UTC' );
			/* translators: %s: time */
			return $at > time() ? esc_html( sprintf( __( 'Next try %s', 'backhaus-email-campaigns' ), wp_date( get_option( 'time_format' ), $at ) ) ) : '';
		}
		return '';
	}

	public function no_items() {
		esc_html_e( 'Nobody here.', 'backhaus-email-campaigns' );
	}
}
