<?php
/**
 * WooCommerce My Account — Email Preferences tab.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_MyAccount {

	/**
	 * Register the My Account endpoint.
	 */
	public function register_endpoint() {
		add_rewrite_endpoint( 'email-preferences', EP_ROOT | EP_PAGES );

		// Flush rewrite rules once if our endpoint isn't registered yet.
		if ( ! get_option( 'bhec_rewrite_flushed' ) ) {
			flush_rewrite_rules();
			update_option( 'bhec_rewrite_flushed', '1' );
		}
	}

	/**
	 * Add the menu item to My Account navigation.
	 *
	 * @param array $items Menu items.
	 * @return array
	 */
	public function add_menu_item( $items ) {
		// Insert before "Logout".
		$logout = array();
		if ( isset( $items['customer-logout'] ) ) {
			$logout = array( 'customer-logout' => $items['customer-logout'] );
			unset( $items['customer-logout'] );
		}

		$items['email-preferences'] = __( 'Email Preferences', 'backhaus-email-campaigns' );

		return array_merge( $items, $logout );
	}

	/**
	 * The addresses this preference covers: the account email, and the billing
	 * emails on the customer's own paid orders (campaigns go to those, and they
	 * can differ). Not the profile's billing email on its own: anyone can type
	 * any address there, and a choice made here must not reach someone else's
	 * inbox. Every paid order counts, not only the latest.
	 *
	 * @param WP_User $user Customer.
	 * @return string[] Lowercase addresses.
	 */
	public static function addresses( $user ) {
		$emails = array( $user->user_email );
		$page   = 1;
		do {
			$orders = wc_get_orders(
				array(
					'type'        => 'shop_order',
					'customer_id' => $user->ID,
					'status'      => wc_get_is_paid_statuses(),
					'limit'       => 200,
					'page'        => $page++,
				)
			);
			foreach ( $orders as $order ) {
				$emails[] = $order->get_billing_email();
			}
		} while ( 200 === count( $orders ) );

		return array_values( array_unique( array_filter( array_map( 'strtolower', $emails ), 'is_email' ) ) );
	}

	/**
	 * Render the Email Preferences content.
	 */
	public function render_content() {
		$user  = wp_get_current_user();
		$email = $user->user_email;

		$emails = self::addresses( $user );

		// Handle form submission.
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['bhec_email_pref_nonce'] ) ) {
			if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bhec_email_pref_nonce'] ) ), 'bhec_email_preferences' ) ) {
				$subscribe = isset( $_POST['bhec_subscribe'] ) && '1' === $_POST['bhec_subscribe'];
				foreach ( $emails as $address ) {
					if ( ! $subscribe ) {
						BHEC_Subscription::unsubscribe( $address );
					} elseif ( ! BHEC_Subscription::is_subscribed( $address ) ) {
						// Only the account email is theirs for sure. Billing emails typed
						// on orders may belong to someone else, so those confirm by email.
						$source = strtolower( $email ) === $address ? 'myaccount' : 'myaccount_billing';
						BHEC_Subscription::subscribe( $address, $user->ID, $source );
					}
				}
				echo '<div class="woocommerce-message">' . esc_html__( 'Your email preferences have been updated.', 'backhaus-email-campaigns' ) . '</div>';
			}
		}

		// Shown as subscribed while any of the addresses still gets email.
		$is_subscribed = false;
		foreach ( $emails as $address ) {
			$is_subscribed = $is_subscribed || BHEC_Subscription::is_subscribed( $address );
		}
		$record = BHEC_Subscription::get_status( $email );
		$nonce  = wp_create_nonce( 'bhec_email_preferences' );

		?>
		<h3><?php esc_html_e( 'Email Preferences', 'backhaus-email-campaigns' ); ?></h3>
		<p><?php esc_html_e( 'Manage your marketing email subscription below. This does not affect transactional emails (order confirmations, shipping updates, etc.).', 'backhaus-email-campaigns' ); ?></p>

		<form method="post">
			<input type="hidden" name="bhec_email_pref_nonce" value="<?php echo esc_attr( $nonce ); ?>">

			<p>
				<label>
					<input type="checkbox" name="bhec_subscribe" value="1" <?php checked( $is_subscribed ); ?>>
					<?php esc_html_e( 'I would like to receive marketing emails, promotions, and updates.', 'backhaus-email-campaigns' ); ?>
				</label>
			</p>

			<?php if ( ! $is_subscribed && $record && $record->opted_out_at ) : ?>
				<p class="bhec-opt-out-info" style="font-size: 0.9em; color: #666;">
					<?php
					printf(
						/* translators: %s: date */
						esc_html__( 'You unsubscribed on %s.', 'backhaus-email-campaigns' ),
						esc_html( gmdate( get_option( 'date_format' ), strtotime( $record->opted_out_at ) ) )
					);
					?>
				</p>
			<?php endif; ?>

			<p>
				<button type="submit" class="woocommerce-Button button">
					<?php esc_html_e( 'Save Preferences', 'backhaus-email-campaigns' ); ?>
				</button>
			</p>
		</form>
		<?php
	}
}
