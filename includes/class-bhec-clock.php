<?php
/**
 * The database clock. Columns filled by CURRENT_TIMESTAMP (log times,
 * tracking events, created and updated times) use the MySQL server's time
 * zone, which is UTC on most hosts but not all.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Clock {

	/** @var int|null Seconds, per request. */
	private static $offset = null;

	/**
	 * Seconds the database clock runs ahead of UTC.
	 *
	 * @return int
	 */
	public static function db_offset() {
		if ( null === self::$offset ) {
			global $wpdb;
			// Whole quarter hours: every real time zone is one.
			self::$offset = (int) ( round( (int) $wpdb->get_var( 'SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())' ) / 900 ) * 900 );
		}
		return self::$offset;
	}

	/**
	 * A database-clock datetime as a Unix timestamp.
	 *
	 * @param string $datetime MySQL datetime from a CURRENT_TIMESTAMP column.
	 * @return int 0 for an empty value.
	 */
	public static function timestamp( $datetime ) {
		return $datetime ? (int) strtotime( $datetime . ' UTC' ) - self::db_offset() : 0;
	}

	/**
	 * A database-clock datetime in UTC.
	 *
	 * @param string $datetime MySQL datetime from a CURRENT_TIMESTAMP column.
	 * @return string
	 */
	public static function utc( $datetime ) {
		return $datetime ? gmdate( 'Y-m-d H:i:s', self::timestamp( $datetime ) ) : '';
	}

	/**
	 * A Unix timestamp on the database clock, for comparing with those columns.
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	public static function db( $timestamp ) {
		return gmdate( 'Y-m-d H:i:s', $timestamp + self::db_offset() );
	}
}
