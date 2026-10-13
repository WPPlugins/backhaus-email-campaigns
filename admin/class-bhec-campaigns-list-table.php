<?php
/**
 * The Campaigns list: a standard WordPress list table with status views,
 * search, sorting and row actions.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class BHEC_Campaigns_List_Table extends WP_List_Table {

	/** Status views and the SQL condition for each. */
	const VIEWS = array(
		'draft'     => "status = 'draft' AND scheduled_at IS NULL",
		'scheduled' => "status = 'draft' AND scheduled_at IS NOT NULL",
		'sending'   => "status IN ('queued', 'sending', 'paused')",
		'sent'      => "status = 'completed'",
		'failed'    => "status = 'failed'",
	);

	/** @var array Campaign count per view. */
	private $counts = array();

	/** @var array Held emails per campaign id on this page. */
	private $held = array();

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'campaign',
				'plural'   => 'campaigns',
				'ajax'     => false,
			)
		);
	}

	/**
	 * The view being shown.
	 *
	 * @return string
	 */
	private function current_view() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : '';
		return isset( self::VIEWS[ $view ] ) ? $view : '';
	}

	public function prepare_items() {
		global $wpdb;
		$table = $wpdb->prefix . 'bhec_campaigns';

		foreach ( self::VIEWS as $view => $condition ) {
			$this->counts[ $view ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE $condition" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		$this->counts['all'] = array_sum( $this->counts );

		// "1 = %d" keeps a value for prepare() when no filter is set.
		$where  = array( '1 = %d' );
		$params = array( 1 );
		$view   = $this->current_view();
		if ( $view ) {
			$where[] = self::VIEWS[ $view ];
		}
		// phpcs:ignore WordPress.Security.NonceVerification
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(title LIKE %s OR subject LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		$sortable = array(
			'title'      => 'title',
			'recipients' => 'recipient_count',
			'date'       => 'COALESCE(completed_at, started_at, scheduled_at, updated_at)',
		);
		// phpcs:ignore WordPress.Security.NonceVerification
		$orderby = isset( $_GET['orderby'], $sortable[ $_GET['orderby'] ] ) ? $sortable[ sanitize_key( $_GET['orderby'] ) ] : 'id';
		// phpcs:ignore WordPress.Security.NonceVerification
		$order = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( $_GET['order'] ) ) ? 'ASC' : 'DESC';

		$per_page = $this->get_items_per_page( 'bhec_campaigns_per_page', 20 );
		$offset   = ( $this->get_pagenum() - 1 ) * $per_page;
		$where    = implode( ' AND ', $where );

		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE $where", $params ) // phpcs:ignore WordPress.DB.PreparedSQL -- conditions built above from fixed text and placeholders.
		);
		$this->items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE $where ORDER BY $orderby $order, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL
				array_merge( $params, array( $per_page, $offset ) )
			)
		);

		// Held emails need the admin's decision, so the list shows them.
		$ids = array_values( array_map( 'intval', wp_list_pluck( $this->items, 'id' ) ) );
		if ( $ids ) {
			$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
			$held         = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one placeholder per value.
					"SELECT campaign_id, COUNT(*) AS n FROM {$wpdb->prefix}bhec_queue WHERE status = 'held' AND campaign_id IN ( $placeholders ) GROUP BY campaign_id",
					$ids
				)
			);
			$this->held = wp_list_pluck( $held, 'n', 'campaign_id' );
		}

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
			'title'      => __( 'Campaign', 'backhaus-email-campaigns' ),
			'status'     => __( 'Status', 'backhaus-email-campaigns' ),
			'recipients' => __( 'Recipients', 'backhaus-email-campaigns' ),
			'opened'     => __( 'Opened', 'backhaus-email-campaigns' ),
			'clicked'    => __( 'Clicked', 'backhaus-email-campaigns' ),
		);
	}

	protected function get_sortable_columns() {
		return array(
			'title'      => array( 'title', false ),
			'recipients' => array( 'recipients', true ),
		);
	}

	protected function get_views() {
		$labels = array(
			/* translators: %s: number of campaigns */
			'all'       => _n_noop( 'All <span class="count">(%s)</span>', 'All <span class="count">(%s)</span>', 'backhaus-email-campaigns' ),
			/* translators: %s: number of campaigns */
			'draft'     => _n_noop( 'Draft <span class="count">(%s)</span>', 'Drafts <span class="count">(%s)</span>', 'backhaus-email-campaigns' ),
			/* translators: %s: number of campaigns */
			'scheduled' => _n_noop( 'Scheduled <span class="count">(%s)</span>', 'Scheduled <span class="count">(%s)</span>', 'backhaus-email-campaigns' ),
			/* translators: %s: number of campaigns */
			'sending'   => _n_noop( 'Sending <span class="count">(%s)</span>', 'Sending <span class="count">(%s)</span>', 'backhaus-email-campaigns' ),
			/* translators: %s: number of campaigns */
			'sent'      => _n_noop( 'Sent <span class="count">(%s)</span>', 'Sent <span class="count">(%s)</span>', 'backhaus-email-campaigns' ),
			/* translators: %s: number of campaigns */
			'failed'    => _n_noop( 'Failed <span class="count">(%s)</span>', 'Failed <span class="count">(%s)</span>', 'backhaus-email-campaigns' ),
		);

		$current = $this->current_view();
		$views   = array();
		foreach ( $labels as $view => $label ) {
			$count = $this->counts[ $view ] ?? 0;
			if ( 'all' !== $view && 0 === $count ) {
				continue;
			}
			$url            = 'all' === $view ? BHEC_Admin_Campaigns::url() : BHEC_Admin_Campaigns::url( array( 'view' => $view ) );
			$views[ $view ] = sprintf(
				'<a href="%s"%s>%s</a>',
				esc_url( $url ),
				( $view === $current || ( 'all' === $view && '' === $current ) ) ? ' class="current" aria-current="page"' : '',
				sprintf( translate_nooped_plural( $label, $count, 'backhaus-email-campaigns' ), number_format_i18n( $count ) )
			);
		}
		return $views;
	}

	/**
	 * Campaign name, subject and row actions.
	 *
	 * @param object $item Campaign row.
	 * @return string
	 */
	public function column_title( $item ) {
		$status  = BHEC_Admin_Campaigns::status( $item );
		$editing = in_array( $status, array( 'draft', 'scheduled', 'failed' ), true );
		$link    = $editing ? BHEC_Admin_Campaigns::url( array( 'action' => 'edit', 'id' => $item->id ) ) : BHEC_Admin_Campaigns::url( array( 'action' => 'view', 'id' => $item->id ) );
		$title   = '' !== $item->title ? $item->title : __( '(No Name)', 'backhaus-email-campaigns' );

		$out = sprintf( '<strong><a class="row-title" href="%s">%s</a></strong>', esc_url( $link ), esc_html( $title ) );
		if ( '' !== $item->subject ) {
			$out .= '<span class="bhec-row-subject">' . esc_html( $item->subject ) . '</span>';
		}
		// Phones hide the Status column, so the badge also sits here.
		$out .= '<span class="bhec-mobile-status">' . BHEC_Admin_Campaigns::status_badge( $status ) . '</span>';

		$actions = array();
		if ( $editing ) {
			$actions['edit'] = sprintf( '<a href="%s">%s</a>', esc_url( $link ), esc_html__( 'Edit', 'backhaus-email-campaigns' ) );
		} else {
			$actions['view'] = sprintf( '<a href="%s">%s</a>', esc_url( $link ), esc_html__( 'View', 'backhaus-email-campaigns' ) );
		}
		$actions['duplicate'] = sprintf(
			'<button type="button" class="button-link bhec-duplicate-campaign" data-id="%d">%s</button>',
			(int) $item->id,
			esc_html__( 'Duplicate', 'backhaus-email-campaigns' )
		);
		if ( $editing ) {
			$actions['delete'] = sprintf(
				'<button type="button" class="button-link bhec-delete-campaign" data-id="%d" data-title="%s">%s</button>',
				(int) $item->id,
				esc_attr( $title ),
				esc_html__( 'Delete', 'backhaus-email-campaigns' )
			);
		}

		return $out . $this->row_actions( $actions );
	}

	/**
	 * Status label with one line of detail, and a progress bar while sending.
	 *
	 * @param object $item Campaign row.
	 * @return string
	 */
	public function column_status( $item ) {
		$status = BHEC_Admin_Campaigns::status( $item );
		$out    = BHEC_Admin_Campaigns::status_badge( $status );
		$detail = BHEC_Admin_Campaigns::status_detail( $item, $status );
		if ( in_array( $status, array( 'sending', 'paused' ), true ) && $item->recipient_count > 0 ) {
			$out .= BHEC_Admin_Campaigns::progress_bar( (int) $item->sent_count + (int) $item->failed_count, (int) $item->recipient_count );
		}
		if ( '' !== $detail ) {
			$out .= '<span class="bhec-status-detail">' . esc_html( $detail ) . '</span>';
		}
		$held = (int) ( $this->held[ $item->id ] ?? 0 );
		if ( $held ) {
			$out .= sprintf(
				'<a class="bhec-status-detail bhec-held-link" href="%s">%s</a>',
				esc_url( BHEC_Admin_Campaigns::url( array( 'action' => 'view', 'id' => $item->id, 'who' => 'held' ) ) . '#recipients' ),
				/* translators: %s: number of emails */
				esc_html( sprintf( _n( '%s held back', '%s held back', $held, 'backhaus-email-campaigns' ), number_format_i18n( $held ) ) )
			);
		}
		return $out;
	}

	public function column_recipients( $item ) {
		return in_array( BHEC_Admin_Campaigns::status( $item ), array( 'draft', 'scheduled' ), true ) || ! $item->recipient_count
			? ''
			: esc_html( number_format_i18n( $item->recipient_count ) );
	}

	public function column_opened( $item ) {
		return $this->rate( $item->opened_count, $item->sent_count );
	}

	public function column_clicked( $item ) {
		return $this->rate( $item->clicked_count, $item->sent_count );
	}

	/**
	 * A rate with its count underneath, or nothing before anything was sent.
	 *
	 * @param int $count Opens or clicks.
	 * @param int $sent  Emails sent.
	 * @return string
	 */
	private function rate( $count, $sent ) {
		if ( (int) $sent <= 0 ) {
			return '';
		}
		$out = esc_html( BHEC_Admin_Campaigns::percent( $count, $sent ) );
		if ( $count > 0 ) {
			/* translators: %s: number of people */
			$out .= '<span class="bhec-status-detail">' . esc_html( sprintf( _n( '%s person', '%s people', (int) $count, 'backhaus-email-campaigns' ), number_format_i18n( (int) $count ) ) ) . '</span>';
		}
		return $out;
	}

	public function no_items() {
		if ( $this->counts['all'] > 0 ) {
			esc_html_e( 'No campaigns match.', 'backhaus-email-campaigns' );
			return;
		}
		// The first campaign: three steps and a layout to start from.
		echo '<div class="bhec-empty bhec-first">';
		echo '<p class="bhec-empty-title">' . esc_html__( 'Send Your First Campaign', 'backhaus-email-campaigns' ) . '</p>';
		echo '<p>' . esc_html__( 'A campaign is one email you send to your customers, like a sale announcement or a new arrivals update.', 'backhaus-email-campaigns' ) . '</p>';
		echo '<ol class="bhec-first-steps">';
		printf(
			/* translators: %s: link to Settings, Status */
			'<li>' . esc_html__( 'Check that %s says your email is working.', 'backhaus-email-campaigns' ) . '</li>',
			'<a href="' . esc_url( BHEC_Admin_Settings::url( 'status' ) ) . '">' . esc_html__( 'Settings, Status', 'backhaus-email-campaigns' ) . '</a>'
		);
		echo '<li>' . esc_html__( 'Pick a layout below. Every word can be changed.', 'backhaus-email-campaigns' ) . '</li>';
		echo '<li>' . esc_html__( 'Send yourself a test from the editor, then send it or schedule it.', 'backhaus-email-campaigns' ) . '</li>';
		echo '</ol>';
		echo '<div class="bhec-starters">';
		foreach ( BHEC_Campaign_Starters::all() as $key => $layout ) {
			printf(
				'<a class="bhec-starter" href="%s"><span class="bhec-starter-name">%s</span><span class="bhec-starter-about">%s</span></a>',
				esc_url( BHEC_Admin_Campaigns::url( array( 'action' => 'new', 'starter' => $key ) ) ),
				esc_html( $layout['name'] ),
				esc_html( $layout['about'] )
			);
		}
		printf(
			'<a class="bhec-starter" href="%s"><span class="bhec-starter-name">%s</span><span class="bhec-starter-about">%s</span></a>',
			esc_url( BHEC_Admin_Campaigns::url( array( 'action' => 'new' ) ) ),
			esc_html__( 'Blank', 'backhaus-email-campaigns' ),
			esc_html__( 'Start from an empty message.', 'backhaus-email-campaigns' )
		);
		echo '</div></div>';
	}
}
