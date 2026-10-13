<?php
/**
 * Uninstall: remove all plugin data.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Free and Pro share these tables and options. Deleting one copy while another
// is installed (the usual Free to Pro upgrade, or an old copy left from before
// the 1.2 rename) must keep them. Keep these basenames in step with the main
// plugin file and build.ps1.
$bhec_editions = array(
	'backhaus-email-campaigns/backhaus-email-campaigns.php',
	'email-campaigns-pro-for-woocommerce/email-campaigns-pro-for-woocommerce.php',
	'woo-email-campaigns/woo-email-campaigns.php',
	'woo-email-campaigns-pro/woo-email-campaigns-pro.php',
);
foreach ( $bhec_editions as $bhec_edition ) {
	if ( WP_UNINSTALL_PLUGIN !== $bhec_edition && file_exists( WP_PLUGIN_DIR . '/' . $bhec_edition ) ) {
		return;
	}
}

require_once __DIR__ . '/includes/class-bhec-uninstaller.php';
BHEC_Uninstaller::run();
