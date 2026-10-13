<?php
/**
 * Settings > Logs: every email sent, what to keep, and clearing out old logs.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

// Included from inside a render method, so these variables are local to it.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

require_once BHEC_PLUGIN_DIR . 'admin/class-bhec-logs-list-table.php';

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
			'error'   => __( 'Something went wrong. Please try again.', 'backhaus-email-campaigns' ),
			'loading' => __( 'Loading...', 'backhaus-email-campaigns' ),
		),
	)
);

$list_table = new BHEC_Logs_List_Table();
$list_table->prepare_items();

$logging   = get_option( 'bhec_logging_mode', 'meta' );
$retention = (int) get_option( 'bhec_log_retention_days', 90 );
$stats     = BHEC_Logger::get_table_stats();
$old       = BHEC_Admin_Logs::old_entry_count();
// $logs_deleted and $logs_result come from BHEC_Admin_Settings::render_page().
$deleted = $logs_deleted;
?>
<?php if ( null !== $deleted ) : ?>
	<div class="notice notice-success is-dismissible">
		<p>
			<?php
			/* translators: %s: number of log entries */
			echo esc_html( sprintf( _n( 'Deleted %s old log entry.', 'Deleted %s old log entries.', $deleted, 'backhaus-email-campaigns' ), number_format_i18n( $deleted ) ) );
			?>
		</p>
	</div>
<?php endif; ?>

<?php if ( 'off' === $logging && $stats['rows'] > 0 ) : ?>
	<div class="notice notice-warning inline bhec-callout">
		<p>
			<?php esc_html_e( 'The email log is off, so new emails aren\'t recorded.', 'backhaus-email-campaigns' ); ?>
			<a href="#bhec-log-settings"><?php esc_html_e( 'Turn It On', 'backhaus-email-campaigns' ); ?></a>
		</p>
	</div>
<?php endif; ?>

<h2 class="screen-reader-text"><?php esc_html_e( 'Emails Sent', 'backhaus-email-campaigns' ); ?></h2>
<?php $list_table->views(); ?>
<form method="get" class="bhec-logs-table">
	<input type="hidden" name="page" value="bhec-settings">
	<input type="hidden" name="tab" value="logs">
	<?php
	if ( '' !== $logs_result ) {
		echo '<input type="hidden" name="result" value="' . esc_attr( $logs_result ) . '">';
	}
	$list_table->search_box( __( 'Search Emails', 'backhaus-email-campaigns' ), 'bhec-log' );
	$list_table->display();
	?>
</form>

<div id="bhec-log-settings">
	<?php BHEC_Admin_Settings::render_settings_form( 'logs' ); ?>
</div>

<h2><?php esc_html_e( 'Old Logs', 'backhaus-email-campaigns' ); ?></h2>
<p>
	<?php
	echo esc_html(
		sprintf(
			/* translators: 1: number of emails, 2: size such as "2 MB" */
			_n( 'The log holds %1$s email and uses %2$s.', 'The log holds %1$s emails and uses %2$s.', (int) $stats['rows'], 'backhaus-email-campaigns' ),
			number_format_i18n( (int) $stats['rows'] ),
			size_format( (float) $stats['size_mb'] * MB_IN_BYTES, 1 )
		)
	);
	?>
	<?php if ( $retention > 0 ) : ?>
		<?php
		/* translators: %s: number of days */
		echo esc_html( sprintf( _n( 'Entries older than %s day are deleted automatically once a day.', 'Entries older than %s days are deleted automatically once a day.', $retention, 'backhaus-email-campaigns' ), number_format_i18n( $retention ) ) );
		?>
	<?php else : ?>
		<?php esc_html_e( 'Entries are kept forever. Set Keep Logs For above to clear out old ones.', 'backhaus-email-campaigns' ); ?>
	<?php endif; ?>
</p>
<?php
if ( $retention > 0 && $old > 0 ) :
	$confirm = sprintf(
		/* translators: 1: number of log entries, 2: number of days */
		_n( 'Delete %1$s log entry older than %2$s days? This can\'t be undone.', 'Delete %1$s log entries older than %2$s days? This can\'t be undone.', $old, 'backhaus-email-campaigns' ),
		number_format_i18n( $old ),
		number_format_i18n( $retention )
	);
	?>
	<p class="bhec-inline">
		<button type="button" class="button" id="bhec-delete-old-logs" data-confirm="<?php echo esc_attr( $confirm ); ?>"><?php esc_html_e( 'Delete Old Logs', 'backhaus-email-campaigns' ); ?></button>
		<span class="bhec-muted">
			<?php
			/* translators: 1: number of log entries, 2: number of days */
			echo esc_html( sprintf( _n( '%1$s entry is older than %2$s days.', '%1$s entries are older than %2$s days.', $old, 'backhaus-email-campaigns' ), number_format_i18n( $old ), number_format_i18n( $retention ) ) );
			?>
		</span>
	</p>
<?php endif; ?>

<dialog class="bhec-dialog bhec-log-dialog" id="bhec-log-dialog" aria-labelledby="bhec-log-dialog-title">
	<div class="bhec-log-dialog-head">
		<h2 id="bhec-log-dialog-title"><?php esc_html_e( 'Email Details', 'backhaus-email-campaigns' ); ?></h2>
		<form method="dialog"><button type="submit" class="button-link bhec-dialog-close" aria-label="<?php esc_attr_e( 'Close', 'backhaus-email-campaigns' ); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button></form>
	</div>
	<p class="bhec-log-message" aria-live="polite"></p>
	<table class="bhec-log-facts" hidden>
		<tbody>
			<tr><th scope="row"><?php esc_html_e( 'To', 'backhaus-email-campaigns' ); ?></th><td data-field="to"></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Subject', 'backhaus-email-campaigns' ); ?></th><td data-field="subject"></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Campaign', 'backhaus-email-campaigns' ); ?></th><td data-field="source"></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Result', 'backhaus-email-campaigns' ); ?></th><td data-field="result"></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'When', 'backhaus-email-campaigns' ); ?></th><td data-field="when"></td></tr>
		</tbody>
	</table>
	<div class="bhec-log-content" hidden>
		<div class="bhec-preview-frame"><iframe id="bhec-log-frame" title="<?php esc_attr_e( 'Email Content', 'backhaus-email-campaigns' ); ?>" sandbox></iframe></div>
		<p class="bhec-hint"><?php esc_html_e( 'Links and the open-tracking image are off here, so looking at this email doesn\'t count as the customer opening or clicking it.', 'backhaus-email-campaigns' ); ?></p>
	</div>
	<p class="bhec-hint bhec-log-no-content" hidden>
		<?php
		if ( 'full' === $logging ) {
			esc_html_e( 'This email\'s content wasn\'t kept.', 'backhaus-email-campaigns' );
		} else {
			esc_html_e( 'The log keeps who, what and when, not the email itself. To keep the content too, set Email Log to Everything.', 'backhaus-email-campaigns' );
		}
		?>
	</p>
</dialog>
