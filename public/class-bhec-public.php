<?php
/**
 * Public-facing request handler — routes unsubscribe and tracking requests.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Public {

	/**
	 * Handle incoming public requests.
	 */
	public function handle_request() {
		// Tracking requests.
		if ( null !== self::link_param( 'track' ) ) {
			BHEC_Tracker::handle_tracking_request();
			return;
		}

		// Unsubscribe requests.
		if ( null !== self::link_param( 'unsubscribe' ) ) {
			BHEC_Unsubscribe::handle();
			return;
		}

		// Double opt-in confirmation (Pro only).
		if ( class_exists( 'BHEC_Double_Optin' ) && null !== self::link_param( 'confirm_optin' ) ) {
			BHEC_Double_Optin::handle_confirmation();
			return;
		}
	}

	/**
	 * A parameter of a link from an email: bhec_<name>, or wec_<name> in
	 * emails sent before 1.5 (the plugin's old prefix), whose unsubscribe and
	 * tracking links must keep working. Links from emails can't carry a nonce;
	 * each handler checks the link's token or signature instead.
	 *
	 * @param string $name track, unsubscribe or confirm_optin.
	 * @return string|null Null when the link has neither.
	 */
	public static function link_param( $name ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		foreach ( array( 'bhec_', 'wec_' ) as $prefix ) {
			if ( isset( $_GET[ $prefix . $name ] ) ) {
				return sanitize_text_field( wp_unslash( $_GET[ $prefix . $name ] ) );
			}
		}
		// phpcs:enable
		return null;
	}

	/**
	 * Register rewrite rules.
	 */
	public function register_rewrite_rules() {
		// No custom rewrite rules needed — we use query parameters.
	}

	/**
	 * Register custom query variables.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function register_query_vars( $vars ) {
		$vars[] = 'bhec_unsubscribe';
		$vars[] = 'bhec_track';
		return $vars;
	}
}
