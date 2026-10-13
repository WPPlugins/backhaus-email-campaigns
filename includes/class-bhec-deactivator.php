<?php
/**
 * Plugin deactivation: clear scheduled crons.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Deactivator {

	/**
	 * Run on plugin deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'bhec_process_queue' );
		wp_clear_scheduled_hook( 'bhec_daily_maintenance' );
		flush_rewrite_rules();
	}
}
