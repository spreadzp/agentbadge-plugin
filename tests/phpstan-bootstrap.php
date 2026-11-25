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

// Pure-function shim for tests (Brain Monkey doesn't stub it
// automatically; production WP provides the real one).
if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * @param string|array $value Value to unslash.
	 * @return string|array
	 */
	function wp_unslash( $value ) {
		return is_array( $value )
			? array_map( 'wp_unslash', $value )
			: stripslashes( (string) $value );
	}
}
