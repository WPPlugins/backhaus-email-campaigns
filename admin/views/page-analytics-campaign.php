<?php
/**
 * One campaign's full report: its numbers, opens and clicks over time, the
 * links people clicked most, and the lists to download.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

// Included from inside a render method, so these variables are local to it.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$status    = BHEC_Admin_Campaigns::status( $campaign );
$page_url  = BHEC_Admin_Campaigns::url( array( 'action' => 'view', 'id' => $campaign->id ) );
$data      = BHEC_Tracker::get_campaign_analytics( $campaign->id );
$sent      = (int) $data['total_sent'];
$has_sent  = $sent > 0 || in_array( $status, array( 'sending', 'paused', 'sent' ), true );
$export    = function ( $type ) use ( $campaign ) {
	return add_query_arg(
		array(
			'action'      => 'bhec_export_csv',
			'campaign_id' => (int) $campaign->id,
			'type'        => $type,
			'nonce'       => wp_create_nonce( 'bhec_export' ),
		),
		admin_url( 'admin-ajax.php' )
	);
};
?>
<div class="wrap bhec-wrap bhec-reports bhec-report-campaign">
	<a class="bhec-back" href="<?php echo esc_url( $page_url ); ?>"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span><?php esc_html_e( 'Back to Campaign', 'backhaus-email-campaigns' ); ?></a>
	<h1 class="wp-heading-inline"><?php echo esc_html( '' !== $campaign->title ? $campaign->title : __( '(No Name)', 'backhaus-email-campaigns' ) ); ?></h1>
	<hr class="wp-header-end">

	<p class="bhec-report-status">
		<?php echo wp_kses( BHEC_Admin_Campaigns::status_badge( $status ), BHEC_Admin::allowed_html() ); ?>
		<span>
			<?php
			if ( $campaign->started_at ) {
				/* translators: %s: date and time */
				echo esc_html( sprintf( __( 'Started %s', 'backhaus-email-campaigns' ), BHEC_Admin_Campaigns::format_local( $campaign->started_at ) ) );
			} else {
				echo esc_html( BHEC_Admin_Campaigns::status_detail( $campaign, $status ) );
			}
			?>
		</span>
		<?php if ( '' !== $campaign->subject ) : ?>
			<span class="bhec-muted">
				<?php
				/* translators: %s: email subject */
				echo esc_html( sprintf( __( 'Subject: %s', 'backhaus-email-campaigns' ), $campaign->subject ) );
				?>
			</span>
		<?php endif; ?>
	</p>

	<?php if ( ! $has_sent ) : ?>
		<div class="postbox">
			<div class="inside">
				<div class="bhec-empty">
					<p class="bhec-empty-title"><?php esc_html_e( 'Nothing to report yet', 'backhaus-email-campaigns' ); ?></p>
					<p><?php esc_html_e( 'This campaign hasn\'t sent any emails. Once it goes out, you\'ll see who opened it, what they clicked and what they bought.', 'backhaus-email-campaigns' ); ?></p>
					<p><a class="button button-primary" href="<?php echo esc_url( $page_url ); ?>"><?php esc_html_e( 'Open the Campaign', 'backhaus-email-campaigns' ); ?></a></p>
				</div>
			</div>
		</div>
	</div>
		<?php
		return;
	endif;

	$daily  = BHEC_Admin_Analytics::campaign_daily( $campaign );
	$chart  = BHEC_Admin_Analytics::points(
		$daily['dates'],
		array(
			'opens'  => array( __( 'Opens', 'backhaus-email-campaigns' ), $daily['opens'] ),
			'clicks' => array( __( 'Clicks', 'backhaus-email-campaigns' ), $daily['clicks'] ),
		)
	);
	$links  = array_filter(
		(array) $data['top_links'],
		function ( $link ) {
			return (int) $link['click_count'] > 0;
		}
	);
	$clicks = (int) $data['total_clicks'];
	// Link counters and click events are counted separately; never show more than 100%.
	$link_clicks = max( $clicks, array_sum( array_map( 'intval', wp_list_pluck( $links, 'click_count' ) ) ) );
	?>

	<div class="bhec-stats">
		<?php
		BHEC_Admin_Analytics::render_stat(
			__( 'Sent', 'backhaus-email-campaigns' ),
			esc_html( number_format_i18n( $sent ) ),
			/* translators: %s: number of emails */
			(int) $data['total_failed'] > 0 ? sprintf( _n( '%s failed', '%s failed', (int) $data['total_failed'], 'backhaus-email-campaigns' ), number_format_i18n( (int) $data['total_failed'] ) ) : ''
		);
		BHEC_Admin_Analytics::render_stat(
			__( 'Opened', 'backhaus-email-campaigns' ),
			esc_html( BHEC_Admin_Campaigns::percent( (int) $data['unique_opens'], $sent ) ),
			BHEC_Admin_Analytics::count_of( $data['unique_opens'], 'people' )
		);
		BHEC_Admin_Analytics::render_stat(
			__( 'Clicked', 'backhaus-email-campaigns' ),
			esc_html( BHEC_Admin_Campaigns::percent( (int) $data['unique_clicks'], $sent ) ),
			BHEC_Admin_Analytics::count_of( $data['unique_clicks'], 'people' )
		);
		BHEC_Admin_Analytics::render_stat(
			__( 'Revenue', 'backhaus-email-campaigns' ),
			wp_kses_post( wc_price( (float) $data['revenue'] ) ),
			BHEC_Admin_Analytics::count_of( $data['conversions'], 'orders' )
		);
		?>
	</div>
	<?php BHEC_Admin_Analytics::render_stats_note(); ?>

	<div class="postbox bhec-report-activity">
		<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Opens and Clicks Over Time', 'backhaus-email-campaigns' ); ?></h2></div>
		<div class="inside">
			<?php if ( ! $data['total_opens'] && ! $clicks ) : ?>
				<p class="bhec-report-quiet"><?php esc_html_e( 'Nobody has opened this campaign or clicked a link in it yet.', 'backhaus-email-campaigns' ); ?></p>
			<?php else : ?>
				<?php
				BHEC_Admin_Analytics::render_chart(
					'lines',
					'',
					__( 'Opens and clicks for this campaign each day since it went out. The numbers are in the table below.', 'backhaus-email-campaigns' ),
					$chart
				);
				BHEC_Admin_Analytics::render_chart_table( $chart, __( 'Day', 'backhaus-email-campaigns' ), true );
				?>
			<?php endif; ?>
		</div>
	</div>

	<div class="postbox bhec-report-links">
		<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Most Clicked Links', 'backhaus-email-campaigns' ); ?></h2></div>
		<?php if ( ! $links ) : ?>
			<div class="inside">
				<p class="bhec-report-quiet"><?php esc_html_e( 'Nobody has clicked a link in this campaign yet.', 'backhaus-email-campaigns' ); ?></p>
			</div>
		<?php else : ?>
			<table class="widefat striped bhec-links-table">
				<thead>
					<tr>
						<th scope="col" class="column-link"><?php esc_html_e( 'Link', 'backhaus-email-campaigns' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Clicks', 'backhaus-email-campaigns' ); ?></th>
						<th scope="col" class="num column-share"><?php esc_html_e( 'Share of Clicks', 'backhaus-email-campaigns' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $links as $link ) : ?>
						<?php $url = html_entity_decode( $link['original_url'], ENT_QUOTES, 'UTF-8' ); ?>
						<tr>
							<td class="column-link"><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr( $url ); ?>"><?php echo esc_html( BHEC_Admin_Analytics::short_url( $url ) ); ?></a></td>
							<td class="num"><?php echo esc_html( number_format_i18n( (int) $link['click_count'] ) ); ?></td>
							<td class="num column-share"><?php echo esc_html( BHEC_Admin_Campaigns::percent( (int) $link['click_count'], $link_clicks ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<div class="postbox bhec-report-downloads">
		<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Download Lists', 'backhaus-email-campaigns' ); ?></h2></div>
		<div class="inside">
			<p class="bhec-hint"><?php esc_html_e( 'Spreadsheet files (CSV) with each person\'s email address and name.', 'backhaus-email-campaigns' ); ?></p>
			<p class="bhec-inline">
				<a class="button" href="<?php echo esc_url( $export( 'sent' ) ); ?>"><?php esc_html_e( 'Who Got It', 'backhaus-email-campaigns' ); ?></a>
				<?php if ( (int) $data['unique_opens'] > 0 ) : ?>
					<a class="button" href="<?php echo esc_url( $export( 'opened' ) ); ?>"><?php esc_html_e( 'Who Opened It', 'backhaus-email-campaigns' ); ?></a>
				<?php endif; ?>
				<?php if ( (int) $data['total_failed'] > 0 ) : ?>
					<a class="button" href="<?php echo esc_url( $export( 'failed' ) ); ?>"><?php esc_html_e( 'Failed Emails', 'backhaus-email-campaigns' ); ?></a>
				<?php endif; ?>
			</p>
		</div>
	</div>
</div>
