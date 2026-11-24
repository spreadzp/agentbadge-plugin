<?php
/**
 * SLICE-262-33 — upgrade/rollback surface tests: the version marker
 * written on activate() is what future migrations key off; the
 * option lifecycle must be honest across activate → upgrade →
 * uninstall (credentials and markers never left behind).
 *
 * @package agentbadge
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-agentbadge-plugin.php';

/**
 * Upgrade-path coverage.
 */
class UpgradeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		__ab_reset_options();
	}

	public function test_activate_writes_version_marker(): void {
		Agentbadge_Plugin::activate();
		$this->assertSame( AGENTBADGE_VERSION, get_option( 'agentbadge_version' ) );
		$this->assertNotFalse( get_option( 'agentbadge_installed_at' ) );
	}

	/** Old install (0.0.x marker) → upgrade keeps identity, bumps marker. */
	public function test_upgrade_preserves_installed_at_bumps_version(): void {
		add_option( 'agentbadge_installed_at', '2025-01-01T00:00:00+00:00' );
		add_option( 'agentbadge_version', '0.0.9' );

		Agentbadge_Plugin::activate();

		$this->assertSame( '2025-01-01T00:00:00+00:00', get_option( 'agentbadge_installed_at' ) );
		$this->assertSame( AGENTBADGE_VERSION, get_option( 'agentbadge_version' ) );
	}

	public function test_activate_idempotent(): void {
		Agentbadge_Plugin::activate();
		$first = get_option( 'agentbadge_installed_at' );
		Agentbadge_Plugin::activate();
		$this->assertSame( $first, get_option( 'agentbadge_installed_at' ) );
	}

	/** Uninstall removes every plugin-owned option — rollback hygiene. */
	public function test_uninstall_clears_all_plugin_options(): void {
		foreach ( array(
			'agentbadge_installed_at', 'agentbadge_version',
			'agentbadge_site_key', 'agentbadge_site_id',
			'agentbadge_consents', 'agentbadge_last_report',
		) as $opt ) {
			update_option( $opt, 'x' );
		}
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'agentbadge/agentbadge.php' );
		}
		include dirname( __DIR__, 2 ) . '/uninstall.php';
		foreach ( array(
			'agentbadge_installed_at', 'agentbadge_version',
			'agentbadge_site_key', 'agentbadge_site_id',
			'agentbadge_consents', 'agentbadge_last_report',
		) as $opt ) {
			$this->assertFalse( get_option( $opt, false ), "$opt survived uninstall" );
		}
	}
}
