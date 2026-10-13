<?php
/**
 * Subscribers: everyone the plugin knows and whether they get marketing email.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

// Included from inside a render method, so these variables are local to it.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$list_table = new BHEC_Subscribers_List_Table();
$list_table->prepare_items();

$export_url = add_query_arg(
	array_filter(
		array(
			'action' => 'bhec_export_csv',
			'type'   => 'subscribers',
			'view'   => $list_table->current_view(),
			's'      => rawurlencode( $list_table->current_search() ),
			'nonce'  => wp_create_nonce( 'bhec_export' ),
		)
	),
	admin_url( 'admin-ajax.php' )
);
?>
<div class="wrap bhec-wrap bhec-subscribers">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Subscribers', 'backhaus-email-campaigns' ); ?></h1>
	<button type="button" class="page-title-action bhec-open-import"><?php esc_html_e( 'Import Opt-Outs', 'backhaus-email-campaigns' ); ?></button>
	<?php if ( $list_table->has_people() ) : ?>
		<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action"><?php esc_html_e( 'Export', 'backhaus-email-campaigns' ); ?></a>
	<?php endif; ?>
	<hr class="wp-header-end">

	<?php BHEC_Admin_Customers::render_flash(); ?>

	<p class="bhec-hint bhec-mode-hint">
		<?php
		if ( BHEC_Admin_Customers::auto_mode() ) {
			esc_html_e( 'Automatic opt-in is on: customers get your marketing email unless they opt out.', 'backhaus-email-campaigns' );
		} else {
			esc_html_e( 'Customers only get your marketing email after they opt in.', 'backhaus-email-campaigns' );
		}
		?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=bhec-settings' ) ); ?>"><?php esc_html_e( 'Change in Settings', 'backhaus-email-campaigns' ); ?></a>
	</p>

	<form class="bhec-quick-unsub" id="bhec-quick-unsub" novalidate>
		<label class="bhec-label" for="bhec-quick-unsub-email"><?php esc_html_e( 'Unsubscribe Someone', 'backhaus-email-campaigns' ); ?></label>
		<div class="bhec-inline">
			<input type="email" id="bhec-quick-unsub-email" class="regular-text" autocomplete="off" spellcheck="false" aria-describedby="bhec-quick-unsub-hint">
			<button type="submit" class="button"><?php esc_html_e( 'Unsubscribe', 'backhaus-email-campaigns' ); ?></button>
		</div>
		<p class="bhec-hint" id="bhec-quick-unsub-hint"><?php esc_html_e( 'Type their email address. It works for anyone, including people who haven\'t ordered yet.', 'backhaus-email-campaigns' ); ?></p>
		<p class="bhec-import-error" id="bhec-quick-unsub-error" role="alert" hidden></p>
	</form>

	<?php $list_table->views(); ?>
	<form method="get" id="bhec-subscribers-form">
		<input type="hidden" name="page" value="bhec-customers">
		<?php if ( $list_table->current_view() ) : ?>
			<input type="hidden" name="view" value="<?php echo esc_attr( $list_table->current_view() ); ?>">
		<?php endif; ?>
		<?php
		$list_table->search_box( __( 'Search Subscribers', 'backhaus-email-campaigns' ), 'bhec-subscriber' );
		$list_table->display();
		?>
	</form>

	<dialog class="bhec-dialog" id="bhec-sub-dialog" aria-labelledby="bhec-sub-dialog-title">
		<form method="dialog">
			<h2 id="bhec-sub-dialog-title"></h2>
			<div class="bhec-dialog-body" id="bhec-sub-dialog-body"></div>
			<div class="bhec-dialog-actions">
				<button type="submit" class="button" value="cancel" id="bhec-sub-dialog-cancel"><?php esc_html_e( 'Cancel', 'backhaus-email-campaigns' ); ?></button>
				<button type="submit" class="button button-primary" value="confirm" id="bhec-sub-dialog-confirm"></button>
			</div>
		</form>
	</dialog>

	<dialog class="bhec-dialog bhec-history-dialog" id="bhec-history-dialog" aria-labelledby="bhec-history-title">
		<div class="bhec-history-head">
			<div>
				<h2 id="bhec-history-title"></h2>
				<p class="bhec-history-name" id="bhec-history-name"></p>
			</div>
			<form method="dialog"><button type="submit" class="button-link bhec-dialog-close" aria-label="<?php esc_attr_e( 'Close', 'backhaus-email-campaigns' ); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button></form>
		</div>
		<div class="bhec-history-body" id="bhec-history-body" aria-live="polite"></div>
	</dialog>

	<dialog class="bhec-dialog" id="bhec-import-dialog" aria-labelledby="bhec-import-title">
		<form id="bhec-import-form" enctype="multipart/form-data">
			<h2 id="bhec-import-title"><?php esc_html_e( 'Import Opt-Outs', 'backhaus-email-campaigns' ); ?></h2>
			<div class="bhec-dialog-body" id="bhec-import-intro">
				<p><?php esc_html_e( 'Mark a list of people as unsubscribed in one go, such as the unsubscribe list from the email tool you used before.', 'backhaus-email-campaigns' ); ?></p>
				<p class="bhec-label"><?php esc_html_e( 'What the File Should Contain', 'backhaus-email-campaigns' ); ?></p>
				<ul class="bhec-import-rules">
					<li><?php esc_html_e( 'A CSV or TXT file with email addresses, one per row.', 'backhaus-email-campaigns' ); ?></li>
					<li><?php esc_html_e( 'Other columns and a header row are fine: every cell with an email address counts, and the rest is ignored.', 'backhaus-email-campaigns' ); ?></li>
					<li><?php esc_html_e( 'People who aren\'t in your store yet are added as unsubscribed, so they won\'t be emailed if they order later.', 'backhaus-email-campaigns' ); ?></li>
				</ul>
				<div class="bhec-dialog-note"><p><?php esc_html_e( 'Everyone in the file stops getting marketing email. You can\'t undo this here: only they can opt back in.', 'backhaus-email-campaigns' ); ?></p></div>
				<p>
					<label class="bhec-label" for="bhec-import-file"><?php esc_html_e( 'File', 'backhaus-email-campaigns' ); ?></label>
					<input type="file" name="csv_file" id="bhec-import-file" accept=".csv,.txt,text/csv,text/plain">
				</p>
				<p class="bhec-import-error" id="bhec-import-error" role="alert" hidden></p>
			</div>
			<div class="bhec-dialog-body" id="bhec-import-result" hidden>
				<div class="notice notice-success inline"><p id="bhec-import-summary"></p></div>
			</div>
			<div class="bhec-dialog-actions">
				<span class="spinner" id="bhec-import-spinner"></span>
				<button type="button" class="button" id="bhec-import-cancel"><?php esc_html_e( 'Cancel', 'backhaus-email-campaigns' ); ?></button>
				<button type="submit" class="button button-primary" id="bhec-import-submit"><?php esc_html_e( 'Import', 'backhaus-email-campaigns' ); ?></button>
			</div>
		</form>
	</dialog>
</div>
