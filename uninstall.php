<?php
/**
 * Uninstall — remove all plugin state. Never leaves secrets behind.
 *
 * @package agentbadge
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Options owned by the plugin (site_key, consents, cached reports,
// trust pins, pull-job nonces, status snapshot).
$agentbadge_options = array(
	'agentbadge_installed_at',
	'agentbadge_version',
	'agentbadge_site_key',        // credential — must not survive uninstall.
	'agentbadge_site_id',
	'agentbadge_consents',
	'agentbadge_last_report',
	'agentbadge_last_report_at',
	'agentbadge_last_scan',
	'agentbadge_status_snapshot',
	'agentbadge_visit_check_at',
	'agentbadge_connected',
	'agentbadge_keyset',
	'agentbadge_root_pubkey',     // TOFU pin — forget on uninstall.
	'agentbadge_pull_nonces',
	'agentbadge_report_state',
);

foreach ( $agentbadge_options as $agentbadge_option ) {
	delete_option( $agentbadge_option );
}

wp_clear_scheduled_hook( 'agentbadge_job_pull' );
wp_clear_scheduled_hook( 'agentbadge_job_pull_once' );
wp_clear_scheduled_hook( 'agentbadge_self_report' );
wp_clear_scheduled_hook( 'agentbadge_self_report_once' );

// Drop the capability we granted at activation.
$agentbadge_admin = get_role( 'administrator' );
if ( $agentbadge_admin instanceof WP_Role ) {
	$agentbadge_admin->remove_cap( 'agentbadge_view' );
}
