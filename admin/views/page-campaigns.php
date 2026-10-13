<?php
/**
 * Campaigns list.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

// Included from inside a render method, so these variables are local to it.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$list_table = new BHEC_Campaigns_List_Table();
$list_table->prepare_items();
?>
<div class="wrap bhec-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Campaigns', 'backhaus-email-campaigns' ); ?></h1>
	<a href="<?php echo esc_url( BHEC_Admin_Campaigns::url( array( 'action' => 'new' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New Campaign', 'backhaus-email-campaigns' ); ?></a>
	<hr class="wp-header-end">

	<?php BHEC_Admin_Campaigns::render_flash(); ?>

	<?php $list_table->views(); ?>
	<form method="get">
		<input type="hidden" name="page" value="bhec-campaigns">
		<?php
		// $view_filter comes from BHEC_Admin_Campaigns::render_page().
		if ( '' !== $view_filter ) {
			echo '<input type="hidden" name="view" value="' . esc_attr( $view_filter ) . '">';
		}
		$list_table->search_box( __( 'Search Campaigns', 'backhaus-email-campaigns' ), 'bhec-campaign' );
		$list_table->display();
		?>
	</form>
</div>
