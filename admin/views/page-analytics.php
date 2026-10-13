<?php
/**
 * Reports: how the store's emails did over the chosen period.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

// Included from inside a render method, so these variables are local to it.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$period = BHEC_Admin_Analytics::period();
$range  = BHEC_Admin_Analytics::range( $period );
?>
<div class="wrap bhec-wrap bhec-reports">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Reports', 'backhaus-email-campaigns' ); ?></h1>
	<hr class="wp-header-end">

	<?php if ( $missing ) : ?>
		<div class="notice notice-warning"><p><?php esc_html_e( 'That campaign doesn\'t exist any more. Here are the reports for all your emails.', 'backhaus-email-campaigns' ); ?></p></div>
	<?php endif; ?>

	<?php if ( ! BHEC_Admin_Analytics::has_sent_anything() ) : ?>
		<div class="postbox bhec-reports-empty">
			<div class="inside">
				<div class="bhec-empty">
					<p class="bhec-empty-title"><?php esc_html_e( 'No emails sent yet', 'backhaus-email-campaigns' ); ?></p>
					<p><?php esc_html_e( 'Reports show how your emails do: how many people open them, click through and buy. Send your first campaign and its numbers show up here.', 'backhaus-email-campaigns' ); ?></p>
					<p><a class="button button-primary" href="<?php echo esc_url( BHEC_Admin_Campaigns::url( array( 'action' => 'new' ) ) ); ?>"><?php esc_html_e( 'Create a Campaign', 'backhaus-email-campaigns' ); ?></a></p>
				</div>
			</div>
		</div>
	</div>
		<?php
		return;
	endif;

	$automatic = BHEC_Admin_Analytics::automatic_stats( $range );
	$summary   = BHEC_Admin_Analytics::summary( $range, $automatic );
	$daily     = BHEC_Admin_Analytics::daily( $range );
	$active    = array_sum( $daily['sent'] ) + array_sum( $daily['opens'] ) + array_sum( $daily['clicks'] ) > 0;
	$sent      = $summary['sent'];

	$from      = wp_date( wp_date( 'Y', $range['time'] ) === wp_date( 'Y' ) ? 'j M' : 'j M Y', $range['time'] );
	$labels    = array(
		'sent'   => __( 'Emails Sent', 'backhaus-email-campaigns' ),
		'opens'  => __( 'Opens', 'backhaus-email-campaigns' ),
		'clicks' => __( 'Clicks', 'backhaus-email-campaigns' ),
	);
	?>

	<div class="bhec-report-filters">
		<div class="bhec-segmented" role="group" aria-label="<?php esc_attr_e( 'Period', 'backhaus-email-campaigns' ); ?>">
			<?php foreach ( BHEC_Admin_Analytics::PERIODS as $days ) : ?>
				<a class="button" href="<?php echo esc_url( BHEC_Admin_Analytics::url( array( 'period' => $days ) ) ); ?>"<?php echo $days === $period ? ' aria-current="page"' : ''; ?>>
					<?php
					/* translators: %s: number of days */
					echo esc_html( sprintf( __( 'Last %s Days', 'backhaus-email-campaigns' ), number_format_i18n( $days ) ) );
					?>
				</a>
			<?php endforeach; ?>
		</div>
		<span class="bhec-muted">
			<?php
			/* translators: 1: first day of the period, 2: today */
			echo esc_html( sprintf( __( '%1$s to %2$s', 'backhaus-email-campaigns' ), $from, wp_date( 'j M' ) ) );
			?>
		</span>
	</div>

	<div class="bhec-stats bhec-stats-5">
		<?php
		BHEC_Admin_Analytics::render_stat(
			__( 'Emails Sent', 'backhaus-email-campaigns' ),
			esc_html( number_format_i18n( $sent ) ),
			/* translators: %s: number of emails */
			$summary['automatic_sent'] > 0 ? sprintf( __( '%s from automatic emails', 'backhaus-email-campaigns' ), number_format_i18n( $summary['automatic_sent'] ) ) : ''
		);
		BHEC_Admin_Analytics::render_stat(
			__( 'Opened', 'backhaus-email-campaigns' ),
			esc_html( BHEC_Admin_Campaigns::percent( $summary['opened'], $sent ) ),
			$sent > 0 ? BHEC_Admin_Analytics::count_of( $summary['opened'], 'emails' ) : ''
		);
		BHEC_Admin_Analytics::render_stat(
			__( 'Clicked', 'backhaus-email-campaigns' ),
			esc_html( BHEC_Admin_Campaigns::percent( $summary['clicked'], $sent ) ),
			$sent > 0 ? BHEC_Admin_Analytics::count_of( $summary['clicked'], 'emails' ) : ''
		);
		BHEC_Admin_Analytics::render_stat(
			__( 'Revenue', 'backhaus-email-campaigns' ),
			wp_kses_post( wc_price( $summary['revenue'] ) ),
			BHEC_Admin_Analytics::count_of( $summary['orders'], 'orders' )
		);
		BHEC_Admin_Analytics::render_stat(
			__( 'Unsubscribed', 'backhaus-email-campaigns' ),
			esc_html( number_format_i18n( $summary['unsubscribed'] ) ),
			/* translators: %s: percentage */
			$sent > 0 && $summary['unsubscribed'] > 0 ? sprintf( __( '%s of emails sent', 'backhaus-email-campaigns' ), BHEC_Admin_Campaigns::percent( $summary['unsubscribed'], $sent ) ) : ''
		);
		?>
	</div>
	<?php BHEC_Admin_Analytics::render_stats_note(); ?>

	<div class="postbox bhec-report-activity">
		<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Activity', 'backhaus-email-campaigns' ); ?></h2></div>
		<div class="inside">
			<?php if ( ! $active ) : ?>
				<p class="bhec-report-quiet">
					<?php
					/* translators: %s: number of days */
					echo esc_html( sprintf( __( 'Nothing was sent, opened or clicked in the last %s days.', 'backhaus-email-campaigns' ), number_format_i18n( $period ) ) );
					?>
				</p>
			<?php else : ?>
				<div class="bhec-chart-pair">
					<?php
					BHEC_Admin_Analytics::render_chart(
						'columns',
						$labels['sent'],
						/* translators: %s: number of days */
						sprintf( __( 'Emails sent each day in the last %s days. The numbers are in the table below.', 'backhaus-email-campaigns' ), number_format_i18n( $period ) ),
						BHEC_Admin_Analytics::points( $range['days'], array( 'sent' => array( $labels['sent'], $daily['sent'] ) ) )
					);
					BHEC_Admin_Analytics::render_chart(
						'lines',
						__( 'Opens and Clicks', 'backhaus-email-campaigns' ),
						/* translators: %s: number of days */
						sprintf( __( 'Opens and clicks each day in the last %s days. The numbers are in the table below.', 'backhaus-email-campaigns' ), number_format_i18n( $period ) ),
						BHEC_Admin_Analytics::points(
							$range['days'],
							array(
								'opens'  => array( $labels['opens'], $daily['opens'] ),
								'clicks' => array( $labels['clicks'], $daily['clicks'] ),
							)
						)
					);
					?>
				</div>
				<?php
				BHEC_Admin_Analytics::render_chart_table(
					BHEC_Admin_Analytics::points(
						$range['days'],
						array(
							'sent'   => array( $labels['sent'], $daily['sent'] ),
							'opens'  => array( $labels['opens'], $daily['opens'] ),
							'clicks' => array( $labels['clicks'], $daily['clicks'] ),
						)
					),
					__( 'Day', 'backhaus-email-campaigns' ),
					true
				);
				?>
			<?php endif; ?>
		</div>
	</div>

	<?php
	$campaigns_table = new BHEC_Report_Campaigns_List_Table( $range['local'], $period );
	$campaigns_table->prepare_items();
	?>
	<h2 id="campaigns" class="bhec-section-heading"><?php esc_html_e( 'Campaigns', 'backhaus-email-campaigns' ); ?></h2>
	<p class="bhec-hint bhec-section-hint"><?php esc_html_e( 'Campaigns that sent in this period, with their results so far.', 'backhaus-email-campaigns' ); ?></p>
	<form method="get" action="#campaigns">
		<input type="hidden" name="page" value="bhec-analytics">
		<input type="hidden" name="period" value="<?php echo esc_attr( $period ); ?>">
		<?php $campaigns_table->display(); ?>
	</form>

	<?php if ( BHEC_Admin_Analytics::has_automatic() ) : ?>
		<?php
		$automatic_table = new BHEC_Report_Automatic_List_Table( $automatic );
		$automatic_table->prepare_items();
		?>
		<h2 class="bhec-section-heading"><?php esc_html_e( 'Automatic Emails', 'backhaus-email-campaigns' ); ?></h2>
		<p class="bhec-hint bhec-section-hint"><?php esc_html_e( 'What each automatic email sent in this period, and what came of it.', 'backhaus-email-campaigns' ); ?></p>
		<?php $automatic_table->display(); ?>
	<?php endif; ?>
</div>
