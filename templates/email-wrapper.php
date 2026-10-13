<?php
/**
 * Fallback email wrapper template.
 *
 * This file is not normally used — the plugin wraps email content
 * using WooCommerce's own email templates (email-header.php / email-footer.php).
 * This exists only as a fallback if WooCommerce templates are unavailable.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0; padding:20px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; background:#f7f7f7;">
	<div style="max-width:600px; margin:0 auto; background:#fff; padding:30px; border-radius:4px;">
		<?php if ( ! empty( $email_heading ) ) : ?>
			<h1 style="margin:0 0 20px; font-size:24px; color:#333;"><?php echo esc_html( $email_heading ); ?></h1>
		<?php endif; ?>

		<?php echo wp_kses_post( $email_body ); ?>
	</div>
</body>
</html>
