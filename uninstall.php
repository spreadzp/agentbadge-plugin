<?php
/**
 * Uninstall — remove plugin options. Never leaves secrets behind.
 *
 * @package agentbadge
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Options owned by the plugin (site_key, consents, cached reports).
$agentbadge_options = array(
	'agentbadge_installed_at',
	'agentbadge_version',
	'agentbadge_site_key',       // credential — must not survive uninstall.
	'agentbadge_site_id',
	'agentbadge_consents',
	'agentbadge_last_report',
);

foreach ( $agentbadge_options as $agentbadge_option ) {
	delete_option( $agentbadge_option );
}

wp_clear_scheduled_hook( 'agentbadge_job_pull' );
