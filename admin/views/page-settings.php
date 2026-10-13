<?php
/**
 * Settings, in tabs. Logs and Status have their own views.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

// Included from inside a render method, so these variables are local to it.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$current = BHEC_Admin_Settings::current_tab();
?>
<div class="wrap bhec-wrap bhec-settings">
	<h1><?php esc_html_e( 'Settings', 'backhaus-email-campaigns' ); ?></h1>

	<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Settings Sections', 'backhaus-email-campaigns' ); ?>">
		<?php foreach ( BHEC_Admin_Settings::tabs() as $slug => $tab ) : ?>
			<a href="<?php echo esc_url( BHEC_Admin_Settings::url( $slug ) ); ?>" class="nav-tab<?php echo $slug === $current ? ' nav-tab-active' : ''; ?>"<?php echo $slug === $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $tab[0] ); ?></a>
		<?php endforeach; ?>
	</nav>

	<?php settings_errors(); ?>

	<div class="bhec-settings-body">
		<?php
		switch ( $current ) {
			case 'logs':
			case 'status':
				include BHEC_PLUGIN_DIR . 'admin/views/settings-' . $current . '.php';
				break;
			case 'license':
				BHEC_Admin_Settings::render_license();
				break;
			default:
				BHEC_Admin_Settings::render_settings_form( $current );
				break;
		}
		?>
	</div>
</div>
