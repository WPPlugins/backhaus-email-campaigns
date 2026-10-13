<?php
/**
 * The free edition has no license: this only tells shared code it is the
 * free build. Pro's features aren't in it at all.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_License {
	public static function is_pro() {
		return false;
	}

	public static function is_pro_build() {
		return false;
	}

	public static function get_status() {
		return 'free';
	}
}

function bhec_is_pro() {
	return false;
}