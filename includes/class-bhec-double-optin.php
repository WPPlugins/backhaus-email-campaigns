<?php
/**
 * Double opt-in confirmation system.
 *
 * When enabled, new subscribers receive a confirmation email with a
 * verification link. They are marked as 'pending' until confirmed.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Double_Optin {

	/**
	 * Check if double opt-in is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return 'yes' === get_option( 'bhec_double_optin', 'no' );
	}

	/**
	 * Send a confirmation email to a subscriber.
	 *
	 * @param string $email Email address.
	 * @return bool
	 */
	public static function send_confirmation( $email ) {
		$token         = BHEC_Subscription::get_token( $email );
		$confirm_url   = add_query_arg( 'bhec_confirm_optin', $token, home_url( '/' ) );
		$store_name    = get_bloginfo( 'name' );
		$from_name     = get_option( 'bhec_from_name' ) ?: $store_name;
		$from_email    = get_option( 'bhec_from_email' ) ?: get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) );

		$subject = sprintf(
			/* translators: %s: store name */
			__( 'Confirm your subscription to %s', 'backhaus-email-campaigns' ),
			$store_name
		);

		$body = sprintf(
			/* translators: 1: store name, 2: confirmation URL */
			__( 'Thank you for subscribing to marketing emails from %1$s. Please confirm your subscription by clicking the link below:

%2$s

If you did not request this, you can safely ignore this email.', 'backhaus-email-campaigns' ),
			$store_name,
			$confirm_url
		);

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			"From: {$from_name} <{$from_email}>",
		);

		// Wrap in a simple HTML template.
		$html = '<div style="font-family:-apple-system,sans-serif;max-width:600px;margin:20px auto;padding:30px;">'
			. '<h2>' . esc_html( $subject ) . '</h2>'
			. '<p>' . nl2br( esc_html( $body ) ) . '</p>'
			. '<p><a href="' . esc_url( $confirm_url ) . '" style="display:inline-block;background:#0073aa;color:#fff;padding:12px 24px;text-decoration:none;border-radius:4px;">'
			. esc_html__( 'Confirm Subscription', 'backhaus-email-campaigns' )
			. '</a></p>'
			. '</div>';

		// Replace the plain text URL in the HTML with the button.
		$html = str_replace( esc_html( $confirm_url ), '', $html );

		return wp_mail( $email, $subject, $html, $headers );
	}

	/**
	 * Handle the confirmation link click.
	 */
	public static function handle_confirmation() {
		// A link from an email: the token is the proof, a nonce can't work there.
		$token = (string) BHEC_Public::link_param( 'confirm_optin' );
		if ( '' === $token ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bhec_subscriptions';
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, email, status FROM {$wpdb->prefix}bhec_subscriptions WHERE token = %s", $token )
		);

		if ( ! $row ) {
			self::render_page(
				__( 'Invalid Link', 'backhaus-email-campaigns' ),
				__( 'This confirmation link is invalid or has expired.', 'backhaus-email-campaigns' )
			);
			return;
		}

		if ( 'subscribed' === $row->status ) {
			self::render_page(
				__( 'Already Confirmed', 'backhaus-email-campaigns' ),
				__( 'Your subscription is already confirmed.', 'backhaus-email-campaigns' )
			);
			return;
		}

		// Only a pending sign-up can be confirmed. This link shares its token with
		// the unsubscribe link, so it must never undo an opt-out.
		if ( 'pending' !== $row->status ) {
			self::render_page(
				__( 'Link No Longer Valid', 'backhaus-email-campaigns' ),
				__( 'You unsubscribed after this link was sent, so it no longer does anything. To get our emails again, turn them on in your account, or tick the box next time you check out.', 'backhaus-email-campaigns' )
			);
			return;
		}

		// Opening the link only shows a button. Mail scanners (Safe Links and
		// the like) open every link, and must not confirm on someone's behalf.
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			self::render_page(
				__( 'Confirm Your Subscription', 'backhaus-email-campaigns' ),
				'<p>' . sprintf(
					/* translators: %s: email address */
					__( 'Press the button to start receiving marketing emails at %s.', 'backhaus-email-campaigns' ),
					'<strong>' . esc_html( $row->email ) . '</strong>'
				) . '</p>'
				. '<form method="post"><button type="submit" style="background:#0073aa;color:#fff;border:none;padding:10px 24px;font-size:16px;cursor:pointer;border-radius:4px;">'
				. esc_html__( 'Confirm Subscription', 'backhaus-email-campaigns' )
				. '</button></form>'
			);
			return;
		}

		$wpdb->update(
			$table,
			array(
				'status'       => 'subscribed',
				'opted_in_at'  => current_time( 'mysql' ),
				'opted_out_at' => null,
			),
			array( 'id' => $row->id, 'status' => 'pending' ),
			array( '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);

		self::render_page(
			__( 'Subscription Confirmed', 'backhaus-email-campaigns' ),
			sprintf(
				/* translators: %s: email address */
				__( 'Thank you! %s has been confirmed for marketing emails.', 'backhaus-email-campaigns' ),
				'<strong>' . esc_html( $row->email ) . '</strong>'
			)
		);
	}

	/**
	 * Render a simple standalone page.
	 *
	 * @param string $title   Page title.
	 * @param string $content HTML content.
	 */
	private static function render_page( $title, $content ) {
		$store_name = get_bloginfo( 'name' );
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html( $title . ' - ' . $store_name ); ?></title>
			<style>
				body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f1f1f1; margin: 0; padding: 40px 20px; }
				.bhec-confirm-box { max-width: 500px; margin: 0 auto; background: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center; }
				.bhec-confirm-box h1 { font-size: 24px; margin: 0 0 16px; color: #333; }
				.bhec-confirm-box p { color: #666; line-height: 1.6; }
			</style>
		</head>
		<body>
			<div class="bhec-confirm-box">
				<h1><?php echo esc_html( $title ); ?></h1>
				<?php
				echo wp_kses( $content, array(
					'p'      => array(),
					'strong' => array(),
					'form'   => array( 'method' => array() ),
					'button' => array( 'type' => array(), 'style' => array() ),
				) );
				?>
				<p style="font-size: 14px; color: #999; margin-top: 24px;"><?php echo esc_html( $store_name ); ?></p>
			</div>
		</body>
		</html>
		<?php
		exit;
	}
}
