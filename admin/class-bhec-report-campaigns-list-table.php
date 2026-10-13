<?php
/**
 * Reports: the campaigns that sent in the period, side by side, with each
 * campaign's results so far (the same numbers as its own page).
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class BHEC_Report_Campaigns_List_Table extends WP_List_Table {

	/** @var string Period start, store time. */
	private $since;

	/** @var int Period length in days. */
	private $days;

	/**
	 * @param string $since Period start, store-local MySQL datetime.
	 * @param int    $days  Period length.
	 */
	public function __construct( $since, $days ) {
		$this->since = $since;
		$this->days  = (int) $days;
		parent::__construct(
			array(
				'singular' => 'bhec-report-campaign',
				'plural'   => 'bhec-report-campaigns',
				'ajax'     => false,
			)
		);
	}

	public function prepare_items() {
		global $wpdb;
		$campaigns = $wpdb->prefix . 'bhec_campaigns';
		$events    = $wpdb->prefix . 'bhec_tracking_events';
		$in_period = $wpdb->prepare( "SELECT campaign_id FROM {$wpdb->prefix}bhec_queue WHERE status = 'sent' AND sent_at >= %s", $this->since );

		$sortable = array(
			'sent'    => 'c.sent_count',
			'opened'  => 'c.opened_count / NULLIF(c.sent_count, 0)',
			'clicked' => 'c.clicked_count / NULLIF(c.sent_count, 0)',
			'revenue' => 'revenue',
		);
		// phpcs:ignore WordPress.Security.NonceVerification
		$key     = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : '';
		$orderby = $sortable[ $key ] ?? 'COALESCE(c.started_at, c.updated_at)';
		// phpcs:ignore WordPress.Security.NonceVerification
		$order = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( $_GET['order'] ) ) ? 'ASC' : 'DESC';

		$per_page = 20;
		$total    = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT campaign_id) FROM ( $in_period ) p" ); // phpcs:ignore WordPress.DB.PreparedSQL -- prepared above.

		// Revenue is what the tracker attributes to the campaign, as on the old report.
		$this->items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.id, c.title, c.subject, c.status, c.scheduled_at, c.started_at, c.completed_at, c.updated_at,
					c.recipient_count, c.sent_count, c.failed_count, c.opened_count, c.clicked_count,
					COALESCE(v.orders, 0) AS orders, COALESCE(v.revenue, 0) AS revenue
				FROM $campaigns c
				LEFT JOIN (
					SELECT campaign_id, COUNT(*) AS orders, SUM(CAST(url AS DECIMAL(10,2))) AS revenue
					FROM $events WHERE event_type = 'conversion' AND campaign_id IN ( $in_period )
					GROUP BY campaign_id
				) v ON v.campaign_id = c.id
				WHERE c.id IN ( $in_period )
				ORDER BY $orderby $order, c.id DESC
				LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$per_page,
				( $this->get_pagenum() - 1 ) * $per_page
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'title' );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
			)
		);
	}

	public function get_columns() {
		return array(
			'title'   => __( 'Campaign', 'backhaus-email-campaigns' ),
			'sent'    => __( 'Sent', 'backhaus-email-campaigns' ),
			'opened'  => __( 'Opened', 'backhaus-email-campaigns' ),
			'clicked' => __( 'Clicked', 'backhaus-email-campaigns' ),
			'revenue' => __( 'Revenue', 'backhaus-email-campaigns' ),
		);
	}

	protected function get_sortable_columns() {
		return array(
			'sent'    => array( 'sent', true ),
			'opened'  => array( 'opened', true ),
			'clicked' => array( 'clicked', true ),
			'revenue' => array( 'revenue', true ),
		);
	}

	/**
	 * Name, when it went out, and links to its page and full report.
	 *
	 * @param object $item Campaign row with orders and revenue.
	 * @return string
	 */
	public function column_title( $item ) {
		$link   = BHEC_Admin_Campaigns::url( array( 'action' => 'view', 'id' => $item->id ) );
		$title  = '' !== $item->title ? $item->title : __( '(No Name)', 'backhaus-email-campaigns' );
		$status = BHEC_Admin_Campaigns::status( $item );

		$out = sprintf( '<strong><a class="row-title" href="%s">%s</a></strong>', esc_url( $link ), esc_html( $title ) );
		if ( 'sent' === $status && $item->started_at ) {
			/* translators: %s: date and time */
			$out .= '<span class="bhec-status-detail">' . esc_html( sprintf( __( 'Sent %s', 'backhaus-email-campaigns' ), BHEC_Admin_Campaigns::format_local( $item->started_at ) ) ) . '</span>';
		} else {
			$out .= '<span class="bhec-status-detail">' . BHEC_Admin_Campaigns::status_badge( $status ) . ' ' . esc_html( BHEC_Admin_Campaigns::status_detail( $item, $status ) ) . '</span>';
		}

		// Phones hide the number columns, so the headline numbers sit here too.
		$out .= '<span class="bhec-status-detail bhec-report-mobile">' . esc_html(
			sprintf(
				/* translators: 1: number of emails, 2: open rate, 3: click rate */
				__( '%1$s sent, %2$s opened, %3$s clicked', 'backhaus-email-campaigns' ),
				number_format_i18n( (int) $item->sent_count ),
				BHEC_Admin_Campaigns::percent( (int) $item->opened_count, (int) $item->sent_count ),
				BHEC_Admin_Campaigns::percent( (int) $item->clicked_count, (int) $item->sent_count )
			)
		) . '</span>';

		$actions = array(
			'view'   => sprintf( '<a href="%s">%s</a>', esc_url( $link ), esc_html__( 'View Campaign', 'backhaus-email-campaigns' ) ),
			'report' => sprintf( '<a href="%s">%s</a>', esc_url( BHEC_Admin_Analytics::url( array( 'campaign_id' => $item->id ) ) ), esc_html__( 'Full Report', 'backhaus-email-campaigns' ) ),
		);
		return $out . $this->row_actions( $actions );
	}

	/**
	 * Emails sent, linking to who got it.
	 *
	 * @param object $item Campaign row.
	 * @return string
	 */
	public function column_sent( $item ) {
		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( BHEC_Admin_Campaigns::url( array( 'action' => 'view', 'id' => $item->id, 'who' => 'sent' ) ) . '#recipients' ),
			esc_html( number_format_i18n( (int) $item->sent_count ) )
		);
	}

	public function column_opened( $item ) {
		return $this->rate( (int) $item->opened_count, (int) $item->sent_count );
	}

	public function column_clicked( $item ) {
		return $this->rate( (int) $item->clicked_count, (int) $item->sent_count );
	}

	public function column_revenue( $item ) {
		$out = wp_kses_post( wc_price( (float) $item->revenue ) );
		if ( (int) $item->orders > 0 ) {
			$out .= '<span class="bhec-status-detail">' . esc_html( BHEC_Admin_Analytics::count_of( $item->orders, 'orders' ) ) . '</span>';
		}
		return $out;
	}

	/**
	 * A rate with its count underneath, as on the Campaigns list.
	 *
	 * @param int $count Opens or clicks (people).
	 * @param int $sent  Emails sent.
	 * @return string
	 */
	private function rate( $count, $sent ) {
		if ( $sent <= 0 ) {
			return '';
		}
		$out = esc_html( BHEC_Admin_Campaigns::percent( $count, $sent ) );
		if ( $count > 0 ) {
			$out .= '<span class="bhec-status-detail">' . esc_html( BHEC_Admin_Analytics::count_of( $count, 'people' ) ) . '</span>';
		}
		return $out;
	}

	public function no_items() {
		/* translators: %s: number of days */
		echo esc_html( sprintf( __( 'No campaigns went out in the last %s days.', 'backhaus-email-campaigns' ), number_format_i18n( $this->days ) ) );
	}
}
