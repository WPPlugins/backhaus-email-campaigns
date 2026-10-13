<?php
/**
 * Campaign editor: the email (subject and message) on the left, who gets it
 * and when on the right.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

// Included from inside a render method, so these variables are local to it.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

global $wpdb;

// $campaign and $starter_key come from BHEC_Admin_Campaigns::render_page().
$campaign_id = $campaign ? (int) $campaign->id : 0;
$is_pro      = bhec_is_pro();
// A new campaign can start from a layout (BHEC_Campaign_Starters).
$starter = ! $campaign && '' !== $starter_key ? BHEC_Campaign_Starters::get( $starter_key ) : null;

$filters  = $campaign && $campaign->filters ? json_decode( $campaign->filters, true ) : array();
$filters  = is_array( $filters ) ? $filters : array();
$segments = $wpdb->get_results( "SELECT id, name, filters FROM {$wpdb->prefix}bhec_segments ORDER BY name ASC" );

$coupon = $campaign && $campaign->coupon_settings ? json_decode( $campaign->coupon_settings, true ) : ( $starter['coupon'] ?? array() );
$coupon = is_array( $coupon ) ? $coupon : array();

$attachment_ids = $campaign && $campaign->attachments ? json_decode( $campaign->attachments, true ) : array();
$attachment_ids = is_array( $attachment_ids ) ? array_filter( array_map( 'absint', $attachment_ids ) ) : array();

$scheduled_value = $campaign && $campaign->scheduled_at ? gmdate( 'Y-m-d\TH:i', strtotime( $campaign->scheduled_at ) ) : '';
$default_statuses = array( 'wc-completed', 'wc-processing', 'wc-on-hold' );
$checked_statuses = ! empty( $filters['order_status'] ) ? (array) $filters['order_status'] : $default_statuses;
$currency         = get_woocommerce_currency_symbol();
?>
<div class="wrap bhec-wrap bhec-editor">
	<a class="bhec-back" href="<?php echo esc_url( BHEC_Admin_Campaigns::url() ); ?>"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span><?php esc_html_e( 'All Campaigns', 'backhaus-email-campaigns' ); ?></a>
	<h1 class="wp-heading-inline"><?php echo $campaign ? esc_html__( 'Edit Campaign', 'backhaus-email-campaigns' ) : esc_html__( 'New Campaign', 'backhaus-email-campaigns' ); ?></h1>
	<hr class="wp-header-end">

	<?php BHEC_Admin_Campaigns::render_flash(); ?>

	<?php if ( ! $campaign ) : ?>
		<p class="bhec-starter-line">
			<?php esc_html_e( 'Start From a Layout:', 'backhaus-email-campaigns' ); ?>
			<?php
			$links = array();
			foreach ( BHEC_Campaign_Starters::all() as $key => $layout ) {
				$links[] = sprintf(
					'<a href="%s"%s>%s</a>',
					esc_url( BHEC_Admin_Campaigns::url( array( 'action' => 'new', 'starter' => $key ) ) ),
					$starter && $layout['name'] === $starter['name'] ? ' aria-current="true"' : '',
					esc_html( $layout['name'] )
				);
			}
			echo wp_kses( implode( ' <span aria-hidden="true">&middot;</span> ', $links ), BHEC_Admin::allowed_html() );
			?>
		</p>
	<?php endif; ?>

	<?php if ( $campaign && 'failed' === $campaign->status ) : ?>
		<div class="notice notice-error inline"><p><?php esc_html_e( 'This campaign did not send. Check it over, then send or schedule it again.', 'backhaus-email-campaigns' ); ?></p></div>
	<?php endif; ?>

	<div class="notice notice-error inline bhec-form-error" hidden><p></p></div>

	<input type="hidden" id="bhec-campaign-id" value="<?php echo esc_attr( $campaign_id ); ?>">

	<div id="poststuff">
		<div id="post-body" class="metabox-holder columns-2">

			<div id="post-body-content">
				<div id="titlediv">
					<div id="titlewrap">
						<label class="screen-reader-text" for="bhec-title"><?php esc_html_e( 'Campaign Name', 'backhaus-email-campaigns' ); ?></label>
						<input type="text" id="bhec-title" size="30" autocomplete="off" spellcheck="true"
							placeholder="<?php esc_attr_e( 'Campaign name', 'backhaus-email-campaigns' ); ?>"
							value="<?php echo esc_attr( $campaign ? $campaign->title : ( $starter['title'] ?? '' ) ); ?>">
					</div>
					<p class="bhec-hint"><?php esc_html_e( 'Only you see this. Customers see the subject.', 'backhaus-email-campaigns' ); ?></p>
				</div>

				<div class="postbox bhec-compose">
					<div class="inside">
						<div class="bhec-field">
							<div class="bhec-label-row">
								<label class="bhec-label" for="bhec-subject"><?php esc_html_e( 'Subject', 'backhaus-email-campaigns' ); ?></label>
								<div class="bhec-tools">
									<?php BHEC_Admin_Campaigns::render_tool( 'personalize', __( 'Personalize', 'backhaus-email-campaigns' ), array( 'class' => 'bhec-personalize', 'data-target' => 'subject', 'aria-haspopup' => 'menu', 'aria-expanded' => 'false' ) ); ?>
								</div>
							</div>
							<input type="text" id="bhec-subject" class="bhec-subject-input" autocomplete="off"
								value="<?php echo esc_attr( $campaign ? $campaign->subject : ( $starter['subject'] ?? '' ) ); ?>"
								placeholder="<?php esc_attr_e( 'What your customers see in their inbox', 'backhaus-email-campaigns' ); ?>">
							<p class="bhec-hint bhec-subject-meter" aria-live="polite"></p>
							<?php  ?>
						</div>

						<div class="bhec-field">
							<div class="bhec-label-row">
								<span class="bhec-label" id="bhec-message-label"><?php esc_html_e( 'Message', 'backhaus-email-campaigns' ); ?></span>
								<div class="bhec-tools" role="toolbar" aria-label="<?php esc_attr_e( 'Add to the Message', 'backhaus-email-campaigns' ); ?>">
									<?php
									BHEC_Admin_Campaigns::render_tool( 'image', __( 'Add Image', 'backhaus-email-campaigns' ), array( 'id' => 'bhec-add-image' ) );
									BHEC_Admin_Campaigns::render_tool( 'personalize', __( 'Personalize', 'backhaus-email-campaigns' ), array( 'class' => 'bhec-personalize', 'data-target' => 'body', 'aria-haspopup' => 'menu', 'aria-expanded' => 'false' ) );
									BHEC_Admin_Campaigns::render_tool( 'product', __( 'Add Product', 'backhaus-email-campaigns' ), array( 'id' => 'bhec-add-product', 'aria-haspopup' => 'dialog', 'aria-expanded' => 'false' ) );
																		?>
								</div>
							</div>
							<?php
							wp_editor(
								$campaign ? $campaign->body : ( $starter['body'] ?? '' ),
								'bhec_body',
								array(
									'textarea_name' => 'body',
									'textarea_rows' => 18,
									'media_buttons' => false,
									'editor_height' => 360,
									'tinymce'       => array(
										'toolbar1' => 'formatselect,bold,italic,underline,|,bullist,numlist,blockquote,|,alignleft,aligncenter,alignright,|,link,unlink,|,wp_adv',
										'toolbar2' => 'forecolor,backcolor,hr,removeformat,charmap,|,undo,redo',
									),
								)
							);
							?>
						</div>
					</div>
				</div>

				<?php  ?>

				<div class="postbox bhec-section" id="bhec-more-section">
					<button type="button" class="bhec-section-toggle" aria-expanded="false" aria-controls="bhec-more-body">
						<span class="bhec-section-title"><?php esc_html_e( 'More Options', 'backhaus-email-campaigns' ); ?></span>
						<span class="bhec-section-summary" id="bhec-more-summary"></span>
						<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
					</button>
					<div class="bhec-section-body" id="bhec-more-body" hidden>
						<div class="bhec-grid">
							<div class="bhec-field">
								<label class="bhec-label" for="bhec-reply-to-email"><?php esc_html_e( 'Replies Go To', 'backhaus-email-campaigns' ); ?></label>
								<input type="email" id="bhec-reply-to-email" class="regular-text" value="<?php echo $campaign ? esc_attr( $campaign->reply_to_email ) : ''; ?>" placeholder="<?php esc_attr_e( 'Your store email', 'backhaus-email-campaigns' ); ?>">
							</div>
							<div class="bhec-field">
								<label class="bhec-label" for="bhec-reply-to-name"><?php esc_html_e( 'Reply Name', 'backhaus-email-campaigns' ); ?></label>
								<input type="text" id="bhec-reply-to-name" class="regular-text" value="<?php echo $campaign ? esc_attr( $campaign->reply_to_name ) : ''; ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
							</div>
							<div class="bhec-field">
								<label class="bhec-label" for="bhec-layout-template"><?php esc_html_e( 'Layout', 'backhaus-email-campaigns' ); ?></label>
								<select id="bhec-layout-template">
									<?php
									$current_layout = $campaign && $campaign->layout_template ? $campaign->layout_template : 'default';
									foreach ( BHEC_Email_Templates::get_layout_templates() as $layout_id => $layout_label ) :
										?>
										<option value="<?php echo esc_attr( $layout_id ); ?>" <?php selected( $current_layout, $layout_id ); ?>><?php echo esc_html( $layout_label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="bhec-hint"><?php esc_html_e( 'WooCommerce Default uses the header, colors and footer of your store emails.', 'backhaus-email-campaigns' ); ?></p>
							</div>
							<div class="bhec-field">
								<span class="bhec-label"><?php esc_html_e( 'Attachments', 'backhaus-email-campaigns' ); ?></span>
								<ul id="bhec-attachment-list" class="bhec-attachments">
									<?php foreach ( $attachment_ids as $att_id ) : ?>
										<li class="bhec-attachment-item" data-id="<?php echo esc_attr( $att_id ); ?>">
											<span class="dashicons dashicons-paperclip" aria-hidden="true"></span>
											<?php echo esc_html( wp_basename( (string) get_attached_file( $att_id ) ) ); ?>
											<button type="button" class="button-link bhec-remove-attachment"><?php esc_html_e( 'Remove', 'backhaus-email-campaigns' ); ?></button>
										</li>
									<?php endforeach; ?>
								</ul>
								<button type="button" class="button" id="bhec-add-attachment"><?php esc_html_e( 'Add Attachment', 'backhaus-email-campaigns' ); ?></button>
							</div>
						</div>
						<details class="bhec-details" <?php echo $campaign && ( $campaign->email_cc || $campaign->email_bcc || ( $campaign->email_to && '{{ customer.email }}' !== $campaign->email_to ) ) ? 'open' : ''; ?>>
							<summary><?php esc_html_e( 'Copies and Recipient Address', 'backhaus-email-campaigns' ); ?></summary>
							<div class="bhec-grid">
								<div class="bhec-field">
									<label class="bhec-label" for="bhec-email-bcc"><?php esc_html_e( 'BCC', 'backhaus-email-campaigns' ); ?></label>
									<input type="text" id="bhec-email-bcc" class="regular-text" value="<?php echo $campaign ? esc_attr( $campaign->email_bcc ) : ''; ?>">
									<p class="bhec-hint"><?php esc_html_e( 'Gets a copy of every email, so one address here receives one email per customer.', 'backhaus-email-campaigns' ); ?></p>
								</div>
								<div class="bhec-field">
									<label class="bhec-label" for="bhec-email-cc"><?php esc_html_e( 'CC', 'backhaus-email-campaigns' ); ?></label>
									<input type="text" id="bhec-email-cc" class="regular-text" value="<?php echo $campaign ? esc_attr( $campaign->email_cc ) : ''; ?>">
									<p class="bhec-hint"><?php esc_html_e( 'Customers can see CC addresses.', 'backhaus-email-campaigns' ); ?></p>
								</div>
								<div class="bhec-field">
									<label class="bhec-label" for="bhec-email-to"><?php esc_html_e( 'Send To', 'backhaus-email-campaigns' ); ?></label>
									<input type="text" id="bhec-email-to" class="regular-text" value="<?php echo esc_attr( $campaign && $campaign->email_to ? $campaign->email_to : '{{ customer.email }}' ); ?>">
									<p class="bhec-hint"><?php esc_html_e( 'Leave as {{ customer.email }} unless you know you need something else.', 'backhaus-email-campaigns' ); ?></p>
								</div>
							</div>
						</details>
					</div>
				</div>
			</div>

			<div id="postbox-container-1" class="postbox-container">
				<div class="postbox bhec-sendbox">
					<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Send', 'backhaus-email-campaigns' ); ?></h2></div>
					<div class="inside">
						<div class="bhec-reach">
							<span class="bhec-reach-number" id="bhec-reach-number">&nbsp;</span>
							<span class="bhec-reach-label" id="bhec-reach-label"><?php esc_html_e( 'Counting who gets this...', 'backhaus-email-campaigns' ); ?></span>
						</div>

						<fieldset class="bhec-when">
							<legend class="screen-reader-text"><?php esc_html_e( 'When to Send', 'backhaus-email-campaigns' ); ?></legend>
							<label><input type="radio" name="bhec-when" value="now" <?php checked( '' === $scheduled_value ); ?>> <?php esc_html_e( 'Send now', 'backhaus-email-campaigns' ); ?></label>
							<label><input type="radio" name="bhec-when" value="later" <?php checked( '' !== $scheduled_value ); ?>> <?php esc_html_e( 'Schedule for later', 'backhaus-email-campaigns' ); ?></label>
							<div class="bhec-schedule" <?php echo '' === $scheduled_value ? 'hidden' : ''; ?>>
								<label class="screen-reader-text" for="bhec-scheduled-at"><?php esc_html_e( 'Send At', 'backhaus-email-campaigns' ); ?></label>
								<input type="datetime-local" id="bhec-scheduled-at" value="<?php echo esc_attr( $scheduled_value ); ?>" min="<?php echo esc_attr( wp_date( 'Y-m-d\TH:i' ) ); ?>">
								<p class="bhec-hint">
									<?php
									/* translators: %s: time zone, such as UTC+8 or Asia/Singapore */
									echo esc_html( sprintf( __( 'Store time (%s)', 'backhaus-email-campaigns' ), BHEC_Admin_Campaigns::timezone_label() ) );
									?>
								</p>
							</div>
						</fieldset>

						<div class="bhec-check-row">
							<button type="button" class="button" id="bhec-preview-email"><?php esc_html_e( 'Preview', 'backhaus-email-campaigns' ); ?></button>
							<button type="button" class="button" id="bhec-test-toggle" aria-expanded="false" aria-controls="bhec-test-form"><?php esc_html_e( 'Send a Test', 'backhaus-email-campaigns' ); ?></button>
						</div>
						<div class="bhec-test-form" id="bhec-test-form" hidden>
							<label class="bhec-label" for="bhec-test-email"><?php esc_html_e( 'Send a Test To', 'backhaus-email-campaigns' ); ?></label>
							<div class="bhec-inline">
								<input type="email" id="bhec-test-email" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>">
								<button type="button" class="button" id="bhec-send-test"><?php esc_html_e( 'Send', 'backhaus-email-campaigns' ); ?></button>
							</div>
							<p class="bhec-hint" id="bhec-test-status" aria-live="polite"><?php esc_html_e( 'Filled in with a recent customer\'s details. The unsubscribe link is a sample.', 'backhaus-email-campaigns' ); ?></p>
						</div>
					</div>
					<div id="major-publishing-actions">
						<div id="delete-action">
							<button type="button" class="button-link" id="bhec-save-draft"><?php esc_html_e( 'Save Draft', 'backhaus-email-campaigns' ); ?></button>
							<span class="bhec-save-state" id="bhec-save-state" aria-live="polite"></span>
						</div>
						<div id="publishing-action">
							<span class="spinner"></span>
							<button type="button" class="button button-primary button-large" id="bhec-send" data-now="<?php esc_attr_e( 'Send', 'backhaus-email-campaigns' ); ?>" data-later="<?php esc_attr_e( 'Schedule', 'backhaus-email-campaigns' ); ?>">
								<?php '' === $scheduled_value ? esc_html_e( 'Send', 'backhaus-email-campaigns' ) : esc_html_e( 'Schedule', 'backhaus-email-campaigns' ); ?>
							</button>
						</div>
						<div class="clear"></div>
					</div>
				</div>

				<div class="postbox bhec-audience">
					<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Who Gets This', 'backhaus-email-campaigns' ); ?></h2></div>
					<div class="inside">
						<p class="bhec-hint"><?php esc_html_e( 'Subscribed customers with a completed, processing or on-hold order. Narrow it down below. Nobody gets the same campaign twice.', 'backhaus-email-campaigns' ); ?></p>

						<?php if ( $segments ) : ?>
						<div class="bhec-field">
							<label class="bhec-label" for="bhec-load-segment"><?php esc_html_e( 'Start From a Saved Segment', 'backhaus-email-campaigns' ); ?></label>
							<select id="bhec-load-segment" class="widefat">
								<option value=""><?php esc_html_e( 'Choose a segment', 'backhaus-email-campaigns' ); ?></option>
								<?php foreach ( $segments as $seg ) : ?>
									<option value="<?php echo esc_attr( $seg->id ); ?>" data-filters="<?php echo esc_attr( $seg->filters ); ?>"><?php echo esc_html( $seg->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<?php endif; ?>

						<div class="bhec-field">
							<span class="bhec-label"><?php esc_html_e( 'Ordered Between', 'backhaus-email-campaigns' ); ?></span>
							<div class="bhec-dates">
								<label for="bhec-filter-date-from"><?php esc_html_e( 'From', 'backhaus-email-campaigns' ); ?></label>
								<input type="date" id="bhec-filter-date-from" value="<?php echo esc_attr( $filters['date_from'] ?? '' ); ?>">
								<label for="bhec-filter-date-to"><?php esc_html_e( 'To', 'backhaus-email-campaigns' ); ?></label>
								<input type="date" id="bhec-filter-date-to" value="<?php echo esc_attr( $filters['date_to'] ?? '' ); ?>">
							</div>
						</div>

						<div class="bhec-field">
							<span class="bhec-label"><?php esc_html_e( 'Number of Orders', 'backhaus-email-campaigns' ); ?></span>
							<div class="bhec-range">
								<label for="bhec-filter-order-count-min"><?php esc_html_e( 'At least', 'backhaus-email-campaigns' ); ?></label>
								<input type="number" id="bhec-filter-order-count-min" min="0" value="<?php echo esc_attr( $filters['order_count_min'] ?? '' ); ?>">
								<label for="bhec-filter-order-count-max"><?php esc_html_e( 'at most', 'backhaus-email-campaigns' ); ?></label>
								<input type="number" id="bhec-filter-order-count-max" min="0" value="<?php echo esc_attr( $filters['order_count_max'] ?? '' ); ?>">
							</div>
						</div>

						<div class="bhec-field">
							<?php /* translators: %s: currency symbol */ ?>
							<span class="bhec-label"><?php echo esc_html( sprintf( __( 'Total Spent (%s)', 'backhaus-email-campaigns' ), $currency ) ); ?></span>
							<div class="bhec-range">
								<label for="bhec-filter-total-spent-min"><?php esc_html_e( 'At least', 'backhaus-email-campaigns' ); ?></label>
								<input type="number" id="bhec-filter-total-spent-min" min="0" step="0.01" value="<?php echo esc_attr( $filters['total_spent_min'] ?? '' ); ?>">
								<label for="bhec-filter-total-spent-max"><?php esc_html_e( 'at most', 'backhaus-email-campaigns' ); ?></label>
								<input type="number" id="bhec-filter-total-spent-max" min="0" step="0.01" value="<?php echo esc_attr( $filters['total_spent_max'] ?? '' ); ?>">
							</div>
						</div>

						<div class="bhec-field">
							<label class="bhec-label" for="bhec-filter-billing-country"><?php esc_html_e( 'Billing Country', 'backhaus-email-campaigns' ); ?></label>
							<select id="bhec-filter-billing-country" class="wc-enhanced-select" style="width:100%" data-placeholder="<?php esc_attr_e( 'Any country', 'backhaus-email-campaigns' ); ?>" data-allow_clear="true">
								<option value=""></option>
								<?php foreach ( WC()->countries->get_countries() as $code => $name ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $filters['billing_country'] ?? '', $code ); ?>><?php echo esc_html( html_entity_decode( $name ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>

						<details class="bhec-details" <?php echo ! empty( $filters['order_status'] ) && array_diff( (array) $filters['order_status'], $default_statuses ) ? 'open' : ''; ?>>
							<summary><?php esc_html_e( 'Order Status', 'backhaus-email-campaigns' ); ?></summary>
							<div class="bhec-status-checks" id="bhec-filter-order-status" data-default="<?php echo esc_attr( implode( ',', $default_statuses ) ); ?>">
								<?php foreach ( wc_get_order_statuses() as $key => $label ) : ?>
									<label><input type="checkbox" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $checked_statuses, true ) ); ?>> <?php echo esc_html( $label ); ?></label>
								<?php endforeach; ?>
							</div>
						</details>

						<label class="bhec-toggle-row">
							<input type="checkbox" id="bhec-filter-include-signups" <?php checked( ! empty( $filters['include_signups'] ) ); ?>>
							<?php esc_html_e( 'Also include people who signed up but haven\'t ordered', 'backhaus-email-campaigns' ); ?>
						</label>

						<?php if ( $segments ) : ?>
						<div class="bhec-field">
							<label class="bhec-label" for="bhec-exclude-segment"><?php esc_html_e( 'Leave Out a Segment', 'backhaus-email-campaigns' ); ?></label>
							<select id="bhec-exclude-segment" class="widefat">
								<option value="0"><?php esc_html_e( 'Nobody', 'backhaus-email-campaigns' ); ?></option>
								<?php foreach ( $segments as $seg ) : ?>
									<option value="<?php echo esc_attr( $seg->id ); ?>" <?php selected( $campaign ? (int) $campaign->exclude_segment_id : 0, (int) $seg->id ); ?>><?php echo esc_html( $seg->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<?php endif; ?>

						<div class="bhec-audience-footer">
							<button type="button" class="button-link" id="bhec-show-sample" aria-expanded="false" aria-controls="bhec-sample"><?php esc_html_e( 'See Who', 'backhaus-email-campaigns' ); ?></button>
							<button type="button" class="button-link" id="bhec-save-segment-toggle" aria-expanded="false" aria-controls="bhec-save-segment-form"><?php esc_html_e( 'Save as Segment', 'backhaus-email-campaigns' ); ?></button>
						</div>
						<div id="bhec-sample" class="bhec-sample" hidden>
							<ul id="bhec-sample-list"></ul>
						</div>
						<div id="bhec-save-segment-form" class="bhec-inline" hidden>
							<label class="screen-reader-text" for="bhec-save-segment-name"><?php esc_html_e( 'Segment Name', 'backhaus-email-campaigns' ); ?></label>
							<input type="text" id="bhec-save-segment-name" placeholder="<?php esc_attr_e( 'Segment name', 'backhaus-email-campaigns' ); ?>">
							<button type="button" class="button" id="bhec-save-segment"><?php esc_html_e( 'Save', 'backhaus-email-campaigns' ); ?></button>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<?php BHEC_Admin_Campaigns::render_merge_tag_menu(); ?>

	<div class="bhec-popover" id="bhec-product-popover" role="dialog" aria-label="<?php esc_attr_e( 'Add Product', 'backhaus-email-campaigns' ); ?>" hidden>
		<label class="bhec-label" for="bhec-product-select"><?php esc_html_e( 'Add a Product Card', 'backhaus-email-campaigns' ); ?></label>
		<select id="bhec-product-select" class="wc-product-search" style="width:100%" data-placeholder="<?php esc_attr_e( 'Search your products', 'backhaus-email-campaigns' ); ?>" data-action="woocommerce_json_search_products" data-allow_clear="true">
			<option value=""></option>
		</select>
		<p class="bhec-hint"><?php esc_html_e( 'Shows the photo, name, price and a link. Cards placed next to each other line up in a row.', 'backhaus-email-campaigns' ); ?></p>
	</div>

	<dialog class="bhec-dialog" id="bhec-send-dialog" aria-labelledby="bhec-send-dialog-title">
		<form method="dialog">
			<h2 id="bhec-send-dialog-title"></h2>
			<div class="bhec-dialog-body" id="bhec-send-dialog-body"></div>
			<div class="bhec-dialog-actions">
				<button type="submit" class="button" value="cancel"><?php esc_html_e( 'Cancel', 'backhaus-email-campaigns' ); ?></button>
				<button type="submit" class="button button-primary" value="confirm" id="bhec-send-confirm"></button>
			</div>
		</form>
	</dialog>

	<dialog class="bhec-dialog bhec-preview-dialog" id="bhec-preview-dialog" aria-labelledby="bhec-preview-title">
		<div class="bhec-preview-head">
			<div>
				<h2 id="bhec-preview-title"><?php esc_html_e( 'Preview', 'backhaus-email-campaigns' ); ?></h2>
				<p class="bhec-preview-subject" id="bhec-preview-subject"></p>
			</div>
			<div class="bhec-segmented" role="group" aria-label="<?php esc_attr_e( 'Preview Width', 'backhaus-email-campaigns' ); ?>">
				<button type="button" class="button" data-width="desktop" aria-pressed="true"><?php esc_html_e( 'Desktop', 'backhaus-email-campaigns' ); ?></button>
				<button type="button" class="button" data-width="mobile" aria-pressed="false"><?php esc_html_e( 'Mobile', 'backhaus-email-campaigns' ); ?></button>
			</div>
			<form method="dialog"><button type="submit" class="button-link bhec-dialog-close" aria-label="<?php esc_attr_e( 'Close', 'backhaus-email-campaigns' ); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button></form>
		</div>
		<p class="bhec-hint bhec-preview-note"><?php esc_html_e( 'Filled in with a recent customer\'s details.', 'backhaus-email-campaigns' ); ?></p>
		<div class="bhec-preview-frame"><iframe id="bhec-preview-frame" title="<?php esc_attr_e( 'Email Preview', 'backhaus-email-campaigns' ); ?>" sandbox="allow-popups allow-popups-to-escape-sandbox"></iframe></div>
	</dialog>
</div>
