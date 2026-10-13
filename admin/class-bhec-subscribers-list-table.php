<?php
/**
 * The Subscribers list: everyone the plugin knows, with their subscription
 * state as views, search, sorting, and subscribe/unsubscribe actions.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class BHEC_Subscribers_List_Table extends WP_List_Table {

	/** @var array People per state, plus 'all'. */
	private $counts = array();

	/** @var array Lowercase email => when they last got an email (store-local). */
	private $last_emails = array();

	/** @var int Row number, for checkbox ids. */
	private $row = 0;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'subscriber',
				'plural'   => 'subscribers',
				'ajax'     => false,
			)
		);
	}

	/**
	 * The view being shown.
	 *
	 * @return string
	 */
	public function current_view() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : '';
		return isset( BHEC_Admin_Customers::states()[ $view ] ) ? $view : '';
	}

	/**
	 * @return string
	 */
	public function current_search() {
		// phpcs:ignore WordPress.Security.NonceVerification
		return isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
	}

	/**
	 * Whether the store has anyone at all.
	 *
	 * @return bool
	 */
	public function has_people() {
		return ! empty( $this->counts['all'] );
	}

	public function prepare_items() {
		$this->counts = BHEC_Admin_Customers::counts();
		$view         = $this->current_view();
		$search       = $this->current_search();

		// phpcs:disable WordPress.Security.NonceVerification
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'email';
		$order   = isset( $_GET['order'] ) && 'desc' === strtolower( sanitize_key( $_GET['order'] ) ) ? 'DESC' : 'ASC';
		// phpcs:enable

		$per_page = $this->get_items_per_page( 'bhec_subscribers_per_page', 25 );
		$total    = '' === $search
			? (int) $this->counts[ $view ? $view : 'all' ]
			: BHEC_Admin_Customers::query_people( array( 'view' => $view, 'search' => $search, 'count' => true ) );

		$this->items = BHEC_Admin_Customers::query_people(
			array(
				'view'    => $view,
				'search'  => $search,
				'orderby' => $orderby,
				'order'   => $order,
				'limit'   => $per_page,
				'offset'  => ( $this->get_pagenum() - 1 ) * $per_page,
			)
		);

		// Sign-ups with an account but no order have no billing name.
		foreach ( $this->items as $item ) {
			if ( '' === trim( (string) $item->name ) && $item->customer_id ) {
				$user = get_userdata( (int) $item->customer_id );
				if ( $user ) {
					$item->name = trim( $user->first_name . ' ' . $user->last_name );
				}
			}
		}
		$this->last_emails = BHEC_Admin_Customers::last_emails( wp_list_pluck( $this->items, 'email' ) );

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'email' );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
			)
		);
	}

	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'email'      => __( 'Email', 'backhaus-email-campaigns' ),
			'status'     => __( 'Subscription', 'backhaus-email-campaigns' ),
			'source'     => __( 'Source', 'backhaus-email-campaigns' ),
			'orders'     => __( 'Orders', 'backhaus-email-campaigns' ),
			'spent'      => __( 'Total Spent', 'backhaus-email-campaigns' ),
			'last_email' => __( 'Last Email', 'backhaus-email-campaigns' ),
		);
	}

	protected function get_sortable_columns() {
		return array(
			'email'  => array( 'email', false ),
			'orders' => array( 'orders', true ),
			'spent'  => array( 'spent', true ),
		);
	}

	protected function get_bulk_actions() {
		return array(
			'subscribe'   => __( 'Subscribe', 'backhaus-email-campaigns' ),
			'unsubscribe' => __( 'Unsubscribe', 'backhaus-email-campaigns' ),
		);
	}

	protected function get_views() {
		$labels = array(
			/* translators: %s: number of people */
			'all'            => _n_noop( 'All <span class="count">(%s)</span>', 'All <span class="count">(%s)</span>', 'backhaus-email-campaigns' ),
			/* translators: %s: number of people */
			'subscribed'     => _n_noop( 'Subscribed <span class="count">(%s)</span>', 'Subscribed <span class="count">(%s)</span>', 'backhaus-email-campaigns' ),
			/* translators: %s: number of people */
			'pending'        => _n_noop( 'Waiting for Confirmation <span class="count">(%s)</span>', 'Waiting for Confirmation <span class="count">(%s)</span>', 'backhaus-email-campaigns' ),
			/* translators: %s: number of people */
			'unsubscribed'   => _n_noop( 'Unsubscribed <span class="count">(%s)</span>', 'Unsubscribed <span class="count">(%s)</span>', 'backhaus-email-campaigns' ),
			/* translators: %s: number of people */
			'bounced'        => _n_noop( 'Bounced <span class="count">(%s)</span>', 'Bounced <span class="count">(%s)</span>', 'backhaus-email-campaigns' ),
			/* translators: %s: number of people */
			'not_subscribed' => _n_noop( 'Not Subscribed <span class="count">(%s)</span>', 'Not Subscribed <span class="count">(%s)</span>', 'backhaus-email-campaigns' ),
		);

		$current = $this->current_view();
		$views   = array();
		foreach ( $labels as $view => $label ) {
			$count = $this->counts[ $view ] ?? 0;
			if ( 'all' !== $view && 0 === $count && $view !== $current ) {
				continue;
			}
			$url            = 'all' === $view ? BHEC_Admin_Customers::url() : BHEC_Admin_Customers::url( array( 'view' => $view ) );
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
	 * @param object $item Person row.
	 * @return string
	 */
	public function column_cb( $item ) {
		$this->row++;
		return sprintf(
			'<label class="screen-reader-text" for="bhec-sub-%1$d">%2$s</label><input type="checkbox" id="bhec-sub-%1$d" name="email[]" value="%3$s" data-state="%4$s" data-can-subscribe="%5$d">',
			$this->row,
			/* translators: %s: email address */
			esc_html( sprintf( __( 'Select %s', 'backhaus-email-campaigns' ), $item->email ) ),
			esc_attr( $item->email ),
			esc_attr( $item->state ),
			BHEC_Admin_Customers::can_subscribe( $item ) ? 1 : 0
		);
	}

	/**
	 * Email, name and row actions. The email opens their history.
	 *
	 * @param object $item Person row.
	 * @return string
	 */
	public function column_email( $item ) {
		$name = trim( (string) $item->name );
		$data = sprintf( ' data-email="%s" data-name="%s" data-state="%s"', esc_attr( $item->email ), esc_attr( $name ), esc_attr( $item->state ) );

		$out = sprintf( '<strong><button type="button" class="button-link row-title bhec-sub-history"%s>%s</button></strong>', $data, esc_html( $item->email ) );
		if ( '' !== $name ) {
			$out .= '<span class="bhec-row-subject">' . esc_html( $name ) . '</span>';
		}
		// Phones hide the Subscription column, so the badge also sits here.
		$out .= '<span class="bhec-mobile-status">' . BHEC_Admin_Customers::status_badge( $item->state ) . '</span>';

		$actions            = array();
		$actions['history'] = sprintf( '<button type="button" class="button-link bhec-sub-history"%s>%s</button>', $data, esc_html__( 'Email History', 'backhaus-email-campaigns' ) );
		if ( 'subscribed' !== $item->state && BHEC_Admin_Customers::can_subscribe( $item ) ) {
			$actions['subscribe'] = sprintf( '<button type="button" class="button-link bhec-sub-action" data-action="subscribe"%s>%s</button>', $data, esc_html__( 'Subscribe', 'backhaus-email-campaigns' ) );
		}
		if ( in_array( $item->state, array( 'subscribed', 'pending' ), true ) ) {
			$actions['unsubscribe'] = sprintf( '<button type="button" class="button-link bhec-sub-action" data-action="unsubscribe"%s>%s</button>', $data, esc_html__( 'Unsubscribe', 'backhaus-email-campaigns' ) );
		}
		if ( $item->customer_id ) {
			$actions['customer'] = sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'user-edit.php?user_id=' . (int) $item->customer_id ) ), esc_html__( 'Customer Profile', 'backhaus-email-campaigns' ) );
		}
		return $out . $this->row_actions( $actions );
	}

	/**
	 * State, since when, and why Subscribe isn't offered after an opt-out.
	 *
	 * @param object $item Person row.
	 * @return string
	 */
	public function column_status( $item ) {
		$out    = BHEC_Admin_Customers::status_badge( $item->state );
		$detail = BHEC_Admin_Customers::status_detail( $item->state, $item );
		if ( '' !== $detail ) {
			$out .= '<span class="bhec-status-detail">' . esc_html( $detail ) . '</span>';
		}
		if ( 'subscribed' !== $item->state && ! BHEC_Admin_Customers::can_subscribe( $item ) ) {
			$why = 'pending' === $item->state ? __( 'Only they can confirm it', 'backhaus-email-campaigns' ) : __( 'Only they can opt back in', 'backhaus-email-campaigns' );
			$out .= '<span class="bhec-status-detail">' . esc_html( $why ) . '</span>';
		}
		return $out;
	}

	public function column_source( $item ) {
		return esc_html( BHEC_Admin_Customers::source_label( $item->source ) );
	}

	/**
	 * Paid orders, linked to their orders in WooCommerce.
	 *
	 * @param object $item Person row.
	 * @return string
	 */
	public function column_orders( $item ) {
		$orders = (int) $item->orders;
		if ( ! $orders ) {
			return '';
		}
		$base = BHEC_Customer_Query::is_hpos_enabled() ? admin_url( 'admin.php?page=wc-orders' ) : admin_url( 'edit.php?post_type=shop_order' );
		return sprintf( '<a href="%s">%s</a>', esc_url( add_query_arg( 's', rawurlencode( $item->email ), $base ) ), esc_html( number_format_i18n( $orders ) ) );
	}

	public function column_spent( $item ) {
		return (int) $item->orders ? wp_kses_post( wc_price( $item->spent ) ) : '';
	}

	public function column_last_email( $item ) {
		$when = $this->last_emails[ strtolower( $item->email ) ] ?? '';
		return $when ? esc_html( BHEC_Admin_Campaigns::format_local( $when ) ) : '';
	}

	public function no_items() {
		if ( $this->has_people() ) {
			esc_html_e( 'Nobody matches.', 'backhaus-email-campaigns' );
			return;
		}
		echo '<div class="bhec-empty">';
		echo '<p class="bhec-empty-title">' . esc_html__( 'No subscribers yet', 'backhaus-email-campaigns' ) . '</p>';
		echo '<p>' . esc_html__( 'Everyone who orders from your store or signs up for your emails shows up here, with whether they get your marketing email.', 'backhaus-email-campaigns' ) . '</p>';
		echo '<p>' . wp_kses(
			sprintf(
				/* translators: %s: shortcode */
				__( 'Add a sign-up form to any page with the %s shortcode.', 'backhaus-email-campaigns' ),
				'<code>[bhec_signup]</code>'
			),
			array( 'code' => array() )
		) . '</p>';
		echo '<p>' . esc_html__( 'Moving from another email tool? Import its unsubscribe list first, so nobody who opted out gets emailed.', 'backhaus-email-campaigns' ) . '</p>';
		printf( '<p><button type="button" class="button button-primary bhec-open-import">%s</button></p>', esc_html__( 'Import Opt-Outs', 'backhaus-email-campaigns' ) );
		echo '</div>';
	}
}
