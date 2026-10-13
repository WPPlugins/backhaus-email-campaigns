<?php
/**
 * Mail merge tag processing.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Merge_Tags {

	/**
	 * Get all available merge tags with descriptions.
	 *
	 * @return array
	 */
	public static function get_available_tags() {
		return array(
			'{{ customer.email }}'          => __( 'Customer email', 'backhaus-email-campaigns' ),
			'{{ customer.first_name }}'     => __( 'First name', 'backhaus-email-campaigns' ),
			'{{ customer.last_name }}'      => __( 'Last name', 'backhaus-email-campaigns' ),
			'{{ customer.full_name }}'      => __( 'Full name', 'backhaus-email-campaigns' ),
			'{{ customer.company }}'        => __( 'Company', 'backhaus-email-campaigns' ),
			'{{ customer.city }}'           => __( 'City', 'backhaus-email-campaigns' ),
			'{{ customer.state }}'          => __( 'State', 'backhaus-email-campaigns' ),
			'{{ customer.country }}'        => __( 'Country', 'backhaus-email-campaigns' ),
			'{{ customer.order_count }}'    => __( 'Total orders', 'backhaus-email-campaigns' ),
			'{{ customer.total_spent }}'    => __( 'Total spent', 'backhaus-email-campaigns' ),
			'{{ customer.last_order_date }}' => __( 'Last order date', 'backhaus-email-campaigns' ),
			'{{ store.name }}'              => __( 'Store name', 'backhaus-email-campaigns' ),
			'{{ store.url }}'               => __( 'Store URL', 'backhaus-email-campaigns' ),
			'{{ unsubscribe_url }}'         => __( 'Unsubscribe link', 'backhaus-email-campaigns' ),
			'{{ coupon }}'                  => __( 'Unique coupon code', 'backhaus-email-campaigns' ),
			'{{ order.items_card }}'        => __( 'Order items as product cards (images + prices)', 'backhaus-email-campaigns' ),
			'{{ order.items_table }}'       => __( 'Order items as table (product, qty, price)', 'backhaus-email-campaigns' ),
			'{{ product:ID }}'              => __( 'Product card (use product ID, e.g. {{ product:42 }} or {{ product:42,55,78 }})', 'backhaus-email-campaigns' ),
		);
	}

	/**
	 * Tags that callers or later steps render; replace() only normalises their spacing.
	 */
	const DEFERRED_TAGS = array( 'coupon', 'cross_sell_products', 'order.items_card', 'order.items', 'order.items_table' );

	/**
	 * Replace merge tags in text with customer data.
	 *
	 * Every {{ ... }} is resolved in one pass, so a customer's own data is never
	 * read as another tag. Spaces inside the braces are optional.
	 * {{ tag ?? 'default' }} prints the default when the value is empty.
	 * An unknown tag without a default is left as typed. The website's merge
	 * tag playground follows the same rules.
	 *
	 * Values are escaped for where they land: HTML in a message, plain text in
	 * a subject or an address. A value can't turn into another tag in a later
	 * step, such as a name typed as {{ coupon }} (HANDOFF L2).
	 *
	 * @param string $text          Text containing merge tags.
	 * @param array  $customer_data Associative array of customer data.
	 * @param string $context       'html' for a message body, 'text' for a subject or address.
	 * @return string
	 */
	public static function replace( $text, $customer_data, $context = 'html' ) {
		$values = array(
			'customer.email'           => $customer_data['email'] ?? '',
			'customer.first_name'      => $customer_data['first_name'] ?? '',
			'customer.last_name'       => $customer_data['last_name'] ?? '',
			'customer.full_name'       => trim( ( $customer_data['first_name'] ?? '' ) . ' ' . ( $customer_data['last_name'] ?? '' ) ),
			'customer.company'         => $customer_data['company'] ?? '',
			'customer.city'            => $customer_data['city'] ?? '',
			'customer.state'           => $customer_data['state'] ?? '',
			'customer.country'         => $customer_data['country'] ?? '',
			'customer.order_count'     => $customer_data['order_count'] ?? '0',
			'customer.total_spent'     => $customer_data['total_spent'] ?? wc_price( 0 ),
			'customer.last_order_date' => $customer_data['last_order_date'] ?? '',
			'store.name'               => get_bloginfo( 'name' ),
			'store.url'                => home_url( '/' ),
			'unsubscribe_url'          => $customer_data['unsubscribe_url'] ?? '#',
			'checkout_url'             => $customer_data['checkout_url'] ?? '',
			'order_total'              => $customer_data['order_total'] ?? '',
			'order_date'               => $customer_data['order_date'] ?? '',
			'order_id'                 => $customer_data['order_id'] ?? '',
			'product.name'             => $customer_data['product_name'] ?? '',
			'product.url'              => $customer_data['product_url'] ?? '',
		);
		foreach ( $values as $key => $value ) {
			$values[ $key ] = self::escape_value( $key, (string) $value, $context );
		}

		$text = preg_replace_callback(
			'/\{\{\s*([^{}]*?)\s*\}\}/',
			static function ( $matches ) use ( $values ) {
				$parts   = preg_split( '/\s*\?\?\s*/', $matches[1], 2 );
				$tag     = trim( $parts[0] );
				$default = isset( $parts[1] ) ? preg_replace( '/^([\'"])(.*)\1$/s', '$2', trim( $parts[1] ) ) : null;

				if ( preg_match( '/^product:\s*(\d+(?:\s*,\s*\d+)*)$/', $tag, $product ) ) {
					return '{{ product:' . preg_replace( '/\s+/', '', $product[1] ) . ' }}';
				}
				if ( in_array( $tag, self::DEFERRED_TAGS, true ) ) {
					return '{{ ' . $tag . ' }}';
				}
				if ( array_key_exists( $tag, $values ) && '' !== (string) $values[ $tag ] ) {
					return (string) $values[ $tag ];
				}
				if ( null !== $default ) {
					return $default;
				}
				return array_key_exists( $tag, $values ) ? '' : $matches[0];
			},
			$text
		);

		// TinyMCE prefixes http:// to a merge tag in a link, giving
		// http://https://... once the tag is replaced.
		$text = preg_replace( '#(href\s*=\s*["\'])https?://(https?://)#i', '$1$2', $text );

		// Process order items tags.
		$oid = ! empty( $customer_data['order_id'] ) ? (int) $customer_data['order_id'] : 0;

		// {{ order.items_card }} and {{ order.items }} (deprecated alias) → product card grid.
		foreach ( array( '{{ order.items_card }}', '{{ order.items }}' ) as $card_tag ) {
			if ( strpos( $text, $card_tag ) !== false && $oid ) {
				$text = str_replace( $card_tag, self::render_order_items( $oid ), $text );
			}
			$text = str_replace( $card_tag, '', $text );
		}

		// {{ order.items_table }} → table with product, qty, price.
		if ( strpos( $text, '{{ order.items_table }}' ) !== false && $oid ) {
			$text = str_replace( '{{ order.items_table }}', self::render_order_items_table( $oid ), $text );
		}
		$text = str_replace( '{{ order.items_table }}', '', $text );

		// Process product merge tags.
		$text = self::replace_product_tags( $text );

		return $text;
	}

	/**
	 * One merge tag value, made safe for where it lands.
	 *
	 * @param string $key     Tag name.
	 * @param string $value   Raw value. Prices are HTML (wc_price); the store name is stored escaped.
	 * @param string $context 'html' or 'text'.
	 * @return string
	 */
	private static function escape_value( $key, $value, $context ) {
		$html_values = array( 'customer.total_spent', 'order_total', 'store.name' );
		$url_values  = array( 'store.url', 'unsubscribe_url', 'checkout_url', 'product.url' );

		if ( 'text' === $context ) {
			$value = in_array( $key, $html_values, true ) ? html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES, 'UTF-8' ) : $value;
		} elseif ( in_array( $key, $url_values, true ) ) {
			$value = '#' === $value ? $value : esc_url( $value );
		} elseif ( ! in_array( $key, $html_values, true ) ) {
			$value = esc_html( $value );
		}

		// Later steps look for {{ coupon }}, {{ product:ID }} and the order tags
		// in the whole text; a value mustn't supply one.
		return str_replace( array( '{{', '}}' ), array( '{ {', '} }' ), $value );
	}

	/**
	 * Render order items as an HTML table for emails.
	 *
	 * @param int $order_id WC Order ID.
	 * @return string HTML table of order items.
	 */
	public static function render_order_items( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return '';
		}

		// Collect product IDs from order items.
		$product_ids = array();
		foreach ( $order->get_items() as $item ) {
			$pid = $item->get_product_id();
			if ( $pid && ! in_array( $pid, $product_ids, true ) ) {
				$product_ids[] = $pid;
			}
		}

		if ( empty( $product_ids ) ) {
			return '';
		}

		// Render as product card grid (same style as {{ product:ID }}).
		$tag = '{{ product:' . implode( ',', $product_ids ) . ' }}';
		return self::replace_product_tags( $tag );
	}

	/**
	 * Render order items as a table with product image, name, quantity, and price.
	 *
	 * @param int $order_id WC Order ID.
	 * @return string HTML table.
	 */
	public static function render_order_items_table( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return '';
		}

		$html = '<table style="width:100%;border-collapse:collapse;margin:10px 0;" cellpadding="0" cellspacing="0">';
		$html .= '<tr style="background:#f8f8f8;">';
		$html .= '<th style="padding:10px 12px;border:1px solid #e5e5e5;text-align:left;">' . esc_html__( 'Product', 'backhaus-email-campaigns' ) . '</th>';
		$html .= '<th style="padding:10px 12px;border:1px solid #e5e5e5;text-align:center;width:60px;">' . esc_html__( 'Qty', 'backhaus-email-campaigns' ) . '</th>';
		$html .= '<th style="padding:10px 12px;border:1px solid #e5e5e5;text-align:right;width:90px;">' . esc_html__( 'Price', 'backhaus-email-campaigns' ) . '</th>';
		$html .= '</tr>';

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$name    = $item->get_name();
			$qty     = $item->get_quantity();
			$total   = $item->get_total();
			$link    = $product ? $product->get_permalink() : '#';

			$image = '';
			if ( $product && $product->get_image_id() ) {
				$img_url = wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_gallery_thumbnail' );
				if ( $img_url ) {
					$image = '<img src="' . esc_url( $img_url ) . '" width="48" height="48" style="vertical-align:middle;margin-right:10px;border-radius:4px;" alt="">';
				}
			}

			$html .= '<tr>';
			$html .= '<td style="padding:10px 12px;border:1px solid #e5e5e5;">'
				. $image
				. '<a href="' . esc_url( $link ) . '" style="color:#333;text-decoration:none;font-weight:500;">' . esc_html( $name ) . '</a>'
				. '</td>';
			$html .= '<td style="padding:10px 12px;border:1px solid #e5e5e5;text-align:center;">' . esc_html( $qty ) . '</td>';
			$html .= '<td style="padding:10px 12px;border:1px solid #e5e5e5;text-align:right;">' . wp_kses_post( wc_price( $total ) ) . '</td>';
			$html .= '</tr>';
		}

		$html .= '<tr style="background:#f8f8f8;">';
		$html .= '<td colspan="2" style="padding:10px 12px;border:1px solid #e5e5e5;text-align:right;font-weight:600;">' . esc_html__( 'Total', 'backhaus-email-campaigns' ) . '</td>';
		$html .= '<td style="padding:10px 12px;border:1px solid #e5e5e5;text-align:right;font-weight:600;">' . wp_kses_post( wc_price( $order->get_total() ) ) . '</td>';
		$html .= '</tr>';
		$html .= '</table>';

		return $html;
	}

	/**
	 * Load customer data for merge tag resolution.
	 *
	 * @param int    $customer_id WC customer ID (0 for guest).
	 * @param string $email       Customer email.
	 * @return array
	 */
	public static function get_customer_data( $customer_id, $email, $with_unsubscribe_url = true ) {
		$data = array(
			'email'           => $email,
			'first_name'      => '',
			'last_name'       => '',
			'company'         => '',
			'city'            => '',
			'state'           => '',
			'country'         => '',
			'order_count'     => 0,
			'total_spent'     => wc_price( 0 ),
			'last_order_date' => '',
			// Building the link creates a subscription row to hold its token.
			'unsubscribe_url' => $with_unsubscribe_url ? BHEC_Subscription::get_unsubscribe_url( $email ) : '',
		);

		// Try loading from WC customer object.
		if ( $customer_id > 0 ) {
			$customer = new WC_Customer( $customer_id );
			if ( $customer->get_id() > 0 ) {
				$data['first_name'] = $customer->get_billing_first_name();
				$data['last_name']  = $customer->get_billing_last_name();
				$data['company']    = $customer->get_billing_company();
				$data['city']       = $customer->get_billing_city();
				$data['state']      = $customer->get_billing_state();
				$data['country']    = $customer->get_billing_country();
				$data['order_count'] = $customer->get_order_count();
				$data['total_spent'] = wc_price( $customer->get_total_spent() );
				// get_date_last_order() removed in newer WC — query the last order instead.
				$last_order = wc_get_orders( array(
					'customer_id' => $customer->get_id(),
					'limit'       => 1,
					'orderby'     => 'date',
					'order'       => 'DESC',
					'return'      => 'objects',
				) );
				$data['last_order_date'] = ! empty( $last_order )
					? wc_format_datetime( $last_order[0]->get_date_created() )
					: '';
				return $data;
			}
		}

		// Fallback: query from last order for guests.
		$orders = wc_get_orders( array(
			'billing_email' => $email,
			'limit'         => 1,
			'orderby'       => 'date',
			'order'         => 'DESC',
			'return'        => 'objects',
		) );

		if ( ! empty( $orders ) ) {
			$order = $orders[0];
			$data['first_name'] = $order->get_billing_first_name();
			$data['last_name']  = $order->get_billing_last_name();
			$data['company']    = $order->get_billing_company();
			$data['city']       = $order->get_billing_city();
			$data['state']      = $order->get_billing_state();
			$data['country']    = $order->get_billing_country();

			// Count all orders for this email.
			$all_orders = wc_get_orders( array(
				'billing_email' => $email,
				'limit'         => -1,
				'return'        => 'ids',
			) );
			$data['order_count'] = count( $all_orders );

			// Sum total spent.
			$total = 0;
			foreach ( wc_get_orders( array( 'billing_email' => $email, 'limit' => -1, 'status' => array( 'completed', 'processing' ) ) ) as $o ) {
				$total += (float) $o->get_total();
			}
			$data['total_spent']     = wc_price( $total );
			$data['last_order_date'] = wc_format_datetime( $order->get_date_created() );
		}

		return $data;
	}

	/**
	 * Get mock/sample data for test emails.
	 *
	 * @return array
	 */
	public static function get_mock_data() {
		$current_user = wp_get_current_user();
		return array(
			'email'           => $current_user->user_email ?: 'customer@example.com',
			'first_name'      => $current_user->first_name ?: 'Jane',
			'last_name'       => $current_user->last_name ?: 'Doe',
			'company'         => 'Example Corp',
			'city'            => 'San Francisco',
			'state'           => 'CA',
			'country'         => 'US',
			'order_count'     => 5,
			'total_spent'     => wc_price( 250.00 ),
			'last_order_date' => wp_date( get_option( 'date_format' ) ),
			'unsubscribe_url' => home_url( '/?bhec_unsubscribe=SAMPLE_TOKEN' ),
			'coupon'          => 'WEC-SAMPLE-CODE',
			'product_sample'  => true,
		);
	}

	/**
	 * Replace product merge tags with rendered HTML product cards.
	 *
	 * Supports {{ product:ID }} and {{ product:ID,ID,ID }} syntax.
	 *
	 * @param string $text Text containing product merge tags.
	 * @return string
	 */
	public static function replace_product_tags( $text ) {
		// First, merge consecutive product tags (separated only by whitespace)
		// into a single comma-separated tag.
		// e.g. {{ product:111 }} {{ product:222 }} → {{ product:111,222 }}
		$text = preg_replace(
			'/\{\{\s*product:(\d+(?:,\d+)*)\s*\}\}\s*\{\{\s*product:/',
			'{{ product:$1,',
			$text
		);
		// Repeat to catch chains of 3+ consecutive tags.
		$text = preg_replace(
			'/\{\{\s*product:(\d+(?:,\d+)*)\s*\}\}\s*\{\{\s*product:/',
			'{{ product:$1,',
			$text
		);
		$text = preg_replace(
			'/\{\{\s*product:(\d+(?:,\d+)*)\s*\}\}\s*\{\{\s*product:/',
			'{{ product:$1,',
			$text
		);

		return preg_replace_callback(
			'/\{\{\s*product:(\d+(?:,\d+)*)\s*\}\}/',
			function ( $matches ) {
				$ids = array_map( 'absint', explode( ',', $matches[1] ) );
				$ids = array_filter( $ids );

				if ( empty( $ids ) ) {
					return '';
				}

				// Always use the grid renderer (handles single cards too).
				return self::render_product_cards( $ids );
			},
			$text
		);
	}

	/**
	 * Render a single product card as email-safe HTML.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return string HTML table-based product card or empty string if not found.
	 */
	private static function render_product_card( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return '';
		}

		$permalink = $product->get_permalink();
		$name      = $product->get_name();
		$price     = wc_price( $product->get_price() );
		$image_id  = $product->get_image_id();
		$image_url = $image_id
			? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' )
			: wc_placeholder_img_src( 'woocommerce_thumbnail' );

		// Returns a single <td> cell — caller wraps in <tr>/<table>.
		$html  = '<td style="width:50%;padding:8px;vertical-align:top;text-align:center;">';
		$html .= '<table style="border:1px solid #e5e5e5;border-radius:4px;width:100%;">';
		$html .= '<tr><td style="padding:0;text-align:center;">';
		$html .= '<a href="' . esc_url( $permalink ) . '"><img src="' . esc_url( $image_url ) . '" width="200" style="width:100%;max-width:200px;height:auto;display:block;margin:0 auto;" alt="' . esc_attr( $name ) . '"></a>';
		$html .= '</td></tr>';
		$html .= '<tr><td style="padding:10px;text-align:center;">';
		$html .= '<a href="' . esc_url( $permalink ) . '" style="color:#333;text-decoration:none;font-weight:600;font-size:14px;">' . esc_html( $name ) . '</a>';
		$html .= '<br><span style="color:#0073aa;font-size:16px;font-weight:600;">' . $price . '</span>';
		$html .= '</td></tr>';
		$html .= '</table>';
		$html .= '</td>';

		return $html;
	}

	/**
	 * Render multiple product cards in a grid (2 per row).
	 *
	 * Uses a proper table grid for email client compatibility
	 * (inline-table/inline-block don't work in Outlook/Gmail).
	 *
	 * @param array $product_ids Array of WooCommerce product IDs.
	 * @return string HTML table grid with product cards.
	 */
	private static function render_product_cards( $product_ids ) {
		$cells = array();
		foreach ( $product_ids as $id ) {
			$cell = self::render_product_card( $id );
			if ( $cell ) {
				$cells[] = $cell;
			}
		}

		if ( empty( $cells ) ) {
			return '';
		}

		// Build a table grid with 2 cards per row.
		$html = '<table style="width:100%;border-collapse:collapse;margin:10px 0;" cellpadding="0" cellspacing="0">';

		for ( $i = 0; $i < count( $cells ); $i += 2 ) {
			$html .= '<tr>';
			$html .= $cells[ $i ];
			// Second cell in the row (or empty cell if odd number).
			if ( isset( $cells[ $i + 1 ] ) ) {
				$html .= $cells[ $i + 1 ];
			} else {
				$html .= '<td style="width:50%;padding:8px;"></td>';
			}
			$html .= '</tr>';
		}

		$html .= '</table>';

		return $html;
	}
}
