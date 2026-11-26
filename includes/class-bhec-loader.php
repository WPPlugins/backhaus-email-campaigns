<?php
/**
 * Central hook orchestrator — loads all classes and registers hooks.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Loader {

	/**
	 * Boot the plugin.
	 */
	public function run() {
		$this->load_dependencies();
		BHEC_Migrator::check_and_migrate();
		BHEC_GDPR::init();
		BHEC_Signup_Form::init();
		$this->register_cron_interval();
		$this->register_admin_hooks();
		$this->register_public_hooks();
		$this->register_woocommerce_hooks();

			}

	/**
	 * Require all class files.
	 */
	private function load_dependencies() {
		$includes = BHEC_PLUGIN_DIR . 'includes/';
		$admin    = BHEC_PLUGIN_DIR . 'admin/';
		$wc       = BHEC_PLUGIN_DIR . 'woocommerce/';
		$pub      = BHEC_PLUGIN_DIR . 'public/';

		// License (must load first — provides bhec_is_pro()).
		require_once $includes . 'class-bhec-license.php';

		// Core includes.
		require_once $includes . 'class-bhec-subscription.php';
		require_once $includes . 'class-bhec-merge-tags.php';
		require_once $includes . 'class-bhec-customer-query.php';
		require_once $includes . 'class-bhec-campaign.php';
		require_once $includes . 'class-bhec-queue.php';
		require_once $includes . 'class-bhec-clock.php';
		require_once $includes . 'class-bhec-send-control.php';
		require_once $includes . 'class-bhec-send-guard.php';
		require_once $includes . 'class-bhec-sender.php';
		require_once $includes . 'class-bhec-cron.php';
		require_once $includes . 'class-bhec-tracker.php';
		require_once $includes . 'class-bhec-csv-importer.php';
		require_once $includes . 'class-bhec-logger.php';
		require_once $includes . 'class-bhec-unsubscribe.php';
		require_once $includes . 'class-bhec-email-templates.php';
		require_once $includes . 'class-bhec-import-export.php';
		require_once $includes . 'class-bhec-double-optin.php';
		require_once $includes . 'class-bhec-captcha.php';
		require_once $includes . 'class-bhec-migrator.php';
		require_once $includes . 'class-bhec-gdpr.php';
		require_once $includes . 'class-bhec-signup-form.php';

		// Admin.
		if ( is_admin() ) {
			require_once $admin . 'class-bhec-admin.php';
			require_once $admin . 'class-bhec-admin-settings.php';
			require_once $admin . 'class-bhec-admin-campaigns.php';
			require_once $admin . 'class-bhec-campaign-starters.php';
			require_once $admin . 'class-bhec-admin-customers.php';
			require_once $admin . 'class-bhec-admin-queue.php';
			require_once $admin . 'class-bhec-admin-analytics.php';
			require_once $admin . 'class-bhec-admin-logs.php';
			require_once $admin . 'class-bhec-admin-status.php';
			require_once $admin . 'class-bhec-dashboard-widget.php';
		}

		// WooCommerce integrations.
		require_once $wc . 'class-bhec-checkout-optin.php';
		require_once $wc . 'class-bhec-myaccount.php';

		// Public.
		require_once $pub . 'class-bhec-public.php';
	}

	/**
	 * Register a custom 1-minute cron interval.
	 */
	private function register_cron_interval() {
		// Not translated: scheduling runs this filter before init, when loading
		// translations triggers a "loaded too early" notice. Only cron tools show it.
		add_filter( 'cron_schedules', function ( $schedules ) {
			$schedules['bhec_every_minute'] = array(
				'interval' => 60,
				'display'  => 'Every Minute (Email Campaigns)',
			);
			return $schedules;
		} );

		// Hook the queue processor.
		$cron = new BHEC_Cron();
		add_action( 'bhec_process_queue', array( $cron, 'process_queue' ) );

		
		add_action( 'bhec_daily_maintenance', array( $this, 'daily_maintenance' ) );

		// Self-heal: WP-Cron can silently drop a recurring event (a fatal during a
		// run, or the custom interval missing when it reschedules), which stops all
		// sending with no visible error. Re-add missing events on every load. This
		// also schedules them after activation. It runs on init because scheduling
		// applies the cron_schedules filter, where WooCommerce translates labels,
		// and translations must not load before init.
		add_action( 'init', function () {
			if ( ! wp_next_scheduled( 'bhec_process_queue' ) ) {
				wp_schedule_event( time(), 'bhec_every_minute', 'bhec_process_queue' );
			}
			if ( ! wp_next_scheduled( 'bhec_daily_maintenance' ) ) {
				wp_schedule_event( time(), 'daily', 'bhec_daily_maintenance' );
			}
		} );
	}

	/**
	 * Daily maintenance: purge old logs, delete expired coupons.
	 */
	public function daily_maintenance() {
		BHEC_Logger::purge_old_logs();
		if ( class_exists( 'BHEC_Coupon' ) ) {
			BHEC_Coupon::cleanup_expired_coupons();
		}
		if ( class_exists( 'BHEC_Triggered_Emails' ) ) {
			BHEC_Triggered_Emails::trash_stale_abandoned_orders();
		}
	}

	/**
	 * Register admin-side hooks.
	 */
	private function register_admin_hooks() {
		if ( ! is_admin() ) {
			return;
		}

		$admin = new BHEC_Admin();
		add_action( 'admin_menu', array( $admin, 'register_menus' ) );
		add_action( 'admin_init', array( $admin, 'redirect_moved_pages' ) );
		add_action( 'admin_enqueue_scripts', array( $admin, 'enqueue_assets' ) );

		// Settings.
		$settings = new BHEC_Admin_Settings();
		add_action( 'admin_init', array( $settings, 'register_settings' ) );

		// Campaign AJAX handlers.
		$campaigns = new BHEC_Admin_Campaigns();
		add_action( 'wp_ajax_bhec_preview_recipients', array( $campaigns, 'ajax_preview_recipients' ) );
		add_action( 'wp_ajax_bhec_send_test_email', array( $campaigns, 'ajax_send_test_email' ) );
		add_action( 'wp_ajax_bhec_save_campaign', array( $campaigns, 'ajax_save_campaign' ) );
		add_action( 'wp_ajax_bhec_queue_campaign', array( $campaigns, 'ajax_queue_campaign' ) );
		add_action( 'wp_ajax_bhec_campaign_send_check', array( $campaigns, 'ajax_send_check' ) );
		add_action( 'wp_ajax_bhec_delete_campaign', array( $campaigns, 'ajax_delete_campaign' ) );
		add_action( 'wp_ajax_bhec_duplicate_campaign', array( $campaigns, 'ajax_duplicate_campaign' ) );
		add_filter(
			'removable_query_args',
			function ( $args ) {
				$args[] = 'done';
				return $args;
			}
		);

		// Queue AJAX.
		$queue_admin = new BHEC_Admin_Queue();
		add_action( 'wp_ajax_bhec_queue_status', array( $queue_admin, 'ajax_queue_status' ) );
		add_action( 'wp_ajax_bhec_pause_campaign', array( $queue_admin, 'ajax_pause_campaign' ) );
		add_action( 'wp_ajax_bhec_resume_campaign', array( $queue_admin, 'ajax_resume_campaign' ) );
		add_action( 'wp_ajax_bhec_retry_failed', array( $queue_admin, 'ajax_retry_failed' ) );
		add_action( 'wp_ajax_bhec_stop_campaign', array( $queue_admin, 'ajax_stop_campaign' ) );
		add_action( 'wp_ajax_bhec_process_now', array( $this, 'ajax_process_now' ) );
		add_action( 'wp_ajax_bhec_resume_sending', array( $queue_admin, 'ajax_resume_sending' ) );
		add_action( 'wp_ajax_bhec_resolve_held', array( $queue_admin, 'ajax_resolve_held' ) );
		add_action( 'admin_notices', array( $admin, 'render_pause_notices' ) );
		add_action( 'wp_ajax_bhec_force_send', array( $queue_admin, 'ajax_force_send' ) );

		// Customer AJAX.
		$customers = new BHEC_Admin_Customers();
		add_action( 'wp_ajax_bhec_import_optouts', array( $customers, 'ajax_import_optouts' ) );
		add_action( 'wp_ajax_bhec_toggle_subscription', array( $customers, 'ajax_toggle_subscription' ) );
		add_action( 'wp_ajax_bhec_quick_unsubscribe', array( $customers, 'ajax_quick_unsubscribe' ) );
		add_action( 'wp_ajax_bhec_bulk_subscription', array( $customers, 'ajax_bulk_subscription' ) );
		add_action( 'wp_ajax_bhec_customer_history', array( $customers, 'ajax_customer_history' ) );

		// Logs AJAX.
		$logs = new BHEC_Admin_Logs();
		add_action( 'wp_ajax_bhec_get_log_detail', array( $logs, 'ajax_get_log_detail' ) );
		add_action( 'wp_ajax_bhec_purge_logs', array( $logs, 'ajax_purge_logs' ) );

		
		// Preview AJAX.
		add_action( 'wp_ajax_bhec_preview_render', array( $this, 'ajax_preview_render' ) );

		// Maintenance AJAX.
		add_action( 'wp_ajax_bhec_purge_coupons', array( $this, 'ajax_purge_coupons' ) );
		add_action( 'wp_ajax_bhec_send_health_check', array( $this, 'ajax_send_health_check' ) );
		add_action( 'wp_ajax_bhec_reschedule_cron', array( $this, 'ajax_reschedule_cron' ) );

		// Segment/template/export AJAX.
		add_action( 'wp_ajax_bhec_save_segment', array( $this, 'ajax_save_segment' ) );
		add_action( 'wp_ajax_bhec_get_template', array( $this, 'ajax_get_template' ) );
		add_action( 'wp_ajax_bhec_export_csv', array( $this, 'ajax_export_csv' ) );

		// Import / Export handlers.
		add_action( 'admin_post_bhec_export_settings', array( $this, 'handle_export' ) );
		add_action( 'admin_post_bhec_import_settings', array( $this, 'handle_import' ) );

		// Dashboard widget.
		$dashboard = new BHEC_Dashboard_Widget();
		add_action( 'wp_dashboard_setup', array( $dashboard, 'register' ) );

		// Display import notice.
		add_action( 'admin_notices', array( $this, 'display_import_notice' ) );
	}

	/**
	 * AJAX: Save a segment.
	 */
	public function ajax_save_segment() {
		check_ajax_referer( 'bhec_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'backhaus-email-campaigns' ) );
		}

		global $wpdb;
		$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$filters = isset( $_POST['filters'] ) ? sanitize_text_field( wp_unslash( $_POST['filters'] ) ) : '{}';

		if ( empty( $name ) ) {
			wp_send_json_error( __( 'Please enter a segment name.', 'backhaus-email-campaigns' ) );
		}

		$wpdb->insert(
			$wpdb->prefix . 'bhec_segments',
			array(
				'name'       => $name,
				'filters'    => $filters,
				'created_by' => get_current_user_id(),
			),
			array( '%s', '%s', '%d' )
		);

		wp_send_json_success( array( 'id' => $wpdb->insert_id ) );
	}

	/**
	 * AJAX: Get a template body.
	 */
	public function ajax_get_template() {
		check_ajax_referer( 'bhec_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'backhaus-email-campaigns' ) );
		}

		global $wpdb;
		$id       = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		$template = $wpdb->get_row(
			$wpdb->prepare( "SELECT body FROM {$wpdb->prefix}bhec_templates WHERE id = %d", $id )
		);

		if ( ! $template ) {
			wp_send_json_error( __( 'Template not found.', 'backhaus-email-campaigns' ) );
		}

		wp_send_json_success( array( 'body' => $template->body ) );
	}

	/**
	 * AJAX: Live Processing. Runs the whole cron pipeline now, with a longer
	 * sending window than WP-Cron; Send Faster on a campaign's page calls it in a loop.
	 */
	public function ajax_process_now() {
		check_ajax_referer( 'bhec_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'backhaus-email-campaigns' ) );
		}

		if ( ! defined( 'BHEC_LIVE_PROCESSING' ) ) {
			define( 'BHEC_LIVE_PROCESSING', true );
		}

		// Up to 55 s waiting for the queue lock plus a 270 s sending window.
		wc_set_time_limit( 390 );

		// Release the PHP session so queue status polling isn't blocked while this runs.
		if ( session_status() === PHP_SESSION_ACTIVE ) {
			session_write_close();
		}

		$pause = BHEC_Send_Control::provider_pause();
		if ( $pause ) {
			wp_send_json_success( array(
				'message' => sprintf(
					/* translators: %s: time sending resumes */
					__( 'Paused until %s because your mail provider asked us to slow down.', 'backhaus-email-campaigns' ),
					wp_date( get_option( 'time_format' ), (int) $pause['until'] )
				),
				'wait'    => min( 60, max( 5, (int) $pause['until'] - time() ) ),
			) );
		}

		$start = microtime( true );

		// Run the full cron pipeline (Pro classes guarded).
		if ( class_exists( 'BHEC_Cart_Capture' ) ) {
			BHEC_Cart_Capture::process_abandoned_captures();
		}
		if ( class_exists( 'BHEC_Triggered_Emails' ) ) {
			BHEC_Triggered_Emails::process_winback_emails();
		}
		if ( class_exists( 'BHEC_Triggered_Emails' ) ) {
			BHEC_Triggered_Emails::process_due_triggered_emails();
		}

		$cron = new BHEC_Cron();
		if ( ! $cron->process_queue() ) {
			wp_send_json_success( __( 'Another run is still sending. Trying again.', 'backhaus-email-campaigns' ) );
		}

		$elapsed = round( microtime( true ) - $start, 1 );

		wp_send_json_success( sprintf(
			/* translators: %s: elapsed seconds */
			__( 'Queue processed in %ss.', 'backhaus-email-campaigns' ),
			$elapsed
		) );
	}

	
	public function ajax_preview_render() {
		check_ajax_referer( 'bhec_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'backhaus-email-campaigns' ) );
		}

		wp_send_json_success(
			self::render_preview(
				isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '',
				isset( $_POST['body'] ) ? wp_kses_post( wp_unslash( $_POST['body'] ) ) : '',
				isset( $_POST['layout_template'] ) ? sanitize_key( $_POST['layout_template'] ) : 'default'
			)
		);
	}

	/**
	 * An email as a customer would see it, filled in with the latest paying
	 * customer's details (or sample details) and a sample unsubscribe link.
	 *
	 * @param string $subject Subject with merge tags.
	 * @param string $body    Body with merge tags.
	 * @param string $layout  Layout template id.
	 * @return array { subject, html }
	 */
	public static function render_preview( $subject, $body, $layout = 'default' ) {
		// Try to use a real order for preview data if available.
		$preview_data = BHEC_Merge_Tags::get_mock_data();
		// Orders only: a refund is "completed" too, and has no customer.
		$sample_order = wc_get_orders( array(
			'type'    => 'shop_order',
			'limit'   => 1,
			'orderby' => 'date',
			'order'   => 'DESC',
			'status'  => array( 'completed', 'processing' ),
			'return'  => 'objects',
		) );

		if ( ! empty( $sample_order ) ) {
			$order = $sample_order[0];
			$preview_data = BHEC_Merge_Tags::get_customer_data(
				$order->get_customer_id(),
				$order->get_billing_email(),
				false
			);
			// Add order-specific data.
			$preview_data['order_id']     = $order->get_id();
			$preview_data['order_total']  = wc_price( $order->get_total() );
			$preview_data['order_date']   = wc_format_datetime( $order->get_date_created() );
			$preview_data['checkout_url'] = $order->get_checkout_payment_url();
		}

		// Never show a real customer's unsubscribe link in a preview.
		$preview_data['unsubscribe_url'] = BHEC_Merge_Tags::get_mock_data()['unsubscribe_url'];

		$subject = BHEC_Merge_Tags::replace( $subject, $preview_data, 'text' );
		$body    = BHEC_Merge_Tags::replace( $body, $preview_data );

		// Replace coupon tag with sample.
		$body    = str_replace( '{{ coupon }}', 'WEC-SAMPLE-CODE', $body );
		$subject = str_replace( '{{ coupon }}', 'WEC-SAMPLE-CODE', $subject );

		$body .= BHEC_Sender::unsubscribe_footer( $preview_data['unsubscribe_url'] );

		$body .= BHEC_Sender::credit_footer();

		$sender = new BHEC_Sender();
		$html   = $sender->wrap_in_wc_template( $body, $subject, $layout );

		return array(
			'subject' => $subject,
			'html'    => $html,
		);
	}

	public function ajax_purge_coupons() {
		check_ajax_referer( 'bhec_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'backhaus-email-campaigns' ) );
		}

		$count = class_exists( 'BHEC_Coupon' ) ? BHEC_Coupon::cleanup_expired_coupons( 0 ) : 0;
		wp_send_json_success( sprintf(
			/* translators: %d: number of coupons deleted */
			__( '%d expired coupons deleted.', 'backhaus-email-campaigns' ),
			$count
		) );
	}

	/**
	 * AJAX: Send a health check test email.
	 */
	public function ajax_send_health_check() {
		check_ajax_referer( 'bhec_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'backhaus-email-campaigns' ) );
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! is_email( $email ) ) {
			wp_send_json_error( __( 'Invalid email address.', 'backhaus-email-campaigns' ) );
		}

		$sent = wp_mail(
			$email,
			/* translators: %s: store name */
			sprintf( __( 'Test email from %s', 'backhaus-email-campaigns' ), get_bloginfo( 'name' ) ),
			__( 'Email Campaigns sent this to check that your store can send email. If you are reading it, it can.', 'backhaus-email-campaigns' )
		);

		if ( $sent ) {
			wp_send_json_success();
		} else {
			wp_send_json_error( __( 'WordPress couldn\'t send it. Check the plugin that sends your store\'s email (such as WP Mail SMTP).', 'backhaus-email-campaigns' ) );
		}
	}

	/**
	 * AJAX: Manually (re)schedule the queue-processing cron event.
	 *
	 * Recovery control for when WP-Cron has dropped bhec_process_queue. Clears any
	 * stale schedule and re-adds it so the next run lands within a minute.
	 */
	public function ajax_reschedule_cron() {
		check_ajax_referer( 'bhec_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'backhaus-email-campaigns' ) );
		}

		wp_clear_scheduled_hook( 'bhec_process_queue' );
		wp_schedule_event( time(), 'bhec_every_minute', 'bhec_process_queue' );

		$next = wp_next_scheduled( 'bhec_process_queue' );
		if ( ! $next ) {
			wp_send_json_error( __( 'Could not schedule the cron event.', 'backhaus-email-campaigns' ) );
		}

		// $next is a true UTC timestamp, so wp_date() correctly renders it in site time.
		wp_send_json_success( array(
			'next' => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next ),
		) );
	}

	/**
	 * AJAX: Export campaign data, or the Subscribers list (type=subscribers,
	 * with the page's view and search), as CSV.
	 */
	public function ajax_export_csv() {
		// Uses GET for download links.
		if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['nonce'] ) ), 'bhec_export' ) ) {
			wp_die( esc_html__( 'Invalid nonce.', 'backhaus-email-campaigns' ) );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'backhaus-email-campaigns' ) );
		}

		$campaign_id = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0;
		$type        = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : 'sent';
		$filename    = 'campaign-' . $campaign_id . '-' . $type . '.csv';

		global $wpdb;

		if ( 'subscribers' === $type ) {
			list( $headers, $rows ) = BHEC_Admin_Customers::export_rows(
				isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : '',
				isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''
			);
			$filename = 'subscribers-' . wp_date( 'Y-m-d' ) . '.csv';
		} elseif ( 'opened' === $type ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DISTINCT e.email, q.customer_name, MIN(e.created_at) as first_opened
					FROM {$wpdb->prefix}bhec_tracking_events e
					INNER JOIN {$wpdb->prefix}bhec_queue q ON e.queue_id = q.id
					WHERE e.campaign_id = %d AND e.event_type = 'open'
					GROUP BY e.email, q.customer_name",
					$campaign_id
				),
				ARRAY_A
			);
			$headers = array( 'Email', 'Name', 'First Opened' );
		} else {
			$status = 'failed' === $type ? 'failed' : 'sent';
			$rows   = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT customer_email as email, customer_name as name, status, sent_at, last_error
					FROM {$wpdb->prefix}bhec_queue WHERE campaign_id = %d AND status = %s",
					$campaign_id,
					$status
				),
				ARRAY_A
			);
			$headers = array( 'Email', 'Name', 'Status', 'Sent At', 'Error' );
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, array_map( array( __CLASS__, 'csv_cell' ), $headers ) );
		foreach ( $rows as $row ) {
			fputcsv( $output, array_map( array( __CLASS__, 'csv_cell' ), array_values( $row ) ) );
		}
		exit;
	}

	/**
	 * A CSV cell that a spreadsheet won't run as a formula. Names, subjects and
	 * errors come from customers and mail servers, so a cell starting with
	 * = + - @ or a tab or carriage return gets a leading quote.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public static function csv_cell( $value ) {
		$value = (string) $value;
		return '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ? "'" . $value : $value;
	}

	/**
	 * Display admin notice after import.
	 */
	public function display_import_notice() {
		$notice = get_transient( 'bhec_import_notice' );
		if ( ! $notice ) {
			return;
		}

		delete_transient( 'bhec_import_notice' );

		$type    = isset( $notice['type'] ) ? sanitize_text_field( $notice['type'] ) : 'info';
		$message = isset( $notice['message'] ) ? $notice['message'] : '';

		if ( ! empty( $message ) ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $type ),
				esc_html( $message )
			);
		}
	}

	/**
	 * Handle settings export: verify nonce/capability, stream JSON download.
	 */
	public function handle_export() {
		if ( ! isset( $_POST['bhec_import_export_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bhec_import_export_nonce'] ) ), 'bhec_import_export' ) ) {
			wp_die( esc_html__( 'Invalid nonce.', 'backhaus-email-campaigns' ) );
		}

		// Settings are for administrators, as on the Settings tabs.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'backhaus-email-campaigns' ) );
		}

		$include_subscriptions = ! empty( $_POST['include_subscriptions'] );
		$data                  = BHEC_Import_Export::export( $include_subscriptions );
		$filename              = 'bhec-export-' . gmdate( 'Y-m-d' ) . '.json';

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * Handle settings import: verify nonce/capability, read uploaded JSON, import, redirect.
	 */
	public function handle_import() {
		if ( ! isset( $_POST['bhec_import_export_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bhec_import_export_nonce'] ) ), 'bhec_import_export' ) ) {
			wp_die( esc_html__( 'Invalid nonce.', 'backhaus-email-campaigns' ) );
		}

		// Settings are for administrators, as on the Settings tabs.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'backhaus-email-campaigns' ) );
		}

		// The import form is on Settings > Status.
		$redirect_url = BHEC_Admin_Settings::url( 'status' );

		// Validate the uploaded file.
		if ( empty( $_FILES['import_file'] ) || empty( $_FILES['import_file']['tmp_name'] ) ) {
			set_transient( 'bhec_import_notice', array(
				'type'    => 'error',
				'message' => __( 'No file was uploaded.', 'backhaus-email-campaigns' ),
			), 60 );
			wp_safe_redirect( $redirect_url );
			exit;
		}

		// Only the parts used here, cleaned. $_FILES isn't slashed by WordPress.
		$file = array(
			'name'     => isset( $_FILES['import_file']['name'] ) ? sanitize_file_name( $_FILES['import_file']['name'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			'tmp_name' => isset( $_FILES['import_file']['tmp_name'] ) ? sanitize_text_field( $_FILES['import_file']['tmp_name'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			'error'    => isset( $_FILES['import_file']['error'] ) ? (int) $_FILES['import_file']['error'] : UPLOAD_ERR_NO_FILE,
		);

		// Check for upload errors.
		if ( UPLOAD_ERR_OK !== $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			set_transient( 'bhec_import_notice', array(
				'type'    => 'error',
				'message' => __( 'File upload failed.', 'backhaus-email-campaigns' ),
			), 60 );
			wp_safe_redirect( $redirect_url );
			exit;
		}

		// Validate file extension.
		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( 'json' !== $ext ) {
			set_transient( 'bhec_import_notice', array(
				'type'    => 'error',
				'message' => __( 'Invalid file type. Please upload a .json file.', 'backhaus-email-campaigns' ),
			), 60 );
			wp_safe_redirect( $redirect_url );
			exit;
		}

		// Read and decode.
		$contents = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data     = json_decode( $contents, true );

		if ( ! is_array( $data ) ) {
			set_transient( 'bhec_import_notice', array(
				'type'    => 'error',
				'message' => __( 'Invalid JSON file. Could not decode the file contents.', 'backhaus-email-campaigns' ),
			), 60 );
			wp_safe_redirect( $redirect_url );
			exit;
		}

		// Validate plugin version key exists.
		if ( empty( $data['plugin_version'] ) ) {
			set_transient( 'bhec_import_notice', array(
				'type'    => 'error',
				'message' => __( 'Invalid export file. Missing plugin version information.', 'backhaus-email-campaigns' ),
			), 60 );
			wp_safe_redirect( $redirect_url );
			exit;
		}

		$overwrite            = ! empty( $_POST['overwrite_settings'] );
		$import_subscriptions = ! empty( $_POST['import_subscriptions'] );

		$summary = BHEC_Import_Export::import( $data, $overwrite, $import_subscriptions );

		$message = sprintf(
			/* translators: 1: settings count, 2: segments count, 3: templates count, 4: subscriptions count */
			__( 'Import complete. Settings: %1$d, Segments: %2$d, Templates: %3$d, Subscriptions: %4$d.', 'backhaus-email-campaigns' ),
			$summary['settings_count'],
			$summary['segments_count'],
			$summary['templates_count'],
			$summary['subscriptions_count']
		);

		set_transient( 'bhec_import_notice', array(
			'type'    => 'success',
			'message' => $message,
		), 60 );

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Register public-facing hooks.
	 */
	private function register_public_hooks() {
		$public = new BHEC_Public();
		add_action( 'template_redirect', array( $public, 'handle_request' ) );
		add_action( 'init', array( $public, 'register_rewrite_rules' ) );
		add_filter( 'query_vars', array( $public, 'register_query_vars' ) );
	}

	/**
	 * Register WooCommerce-specific hooks.
	 */
	private function register_woocommerce_hooks() {
		// Checkout opt-in (classic shortcode checkout).
		$checkout = new BHEC_Checkout_Optin();
		add_action( 'woocommerce_review_order_before_submit', array( $checkout, 'render_checkbox' ) );
		add_action( 'woocommerce_checkout_order_processed', array( $checkout, 'process_optin' ), 10, 3 );

		
		// My Account.
		$myaccount = new BHEC_MyAccount();
		add_action( 'init', array( $myaccount, 'register_endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( $myaccount, 'add_menu_item' ) );
		add_action( 'woocommerce_account_email-preferences_endpoint', array( $myaccount, 'render_content' ) );

		
		// An order counts toward an email's revenue once it's paid, not when the
		// thank-you page loads, which an unpaid order reaches too.
		add_action( 'woocommerce_order_status_processing', array( $this, 'track_conversion' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'track_conversion' ) );

			}

	/**
	 * Credit a paid order to the email its customer last clicked, if the click
	 * falls within the conversion window. Runs when an order becomes paid.
	 *
	 * @param int $order_id Order ID.
	 */
	public function track_conversion( $order_id ) {
		if ( 'yes' !== get_option( 'bhec_tracking_enabled', 'yes' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->is_paid() ) {
			return;
		}

		// Prevent duplicate tracking (check order meta).
		if ( $order->get_meta( '_bhec_conversion_tracked' ) ) {
			return;
		}

		$email = $order->get_billing_email();
		if ( ! $email ) {
			return;
		}

		$window_days = max( 1, (int) get_option( 'bhec_conversion_window_days', 7 ) );

		global $wpdb;
		$events_table = $wpdb->prefix . 'bhec_tracking_events';

		// The most recent click within the window. Click times come from the
		// database clock, so the window is measured on it too, not store time.
		$click = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT campaign_id, queue_id FROM $events_table
				WHERE email = %s AND event_type = 'click' AND created_at >= DATE_SUB( NOW(), INTERVAL %d DAY )
				ORDER BY created_at DESC LIMIT 1",
				$email,
				$window_days
			)
		);

		if ( $click ) {
			$revenue = $order->get_total();
			BHEC_Tracker::record_event(
				(int) $click->campaign_id,
				(int) $click->queue_id,
				$email,
				'conversion',
				(string) $revenue
			);
			$order->update_meta_data( '_bhec_conversion_revenue', $revenue );
			$order->update_meta_data( '_bhec_conversion_campaign_id', $click->campaign_id );
			$order->update_meta_data( '_bhec_conversion_tracked', '1' );
			$order->save();
		}
	}
}
