<?php
/**
 * Admin menu registration and asset loading.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Admin {

	/**
	 * The admin menu icon: the one-colour Postmark glyph (brand/svg/menu-icon.svg).
	 * WordPress recolours it by its fill to match each user's admin colour scheme.
	 *
	 * @return string Data URI.
	 */
	public static function menu_icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="black" fill-rule="evenodd" d="M3.4 1.8H4.25A.95.95 0 0 0 6.15 1.8H7.65A.95.95 0 0 0 9.55 1.8H11.05A.95.95 0 0 0 12.95 1.8H14.45A.95.95 0 0 0 16.35 1.8H17.85A.95.95 0 0 0 19.75 1.8H20.6V2.55A.95.95 0 0 0 20.6 4.45V5.95A.95.95 0 0 0 20.6 7.85V9.35A.95.95 0 0 0 20.6 11.25V12.75A.95.95 0 0 0 20.6 14.65V16.15A.95.95 0 0 0 20.6 18.05V19.55A.95.95 0 0 0 20.6 21.45V22.2H19.75A.95.95 0 0 0 17.85 22.2H16.35A.95.95 0 0 0 14.45 22.2H12.95A.95.95 0 0 0 11.05 22.2H9.55A.95.95 0 0 0 7.65 22.2H6.15A.95.95 0 0 0 4.25 22.2H3.4V21.45A.95.95 0 0 0 3.4 19.55V18.05A.95.95 0 0 0 3.4 16.15V14.65A.95.95 0 0 0 3.4 12.75V11.25A.95.95 0 0 0 3.4 9.35V7.85A.95.95 0 0 0 3.4 5.95V4.45A.95.95 0 0 0 3.4 2.55ZM6.6 6.4H17.4V8.8A1.8 1.8 0 0 1 13.8 8.8A1.8 1.8 0 0 1 10.2 8.8A1.8 1.8 0 0 1 6.6 8.8ZM7.2 11.4H16.8V19.8H7.2ZM8.4 12.6H15.6V19.8H8.4ZM10.7 14.4H13.3V19.8H10.7Z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * A query argument that only chooses what one of the plugin's screens
	 * shows: which campaign, tab or filter. It changes nothing, so it carries
	 * no nonce (every action that changes data checks its own), and it is
	 * read only for people who may manage the store.
	 *
	 * @param string $key Argument name.
	 * @return string The value as a key ('' when absent).
	 */
	public static function screen_arg( $key ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only, see above.
		return isset( $_GET[ $key ] ) ? sanitize_key( wp_unslash( $_GET[ $key ] ) ) : '';
	}

	/**
	 * What wp_kses() lets through when one of the plugin's screens prints
	 * markup it built earlier (a badge, a progress bar, a button, a link):
	 * what posts may hold, plus the progress bar's ARIA values and the
	 * wrapper WooCommerce puts around a price.
	 *
	 * @return array
	 */
	public static function allowed_html() {
		$allowed         = wp_kses_allowed_html( 'post' );
		$allowed['bdi']  = array();
		$allowed['span'] = array_merge(
			isset( $allowed['span'] ) ? $allowed['span'] : array(),
			array(
				'aria-valuemin' => true,
				'aria-valuemax' => true,
				'aria-valuenow' => true,
				'translate'     => true,
			)
		);
		return $allowed;
	}

	/**
	 * The full-colour logo, for the plugin's own screens.
	 *
	 * @param int $size Pixels.
	 * @return string An <img> tag.
	 */
	public static function logo( $size = 72 ) {
		return sprintf(
			'<img class="bhec-logo" src="%s" width="%d" height="%d" alt="">',
			esc_url( plugins_url( 'admin/images/icon.svg', BHEC_PLUGIN_FILE ) ),
			(int) $size,
			(int) $size
		);
	}

	/**
	 * Tell the admin when sending is paused: on the plugin's pages (with a
	 * Resume button) and on the Dashboard (with a link).
	 */
	public function render_pause_notices() {
		$screen = get_current_screen();
		if ( ! $screen || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$on_our_page = false !== strpos( $screen->id, 'bhec-' );
		if ( ! $on_our_page && 'dashboard' !== $screen->id ) {
			return;
		}
		$time = get_option( 'time_format' );

		$provider = BHEC_Send_Control::provider_pause();
		if ( $provider ) {
			$action = $on_our_page
				? '<button type="button" class="button bhec-resume-sending" data-type="provider">' . esc_html__( 'Resume Now', 'backhaus-email-campaigns' ) . '</button>'
				: '<a href="' . esc_url( BHEC_Admin_Campaigns::url() ) . '">' . esc_html__( 'Open Campaigns', 'backhaus-email-campaigns' ) . '</a>';
			printf(
				'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p><p class="description">%3$s</p><p>%4$s</p></div>',
				esc_html__( 'Email Sending Paused.', 'backhaus-email-campaigns' ),
				esc_html( sprintf(
					/* translators: 1: time paused, 2: time it resumes */
					__( 'Your mail provider asked us to slow down at %1$s, so nothing is sent until %2$s. It resumes by itself.', 'backhaus-email-campaigns' ),
					wp_date( $time, (int) $provider['since'] ),
					wp_date( $time, (int) $provider['until'] )
				) ),
				esc_html( sprintf(
					/* translators: %s: error from the mail provider */
					__( 'Provider message: %s', 'backhaus-email-campaigns' ),
					$provider['reason']
				) ),
				wp_kses( $action, self::allowed_html() )
			);
		}

		$automatic = BHEC_Send_Control::automatic_pause();
		if ( $automatic ) {
			$action = $on_our_page
				? '<button type="button" class="button button-primary bhec-resume-sending" data-type="automatic">' . esc_html__( 'Resume Automatic Emails', 'backhaus-email-campaigns' ) . '</button>'
				: '<a href="' . esc_url( admin_url( 'admin.php?page=bhec-triggered&emails=held#emails' ) ) . '">' . esc_html__( 'Review Held Emails', 'backhaus-email-campaigns' ) . '</a>';
			printf(
				'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p><p>%3$s</p><p>%4$s</p></div>',
				esc_html__( 'Automatic Emails Paused.', 'backhaus-email-campaigns' ),
				esc_html( $automatic['reason'] ),
				esc_html__( 'Nobody gets automatic emails until you resume them. Check your automatic emails and the held ones first.', 'backhaus-email-campaigns' ),
				wp_kses( $action, self::allowed_html() )
			);
		}
	}

	/**
	 * The menu: Campaigns, Automatic Emails (Pro), Subscribers, Reports, Settings.
	 * Queue, Logs and Status stay reachable by URL while they move into
	 * Campaigns and Settings.
	 */
	public function register_menus() {
		add_menu_page(
			__( 'Email Campaigns', 'backhaus-email-campaigns' ),
			__( 'Email Campaigns', 'backhaus-email-campaigns' ),
			'manage_woocommerce',
			'bhec-campaigns',
			array( 'BHEC_Admin_Campaigns', 'render_page' ),
			self::menu_icon(),
			56
		);

		$pages = array(
			'bhec-campaigns' => array( __( 'Campaigns', 'backhaus-email-campaigns' ), array( 'BHEC_Admin_Campaigns', 'render_page' ) ),
		);
				$pages += array(
			'bhec-customers' => array( __( 'Subscribers', 'backhaus-email-campaigns' ), array( 'BHEC_Admin_Customers', 'render_page' ) ),
			'bhec-analytics' => array( __( 'Reports', 'backhaus-email-campaigns' ), array( 'BHEC_Admin_Analytics', 'render_page' ) ),
			'bhec-settings'  => array( __( 'Settings', 'backhaus-email-campaigns' ), array( 'BHEC_Admin_Settings', 'render_page' ) ),
		);
		foreach ( $pages as $slug => $page ) {
			$label = $page[0];
			if ( 'bhec-triggered' === $slug && ! bhec_is_pro() ) {
				$label .= ' <span class="bhec-pro-badge">PRO</span>';
			}
			add_submenu_page( 'bhec-campaigns', $page[0], $label, 'manage_woocommerce', $slug, $page[1] );
		}

		// Old addresses, kept so bookmarks land somewhere: redirect_moved_pages()
		// sends them on before anything renders.
		foreach ( array( 'bhec-queue', 'bhec-logs', 'bhec-status' ) as $slug ) {
			add_submenu_page( '', '', '', 'manage_woocommerce', $slug, '__return_null' );
		}
	}

	/**
	 * Old pages that became Settings tabs: send bookmarks and links there.
	 * The pages stay registered (hidden) so WordPress lets the request in.
	 */
	public function redirect_moved_pages() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$page  = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
		$moved = array(
			'bhec-logs'   => BHEC_Admin_Settings::url( 'logs' ),
			'bhec-status' => BHEC_Admin_Settings::url( 'status' ),
			// Each campaign's page now shows its progress and held emails.
			'bhec-queue'  => BHEC_Admin_Campaigns::url( array( 'view' => 'sending' ) ),
		);
		if ( isset( $moved[ $page ] ) ) {
			wp_safe_redirect( $moved[ $page ] );
			exit;
		}
	}

	/**
	 * Version for an admin asset: the plugin version plus the file's time, so
	 * a changed file is never served from a stale browser cache.
	 *
	 * @param string $path Path inside the plugin folder.
	 * @return string
	 */
	private static function asset_version( $path ) {
		$time = @filemtime( BHEC_PLUGIN_DIR . $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return $time ? BHEC_VERSION . '.' . $time : BHEC_VERSION;
	}

	/**
	 * Styles and scripts for the Campaigns list, editor and campaign page.
	 */
	private function enqueue_campaign_assets() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';

		wp_enqueue_style( 'bhec-campaigns', BHEC_PLUGIN_URL . 'admin/css/bhec-campaigns.css', array( 'bhec-admin' ), self::asset_version( 'admin/css/bhec-campaigns.css' ) );
		wp_enqueue_script( 'bhec-campaigns', BHEC_PLUGIN_URL . 'admin/js/bhec-campaigns.js', array( 'jquery', 'bhec-admin' ), self::asset_version( 'admin/js/bhec-campaigns.js' ), true );
		wp_localize_script(
			'bhec-campaigns',
			'bhecCampaigns',
			array(
				'listUrl'  => BHEC_Admin_Campaigns::url(),
				'currency' => html_entity_decode( get_woocommerce_currency_symbol() ),
				'i18n'     => array(
					'error'           => __( 'Something went wrong. Please try again.', 'backhaus-email-campaigns' ),
					/* translators: %s: campaign name */
					'confirmDelete'   => __( 'Delete "%s"? This can\'t be undone.', 'backhaus-email-campaigns' ),
					'sending'         => __( 'Sending...', 'backhaus-email-campaigns' ),
					'couponNone'      => __( 'Not in this email', 'backhaus-email-campaigns' ),
					'couponNoAmount'  => __( 'Set a discount', 'backhaus-email-campaigns' ),
					/* translators: %s: discount percentage */
					'couponPercent'   => __( '%s% off', 'backhaus-email-campaigns' ),
					/* translators: %s: discount amount with currency */
					'couponFixed'     => __( '%s off', 'backhaus-email-campaigns' ),
					'couponShipping'  => __( 'free shipping', 'backhaus-email-campaigns' ),
					/* translators: %s: number of days */
					'couponExpires'   => __( 'expires after %s days', 'backhaus-email-campaigns' ),
					'couponNoExpiry'  => __( 'never expires', 'backhaus-email-campaigns' ),
					/* translators: %s: email address */
					'replyTo'         => __( 'Replies to %s', 'backhaus-email-campaigns' ),
					'replyDefault'    => __( 'Replies to your store email', 'backhaus-email-campaigns' ),
					/* translators: %s: number of files */
					'oneAttachment'   => __( '%s attachment', 'backhaus-email-campaigns' ),
					/* translators: %s: number of files */
					'attachments'     => __( '%s attachments', 'backhaus-email-campaigns' ),
					'copies'          => __( 'with copies', 'backhaus-email-campaigns' ),
					'countFailed'     => __( 'Couldn\'t count. Try fewer filters.', 'backhaus-email-campaigns' ),
					'reachOne'        => __( 'person will get this', 'backhaus-email-campaigns' ),
					'reachMany'       => __( 'people will get this', 'backhaus-email-campaigns' ),
					'reachNone'       => __( 'Nobody matches these filters', 'backhaus-email-campaigns' ),
					/* translators: %s: number of people */
					'andOneMore'      => __( 'and %s more', 'backhaus-email-campaigns' ),
					/* translators: %s: number of people */
					'andMore'         => __( 'and %s more', 'backhaus-email-campaigns' ),
					'seeWho'          => __( 'See Who', 'backhaus-email-campaigns' ),
					'hideWho'         => __( 'Hide List', 'backhaus-email-campaigns' ),
					/* translators: %s: segment name */
					'segmentSaved'    => __( 'Saved segment "%s".', 'backhaus-email-campaigns' ),
					'editTitle'       => __( 'Edit Campaign', 'backhaus-email-campaigns' ),
					'saving'          => __( 'Saving...', 'backhaus-email-campaigns' ),
					/* translators: %s: time */
					'savedAt'         => __( 'Saved at %s', 'backhaus-email-campaigns' ),
					'needSubject'     => __( 'Add a subject before sending.', 'backhaus-email-campaigns' ),
					'needBody'        => __( 'Write a message before sending.', 'backhaus-email-campaigns' ),
					'needTime'        => __( 'Pick a date and time to send it.', 'backhaus-email-campaigns' ),
					'needCoupon'      => __( 'The message has a coupon, so set a discount for it.', 'backhaus-email-campaigns' ),
					'subjectLabel'    => __( 'Subject', 'backhaus-email-campaigns' ),
					'nobodyTitle'     => __( 'Nobody To Send To', 'backhaus-email-campaigns' ),
					'nobody'          => __( 'No subscribed customers match these filters. Change them under Who Gets This.', 'backhaus-email-campaigns' ),
					'sendTitle'       => __( 'Send This Campaign?', 'backhaus-email-campaigns' ),
					'scheduleTitle'   => __( 'Schedule This Campaign?', 'backhaus-email-campaigns' ),
					'theAudience'     => __( 'everyone who matches', 'backhaus-email-campaigns' ),
					/* translators: %s: number of people */
					'onePerson'       => __( '%s person', 'backhaus-email-campaigns' ),
					/* translators: %s: number of people */
					'people'          => __( '%s people', 'backhaus-email-campaigns' ),
					/* translators: 1: number of people, 2: date and time */
					'scheduleLine'    => __( 'It goes to %1$s on %2$s.', 'backhaus-email-campaigns' ),
					/* translators: %s: number of people */
					'sendLine'        => __( 'It goes to %s now.', 'backhaus-email-campaigns' ),
					'scheduleNote'    => __( 'Who gets it is worked out again when it sends, so the number can change.', 'backhaus-email-campaigns' ),
					/* translators: 1: number of people, 2: number of days */
					'recentOne'       => __( '%1$s more person got another campaign in the last %2$s days.', 'backhaus-email-campaigns' ),
					/* translators: 1: number of people, 2: number of days */
					'recentMany'      => __( '%1$s more people got another campaign in the last %2$s days.', 'backhaus-email-campaigns' ),
					'recentHeld'      => __( 'They\'ll be held back, not sent. You can send them anyway from the campaign\'s page.', 'backhaus-email-campaigns' ),
					/* translators: 1: number of people, 2: number of days */
					'allRecent'       => __( 'All %1$s people it matches got another campaign in the last %2$s days, so it will be held back for everyone. You can still send them anyway from the campaign\'s page.', 'backhaus-email-campaigns' ),
					'sendHeldButton'  => __( 'Create and Hold', 'backhaus-email-campaigns' ),
					/* translators: 1: campaign name, 2: date */
					'similar'         => __( 'You sent a campaign with the same subject, "%1$s", on %2$s. Check this isn\'t a repeat.', 'backhaus-email-campaigns' ),
					'scheduleButton'  => __( 'Schedule', 'backhaus-email-campaigns' ),
					/* translators: %s: number of people */
					'sendButton'      => __( 'Send to %s People', 'backhaus-email-campaigns' ),
					/* translators: %s: number of people */
					'sendButtonOne'   => __( 'Send to %s Person', 'backhaus-email-campaigns' ),
					'sendButtonPlain' => __( 'Send Now', 'backhaus-email-campaigns' ),
					'sendingTest'     => __( 'Sending...', 'backhaus-email-campaigns' ),
					/* translators: %s: email address */
					'testSent'        => __( 'Sent to %s. Check your inbox.', 'backhaus-email-campaigns' ),
					'unsaved'         => __( 'You have unsaved changes.', 'backhaus-email-campaigns' ),
				),
			)
		);

		if ( in_array( $action, array( 'new', 'edit' ), true ) ) {
			wp_enqueue_editor();
			wp_enqueue_media();
			wp_enqueue_script( 'wc-enhanced-select' );
			wp_enqueue_style( 'woocommerce_admin_styles' );
			wp_enqueue_script(
				'bhec-campaign-editor',
				BHEC_PLUGIN_URL . 'admin/js/bhec-campaign-editor.js',
				array( 'jquery', 'bhec-admin', 'bhec-campaigns', 'bhec-editor-kit', 'wc-enhanced-select' ),
				self::asset_version( 'admin/js/bhec-campaign-editor.js' ),
				true
			);
		}
	}

	/**
	 * Each screen's own stylesheet and script (admin/css|js/bhec-{name}.*),
	 * keyed by page slug, with the controller that supplies its data.
	 *
	 * A controller can define static enqueue_assets() for extra dependencies
	 * and static script_data() for data its script reads as `bhecPage`.
	 */
	const SCREENS = array(
		'bhec-triggered' => array( 'automatic', 'BHEC_Admin_Triggered' ),
		'bhec-customers' => array( 'subscribers', 'BHEC_Admin_Customers' ),
		'bhec-analytics' => array( 'reports', 'BHEC_Admin_Analytics' ),
		'bhec-settings'  => array( 'settings', 'BHEC_Admin_Settings' ),
	);

	/**
	 * Enqueue admin CSS and JS on our pages only.
	 */
	public function enqueue_assets() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
		if ( 0 !== strpos( $page, 'bhec-' ) ) {
			return;
		}

		wp_enqueue_style( 'bhec-admin', BHEC_PLUGIN_URL . 'admin/css/bhec-admin.css', array(), self::asset_version( 'admin/css/bhec-admin.css' ) );
		wp_enqueue_style( 'bhec-ui', BHEC_PLUGIN_URL . 'admin/css/bhec-ui.css', array( 'bhec-admin' ), self::asset_version( 'admin/css/bhec-ui.css' ) );

		wp_enqueue_script(
			'bhec-admin',
			BHEC_PLUGIN_URL . 'admin/js/bhec-admin.js',
			array( 'jquery', 'wp-util' ),
			self::asset_version( 'admin/js/bhec-admin.js' ),
			true
		);

		wp_localize_script( 'bhec-admin', 'bhecAdmin', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'bhec_admin_nonce' ),
			'i18n'    => array(
				'sendHeld'    => __( 'Send the held emails anyway? These people were emailed recently.', 'backhaus-email-campaigns' ),
				'discardHeld' => __( 'Leave the held emails unsent? They will not go out.', 'backhaus-email-campaigns' ),
			),
		) );

		// Editors enqueue this (with the editor and media library) when they need it.
		wp_register_script( 'bhec-editor-kit', BHEC_PLUGIN_URL . 'admin/js/bhec-editor-kit.js', array( 'jquery', 'bhec-admin' ), self::asset_version( 'admin/js/bhec-editor-kit.js' ), true );
		wp_localize_script(
			'bhec-editor-kit',
			'bhecEditorKit',
			array(
				'addImage'         => __( 'Add an Image', 'backhaus-email-campaigns' ),
				'insertImage'      => __( 'Add to Message', 'backhaus-email-campaigns' ),
				'chooseAttachment' => __( 'Choose Attachments', 'backhaus-email-campaigns' ),
				'remove'           => __( 'Remove', 'backhaus-email-campaigns' ),
				'noSubject'        => __( '(No subject)', 'backhaus-email-campaigns' ),
				/* translators: %s: number of characters */
				'subjectLength'    => __( '%s characters', 'backhaus-email-campaigns' ),
				/* translators: %s: number of characters */
				'subjectLong'      => __( '%s characters. Inboxes on phones cut long subjects short, so put the important words first.', 'backhaus-email-campaigns' ),
			)
		);

		if ( 'bhec-campaigns' === $page ) {
			$this->enqueue_campaign_assets();
			return;
		}

		if ( ! isset( self::SCREENS[ $page ] ) ) {
			return;
		}
		list( $name, $class ) = self::SCREENS[ $page ];

		$css = 'admin/css/bhec-' . $name . '.css';
		if ( file_exists( BHEC_PLUGIN_DIR . $css ) ) {
			wp_enqueue_style( 'bhec-' . $name, BHEC_PLUGIN_URL . $css, array( 'bhec-ui' ), self::asset_version( $css ) );
		}
		if ( class_exists( $class ) && method_exists( $class, 'enqueue_assets' ) ) {
			$class::enqueue_assets();
		}
		$js = 'admin/js/bhec-' . $name . '.js';
		if ( file_exists( BHEC_PLUGIN_DIR . $js ) ) {
			wp_enqueue_script( 'bhec-' . $name, BHEC_PLUGIN_URL . $js, array( 'jquery', 'bhec-admin' ), self::asset_version( $js ), true );
			if ( class_exists( $class ) && method_exists( $class, 'script_data' ) ) {
				wp_localize_script( 'bhec-' . $name, 'bhecPage', $class::script_data() );
			}
		}
	}
}
