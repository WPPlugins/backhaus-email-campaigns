<?php
/**
 * CSV importer for opt-out email addresses.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_CSV_Importer {

	/**
	 * Import opt-out emails from an uploaded CSV file.
	 *
	 * @param array $file $_FILES entry.
	 * @return array|WP_Error { imported, skipped, invalid }
	 */
	public static function import( $file ) {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'upload_error', __( 'No file uploaded.', 'backhaus-email-campaigns' ) );
		}

		$filetype = wp_check_filetype( $file['name'] );
		if ( ! in_array( $filetype['ext'], array( 'csv', 'txt' ), true ) ) {
			return new WP_Error( 'invalid_type', __( 'Please upload a CSV or TXT file.', 'backhaus-email-campaigns' ) );
		}

		$handle = fopen( $file['tmp_name'], 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $handle ) {
			return new WP_Error( 'read_error', __( 'Could not read the uploaded file.', 'backhaus-email-campaigns' ) );
		}

		$emails  = array();
		$invalid = 0;

		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			foreach ( $row as $cell ) {
				$email = sanitize_email( trim( $cell ) );
				if ( is_email( $email ) ) {
					$emails[] = $email;
				} else {
					$trimmed = trim( $cell );
					// Skip header-like values.
					if ( ! empty( $trimmed ) && strtolower( $trimmed ) !== 'email' ) {
						$invalid++;
					}
				}
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( empty( $emails ) ) {
			return new WP_Error( 'no_emails', __( 'No valid email addresses found in the file.', 'backhaus-email-campaigns' ) );
		}

		$result = BHEC_Subscription::bulk_import_optout( $emails );
		$result['invalid'] = $invalid;

		return $result;
	}
}
