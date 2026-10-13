<?php
/**
 * Email sender — assembles and dispatches emails via wp_mail.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Sender {

	/**
	 * Send an email for a queue item.
	 *
	 * @param array  $queue_item Queue row as associative array.
	 * @param object $campaign   Campaign row object.
	 * @return string 'sent', 'skipped' (unsubscribed), 'held' (over-mailing
	 *                guard), 'failed' or 'deferred' (provider asked us to slow down).
	 */
	public function send_email( $queue_item, $campaign ) {
		$queue   = new BHEC_Queue();
		$email   = $queue_item['customer_email'];
		$queue_id = (int) $queue_item['id'];

		// Check subscription at send-time.
		if ( ! BHEC_Subscription::is_subscribed( $email ) ) {
			$queue->mark_skipped( $queue_id );
			return 'skipped';
		}

		// Before building anything (a coupon, say): would this over-mail them?
		if ( empty( $queue_item['guard_override'] ) ) {
			$hold = BHEC_Send_Guard::campaign_hold_reason( $email, (int) $campaign->id );
			if ( $hold ) {
				$queue->mark_held( $queue_id, $hold );
				return 'held';
			}
		}

		// Load customer data for merge tags.
		$customer_data = BHEC_Merge_Tags::get_customer_data(
			(int) $queue_item['customer_id'],
			$email
		);

		// Resolve merge tags in subject. A subject test's second group gets the other subject.
		$raw_subject = 'B' === ( $queue_item['variant'] ?? '' ) && '' !== (string) ( $campaign->subject_b ?? '' ) ? $campaign->subject_b : $campaign->subject;
		$subject     = BHEC_Merge_Tags::replace( $raw_subject, $customer_data, 'text' );

		// Resolve merge tags in body.
		$body = BHEC_Merge_Tags::replace( $campaign->body, $customer_data );

		// Generate unique coupon if {{ coupon }} tag is present (Pro only).
		if ( class_exists( 'BHEC_Coupon' ) && false !== strpos( $body, '{{ coupon }}' ) ) {
			$coupon_settings = BHEC_Coupon::get_campaign_coupon_settings( (int) $campaign->id );
			if ( ! empty( $coupon_settings ) ) {
				$coupon_code = BHEC_Coupon::generate_coupon( (int) $campaign->id, $email, $coupon_settings );
				$body        = str_replace( '{{ coupon }}', $coupon_code, $body );
			}
		}

		// Also handle {{ coupon }} in subject.
		if ( class_exists( 'BHEC_Coupon' ) && false !== strpos( $subject, '{{ coupon }}' ) ) {
			if ( ! isset( $coupon_code ) ) {
				$coupon_settings = BHEC_Coupon::get_campaign_coupon_settings( (int) $campaign->id );
				if ( ! empty( $coupon_settings ) ) {
					$coupon_code = BHEC_Coupon::generate_coupon( (int) $campaign->id, $email, $coupon_settings );
				}
			}
			if ( isset( $coupon_code ) ) {
				$subject = str_replace( '{{ coupon }}', $coupon_code, $subject );
			}
		}

		// Add unsubscribe link before wrapping.
		$unsubscribe_url = $customer_data['unsubscribe_url'];
		$body           .= self::unsubscribe_footer( $unsubscribe_url );

		$body .= self::credit_footer();

		// Wrap in email layout template.
		$layout = ! empty( $campaign->layout_template ) ? $campaign->layout_template : 'default';
		$html   = $this->wrap_in_wc_template( $body, $subject, $layout );

		// Tag the destination before click tracking stores and replaces it.
		if ( 'yes' === get_option( 'bhec_utm_enabled', 'no' ) ) {
			$html = self::inject_utm_params( $html, $campaign );
		}

		// Inject tracking if enabled.
		if ( 'yes' === get_option( 'bhec_tracking_enabled', 'yes' ) ) {
			$html = BHEC_Tracker::inject_tracking_pixel( $html, (int) $campaign->id, $queue_id );
			$html = BHEC_Tracker::rewrite_links( $html, (int) $campaign->id, $queue_id, $email );
		}

		// Resolve the To address.
		$to = BHEC_Merge_Tags::replace( $campaign->email_to ?: '{{ customer.email }}', $customer_data, 'text' );

		// Build headers.
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
		);

		$from_name  = get_option( 'bhec_from_name' ) ?: get_bloginfo( 'name' );
		$from_email = get_option( 'bhec_from_email' ) ?: get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) );
		$headers[]  = "From: {$from_name} <{$from_email}>";

		// Reply-To.
		$reply_name  = $campaign->reply_to_name ?: $from_name;
		$reply_email = $campaign->reply_to_email ?: $from_email;
		$headers[]   = "Reply-To: {$reply_name} <{$reply_email}>";

		// CC.
		if ( ! empty( $campaign->email_cc ) ) {
			$cc = BHEC_Merge_Tags::replace( $campaign->email_cc, $customer_data, 'text' );
			foreach ( array_map( 'trim', explode( ',', $cc ) ) as $cc_addr ) {
				if ( is_email( $cc_addr ) ) {
					$headers[] = "Cc: {$cc_addr}";
				}
			}
		}

		// BCC.
		if ( ! empty( $campaign->email_bcc ) ) {
			$bcc = BHEC_Merge_Tags::replace( $campaign->email_bcc, $customer_data, 'text' );
			foreach ( array_map( 'trim', explode( ',', $bcc ) ) as $bcc_addr ) {
				if ( is_email( $bcc_addr ) ) {
					$headers[] = "Bcc: {$bcc_addr}";
				}
			}
		}

		// List-Unsubscribe header.
		$headers[] = "List-Unsubscribe: <{$unsubscribe_url}>";
		$headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';

		// Attachments.
		$attachments = array();
		if ( ! empty( $campaign->attachments ) ) {
			$attachment_ids = json_decode( $campaign->attachments, true );
			if ( is_array( $attachment_ids ) ) {
				foreach ( $attachment_ids as $att_id ) {
					$file = get_attached_file( $att_id );
					if ( $file && file_exists( $file ) ) {
						$attachments[] = $file;
					}
				}
			}
		}

		// Add plain text alternative for multipart email.
		$plain_text = wp_strip_all_tags( str_replace( array( '<br>', '<br/>', '<br />', '</p>' ), "\n", $html ) );
		$plain_text = html_entity_decode( $plain_text, ENT_QUOTES, 'UTF-8' );
		$plain_text = preg_replace( '/\n{3,}/', "\n\n", trim( $plain_text ) );

		$plain_text_callback = function ( $phpmailer ) use ( $plain_text ) {
			$phpmailer->AltBody = $plain_text;
		};
		add_action( 'phpmailer_init', $plain_text_callback );

		list( $sent, $error ) = BHEC_Send_Control::send( $to, $subject, $html, $headers, $attachments );

		remove_action( 'phpmailer_init', $plain_text_callback );

		global $wpdb;

		if ( $sent ) {
			BHEC_Logger::log( array(
				'campaign_id'     => (int) $campaign->id,
				'queue_id'        => $queue_id,
				'recipient_email' => $email,
				'recipient_name'  => $queue_item['customer_name'] ?? '',
				'subject'         => $subject,
				'status'          => 'sent',
				'email_body'      => $html,
			) );
			$queue->mark_sent( $queue_id );
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}bhec_campaigns SET sent_count = sent_count + 1 WHERE id = %d",
					$campaign->id
				)
			);
			return 'sent';
		}

		$kind = BHEC_Send_Control::classify( $error );

		// The provider is limiting volume: put it back and pause everything.
		// Deferred too often, it's probably not a slow-down after all.
		if ( 'throttle' === $kind ) {
			if ( (int) ( $queue_item['deferrals'] ?? 0 ) < BHEC_Send_Control::MAX_DEFERRALS ) {
				BHEC_Send_Control::pause_provider( $error );
				// Back of the line: after the pause, others go first. If only this
				// address keeps failing, the others' success resets the backoff.
				$pause = BHEC_Send_Control::provider_pause();
				$queue->mark_deferred( $queue_id, $error, (int) $pause['until'] + MINUTE_IN_SECONDS );
				return 'deferred';
			}
			$kind = 'unknown';
		}

		BHEC_Logger::log( array(
			'campaign_id'     => (int) $campaign->id,
			'queue_id'        => $queue_id,
			'recipient_email' => $email,
			'recipient_name'  => $queue_item['customer_name'] ?? '',
			'subject'         => $subject,
			'status'          => 'failed',
			'error_message'   => $error,
			'email_body'      => $html,
		) );
		BHEC_Send_Control::note_failure( $error );
		if ( 'permanent' === $kind ) {
			BHEC_Subscription::mark_bounced( $email );
		}
		if ( 'failed' === $queue->mark_failed( $queue_id, $error, 'permanent' === $kind ) ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}bhec_campaigns SET failed_count = failed_count + 1 WHERE id = %d",
					$campaign->id
				)
			);
		}
		return 'failed';
	}

	/**
	 * The unsubscribe line under every marketing email, preview and test.
	 *
	 * @param string $url Unsubscribe URL.
	 * @return string HTML.
	 */
	public static function unsubscribe_footer( $url ) {
		return '<p style="font-size: 12px; color: #999; margin-top: 20px;">'
			. sprintf(
				/* translators: %s: unsubscribe URL */
				__( 'Don\'t want to receive these emails? <a href="%s" style="color: #999;">Unsubscribe</a>', 'backhaus-email-campaigns' ),
				esc_url( $url )
			)
			. '</p>';
	}

	/**
	 * The free edition's credit line, only if the store turned it on
	 * (WordPress.org requires credits to be opt-in). Pro never adds it.
	 *
	 * @return string HTML, or ''.
	 */
	public static function credit_footer() {
		if ( bhec_is_pro() || 'yes' !== get_option( 'bhec_show_credit', 'no' ) ) {
			return '';
		}
		return '<p style="font-size: 11px; color: #bbb; margin-top: 10px; text-align: center;">'
			. sprintf(
				/* translators: %s: plugin name, linked */
				esc_html__( 'Sent with %s', 'backhaus-email-campaigns' ),
				'<a href="https://backhaus.sg/email-campaigns" style="color: #bbb;">BackHAUS Email Campaigns</a>'
			)
			. '</p>';
	}

	/**
	 * Wrap HTML body content in an email layout template.
	 *
	 * @param string $body        HTML body content.
	 * @param string $heading     Email heading.
	 * @param string $layout_id   Layout template ID (default: 'default').
	 * @return string Full HTML email.
	 */
	public function wrap_in_wc_template( $body, $heading, $layout_id = 'default' ) {
		$header  = BHEC_Email_Templates::render_header( $layout_id, $heading );
		$footer  = BHEC_Email_Templates::render_footer( $layout_id );
		$wrapped = $header . wp_kses_post( $body ) . $footer;

		// Replace WooCommerce email placeholders that the template uses.
		$store_address = WC()->countries->get_base_address();
		$store_city    = WC()->countries->get_base_city();
		$store_state   = WC()->countries->get_base_state();
		$store_country = WC()->countries->get_base_country();
		$store_postcode = WC()->countries->get_base_postcode();

		$address_parts = array_filter( array( $store_address, $store_city, $store_state, $store_postcode, $store_country ) );
		$full_address  = implode( ', ', $address_parts );

		$wrapped = str_replace(
			array( '{site_title}', '{site_address}', '{store_address}', '{site_url}' ),
			array(
				wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ),
				$full_address,
				$full_address,
				home_url( '/' ),
			),
			$wrapped
		);

		// Remove empty address line if no store address is configured.
		if ( empty( $full_address ) ) {
			$wrapped = preg_replace( '/<[^>]*>\s*,?\s*<\/[^>]*>\s*/i', '', $wrapped );
		}

		// Apply WC inline styles via a WC_Email instance.
		$mailer = WC()->mailer();
		$emails = $mailer->get_emails();
		if ( ! empty( $emails ) ) {
			$email_instance = reset( $emails );
			$wrapped        = $email_instance->style_inline( $wrapped );
		}

		return $wrapped;
	}

	/**
	 * Add UTM tags to the email's web links. Run it before click tracking:
	 * tracking swaps each link for a redirect, and redirects are left alone,
	 * so tagging afterwards tagged nothing (fixed in 1.4.1). Only http(s)
	 * links get tags; unsubscribe and confirmation links don't. Tags the
	 * author already set win, and the rest of the address (an order's payment
	 * key, a #fragment) is kept.
	 *
	 * @param string $html     HTML content.
	 * @param object $campaign Campaign, or { id, title } for an automatic email.
	 * @return string Modified HTML.
	 */
	public static function inject_utm_params( $html, $campaign ) {
		$params = array(
			'utm_source'   => get_option( 'bhec_utm_source', 'wec' ) ?: 'wec',
			'utm_medium'   => get_option( 'bhec_utm_medium', 'email' ) ?: 'email',
			'utm_campaign' => sanitize_title( $campaign->title ),
			'utm_content'  => (string) $campaign->id,
		);

		return preg_replace_callback(
			'/(<a\b[^>]*\bhref\s*=\s*["\'])([^"\']+)(["\'][^>]*>)/i',
			function ( $matches ) use ( $params ) {
				$url = html_entity_decode( $matches[2], ENT_QUOTES, 'UTF-8' );

				if ( ! in_array( strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ), array( 'http', 'https' ), true ) ) {
					return $matches[0];
				}

				// Leave subscription actions and existing tracking redirects alone.
				if ( false !== strpos( $url, 'unsubscribe' ) || false !== strpos( $url, 'bhec_confirm_optin' )
					|| false !== strpos( $url, 'bhec_track' ) || false !== strpos( $url, 'bhec-track' ) ) {
					return $matches[0];
				}

				// Respect tags supplied by the author, and preserve checkout keys.
				wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $existing );
				$url = add_query_arg( array_diff_key( $params, $existing ), $url );

				return $matches[1] . esc_url( $url ) . $matches[3];
			},
			$html
		);
	}

	/**
	 * Send a test email to a specific address.
	 *
	 * @param object $campaign        Campaign object.
	 * @param string $test_email      Email address to send to.
	 * @param array  $customer_data   Optional specific customer data (uses mock if empty).
	 * @return bool
	 */
	public function send_test_email( $campaign, $test_email, $customer_data = array() ) {
		if ( empty( $customer_data ) ) {
			$customer_data = BHEC_Merge_Tags::get_mock_data();
		}
		// A test can borrow a real customer's details, but never their unsubscribe
		// link: whoever receives the test could unsubscribe them.
		$customer_data['unsubscribe_url'] = BHEC_Merge_Tags::get_mock_data()['unsubscribe_url'];
		$customer_data['email'] = $test_email;

		$subject = BHEC_Merge_Tags::replace( $campaign->subject, $customer_data, 'text' );
		$body    = BHEC_Merge_Tags::replace( $campaign->body, $customer_data );

		// Replace {{ coupon }} with sample code in test emails (no real coupon generated).
		$body    = str_replace( '{{ coupon }}', 'WEC-SAMPLE-CODE', $body );
		$subject = str_replace( '{{ coupon }}', 'WEC-SAMPLE-CODE', $subject );

		$body .= self::unsubscribe_footer( $customer_data['unsubscribe_url'] );
		$body .= self::credit_footer();

		$html = $this->wrap_in_wc_template( $body, $subject );

		$from_name  = get_option( 'bhec_from_name' ) ?: get_bloginfo( 'name' );
		$from_email = get_option( 'bhec_from_email' ) ?: get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			"From: {$from_name} <{$from_email}>",
		);

		$subject = '[TEST] ' . $subject;

		// Add plain text alternative for multipart email.
		$plain_text = wp_strip_all_tags( str_replace( array( '<br>', '<br/>', '<br />', '</p>' ), "\n", $html ) );
		$plain_text = html_entity_decode( $plain_text, ENT_QUOTES, 'UTF-8' );
		$plain_text = preg_replace( '/\n{3,}/', "\n\n", trim( $plain_text ) );

		$plain_text_callback = function ( $phpmailer ) use ( $plain_text ) {
			$phpmailer->AltBody = $plain_text;
		};
		add_action( 'phpmailer_init', $plain_text_callback );

		// Resolve attachments.
		$file_attachments = array();
		if ( ! empty( $campaign->attachments ) ) {
			$attachment_ids = json_decode( $campaign->attachments, true );
			if ( is_array( $attachment_ids ) ) {
				foreach ( $attachment_ids as $att_id ) {
					$file = get_attached_file( $att_id );
					if ( $file && file_exists( $file ) ) {
						$file_attachments[] = $file;
					}
				}
			}
		}

		$sent = wp_mail( $test_email, $subject, $html, $headers, $file_attachments );

		remove_action( 'phpmailer_init', $plain_text_callback );

		return $sent;
	}
}
