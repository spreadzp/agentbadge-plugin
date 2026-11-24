<?php
/**
 * E2E bootstrap (SLICE-262-18): WP stubs + plugin classes + harness
 * constants from the fake backend's boot output (FAKE_ROOT_PUB).
 * Constants must be defined BEFORE any plugin class loads — Env
 * reads them lazily but Key_Store resolves once.
 *
 * @package agentbadge
 */

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-stubs.php';

foreach ( array( 'AGENTBADGE_API_BASE', 'AGENTBADGE_ROOT_PUBKEY', 'AGENTBADGE_SITE_KEY' ) as $const ) {
	$env_val = getenv( 'E2E_' . $const );
	if ( is_string( $env_val ) && '' !== $env_val && ! defined( $const ) ) {
		define( $const, $env_val );
	}
}
if ( ! defined( 'AGENTBADGE_INSECURE_DEV' ) ) {
	define( 'AGENTBADGE_INSECURE_DEV', true ); // harness-only hatch — fake backend is http://
}
if ( ! defined( 'AGENTBADGE_VERSION' ) ) {
	define( 'AGENTBADGE_VERSION', 'e2e' );
}
