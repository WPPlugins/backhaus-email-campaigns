<?php
/**
 * Subscribers: everyone the plugin knows (customers and sign-ups) and
 * whether they get marketing email.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Admin_Customers {

	/** Emails shown in a person's history. */
	const HISTORY_LIMIT = 50;

	/**
	 * Render the Subscribers page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'backhaus-email-campaigns' ) );
		}
		require_once BHEC_PLUGIN_DIR . 'admin/class-bhec-subscribers-list-table.php';
		include BHEC_PLUGIN_DIR . 'admin/views/page-customers.php';
	}

	/**
	 * Link to the Subscribers page.
	 *
	 * @param array $args Query args, such as view.
	 * @return string
	 */
	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'bhec-customers' ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * The flash arguments come off the address bar once the notice shows.
	 */
	public static function enqueue_assets() {
		add_filter(
			'removable_query_args',
			function ( $args ) {
				return array_merge( $args, array( 'count', 'skipped' ) );
			}
		);
	}

	/**
	 * Data for bhec-subscribers.js (as bhecPage).
	 *
	 * @return array
	 */
	public static function script_data() {
		$back_in = class_exists( 'BHEC_Double_Optin' )
			? __( 'Only they can opt back in, from My Account or by ticking the box at checkout.', 'backhaus-email-campaigns' )
			: __( 'Only they can opt back in, from My Account.', 'backhaus-email-campaigns' );

		return array(
			'listUrl' => self::url(),
			'i18n'    => array(
				'error'           => __( 'Something went wrong. Please try again.', 'backhaus-email-campaigns' ),
				'selectFirst'     => __( 'Tick the people you want to change first.', 'backhaus-email-campaigns' ),
				'subscribeTitle'  => __( 'Subscribe This Person?', 'backhaus-email-campaigns' ),
				/* translators: %s: number of people */
				'subscribeMany'   => __( 'Subscribe %s People?', 'backhaus-email-campaigns' ),
				/* translators: %s: email address */
				'subscribeOne'    => __( '%s will start getting your marketing email. Only do this if they asked you to.', 'backhaus-email-campaigns' ),
				'subscribeThem'   => __( 'They will start getting your marketing email. Only do this if they asked you to.', 'backhaus-email-campaigns' ),
				/* translators: %s: email address */
				'pendingOne'      => __( '%s signed up but hasn\'t clicked the link in the confirmation email yet. Subscribe them only if they asked you to.', 'backhaus-email-campaigns' ),
				'unsubscribeTitle' => __( 'Unsubscribe This Person?', 'backhaus-email-campaigns' ),
				/* translators: %s: number of people */
				'unsubscribeMany' => __( 'Unsubscribe %s People?', 'backhaus-email-campaigns' ),
				/* translators: %s: email address */
				'unsubscribeOne'  => __( '%s won\'t get any more marketing email.', 'backhaus-email-campaigns' ),
				'unsubscribeThem' => __( 'They won\'t get any more marketing email.', 'backhaus-email-campaigns' ),
				'backIn'          => $back_in,
				'blockedOne'      => __( 'One of them opted out, so only they can opt back in. They\'re left as they are.', 'backhaus-email-campaigns' ),
				/* translators: %s: number of people */
				'blockedMany'     => __( '%s of them opted out, so only they can opt back in. They\'re left as they are.', 'backhaus-email-campaigns' ),
				'nobodySubTitle'  => __( 'Nobody To Subscribe', 'backhaus-email-campaigns' ),
				'nobodySub'       => __( 'Everyone you picked is already subscribed or opted out.', 'backhaus-email-campaigns' ) . ' ' . $back_in,
				'nobodyUnsubTitle' => __( 'Nobody To Unsubscribe', 'backhaus-email-campaigns' ),
				'nobodyUnsub'     => __( 'Everyone you picked is already unsubscribed.', 'backhaus-email-campaigns' ),
				'subscribe'       => __( 'Subscribe', 'backhaus-email-campaigns' ),
				/* translators: %s: number of people */
				'subscribeButton' => __( 'Subscribe %s People', 'backhaus-email-campaigns' ),
				'unsubscribe'     => __( 'Unsubscribe', 'backhaus-email-campaigns' ),
				/* translators: %s: number of people */
				'unsubscribeButton' => __( 'Unsubscribe %s People', 'backhaus-email-campaigns' ),
				'close'           => __( 'Close', 'backhaus-email-campaigns' ),
				'loading'         => __( 'Loading...', 'backhaus-email-campaigns' ),
				'colEmail'        => __( 'Email', 'backhaus-email-campaigns' ),
				'colWhen'         => __( 'When', 'backhaus-email-campaigns' ),
				'colOpened'       => __( 'Opened', 'backhaus-email-campaigns' ),
				'colClicked'      => __( 'Clicked', 'backhaus-email-campaigns' ),
				'yes'             => __( 'Yes', 'backhaus-email-campaigns' ),
				'no'              => __( 'No', 'backhaus-email-campaigns' ),
				'noEmails'        => __( 'No campaigns or automatic emails yet.', 'backhaus-email-campaigns' ),
				'trackingOff'     => __( 'Open and click tracking is off in Settings, so new opens and clicks aren\'t recorded.', 'backhaus-email-campaigns' ),
				/* translators: %s: number of emails */
				'latest'          => __( 'Showing the latest %s.', 'backhaus-email-campaigns' ),
				'profile'         => __( 'Customer Profile', 'backhaus-email-campaigns' ),
				'sourceLabel'     => __( 'Source', 'backhaus-email-campaigns' ),
				'notEmail'        => __( 'That isn\'t an email address. Check it and try again.', 'backhaus-email-campaigns' ),
				'chooseFile'      => __( 'Choose a file first.', 'backhaus-email-campaigns' ),
				'importing'       => __( 'Importing...', 'backhaus-email-campaigns' ),
			),
		);
	}

	/**
	 * Confirmation after an action that reloads the page (?done=...).
	 */
	public static function render_flash() {
		// phpcs:disable WordPress.Security.NonceVerification
		$done    = isset( $_GET['done'] ) ? sanitize_key( $_GET['done'] ) : '';
		$count   = isset( $_GET['count'] ) ? absint( $_GET['count'] ) : 0;
		$skipped = isset( $_GET['skipped'] ) ? absint( $_GET['skipped'] ) : 0;
		// phpcs:enable

		$lines = array();
		if ( 'subscribed' === $done ) {
			/* translators: %s: number of people */
			$lines[] = sprintf( _n( '%s person subscribed.', '%s people subscribed.', $count, 'backhaus-email-campaigns' ), number_format_i18n( $count ) );
			if ( $skipped ) {
				/* translators: %s: number of people */
				$lines[] = sprintf( _n( '%s person was left as they are: they opted out, so only they can opt back in.', '%s people were left as they are: they opted out, so only they can opt back in.', $skipped, 'backhaus-email-campaigns' ), number_format_i18n( $skipped ) );
			}
		} elseif ( 'unsubscribed' === $done ) {
			if ( $count ) {
				/* translators: %s: number of people */
				$lines[] = sprintf( _n( '%s person unsubscribed.', '%s people unsubscribed.', $count, 'backhaus-email-campaigns' ), number_format_i18n( $count ) );
			}
			if ( $skipped ) {
				/* translators: %s: number of people */
				$lines[] = sprintf( _n( '%s person was already unsubscribed, so nothing changed.', '%s people were already unsubscribed, so nothing changed.', $skipped, 'backhaus-email-campaigns' ), number_format_i18n( $skipped ) );
			}
		}
		if ( $lines ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( implode( ' ', $lines ) ) );
		}
	}

	/**
	 * Whether a customer with no subscription record gets marketing email.
	 *
	 * @return bool
	 */
	public static function auto_mode() {
		return 'auto' === get_option( 'bhec_optin_mode', 'auto' );
	}

	/**
	 * Subscription states people see, one per view.
	 *
	 * @return array Key => label.
	 */
	public static function states() {
		return array(
			'subscribed'     => __( 'Subscribed', 'backhaus-email-campaigns' ),
			'pending'        => __( 'Waiting for Confirmation', 'backhaus-email-campaigns' ),
			'unsubscribed'   => __( 'Unsubscribed', 'backhaus-email-campaigns' ),
			'bounced'        => __( 'Bounced', 'backhaus-email-campaigns' ),
			'not_subscribed' => __( 'Not Subscribed', 'backhaus-email-campaigns' ),
		);
	}

	/**
	 * The state for a subscription record, matching BHEC_Subscription::is_subscribed():
	 * only 'subscribed' (or no record in auto mode) gets marketing email. An
	 * 'unsubscribed' row without an opt-out time never opted in or out.
	 *
	 * @param object|null $sub Row with status and opted_out_at, or null for no record.
	 * @return string
	 */
	public static function state_of( $sub ) {
		if ( ! $sub || null === $sub->status ) {
			return self::auto_mode() ? 'subscribed' : 'not_subscribed';
		}
		if ( in_array( $sub->status, array( 'subscribed', 'pending', 'bounced' ), true ) ) {
			return $sub->status;
		}
		if ( 'unsubscribed' === $sub->status && ! empty( $sub->opted_out_at ) ) {
			return 'unsubscribed';
		}
		return 'not_subscribed';
	}

	/**
	 * The same as state_of(), in SQL on a subscriptions row aliased `s`.
	 *
	 * @return string
	 */
	private static function state_sql() {
		$none = self::auto_mode() ? 'subscribed' : 'not_subscribed';
		return "CASE
			WHEN s.id IS NULL THEN '$none'
			WHEN s.status IN ('subscribed', 'pending', 'bounced') THEN s.status
			WHEN s.status = 'unsubscribed' AND s.opted_out_at IS NOT NULL THEN 'unsubscribed'
			ELSE 'not_subscribed' END";
	}

	/**
	 * Whether an admin may subscribe this person. Mirrors the admin path in
	 * BHEC_Subscription::subscribe(): no record, a record that never opted in or
	 * out, a pending sign-up that doesn't follow an opt-out, or a bounced
	 * address. An opt-out is only lifted by the person.
	 *
	 * @param object|null $sub Row with status and opted_out_at, or null for no record.
	 * @return bool
	 */
	public static function can_subscribe( $sub ) {
		if ( ! $sub || null === $sub->status ) {
			return true;
		}
		return 'bounced' === $sub->status || ( in_array( $sub->status, array( 'unsubscribed', 'pending' ), true ) && empty( $sub->opted_out_at ) );
	}

	/**
	 * Badge color for a state (bhec-badge-{tone}).
	 *
	 * @param string $state From state_of().
	 * @return string
	 */
	public static function tone( $state ) {
		$tones = array(
			'subscribed'   => 'good',
			'pending'      => 'warn',
			'unsubscribed' => 'bad',
			'bounced'      => 'bad',
		);
		return $tones[ $state ] ?? 'none';
	}

	/**
	 * @param string $state From state_of().
	 * @return string HTML.
	 */
	public static function status_badge( $state ) {
		return sprintf(
			'<span class="bhec-badge bhec-badge-%s">%s</span>',
			esc_attr( self::tone( $state ) ),
			esc_html( self::states()[ $state ] ?? $state )
		);
	}

	/**
	 * One line under the status: since when, or why.
	 *
	 * @param string      $state From state_of().
	 * @param object|null $sub   Subscription row, or null for no record.
	 * @return string Plain text.
	 */
	public static function status_detail( $state, $sub ) {
		$has_row = $sub && null !== $sub->status;
		switch ( $state ) {
			case 'subscribed':
				if ( $has_row && ! empty( $sub->opted_in_at ) ) {
					/* translators: %s: date and time */
					return sprintf( __( 'Since %s', 'backhaus-email-campaigns' ), BHEC_Admin_Campaigns::format_local( $sub->opted_in_at ) );
				}
				return __( 'Automatically, as a customer', 'backhaus-email-campaigns' );
			case 'pending':
				if ( ! empty( $sub->opted_out_at ) ) {
					/* translators: %s: date and time */
					return sprintf( __( 'Opted out %s, then asked to rejoin', 'backhaus-email-campaigns' ), BHEC_Admin_Campaigns::format_local( $sub->opted_out_at ) );
				}
				return __( 'Hasn\'t clicked the confirmation link yet', 'backhaus-email-campaigns' );
			case 'unsubscribed':
				/* translators: %s: date and time */
				return sprintf( __( 'Opted out %s', 'backhaus-email-campaigns' ), BHEC_Admin_Campaigns::format_local( $sub->opted_out_at ) );
			case 'bounced':
				return __( 'Their mail service said this mailbox doesn\'t exist, so nothing more is sent. Subscribe them again once it\'s fixed.', 'backhaus-email-campaigns' );
		}
		return __( 'Hasn\'t opted in', 'backhaus-email-campaigns' );
	}

	/**
	 * Where someone came from, in plain words.
	 *
	 * @param string|null $source Subscription source, or null for no record.
	 * @return string
	 */
	public static function source_label( $source ) {
		$labels = array(
			'auto'              => __( 'Checkout', 'backhaus-email-campaigns' ),
			'checkout'          => __( 'Checkout', 'backhaus-email-campaigns' ),
			'myaccount'         => __( 'My Account', 'backhaus-email-campaigns' ),
			'myaccount_billing' => __( 'My Account', 'backhaus-email-campaigns' ),
			'signup_form'       => __( 'Sign-Up Form', 'backhaus-email-campaigns' ),
			'import'            => __( 'Import', 'backhaus-email-campaigns' ),
			'admin'             => __( 'Admin', 'backhaus-email-campaigns' ),
			'erasure'           => __( 'Erasure Request', 'backhaus-email-campaigns' ),
			'bounce'            => __( 'Failed Send', 'backhaus-email-campaigns' ),
		);
		if ( null === $source || '' === $source ) {
			return $labels['checkout'];
		}
		return $labels[ $source ] ?? ucwords( str_replace( '_', ' ', $source ) );
	}

	/**
	 * Everyone the plugin knows, one row per address: customers with a paid
	 * order (the statuses campaigns go to) and every subscription record,
	 * with their orders, spend and state.
	 *
	 * @return string SQL with no placeholders.
	 */
	private static function people_sql() {
		global $wpdb;
		$subs = $wpdb->prefix . 'bhec_subscriptions';
		$paid = "'wc-completed', 'wc-processing', 'wc-on-hold'";

		if ( BHEC_Customer_Query::is_hpos_enabled() ) {
			$orders = "SELECT addr.email AS email,
					MAX(CONCAT_WS(' ', NULLIF(addr.first_name, ''), NULLIF(addr.last_name, ''))) AS name,
					MAX(o.customer_id) AS customer_id,
					COUNT(DISTINCT o.id) AS orders,
					SUM(o.total_amount) AS spent
				FROM {$wpdb->prefix}wc_orders o
				INNER JOIN {$wpdb->prefix}wc_order_addresses addr ON addr.order_id = o.id AND addr.address_type = 'billing'
				WHERE o.type = 'shop_order' AND o.status IN ($paid) AND addr.email IS NOT NULL AND addr.email <> ''
				GROUP BY addr.email";
		} else {
			$orders = "SELECT pm_email.meta_value AS email,
					MAX(CONCAT_WS(' ', NULLIF(pm_fn.meta_value, ''), NULLIF(pm_ln.meta_value, ''))) AS name,
					MAX(CAST(pm_cid.meta_value AS UNSIGNED)) AS customer_id,
					COUNT(DISTINCT p.ID) AS orders,
					SUM(CAST(pm_total.meta_value AS DECIMAL(19,4))) AS spent
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm_email ON pm_email.post_id = p.ID AND pm_email.meta_key = '_billing_email'
				LEFT JOIN {$wpdb->postmeta} pm_fn ON pm_fn.post_id = p.ID AND pm_fn.meta_key = '_billing_first_name'
				LEFT JOIN {$wpdb->postmeta} pm_ln ON pm_ln.post_id = p.ID AND pm_ln.meta_key = '_billing_last_name'
				LEFT JOIN {$wpdb->postmeta} pm_cid ON pm_cid.post_id = p.ID AND pm_cid.meta_key = '_customer_user'
				LEFT JOIN {$wpdb->postmeta} pm_total ON pm_total.post_id = p.ID AND pm_total.meta_key = '_order_total'
				WHERE p.post_type = 'shop_order' AND p.post_status IN ($paid) AND pm_email.meta_value <> ''
				GROUP BY pm_email.meta_value";
		}

		return 'SELECT pe.email, pe.name, pe.customer_id, pe.orders, COALESCE(pe.spent, 0) AS spent,
				s.status, s.source, s.opted_in_at, s.opted_out_at, ' . self::state_sql() . " AS state
			FROM (
				SELECT u.email, MAX(u.name) AS name, MAX(u.customer_id) AS customer_id, SUM(u.orders) AS orders, SUM(u.spent) AS spent
				FROM ( $orders UNION ALL SELECT email, '', customer_id, 0, 0 FROM $subs ) u
				GROUP BY u.email
			) pe
			LEFT JOIN $subs s ON s.email = pe.email";
	}

	/**
	 * Number of people in each state, plus 'all'.
	 *
	 * @return array
	 */
	public static function counts() {
		global $wpdb;
		$counts = array_fill_keys( array_keys( self::states() ), 0 );
		$people = self::people_sql();
		// "1 = %d" gives prepare() its value: the query has no input of its own.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT x.state, COUNT(*) AS n FROM ( $people ) x WHERE 1 = %d GROUP BY x.state", 1 ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- people_sql() is fixed text.
		foreach ( (array) $rows as $row ) {
			if ( isset( $counts[ $row->state ] ) ) {
				$counts[ $row->state ] = (int) $row->n;
			}
		}
		$counts['all'] = array_sum( $counts );
		return $counts;
	}

	/**
	 * People in a view, or how many there are.
	 *
	 * @param array $args {
	 *     @type string $view    A key of states(), or '' for everyone.
	 *     @type string $search  Part of an email address or name.
	 *     @type string $orderby email, orders or spent.
	 *     @type string $order   ASC or DESC.
	 *     @type int    $limit   0 for no limit.
	 *     @type int    $offset  Offset.
	 *     @type bool   $count   Return the number of matches instead.
	 * }
	 * @return object[]|int
	 */
	public static function query_people( $args = array() ) {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			array(
				'view'    => '',
				'search'  => '',
				'orderby' => 'email',
				'order'   => 'ASC',
				'limit'   => 0,
				'offset'  => 0,
				'count'   => false,
			)
		);

		// "1 = %d" keeps a value for prepare() when no filter is set.
		$where  = array( '1 = %d' );
		$params = array( 1 );
		if ( isset( self::states()[ $args['view'] ] ) ) {
			$where[]  = 'x.state = %s';
			$params[] = $args['view'];
		}
		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(x.email LIKE %s OR x.name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}
		$sql = 'FROM ( ' . self::people_sql() . ' ) x WHERE ' . implode( ' AND ', $where );

		if ( $args['count'] ) {
			$sql = 'SELECT COUNT(*) ' . $sql;
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- built above from fixed text and placeholders.
		}

		$order   = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';
		$columns = array(
			'email'  => 'x.email',
			'orders' => 'x.orders',
			'spent'  => 'x.spent',
		);
		$column  = $columns[ $args['orderby'] ] ?? 'x.email';
		$sql     = "SELECT x.* $sql ORDER BY $column $order" . ( 'x.email' !== $column ? ', x.email ASC' : '' );
		if ( $args['limit'] > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = (int) $args['limit'];
			$params[] = (int) $args['offset'];
		}
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- built above from fixed text and placeholders.
	}

	/**
	 * When each address last got a campaign or automatic email.
	 *
	 * @param string[] $emails Addresses.
	 * @return array Lowercase email => store-local datetime.
	 */
	public static function last_emails( $emails ) {
		global $wpdb;
		$last = array();
		if ( ! $emails ) {
			return $last;
		}
		$in = implode( ',', array_fill( 0, count( $emails ), '%s' ) );
		foreach ( array( 'bhec_queue', 'bhec_triggered_queue' ) as $table ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT customer_email, MAX(sent_at) AS sent_at FROM {$wpdb->prefix}$table WHERE status = 'sent' AND customer_email IN ($in) GROUP BY customer_email", // phpcs:ignore WordPress.DB.PreparedSQL
					$emails
				)
			);
			foreach ( (array) $rows as $row ) {
				$key = strtolower( $row->customer_email );
				if ( $row->sent_at && ( ! isset( $last[ $key ] ) || $row->sent_at > $last[ $key ] ) ) {
					$last[ $key ] = $row->sent_at;
				}
			}
		}
		return $last;
	}

	/**
	 * The export for a view and search: headers and rows.
	 *
	 * @param string $view   A key of states(), or ''.
	 * @param string $search Search text.
	 * @return array [ headers, rows ]
	 */
	public static function export_rows( $view, $search ) {
		$headers = array(
			__( 'Email', 'backhaus-email-campaigns' ),
			__( 'Name', 'backhaus-email-campaigns' ),
			__( 'Subscription', 'backhaus-email-campaigns' ),
			__( 'Source', 'backhaus-email-campaigns' ),
			__( 'Opted In', 'backhaus-email-campaigns' ),
			__( 'Opted Out', 'backhaus-email-campaigns' ),
			__( 'Orders', 'backhaus-email-campaigns' ),
			__( 'Total Spent', 'backhaus-email-campaigns' ),
		);
		$states  = self::states();
		$rows    = array();
		foreach ( self::query_people( array( 'view' => $view, 'search' => $search ) ) as $person ) {
			$rows[] = array(
				$person->email,
				$person->name,
				$states[ $person->state ] ?? $person->state,
				self::source_label( $person->source ),
				(string) $person->opted_in_at,
				(string) $person->opted_out_at,
				(int) $person->orders,
				wc_format_decimal( $person->spent, wc_get_price_decimals() ),
			);
		}
		return array( $headers, $rows );
	}

	/**
	 * AJAX: Import opt-out emails from CSV.
	 */
	public function ajax_import_optouts() {
		check_ajax_referer( 'bhec_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'backhaus-email-campaigns' ) );
		}

		if ( empty( $_FILES['csv_file'] ) ) {
			wp_send_json_error( __( 'No file uploaded.', 'backhaus-email-campaigns' ) );
		}

		$result = BHEC_CSV_Importer::import( $_FILES['csv_file'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked by the importer.

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$imported = (int) $result['imported'];
		$skipped  = (int) $result['skipped'];
		$invalid  = (int) ( $result['invalid'] ?? 0 );

		/* translators: %s: number of people */
		$lines = array( sprintf( _n( '%s person unsubscribed.', '%s people unsubscribed.', $imported, 'backhaus-email-campaigns' ), number_format_i18n( $imported ) ) );
		if ( $skipped ) {
			/* translators: %s: number of people */
			$lines[] = sprintf( _n( '%s was already unsubscribed.', '%s were already unsubscribed.', $skipped, 'backhaus-email-campaigns' ), number_format_i18n( $skipped ) );
		}
		if ( $invalid ) {
			/* translators: %s: number of cells */
			$lines[] = sprintf( _n( '%s other cell, such as a name or header, was ignored.', '%s other cells, such as names or headers, were ignored.', $invalid, 'backhaus-email-campaigns' ), number_format_i18n( $invalid ) );
		}

		wp_send_json_success(
			array(
				'message'  => implode( ' ', $lines ),
				'lines'    => $lines,
				'imported' => $imported,
				'skipped'  => $skipped,
				'invalid'  => $invalid,
			)
		);
	}

	/**
	 * AJAX: Toggle subscription for a single customer.
	 */
	public function ajax_toggle_subscription() {
		check_ajax_referer( 'bhec_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'backhaus-email-campaigns' ) );
		}

		$email  = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$action = isset( $_POST['sub_action'] ) ? sanitize_key( $_POST['sub_action'] ) : '';

		if ( ! is_email( $email ) ) {
			wp_send_json_error( __( 'Invalid email address.', 'backhaus-email-campaigns' ) );
		}

		if ( 'subscribe' === $action ) {
			if ( BHEC_Subscription::subscribe( $email, 0, 'admin' ) ) {
				wp_send_json_success( __( 'Customer subscribed.', 'backhaus-email-campaigns' ) );
			}
			wp_send_json_error( __( 'This customer unsubscribed or hasn\'t confirmed, so only they can opt back in: from My Account, or by ticking the box at checkout.', 'backhaus-email-campaigns' ) );
		} elseif ( 'unsubscribe' === $action ) {
			BHEC_Subscription::unsubscribe( $email );
			wp_send_json_success( __( 'Customer unsubscribed.', 'backhaus-email-campaigns' ) );
		} else {
			wp_send_json_error( __( 'Invalid action.', 'backhaus-email-campaigns' ) );
		}
	}

	/**
	 * AJAX: Unsubscribe a typed-in address, whether or not the store knows it
	 * yet (like the opt-out import, it is kept so a later order doesn't
	 * subscribe them). Someone already opted out keeps their original date.
	 */
	public function ajax_quick_unsubscribe() {
		check_ajax_referer( 'bhec_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'backhaus-email-campaigns' ) );
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! is_email( $email ) ) {
			wp_send_json_error( __( 'That isn\'t an email address. Check it and try again.', 'backhaus-email-campaigns' ) );
		}

		$sub = BHEC_Subscription::get_status( $email );
		if ( $sub && 'unsubscribed' === $sub->status && ! empty( $sub->opted_out_at ) ) {
			wp_send_json_success( array( 'changed' => false ) );
		}

		BHEC_Subscription::unsubscribe( $email );
		wp_send_json_success( array( 'changed' => true ) );
	}

	/**
	 * AJAX: One person's subscription and the campaigns and automatic emails
	 * they got, from the send queues (the send log can be off or purged).
	 */
	public function ajax_customer_history() {
		check_ajax_referer( 'bhec_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'backhaus-email-campaigns' ) );
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! is_email( $email ) ) {
			wp_send_json_error( __( 'Invalid email.', 'backhaus-email-campaigns' ) );
		}

		global $wpdb;
		$limit     = self::HISTORY_LIMIT;
		$campaigns = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT q.id, q.campaign_id, q.status, q.sent_at, q.created_at, q.held_reason, c.title, c.subject
				FROM {$wpdb->prefix}bhec_queue q LEFT JOIN {$wpdb->prefix}bhec_campaigns c ON c.id = q.campaign_id
				WHERE q.customer_email = %s ORDER BY q.id DESC LIMIT %d",
				$email,
				$limit
			)
		);
		$automatic = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.id, t.rule_id, t.status, t.sent_at, t.send_at, t.created_at, t.held_reason, r.name
				FROM {$wpdb->prefix}bhec_triggered_queue t LEFT JOIN {$wpdb->prefix}bhec_triggered_rules r ON r.id = t.rule_id
				WHERE t.customer_email = %s ORDER BY t.id DESC LIMIT %d",
				$email,
				$limit
			)
		);

		// Opens and clicks are keyed by campaign and queue row; automatic emails
		// track under the rule's id made negative.
		$seen = array();
		$ids  = array_values( array_map( 'intval', array_merge( wp_list_pluck( $campaigns, 'id' ), wp_list_pluck( $automatic, 'id' ) ) ) );
		if ( $ids ) {
			$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
			$events       = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one %d per id.
					"SELECT campaign_id, queue_id, event_type FROM {$wpdb->prefix}bhec_tracking_events WHERE queue_id IN ( $placeholders ) AND event_type IN ('open', 'click') GROUP BY campaign_id, queue_id, event_type",
					$ids
				)
			);
			foreach ( (array) $events as $event ) {
				$seen[ $event->campaign_id . ':' . $event->queue_id ][ $event->event_type ] = true;
			}
		}

		$statuses = array(
			'sent'      => __( 'Sent', 'backhaus-email-campaigns' ),
			'pending'   => __( 'Waiting to send', 'backhaus-email-campaigns' ),
			'sending'   => __( 'Sending', 'backhaus-email-campaigns' ),
			'held'      => __( 'Held back', 'backhaus-email-campaigns' ),
			'failed'    => __( 'Failed', 'backhaus-email-campaigns' ),
			'skipped'   => __( 'Skipped', 'backhaus-email-campaigns' ),
			'cancelled' => __( 'Cancelled', 'backhaus-email-campaigns' ),
		);
		$reasons  = BHEC_Send_Guard::reasons();
		$local    = function ( $datetime ) {
			$date = $datetime ? date_create_immutable( $datetime, wp_timezone() ) : false;
			return $date ? $date->getTimestamp() : 0;
		};
		$item     = function ( $row, $kind, $name, $link, $tracking_id, $when ) use ( $statuses, $reasons, $seen ) {
			$sent   = 'sent' === $row->status;
			$key    = $tracking_id . ':' . $row->id;
			$status = $statuses[ $row->status ] ?? ucfirst( $row->status );
			if ( 'held' === $row->status && isset( $reasons[ $row->held_reason ] ) ) {
				$status .= ': ' . $reasons[ $row->held_reason ];
			}
			return array(
				'kind'    => $kind,
				'name'    => $name,
				'link'    => $link,
				'sent'    => $sent,
				'status'  => $sent ? '' : $status,
				'when'    => $when ? wp_date( self::date_format( $when ), $when ) : '',
				'time'    => $when,
				'opened'  => $sent ? ! empty( $seen[ $key ]['open'] ) || ! empty( $seen[ $key ]['click'] ) : null,
				'clicked' => $sent ? ! empty( $seen[ $key ]['click'] ) : null,
			);
		};

		$emails = array();
		foreach ( $campaigns as $row ) {
			$name     = '' !== (string) $row->title ? $row->title : ( '' !== (string) $row->subject ? $row->subject : __( '(Deleted Campaign)', 'backhaus-email-campaigns' ) );
			$when     = $row->sent_at ? $local( $row->sent_at ) : BHEC_Clock::timestamp( $row->created_at );
			$link     = null !== $row->title ? BHEC_Admin_Campaigns::url( array( 'action' => 'view', 'id' => (int) $row->campaign_id ) ) : '';
			$emails[] = $item( $row, __( 'Campaign', 'backhaus-email-campaigns' ), $name, $link, (int) $row->campaign_id, $when );
		}
		foreach ( $automatic as $row ) {
			$name     = null !== $row->name ? $row->name : __( '(Deleted Automatic Email)', 'backhaus-email-campaigns' );
			$when     = $row->sent_at ? $local( $row->sent_at ) : ( 'pending' === $row->status ? $local( $row->send_at ) : BHEC_Clock::timestamp( $row->created_at ) );
			$link     = null !== $row->name ? admin_url( 'admin.php?page=bhec-triggered&action=edit&id=' . (int) $row->rule_id ) : '';
			$emails[] = $item( $row, __( 'Automatic Email', 'backhaus-email-campaigns' ), $name, $link, -1 * (int) $row->rule_id, $when );
		}
		usort(
			$emails,
			function ( $a, $b ) {
				return $b['time'] <=> $a['time'];
			}
		);
		$more   = count( $emails ) > $limit || count( $campaigns ) >= $limit || count( $automatic ) >= $limit;
		$emails = array_slice( $emails, 0, $limit );

		$sub    = BHEC_Subscription::get_status( $email );
		$state  = self::state_of( $sub );
		$name   = '';
		$user   = get_user_by( 'email', $email );
		if ( $user ) {
			$name = trim( $user->first_name . ' ' . $user->last_name );
		}

		wp_send_json_success(
			array(
				'email'    => $email,
				'name'     => $name,
				'state'    => $state,
				'tone'     => self::tone( $state ),
				'label'    => self::states()[ $state ],
				'detail'   => self::status_detail( $state, $sub ),
				'source'   => self::source_label( $sub ? $sub->source : null ),
				'profile'  => $user ? admin_url( 'user-edit.php?user_id=' . (int) $user->ID ) : '',
				'tracking' => 'yes' === get_option( 'bhec_tracking_enabled', 'yes' ),
				'emails'   => $emails,
				'more'     => $more,
				'limit'    => $limit,
			)
		);
	}

	/**
	 * The site's date and time format, without the year for this year.
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	private static function date_format( $timestamp ) {
		return wp_date( 'Y', $timestamp ) === wp_date( 'Y' )
			? 'j M, ' . get_option( 'time_format' )
			: get_option( 'date_format' ) . ', ' . get_option( 'time_format' );
	}

	/**
	 * AJAX: Bulk subscribe/unsubscribe.
	 */
	public function ajax_bulk_subscription() {
		check_ajax_referer( 'bhec_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'backhaus-email-campaigns' ) );
		}

		$emails = isset( $_POST['emails'] ) ? array_map( 'sanitize_email', wp_unslash( (array) $_POST['emails'] ) ) : array();
		$action = isset( $_POST['sub_action'] ) ? sanitize_key( $_POST['sub_action'] ) : '';

		$count   = 0;
		$skipped = 0;
		foreach ( $emails as $email ) {
			if ( ! is_email( $email ) ) {
				continue;
			}
			if ( 'subscribe' === $action ) {
				if ( ! BHEC_Subscription::subscribe( $email, 0, 'admin' ) ) {
					$skipped++;
					continue;
				}
			} elseif ( 'unsubscribe' === $action ) {
				BHEC_Subscription::unsubscribe( $email );
			}
			$count++;
		}

		if ( $skipped ) {
			$message = sprintf(
				/* translators: 1: number of customers updated, 2: number skipped */
				__( '%1$d customers updated. %2$d skipped because they unsubscribed or haven\'t confirmed; only they can opt back in.', 'backhaus-email-campaigns' ),
				$count,
				$skipped
			);
		} else {
			$message = sprintf(
				/* translators: %d: number of customers affected */
				__( '%d customers updated.', 'backhaus-email-campaigns' ),
				$count
			);
		}

		wp_send_json_success(
			array(
				'message' => $message,
				'updated' => $count,
				'skipped' => $skipped,
			)
		);
	}
}
