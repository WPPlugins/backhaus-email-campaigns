<?php
/**
 * Campaign admin pages — list and editor.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Admin_Campaigns {

	/**
	 * Route to the correct sub-page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'backhaus-email-campaigns' ) );
		}

		$action   = BHEC_Admin::screen_arg( 'action' );
		$action   = '' !== $action ? $action : 'list';
		$campaign = absint( BHEC_Admin::screen_arg( 'id' ) ) ? BHEC_Campaign::get( absint( BHEC_Admin::screen_arg( 'id' ) ) ) : null;
		// For the views, which don't read the request themselves.
		$starter_key = BHEC_Admin::screen_arg( 'starter' );
		$who_filter  = BHEC_Admin::screen_arg( 'who' );
		$view_filter = BHEC_Admin::screen_arg( 'view' );

		// Sent and sending campaigns open on their summary; drafts in the editor.
		if ( $campaign && 'edit' === $action && ! self::is_editable( $campaign ) ) {
			$action = 'view';
		}
		if ( $campaign && 'view' === $action && self::is_editable( $campaign ) ) {
			$action = 'edit';
		}
		if ( ! $campaign && in_array( $action, array( 'edit', 'view' ), true ) ) {
			$action = 'list';
		}

		switch ( $action ) {
			case 'new':
			case 'edit':
				include BHEC_PLUGIN_DIR . 'admin/views/page-campaign-edit.php';
				break;
			case 'view':
				require_once BHEC_PLUGIN_DIR . 'admin/class-bhec-campaign-recipients-list-table.php';
				include BHEC_PLUGIN_DIR . 'admin/views/page-campaign-view.php';
				break;
			default:
				require_once BHEC_PLUGIN_DIR . 'admin/class-bhec-campaigns-list-table.php';
				include BHEC_PLUGIN_DIR . 'admin/views/page-campaigns.php';
				break;
		}
	}

	/**
	 * Link to a Campaigns screen.
	 *
	 * @param array $args Query args, such as action and id.
	 * @return string
	 */
	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'bhec-campaigns' ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Whether a campaign can still be changed (it hasn't started sending).
	 *
	 * @param object $campaign Campaign row.
	 * @return bool
	 */
	public static function is_editable( $campaign ) {
		return in_array( $campaign->status, array( 'draft', 'failed' ), true );
	}

	/**
	 * The status people see: draft, scheduled, sending, paused, sent or failed.
	 *
	 * @param object $campaign Campaign row.
	 * @return string
	 */
	public static function status( $campaign ) {
		switch ( $campaign->status ) {
			case 'draft':
				return $campaign->scheduled_at ? 'scheduled' : 'draft';
			case 'queued':
			case 'sending':
				return 'sending';
			case 'completed':
				return 'sent';
			default:
				return $campaign->status;
		}
	}

	/**
	 * @param string $status From status().
	 * @return string HTML.
	 */
	public static function status_badge( $status ) {
		$labels = array(
			'draft'     => __( 'Draft', 'backhaus-email-campaigns' ),
			'scheduled' => __( 'Scheduled', 'backhaus-email-campaigns' ),
			'sending'   => __( 'Sending', 'backhaus-email-campaigns' ),
			'paused'    => __( 'Paused', 'backhaus-email-campaigns' ),
			'sent'      => __( 'Sent', 'backhaus-email-campaigns' ),
			'failed'    => __( 'Failed', 'backhaus-email-campaigns' ),
		);
		return sprintf(
			'<span class="bhec-badge bhec-badge-%s">%s</span>',
			esc_attr( $status ),
			esc_html( $labels[ $status ] ?? $status )
		);
	}

	/**
	 * One line under the status: when it sends or sent, or how far it got.
	 *
	 * @param object $campaign Campaign row.
	 * @param string $status   From status().
	 * @return string Plain text.
	 */
	public static function status_detail( $campaign, $status ) {
		$sent  = number_format_i18n( (int) $campaign->sent_count );
		$total = number_format_i18n( (int) $campaign->recipient_count );

		switch ( $status ) {
			case 'draft':
				/* translators: %s: time since, such as "5 mins" */
				return sprintf( __( 'Edited %s ago', 'backhaus-email-campaigns' ), human_time_diff( BHEC_Clock::timestamp( $campaign->updated_at ) ) );
			case 'scheduled':
				/* translators: %s: date and time */
				return sprintf( __( 'Sends %s', 'backhaus-email-campaigns' ), self::format_local( $campaign->scheduled_at ) );
			case 'sending':
				if ( 'queued' === $campaign->status ) {
					return __( 'Starting in a minute', 'backhaus-email-campaigns' );
				}
				/* translators: 1: emails sent, 2: recipients */
				return sprintf( __( '%1$s of %2$s sent', 'backhaus-email-campaigns' ), $sent, $total );
			case 'paused':
				/* translators: 1: emails sent, 2: recipients */
				return sprintf( __( 'Paused at %1$s of %2$s', 'backhaus-email-campaigns' ), $sent, $total );
			case 'sent':
				return $campaign->completed_at ? self::format_local( $campaign->completed_at ) : '';
			case 'failed':
				return (int) $campaign->failed_count > 0
					/* translators: %s: number of emails */
					? sprintf( __( '%s emails could not be sent', 'backhaus-email-campaigns' ), number_format_i18n( (int) $campaign->failed_count ) )
					: __( 'Could not start sending', 'backhaus-email-campaigns' );
		}
		return '';
	}

	/**
	 * Store-local "Y-m-d H:i:s" as the site's date and time format.
	 *
	 * @param string $datetime Store-local MySQL datetime.
	 * @return string
	 */
	public static function format_local( $datetime ) {
		$date = date_create_immutable( $datetime, wp_timezone() );
		if ( ! $date ) {
			return '';
		}
		$format = wp_date( 'Y', $date->getTimestamp() ) === wp_date( 'Y' )
			? 'j M, ' . get_option( 'time_format' )
			: get_option( 'date_format' ) . ', ' . get_option( 'time_format' );
		return wp_date( $format, $date->getTimestamp() );
	}

	/**
	 * A share as a percentage: whole numbers from 10% up, one decimal below.
	 *
	 * @param int $part  Count.
	 * @param int $whole Total.
	 * @return string
	 */
	public static function percent( $part, $whole ) {
		if ( $whole <= 0 || $part <= 0 ) {
			return '0%';
		}
		$pct = 100 * $part / $whole;
		return number_format_i18n( $pct, $pct < 10 ? 1 : 0 ) . '%';
	}

	/**
	 * @param int $done  Emails finished.
	 * @param int $total Emails in the campaign.
	 * @return string HTML.
	 */
	public static function progress_bar( $done, $total ) {
		$pct = $total > 0 ? min( 100, round( 100 * $done / $total, 1 ) ) : 0;
		return sprintf(
			'<span class="bhec-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="%1$s"><span style="width:%1$s%%"></span></span>',
			esc_attr( $pct )
		);
	}

	/**
	 * The store's time zone as people know it: a city name or a UTC offset.
	 *
	 * @return string
	 */
	public static function timezone_label() {
		$zone = wp_timezone_string();
		if ( preg_match( '/^([+-])(\d{2}):(\d{2})$/', $zone, $m ) ) {
			$hours = ltrim( $m[2], '0' );
			return 'UTC' . $m[1] . ( '' === $hours ? '0' : $hours ) . ( '00' !== $m[3] ? ':' . $m[3] : '' );
		}
		return str_replace( '_', ' ', $zone );
	}

	/**
	 * Personal details a campaign can include, grouped for the Personalize menu.
	 * Order details and coupons are left out: campaigns aren't about one order,
	 * and coupons have their own button.
	 *
	 * @return array Group label => [ tag => label ].
	 */
	public static function merge_tag_groups() {
		return array(
			__( 'Customer', 'backhaus-email-campaigns' ) => array(
				'{{ customer.first_name }}'      => __( 'First Name', 'backhaus-email-campaigns' ),
				'{{ customer.last_name }}'       => __( 'Last Name', 'backhaus-email-campaigns' ),
				'{{ customer.full_name }}'       => __( 'Full Name', 'backhaus-email-campaigns' ),
				'{{ customer.email }}'           => __( 'Email Address', 'backhaus-email-campaigns' ),
				'{{ customer.company }}'         => __( 'Company', 'backhaus-email-campaigns' ),
				'{{ customer.city }}'            => __( 'City', 'backhaus-email-campaigns' ),
				'{{ customer.state }}'           => __( 'State', 'backhaus-email-campaigns' ),
				'{{ customer.country }}'         => __( 'Country', 'backhaus-email-campaigns' ),
				'{{ customer.order_count }}'     => __( 'Number of Orders', 'backhaus-email-campaigns' ),
				'{{ customer.total_spent }}'     => __( 'Total Spent', 'backhaus-email-campaigns' ),
				'{{ customer.last_order_date }}' => __( 'Last Order Date', 'backhaus-email-campaigns' ),
			),
			__( 'Store', 'backhaus-email-campaigns' )    => array(
				'{{ store.name }}' => __( 'Store Name', 'backhaus-email-campaigns' ),
				'{{ store.url }}'  => __( 'Store Link', 'backhaus-email-campaigns' ),
			),
			__( 'Links', 'backhaus-email-campaigns' )    => array(
				'{{ unsubscribe_url }}' => __( 'Unsubscribe Link', 'backhaus-email-campaigns' ),
			),
		);
	}

	/**
	 * The Personalize menu, shared by the subject and the message. Tags marked
	 * body-only (links, blocks of HTML) are hidden when it opens for the subject.
	 *
	 * @param array|null $groups   Group label => [ tag => label ]; campaign tags by default.
	 * @param string[]   $body_only Tags that only make sense in the message.
	 */
	public static function render_merge_tag_menu( $groups = null, $body_only = array( '{{ unsubscribe_url }}' ) ) {
		echo '<div class="bhec-popover bhec-tag-menu" id="bhec-tag-menu" role="menu" aria-label="' . esc_attr__( 'Personalize', 'backhaus-email-campaigns' ) . '" hidden>';
		foreach ( null === $groups ? self::merge_tag_groups() : $groups as $group => $tags ) {
			echo '<div class="bhec-tag-group" role="group" aria-label="' . esc_attr( $group ) . '">';
			echo '<p class="bhec-tag-group-label" aria-hidden="true">' . esc_html( $group ) . '</p>';
			foreach ( $tags as $tag => $label ) {
				printf(
					'<button type="button" role="menuitem" class="bhec-tag-item" data-tag="%1$s"%3$s><span>%2$s</span><code>%1$s</code></button>',
					esc_attr( $tag ),
					esc_html( $label ),
					in_array( $tag, $body_only, true ) ? ' data-body-only="1"' : ''
				);
			}
			echo '</div>';
		}
		echo '<p class="bhec-tag-tip">' . wp_kses(
			sprintf(
				/* translators: %s: example merge tag with a fallback */
				__( 'For people with no first name on file, add a fallback: %s', 'backhaus-email-campaigns' ),
				'<code>{{ customer.first_name ?? \'there\' }}</code>'
			),
			array( 'code' => array() )
		) . '</p>';
		echo '</div>';
	}

	/**
	 * A quiet tool button for a field's label row (Add Image, Personalize...).
	 *
	 * @param string $icon  image, personalize, product or coupon.
	 * @param string $label Button text.
	 * @param array  $attrs Extra attributes; 'class' is added to bhec-tool.
	 */
	public static function render_tool( $icon, $label, $attrs = array() ) {
		$icons = array(
			'image'       => 'dashicons-format-image',
			'personalize' => 'dashicons-admin-users',
			'product'     => 'dashicons-products',
			'coupon'      => 'dashicons-tag',
		);
		$attrs['class'] = trim( 'bhec-tool ' . ( $attrs['class'] ?? '' ) );
		echo '<button type="button"';
		foreach ( $attrs as $name => $value ) {
			printf( ' %s="%s"', esc_attr( $name ), esc_attr( $value ) );
		}
		printf(
			'><span class="dashicons %s" aria-hidden="true"></span>%s',
			esc_attr( $icons[ $icon ] ?? '' ),
			esc_html( $label )
		);
		if ( 'personalize' === $icon ) {
			echo '<span class="dashicons dashicons-arrow-down-alt2 bhec-tool-caret" aria-hidden="true"></span>';
		}
		echo '</button>';
	}

	/**
	 * A campaign's audience in plain words, one line per filter.
	 *
	 * @param array $filters            Saved filters.
	 * @param int   $exclude_segment_id Segment left out.
	 * @return string[]
	 */
	public static function describe_audience( $filters, $exclude_segment_id = 0 ) {
		global $wpdb;

		$statuses = wc_get_order_statuses();
		$picked   = ! empty( $filters['order_status'] ) ? (array) $filters['order_status'] : array( 'wc-completed', 'wc-processing', 'wc-on-hold' );
		$names    = array_map(
			function ( $s ) use ( $statuses ) {
				return strtolower( $statuses[ $s ] ?? $s );
			},
			$picked
		);
		$lines    = array(
			/* translators: %s: list of order statuses */
			sprintf( __( 'Subscribed customers with a %s order', 'backhaus-email-campaigns' ), wp_sprintf_l( '%l', $names ) ),
		);
		$date     = function ( $ymd ) {
			return wp_date( get_option( 'date_format' ), strtotime( $ymd . ' 12:00:00' ) );
		};

		if ( ! empty( $filters['date_from'] ) && ! empty( $filters['date_to'] ) ) {
			/* translators: 1: start date, 2: end date */
			$lines[] = sprintf( __( 'Ordered between %1$s and %2$s', 'backhaus-email-campaigns' ), $date( $filters['date_from'] ), $date( $filters['date_to'] ) );
		} elseif ( ! empty( $filters['date_from'] ) ) {
			/* translators: %s: date */
			$lines[] = sprintf( __( 'Ordered on or after %s', 'backhaus-email-campaigns' ), $date( $filters['date_from'] ) );
		} elseif ( ! empty( $filters['date_to'] ) ) {
			/* translators: %s: date */
			$lines[] = sprintf( __( 'Ordered on or before %s', 'backhaus-email-campaigns' ), $date( $filters['date_to'] ) );
		}

		$range = function ( $min, $max, $format ) {
			if ( '' !== (string) $min && '' !== (string) $max ) {
				/* translators: 1: minimum, 2: maximum */
				return sprintf( __( 'between %1$s and %2$s', 'backhaus-email-campaigns' ), $format( $min ), $format( $max ) );
			}
			if ( '' !== (string) $min ) {
				/* translators: %s: minimum */
				return sprintf( __( 'at least %s', 'backhaus-email-campaigns' ), $format( $min ) );
			}
			/* translators: %s: maximum */
			return sprintf( __( 'at most %s', 'backhaus-email-campaigns' ), $format( $max ) );
		};
		if ( isset( $filters['order_count_min'] ) || isset( $filters['order_count_max'] ) ) {
			/* translators: %s: such as "at least 2" */
			$lines[] = sprintf( __( 'Placed %s orders', 'backhaus-email-campaigns' ), $range( $filters['order_count_min'] ?? '', $filters['order_count_max'] ?? '', 'number_format_i18n' ) );
		}
		if ( isset( $filters['total_spent_min'] ) || isset( $filters['total_spent_max'] ) ) {
			$money = function ( $v ) {
				return html_entity_decode( wp_strip_all_tags( wc_price( $v ) ) );
			};
			/* translators: %s: such as "at least $50.00" */
			$lines[] = sprintf( __( 'Spent %s in total', 'backhaus-email-campaigns' ), $range( $filters['total_spent_min'] ?? '', $filters['total_spent_max'] ?? '', $money ) );
		}
		if ( ! empty( $filters['billing_country'] ) ) {
			$countries = WC()->countries->get_countries();
			/* translators: %s: country name */
			$lines[] = sprintf( __( 'Billing address in %s', 'backhaus-email-campaigns' ), html_entity_decode( $countries[ $filters['billing_country'] ] ?? $filters['billing_country'] ) );
		}
		if ( ! empty( $filters['include_signups'] ) ) {
			$lines[] = __( 'Plus people who signed up without ordering', 'backhaus-email-campaigns' );
		}
		if ( $exclude_segment_id ) {
			$name = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}bhec_segments WHERE id = %d", $exclude_segment_id ) );
			if ( $name ) {
				/* translators: %s: segment name */
				$lines[] = sprintf( __( 'Leaving out the "%s" segment', 'backhaus-email-campaigns' ), $name );
			}
		}
		return $lines;
	}

	/**
	 * Confirmation after an action that reloads the page (?done=...).
	 */
	public static function render_flash() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$done     = isset( $_GET['done'] ) ? sanitize_key( $_GET['done'] ) : '';
		$messages = array(
			'deleted'    => __( 'Campaign deleted.', 'backhaus-email-campaigns' ),
			'duplicated' => __( 'Campaign duplicated. This is the copy.', 'backhaus-email-campaigns' ),
			'scheduled'  => __( 'Campaign scheduled.', 'backhaus-email-campaigns' ),
			'sending'    => __( 'Sending started. You can leave this page; sending carries on in the background.', 'backhaus-email-campaigns' ),
		);
		if ( isset( $messages[ $done ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $done ] ) );
		}
	}

	/**
	 * AJAX: Preview recipients for current filter criteria.
	 */
	public function ajax_preview_recipients() {
		check_ajax_referer( 'bhec_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'backhaus-email-campaigns' ) );
		}

		$filters            = isset( $_POST['filters'] ) ? json_decode( sanitize_text_field( wp_unslash( $_POST['filters'] ) ), true ) : array();
		$exclude_segment_id = isset( $_POST['exclude_segment_id'] ) ? absint( $_POST['exclude_segment_id'] ) : 0;

		// Fetch sample + 1 extra to detect "more than N" without a full count.
		$sample_limit = 10;
		$sample       = BHEC_Customer_Query::query( $filters ?: array(), $exclude_segment_id, false, $sample_limit + 1 );
		$has_more     = count( $sample ) > $sample_limit;

		if ( $has_more ) {
			$sample = array_slice( $sample, 0, $sample_limit );
			// Only run the expensive count query if there are more than the sample.
			$count = BHEC_Customer_Query::query( $filters ?: array(), $exclude_segment_id, true );
		} else {
			$count = count( $sample );
		}

		wp_send_json_success( array(
			'count'  => $count,
			'sample' => $sample,
		) );
	}

	/**
	 * AJAX: Send a test email.
	 */
	public function ajax_send_test_email() {
		check_ajax_referer( 'bhec_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'backhaus-email-campaigns' ) );
		}

		$test_email = isset( $_POST['test_email'] ) ? sanitize_email( wp_unslash( $_POST['test_email'] ) ) : '';
		if ( ! is_email( $test_email ) ) {
			wp_send_json_error( __( 'Please enter a valid email address.', 'backhaus-email-campaigns' ) );
		}

		// Build a temporary campaign object from posted data.
		$campaign = (object) array(
			'id'             => 0,
			'subject'        => isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '',
			'body'           => isset( $_POST['body'] ) ? wp_kses_post( wp_unslash( $_POST['body'] ) ) : '',
			'email_to'       => '{{ customer.email }}',
			'email_cc'       => '',
			'email_bcc'      => '',
			'reply_to_name'  => isset( $_POST['reply_to_name'] ) ? sanitize_text_field( wp_unslash( $_POST['reply_to_name'] ) ) : '',
			'reply_to_email' => isset( $_POST['reply_to_email'] ) ? sanitize_text_field( wp_unslash( $_POST['reply_to_email'] ) ) : '',
			'attachments'    => isset( $_POST['attachments'] ) ? sanitize_text_field( wp_unslash( $_POST['attachments'] ) ) : '[]',
		);

		// Use specific customer data if a customer_id is provided.
		$customer_data = array();
		$preview_customer_id = isset( $_POST['preview_customer_id'] ) ? absint( $_POST['preview_customer_id'] ) : 0;
		if ( $preview_customer_id > 0 ) {
			$customer = new WC_Customer( $preview_customer_id );
			if ( $customer->get_id() > 0 ) {
				$customer_data = BHEC_Merge_Tags::get_customer_data( $preview_customer_id, $customer->get_email(), false );
			}
		}

		$sender = new BHEC_Sender();
		$sent   = $sender->send_test_email( $campaign, $test_email, $customer_data );

		if ( $sent ) {
			wp_send_json_success( __( 'Test email sent!', 'backhaus-email-campaigns' ) );
		} else {
			wp_send_json_error( __( 'Failed to send test email. Check your email configuration.', 'backhaus-email-campaigns' ) );
		}
	}

	/**
	 * AJAX: Save a campaign (create or update).
	 */
	public function ajax_save_campaign() {
		check_ajax_referer( 'bhec_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'backhaus-email-campaigns' ) );
		}

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;

		$data = array(
			'title'              => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'subject'            => isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '',
			'body'               => isset( $_POST['body'] ) ? wp_kses_post( wp_unslash( $_POST['body'] ) ) : '',
			'filters'            => isset( $_POST['filters'] ) ? sanitize_text_field( wp_unslash( $_POST['filters'] ) ) : '{}',
			'exclude_segment_id' => isset( $_POST['exclude_segment_id'] ) ? absint( $_POST['exclude_segment_id'] ) : null,
			'email_to'           => isset( $_POST['email_to'] ) ? sanitize_text_field( wp_unslash( $_POST['email_to'] ) ) : '{{ customer.email }}',
			'email_cc'           => isset( $_POST['email_cc'] ) ? sanitize_text_field( wp_unslash( $_POST['email_cc'] ) ) : '',
			'email_bcc'          => isset( $_POST['email_bcc'] ) ? sanitize_text_field( wp_unslash( $_POST['email_bcc'] ) ) : '',
			'reply_to_name'      => isset( $_POST['reply_to_name'] ) ? sanitize_text_field( wp_unslash( $_POST['reply_to_name'] ) ) : '',
			'reply_to_email'     => isset( $_POST['reply_to_email'] ) ? sanitize_text_field( wp_unslash( $_POST['reply_to_email'] ) ) : '',
			'layout_template'    => isset( $_POST['layout_template'] ) ? sanitize_key( $_POST['layout_template'] ) : 'default',
			'attachments'        => isset( $_POST['attachments'] ) ? sanitize_text_field( wp_unslash( $_POST['attachments'] ) ) : '[]',
			'coupon_settings'    => isset( $_POST['coupon_settings'] ) ? sanitize_text_field( wp_unslash( $_POST['coupon_settings'] ) ) : '',
			'scheduled_at'       => null,
		);
		// A second subject to test (Pro).
		if ( class_exists( 'BHEC_AB_Test' ) ) {
			$data['subject_b'] = isset( $_POST['subject_b'] ) ? sanitize_text_field( wp_unslash( $_POST['subject_b'] ) ) : '';
			$data['ab_test']   = BHEC_AB_Test::sanitize(
				array(
					'percent' => isset( $_POST['ab_percent'] ) ? absint( $_POST['ab_percent'] ) : 20,
					'hours'   => isset( $_POST['ab_hours'] ) ? absint( $_POST['ab_hours'] ) : 4,
					'metric'  => isset( $_POST['ab_metric'] ) ? sanitize_key( $_POST['ab_metric'] ) : 'opens',
				)
			);
		}

		// A schedule must be in the future: cron sends any draft whose time has
		// passed, so a stale date would send the campaign within a minute.
		$scheduled = isset( $_POST['scheduled_at'] ) ? sanitize_text_field( wp_unslash( $_POST['scheduled_at'] ) ) : '';
		if ( '' !== $scheduled ) {
			$when = date_create_immutable( $scheduled, wp_timezone() );
			if ( ! $when || $when->getTimestamp() <= time() ) {
				wp_send_json_error( __( 'Choose a time in the future to schedule this campaign, or clear the date.', 'backhaus-email-campaigns' ) );
			}
			$data['scheduled_at'] = $when->format( 'Y-m-d H:i:s' );
		}

		if ( $campaign_id > 0 ) {
			BHEC_Campaign::update( $campaign_id, $data );
			wp_send_json_success( array(
				'campaign_id' => $campaign_id,
				'message'     => __( 'Campaign saved.', 'backhaus-email-campaigns' ),
			) );
		} else {
			$new_id = BHEC_Campaign::create( $data );
			if ( $new_id ) {
				wp_send_json_success( array(
					'campaign_id' => $new_id,
					'message'     => __( 'Campaign created.', 'backhaus-email-campaigns' ),
				) );
			} else {
				wp_send_json_error( __( 'Failed to create campaign.', 'backhaus-email-campaigns' ) );
			}
		}
	}

	/**
	 * AJAX: What to tell the admin before sending: how many it reaches, how
	 * many were emailed recently (they'll be held) and any recent campaign with
	 * the same subject.
	 */
	public function ajax_send_check() {
		check_ajax_referer( 'bhec_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'backhaus-email-campaigns' ) );
		}

		$filters    = isset( $_POST['filters'] ) ? json_decode( sanitize_text_field( wp_unslash( $_POST['filters'] ) ), true ) : array();
		$recipients = BHEC_Customer_Query::query( $filters ?: array(), isset( $_POST['exclude_segment_id'] ) ? absint( $_POST['exclude_segment_id'] ) : 0 );
		$campaign   = (object) array(
			'id'      => isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0,
			'subject' => isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '',
		);

		$check = BHEC_Send_Guard::send_check( $campaign, $recipients );
		if ( $check['similar'] ) {
			$check['similar']['when'] = self::format_local( $check['similar']['when'] );
		}
		wp_send_json_success( $check );
	}

	/**
	 * AJAX: Queue a campaign for sending.
	 */
	public function ajax_queue_campaign() {
		check_ajax_referer( 'bhec_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'backhaus-email-campaigns' ) );
		}

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
		if ( ! $campaign_id ) {
			wp_send_json_error( __( 'No campaign specified.', 'backhaus-email-campaigns' ) );
		}

		$result = BHEC_Campaign::queue_campaign( $campaign_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( array(
			'message'         => sprintf(
				/* translators: %d: number of recipients */
				__( 'Campaign queued for %d recipients.', 'backhaus-email-campaigns' ),
				$result['recipient_count']
			),
			'recipient_count' => $result['recipient_count'],
		) );
	}

	/**
	 * AJAX: Delete a campaign.
	 */
	public function ajax_delete_campaign() {
		check_ajax_referer( 'bhec_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'backhaus-email-campaigns' ) );
		}

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
		if ( BHEC_Campaign::delete( $campaign_id ) ) {
			wp_send_json_success( __( 'Campaign deleted.', 'backhaus-email-campaigns' ) );
		} else {
			wp_send_json_error( __( 'Failed to delete campaign.', 'backhaus-email-campaigns' ) );
		}
	}

	/**
	 * AJAX: Duplicate a campaign.
	 */
	public function ajax_duplicate_campaign() {
		check_ajax_referer( 'bhec_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'backhaus-email-campaigns' ) );
		}

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
		$new_id      = BHEC_Campaign::duplicate( $campaign_id );

		if ( $new_id ) {
			wp_send_json_success( array(
				'campaign_id' => $new_id,
				'message'     => __( 'Campaign duplicated.', 'backhaus-email-campaigns' ),
			) );
		} else {
			wp_send_json_error( __( 'Failed to duplicate campaign.', 'backhaus-email-campaigns' ) );
		}
	}
}
