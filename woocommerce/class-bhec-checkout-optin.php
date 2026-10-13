<?php
/**
 * WooCommerce checkout opt-in integration.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Checkout_Optin {

	/**
	 * Render the opt-in checkbox on the checkout page.
	 */
	public function render_checkbox() {
		$mode = get_option( 'bhec_optin_mode', 'auto' );

		// In auto mode, we don't show a checkbox — customers are auto-subscribed.
		if ( 'auto' === $mode ) {
			return;
		}

		$default_checked = 'yes' === get_option( 'bhec_checkout_default_checked', 'yes' );

		woocommerce_form_field( 'bhec_marketing_optin', array(
			'type'  => 'checkbox',
			'class' => array( 'form-row-wide bhec-optin-checkbox' ),
			'label' => __( 'Subscribe to our marketing emails', 'backhaus-email-campaigns' ),
		), $default_checked ? '1' : '' );
	}

	/**
	 * Process the opt-in after order is placed.
	 *
	 * @param int      $order_id    Order ID.
	 * @param array    $posted_data Posted checkout data.
	 * @param WC_Order $order       Order object.
	 */
	public function process_optin( $order_id, $posted_data, $order ) {
		$email       = $order->get_billing_email();
		$customer_id = $order->get_customer_id();
		$mode        = get_option( 'bhec_optin_mode', 'auto' );

		if ( ! $email ) {
			return;
		}

		if ( 'auto' === $mode ) {
			BHEC_Subscription::auto_subscribe_customer( $email, $customer_id );
			return;
		}

		// Explicit mode: check if the checkbox was ticked.
		// phpcs:ignore WordPress.Security.NonceVerification
		$opted_in = isset( $_POST['bhec_marketing_optin'] ) && '1' === $_POST['bhec_marketing_optin'];

		if ( $opted_in ) {
			BHEC_Subscription::subscribe( $email, $customer_id, 'checkout' );
		}
	}
}
