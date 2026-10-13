<?php
/**
 * A sent or sending campaign: how it went, what it said and who got it.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

// Included from inside a render method, so these variables are local to it.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

// $campaign and $who_filter come from BHEC_Admin_Campaigns::render_page().
$status   = BHEC_Admin_Campaigns::status( $campaign );
$progress = ( new BHEC_Queue() )->get_progress( $campaign->id );
$filters  = $campaign->filters ? json_decode( $campaign->filters, true ) : array();
$audience = BHEC_Admin_Campaigns::describe_audience( is_array( $filters ) ? $filters : array(), (int) $campaign->exclude_segment_id );
$sent     = (int) $progress['sent'];
$live     = in_array( $status, array( 'sending', 'paused' ), true );
$waiting  = (int) $progress['pending'] + (int) $progress['sending'];

$recipients = new BHEC_Campaign_Recipients_List_Table( $campaign, $progress );
$recipients->prepare_items();

$people = function ( $count ) use ( $sent ) {
	/* translators: %s: number of people */
	return $sent > 0 ? sprintf( _n( '%s person', '%s people', $count, 'backhaus-email-campaigns' ), number_format_i18n( $count ) ) : '';
};
$rate   = function ( $count ) use ( $sent ) {
	return $sent > 0 ? BHEC_Admin_Campaigns::percent( $count, $sent ) : '';
};
?>
<div class="wrap bhec-wrap bhec-view" data-campaign-id="<?php echo esc_attr( $campaign->id ); ?>" data-status="<?php echo esc_attr( $status ); ?>">
	<a class="bhec-back" href="<?php echo esc_url( BHEC_Admin_Campaigns::url() ); ?>"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span><?php esc_html_e( 'All Campaigns', 'backhaus-email-campaigns' ); ?></a>
	<h1 class="wp-heading-inline"><?php echo esc_html( '' !== $campaign->title ? $campaign->title : __( '(No Name)', 'backhaus-email-campaigns' ) ); ?></h1>
	<button type="button" class="page-title-action bhec-duplicate-campaign" data-id="<?php echo esc_attr( $campaign->id ); ?>"><?php esc_html_e( 'Duplicate', 'backhaus-email-campaigns' ); ?></button>
	<hr class="wp-header-end">

	<?php BHEC_Admin_Campaigns::render_flash(); ?>

	<div class="bhec-view-status">
		<?php echo wp_kses( BHEC_Admin_Campaigns::status_badge( $status ), BHEC_Admin::allowed_html() ); ?>
		<span class="bhec-view-when" id="bhec-view-detail">
			<?php
			if ( 'sent' === $status && $campaign->completed_at ) {
				/* translators: %s: date and time */
				echo esc_html( sprintf( __( 'Finished %s', 'backhaus-email-campaigns' ), BHEC_Admin_Campaigns::format_local( $campaign->completed_at ) ) );
			} elseif ( ! $live ) {
				echo esc_html( BHEC_Admin_Campaigns::status_detail( $campaign, $status ) );
			}
			if ( $campaign->started_at ) {
				/* translators: %s: date and time */
				echo ' <span class="bhec-muted">' . esc_html( sprintf( __( 'Started %s', 'backhaus-email-campaigns' ), BHEC_Admin_Campaigns::format_local( $campaign->started_at ) ) ) . '</span>';
			} elseif ( 'queued' === $campaign->status ) {
				echo ' <span class="bhec-muted">' . esc_html__( 'Starting in a minute', 'backhaus-email-campaigns' ) . '</span>';
			}
			?>
		</span>
	</div>

	<?php if ( $live ) : ?>
		<div class="bhec-live postbox">
			<div class="inside">
				<div class="bhec-live-numbers">
					<?php
					printf(
						/* translators: 1: emails sent, 2: recipients */
						esc_html__( '%1$s of %2$s sent', 'backhaus-email-campaigns' ),
						'<strong id="bhec-live-sent">' . esc_html( number_format_i18n( $sent ) ) . '</strong>',
						'<span id="bhec-live-total">' . esc_html( number_format_i18n( (int) $progress['total'] ) ) . '</span>'
					);
					?>
				</div>
				<div id="bhec-live-bar"><?php echo wp_kses( BHEC_Admin_Campaigns::progress_bar( $sent + (int) $progress['failed'] + (int) $progress['skipped'], (int) $progress['total'] ), BHEC_Admin::allowed_html() ); ?></div>
				<p class="bhec-hint">
					<?php
					if ( 'paused' === $status ) {
						esc_html_e( 'Paused. Nothing more goes out until you resume.', 'backhaus-email-campaigns' );
					} else {
						esc_html_e( 'Sending carries on in the background, even with this page closed. Numbers here update every few seconds.', 'backhaus-email-campaigns' );
					}
					?>
				</p>
				<div class="bhec-live-actions">
					<?php if ( 'paused' === $status ) : ?>
						<button type="button" class="button button-primary bhec-campaign-action" data-action="bhec_resume_campaign"><?php esc_html_e( 'Resume Sending', 'backhaus-email-campaigns' ); ?></button>
					<?php else : ?>
						<button type="button" class="button" id="bhec-send-faster" aria-pressed="false" aria-describedby="bhec-faster-hint"
							data-on="<?php esc_attr_e( 'Stop Sending Faster', 'backhaus-email-campaigns' ); ?>"
							data-off="<?php esc_attr_e( 'Send Faster', 'backhaus-email-campaigns' ); ?>"><?php esc_html_e( 'Send Faster', 'backhaus-email-campaigns' ); ?></button>
						<button type="button" class="button bhec-campaign-action" data-action="bhec_pause_campaign"><?php esc_html_e( 'Pause', 'backhaus-email-campaigns' ); ?></button>
					<?php endif; ?>
					<button type="button" class="button-link bhec-danger bhec-campaign-action" data-action="bhec_stop_campaign"
						data-confirm="<?php echo esc_attr( sprintf( /* translators: %s: number of emails */ __( 'Stop this campaign? The %s emails still waiting will not be sent, and you can\'t restart it. Duplicate it if you want to send it again later.', 'backhaus-email-campaigns' ), number_format_i18n( $waiting + (int) $progress['held'] ) ) ); ?>">
						<?php esc_html_e( 'Stop Sending', 'backhaus-email-campaigns' ); ?>
					</button>
				</div>
				<?php if ( 'sending' === $status ) : ?>
					<p class="bhec-hint" id="bhec-faster-hint" aria-live="polite"><?php esc_html_e( 'Send Faster keeps sending without the minute breaks while this page stays open. Your speed limits still apply.', 'backhaus-email-campaigns' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
	<?php endif; ?>

	<div class="bhec-stats">
		<div class="bhec-stat">
			<span class="bhec-stat-label"><?php esc_html_e( 'Recipients', 'backhaus-email-campaigns' ); ?></span>
			<span class="bhec-stat-value"><?php echo esc_html( number_format_i18n( (int) $progress['total'] ) ); ?></span>
		</div>
		<div class="bhec-stat">
			<span class="bhec-stat-label"><?php esc_html_e( 'Sent', 'backhaus-email-campaigns' ); ?></span>
			<span class="bhec-stat-value" id="bhec-stat-sent"><?php echo esc_html( number_format_i18n( $sent ) ); ?></span>
		</div>
		<div class="bhec-stat">
			<span class="bhec-stat-label"><?php esc_html_e( 'Opened', 'backhaus-email-campaigns' ); ?></span>
			<span class="bhec-stat-value"><?php echo esc_html( $rate( (int) $campaign->opened_count ) ); ?></span>
			<span class="bhec-stat-sub"><?php echo esc_html( $people( (int) $campaign->opened_count ) ); ?></span>
		</div>
		<div class="bhec-stat">
			<span class="bhec-stat-label"><?php esc_html_e( 'Clicked', 'backhaus-email-campaigns' ); ?></span>
			<span class="bhec-stat-value"><?php echo esc_html( $rate( (int) $campaign->clicked_count ) ); ?></span>
			<span class="bhec-stat-sub"><?php echo esc_html( $people( (int) $campaign->clicked_count ) ); ?></span>
		</div>
	</div>
	<p class="bhec-hint bhec-stats-note">
		<?php esc_html_e( 'Opens are a rough guide: some mail apps load images for privacy, others block them.', 'backhaus-email-campaigns' ); ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=bhec-analytics&campaign_id=' . (int) $campaign->id ) ); ?>"><?php esc_html_e( 'Full Report', 'backhaus-email-campaigns' ); ?></a>
	</p>

	<?php if ( (int) $progress['held'] > 0 ) : ?>
		<div class="notice notice-warning inline bhec-callout">
			<p>
				<strong>
					<?php
					/* translators: %s: number of emails */
					echo esc_html( sprintf( _n( '%s email held back', '%s emails held back', (int) $progress['held'], 'backhaus-email-campaigns' ), number_format_i18n( (int) $progress['held'] ) ) );
					?>
				</strong>
				<?php esc_html_e( 'These people got another email recently, so the over-mailing guard held this campaign back for them. The Held tab below shows who and why.', 'backhaus-email-campaigns' ); ?>
			</p>
			<p>
				<button type="button" class="button bhec-resolve-held" data-id="<?php echo esc_attr( $campaign->id ); ?>" data-decision="send"><?php esc_html_e( 'Send Them Anyway', 'backhaus-email-campaigns' ); ?></button>
				<button type="button" class="button-link bhec-resolve-held" data-id="<?php echo esc_attr( $campaign->id ); ?>" data-decision="discard"><?php esc_html_e( 'Don\'t Send', 'backhaus-email-campaigns' ); ?></button>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( (int) $progress['failed'] > 0 ) : ?>
		<div class="notice notice-error inline bhec-callout">
			<p>
				<strong>
					<?php
					/* translators: %s: number of emails */
					echo esc_html( sprintf( _n( '%s email failed', '%s emails failed', (int) $progress['failed'], 'backhaus-email-campaigns' ), number_format_i18n( (int) $progress['failed'] ) ) );
					?>
				</strong>
				<?php esc_html_e( 'Your mail provider turned them down. The Failed tab below shows the reason for each.', 'backhaus-email-campaigns' ); ?>
			</p>
			<p><button type="button" class="button bhec-campaign-action" data-action="bhec_retry_failed"><?php esc_html_e( 'Try Them Again', 'backhaus-email-campaigns' ); ?></button></p>
		</div>
	<?php endif; ?>

	<div class="bhec-view-grid">
		<div class="postbox bhec-view-email">
			<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Email', 'backhaus-email-campaigns' ); ?></h2></div>
			<div class="inside">
				<p class="bhec-view-subject"><span class="bhec-muted"><?php esc_html_e( 'Subject', 'backhaus-email-campaigns' ); ?></span> <?php echo esc_html( $campaign->subject ); ?></p>
				<?php  ?>
				<?php $preview = BHEC_Loader::render_preview( $campaign->subject, $campaign->body, $campaign->layout_template ? $campaign->layout_template : 'default' ); ?>
				<div class="bhec-preview-frame bhec-preview-inline"><iframe title="<?php esc_attr_e( 'Email Preview', 'backhaus-email-campaigns' ); ?>" sandbox="allow-popups allow-popups-to-escape-sandbox" srcdoc="<?php echo esc_attr( $preview['html'] ); ?>"></iframe></div>
				<p class="bhec-hint"><?php esc_html_e( 'Shown with a recent customer\'s details.', 'backhaus-email-campaigns' ); ?></p>
			</div>
		</div>
		<div class="postbox bhec-view-details">
			<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Sent To', 'backhaus-email-campaigns' ); ?></h2></div>
			<div class="inside">
				<ul class="bhec-audience-lines">
					<?php foreach ( $audience as $line ) : ?>
						<li><?php echo esc_html( $line ); ?></li>
					<?php endforeach; ?>
				</ul>
				<?php if ( $campaign->reply_to_email ) : ?>
					<p><span class="bhec-muted"><?php esc_html_e( 'Replies go to', 'backhaus-email-campaigns' ); ?></span> <?php echo esc_html( $campaign->reply_to_email ); ?></p>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<h2 id="recipients" class="bhec-section-heading"><?php esc_html_e( 'Recipients', 'backhaus-email-campaigns' ); ?></h2>
	<?php $recipients->views(); ?>
	<form method="get" action="#recipients">
		<input type="hidden" name="page" value="bhec-campaigns">
		<input type="hidden" name="action" value="view">
		<input type="hidden" name="id" value="<?php echo esc_attr( $campaign->id ); ?>">
		<?php
		if ( '' !== $who_filter ) {
			echo '<input type="hidden" name="who" value="' . esc_attr( $who_filter ) . '">';
		}
		$recipients->search_box( __( 'Search Recipients', 'backhaus-email-campaigns' ), 'bhec-recipient' );
		$recipients->display();
		?>
	</form>
</div>
