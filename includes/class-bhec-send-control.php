<?php
/**
 * Sending safety shared by campaigns and automatic emails: what a failed send
 * means, pausing when the mail provider asks us to slow down, retry timing,
 * pacing and sending caps. Works with any wp_mail() setup.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Send_Control {

	/** Pause lengths after successive provider slow-downs, in minutes. */
	const PROVIDER_BACKOFF_MINUTES = array( 1, 5, 15, 60 );

	/** Waits before retrying an email that failed for an unclear reason, in minutes. */
	const RETRY_DELAY_MINUTES = array( 5, 30, 120 );

	/** This many unclear failures in a row usually means the mail setup is broken. */
	const CONSECUTIVE_FAILURES_PAUSE = 10;

	/**
	 * An email put back this many times for slow-downs is treated as failed.
	 * An error wrongly read as a slow-down would otherwise hold the queue forever.
	 */
	const MAX_DEFERRALS = 5;

	/** @var int Unclear failures in a row during this request. */
	private static $consecutive_failures = 0;

	/** @var bool Whether this request already cleared the backoff step. */
	private static $backoff_cleared = false;

	/** @var float When this request last sent an email (microtime). */
	private static $last_send = 0.0;

	/**
	 * Send through wp_mail() and capture the mail library's error, if any.
	 *
	 * @return array { bool sent, string error }
	 */
	public static function send( $to, $subject, $html, $headers, $attachments = array() ) {
		$error = '';
		$catch = static function ( $wp_error ) use ( &$error ) {
			if ( is_wp_error( $wp_error ) ) {
				$error = $wp_error->get_error_message();
			}
		};
		add_action( 'wp_mail_failed', $catch );
		$sent = wp_mail( $to, $subject, $html, $headers, $attachments );
		remove_action( 'wp_mail_failed', $catch );

		if ( $sent ) {
			self::note_success();
			return array( true, '' );
		}
		if ( '' === $error ) {
			$error = __( 'The mail setup reported a failure without a reason.', 'backhaus-email-campaigns' );
		}
		return array( false, $error );
	}

	/**
	 * What a failed send means.
	 *
	 * 'throttle': the provider is limiting volume (retry later, not this person's fault).
	 * 'permanent': this address can't receive mail.
	 * 'unknown': anything else; retry after a delay.
	 *
	 * @param string $message Error from the mail library or provider.
	 * @return string
	 */
	public static function classify( $message ) {
		$m = strtolower( (string) $message );

		// Checked first: some providers report volume limits with 5xx codes
		// (Gmail's "550 5.4.5 Daily sending quota exceeded", Microsoft's
		// "5.1.90 ... daily limit"). SMTP codes only count as separate tokens,
		// not inside an address such as x.451@example.com. "Over quota" alone
		// is a full mailbox, not a sending limit.
		if ( preg_match( '/(?<![\w.@-])(421|429|450|451|452)(?![\w@])|(?<![\w.@-])4\.7\.\d+|rate.?limit|(submission|sending|message) rate|too many|throttl|try again later|(sending|submission|daily|message) quota|quota ?exceeded|limit exceeded|sending limit|daily limit|exceeded (its|your|the) limit|temporar/', $m ) ) {
			return 'throttle';
		}
		// Only rejections of the recipient's address. Anything about the sender
		// (5.1.7, 5.1.8, "Invalid address: (From)") is a setup problem, and a
		// bare 550 may be one too.
		$about_sender = false !== strpos( $m, 'sender' ) || false !== strpos( $m, '(from)' );
		if ( ! $about_sender && preg_match( '/\b5\.1\.(1|2|3|6|10)\b|user unknown|no such user|does not exist|mailbox (unavailable|not found)|invalid recipient|recipient (address )?rejected/', $m ) ) {
			return 'permanent';
		}
		return 'unknown';
	}

	/**
	 * Start of a sending run: "in a row" counts within one run.
	 */
	public static function start_run() {
		self::$consecutive_failures = 0;
	}

	/**
	 * Record a failed send (bad address or unclear). Many in a row usually
	 * mean the mail setup is broken, so sending pauses like a slow-down.
	 *
	 * @param string $error Last error.
	 */
	public static function note_failure( $error ) {
		self::$consecutive_failures++;
		if ( self::$consecutive_failures >= self::CONSECUTIVE_FAILURES_PAUSE ) {
			self::$consecutive_failures = 0;
			self::pause_provider(
				sprintf(
					/* translators: 1: number of emails, 2: last error message */
					__( '%1$d emails in a row failed. Last error: %2$s', 'backhaus-email-campaigns' ),
					self::CONSECUTIVE_FAILURES_PAUSE,
					$error
				)
			);
		}
	}

	/**
	 * A send worked: the provider is accepting mail again.
	 */
	private static function note_success() {
		self::$consecutive_failures = 0;
		if ( ! self::$backoff_cleared ) {
			self::$backoff_cleared = true;
			if ( get_option( 'bhec_provider_pause_step' ) ) {
				update_option( 'bhec_provider_pause_step', 0, false );
			}
		}
	}

	/**
	 * Pause all sending after the provider asked us to slow down. Each pause
	 * in a row lasts longer; a successful send resets it.
	 *
	 * @param string $reason Provider's message.
	 */
	public static function pause_provider( $reason ) {
		// A slow-down long after the last pause starts the backoff again.
		$last = get_option( 'bhec_provider_pause' );
		$step = ( is_array( $last ) && time() - (int) $last['until'] > HOUR_IN_SECONDS ) ? 0 : (int) get_option( 'bhec_provider_pause_step', 0 );

		$minutes = self::PROVIDER_BACKOFF_MINUTES[ min( $step, count( self::PROVIDER_BACKOFF_MINUTES ) - 1 ) ];

		update_option(
			'bhec_provider_pause',
			array(
				'until'  => time() + $minutes * MINUTE_IN_SECONDS,
				'since'  => time(),
				'reason' => mb_substr( (string) $reason, 0, 500 ),
			),
			false
		);
		update_option( 'bhec_provider_pause_step', $step + 1, false );
		self::$backoff_cleared = false;
	}

	/**
	 * Current provider pause, or null.
	 *
	 * @return array|null { until, since, reason }
	 */
	public static function provider_pause() {
		$pause = get_option( 'bhec_provider_pause' );
		return ( is_array( $pause ) && (int) $pause['until'] > time() ) ? $pause : null;
	}

	/**
	 * End a provider pause now (admin "Resume Now").
	 */
	public static function resume_provider() {
		delete_option( 'bhec_provider_pause' );
	}

	/**
	 * How long to wait before retrying an email that failed for an unclear reason.
	 *
	 * @param int $attempt The attempt that just failed (1-based).
	 * @return int Seconds.
	 */
	public static function retry_delay( $attempt ) {
		$delays = self::RETRY_DELAY_MINUTES;
		return $delays[ max( 0, min( (int) $attempt - 1, count( $delays ) - 1 ) ) ] * MINUTE_IN_SECONDS;
	}

	/**
	 * When to retry, as a UTC MySQL datetime.
	 *
	 * @param int $attempt The attempt that just failed (1-based).
	 * @return string
	 */
	public static function retry_at( $attempt ) {
		return gmdate( 'Y-m-d H:i:s', time() + self::retry_delay( $attempt ) );
	}

	/**
	 * Wait for a sending slot: the delay between emails and even spacing within
	 * this request, and the per-minute cap across every request (counted from
	 * the database, since cron and Live Processing can run side by side). Call
	 * before each email.
	 *
	 * @param int $deadline Unix time the run must finish by.
	 * @return bool False if no slot opens before the deadline: stop the run.
	 */
	public static function wait_for_slot( $deadline ) {
		$per_minute = (int) get_option( 'bhec_max_emails_per_minute', 0 );
		$gap        = max( (float) get_option( 'bhec_batch_delay', 0 ), $per_minute > 0 ? 60 / $per_minute : 0 );

		if ( $gap > 0 && self::$last_send > 0 ) {
			$wait = self::$last_send + $gap - microtime( true );
			if ( $wait > 0 ) {
				usleep( (int) ( $wait * 1000000 ) );
			}
		}

		if ( $per_minute > 0 ) {
			while ( self::count_sent_since( wp_date( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ) ) >= $per_minute ) {
				if ( time() + 5 >= $deadline ) {
					return false;
				}
				sleep( 5 );
			}
		}

		self::$last_send = microtime( true );
		return true;
	}

	/**
	 * Take a named lock. MySQL releases it by itself if the process dies.
	 * Names are server-wide, so they include the database and table prefix.
	 *
	 * @param string $name Lock name ('queue', 'triggered').
	 * @param int    $wait Seconds to wait for it.
	 * @return bool
	 */
	public static function lock( $name, $wait = 0 ) {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::lock_name( $name ), $wait ) );
	}

	/**
	 * Release a named lock.
	 *
	 * @param string $name Lock name.
	 */
	public static function unlock( $name ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name( $name ) ) );
	}

	/**
	 * @param string $name Lock name.
	 * @return string
	 */
	private static function lock_name( $name ) {
		global $wpdb;
		return 'bhec_' . $name . '_' . substr( md5( DB_NAME . '|' . $wpdb->prefix ), 0, 16 );
	}

	/**
	 * How many more emails the hourly and daily caps allow right now. Counts
	 * campaigns and automatic emails together, since both use the same provider.
	 *
	 * @return int PHP_INT_MAX when no cap is set.
	 */
	public static function remaining_capacity() {
		$remaining = PHP_INT_MAX;

		$per_hour = (int) get_option( 'bhec_max_emails_per_hour', 0 );
		if ( $per_hour > 0 ) {
			$remaining = min( $remaining, $per_hour - self::count_sent_since( wp_date( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) );
		}

		$per_day = (int) get_option( 'bhec_max_emails_per_day', 0 );
		if ( $per_day > 0 ) {
			$remaining = min( $remaining, $per_day - self::count_sent_since( wp_date( 'Y-m-d 00:00:00' ) ) );
		}

		return max( 0, $remaining );
	}

	/**
	 * Campaign and automatic emails sent since a store-local time.
	 *
	 * @param string $since MySQL datetime, store-local (as sent_at is stored).
	 * @return int
	 */
	private static function count_sent_since( $since ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ( SELECT COUNT(*) FROM {$wpdb->prefix}bhec_queue WHERE status = 'sent' AND sent_at >= %s )
					+ ( SELECT COUNT(*) FROM {$wpdb->prefix}bhec_triggered_queue WHERE status = 'sent' AND sent_at >= %s )",
				$since,
				$since
			)
		);
	}

	/**
	 * Pause automatic emails (the over-mailing guard tripped). Stays paused
	 * until an admin resumes, and tells the admin once.
	 *
	 * @param string $reason Why.
	 */
	public static function pause_automatic( $reason ) {
		if ( self::automatic_pause() ) {
			return;
		}
		update_option(
			'bhec_automatic_pause',
			array(
				'since'  => time(),
				'reason' => $reason,
			),
			false
		);

		wp_mail(
			get_option( 'admin_email' ),
			sprintf(
				/* translators: %s: site name */
				__( '[%s] Automatic emails paused', 'backhaus-email-campaigns' ),
				get_bloginfo( 'name' )
			),
			sprintf(
				/* translators: 1: reason, 2: admin URL */
				__( "Email Campaigns paused automatic emails to protect your customers.\n\n%1\$s\n\nThis usually means a rule fires more often than intended, for example on every order. Nobody is emailed while it's paused. Check your rules, then resume sending here:\n%2\$s", 'backhaus-email-campaigns' ),
				$reason,
				admin_url( 'admin.php?page=bhec-triggered' )
			)
		);
	}

	/**
	 * Current automatic-email pause, or null.
	 *
	 * @return array|null { since, reason }
	 */
	public static function automatic_pause() {
		$pause = get_option( 'bhec_automatic_pause' );
		return is_array( $pause ) ? $pause : null;
	}

	/**
	 * Resume automatic emails (admin). Holds from before now no longer count
	 * toward the breaker.
	 */
	public static function resume_automatic() {
		delete_option( 'bhec_automatic_pause' );
		update_option( 'bhec_automatic_resumed_at', time(), false );
	}
}
