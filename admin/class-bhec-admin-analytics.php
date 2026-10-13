<?php
/**
 * Reports: how the store's emails did over a period, and a full report for
 * one campaign.
 *
 * Sent and opt-out times are stored in store time; tracking events use the
 * database clock (BHEC_Clock), so each query compares against the matching boundary.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Admin_Analytics {

	/** Periods on offer, in days. */
	const PERIODS = array( 7, 30, 90 );

	const DEFAULT_PERIOD = 30;

	/** A chart spanning more days than this shows weeks instead. */
	const MAX_DAYS = 92;

	/**
	 * Render the Reports page, or one campaign's report.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'backhaus-email-campaigns' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification
		$campaign_id = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0;
		$campaign    = $campaign_id ? BHEC_Campaign::get( $campaign_id ) : null;
		if ( $campaign ) {
			include BHEC_PLUGIN_DIR . 'admin/views/page-analytics-campaign.php';
			return;
		}

		$missing = $campaign_id > 0;
		require_once BHEC_PLUGIN_DIR . 'admin/class-bhec-report-campaigns-list-table.php';
		if ( self::has_automatic() ) {
			require_once BHEC_PLUGIN_DIR . 'admin/class-bhec-report-automatic-list-table.php';
		}
		include BHEC_PLUGIN_DIR . 'admin/views/page-analytics.php';
	}

	/**
	 * Link to the Reports screen.
	 *
	 * @param array $args Query args, such as period or campaign_id.
	 * @return string
	 */
	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'bhec-analytics' ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Automatic emails are Pro only.
	 *
	 * @return bool
	 */
	public static function has_automatic() {
		return class_exists( 'BHEC_Triggered_Emails' );
	}

	/**
	 * The chosen period in days.
	 *
	 * @return int
	 */
	public static function period() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$days = isset( $_GET['period'] ) ? absint( $_GET['period'] ) : self::DEFAULT_PERIOD;
		return in_array( $days, self::PERIODS, true ) ? $days : self::DEFAULT_PERIOD;
	}

	/**
	 * A period's first moment in store time and on the database clock, and its days.
	 * "Last 7 days" is today and the six days before it.
	 *
	 * @param int $days Period length.
	 * @return array local, utc (database clock), time (timestamp) and days (Y-m-d list).
	 */
	public static function range( $days ) {
		$start = ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( '-' . ( $days - 1 ) . ' days' );
		$list  = array();
		for ( $i = 0; $i < $days; $i++ ) {
			$list[] = $start->modify( '+' . $i . ' days' )->format( 'Y-m-d' );
		}
		return array(
			'local' => $start->format( 'Y-m-d H:i:s' ),
			'utc'   => BHEC_Clock::db( $start->getTimestamp() ),
			'time'  => $start->getTimestamp(),
			'days'  => $list,
		);
	}

	/**
	 * Seconds to add to a database-clock time to get store time (today's offset).
	 *
	 * @return int
	 */
	private static function utc_offset() {
		return (int) wp_timezone()->getOffset( new DateTime( 'now', new DateTimeZone( 'UTC' ) ) ) - BHEC_Clock::db_offset();
	}

	/**
	 * Whether the store has ever sent a campaign or automatic email.
	 *
	 * @return bool
	 */
	public static function has_sent_anything() {
		global $wpdb;
		if ( $wpdb->get_var( "SELECT 1 FROM {$wpdb->prefix}bhec_queue WHERE status = 'sent' LIMIT 1" ) ) {
			return true;
		}
		if ( $wpdb->get_var( "SELECT 1 FROM {$wpdb->prefix}bhec_campaigns WHERE sent_count > 0 LIMIT 1" ) ) {
			return true;
		}
		return self::has_automatic() && (bool) $wpdb->get_var( "SELECT 1 FROM {$wpdb->prefix}bhec_triggered_queue WHERE status = 'sent' LIMIT 1" );
	}

	/**
	 * The period's headline numbers. Opens and clicks count the emails sent in
	 * the period that were opened or clicked; orders count what was bought in
	 * the period after a click (the attribution the tracker records).
	 *
	 * @param array $range     From range().
	 * @param array $automatic From automatic_stats().
	 * @return array
	 */
	public static function summary( $range, $automatic = array() ) {
		global $wpdb;
		$queue  = $wpdb->prefix . 'bhec_queue';
		$events = $wpdb->prefix . 'bhec_tracking_events';

		$out = array(
			'campaign_sent'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $queue WHERE status = 'sent' AND sent_at >= %s", $range['local'] ) ), // phpcs:ignore WordPress.DB.PreparedSQL
			'automatic_sent' => 0,
		);

		// An email sent in the period can only be opened after it went out, so
		// the event-time bound only narrows the scan.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT CASE WHEN e.event_type = 'open' THEN e.queue_id END) AS opened,
					COUNT(DISTINCT CASE WHEN e.event_type = 'click' THEN e.queue_id END) AS clicked
				FROM $events e
				INNER JOIN $queue q ON q.id = e.queue_id AND q.campaign_id = e.campaign_id
				WHERE e.event_type IN ('open', 'click') AND e.created_at >= %s
					AND q.status = 'sent' AND q.sent_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$range['utc'],
				$range['local']
			)
		);
		$out['opened']  = (int) $row->opened;
		$out['clicked'] = (int) $row->clicked;

		foreach ( $automatic as $stats ) {
			$out['automatic_sent'] += $stats['sent'];
			$out['opened']         += $stats['opened'];
			$out['clicked']        += $stats['clicked'];
		}
		$out['sent'] = $out['campaign_sent'] + $out['automatic_sent'];

		$conversions    = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS orders, COALESCE(SUM(CAST(url AS DECIMAL(10,2))), 0) AS revenue
				FROM $events WHERE event_type = 'conversion' AND created_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$range['utc']
			)
		);
		$out['orders']  = (int) $conversions->orders;
		$out['revenue'] = (float) $conversions->revenue;

		// Imported opt-out lists aren't people unsubscribing.
		$out['unsubscribed'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bhec_subscriptions
				WHERE status = 'unsubscribed' AND opted_out_at >= %s AND ( source IS NULL OR source <> 'import' )",
				$range['local']
			)
		);

		return $out;
	}

	/**
	 * Each automatic email's numbers for the period, by rule id. Automatic
	 * emails are tracked under the negative rule id (see BHEC_Tracker).
	 *
	 * @param array $range From range().
	 * @return array rule_id => sent, opened, clicked, orders, revenue.
	 */
	public static function automatic_stats( $range ) {
		global $wpdb;
		if ( ! self::has_automatic() ) {
			return array();
		}
		$queue  = $wpdb->prefix . 'bhec_triggered_queue';
		$events = $wpdb->prefix . 'bhec_tracking_events';
		$stats  = array();
		$blank  = array(
			'sent'    => 0,
			'opened'  => 0,
			'clicked' => 0,
			'orders'  => 0,
			'revenue' => 0.0,
		);

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT rule_id, COUNT(*) AS n FROM $queue WHERE status = 'sent' AND sent_at >= %s GROUP BY rule_id", $range['local'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( $rows as $row ) {
			$stats[ (int) $row->rule_id ]         = $blank;
			$stats[ (int) $row->rule_id ]['sent'] = (int) $row->n;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.rule_id,
					COUNT(DISTINCT CASE WHEN e.event_type = 'open' THEN e.queue_id END) AS opened,
					COUNT(DISTINCT CASE WHEN e.event_type = 'click' THEN e.queue_id END) AS clicked
				FROM $events e
				INNER JOIN $queue t ON t.id = e.queue_id AND CAST(e.campaign_id AS SIGNED) = -CAST(t.rule_id AS SIGNED)
				WHERE e.event_type IN ('open', 'click') AND e.created_at >= %s
					AND t.status = 'sent' AND t.sent_at >= %s
				GROUP BY t.rule_id", // phpcs:ignore WordPress.DB.PreparedSQL
				$range['utc'],
				$range['local']
			)
		);
		foreach ( $rows as $row ) {
			$id                      = (int) $row->rule_id;
			$stats[ $id ]            = $stats[ $id ] ?? $blank;
			$stats[ $id ]['opened']  = (int) $row->opened;
			$stats[ $id ]['clicked'] = (int) $row->clicked;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT -CAST(campaign_id AS SIGNED) AS rule_id, COUNT(*) AS orders, SUM(CAST(url AS DECIMAL(10,2))) AS revenue
				FROM $events
				WHERE event_type = 'conversion' AND created_at >= %s AND CAST(campaign_id AS SIGNED) < 0
				GROUP BY campaign_id", // phpcs:ignore WordPress.DB.PreparedSQL
				$range['utc']
			)
		);
		foreach ( $rows as $row ) {
			$id                      = (int) $row->rule_id;
			$stats[ $id ]            = $stats[ $id ] ?? $blank;
			$stats[ $id ]['orders']  = (int) $row->orders;
			$stats[ $id ]['revenue'] = (float) $row->revenue;
		}

		return $stats;
	}

	/**
	 * Emails sent, opens and clicks on each day of the period.
	 *
	 * @param array $range From range().
	 * @return array sent, opens, clicks: each Y-m-d => count.
	 */
	public static function daily( $range ) {
		global $wpdb;
		$zero   = array_fill_keys( $range['days'], 0 );
		$sent   = $zero;
		$opens  = $zero;
		$clicks = $zero;

		$tables = array( $wpdb->prefix . 'bhec_queue' );
		if ( self::has_automatic() ) {
			$tables[] = $wpdb->prefix . 'bhec_triggered_queue';
		}
		foreach ( $tables as $table ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(sent_at) AS d, COUNT(*) AS n FROM $table WHERE status = 'sent' AND sent_at >= %s GROUP BY d", $range['local'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( $rows as $row ) {
				if ( isset( $sent[ $row->d ] ) ) {
					$sent[ $row->d ] += (int) $row->n;
				}
			}
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(DATE_ADD(created_at, INTERVAL %d SECOND)) AS d, SUM(event_type = 'open') AS opens, SUM(event_type = 'click') AS clicks
				FROM {$wpdb->prefix}bhec_tracking_events
				WHERE event_type IN ('open', 'click') AND created_at >= %s
				GROUP BY d",
				self::utc_offset(),
				$range['utc']
			)
		);
		foreach ( $rows as $row ) {
			if ( isset( $opens[ $row->d ] ) ) {
				$opens[ $row->d ]  = (int) $row->opens;
				$clicks[ $row->d ] = (int) $row->clicks;
			}
		}

		return array(
			'sent'   => $sent,
			'opens'  => $opens,
			'clicks' => $clicks,
		);
	}

	/**
	 * One campaign's opens and clicks per day, from the day it started sending
	 * to its last open or click (at least a week, so a new campaign has room).
	 *
	 * @param object $campaign Campaign row.
	 * @return array dates (Y-m-d list), opens, clicks (Y-m-d => count).
	 */
	public static function campaign_daily( $campaign ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(DATE_ADD(created_at, INTERVAL %d SECOND)) AS d, SUM(event_type = 'open') AS opens, SUM(event_type = 'click') AS clicks
				FROM {$wpdb->prefix}bhec_tracking_events
				WHERE campaign_id = %d AND event_type IN ('open', 'click')
				GROUP BY d ORDER BY d",
				self::utc_offset(),
				$campaign->id
			)
		);

		$first = $campaign->started_at ? substr( $campaign->started_at, 0, 10 ) : ( $rows ? $rows[0]->d : wp_date( 'Y-m-d' ) );
		if ( $rows && $rows[0]->d < $first ) {
			$first = $rows[0]->d;
		}
		$start = new DateTimeImmutable( $first, wp_timezone() );
		$last  = $rows ? end( $rows )->d : $first;
		$end   = max( $start->modify( '+6 days' )->format( 'Y-m-d' ), $last );

		$dates = array();
		for ( $day = $start; $day->format( 'Y-m-d' ) <= $end; $day = $day->modify( '+1 day' ) ) {
			$dates[] = $day->format( 'Y-m-d' );
		}

		$opens  = array_fill_keys( $dates, 0 );
		$clicks = $opens;
		foreach ( $rows as $row ) {
			$opens[ $row->d ]  = (int) $row->opens;
			$clicks[ $row->d ] = (int) $row->clicks;
		}

		return array(
			'dates'  => $dates,
			'opens'  => $opens,
			'clicks' => $clicks,
		);
	}

	/**
	 * Turn per-day counts into chart points: days, or weeks for long spans.
	 * Days after today have no value, so lines stop at today.
	 *
	 * @param string[] $dates  Y-m-d list.
	 * @param array    $series key => [ label, [ Y-m-d => count ] ].
	 * @return array points (short and long labels) and series (key, label, values).
	 */
	public static function points( $dates, $series ) {
		$weekly = count( $dates ) > self::MAX_DAYS;
		$step   = $weekly ? 7 : 1;
		$today  = wp_date( 'Y-m-d' );
		$points = array();
		$values = array_fill_keys( array_keys( $series ), array() );

		for ( $i = 0, $n = count( $dates ); $i < $n; $i += $step ) {
			$chunk = array_slice( $dates, $i, $step );
			$time  = ( new DateTimeImmutable( $chunk[0] . ' 12:00:00', wp_timezone() ) )->getTimestamp();
			$long  = wp_date( get_option( 'date_format' ), $time );

			$points[] = array(
				'short' => wp_date( 'j M', $time ),
				/* translators: %s: date the week starts */
				'long'  => $weekly ? sprintf( __( 'Week of %s', 'backhaus-email-campaigns' ), $long ) : $long,
			);
			foreach ( $series as $key => $def ) {
				if ( $chunk[0] > $today ) {
					$values[ $key ][] = null;
					continue;
				}
				$sum = 0;
				foreach ( $chunk as $date ) {
					$sum += (int) ( $def[1][ $date ] ?? 0 );
				}
				$values[ $key ][] = $sum;
			}
		}

		$out = array();
		foreach ( $series as $key => $def ) {
			$out[] = array(
				'key'    => $key,
				'label'  => $def[0],
				'values' => $values[ $key ],
			);
		}
		return array(
			'points' => $points,
			'series' => $out,
		);
	}

	/**
	 * A chart: a caption, a legend for two or more series, and a box that
	 * bhec-reports.js draws the SVG into. render_chart_table() has the numbers.
	 *
	 * @param string $type        columns or lines.
	 * @param string $title       Caption, or '' when the panel heading says it.
	 * @param string $description What the chart shows, for screen readers.
	 * @param array  $chart       From points().
	 */
	public static function render_chart( $type, $title, $description, $chart ) {
		$data = array(
			'type'   => $type,
			'labels' => wp_list_pluck( $chart['points'], 'short' ),
			'titles' => wp_list_pluck( $chart['points'], 'long' ),
			'series' => $chart['series'],
		);
		?>
		<figure class="bhec-chart-figure">
			<?php if ( '' !== $title ) : ?>
				<figcaption class="bhec-chart-title"><?php echo esc_html( $title ); ?></figcaption>
			<?php endif; ?>
			<?php if ( count( $chart['series'] ) > 1 ) : ?>
				<ul class="bhec-chart-legend">
					<?php foreach ( $chart['series'] as $series ) : ?>
						<li><span class="bhec-chart-key bhec-series-<?php echo esc_attr( $series['key'] ); ?>" aria-hidden="true"></span><?php echo esc_html( $series['label'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<div class="bhec-chart bhec-chart-<?php echo esc_attr( $type ); ?>" tabindex="0" role="img" aria-label="<?php echo esc_attr( $description ); ?>" data-chart="<?php echo esc_attr( wp_json_encode( $data ) ); ?>"></div>
		</figure>
		<?php
	}

	/**
	 * The charts' numbers as a table, folded away under the charts.
	 *
	 * @param array  $chart      From points(), with every series to list.
	 * @param string $first      Heading of the date column.
	 * @param bool   $with_total Add a Total row.
	 */
	public static function render_chart_table( $chart, $first, $with_total = false ) {
		$totals = array();
		?>
		<details class="bhec-details bhec-chart-numbers">
			<summary><?php esc_html_e( 'Show as a Table', 'backhaus-email-campaigns' ); ?></summary>
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html( $first ); ?></th>
						<?php foreach ( $chart['series'] as $series ) : ?>
							<th scope="col" class="num"><?php echo esc_html( $series['label'] ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $chart['points'] as $i => $point ) : ?>
						<?php
						if ( null === $chart['series'][0]['values'][ $i ] ) {
							continue;
						}
						?>
						<tr>
							<th scope="row"><?php echo esc_html( $point['long'] ); ?></th>
							<?php foreach ( $chart['series'] as $series ) : ?>
								<?php $totals[ $series['key'] ] = ( $totals[ $series['key'] ] ?? 0 ) + (int) $series['values'][ $i ]; ?>
								<td class="num"><?php echo esc_html( number_format_i18n( (int) $series['values'][ $i ] ) ); ?></td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<?php if ( $with_total ) : ?>
					<tfoot>
						<tr>
							<th scope="row"><?php esc_html_e( 'Total', 'backhaus-email-campaigns' ); ?></th>
							<?php foreach ( $chart['series'] as $series ) : ?>
								<td class="num"><?php echo esc_html( number_format_i18n( $totals[ $series['key'] ] ?? 0 ) ); ?></td>
							<?php endforeach; ?>
						</tr>
					</tfoot>
				<?php endif; ?>
			</table>
		</details>
		<?php
	}

	/**
	 * One cell of the numbers strip.
	 *
	 * @param string $label Label.
	 * @param string $value Value; may hold markup (a price, a link).
	 * @param string $sub   Line under the value, plain text.
	 */
	public static function render_stat( $label, $value, $sub = '' ) {
		printf(
			'<div class="bhec-stat"><span class="bhec-stat-label">%1$s</span><span class="bhec-stat-value">%2$s</span><span class="bhec-stat-sub">%3$s</span></div>',
			esc_html( $label ),
			wp_kses( $value, BHEC_Admin::allowed_html() ),
			esc_html( $sub )
		);
	}

	/**
	 * A count with its noun: "1 person", "12 emails", "3 orders".
	 *
	 * @param int    $count Count.
	 * @param string $noun  people, emails or orders.
	 * @return string
	 */
	public static function count_of( $count, $noun ) {
		$count = (int) $count;
		if ( 'emails' === $noun ) {
			/* translators: %s: number of emails */
			return sprintf( _n( '%s email', '%s emails', $count, 'backhaus-email-campaigns' ), number_format_i18n( $count ) );
		}
		if ( 'orders' === $noun ) {
			/* translators: %s: number of orders */
			return sprintf( _n( '%s order', '%s orders', $count, 'backhaus-email-campaigns' ), number_format_i18n( $count ) );
		}
		/* translators: %s: number of people */
		return sprintf( _n( '%s person', '%s people', $count, 'backhaus-email-campaigns' ), number_format_i18n( $count ) );
	}

	/**
	 * The line under the numbers: opens are approximate, and what counts as an
	 * order from an email.
	 */
	public static function render_stats_note() {
		$window = max( 1, (int) get_option( 'bhec_conversion_window_days', 7 ) );
		echo '<p class="bhec-hint bhec-stats-note">';
		esc_html_e( 'Opens are a rough guide: some mail apps load images for privacy, others block them.', 'backhaus-email-campaigns' );
		echo ' ';
		/* translators: %s: number of days */
		echo esc_html( sprintf( _n( 'Orders count when someone buys within %s day of clicking a link in an email.', 'Orders count when someone buys within %s days of clicking a link in an email.', $window, 'backhaus-email-campaigns' ), number_format_i18n( $window ) ) );
		echo '</p>';
	}

	/**
	 * A link as people read it: no scheme, and no domain for the store's own.
	 *
	 * @param string $url Link.
	 * @return string
	 */
	public static function short_url( $url ) {
		$url   = html_entity_decode( $url, ENT_QUOTES, 'UTF-8' );
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return $url;
		}
		$host = preg_replace( '/^www\./', '', $parts['host'] );
		$home = preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$rest = ( $parts['path'] ?? '' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		if ( $host === $home ) {
			return '' !== $rest ? $rest : '/';
		}
		return $host . ( '/' !== $rest ? $rest : '' );
	}
}
