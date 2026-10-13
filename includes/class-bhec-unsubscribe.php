<?php
/**
 * Public unsubscribe endpoint handler.
 *
 * GET shows a confirmation page, so link scanners and prefetching never
 * unsubscribe anyone. Any POST with a valid token unsubscribes: the confirm
 * button, and RFC 8058 one-click requests from mail providers (Gmail, Yahoo),
 * which carry no nonce. The 64-character token is the credential.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Unsubscribe {

	/**
	 * Handle unsubscribe requests.
	 */
	public static function handle() {
		$token = (string) BHEC_Public::link_param( 'unsubscribe' );
		if ( empty( $token ) ) {
			return;
		}

		// Verify token exists.
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT email, status FROM {$wpdb->prefix}bhec_subscriptions WHERE token = %s", $token )
		);

		if ( ! $row ) {
			self::render_page(
				__( 'Invalid Link', 'backhaus-email-campaigns' ),
				__( 'This unsubscribe link is invalid or has expired.', 'backhaus-email-campaigns' )
			);
			return;
		}

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			$email = BHEC_Subscription::unsubscribe_by_token( $token );

			// phpcs:ignore WordPress.Security.NonceVerification
			if ( isset( $_POST['List-Unsubscribe'] ) && 'One-Click' === $_POST['List-Unsubscribe'] ) {
				nocache_headers();
				status_header( $email ? 200 : 500 );
				header( 'Content-Type: text/plain; charset=utf-8' );
				echo $email ? 'Unsubscribed.' : 'Error.';
				exit;
			}

			if ( $email ) {
				self::render_page(
					__( 'Unsubscribed', 'backhaus-email-campaigns' ),
					sprintf(
						/* translators: %s: email address */
						__( 'You have been successfully unsubscribed. %s will no longer receive marketing emails from us.', 'backhaus-email-campaigns' ),
						'<strong>' . esc_html( $email ) . '</strong>'
					)
				);
			} else {
				self::render_page(
					__( 'Error', 'backhaus-email-campaigns' ),
					__( 'Something went wrong. Please try again or contact us.', 'backhaus-email-campaigns' )
				);
			}
			return;
		}

		// GET = show confirmation form.
		if ( 'unsubscribed' === $row->status ) {
			self::render_page(
				__( 'Already Unsubscribed', 'backhaus-email-campaigns' ),
				sprintf(
					/* translators: %s: email address */
					__( '%s is already unsubscribed from marketing emails.', 'backhaus-email-campaigns' ),
					'<strong>' . esc_html( $row->email ) . '</strong>'
				)
			);
			return;
		}

		$form = '<p>' . sprintf(
			/* translators: %s: email address */
			__( 'Are you sure you want to unsubscribe %s from marketing emails?', 'backhaus-email-campaigns' ),
			'<strong>' . esc_html( $row->email ) . '</strong>'
		) . '</p>'
			. '<form method="post">'
			. '<button type="submit" style="background:#0073aa;color:#fff;border:none;padding:10px 24px;font-size:16px;cursor:pointer;border-radius:4px;">'
			. esc_html__( 'Confirm Unsubscribe', 'backhaus-email-campaigns' )
			. '</button>'
			. '</form>';

		self::render_page( __( 'Unsubscribe', 'backhaus-email-campaigns' ), $form );
	}

	/**
	 * Render a standalone page and stop, so the theme doesn't render after it.
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
				.bhec-unsubscribe-box { max-width: 500px; margin: 0 auto; background: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center; }
				.bhec-unsubscribe-box h1 { font-size: 24px; margin: 0 0 16px; color: #333; }
				.bhec-unsubscribe-box p { color: #666; line-height: 1.6; }
				.bhec-store-name { font-size: 14px; color: #999; margin-top: 24px; }
			</style>
		</head>
		<body>
			<div class="bhec-unsubscribe-box">
				<h1><?php echo esc_html( $title ); ?></h1>
				<?php
				echo wp_kses( $content, array(
					'p'      => array(),
					'strong' => array(),
					'a'      => array( 'href' => array(), 'style' => array() ),
					'form'   => array( 'method' => array(), 'action' => array() ),
					'input'  => array( 'type' => array(), 'name' => array(), 'value' => array() ),
					'button' => array( 'type' => array(), 'style' => array() ),
				) );
				?>
				<p class="bhec-store-name"><?php echo esc_html( $store_name ); ?></p>
			</div>
		</body>
		</html>
		<?php
		exit;
	}
}
