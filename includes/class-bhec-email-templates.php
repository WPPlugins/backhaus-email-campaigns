<?php
/**
 * Email layout template management.
 *
 * Layout templates define the header/footer wrapping for campaign emails.
 * Body content templates (reusable email body text) are separate and stored in the DB.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Email_Templates {

	/**
	 * Get all available layout templates.
	 *
	 * @return array [ id => label ]
	 */
	public static function get_layout_templates() {
		$templates = array(
			'default' => __( 'WooCommerce Default', 'backhaus-email-campaigns' ),
			'plain'   => __( 'Plain HTML (no WC styling)', 'backhaus-email-campaigns' ),
		);

		/**
		 * Filter available email layout templates.
		 *
		 * Custom templates should provide header.php and footer.php files.
		 * Register them by adding to this array:
		 *   $templates['my-template'] = 'My Custom Template';
		 *
		 * Then place header.php and footer.php in:
		 *   {theme}/backhaus-email-campaigns/email-templates/{my-template}/
		 * (email-campaigns-for-woocommerce/ from before 1.5 and woo-email-campaigns/
		 * from before 1.2 still work).
		 *
		 * @param array $templates [ id => label ]
		 */
		return apply_filters( 'bhec_email_layout_templates', $templates );
	}

	/**
	 * Render email header for a given layout template.
	 *
	 * @param string $template_id Layout template ID.
	 * @param string $heading     Email heading text.
	 * @return string HTML header.
	 */
	public static function render_header( $template_id, $heading ) {
		ob_start();

		switch ( $template_id ) {
			case 'plain':
				self::render_plain_header( $heading );
				break;

			case 'default':
			default:
				wc_get_template(
					'emails/email-header.php',
					array( 'email_heading' => $heading )
				);
				break;
		}

		// Check for custom template in theme.
		if ( ! in_array( $template_id, array( 'default', 'plain' ), true ) ) {
			$theme_file = self::theme_file( $template_id, 'header.php' );
			if ( $theme_file ) {
				ob_clean();
				include $theme_file;
			}
		}

		return ob_get_clean();
	}

	/**
	 * Render email footer for a given layout template.
	 *
	 * @param string $template_id Layout template ID.
	 * @return string HTML footer.
	 */
	public static function render_footer( $template_id ) {
		ob_start();

		switch ( $template_id ) {
			case 'plain':
				self::render_plain_footer();
				break;

			case 'default':
			default:
				wc_get_template( 'emails/email-footer.php' );
				break;
		}

		// Check for custom template in theme.
		if ( ! in_array( $template_id, array( 'default', 'plain' ), true ) ) {
			$theme_file = self::theme_file( $template_id, 'footer.php' );
			if ( $theme_file ) {
				ob_clean();
				include $theme_file;
			}
		}

		return ob_get_clean();
	}

	/**
	 * Plain HTML header.
	 *
	 * @param string $heading Email heading.
	 */
	private static function render_plain_header( $heading ) {
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="UTF-8">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<style>
				body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f7f7f7; margin: 0; padding: 0; }
				.bhec-plain-wrap { max-width: 600px; margin: 20px auto; background: #ffffff; padding: 30px; }
				.bhec-plain-heading { font-size: 24px; font-weight: 600; color: #333333; margin: 0 0 20px 0; }
				a { color: #0073aa; }
			</style>
		</head>
		<body>
			<div class="bhec-plain-wrap">
				<?php if ( $heading ) : ?>
					<h1 class="bhec-plain-heading"><?php echo esc_html( $heading ); ?></h1>
				<?php endif; ?>
		<?php
	}

	/**
	 * Plain HTML footer.
	 */
	private static function render_plain_footer() {
		$store_name = get_bloginfo( 'name' );
		?>
			</div>
		</body>
		</html>
		<?php
	}

	/**
	 * Get all saved body content templates from the database.
	 *
	 * @return array
	 */
	public static function get_body_templates() {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT id, name, body, created_at FROM {$wpdb->prefix}bhec_templates ORDER BY name ASC"
		);
	}

	/**
	 * Save a body content template.
	 *
	 * @param string $name Template name.
	 * @param string $body Template body HTML.
	 * @param int    $id   Optional ID for update.
	 * @return int|false Insert ID or false.
	 */
	public static function save_body_template( $name, $body, $id = 0 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bhec_templates';

		if ( $id > 0 ) {
			$wpdb->update(
				$table,
				array(
					'name' => sanitize_text_field( $name ),
					'body' => wp_kses_post( $body ),
				),
				array( 'id' => $id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			return $id;
		}

		$wpdb->insert(
			$table,
			array(
				'name'       => sanitize_text_field( $name ),
				'body'       => wp_kses_post( $body ),
				'created_by' => get_current_user_id(),
			),
			array( '%s', '%s', '%d' )
		);

		return $wpdb->insert_id ?: false;
	}

	/**
	 * Delete a body content template.
	 *
	 * @param int $id Template ID.
	 * @return bool
	 */
	public static function delete_body_template( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete(
			$wpdb->prefix . 'bhec_templates',
			array( 'id' => $id ),
			array( '%d' )
		);
	}

	/**
	 * A theme's own layout file, from the folder named after the plugin, or
	 * the one it used before the 1.2 rename.
	 *
	 * @param string $template_id Layout template ID.
	 * @param string $file        header.php or footer.php.
	 * @return string Path, or '' if the theme has none.
	 */
	private static function theme_file( $template_id, $file ) {
		foreach ( array( 'backhaus-email-campaigns', 'email-campaigns-for-woocommerce', 'woo-email-campaigns' ) as $folder ) {
			$path = get_stylesheet_directory() . '/' . $folder . '/email-templates/' . sanitize_file_name( $template_id ) . '/' . $file;
			if ( file_exists( $path ) ) {
				return $path;
			}
		}
		return '';
	}
}
