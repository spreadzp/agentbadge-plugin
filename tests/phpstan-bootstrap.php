<?php
/**
 * PHPStan/test bootstrap — shims for symbols PHPStan/Brain Monkey
 * should know about. WP core symbols come from phpstan-wordpress;
 * here live only plugin-specific globals/constants shims.
 *
 * @package agentbadge
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', 'agentbadge/agentbadge.php' );
}
