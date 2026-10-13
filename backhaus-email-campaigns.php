<?php
/**
 * Plugin Name: BackHAUS Email Campaigns for WooCommerce
 * Plugin URI:  https://backhaus.sg/email-campaigns
 * Description: Email your WooCommerce customers from WordPress, sent through your store's own mail setup.
 * Version:     1.5.2
 * Author:      BackHAUS
 * Author URI:  https://backhaus.sg/
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: backhaus-email-campaigns
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 11.1
 * Requires Plugins: woocommerce
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

// Free and Pro share every class and function name. If the other edition is
// already running, stand down instead of crashing the site. This file must
// not declare named functions: PHP binds those before this check runs.
if ( defined( 'BHEC_VERSION' ) ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-warning"><p>';
		esc_html_e( 'Two copies of Email Campaigns are active. Keep one (Pro if you have it, and the newest version) and deactivate the other from the Plugins screen. Don\'t delete it from there: deleting a copy can remove your campaigns and subscribers.', 'backhaus-email-campaigns' );
		echo '</p></div>';
	} );
	return;
}

// Plugin constants.
define( 'BHEC_VERSION', '1.5.2' );
define( 'BHEC_PLUGIN_FILE', __FILE__ );
define( 'BHEC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BHEC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BHEC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Declare HPOS compatibility.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
	}
} );

// When an edition is activated, the copies it replaces are deactivated: Free
// when Pro is active (Pro includes everything in it), Free from before 1.5
// (the email-campaigns-for-woocommerce folder), and copies from before 1.2,
// which used the woo-email-campaigns folder names. Keep these basenames in
// step with build.ps1 and uninstall.php.
add_action( 'activated_plugin', function ( $plugin ) {
	$free   = 'backhaus-email-campaigns/backhaus-email-campaigns.php';
	$pro    = 'email-campaigns-pro-for-woocommerce/email-campaigns-pro-for-woocommerce.php';
	$legacy = array( 'email-campaigns-for-woocommerce/email-campaigns-for-woocommerce.php', 'woo-email-campaigns/woo-email-campaigns.php', 'woo-email-campaigns-pro/woo-email-campaigns-pro.php' );
	if ( ! in_array( $plugin, array( $free, $pro ), true ) ) {
		return;
	}
	$replaced = array_values( array_filter( $legacy, 'is_plugin_active' ) );
	if ( is_plugin_active( $free ) && is_plugin_active( $pro ) ) {
		$replaced[] = $free;
	}
	if ( $replaced ) {
		deactivate_plugins( $replaced );
		set_transient( 'bhec_free_deactivated_notice', 1, MINUTE_IN_SECONDS );
	}
} );

add_action( 'admin_notices', function () {
	if ( ! get_transient( 'bhec_free_deactivated_notice' ) ) {
		return;
	}
	delete_transient( 'bhec_free_deactivated_notice' );
	echo '<div class="notice notice-info is-dismissible"><p>';
	esc_html_e( 'The other copy of Email Campaigns has been deactivated. Your campaigns, subscribers and settings are shared and unchanged. Remove its folder by hand if you like, but don\'t delete it from the Plugins screen.', 'backhaus-email-campaigns' );
	echo '</p></div>';
} );

// Activation / deactivation hooks (run before WC check so tables are always created).
register_activation_hook( __FILE__, function () {
	require_once BHEC_PLUGIN_DIR . 'includes/class-bhec-activator.php';
	BHEC_Activator::activate();
} );

register_deactivation_hook( __FILE__, function () {
	require_once BHEC_PLUGIN_DIR . 'includes/class-bhec-deactivator.php';
	BHEC_Deactivator::deactivate();
} );

/**
 * Bootstrap the plugin after all plugins are loaded.
 */
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>';
			esc_html_e( 'Email Campaigns needs WooCommerce to be installed and active.', 'backhaus-email-campaigns' );
			echo '</p></div>';
		} );
		return;
	}

	require_once BHEC_PLUGIN_DIR . 'includes/class-bhec-loader.php';
	$loader = new BHEC_Loader();
	$loader->run();
}, 20 );
