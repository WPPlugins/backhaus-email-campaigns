<?php
/**
 * Settings > Status: the "is email working?" checks and the facts below them.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Admin_Status {

	/** WordPress's guide to running WP-Cron from the server. */
	const REAL_CRON_DOCS = 'https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/';

	/** A run this far overdue means sending is falling behind. */
	const LATE_AFTER = 15 * MINUTE_IN_SECONDS;

	/**
	 * The old Status page. Its address now redirects to the Settings tab
	 * before anything renders, so this only points the way.
	 */
	public static function render_page() {
		printf(
			'<div class="wrap"><p><a href="%s">%s</a></p></div>',
			esc_url( BHEC_Admin_Settings::url( 'status' ) ),
			esc_html__( 'Status is under Settings, Status.', 'backhaus-email-campaigns' )
		);
	}

	/**
	 * Everything that decides whether email goes out, one line each.
	 *
	 * @return array[] See line().
	 */
	public static function health_checks() {
		return array(
			self::check_background(),
			self::check_pauses(),
			self::check_last_sent(),
			self::check_mail(),
			self::check_sender_domain(),
		);
	}

	/**
	 * One line of the summary.
	 *
	 * @param string $label Line label.
	 * @param string $level good, warn, bad or none.
	 * @param array  $lines Plain text: the state first, then any notes.
	 * @param array  $more  Optional: link (escaped HTML after the notes),
	 *                      actions (escaped buttons), badge (text).
	 * @return array
	 */
	private static function line( $label, $level, $lines, $more = array() ) {
		$more = wp_parse_args(
			$more,
			array(
				'link'    => '',
				'actions' => '',
				'badge'   => 'good' === $level ? __( 'OK', 'backhaus-email-campaigns' ) : __( 'Needs Attention', 'backhaus-email-campaigns' ),
			)
		);
		return array_merge( compact( 'label', 'level', 'lines' ), $more );
	}

	/**
	 * The once-a-minute run that sends campaigns and automatic emails.
	 *
	 * @return array
	 */
	private static function check_background() {
		$label    = __( 'Background Sending', 'backhaus-email-campaigns' );
		$next     = wp_next_scheduled( 'bhec_process_queue' );
		$wp_cron  = ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
		$how_to   = sprintf(
			'<a href="%s" target="_blank" rel="noopener">%s<span class="screen-reader-text"> %s</span></a>',
			esc_url( self::REAL_CRON_DOCS ),
			esc_html__( 'How to Use a Real Cron Job', 'backhaus-email-campaigns' ),
			/* translators: accessibility text */
			esc_html__( '(opens in a new tab)', 'backhaus-email-campaigns' )
		);
		$visits   = __( 'It only runs when someone visits your site, so quiet hours can hold emails back.', 'backhaus-email-campaigns' );
		$time     = get_option( 'time_format' );

		if ( ! $next ) {
			return self::line(
				$label,
				'bad',
				array( __( 'It isn\'t scheduled, so no campaigns or automatic emails go out.', 'backhaus-email-campaigns' ) ),
				array( 'actions' => '<button type="button" class="button bhec-reschedule-cron">' . esc_html__( 'Start It Again', 'backhaus-email-campaigns' ) . '</button> <span class="bhec-tool-status" aria-live="polite"></span>' )
			);
		}

		$late = time() - $next;
		if ( $late > self::LATE_AFTER ) {
			return self::line(
				$label,
				'warn',
				array(
					/* translators: %s: time since, such as "25 mins" */
					sprintf( __( 'It\'s behind: the next check was due %s ago.', 'backhaus-email-campaigns' ), human_time_diff( $next ) ),
					$wp_cron ? $visits : __( 'Check that your server\'s cron job still runs.', 'backhaus-email-campaigns' ),
				),
				array( 'link' => $wp_cron ? $how_to : '' )
			);
		}

		$lines = array(
			$next > time()
				/* translators: %s: time */
				? sprintf( __( 'Checks for emails to send every minute. Next check at %s.', 'backhaus-email-campaigns' ), wp_date( $time, $next ) )
				: __( 'Checks for emails to send every minute. The next check is due now.', 'backhaus-email-campaigns' ),
			$wp_cron ? $visits : __( 'Your server\'s cron job runs it.', 'backhaus-email-campaigns' ),
		);
		return self::line( $label, 'good', $lines, array( 'link' => $wp_cron ? $how_to : '' ) );
	}

	/**
	 * Pauses: the provider's slow-down, and the over-mailing guard's stop on
	 * automatic emails. Resume buttons use the shared .bhec-resume-sending.
	 *
	 * @return array
	 */
	private static function check_pauses() {
		$label     = __( 'Pauses', 'backhaus-email-campaigns' );
		$provider  = BHEC_Send_Control::provider_pause();
		$automatic = BHEC_Send_Control::automatic_pause();
		if ( ! $provider && ! $automatic ) {
			return self::line( $label, 'good', array( __( 'Nothing is paused.', 'backhaus-email-campaigns' ) ) );
		}

		$time  = get_option( 'time_format' );
		$lines = array();
		$html  = '';
		if ( $provider ) {
			$lines[] = sprintf(
				/* translators: 1: time paused, 2: time it resumes */
				__( 'Your mail provider asked us to slow down at %1$s, so nothing sends until %2$s. It resumes by itself.', 'backhaus-email-campaigns' ),
				wp_date( $time, (int) $provider['since'] ),
				wp_date( $time, (int) $provider['until'] )
			);
			if ( '' !== (string) $provider['reason'] ) {
				/* translators: %s: error from the mail provider */
				$lines[] = sprintf( __( 'Provider message: %s', 'backhaus-email-campaigns' ), $provider['reason'] );
			}
			$html .= '<button type="button" class="button bhec-resume-sending" data-type="provider">' . esc_html__( 'Resume Now', 'backhaus-email-campaigns' ) . '</button> ';
		}
		if ( $automatic ) {
			/* translators: %s: why automatic emails were paused */
			$lines[] = sprintf( __( 'Automatic emails are paused: %s', 'backhaus-email-campaigns' ), $automatic['reason'] );
			$lines[] = __( 'Nobody gets automatic emails until you resume them.', 'backhaus-email-campaigns' );
			$html   .= '<button type="button" class="button bhec-resume-sending" data-type="automatic">' . esc_html__( 'Resume Automatic Emails', 'backhaus-email-campaigns' ) . '</button>';
		}
		return self::line( $label, $automatic ? 'bad' : 'warn', $lines, array( 'actions' => $html ) );
	}

	/**
	 * When an email last went out, and whether the latest attempt failed.
	 *
	 * @return array
	 */
	private static function check_last_sent() {
		global $wpdb;
		$label = __( 'Last Email Sent', 'backhaus-email-campaigns' );

		// Queue times are store-local; log times are on the database clock.
		$local = function ( $datetime ) {
			$date = $datetime ? date_create( $datetime, wp_timezone() ) : false;
			return $date ? $date->getTimestamp() : 0;
		};
		$utc   = function ( $datetime ) {
			return BHEC_Clock::timestamp( $datetime );
		};

		$last = max(
			$local( $wpdb->get_var( "SELECT MAX(sent_at) FROM {$wpdb->prefix}bhec_queue WHERE status = 'sent'" ) ),
			$local( $wpdb->get_var( "SELECT MAX(sent_at) FROM {$wpdb->prefix}bhec_triggered_queue WHERE status = 'sent'" ) ),
			$utc( $wpdb->get_var( "SELECT sent_at FROM {$wpdb->prefix}bhec_logs WHERE status = 'sent' ORDER BY sent_at DESC LIMIT 1" ) )
		);
		$failed = $wpdb->get_row( "SELECT sent_at, error_message FROM {$wpdb->prefix}bhec_logs WHERE status = 'failed' ORDER BY sent_at DESC LIMIT 1" );
		$failed_at = $failed ? $utc( $failed->sent_at ) : 0;

		$failed_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( add_query_arg( 'result', 'failed', BHEC_Admin_Settings::url( 'logs' ) ) ),
			esc_html__( 'See Failed Emails', 'backhaus-email-campaigns' )
		);

		if ( ! $last && ! $failed_at ) {
			return self::line( $label, 'none', array( __( 'Nothing has gone out yet.', 'backhaus-email-campaigns' ) ), array( 'badge' => __( 'Nothing Yet', 'backhaus-email-campaigns' ) ) );
		}

		$lines = array();
		if ( $last ) {
			$lines[] = sprintf(
				/* translators: 1: date and time, 2: time since, such as "5 mins" */
				__( '%1$s, %2$s ago.', 'backhaus-email-campaigns' ),
				BHEC_Admin_Campaigns::format_local( wp_date( 'Y-m-d H:i:s', $last ) ),
				human_time_diff( $last )
			);
		} else {
			$lines[] = __( 'Nothing has gone out yet.', 'backhaus-email-campaigns' );
		}
		if ( $failed_at <= $last ) {
			return self::line( $label, 'good', $lines );
		}
		// The latest try failed: often the first sign of a broken mail setup.
		$lines[] = sprintf(
			/* translators: %s: error message */
			__( 'The latest email failed: %s', 'backhaus-email-campaigns' ),
			$failed->error_message
		);
		return self::line( $label, $last ? 'warn' : 'bad', $lines, array( 'link' => $failed_link ) );
	}

	/**
	 * Which plugin hands email to a mail provider, if one we know is active.
	 *
	 * @return array|null { name, via } or null for WordPress's own mail.
	 */
	public static function mail_plugin() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active( 'wp-mail-smtp/wp_mail_smtp.php' ) || is_plugin_active( 'wp-mail-smtp-pro/wp_mail_smtp.php' ) ) {
			$options = get_option( 'wp_mail_smtp', array() );
			$mailer  = isset( $options['mail']['mailer'] ) ? sanitize_key( $options['mail']['mailer'] ) : '';
			$names   = array(
				'smtp'         => 'SMTP',
				'gmail'        => 'Google',
				'outlook'      => 'Microsoft 365',
				'mailgun'      => 'Mailgun',
				'sendgrid'     => 'SendGrid',
				'amazonses'    => 'Amazon SES',
				'postmark'     => 'Postmark',
				'sparkpost'    => 'SparkPost',
				'sendinblue'   => 'Brevo',
				'brevo'        => 'Brevo',
				'zoho'         => 'Zoho Mail',
				'smtpcom'      => 'SMTP.com',
				'sendlayer'    => 'SendLayer',
				'elasticemail' => 'Elastic Email',
				'mailjet'      => 'Mailjet',
				'mailersend'   => 'MailerSend',
				'mandrill'     => 'Mandrill',
				'resend'       => 'Resend',
				'smtp2go'      => 'SMTP2GO',
			);
			return array(
				'name' => 'WP Mail SMTP',
				// 'mail' is WP Mail SMTP set to WordPress's own mail.
				'via'  => 'mail' === $mailer || '' === $mailer ? '' : ( $names[ $mailer ] ?? ucfirst( $mailer ) ),
			);
		}
		$others = array(
			'fluent-smtp/fluent-smtp.php'           => 'FluentSMTP',
			'post-smtp/postman-smtp.php'            => 'Post SMTP',
			'easy-wp-smtp/easy-wp-smtp.php'         => 'Easy WP SMTP',
			'wp-ses/wp-ses.php'                     => 'WP Offload SES',
			'wp-offload-ses/wp-offload-ses.php'     => 'WP Offload SES',
			'mailgun/mailgun.php'                   => 'Mailgun',
		);
		foreach ( $others as $file => $name ) {
			if ( is_plugin_active( $file ) ) {
				return array(
					'name' => $name,
					'via'  => null,
				);
			}
		}
		return null;
	}

	/**
	 * How email leaves the site.
	 *
	 * @return array
	 */
	private static function check_mail() {
		$label  = __( 'Mail Setup', 'backhaus-email-campaigns' );
		$plugin = self::mail_plugin();
		$spam   = __( 'Email sent straight from a web server often lands in spam. A mail plugin that sends through your email provider is more reliable.', 'backhaus-email-campaigns' );

		if ( ! $plugin ) {
			return self::line( $label, 'warn', array( __( 'No mail plugin found, so WordPress sends email from your web server.', 'backhaus-email-campaigns' ), $spam ) );
		}
		if ( '' === $plugin['via'] ) {
			return self::line(
				$label,
				'warn',
				/* translators: %s: mail plugin name */
				array( sprintf( __( '%s is set to send from your web server.', 'backhaus-email-campaigns' ), $plugin['name'] ), $spam )
			);
		}
		$line = null === $plugin['via']
			/* translators: %s: mail plugin name */
			? sprintf( __( '%s sends your email.', 'backhaus-email-campaigns' ), $plugin['name'] )
			/* translators: 1: mail plugin name, 2: email service */
			: sprintf( __( '%1$s sends your email through %2$s.', 'backhaus-email-campaigns' ), $plugin['name'], $plugin['via'] );
		return self::line( $label, 'good', array( $line ) );
	}

	/** Free mailbox domains: mail claiming one of these from a store's server fails DMARC. */
	const FREE_MAILBOXES = array( 'gmail.com', 'googlemail.com', 'yahoo.com', 'ymail.com', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'icloud.com', 'me.com', 'mac.com', 'aol.com', 'proton.me', 'protonmail.com', 'gmx.com', 'gmx.net', 'yandex.com', 'mail.com', 'zoho.com', 'qq.com', '163.com' );

	/**
	 * DKIM selectors to look for, by the service the mail plugin sends
	 * through. A selector can be any name, so these are the usual ones.
	 */
	const DKIM_SELECTORS = array(
		'Google'        => array( 'google' ),
		'Microsoft 365' => array( 'selector1', 'selector2' ),
		'Mailgun'       => array( 'k1', 'mx', 'smtp', 'krs', 'pic' ),
		'SendGrid'      => array( 's1', 's2' ),
		'Brevo'         => array( 'brevo1', 'brevo2', 'mail' ),
		'Zoho Mail'     => array( 'zmail', 'zoho' ),
		'Mailjet'       => array( 'mailjet' ),
		'MailerSend'    => array( 'mlsend', 'mlsend2' ),
		'Resend'        => array( 'resend' ),
		'Mandrill'      => array( 'mandrill', 'mte1', 'mte2' ),
		''              => array( 'default', 'dkim', 'mail', 'google', 'selector1', 'selector2', 'k1', 's1' ),
	);

	/**
	 * Whether inboxes can tell mail from the sender address is really the
	 * store's: SPF, DKIM and DMARC for its domain. Gmail and Yahoo expect all
	 * three from stores that send in bulk.
	 *
	 * @return array
	 */
	private static function check_sender_domain() {
		$label  = __( 'Sender Domain', 'backhaus-email-campaigns' );
		$from   = (string) ( get_option( 'bhec_from_email' ) ?: get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) ) );
		$at     = strrpos( $from, '@' );
		$domain = false === $at ? '' : strtolower( substr( $from, $at + 1 ) );
		$skip   = array( 'badge' => __( 'Not Checked', 'backhaus-email-campaigns' ) );

		if ( '' === $domain ) {
			return self::line( $label, 'warn', array( __( 'There\'s no sender address. Set one under General, From Email.', 'backhaus-email-campaigns' ) ) );
		}
		if ( in_array( $domain, self::FREE_MAILBOXES, true ) ) {
			return self::line(
				$label,
				'bad',
				array(
					/* translators: %s: email address */
					sprintf( __( 'Your emails come from %s, a free mailbox. Inboxes turn away or spam-folder mail that claims a free mailbox address but is sent from somewhere else.', 'backhaus-email-campaigns' ), $from ),
					__( 'Send from an address at your own domain (General, From Email).', 'backhaus-email-campaigns' ),
				)
			);
		}
		// A local name has no public records, unless the site answers for it (bhec_pre_dns_txt).
		if ( ( false === strpos( $domain, '.' ) || preg_match( '/\.(test|local|localhost|example|invalid)$/', $domain ) ) && ! has_filter( 'bhec_pre_dns_txt' ) ) {
			/* translators: %s: domain name */
			return self::line( $label, 'none', array( sprintf( __( '%s is a local address, so it isn\'t checked.', 'backhaus-email-campaigns' ), $domain ) ), $skip );
		}

		$dns = self::sender_dns( $domain );
		if ( null === $dns ) {
			/* translators: %s: domain name */
			return self::line( $label, 'none', array( sprintf( __( 'This server can\'t look up DNS records, so %s wasn\'t checked.', 'backhaus-email-campaigns' ), $domain ) ), $skip );
		}

		$found = array();
		$fix   = array();
		if ( 1 === count( $dns['spf'] ) ) {
			$found[] = 'SPF';
		} elseif ( ! $dns['spf'] ) {
			$fix[] = __( 'No SPF record, so inboxes can\'t tell your mail service may send for this domain. Your mail service\'s setup guide gives the record to add.', 'backhaus-email-campaigns' );
		} else {
			$fix[] = __( 'More than one SPF record: inboxes ignore them all. Merge them into one.', 'backhaus-email-campaigns' );
		}
		if ( '' !== $dns['dkim'] ) {
			$found[] = 'DKIM';
		}
		if ( '' !== $dns['dmarc'] ) {
			$found[] = 'DMARC';
		} else {
			$fix[] = __( 'No DMARC record. To start, add a TXT record named _dmarc with the value v=DMARC1; p=none;', 'backhaus-email-campaigns' );
		}

		$lines = array(
			$found
				/* translators: 1: domain name, 2: list such as "SPF, DKIM and DMARC" */
				? sprintf( __( '%1$s has %2$s set up.', 'backhaus-email-campaigns' ), $domain, wp_sprintf( '%l', $found ) )
				/* translators: %s: domain name */
				: sprintf( __( '%s has none of SPF, DKIM or DMARC set up.', 'backhaus-email-campaigns' ), $domain ),
		);
		if ( '' === $dns['dkim'] ) {
			$lines[] = __( 'DKIM wasn\'t found under the usual names. It can sit under any name, so check your mail service\'s domain settings.', 'backhaus-email-campaigns' );
		}

		return self::line( $label, $fix ? 'warn' : 'good', array_merge( $lines, $fix ) );
	}

	/**
	 * The sender domain's SPF, DMARC and DKIM records, kept for an hour.
	 *
	 * @param string $domain Domain.
	 * @return array|null { spf: string[], dmarc: string, dkim: string (the selector) }, or null if DNS can't be looked up.
	 */
	private static function sender_dns( $domain ) {
		$plugin    = self::mail_plugin();
		$via       = $plugin && $plugin['via'] ? $plugin['via'] : '';
		$selectors = self::DKIM_SELECTORS[ $via ] ?? self::DKIM_SELECTORS[''];
		$key       = 'bhec_dns_' . md5( $domain . '|' . implode( ',', $selectors ) );
		$cached    = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$txt = self::txt( $domain );
		if ( null === $txt ) {
			return null;
		}
		$starts = static function ( $records, $prefix ) {
			return array_values(
				array_filter(
					(array) $records,
					static function ( $record ) use ( $prefix ) {
						return 0 === stripos( ltrim( $record ), $prefix );
					}
				)
			);
		};

		$dkim = '';
		foreach ( $selectors as $selector ) {
			foreach ( (array) self::txt( $selector . '._domainkey.' . $domain ) as $record ) {
				// An empty p= is a revoked key.
				if ( preg_match( '#(^|;)\s*p=\s*[A-Za-z0-9+/]#', $record ) ) {
					$dkim = $selector;
					break 2;
				}
			}
		}

		$result = array(
			'spf'   => $starts( $txt, 'v=spf1' ),
			'dmarc' => (string) ( $starts( self::txt( '_dmarc.' . $domain ), 'v=DMARC1' )[0] ?? '' ),
			'dkim'  => $dkim,
		);
		set_transient( $key, $result, HOUR_IN_SECONDS );
		return $result;
	}

	/**
	 * TXT records at a name. The bhec_pre_dns_txt filter can answer instead
	 * (tests, or a host whose PHP can't reach DNS).
	 *
	 * @param string $name DNS name.
	 * @return string[]|null Records, or null if the lookup failed.
	 */
	public static function txt( $name ) {
		$pre = apply_filters( 'bhec_pre_dns_txt', null, $name );
		if ( null !== $pre ) {
			return (array) $pre;
		}
		if ( ! function_exists( 'dns_get_record' ) ) {
			return null;
		}
		$records = @dns_get_record( $name, DNS_TXT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a name with no records warns.
		if ( false === $records ) {
			return null;
		}
		return array_map(
			static function ( $record ) {
				return isset( $record['entries'] ) ? implode( '', $record['entries'] ) : (string) ( $record['txt'] ?? '' );
			},
			$records
		);
	}

	/**
	 * The plugin's tables with their size.
	 *
	 * @return array Rows: name, rows, bytes.
	 */
	public static function tables() {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME AS name, TABLE_ROWS AS `rows`, DATA_LENGTH + INDEX_LENGTH AS bytes
				FROM information_schema.TABLES
				WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s
				ORDER BY TABLE_NAME',
				DB_NAME,
				$wpdb->esc_like( $wpdb->prefix . 'bhec_' ) . '%'
			)
		);
	}
}
