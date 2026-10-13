<?php
/**
 * WordPress Dashboard widget: the plugin's numbers at a glance, what is
 * sending now and the latest campaigns, with links to Campaigns and Reports.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Dashboard_Widget {

	/**
	 * Register the widget and its styles for users who can manage the store.
	 * Runs on wp_dashboard_setup, before the Dashboard prints its styles.
	 */
	public function register() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'bhec_dashboard_widget',
			__( 'Email Campaigns', 'backhaus-email-campaigns' ),
			array( $this, 'render' )
		);

		wp_enqueue_style( 'bhec-ui', BHEC_PLUGIN_URL . 'admin/css/bhec-ui.css', array(), self::asset_version( 'admin/css/bhec-ui.css' ) );
		wp_enqueue_style( 'bhec-reports', BHEC_PLUGIN_URL . 'admin/css/bhec-reports.css', array( 'bhec-ui' ), self::asset_version( 'admin/css/bhec-reports.css' ) );
	}

	/**
	 * Plugin version plus the file's time, so a changed file isn't served stale.
	 *
	 * @param string $path Path inside the plugin folder.
	 * @return string
	 */
	private static function asset_version( $path ) {
		$time = @filemtime( BHEC_PLUGIN_DIR . $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return $time ? BHEC_VERSION . '.' . $time : BHEC_VERSION;
	}

	/**
	 * Render the widget.
	 */
	public function render() {
		global $wpdb;

		$campaigns_table     = $wpdb->prefix . 'bhec_campaigns';
		$subscriptions_table = $wpdb->prefix . 'bhec_subscriptions';

		$total_campaigns   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $campaigns_table" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$total_sent        = (int) $wpdb->get_var( "SELECT COALESCE(SUM(sent_count), 0) FROM $campaigns_table" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$total_subscribers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $subscriptions_table WHERE status = 'subscribed'" ); // phpcs:ignore WordPress.DB.PreparedSQL
		// An unsubscribed row without opted_out_at only holds a token (explicit
		// mode) and counts as no record, so it isn't an opt-out.
		$total_unsubscribed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $subscriptions_table WHERE status = 'unsubscribed' AND opted_out_at IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL

		$columns = 'id, title, subject, status, scheduled_at, started_at, completed_at, updated_at, recipient_count, sent_count, failed_count, opened_count';
		$recent  = $wpdb->get_results( "SELECT $columns FROM $campaigns_table ORDER BY created_at DESC LIMIT 5" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$sending = $wpdb->get_results( "SELECT $columns FROM $campaigns_table WHERE status IN ('sending', 'queued') ORDER BY started_at DESC LIMIT 5" ); // phpcs:ignore WordPress.DB.PreparedSQL

		// Held emails wait for the admin's decision, so the widget shows them too.
		$held = array();
		$ids  = array_values( array_map( 'intval', wp_list_pluck( $recent, 'id' ) ) );
		if ( $ids ) {
			$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
			$held         = wp_list_pluck(
				$wpdb->get_results(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one placeholder per value.
						"SELECT campaign_id, COUNT(*) AS n FROM {$wpdb->prefix}bhec_queue WHERE status = 'held' AND campaign_id IN ( $placeholders ) GROUP BY campaign_id",
						$ids
					)
				),
				'n',
				'campaign_id'
			);
		}

		$stats = array(
			__( 'Campaigns', 'backhaus-email-campaigns' )    => $total_campaigns,
			__( 'Emails Sent', 'backhaus-email-campaigns' )  => $total_sent,
			__( 'Subscribers', 'backhaus-email-campaigns' )  => $total_subscribers,
			__( 'Unsubscribed', 'backhaus-email-campaigns' ) => $total_unsubscribed,
		);
		?>
		<div class="bhec-wrap bhec-widget">
			<div class="bhec-stats">
				<?php foreach ( $stats as $label => $value ) : ?>
					<div class="bhec-stat">
						<span class="bhec-stat-label"><?php echo esc_html( $label ); ?></span>
						<span class="bhec-stat-value"><?php echo esc_html( number_format_i18n( $value ) ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<?php if ( $sending ) : ?>
				<h3 class="bhec-widget-heading"><?php esc_html_e( 'Sending Now', 'backhaus-email-campaigns' ); ?></h3>
				<ul class="bhec-widget-list">
					<?php foreach ( $sending as $campaign ) : ?>
						<li>
							<?php self::render_title( $campaign ); ?>
							<span class="bhec-status-detail"><?php echo esc_html( BHEC_Admin_Campaigns::status_detail( $campaign, 'sending' ) ); ?></span>
							<?php echo wp_kses( BHEC_Admin_Campaigns::progress_bar( (int) $campaign->sent_count, (int) $campaign->recipient_count ), BHEC_Admin::allowed_html() ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<h3 class="bhec-widget-heading"><?php esc_html_e( 'Recent Campaigns', 'backhaus-email-campaigns' ); ?></h3>
			<?php if ( ! $recent ) : ?>
				<p class="bhec-muted">
					<?php esc_html_e( 'No campaigns yet.', 'backhaus-email-campaigns' ); ?>
					<a href="<?php echo esc_url( BHEC_Admin_Campaigns::url( array( 'action' => 'new' ) ) ); ?>"><?php esc_html_e( 'Create Your First Campaign', 'backhaus-email-campaigns' ); ?></a>
				</p>
			<?php else : ?>
				<ul class="bhec-widget-list">
					<?php foreach ( $recent as $campaign ) : ?>
						<?php
						$status = BHEC_Admin_Campaigns::status( $campaign );
						$detail = BHEC_Admin_Campaigns::status_detail( $campaign, $status );
						if ( 'sent' === $status && (int) $campaign->sent_count > 0 ) {
							$detail = sprintf(
								/* translators: 1: number of emails, 2: open rate */
								__( '%1$s sent, %2$s opened', 'backhaus-email-campaigns' ),
								number_format_i18n( (int) $campaign->sent_count ),
								BHEC_Admin_Campaigns::percent( (int) $campaign->opened_count, (int) $campaign->sent_count )
							);
						}
						?>
						<li class="bhec-widget-row">
							<span class="bhec-widget-main">
								<?php self::render_title( $campaign ); ?>
								<span class="bhec-status-detail"><?php echo esc_html( $detail ); ?></span>
								<?php if ( ! empty( $held[ $campaign->id ] ) ) : ?>
									<a class="bhec-status-detail bhec-held-link" href="<?php echo esc_url( BHEC_Admin_Campaigns::url( array( 'action' => 'view', 'id' => $campaign->id, 'who' => 'held' ) ) . '#recipients' ); ?>">
										<?php
										/* translators: %s: number of emails */
										echo esc_html( sprintf( _n( '%s held back', '%s held back', (int) $held[ $campaign->id ], 'backhaus-email-campaigns' ), number_format_i18n( (int) $held[ $campaign->id ] ) ) );
										?>
									</a>
								<?php endif; ?>
							</span>
							<?php echo wp_kses( BHEC_Admin_Campaigns::status_badge( $status ), BHEC_Admin::allowed_html() ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<p class="bhec-widget-links">
				<a href="<?php echo esc_url( BHEC_Admin_Campaigns::url() ); ?>"><?php esc_html_e( 'Campaigns', 'backhaus-email-campaigns' ); ?></a>
				<span aria-hidden="true">|</span>
				<a href="<?php echo esc_url( BHEC_Admin_Analytics::url() ); ?>"><?php esc_html_e( 'Reports', 'backhaus-email-campaigns' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * A campaign's name, linking to its editor (drafts) or its page.
	 *
	 * @param object $campaign Campaign row.
	 */
	private static function render_title( $campaign ) {
		$action = BHEC_Admin_Campaigns::is_editable( $campaign ) ? 'edit' : 'view';
		printf(
			'<a class="bhec-widget-title" href="%s">%s</a>',
			esc_url( BHEC_Admin_Campaigns::url( array( 'action' => $action, 'id' => $campaign->id ) ) ),
			esc_html( '' !== $campaign->title ? $campaign->title : __( '(No Name)', 'backhaus-email-campaigns' ) )
		);
	}
}
