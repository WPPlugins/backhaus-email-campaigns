<?php
/**
 * Subscription management — opt-in / opt-out for marketing emails.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Subscription {

	/**
	 * Check if an email is subscribed.
	 *
	 * @param string $email Email address.
	 * @return bool
	 */
	public static function is_subscribed( $email ) {
		global $wpdb;
		$table  = $wpdb->prefix . 'bhec_subscriptions';
		$status = $wpdb->get_var(
			$wpdb->prepare( "SELECT status FROM $table WHERE email = %s", $email )
		);

		if ( null === $status ) {
			// No record: subscribed only if auto mode.
			return 'auto' === get_option( 'bhec_optin_mode', 'auto' );
		}

		return 'subscribed' === $status;
	}

	/**
	 * Subscribe an email address.
	 *
	 * An opt-out is only lifted by the person themselves: signed in to My
	 * Account (their account email), or by confirming an email. A checkout tick
	 * or My Account choice for another opted-out address (the billing email is
	 * unverified) sends one confirmation email; an admin or an import cannot
	 * lift an opt-out at all. An admin can confirm a pending sign-up that
	 * doesn't follow an opt-out. A new billing address from My Account always
	 * confirms by email first, and Free (no confirmation emails) can't take one.
	 *
	 * @param string $email       Email address.
	 * @param int    $customer_id WC customer ID (0 for guest).
	 * @param string $source      auto|checkout|myaccount|myaccount_billing|admin|import.
	 * @return bool True if the address is subscribed afterwards.
	 */
	public static function subscribe( $email, $customer_id = 0, $source = 'admin' ) {
		// Free sends no confirmation emails, so it can't take a billing address
		// that may belong to someone else (HANDOFF S1).
		if ( 'myaccount_billing' === $source && ! class_exists( 'BHEC_Double_Optin' ) ) {
			return false;
		}

		global $wpdb;
		$table    = $wpdb->prefix . 'bhec_subscriptions';
		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, status, opted_out_at FROM $table WHERE email = %s", $email )
		);

		$token = wp_generate_password( 64, false );

		// An 'unsubscribed' row with no opt-out time only holds an unsubscribe
		// token (get_token() in explicit mode). The person never opted out, so
		// treat it like a new sign-up, keeping the token already in sent emails.
		if ( $existing && 'unsubscribed' === $existing->status && empty( $existing->opted_out_at ) ) {
			$status = self::initial_status( $source );
			$wpdb->update(
				$table,
				array(
					'status'      => $status,
					'source'      => $source,
					'customer_id' => $customer_id,
					'opted_in_at' => 'subscribed' === $status ? current_time( 'mysql' ) : null,
				),
				array( 'id' => $existing->id ),
				array( '%s', '%s', '%d', '%s' ),
				array( '%d' )
			);
			if ( 'pending' === $status ) {
				BHEC_Double_Optin::send_confirmation( $email );
			}
			return 'subscribed' === $status;
		}

		if ( $existing ) {
			if ( 'subscribed' === $existing->status ) {
				return true;
			}

			// An admin can also subscribe a bounced address again, say once
			// the customer has fixed their mailbox.
			$direct = 'myaccount' === $source
				|| ( 'admin' === $source && 'pending' === $existing->status && empty( $existing->opted_out_at ) )
				|| ( 'admin' === $source && 'bounced' === $existing->status );

			if ( $direct ) {
				return (bool) $wpdb->update(
					$table,
					array(
						'status'       => 'subscribed',
						'source'       => $source,
						'customer_id'  => $customer_id,
						'opted_in_at'  => current_time( 'mysql' ),
						'opted_out_at' => null,
					),
					array( 'id' => $existing->id ),
					array( '%s', '%s', '%d', '%s', '%s' ),
					array( '%d' )
				);
			}

			// One confirmation per opt-out: a row that is already pending gets no more.
			if ( in_array( $source, array( 'checkout', 'myaccount_billing' ), true ) && 'unsubscribed' === $existing->status && class_exists( 'BHEC_Double_Optin' ) ) {
				$wpdb->update(
					$table,
					array(
						'status' => 'pending',
						'source' => $source,
					),
					array( 'id' => $existing->id, 'status' => 'unsubscribed' ),
					array( '%s', '%s' ),
					array( '%d', '%s' )
				);
				BHEC_Double_Optin::send_confirmation( $email );
			}

			return false;
		}

		$initial_status = self::initial_status( $source );

		$result = (bool) $wpdb->insert(
			$table,
			array(
				'email'       => $email,
				'customer_id' => $customer_id,
				'status'      => $initial_status,
				'source'      => $source,
				'token'       => $token,
				'opted_in_at' => 'subscribed' === $initial_status ? current_time( 'mysql' ) : null,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( $result && 'pending' === $initial_status ) {
			BHEC_Double_Optin::send_confirmation( $email );
		}

		return $result && 'subscribed' === $initial_status;
	}

	/**
	 * Status for a new sign-up: 'pending' (confirmation email) when double
	 * opt-in is on (Pro), except for admin additions and imports.
	 *
	 * @param string $source Sign-up source.
	 * @return string
	 */
	private static function initial_status( $source ) {
		if ( ! class_exists( 'BHEC_Double_Optin' ) || in_array( $source, array( 'admin', 'import' ), true ) ) {
			return 'subscribed';
		}
		// A billing address a customer typed may not be theirs, so it always
		// confirms by email, whatever the double opt-in setting.
		return ( 'myaccount_billing' === $source || BHEC_Double_Optin::is_enabled() ) ? 'pending' : 'subscribed';
	}

	/**
	 * Stop all email to an address whose mailbox doesn't exist, as the mail
	 * service said while sending (a hard bounce). Sending to it again only
	 * hurts the store's standing with inboxes. An opt-out stays an opt-out;
	 * an admin can subscribe a bounced address again.
	 *
	 * @param string $email Email address.
	 */
	public static function mark_bounced( $email ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bhec_subscriptions';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, status, opted_out_at FROM $table WHERE email = %s", $email ) );

		if ( ! $row ) {
			$wpdb->insert(
				$table,
				array(
					'email'  => $email,
					'status' => 'bounced',
					'source' => 'bounce',
					'token'  => wp_generate_password( 64, false ),
				),
				array( '%s', '%s', '%s', '%s' )
			);
		} elseif ( 'unsubscribed' !== $row->status || empty( $row->opted_out_at ) ) {
			$wpdb->update( $table, array( 'status' => 'bounced' ), array( 'id' => $row->id ), array( '%s' ), array( '%d' ) );
		}
	}

	/**
	 * Whether sending to this address failed because its mailbox doesn't exist.
	 *
	 * @param string $email Email address.
	 * @return bool
	 */
	public static function has_bounced( $email ) {
		global $wpdb;
		return 'bounced' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}bhec_subscriptions WHERE email = %s", $email ) );
	}

	/**
	 * Unsubscribe an email address.
	 *
	 * @param string $email Email address.
	 * @return bool
	 */
	public static function unsubscribe( $email ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bhec_subscriptions';

		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT id FROM $table WHERE email = %s", $email )
		);

		if ( $existing ) {
			return (bool) $wpdb->update(
				$table,
				array(
					'status'       => 'unsubscribed',
					'opted_out_at' => current_time( 'mysql' ),
				),
				array( 'id' => $existing->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}

		// Create a new record as unsubscribed.
		$token = wp_generate_password( 64, false );
		return (bool) $wpdb->insert(
			$table,
			array(
				'email'        => $email,
				'status'       => 'unsubscribed',
				'source'       => 'admin',
				'token'        => $token,
				'opted_out_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Unsubscribe by token (for public unsubscribe endpoint).
	 *
	 * @param string $token Unique unsubscribe token.
	 * @return string|false Email address on success, false on failure.
	 */
	public static function unsubscribe_by_token( $token ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bhec_subscriptions';

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, email FROM $table WHERE token = %s", $token )
		);

		if ( ! $row ) {
			return false;
		}

		$wpdb->update(
			$table,
			array(
				'status'       => 'unsubscribed',
				'opted_out_at' => current_time( 'mysql' ),
			),
			array( 'id' => $row->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return $row->email;
	}

	/**
	 * Auto-subscribe a customer (only in auto mode, never re-subscribes opt-outs).
	 *
	 * @param string $email       Email address.
	 * @param int    $customer_id WC customer ID.
	 * @return bool
	 */
	public static function auto_subscribe_customer( $email, $customer_id = 0 ) {
		if ( 'auto' !== get_option( 'bhec_optin_mode', 'auto' ) ) {
			return false;
		}

		global $wpdb;
		$table    = $wpdb->prefix . 'bhec_subscriptions';
		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, status FROM $table WHERE email = %s", $email )
		);

		// Never re-subscribe someone who explicitly opted out.
		if ( $existing ) {
			return false;
		}

		$token = wp_generate_password( 64, false );
		return (bool) $wpdb->insert(
			$table,
			array(
				'email'       => $email,
				'customer_id' => $customer_id,
				'status'      => 'subscribed',
				'source'      => 'auto',
				'token'       => $token,
				'opted_in_at' => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Bulk import opt-out email addresses.
	 *
	 * @param array $emails Array of email addresses.
	 * @return array { imported: int, skipped: int }
	 */
	public static function bulk_import_optout( $emails ) {
		$imported = 0;
		$skipped  = 0;

		foreach ( $emails as $email ) {
			$email = sanitize_email( trim( $email ) );
			if ( ! is_email( $email ) ) {
				$skipped++;
				continue;
			}

			global $wpdb;
			$table    = $wpdb->prefix . 'bhec_subscriptions';
			$existing = $wpdb->get_row(
				$wpdb->prepare( "SELECT id, status, opted_out_at FROM $table WHERE email = %s", $email )
			);

			// Already opted out: keep the original date. A row that only holds
			// an unsubscribe-link token isn't an opt-out, so it gets recorded.
			if ( $existing && 'unsubscribed' === $existing->status && ! empty( $existing->opted_out_at ) ) {
				$skipped++;
				continue;
			}

			if ( $existing ) {
				$wpdb->update(
					$table,
					array(
						'status'       => 'unsubscribed',
						'source'       => 'import',
						'opted_out_at' => current_time( 'mysql' ),
					),
					array( 'id' => $existing->id ),
					array( '%s', '%s', '%s' ),
					array( '%d' )
				);
			} else {
				$token = wp_generate_password( 64, false );
				$wpdb->insert(
					$table,
					array(
						'email'        => $email,
						'status'       => 'unsubscribed',
						'source'       => 'import',
						'token'        => $token,
						'opted_out_at' => current_time( 'mysql' ),
					),
					array( '%s', '%s', '%s', '%s', '%s' )
				);
			}

			$imported++;
		}

		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
		);
	}

	/**
	 * Get the unsubscribe URL for an email.
	 *
	 * @param string $email Email address.
	 * @return string
	 */
	public static function get_unsubscribe_url( $email ) {
		$token = self::get_token( $email );
		return add_query_arg( 'bhec_unsubscribe', $token, home_url( '/' ) );
	}

	/**
	 * Get or create a token for an email address.
	 *
	 * @param string $email Email address.
	 * @return string
	 */
	public static function get_token( $email ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bhec_subscriptions';
		$token = $wpdb->get_var(
			$wpdb->prepare( "SELECT token FROM $table WHERE email = %s", $email )
		);

		if ( $token ) {
			return $token;
		}

		// Create a record so a token exists for the unsubscribe link.
		$token = wp_generate_password( 64, false );
		$wpdb->insert(
			$table,
			array(
				'email'  => $email,
				'status' => 'auto' === get_option( 'bhec_optin_mode', 'auto' ) ? 'subscribed' : 'unsubscribed',
				'source' => 'auto',
				'token'  => $token,
			),
			array( '%s', '%s', '%s', '%s' )
		);

		return $token;
	}

	/**
	 * Get the full subscription record for an email.
	 *
	 * @param string $email Email address.
	 * @return object|null
	 */
	public static function get_status( $email ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bhec_subscriptions';
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM $table WHERE email = %s", $email )
		);
	}
}
