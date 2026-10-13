<?php
/**
 * Customer query engine — filter WooCommerce customers by various criteria.
 *
 * Supports both HPOS (High-Performance Order Storage) and legacy CPT storage.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Customer_Query {

	/**
	 * Query customers based on filter criteria.
	 *
	 * @param array $filters {
	 *     Optional filter criteria.
	 *
	 *     @type array  $order_status       Array of order statuses (e.g. ['wc-completed','wc-processing']).
	 *     @type string $date_from          Order date range start (Y-m-d).
	 *     @type string $date_to            Order date range end (Y-m-d).
	 *     @type float  $total_spent_min    Minimum lifetime spend.
	 *     @type float  $total_spent_max    Maximum lifetime spend.
	 *     @type int    $order_count_min    Minimum number of orders.
	 *     @type int    $order_count_max    Maximum number of orders.
	 *     @type array  $products           Array of product IDs.
	 *     @type array  $categories         Array of category IDs.
	 *     @type string $billing_country    Billing country code.
	 *     @type string $billing_state      Billing state code.
	 *     @type array  $customer_roles     Array of WP user roles.
	 *     @type string $registered_from    Customer registration date start.
	 *     @type string $registered_to      Customer registration date end.
	 * }
	 * @param int   $exclude_segment_id    Optional segment ID to exclude.
	 * @param bool  $count_only            If true, return count instead of results.
	 * @param int   $limit                 Limit results (0 = no limit).
	 * @param int   $offset                Offset for pagination.
	 * @param bool  $include_unsubscribed  If true, include unsubscribed customers (for admin views).
	 * @return array|int Array of [email, name, customer_id] or count.
	 */
	public static function query( $filters = array(), $exclude_segment_id = 0, $count_only = false, $limit = 0, $offset = 0, $include_unsubscribed = false ) {
		global $wpdb;

		$use_hpos = self::is_hpos_enabled();

		if ( $use_hpos ) {
			return self::query_hpos( $filters, $exclude_segment_id, $count_only, $limit, $offset, $include_unsubscribed );
		}

		return self::query_legacy( $filters, $exclude_segment_id, $count_only, $limit, $offset, $include_unsubscribed );
	}

	/**
	 * SQL condition (on the joined `sub` row) for who may receive marketing
	 * email, matching BHEC_Subscription::is_subscribed(): in auto mode, anyone
	 * without a record or with 'subscribed'; in explicit mode, 'subscribed' only.
	 * Pending sign-ups haven't confirmed yet.
	 *
	 * @return string
	 */
	private static function subscribed_condition() {
		return 'auto' === get_option( 'bhec_optin_mode', 'auto' )
			? "(sub.status IS NULL OR sub.status = 'subscribed')"
			: "sub.status = 'subscribed'";
	}

	/**
	 * A store-local date and time as UTC, for columns stored in UTC.
	 *
	 * @param string $local Y-m-d H:i:s in the store's timezone.
	 * @return string
	 */
	private static function utc( $local ) {
		return get_gmt_from_date( $local );
	}

	/**
	 * Condition: the order has a line item for one of these products. A join
	 * would repeat the order once per matching item and multiply its total in
	 * the spend filters (HANDOFF M6).
	 *
	 * @param string $order_column Order id column in the outer query.
	 * @param int[]  $product_ids  Product ids.
	 * @param array  $params       Query parameters, appended to.
	 * @return string
	 */
	private static function bought_condition( $order_column, array $product_ids, array &$params ) {
		global $wpdb;
		$params       = array_merge( $params, array_map( 'intval', $product_ids ) );
		$placeholders = implode( ', ', array_fill( 0, count( $product_ids ), '%d' ) );
		return "EXISTS ( SELECT 1 FROM {$wpdb->prefix}woocommerce_order_items oi
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_product_id'
			WHERE oi.order_id = {$order_column} AND oi.order_item_type = 'line_item'
			AND oim.meta_value IN ( $placeholders ) )";
	}

	/**
	 * Check if HPOS is enabled.
	 *
	 * @return bool
	 */
	public static function is_hpos_enabled() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}
		return false;
	}

	/**
	 * Query using HPOS tables.
	 */
	private static function query_hpos( $filters, $exclude_segment_id, $count_only, $limit, $offset, $include_unsubscribed = false ) {
		global $wpdb;

		$orders_table    = $wpdb->prefix . 'wc_orders';
		$addresses_table = $wpdb->prefix . 'wc_order_addresses';
		$subs_table      = $wpdb->prefix . 'bhec_subscriptions';

		$select = $count_only
			? "SELECT COUNT(*) FROM ("
			: "";

		$query = "SELECT
			addr.email AS email,
			MAX(CONCAT(addr.first_name, ' ', addr.last_name)) AS name,
			MAX(COALESCE(o.customer_id, 0)) AS customer_id";

		$query .= " FROM {$orders_table} o";
		$query .= " INNER JOIN {$addresses_table} addr ON o.id = addr.order_id AND addr.address_type = 'billing'";

		$joins  = array();
		// The first placeholder in the query, so prepare() always has a value.
		$where  = array( 'o.type = %s' );
		$having = array();
		// HAVING comes after WHERE in the query, so its values are added last.
		$having_params = array();
		$params = array( 'shop_order' );

		// Order status filter.
		if ( ! empty( $filters['order_status'] ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $filters['order_status'] ), '%s' ) );
			$where[]      = "o.status IN ($placeholders)";
			$params       = array_merge( $params, $filters['order_status'] );
		} else {
			$where[] = "o.status IN ('wc-completed', 'wc-processing', 'wc-on-hold')";
		}

		// Date range. The dates are the store's; the column is UTC.
		if ( ! empty( $filters['date_from'] ) ) {
			$where[] = 'o.date_created_gmt >= %s';
			$params[] = self::utc( $filters['date_from'] . ' 00:00:00' );
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$where[] = 'o.date_created_gmt <= %s';
			$params[] = self::utc( $filters['date_to'] . ' 23:59:59' );
		}

		// Billing country.
		if ( ! empty( $filters['billing_country'] ) ) {
			$where[] = 'addr.country = %s';
			$params[] = $filters['billing_country'];
		}

		// Billing state.
		if ( ! empty( $filters['billing_state'] ) ) {
			$where[] = 'addr.state = %s';
			$params[] = $filters['billing_state'];
		}

		// Search by email or name (HPOS).
		if ( ! empty( $filters['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[]  = "(addr.email LIKE %s OR addr.first_name LIKE %s OR addr.last_name LIKE %s OR CONCAT(addr.first_name, ' ', addr.last_name) LIKE %s)";
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		// Products purchased.
		if ( ! empty( $filters['products'] ) ) {
			$where[] = self::bought_condition( 'o.id', $filters['products'], $params );
		}

		// Product categories.
		if ( ! empty( $filters['categories'] ) ) {
			$product_ids = self::get_product_ids_by_categories( $filters['categories'] );
			if ( empty( $product_ids ) ) {
				// No products in these categories — return empty.
				return $count_only ? 0 : array();
			}
			$where[] = self::bought_condition( 'o.id', $product_ids, $params );
		}

		// Order count and total spent: HAVING on the per-email group.
		if ( ! empty( $filters['order_count_min'] ) ) {
			$having[] = 'COUNT(DISTINCT o.id) >= %d';
			$having_params[] = (int) $filters['order_count_min'];
		}
		if ( ! empty( $filters['order_count_max'] ) ) {
			$having[] = 'COUNT(DISTINCT o.id) <= %d';
			$having_params[] = (int) $filters['order_count_max'];
		}
		if ( ! empty( $filters['total_spent_min'] ) ) {
			$having[] = 'SUM(o.total_amount) >= %f';
			$having_params[] = (float) $filters['total_spent_min'];
		}
		if ( ! empty( $filters['total_spent_max'] ) ) {
			$having[] = 'SUM(o.total_amount) <= %f';
			$having_params[] = (float) $filters['total_spent_max'];
		}

		// Customer roles filter (requires join to users/usermeta).
		if ( ! empty( $filters['customer_roles'] ) ) {
			$joins[] = "INNER JOIN {$wpdb->users} u ON o.customer_id = u.ID";
			$joins[] = "INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id AND um.meta_key = '{$wpdb->prefix}capabilities'";
			$role_likes = array();
			foreach ( $filters['customer_roles'] as $role ) {
				$role_likes[] = "um.meta_value LIKE %s";
				$params[]     = '%' . $wpdb->esc_like( '"' . $role . '"' ) . '%';
			}
			$where[] = '(' . implode( ' OR ', $role_likes ) . ')';
		}

		// Registered date range.
		if ( ! empty( $filters['registered_from'] ) ) {
			if ( ! in_array( "INNER JOIN {$wpdb->users} u ON o.customer_id = u.ID", $joins, true ) ) {
				$joins[] = "LEFT JOIN {$wpdb->users} u ON o.customer_id = u.ID";
			}
			$where[] = '(u.user_registered >= %s OR o.customer_id = 0)';
			$params[] = self::utc( $filters['registered_from'] . ' 00:00:00' );
		}
		if ( ! empty( $filters['registered_to'] ) ) {
			if ( ! in_array( "INNER JOIN {$wpdb->users} u ON o.customer_id = u.ID", $joins, true )
				&& ! in_array( "LEFT JOIN {$wpdb->users} u ON o.customer_id = u.ID", $joins, true ) ) {
				$joins[] = "LEFT JOIN {$wpdb->users} u ON o.customer_id = u.ID";
			}
			$where[] = '(u.user_registered <= %s OR o.customer_id = 0)';
			$params[] = self::utc( $filters['registered_to'] . ' 23:59:59' );
		}

		// Subscription join.
		$query .= ' ' . implode( ' ', array_unique( $joins ) );
		$query .= " LEFT JOIN {$subs_table} sub ON addr.email = sub.email";

		// Only people who may receive marketing email (unless admin view).
		if ( ! $include_unsubscribed ) {
			$where[] = self::subscribed_condition();
		}

		// Exclude segment.
		if ( $exclude_segment_id > 0 ) {
			$segment_emails = self::get_segment_emails( $exclude_segment_id );
			if ( ! empty( $segment_emails ) ) {
				$placeholders = implode( ', ', array_fill( 0, count( $segment_emails ), '%s' ) );
				$where[]      = "addr.email NOT IN ($placeholders)";
				$params       = array_merge( $params, $segment_emails );
			}
		}

		// Ensure valid email.
		$where[] = "addr.email IS NOT NULL AND addr.email != ''";

		$query .= ' WHERE ' . implode( ' AND ', $where );
		$params = array_merge( $params, $having_params );

		// Always group by email to deduplicate customers with multiple orders.
		$query .= ' GROUP BY addr.email';
		if ( ! empty( $having ) ) {
			$query .= ' HAVING ' . implode( ' AND ', $having );
		}

		if ( $count_only ) {
			$query = $select . $query . ') AS sub_count';
			$count = (int) $wpdb->get_var( $wpdb->prepare( $query, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- built above from fixed text and placeholders.

			// Add signup-only subscribers count if requested.
			if ( ! empty( $filters['include_signups'] ) ) {
				$count += (int) $wpdb->get_var(
					"SELECT COUNT(*) FROM {$subs_table}
					WHERE status = 'subscribed' AND source = 'signup_form'
					AND email NOT IN (
						SELECT DISTINCT addr2.email FROM {$orders_table} o2
						INNER JOIN {$addresses_table} addr2 ON o2.id = addr2.order_id AND addr2.address_type = 'billing'
						WHERE addr2.email IS NOT NULL AND addr2.email != ''
					)"
				);
			}

			return $count;
		}

		$query .= ' ORDER BY addr.email ASC';

		if ( $limit > 0 ) {
			$query .= ' LIMIT %d OFFSET %d';
			$params[] = $limit;
			$params[] = $offset;
		}

		$results = $wpdb->get_results( $wpdb->prepare( $query, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- built above from fixed text and placeholders.
		$results = $results ?: array();

		// Include signup-only subscribers if requested.
		if ( ! empty( $filters['include_signups'] ) ) {
			$existing_emails = array_column( $results, 'email' );
			if ( ! empty( $existing_emails ) ) {
				$placeholders = implode( ', ', array_fill( 0, count( $existing_emails ), '%s' ) );
				$signup_subs  = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT email, '' as name, 0 as customer_id FROM {$subs_table}
						WHERE status = 'subscribed' AND source = 'signup_form'
						AND email NOT IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL
						$existing_emails
					),
					ARRAY_A
				);
			} else {
				$signup_subs = $wpdb->get_results(
					"SELECT email, '' as name, 0 as customer_id FROM {$subs_table}
					WHERE status = 'subscribed' AND source = 'signup_form'",
					ARRAY_A
				);
			}
			if ( ! empty( $signup_subs ) ) {
				$results = array_merge( $results, $signup_subs );
			}
		}

		return $results;
	}

	/**
	 * Query using legacy CPT tables.
	 */
	private static function query_legacy( $filters, $exclude_segment_id, $count_only, $limit, $offset, $include_unsubscribed = false ) {
		global $wpdb;

		$subs_table     = $wpdb->prefix . 'bhec_subscriptions';

		$select = $count_only
			? "SELECT COUNT(*) FROM ("
			: "";

		$query = "SELECT
			pm_email.meta_value AS email,
			MAX(CONCAT(COALESCE(pm_fn.meta_value, ''), ' ', COALESCE(pm_ln.meta_value, ''))) AS name,
			MAX(COALESCE(pm_cid.meta_value, 0)) AS customer_id";

		$query .= " FROM {$wpdb->posts} p";
		$query .= " INNER JOIN {$wpdb->postmeta} pm_email ON p.ID = pm_email.post_id AND pm_email.meta_key = '_billing_email'";
		$query .= " LEFT JOIN {$wpdb->postmeta} pm_fn ON p.ID = pm_fn.post_id AND pm_fn.meta_key = '_billing_first_name'";
		$query .= " LEFT JOIN {$wpdb->postmeta} pm_ln ON p.ID = pm_ln.post_id AND pm_ln.meta_key = '_billing_last_name'";
		$query .= " LEFT JOIN {$wpdb->postmeta} pm_cid ON p.ID = pm_cid.post_id AND pm_cid.meta_key = '_customer_user'";

		$joins  = array();
		// The first placeholder in the query, so prepare() always has a value.
		$where  = array( 'p.post_type = %s' );
		$having = array();
		// HAVING comes after WHERE in the query, so its values are added last.
		$having_params = array();
		$params = array( 'shop_order' );

		// Order status.
		if ( ! empty( $filters['order_status'] ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $filters['order_status'] ), '%s' ) );
			$where[]      = "p.post_status IN ($placeholders)";
			$params       = array_merge( $params, $filters['order_status'] );
		} else {
			$where[] = "p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')";
		}

		// Date range.
		if ( ! empty( $filters['date_from'] ) ) {
			$where[]  = 'p.post_date >= %s';
			$params[] = $filters['date_from'] . ' 00:00:00';
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$where[]  = 'p.post_date <= %s';
			$params[] = $filters['date_to'] . ' 23:59:59';
		}

		// Billing country.
		if ( ! empty( $filters['billing_country'] ) ) {
			$joins[]  = "INNER JOIN {$wpdb->postmeta} pm_country ON p.ID = pm_country.post_id AND pm_country.meta_key = '_billing_country'";
			$where[]  = 'pm_country.meta_value = %s';
			$params[] = $filters['billing_country'];
		}

		// Billing state.
		if ( ! empty( $filters['billing_state'] ) ) {
			$joins[]  = "INNER JOIN {$wpdb->postmeta} pm_state ON p.ID = pm_state.post_id AND pm_state.meta_key = '_billing_state'";
			$where[]  = 'pm_state.meta_value = %s';
			$params[] = $filters['billing_state'];
		}

		// Search by email or name (legacy).
		if ( ! empty( $filters['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[]  = "(pm_email.meta_value LIKE %s OR pm_fn.meta_value LIKE %s OR pm_ln.meta_value LIKE %s OR CONCAT(COALESCE(pm_fn.meta_value,''), ' ', COALESCE(pm_ln.meta_value,'')) LIKE %s)";
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		// Products purchased.
		if ( ! empty( $filters['products'] ) ) {
			$where[] = self::bought_condition( 'p.ID', $filters['products'], $params );
		}

		// Product categories.
		if ( ! empty( $filters['categories'] ) ) {
			$product_ids = self::get_product_ids_by_categories( $filters['categories'] );
			if ( empty( $product_ids ) ) {
				return $count_only ? 0 : array();
			}
			$where[] = self::bought_condition( 'p.ID', $product_ids, $params );
		}

		// Aggregates.
		if ( ! empty( $filters['order_count_min'] ) || ! empty( $filters['order_count_max'] )
			|| ! empty( $filters['total_spent_min'] ) || ! empty( $filters['total_spent_max'] ) ) {
			if ( ! in_array( true, array_map( function ( $j ) { return strpos( $j, 'pm_total' ) !== false; }, $joins ), true ) ) {
				$joins[] = "LEFT JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'";
			}
		}

		if ( ! empty( $filters['order_count_min'] ) ) {
			$having[] = 'COUNT(DISTINCT p.ID) >= %d';
			$having_params[] = (int) $filters['order_count_min'];
		}
		if ( ! empty( $filters['order_count_max'] ) ) {
			$having[] = 'COUNT(DISTINCT p.ID) <= %d';
			$having_params[] = (int) $filters['order_count_max'];
		}
		if ( ! empty( $filters['total_spent_min'] ) ) {
			$having[] = 'SUM(CAST(pm_total.meta_value AS DECIMAL(10,2))) >= %f';
			$having_params[] = (float) $filters['total_spent_min'];
		}
		if ( ! empty( $filters['total_spent_max'] ) ) {
			$having[] = 'SUM(CAST(pm_total.meta_value AS DECIMAL(10,2))) <= %f';
			$having_params[] = (float) $filters['total_spent_max'];
		}

		// Customer roles.
		if ( ! empty( $filters['customer_roles'] ) ) {
			$joins[] = "INNER JOIN {$wpdb->users} u ON pm_cid.meta_value = u.ID";
			$joins[] = "INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id AND um.meta_key = '{$wpdb->prefix}capabilities'";
			$role_likes = array();
			foreach ( $filters['customer_roles'] as $role ) {
				$role_likes[] = "um.meta_value LIKE %s";
				$params[]     = '%' . $wpdb->esc_like( '"' . $role . '"' ) . '%';
			}
			$where[] = '(' . implode( ' OR ', $role_likes ) . ')';
		}

		// Registered date range, as on HPOS: guests pass (user_registered is UTC).
		if ( ! empty( $filters['registered_from'] ) || ! empty( $filters['registered_to'] ) ) {
			if ( empty( $filters['customer_roles'] ) ) {
				$joins[] = "LEFT JOIN {$wpdb->users} u ON pm_cid.meta_value = u.ID";
			}
			if ( ! empty( $filters['registered_from'] ) ) {
				$where[]  = '(u.user_registered >= %s OR COALESCE(pm_cid.meta_value, 0) = 0)';
				$params[] = self::utc( $filters['registered_from'] . ' 00:00:00' );
			}
			if ( ! empty( $filters['registered_to'] ) ) {
				$where[]  = '(u.user_registered <= %s OR COALESCE(pm_cid.meta_value, 0) = 0)';
				$params[] = self::utc( $filters['registered_to'] . ' 23:59:59' );
			}
		}

		// Build.
		$query .= ' ' . implode( ' ', array_unique( $joins ) );
		$query .= " LEFT JOIN {$subs_table} sub ON pm_email.meta_value = sub.email";

		if ( ! $include_unsubscribed ) {
			$where[] = self::subscribed_condition();
		}
		$where[] = "pm_email.meta_value IS NOT NULL AND pm_email.meta_value != ''";

		// Exclude segment.
		if ( $exclude_segment_id > 0 ) {
			$segment_emails = self::get_segment_emails( $exclude_segment_id );
			if ( ! empty( $segment_emails ) ) {
				$placeholders = implode( ', ', array_fill( 0, count( $segment_emails ), '%s' ) );
				$where[]      = "pm_email.meta_value NOT IN ($placeholders)";
				$params       = array_merge( $params, $segment_emails );
			}
		}

		$query .= ' WHERE ' . implode( ' AND ', $where );
		$params = array_merge( $params, $having_params );

		// One row per address. Group by the column itself: a bare `email` would
		// resolve to the joined subscriptions table and merge every customer
		// without a subscription row into one.
		$query .= ' GROUP BY pm_email.meta_value';
		if ( ! empty( $having ) ) {
			$query .= ' HAVING ' . implode( ' AND ', $having );
		}

		if ( $count_only ) {
			$query = $select . $query . ') AS sub_count';
			$count = (int) $wpdb->get_var( $wpdb->prepare( $query, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- built above from fixed text and placeholders.

			// Add signup-only subscribers count if requested.
			if ( ! empty( $filters['include_signups'] ) ) {
				$count += (int) $wpdb->get_var(
					"SELECT COUNT(*) FROM {$subs_table}
					WHERE status = 'subscribed' AND source = 'signup_form'
					AND email NOT IN (
						SELECT DISTINCT pm2.meta_value FROM {$wpdb->posts} p2
						INNER JOIN {$wpdb->postmeta} pm2 ON p2.ID = pm2.post_id AND pm2.meta_key = '_billing_email'
						WHERE p2.post_type = 'shop_order' AND pm2.meta_value IS NOT NULL AND pm2.meta_value != ''
					)"
				);
			}

			return $count;
		}

		$query .= ' ORDER BY email ASC';

		if ( $limit > 0 ) {
			$query .= ' LIMIT %d OFFSET %d';
			$params[] = $limit;
			$params[] = $offset;
		}

		$results = $wpdb->get_results( $wpdb->prepare( $query, $params ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL -- built above from fixed text and placeholders.

		// Include signup-only subscribers if requested.
		if ( ! empty( $filters['include_signups'] ) ) {
			$existing_emails = array_column( $results, 'email' );
			if ( ! empty( $existing_emails ) ) {
				$placeholders = implode( ', ', array_fill( 0, count( $existing_emails ), '%s' ) );
				$signup_subs  = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT email, '' as name, 0 as customer_id FROM {$subs_table}
						WHERE status = 'subscribed' AND source = 'signup_form'
						AND email NOT IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL
						$existing_emails
					),
					ARRAY_A
				);
			} else {
				$signup_subs = $wpdb->get_results(
					"SELECT email, '' as name, 0 as customer_id FROM {$subs_table}
					WHERE status = 'subscribed' AND source = 'signup_form'",
					ARRAY_A
				);
			}
			if ( ! empty( $signup_subs ) ) {
				$results = array_merge( $results, $signup_subs );
			}
		}

		return $results;
	}

	/**
	 * Get product IDs belonging to given category IDs.
	 *
	 * @param array $category_ids Array of term IDs.
	 * @return array Product IDs.
	 */
	private static function get_product_ids_by_categories( $category_ids ) {
		$args = array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'fields'      => 'ids',
			'numberposts' => -1,
			'tax_query'   => array(
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => array_map( 'intval', $category_ids ),
				),
			),
		);
		return get_posts( $args );
	}

	/**
	 * Get all emails matching a saved segment's filters.
	 *
	 * @param int $segment_id Segment ID.
	 * @return array Email addresses.
	 */
	private static function get_segment_emails( $segment_id ) {
		global $wpdb;
		$table   = $wpdb->prefix . 'bhec_segments';
		$segment = $wpdb->get_row(
			$wpdb->prepare( "SELECT filters FROM $table WHERE id = %d", $segment_id )
		);

		if ( ! $segment ) {
			return array();
		}

		$filters  = json_decode( $segment->filters, true ) ?: array();
		$results  = self::query( $filters, 0, false, 0, 0 );

		return array_column( $results, 'email' );
	}
}
