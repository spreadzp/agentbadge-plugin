<?php
/**
 * Plugin Name: AgentBadge — Agent Readiness
 * Description: Measures how ready your site is for AI agents — discoverability checks (llms.txt, robots, sitemaps, .well-known) plus agent-surface exposure checks. Free local scanner; optional AgentBadge Cloud for independent external verification.
 * Version:     0.1.0
 * Author:      AgentBadge
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: agentbadge
 * Requires PHP: 8.1
 * Requires at least: 6.4
 *
 * @package agentbadge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'AGENTBADGE_VERSION' ) ) {
	define( 'AGENTBADGE_VERSION', '0.1.0' );
}
if ( ! defined( 'AGENTBADGE_PATH' ) ) {
	define( 'AGENTBADGE_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'AGENTBADGE_URL' ) ) {
	define( 'AGENTBADGE_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'AGENTBADGE_FILE' ) ) {
	define( 'AGENTBADGE_FILE', __FILE__ );
}
if ( ! defined( 'AGENTBADGE_PROTOCOL_VERSION' ) ) {
	define( 'AGENTBADGE_PROTOCOL_VERSION', '1.0' );
}

require_once AGENTBADGE_PATH . 'includes/class-agentbadge-plugin.php';

register_activation_hook( __FILE__, array( 'Agentbadge_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Agentbadge_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Agentbadge_Plugin', 'boot' ) );
