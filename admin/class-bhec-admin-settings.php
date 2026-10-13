<?php
/**
 * Settings, in tabs. Each tab saves through its own option group, because
 * options.php writes every option in a group and would blank the fields of
 * tabs that aren't on screen. Option names are unchanged.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Admin_Settings {

	/**
	 * Tabs: slug => [ label, Pro only ]. Logs and Status render their own
	 * views (admin/views/settings-{tab}.php).
	 *
	 * @return array
	 */
	public static function tabs() {
		$tabs = array(
			'general' => array( __( 'General', 'backhaus-email-campaigns' ), false ),
			'sending' => array( __( 'Sending', 'backhaus-email-campaigns' ), false ),
			'carts'   => array( bhec_is_pro() ? __( 'Sign-Ups and Carts', 'backhaus-email-campaigns' ) : __( 'Sign-Ups', 'backhaus-email-campaigns' ), false ),
			'logs'    => array( __( 'Logs', 'backhaus-email-campaigns' ), false ),
			'status'  => array( __( 'Status', 'backhaus-email-campaigns' ), false ),
			'license' => array( BHEC_License::is_pro_build() ? __( 'License', 'backhaus-email-campaigns' ) : __( 'Pro', 'backhaus-email-campaigns' ), false ),
		);
		return array_filter(
			$tabs,
			function ( $tab ) {
				return ! $tab[1] || bhec_is_pro();
			}
		);
	}

	/**
	 * The tab on screen.
	 *
	 * @return string
	 */
	public static function current_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
		return isset( self::tabs()[ $tab ] ) ? $tab : 'general';
	}

	/**
	 * Link to a Settings tab.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	public static function url( $tab = 'general' ) {
		return add_query_arg(
			array(
				'page' => 'bhec-settings',
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Add a field to a tab: registers the option in the tab's group and the
	 * field in the tab's page.
	 *
	 * @param string          $tab      Tab slug.
	 * @param string          $section  Section id.
	 * @param string          $id       Option name.
	 * @param string          $label    Field label.
	 * @param callable|string $render   Renderer, or 'text', 'number', 'toggle'.
	 * @param array           $args     Renderer args.
	 * @param callable        $sanitize Sanitize callback.
	 */
	private function field( $tab, $section, $id, $label, $render, $args, $sanitize ) {
		$renderers = array(
			'text'   => array( $this, 'render_text_field' ),
			'number' => array( $this, 'render_number_field' ),
			'toggle' => array( $this, 'render_toggle' ),
		);
		$args['id']        = $id;
		$args['label_for'] = 'toggle' === $render ? null : $id;
		add_settings_field( $id, $label, is_string( $render ) ? $renderers[ $render ] : $render, 'bhec-settings-' . $tab, $section, $args );
		register_setting( 'bhec_settings_' . $tab, $id, $sanitize );
	}

	/**
	 * Register all settings.
	 */
	public function register_settings() {
		// ----- General -----------------------------------------------------

		add_settings_section( 'bhec_general', __( 'Sender', 'backhaus-email-campaigns' ), '__return_null', 'bhec-settings-general' );
		$this->field( 'general', 'bhec_general', 'bhec_from_name', __( 'From Name', 'backhaus-email-campaigns' ), 'text', array(
			'placeholder' => get_bloginfo( 'name' ),
			'description' => __( 'The name people see in their inbox. Leave empty to use your store name.', 'backhaus-email-campaigns' ),
		), 'sanitize_text_field' );
		$this->field( 'general', 'bhec_general', 'bhec_from_email', __( 'From Email', 'backhaus-email-campaigns' ), 'text', array(
			'type'        => 'email',
			'placeholder' => get_option( 'woocommerce_email_from_address', '' ),
			'description' => __( 'Leave empty to use your WooCommerce email address. Some mail providers only send from their own address.', 'backhaus-email-campaigns' ),
		), 'sanitize_email' );

		if ( ! bhec_is_pro() ) {
			$this->field( 'general', 'bhec_general', 'bhec_show_credit', __( 'Credit Line', 'backhaus-email-campaigns' ), 'toggle', array(
				'default'     => 'no',
				'description' => __( 'Add a small "Sent with BackHAUS Email Campaigns" line under your emails. Thank you if you do.', 'backhaus-email-campaigns' ),
			), array( $this, 'sanitize_toggle' ) );
		}

		add_settings_section( 'bhec_optin', __( 'Consent', 'backhaus-email-campaigns' ), array( $this, 'render_optin_description' ), 'bhec-settings-general' );
		$this->field( 'general', 'bhec_optin', 'bhec_optin_mode', __( 'Who Gets Marketing Email', 'backhaus-email-campaigns' ), array( $this, 'render_optin_mode' ), array(), array( $this, 'sanitize_optin_mode' ) );
		$this->field( 'general', 'bhec_optin', 'bhec_checkout_default_checked', __( 'Checkout Box Starts', 'backhaus-email-campaigns' ), array( $this, 'render_checkbox_default' ), array(), 'sanitize_key' );
		$this->field( 'general', 'bhec_optin', 'bhec_double_optin', __( 'Confirm by Email', 'backhaus-email-campaigns' ), 'toggle', array(
			'default'     => 'no',
			'description' => __( 'Send new sign-ups a link to confirm before they get marketing email (double opt-in).', 'backhaus-email-campaigns' ),
		), array( $this, 'sanitize_toggle' ) );

		add_settings_section( 'bhec_tracking', __( 'Tracking', 'backhaus-email-campaigns' ), '__return_null', 'bhec-settings-general' );
		$this->field( 'general', 'bhec_tracking', 'bhec_tracking_enabled', __( 'Opens and Clicks', 'backhaus-email-campaigns' ), 'toggle', array(
			'default'     => 'yes',
			'description' => __( 'Track when people open an email and click its links.', 'backhaus-email-campaigns' ),
		), array( $this, 'sanitize_toggle' ) );
		$this->field( 'general', 'bhec_tracking', 'bhec_conversion_window_days', __( 'Count a Sale Within', 'backhaus-email-campaigns' ), 'number', array(
			'min'         => 1,
			'max'         => 90,
			'unit'        => __( 'days of a click', 'backhaus-email-campaigns' ),
			'description' => __( 'An order placed this soon after someone clicks a link counts toward that email\'s revenue.', 'backhaus-email-campaigns' ),
		), 'absint' );
		$this->field( 'general', 'bhec_tracking', 'bhec_utm_enabled', __( 'Google Analytics', 'backhaus-email-campaigns' ), 'toggle', array(
			'default'     => 'no',
			'description' => __( 'Add UTM tags to the links in campaign and automatic emails.', 'backhaus-email-campaigns' ),
		), array( $this, 'sanitize_toggle' ) );
		$this->field( 'general', 'bhec_tracking', 'bhec_utm_source', __( 'UTM Source', 'backhaus-email-campaigns' ), 'text', array(
			'placeholder' => 'wec',
			'class'       => 'bhec-utm-field',
		), 'sanitize_text_field' );
		$this->field( 'general', 'bhec_tracking', 'bhec_utm_medium', __( 'UTM Medium', 'backhaus-email-campaigns' ), 'text', array(
			'placeholder' => 'email',
			'class'       => 'bhec-utm-field',
		), 'sanitize_text_field' );

		// ----- Sending -----------------------------------------------------

		add_settings_section(
			'bhec_sending',
			__( 'Speed Limits', 'backhaus-email-campaigns' ),
			function () {
				echo '<p>' . esc_html__( 'Match these to your mail provider, so it doesn\'t turn emails away. Campaigns and automatic emails count together. Leave a limit at 0 for none.', 'backhaus-email-campaigns' ) . '</p>';
			},
			'bhec-settings-sending'
		);
		$limits = array(
			'bhec_max_emails_per_minute' => array( __( 'Emails Per Minute', 'backhaus-email-campaigns' ), 10000, __( 'For example, Microsoft 365 allows 30 a minute.', 'backhaus-email-campaigns' ) ),
			'bhec_max_emails_per_hour'   => array( __( 'Emails Per Hour', 'backhaus-email-campaigns' ), 100000, '' ),
			'bhec_max_emails_per_day'    => array( __( 'Emails Per Day', 'backhaus-email-campaigns' ), 1000000, __( 'For example, Microsoft 365 allows 10,000 a day.', 'backhaus-email-campaigns' ) ),
		);
		foreach ( $limits as $id => $limit ) {
			$this->field( 'sending', 'bhec_sending', $id, $limit[0], 'number', array(
				'min'         => 0,
				'max'         => $limit[1],
				'description' => $limit[2],
			), 'absint' );
		}

		add_settings_section(
			'bhec_guard',
			__( 'Over-Mailing Guard', 'backhaus-email-campaigns' ),
			function () {
				echo '<p>' . esc_html__( 'Catches someone about to be emailed too often by mistake, like an automatic email that fires on every order, or a campaign sent twice. Emails over a limit are held back, not sent, and you can send them anyway from the campaign\'s page or from Automatic Emails. Leave a limit at 0 to turn it off.', 'backhaus-email-campaigns' ) . '</p>';
			},
			'bhec-settings-sending'
		);
		$guard = array(
			'bhec_guard_campaign_days' => array( __( 'Days Between Campaigns', 'backhaus-email-campaigns' ), 365, __( 'days', 'backhaus-email-campaigns' ), __( 'Each person gets at most one campaign in this many days.', 'backhaus-email-campaigns' ) ),
			'bhec_guard_daily_limit'   => array( __( 'Emails Per Person Per Day', 'backhaus-email-campaigns' ), 50, __( 'emails', 'backhaus-email-campaigns' ), __( 'Campaigns and automatic emails together, in any 24 hours. Automatic emails set to always send don\'t count.', 'backhaus-email-campaigns' ) ),
			'bhec_guard_rule_hours'    => array( __( 'Repeat an Automatic Email After', 'backhaus-email-campaigns' ), 720, __( 'hours', 'backhaus-email-campaigns' ), __( 'The same automatic email reaches a person at most once in this many hours.', 'backhaus-email-campaigns' ) ),
			'bhec_guard_breaker_holds' => array( __( 'Pause Automatic Emails After', 'backhaus-email-campaigns' ), 1000, __( 'held in 10 minutes', 'backhaus-email-campaigns' ), __( 'That many held in a short time usually means something fires too often, so automatic emails pause and you get an email.', 'backhaus-email-campaigns' ) ),
		);
		foreach ( $guard as $id => $g ) {
			$this->field( 'sending', 'bhec_guard', $id, $g[0], 'number', array(
				'min'         => 0,
				'max'         => $g[1],
				'unit'        => $g[2],
				'description' => $g[3],
			), 'absint' );
		}

		add_settings_section(
			'bhec_batch',
			__( 'Background Sending', 'backhaus-email-campaigns' ),
			function () {
				echo '<p>' . esc_html__( 'Emails go out in small runs about once a minute. The defaults suit most stores.', 'backhaus-email-campaigns' ) . '</p>';
			},
			'bhec-settings-sending'
		);
		$this->field( 'sending', 'bhec_batch', 'bhec_batch_size', __( 'Emails Per Run', 'backhaus-email-campaigns' ), 'number', array(
			'min' => 1,
			'max' => 500,
		), 'absint' );
		$this->field( 'sending', 'bhec_batch', 'bhec_batch_delay', __( 'Pause Between Emails', 'backhaus-email-campaigns' ), 'number', array(
			'min'  => 0,
			'max'  => 60,
			'unit' => __( 'seconds', 'backhaus-email-campaigns' ),
		), 'absint' );
		$this->field( 'sending', 'bhec_batch', 'bhec_max_retries', __( 'Retries for a Failed Email', 'backhaus-email-campaigns' ), 'number', array(
			'min'         => 0,
			'max'         => 10,
			'description' => __( 'A failed email is tried again later, up to this many times.', 'backhaus-email-campaigns' ),
		), 'absint' );

		// ----- Sign-ups, and carts in Pro ------------------------------------

		add_settings_section(
			'bhec_signup',
			__( 'Sign-Up Form', 'backhaus-email-campaigns' ),
			function () {
				printf(
					'<p>%s</p>',
					sprintf(
						/* translators: 1: shortcode, 2: link to the General tab */
						esc_html__( 'Add a sign-up form to any page with the %1$s shortcode. To have new sign-ups confirm by email first, turn on Confirm by Email under %2$s.', 'backhaus-email-campaigns' ),
						'<code>[bhec_signup]</code>',
						'<a href="' . esc_url( self::url( 'general' ) ) . '">' . esc_html__( 'General', 'backhaus-email-campaigns' ) . '</a>'
					)
				);
			},
			'bhec-settings-carts'
		);

		
		add_settings_section(
			'bhec_captcha',
			__( 'Bot Protection', 'backhaus-email-campaigns' ),
			function () {
				$what = bhec_is_pro()
					? __( 'Stops bots from filling in your sign-up form and saved carts. Both services work invisibly; people don\'t solve puzzles.', 'backhaus-email-campaigns' )
					: __( 'Stops bots from filling in your sign-up form. Both services work invisibly; people don\'t solve puzzles.', 'backhaus-email-campaigns' );
				echo '<p>' . esc_html( $what ) . '</p>';
			},
			'bhec-settings-carts'
		);
		$this->field( 'carts', 'bhec_captcha', 'bhec_captcha_provider', __( 'Service', 'backhaus-email-campaigns' ), array( $this, 'render_captcha_provider' ), array(), 'sanitize_key' );
		$this->field( 'carts', 'bhec_captcha', 'bhec_captcha_site_key', __( 'Site Key', 'backhaus-email-campaigns' ), 'text', array(
			'class' => 'bhec-captcha-field',
		), 'sanitize_text_field' );
		$this->field( 'carts', 'bhec_captcha', 'bhec_captcha_secret_key', __( 'Secret Key', 'backhaus-email-campaigns' ), array( $this, 'render_secret_field' ), array(
			'class' => 'bhec-captcha-field',
		), array( $this, 'sanitize_captcha_secret' ) );

		
		// ----- Logs ----------------------------------------------------------

		add_settings_section( 'bhec_logging', __( 'What to Keep', 'backhaus-email-campaigns' ), '__return_null', 'bhec-settings-logs' );
		$this->field( 'logs', 'bhec_logging', 'bhec_logging_mode', __( 'Email Log', 'backhaus-email-campaigns' ), array( $this, 'render_logging_mode' ), array(), 'sanitize_key' );
		$this->field( 'logs', 'bhec_logging', 'bhec_log_retention_days', __( 'Keep Logs For', 'backhaus-email-campaigns' ), 'number', array(
			'min'         => 0,
			'max'         => 3650,
			'unit'        => __( 'days', 'backhaus-email-campaigns' ),
			'description' => __( '0 keeps them forever. 90 days suits most stores.', 'backhaus-email-campaigns' ),
		), 'absint' );
	}

	/**
	 * Render the settings page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'backhaus-email-campaigns' ) );
		}
		// For the Logs tab's view, which doesn't read the request itself.
		$logs_deleted = BHEC_Admin::screen_arg( 'deleted' );
		$logs_deleted = '' !== $logs_deleted ? absint( $logs_deleted ) : null;
		$logs_result  = BHEC_Admin::screen_arg( 'result' );
		include BHEC_PLUGIN_DIR . 'admin/views/page-settings.php';
	}

	/**
	 * A tab's settings form. Saving goes through options.php, which needs
	 * manage_options, so other users see the values without a Save button.
	 *
	 * @param string $tab Tab slug.
	 */
	public static function render_settings_form( $tab ) {
		$can_save = current_user_can( 'manage_options' );
		echo '<form method="post" action="options.php" class="bhec-settings-form">';
		settings_fields( 'bhec_settings_' . $tab );
		do_settings_sections( 'bhec-settings-' . $tab );
		if ( $can_save ) {
			submit_button( __( 'Save Changes', 'backhaus-email-campaigns' ) );
		} else {
			echo '<p class="bhec-hint">' . esc_html__( 'Only site administrators can change these settings.', 'backhaus-email-campaigns' ) . '</p>';
		}
		echo '</form>';
	}

	/**
	 * Data for bhec-settings.js.
	 *
	 * @return array
	 */
	public static function script_data() {
		return array(
			'i18n' => array(
				'activating'     => __( 'Activating...', 'backhaus-email-campaigns' ),
				'deactivating'   => __( 'Deactivating...', 'backhaus-email-campaigns' ),
				'confirmDeactivate' => __( 'Deactivate the license on this site? Everything keeps working; you stop getting updates and support here until you activate it again.', 'backhaus-email-campaigns' ),
				'failed'         => __( 'That didn\'t work. Check the key and try again.', 'backhaus-email-campaigns' ),
				'confirmExplicit' => __( 'Switch to opt-in at checkout? Everyone who was subscribed automatically will be unsubscribed. People who opted in themselves stay subscribed. This can\'t be undone.', 'backhaus-email-campaigns' ),
			),
		);
	}

	/**
	 * Sanitize opt-in mode and handle mode change side effects.
	 *
	 * When switching to explicit mode, all customers without an explicit
	 * subscription record are set to unsubscribed (GDPR-safe default).
	 *
	 * @param string $new_value The new mode value.
	 * @return string Sanitized value.
	 */
	public function sanitize_optin_mode( $new_value ) {
		$new_value = sanitize_key( $new_value );
		$old_value = get_option( 'bhec_optin_mode', 'auto' );

		if ( $old_value !== $new_value && 'explicit' === $new_value ) {
			// Switching TO explicit: mark all auto-subscribed customers as unsubscribed.
			// Only affects records with source='auto'. Customers who explicitly opted in
			// (checkout, myaccount, admin) keep their status.
			global $wpdb;
			$table = $wpdb->prefix . 'bhec_subscriptions';
			$count = $wpdb->query(
				"UPDATE $table SET status = 'unsubscribed', opted_out_at = NOW()
				WHERE status = 'subscribed' AND source = 'auto'"
			);

			if ( $count > 0 ) {
				add_settings_error(
					'bhec_optin_mode',
					'bhec_optin_mode_changed',
					sprintf(
						/* translators: %d: number of customers unsubscribed */
						__( 'Switched to opt-in at checkout. %d people who were subscribed automatically are now unsubscribed. People who opted in themselves are unchanged.', 'backhaus-email-campaigns' ),
						$count
					),
					'warning'
				);
			}
		}

		return $new_value;
	}

	/**
	 * A ticked box saves 'yes'; an unticked one isn't posted at all.
	 *
	 * @param mixed $value Submitted value.
	 * @return string 'yes' or 'no'.
	 */
	public function sanitize_toggle( $value ) {
		return 'yes' === $value ? 'yes' : 'no';
	}

	/**
	 * Keep the saved CAPTCHA secret when the field is left empty; the page
	 * never shows it.
	 *
	 * @param string $value Submitted value.
	 * @return string
	 */
	public function sanitize_captcha_secret( $value ) {
		$value = sanitize_text_field( (string) $value );
		return '' === $value ? (string) get_option( 'bhec_captcha_secret_key', '' ) : $value;
	}

	public function render_optin_description() {
		echo '<p>' . esc_html__( 'Nobody who unsubscribes gets marketing email again unless they sign up again themselves.', 'backhaus-email-campaigns' ) . '</p>';
	}

	/**
	 * Render the opt-in mode radio buttons.
	 */
	public function render_optin_mode() {
		$value = get_option( 'bhec_optin_mode', 'auto' );
		?>
		<fieldset data-current="<?php echo esc_attr( $value ); ?>">
			<legend class="screen-reader-text"><?php esc_html_e( 'Who Gets Marketing Email', 'backhaus-email-campaigns' ); ?></legend>
			<label>
				<input type="radio" name="bhec_optin_mode" value="auto" <?php checked( $value, 'auto' ); ?>>
				<?php esc_html_e( 'Every customer, unless they unsubscribe (auto opt-in)', 'backhaus-email-campaigns' ); ?>
			</label>
			<br>
			<label>
				<input type="radio" name="bhec_optin_mode" value="explicit" <?php checked( $value, 'explicit' ); ?>>
				<?php esc_html_e( 'Only customers who tick a box at checkout (explicit opt-in)', 'backhaus-email-campaigns' ); ?>
			</label>
			<p class="description"><?php esc_html_e( 'Switching to explicit opt-in unsubscribes everyone who was subscribed automatically. People who opted in themselves stay subscribed.', 'backhaus-email-campaigns' ); ?></p>
		</fieldset>
		<?php
	}

	/**
	 * Render the checkout checkbox default setting.
	 */
	public function render_checkbox_default() {
		$value = get_option( 'bhec_checkout_default_checked', 'yes' );
		?>
		<fieldset id="bhec-checkbox-default-wrap">
			<legend class="screen-reader-text"><?php esc_html_e( 'Checkout Box Starts', 'backhaus-email-campaigns' ); ?></legend>
			<label>
				<input type="radio" name="bhec_checkout_default_checked" value="yes" <?php checked( $value, 'yes' ); ?>>
				<?php esc_html_e( 'Ticked', 'backhaus-email-campaigns' ); ?>
			</label>
			<br>
			<label>
				<input type="radio" name="bhec_checkout_default_checked" value="no" <?php checked( $value, 'no' ); ?>>
				<?php esc_html_e( 'Unticked', 'backhaus-email-campaigns' ); ?>
			</label>
			<p class="description"><?php esc_html_e( 'Only used with explicit opt-in.', 'backhaus-email-campaigns' ); ?></p>
		</fieldset>
		<?php
	}

	/**
	 * License key (Pro) or what Pro adds (Free).
	 */
	public static function render_license() {
		if ( ! BHEC_License::is_pro_build() ) {
			echo wp_kses( BHEC_Admin::logo( 64 ), BHEC_Admin::allowed_html() );
			?>
			<h2><?php esc_html_e( 'BackHAUS Email Campaigns Pro', 'backhaus-email-campaigns' ); ?></h2>
			<p><?php esc_html_e( 'Pro adds automatic emails (abandoned carts, unpaid orders, follow-ups after an order, win-backs) that can run as a series, back-in-stock and new-arrival emails, subject tests, a unique coupon for each person and saved guest carts.', 'backhaus-email-campaigns' ); ?></p>
			<p><a class="button button-primary" href="https://backhaus.sg/email-campaigns" target="_blank" rel="noopener"><?php esc_html_e( 'See Pro', 'backhaus-email-campaigns' ); ?></a></p>
			<?php
			return;
		}

			}

	/**
	 * Render a text input field.
	 *
	 * @param array $args Field args.
	 */
	public function render_text_field( $args ) {
		printf(
			'<input type="%s" id="%s" name="%s" value="%s" class="regular-text %s" placeholder="%s">',
			esc_attr( $args['type'] ?? 'text' ),
			esc_attr( $args['id'] ),
			esc_attr( $args['id'] ),
			esc_attr( get_option( $args['id'], '' ) ),
			esc_attr( $args['class'] ?? '' ),
			esc_attr( $args['placeholder'] ?? '' )
		);
		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	/**
	 * A secret: never printed back. Leave empty to keep the saved one.
	 *
	 * @param array $args Field args.
	 */
	public function render_secret_field( $args ) {
		$saved = '' !== (string) get_option( $args['id'], '' );
		printf(
			'<input type="password" id="%1$s" name="%1$s" value="" class="regular-text %2$s" autocomplete="new-password" placeholder="%3$s">',
			esc_attr( $args['id'] ),
			esc_attr( $args['class'] ?? '' ),
			esc_attr( $saved ? __( 'Saved. Type a new one to replace it.', 'backhaus-email-campaigns' ) : '' )
		);
	}

	/**
	 * Render a number input field, with an optional unit after it.
	 *
	 * @param array $args Field args.
	 */
	public function render_number_field( $args ) {
		printf(
			'<input type="number" id="%s" name="%s" value="%s" min="%d" max="%d" class="small-text">',
			esc_attr( $args['id'] ),
			esc_attr( $args['id'] ),
			esc_attr( get_option( $args['id'], 0 ) ),
			isset( $args['min'] ) ? (int) $args['min'] : 0,
			isset( $args['max'] ) ? (int) $args['max'] : 999999
		);
		if ( ! empty( $args['unit'] ) ) {
			echo ' <span class="bhec-unit">' . esc_html( $args['unit'] ) . '</span>';
		}
		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	/**
	 * Render a yes/no toggle.
	 *
	 * @param array $args Field args.
	 */
	public function render_toggle( $args ) {
		// The default must match the one the feature reads, or saving the
		// page would switch it on or off without anyone touching it.
		$value = get_option( $args['id'], $args['default'] );
		?>
		<label>
			<input type="checkbox" id="<?php echo esc_attr( $args['id'] ); ?>" name="<?php echo esc_attr( $args['id'] ); ?>" value="yes" <?php checked( $value, 'yes' ); ?>>
			<?php echo esc_html( $args['description'] ?? '' ); ?>
		</label>
		<?php
	}

	/**
	 * Render the logging mode radio buttons.
	 */
	public function render_logging_mode() {
		$value   = get_option( 'bhec_logging_mode', 'meta' );
		$choices = array(
			'meta' => __( 'Who, what and when: the address, subject and result of each email (recommended)', 'backhaus-email-campaigns' ),
			'full' => __( 'Everything, including each email\'s content (the database grows quickly)', 'backhaus-email-campaigns' ),
			'off'  => __( 'Nothing', 'backhaus-email-campaigns' ),
		);
		?>
		<fieldset>
			<legend class="screen-reader-text"><?php esc_html_e( 'Email Log', 'backhaus-email-campaigns' ); ?></legend>
			<?php foreach ( $choices as $choice => $label ) : ?>
				<label>
					<input type="radio" name="bhec_logging_mode" value="<?php echo esc_attr( $choice ); ?>" <?php checked( $value, $choice ); ?>>
					<?php echo esc_html( $label ); ?>
				</label><br>
			<?php endforeach; ?>
		</fieldset>
		<?php
	}

	/**
	 * Render the CAPTCHA provider dropdown.
	 */
	public function render_captcha_provider() {
		$value = get_option( 'bhec_captcha_provider', 'none' );
		?>
		<select name="bhec_captcha_provider" id="bhec_captcha_provider">
			<option value="none" <?php selected( $value, 'none' ); ?>><?php esc_html_e( 'Off', 'backhaus-email-campaigns' ); ?></option>
			<option value="recaptcha_v3" <?php selected( $value, 'recaptcha_v3' ); ?>><?php esc_html_e( 'Google reCAPTCHA v3', 'backhaus-email-campaigns' ); ?></option>
			<option value="turnstile" <?php selected( $value, 'turnstile' ); ?>><?php esc_html_e( 'Cloudflare Turnstile', 'backhaus-email-campaigns' ); ?></option>
		</select>
		<?php
	}
}
