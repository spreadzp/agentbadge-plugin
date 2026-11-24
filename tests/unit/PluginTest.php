<?php
/**
 * Smoke tests for the plugin bootstrap.
 *
 * @package agentbadge
 */

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

require_once __DIR__ . '/TestCase.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-agentbadge-plugin.php';

/**
 * Agentbadge_Plugin wiring.
 */
final class PluginTest extends TestCase {

	/**
	 * Constants are defined with expected values.
	 */
	public function test_plugin_file_defines_constants(): void {
		Functions\expect( 'plugin_dir_path' )->andReturn( dirname( __DIR__, 2 ) . '/' );
		Functions\expect( 'plugin_dir_url' )->andReturn( 'https://example.test/plugin/' );
		Functions\expect( 'register_activation_hook' )->zeroOrMoreTimes();
		Functions\expect( 'register_deactivation_hook' )->zeroOrMoreTimes();
		Actions\expectAdded( 'plugins_loaded' )->zeroOrMoreTimes();

		require dirname( __DIR__, 2 ) . '/agentbadge.php';

		$this->assertSame( '0.1.0', AGENTBADGE_VERSION );
		$this->assertSame( '1.0', AGENTBADGE_PROTOCOL_VERSION );
	}

	/**
	 * Singleton boots once and registers admin menu hook.
	 */
	public function test_instance_registers_admin_menu_hook(): void {
		Actions\expectAdded( 'admin_menu' )->once();
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->justReturn( true );

		$plugin = Agentbadge_Plugin::instance();
		$this->assertInstanceOf( Agentbadge_Plugin::class, $plugin );
		$this->assertSame( $plugin, Agentbadge_Plugin::instance() );
	}

	/**
	 * Admin page denies users without capability.
	 */
	public function test_admin_page_denies_without_capability(): void {
		Functions\expect( 'current_user_can' )->twice()->andReturn( false );
		Functions\expect( 'wp_die' )->once()->andThrow( new RuntimeException( 'denied' ) );
		Functions\when( 'esc_html__' )->returnArg( 1 );

		$this->expectException( RuntimeException::class );
		( new Agentbadge_Plugin() )->render_admin_page();
	}

	/**
	 * Activation seeds installed_at once.
	 */
	public function test_activate_seeds_option_once(): void {
		Functions\expect( 'get_option' )->with( 'agentbadge_installed_at' )->andReturn( false );
		Functions\expect( 'add_option' )
			->once()
			->with( 'agentbadge_installed_at', \Mockery::type( 'string' ), '', false );

		Agentbadge_Plugin::activate();
	}
}
