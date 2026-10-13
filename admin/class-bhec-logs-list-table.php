<?php
/**
 * Settings > Logs: every email the plugin sent or tried to send, filtered
 * by result and campaign.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class BHEC_Logs_List_Table extends WP_List_Table {

	/** @var array Entry count per result, for the campaign on screen. */
	private $counts = array();

	/** @var array Campaign titles by id, for the rows on this page. */
	private $titles = array();

	/** @var array Automatic email names by rule id, for the rows on this page. */
	private $rule_names = array();

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'log-entry',
				'plural'   => 'log-entries',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Result views: slug => label.
	 *
	 * @return array
	 */
	private function view_labels() {
		return array(
			'sent'    => __( 'Sent', 'backhaus-email-campaigns' ),
			'failed'  => __( 'Failed', 'backhaus-email-campaigns' ),
			'skipped' => __( 'Skipped', 'backhaus-email-campaigns' ),
		);
	}

	/**
	 * The result view on screen.
	 *
	 * @return string
	 */
	private function current_view() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$view = isset( $_GET['result'] ) ? sanitize_key( $_GET['result'] ) : '';
		return isset( $this->view_labels()[ $view ] ) ? $view : '';
	}

	/**
	 * The campaign filter: '' for all, 'auto' for automatic emails, or an id.
	 *
	 * @return string|int
	 */
	public static function current_campaign() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$campaign = isset( $_GET['campaign'] ) ? sanitize_key( $_GET['campaign'] ) : '';
		if ( 'auto' === $campaign ) {
			return 'auto';
		}
		return absint( $campaign ) ? absint( $campaign ) : '';
	}

	/**
	 * SQL condition and values for the campaign filter.
	 *
	 * @return array [ condition, params ]
	 */
	private function campaign_condition() {
		$campaign = self::current_campaign();
		if ( 'auto' === $campaign ) {
			// Automatic emails are logged without a campaign.
			return array( '(campaign_id IS NULL OR campaign_id = 0)', array() );
		}
		if ( $campaign ) {
			return array( 'campaign_id = %d', array( $campaign ) );
		}
		return array( '1=1', array() );
	}

	/**
	 * Link to the Logs tab, keeping the campaign filter.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	private function tab_url( $args = array() ) {
		$campaign = self::current_campaign();
		if ( '' !== $campaign ) {
			$args['campaign'] = $campaign;
		}
		return add_query_arg( $args, BHEC_Admin_Settings::url( 'logs' ) );
	}

	public function prepare_items() {
		global $wpdb;
		$table = $wpdb->prefix . 'bhec_logs';

		list( $campaign_where, $campaign_params ) = $this->campaign_condition();

		// "1 = %d" keeps a value for prepare() when no filter is set.
		$campaign_where  = '1 = %d AND ' . $campaign_where;
		$campaign_params = array_merge( array( 1 ), $campaign_params );

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT status, COUNT(*) AS n FROM $table WHERE $campaign_where GROUP BY status", $campaign_params ) // phpcs:ignore WordPress.DB.PreparedSQL -- conditions are fixed text and placeholders.
		);
		$this->counts = array_map( 'intval', wp_list_pluck( $rows, 'n', 'status' ) );
		$this->counts['all'] = array_sum( $this->counts );

		$where  = array( $campaign_where );
		$params = $campaign_params;
		$view   = $this->current_view();
		if ( $view ) {
			$where[]  = 'status = %s';
			$params[] = $view;
		}
		// phpcs:ignore WordPress.Security.NonceVerification
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(recipient_email LIKE %s OR recipient_name LIKE %s OR subject LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		$where = implode( ' AND ', $where );

		// Newest first; "When" is the only sortable column.
		// phpcs:ignore WordPress.Security.NonceVerification
		$order    = isset( $_GET['order'] ) && 'asc' === sanitize_key( $_GET['order'] ) ? 'ASC' : 'DESC';
		$per_page = $this->get_items_per_page( 'bhec_logs_per_page', 25 );
		$offset   = ( $this->get_pagenum() - 1 ) * $per_page;

		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE $where", $params ) // phpcs:ignore WordPress.DB.PreparedSQL -- conditions are fixed text and placeholders.
		);
		$this->items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, campaign_id, rule_id, recipient_email, recipient_name, subject, status, error_message, sent_at
				FROM $table WHERE $where ORDER BY sent_at $order, id $order LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL
				array_merge( $params, array( $per_page, $offset ) )
			)
		);

		$ids = array_values( array_unique( array_filter( array_map( 'intval', wp_list_pluck( $this->items, 'campaign_id' ) ) ) ) );
		if ( $ids ) {
			$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
			$campaigns    = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one placeholder per value.
					"SELECT id, title FROM {$wpdb->prefix}bhec_campaigns WHERE id IN ( $placeholders )",
					$ids
				)
			);
			$this->titles = wp_list_pluck( $campaigns, 'title', 'id' );
		}
		$this->rule_names = BHEC_Admin_Logs::rule_names( wp_list_pluck( $this->items, 'rule_id' ) );

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'recipient' );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
			)
		);
	}

	public function get_columns() {
		return array(
			'recipient' => __( 'Recipient', 'backhaus-email-campaigns' ),
			'subject'   => __( 'Subject', 'backhaus-email-campaigns' ),
			'campaign'  => __( 'Campaign', 'backhaus-email-campaigns' ),
			'result'    => __( 'Result', 'backhaus-email-campaigns' ),
			'when'      => __( 'When', 'backhaus-email-campaigns' ),
		);
	}

	protected function get_sortable_columns() {
		return array(
			'when' => array( 'when', true ),
		);
	}

	protected function get_views() {
		$current = $this->current_view();
		$views   = array(
			'all' => sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( $this->tab_url() ),
				'' === $current ? ' class="current" aria-current="page"' : '',
				esc_html__( 'All', 'backhaus-email-campaigns' ),
				number_format_i18n( $this->counts['all'] )
			),
		);
		foreach ( $this->view_labels() as $view => $label ) {
			$count = $this->counts[ $view ] ?? 0;
			if ( ! $count ) {
				continue;
			}
			$views[ $view ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( $this->tab_url( array( 'result' => $view ) ) ),
				$view === $current ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				number_format_i18n( $count )
			);
		}
		return $views;
	}

	/**
	 * Campaign filter, listing only campaigns that have log entries.
	 *
	 * @param string $which Top or bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'bhec_logs';
		$ids   = array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT campaign_id FROM $table WHERE campaign_id > 0" ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$auto  = (bool) $wpdb->get_var( "SELECT 1 FROM $table WHERE campaign_id IS NULL OR campaign_id = 0 LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( count( $ids ) + (int) $auto < 2 ) {
			return;
		}
		$titles = array();
		if ( $ids ) {
			$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
			$titles       = wp_list_pluck(
				$wpdb->get_results(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one placeholder per value.
						"SELECT id, title FROM {$wpdb->prefix}bhec_campaigns WHERE id IN ( $placeholders )",
						$ids
					)
				),
				'title',
				'id'
			);
		}
		rsort( $ids );
		$current = (string) self::current_campaign();
		?>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="bhec-log-campaign"><?php esc_html_e( 'Filter by Campaign', 'backhaus-email-campaigns' ); ?></label>
			<select name="campaign" id="bhec-log-campaign">
				<option value=""><?php esc_html_e( 'All Emails', 'backhaus-email-campaigns' ); ?></option>
				<?php if ( $auto ) : ?>
					<option value="auto" <?php selected( $current, 'auto' ); ?>><?php esc_html_e( 'Automatic Emails', 'backhaus-email-campaigns' ); ?></option>
				<?php endif; ?>
				<?php foreach ( $ids as $id ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $current, (string) $id ); ?>><?php echo esc_html( BHEC_Admin_Logs::campaign_name( $id, $titles[ $id ] ?? null ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'backhaus-email-campaigns' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/**
	 * Who it went to, with the row actions.
	 *
	 * @param object $item Log row.
	 * @return string
	 */
	public function column_recipient( $item ) {
		$out = '<strong>' . esc_html( $item->recipient_email ) . '</strong>';
		if ( '' !== trim( (string) $item->recipient_name ) ) {
			$out .= '<span class="bhec-row-subject">' . esc_html( $item->recipient_name ) . '</span>';
		}
		// Phones hide the other columns, so the result also sits here.
		$out .= '<span class="bhec-mobile-result">' . BHEC_Admin_Logs::result_badge( $item->status ) . '</span>';

		$actions = array(
			'details' => sprintf(
				'<button type="button" class="button-link bhec-log-details" data-id="%d">%s</button>',
				(int) $item->id,
				esc_html__( 'View Details', 'backhaus-email-campaigns' )
			),
			'person'  => sprintf(
				'<a href="%s">%s</a>',
				esc_url( add_query_arg( 's', rawurlencode( $item->recipient_email ), BHEC_Admin_Settings::url( 'logs' ) ) ),
				esc_html__( 'Emails to This Person', 'backhaus-email-campaigns' )
			),
		);
		return $out . $this->row_actions( $actions );
	}

	public function column_subject( $item ) {
		return '' !== $item->subject ? esc_html( $item->subject ) : '<span class="bhec-muted">' . esc_html__( '(No subject)', 'backhaus-email-campaigns' ) . '</span>';
	}

	public function column_campaign( $item ) {
		$id = (int) $item->campaign_id;
		if ( ! $id ) {
			$rule_id = (int) $item->rule_id;
			if ( isset( $this->rule_names[ $rule_id ] ) ) {
				return sprintf( '<a href="%s">%s</a>', esc_url( BHEC_Admin_Logs::rule_url( $rule_id ) ), esc_html( $this->rule_names[ $rule_id ] ) );
			}
			return '<span class="bhec-muted">' . esc_html( $rule_id ? __( '(Deleted Automatic Email)', 'backhaus-email-campaigns' ) : __( 'Automatic email', 'backhaus-email-campaigns' ) ) . '</span>';
		}
		$title = $this->titles[ $id ] ?? null;
		$name  = BHEC_Admin_Logs::campaign_name( $id, $title );
		if ( null === $title ) {
			return '<span class="bhec-muted">' . esc_html( $name ) . '</span>';
		}
		return sprintf( '<a href="%s">%s</a>', esc_url( BHEC_Admin_Campaigns::url( array( 'action' => 'view', 'id' => $id ) ) ), esc_html( $name ) );
	}

	public function column_result( $item ) {
		$out = BHEC_Admin_Logs::result_badge( $item->status );
		if ( 'sent' !== $item->status && '' !== (string) $item->error_message ) {
			$out .= '<span class="bhec-status-detail">' . esc_html( $item->error_message ) . '</span>';
		}
		return $out;
	}

	public function column_when( $item ) {
		return esc_html( BHEC_Admin_Logs::format_logged( $item->sent_at ) );
	}

	public function no_items() {
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( $this->counts['all'] > 0 || '' !== self::current_campaign() || ! empty( $_REQUEST['s'] ) ) {
			esc_html_e( 'No emails match.', 'backhaus-email-campaigns' );
			return;
		}
		echo '<div class="bhec-empty">';
		echo '<p class="bhec-empty-title">' . esc_html__( 'No emails logged yet', 'backhaus-email-campaigns' ) . '</p>';
		if ( 'off' === get_option( 'bhec_logging_mode', 'meta' ) ) {
			echo '<p>' . esc_html__( 'The email log is off. Turn it on below to keep a record of every email this plugin sends.', 'backhaus-email-campaigns' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Every email this plugin sends shows up here: who got it, what it said and whether it went through.', 'backhaus-email-campaigns' ) . '</p>';
			printf(
				'<p><a class="button" href="%s">%s</a></p>',
				esc_url( BHEC_Admin_Campaigns::url( array( 'action' => 'new' ) ) ),
				esc_html__( 'Create a Campaign', 'backhaus-email-campaigns' )
			);
		}
		echo '</div>';
	}
}
