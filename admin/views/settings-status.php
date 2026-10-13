<?php
/**
 * Settings > Status: is email working, a test email, the facts support asks
 * for, maintenance, and moving settings to another store.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

// Included from inside a render method, so these variables are local to it.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

global $wpdb;

// This tab's assets load here rather than through BHEC_Admin::SCREENS, which
// loads per page, not per tab. The stylesheet prints now to avoid a flash.
$bhec_tools_ver = static function ( $path ) {
	$time = @filemtime( BHEC_PLUGIN_DIR . $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	return $time ? BHEC_VERSION . '.' . $time : BHEC_VERSION;
};
wp_enqueue_style( 'bhec-settings-tools', BHEC_PLUGIN_URL . 'admin/css/bhec-settings-tools.css', array( 'bhec-ui' ), $bhec_tools_ver( 'admin/css/bhec-settings-tools.css' ) );
wp_print_styles( 'bhec-settings-tools' );
wp_enqueue_script( 'bhec-settings-tools', BHEC_PLUGIN_URL . 'admin/js/bhec-settings-tools.js', array( 'jquery', 'bhec-admin' ), $bhec_tools_ver( 'admin/js/bhec-settings-tools.js' ), true );
wp_localize_script(
	'bhec-settings-tools',
	'bhecTools',
	array(
		'i18n' => array(
			'error'            => __( 'Something went wrong. Please try again.', 'backhaus-email-campaigns' ),
			'sending'          => __( 'Sending...', 'backhaus-email-campaigns' ),
			/* translators: %s: email address */
			'testSent'         => __( 'Sent to %s. Check that inbox, and its spam folder.', 'backhaus-email-campaigns' ),
			'testFailed'       => __( 'WordPress couldn\'t send it. Check your mail plugin\'s settings and its log, then try again.', 'backhaus-email-campaigns' ),
			'confirmCoupons'   => __( 'Delete every expired coupon this plugin made for customers? Coupons you made yourself aren\'t touched. This can\'t be undone.', 'backhaus-email-campaigns' ),
			'deleting'         => __( 'Deleting...', 'backhaus-email-campaigns' ),
			'confirmRestart'   => __( 'Restart background sending? Its schedule is cleared and set up again, and the next check runs within a minute.', 'backhaus-email-campaigns' ),
			'restarting'       => __( 'Restarting...', 'backhaus-email-campaigns' ),
			/* translators: %s: date and time */
			'restarted'        => __( 'Restarted. Next check %s.', 'backhaus-email-campaigns' ),
			'confirmImport'    => __( 'Import this file? Its segments and templates are added to this store.', 'backhaus-email-campaigns' ),
			'importReplace'    => __( 'Your current settings are replaced with the file\'s.', 'backhaus-email-campaigns' ),
			'importKeep'       => __( 'Settings you\'ve already set here stay as they are.', 'backhaus-email-campaigns' ),
			'importPeople'     => __( 'Subscription choices in the file are applied too, except that nobody who unsubscribed here is subscribed again.', 'backhaus-email-campaigns' ),
		),
	)
);

$checks    = BHEC_Admin_Status::health_checks();
$attention = count(
	array_filter(
		$checks,
		function ( $check ) {
			return in_array( $check['level'], array( 'warn', 'bad' ), true );
		}
	)
);
$badge_class = array(
	'good' => ' bhec-badge-good',
	'warn' => ' bhec-badge-warn',
	'bad'  => ' bhec-badge-bad',
	'none' => '',
);

$sending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bhec_campaigns WHERE status IN ('queued', 'sending', 'paused')" );
// An unsubscribed row without opted_out_at only holds a token and counts as no record.
$people = $wpdb->get_row(
	"SELECT SUM(status = 'subscribed') AS subscribed,
		SUM(status = 'unsubscribed' AND opted_out_at IS NOT NULL) AS unsubscribed,
		SUM(status = 'pending') AS pending
	FROM {$wpdb->prefix}bhec_subscriptions"
);
$tables      = BHEC_Admin_Status::tables();
$total_bytes = array_sum( array_map( 'floatval', wp_list_pluck( $tables, 'bytes' ) ) );
$wc_version  = defined( 'WC_VERSION' ) ? WC_VERSION : __( 'Not active', 'backhaus-email-campaigns' );
?>
<div class="postbox bhec-health">
	<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Is Email Working?', 'backhaus-email-campaigns' ); ?></h2></div>
	<div class="inside">
		<p class="bhec-health-verdict">
			<?php
			if ( $attention ) {
				/* translators: %s: number of problems */
				echo esc_html( sprintf( _n( '%s thing needs attention.', '%s things need attention.', $attention, 'backhaus-email-campaigns' ), number_format_i18n( $attention ) ) );
			} else {
				esc_html_e( 'Everything looks fine.', 'backhaus-email-campaigns' );
			}
			?>
		</p>
		<ul class="bhec-health-list">
			<?php foreach ( $checks as $check ) : ?>
				<li class="bhec-health-item">
					<span class="bhec-health-label"><?php echo esc_html( $check['label'] ); ?></span>
					<span class="bhec-health-badge"><span class="bhec-badge<?php echo esc_attr( $badge_class[ $check['level'] ] ); ?>"><?php echo esc_html( $check['badge'] ); ?></span></span>
					<div class="bhec-health-detail">
						<p><?php echo esc_html( $check['lines'][0] ); ?></p>
						<?php if ( count( $check['lines'] ) > 1 || '' !== $check['link'] ) : ?>
							<p class="bhec-hint">
								<?php echo esc_html( implode( ' ', array_slice( $check['lines'], 1 ) ) ); ?>
								<?php echo wp_kses( $check['link'], BHEC_Admin::allowed_html() ); ?>
							</p>
						<?php endif; ?>
						<?php if ( '' !== $check['actions'] ) : ?>
							<p class="bhec-health-actions"><?php echo wp_kses( $check['actions'], BHEC_Admin::allowed_html() ); ?></p>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
</div>

<h2><?php esc_html_e( 'Send a Test Email', 'backhaus-email-campaigns' ); ?></h2>
<p class="bhec-hint"><?php esc_html_e( 'Sends a short plain email the same way campaigns go out, so you can check it arrives.', 'backhaus-email-campaigns' ); ?></p>
<div class="bhec-test-email">
	<label class="bhec-label" for="bhec-test-email-to"><?php esc_html_e( 'Send To', 'backhaus-email-campaigns' ); ?></label>
	<div class="bhec-inline">
		<input type="email" id="bhec-test-email-to" class="regular-text" value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" required>
		<button type="button" class="button button-primary" id="bhec-send-test-email"><?php esc_html_e( 'Send Test Email', 'backhaus-email-campaigns' ); ?></button>
	</div>
	<p class="bhec-tool-status" id="bhec-test-email-status" aria-live="polite"></p>
</div>

<h2><?php esc_html_e( 'Environment', 'backhaus-email-campaigns' ); ?></h2>
<table class="widefat striped bhec-facts">
	<tbody>
		<tr>
			<th scope="row"><?php esc_html_e( 'Plugin Version', 'backhaus-email-campaigns' ); ?></th>
			<td>
				<?php
				/* translators: 1: version number, 2: edition, Free or Pro */
				echo esc_html( sprintf( __( '%1$s (%2$s)', 'backhaus-email-campaigns' ), BHEC_VERSION, bhec_is_pro() ? __( 'Pro', 'backhaus-email-campaigns' ) : __( 'Free', 'backhaus-email-campaigns' ) ) );
				?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'WordPress Version', 'backhaus-email-campaigns' ); ?></th>
			<td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'WooCommerce Version', 'backhaus-email-campaigns' ); ?></th>
			<td><?php echo esc_html( $wc_version ); ?></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'PHP Version', 'backhaus-email-campaigns' ); ?></th>
			<td><?php echo esc_html( phpversion() ); ?></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Store Time Zone', 'backhaus-email-campaigns' ); ?></th>
			<td><?php echo esc_html( BHEC_Admin_Campaigns::timezone_label() ); ?></td>
		</tr>
	</tbody>
</table>

<h2><?php esc_html_e( 'Campaigns and Subscribers', 'backhaus-email-campaigns' ); ?></h2>
<table class="widefat striped bhec-facts">
	<tbody>
		<tr>
			<th scope="row"><?php esc_html_e( 'Campaigns Sending', 'backhaus-email-campaigns' ); ?></th>
			<td>
				<?php if ( $sending ) : ?>
					<a href="<?php echo esc_url( BHEC_Admin_Campaigns::url( array( 'view' => 'sending' ) ) ); ?>"><?php echo esc_html( number_format_i18n( $sending ) ); ?></a>
				<?php else : ?>
					0
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Subscribed', 'backhaus-email-campaigns' ); ?></th>
			<td><?php echo esc_html( number_format_i18n( (int) ( $people->subscribed ?? 0 ) ) ); ?></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Unsubscribed', 'backhaus-email-campaigns' ); ?></th>
			<td><?php echo esc_html( number_format_i18n( (int) ( $people->unsubscribed ?? 0 ) ) ); ?></td>
		</tr>
		<?php if ( (int) ( $people->pending ?? 0 ) > 0 ) : ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Waiting to Confirm', 'backhaus-email-campaigns' ); ?></th>
				<td><?php echo esc_html( number_format_i18n( (int) $people->pending ) ); ?></td>
			</tr>
		<?php endif; ?>
	</tbody>
</table>
<?php if ( 'auto' === get_option( 'bhec_optin_mode', 'auto' ) ) : ?>
	<p class="bhec-hint"><?php esc_html_e( 'Sign-up is automatic, so customers who haven\'t chosen either way also get campaigns. They aren\'t counted above.', 'backhaus-email-campaigns' ); ?></p>
<?php endif; ?>

<h2><?php esc_html_e( 'Database Tables', 'backhaus-email-campaigns' ); ?></h2>
<table class="widefat striped bhec-tables">
	<thead>
		<tr>
			<th scope="col"><?php esc_html_e( 'Table', 'backhaus-email-campaigns' ); ?></th>
			<th scope="col" class="bhec-num"><?php esc_html_e( 'Rows', 'backhaus-email-campaigns' ); ?></th>
			<th scope="col" class="bhec-num"><?php esc_html_e( 'Size', 'backhaus-email-campaigns' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php if ( ! $tables ) : ?>
			<tr><td colspan="3"><?php esc_html_e( 'No tables found. Deactivate and activate the plugin to create them.', 'backhaus-email-campaigns' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $tables as $table ) : ?>
			<tr>
				<td><code><?php echo esc_html( $table->name ); ?></code></td>
				<td class="bhec-num"><?php echo esc_html( number_format_i18n( (int) $table->rows ) ); ?></td>
				<td class="bhec-num"><?php echo esc_html( size_format( (float) $table->bytes, 1 ) ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
	<tfoot>
		<tr>
			<th scope="row"><?php esc_html_e( 'Total', 'backhaus-email-campaigns' ); ?></th>
			<td></td>
			<td class="bhec-num"><strong><?php echo esc_html( size_format( $total_bytes, 1 ) ); ?></strong></td>
		</tr>
	</tfoot>
</table>
<p class="bhec-hint"><?php esc_html_e( 'Row counts are the database\'s estimates.', 'backhaus-email-campaigns' ); ?></p>

<h2><?php esc_html_e( 'Maintenance', 'backhaus-email-campaigns' ); ?></h2>
<table class="form-table bhec-maintenance" role="presentation">
	<tbody>
		<?php  ?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Background Sending', 'backhaus-email-campaigns' ); ?></th>
			<td>
				<p class="bhec-inline">
					<button type="button" class="button bhec-reschedule-cron"><?php esc_html_e( 'Restart Background Sending', 'backhaus-email-campaigns' ); ?></button>
					<span class="bhec-tool-status" aria-live="polite"></span>
				</p>
				<p class="description"><?php esc_html_e( 'Sets up its once-a-minute schedule again. Use it if Background Sending above isn\'t running.', 'backhaus-email-campaigns' ); ?></p>
			</td>
		</tr>
	</tbody>
</table>

<?php if ( current_user_can( 'manage_options' ) ) : ?>
	<h2><?php esc_html_e( 'Move Settings to Another Store', 'backhaus-email-campaigns' ); ?></h2>
	<p class="bhec-hint bhec-move-intro"><?php esc_html_e( 'Download your settings, saved segments and templates as a file, then import it on the other store. Campaigns, reports and logs stay here. The CAPTCHA secret key and license key are left out for safety, so enter those again on the other store.', 'backhaus-email-campaigns' ); ?></p>
	<div class="bhec-grid bhec-move">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="postbox">
			<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Download Settings', 'backhaus-email-campaigns' ); ?></h2></div>
			<div class="inside">
				<input type="hidden" name="action" value="bhec_export_settings">
				<?php wp_nonce_field( 'bhec_import_export', 'bhec_import_export_nonce' ); ?>
				<label class="bhec-toggle-row">
					<input type="checkbox" name="include_subscriptions" value="1">
					<span><?php esc_html_e( 'Include who\'s subscribed and unsubscribed', 'backhaus-email-campaigns' ); ?></span>
				</label>
				<button type="submit" class="button"><?php esc_html_e( 'Download Settings', 'backhaus-email-campaigns' ); ?></button>
			</div>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="postbox bhec-import-form">
			<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Import Settings', 'backhaus-email-campaigns' ); ?></h2></div>
			<div class="inside">
				<input type="hidden" name="action" value="bhec_import_settings">
				<?php wp_nonce_field( 'bhec_import_export', 'bhec_import_export_nonce' ); ?>
				<label class="bhec-label" for="bhec-import-file"><?php esc_html_e( 'Settings File', 'backhaus-email-campaigns' ); ?></label>
				<input type="file" id="bhec-import-file" name="import_file" accept=".json,application/json" required>
				<label class="bhec-toggle-row bhec-import-option">
					<input type="checkbox" name="overwrite_settings" value="1" checked>
					<span><?php esc_html_e( 'Replace settings already set here', 'backhaus-email-campaigns' ); ?></span>
				</label>
				<label class="bhec-toggle-row">
					<input type="checkbox" name="import_subscriptions" value="1">
					<span><?php esc_html_e( 'Import who\'s subscribed and unsubscribed. Nobody who unsubscribed here is subscribed again.', 'backhaus-email-campaigns' ); ?></span>
				</label>
				<button type="submit" class="button"><?php esc_html_e( 'Import Settings', 'backhaus-email-campaigns' ); ?></button>
			</div>
		</form>
	</div>
<?php endif; ?>
