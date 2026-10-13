<?php
/**
 * Starter layouts for a new campaign: a name, subject and message ready to
 * edit, so a first campaign isn't a blank page.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Campaign_Starters {

	/**
	 * Every starter, in the order they're offered.
	 *
	 * @return array key => { name, about, title, subject, body, coupon (Pro, optional) }.
	 */
	public static function all() {
		// The merge tag's quotes must stay quotes, so the greeting isn't escaped
		// as a whole.
		/* translators: %s: the customer's first name */
		$hello = '<p>' . sprintf( esc_html__( 'Hi %s,', 'backhaus-email-campaigns' ), "{{ customer.first_name ?? '" . wp_strip_all_tags( __( 'there', 'backhaus-email-campaigns' ) ) . "' }}" ) . '</p>';
		$bye   = '<p>' . esc_html__( 'Thanks,', 'backhaus-email-campaigns' ) . '<br>{{ store.name }}</p>';

		$starters = array(
			'news'     => array(
				'name'    => __( 'Store News', 'backhaus-email-campaigns' ),
				'about'   => __( 'A short letter about what\'s going on at your store.', 'backhaus-email-campaigns' ),
				'title'   => __( 'Store news', 'backhaus-email-campaigns' ),
				'subject' => __( 'News from {{ store.name }}', 'backhaus-email-campaigns' ),
				'body'    => $hello
					. '<p>' . esc_html__( 'Here\'s what\'s been happening at our store lately.', 'backhaus-email-campaigns' ) . '</p>'
					. '<p>' . esc_html__( 'Write your news here: new stock on the way, an event, a change to opening hours or shipping.', 'backhaus-email-campaigns' ) . '</p>'
					. self::button( __( 'Visit the Store', 'backhaus-email-campaigns' ) )
					. $bye,
			),
			'sale'     => array(
				'name'    => __( 'Sale', 'backhaus-email-campaigns' ),
				'about'   => __( 'Tell customers a sale is on, with a button to shop.', 'backhaus-email-campaigns' ),
				'title'   => __( 'Sale announcement', 'backhaus-email-campaigns' ),
				'subject' => __( 'Our sale is on, {{ customer.first_name ?? \'friend\' }}', 'backhaus-email-campaigns' ),
				'body'    => '<h2>' . esc_html__( 'The sale is on', 'backhaus-email-campaigns' ) . '</h2>'
					. $hello
					. '<p>' . esc_html__( 'Our sale starts today, with savings across the store. It won\'t last long, so take a look while your favourites are in stock.', 'backhaus-email-campaigns' ) . '</p>'
					. self::button( __( 'Shop the Sale', 'backhaus-email-campaigns' ) )
					. $bye,
			),
			'arrivals' => array(
				'name'    => __( 'New Arrivals', 'backhaus-email-campaigns' ),
				'about'   => __( 'Show off your newest products, with photos and prices.', 'backhaus-email-campaigns' ),
				'title'   => __( 'New arrivals', 'backhaus-email-campaigns' ),
				'subject' => __( 'Just in at {{ store.name }}', 'backhaus-email-campaigns' ),
				'body'    => $hello
					. '<p>' . esc_html__( 'Fresh stock just landed. Here are a few of our newest products.', 'backhaus-email-campaigns' ) . '</p>'
					. self::newest_products()
					. self::button( __( 'See Everything New', 'backhaus-email-campaigns' ) )
					. $bye,
			),
			'thanks'   => array(
				'name'    => __( 'Thank You', 'backhaus-email-campaigns' ),
				'about'   => __( 'Thank your customers for their support.', 'backhaus-email-campaigns' ),
				'title'   => __( 'Thank you', 'backhaus-email-campaigns' ),
				'subject' => __( 'Thank you from {{ store.name }}', 'backhaus-email-campaigns' ),
				'body'    => $hello
					. '<p>' . esc_html__( 'We wanted to say thank you. Every order helps our small store keep going, and we\'re grateful you chose us.', 'backhaus-email-campaigns' ) . '</p>'
					. '<p>' . esc_html__( 'If there\'s anything you\'d like to see us stock, just reply to this email.', 'backhaus-email-campaigns' ) . '</p>'
					. $bye,
			),
		);

		
		return $starters;
	}

	/**
	 * One starter.
	 *
	 * @param string $key Starter key.
	 * @return array|null
	 */
	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * A button to the store, in the store's email colour.
	 *
	 * @param string $label Button text.
	 * @return string HTML.
	 */
	private static function button( $label ) {
		$color = sanitize_hex_color( get_option( 'woocommerce_email_base_color', '#7f54b3' ) ) ?: '#7f54b3';
		return sprintf(
			'<p><a href="{{ store.url }}" style="display:inline-block;padding:12px 24px;background:%s;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:bold">%s</a></p>',
			esc_attr( $color ),
			esc_html( $label )
		);
	}

	/**
	 * Product cards for the store's four newest products in stock, or '' if
	 * it has none yet.
	 *
	 * @return string
	 */
	private static function newest_products() {
		$ids = wc_get_products(
			array(
				'status'       => 'publish',
				'visibility'   => 'catalog',
				'stock_status' => 'instock',
				'limit'        => 4,
				'orderby'      => 'date',
				'order'        => 'DESC',
				'return'       => 'ids',
			)
		);
		return $ids ? '<p>{{ product:' . implode( ',', array_map( 'intval', $ids ) ) . ' }}</p>' : '';
	}
}
