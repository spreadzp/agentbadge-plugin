<?php
/**
 * Main plugin singleton.
 *
 * @package agentbadge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agentbadge_Plugin — bootstrap: hooks, subsystems.
 *
 * Subsystems (wired as slices land):
 * - Agentbadge_Scanner   — free local checks (SLICE-262-13)
 * - Agentbadge_Cloud     — site_key, self-report, job pull (262-14/16)
 * - Agentbadge_Admin     — wp-admin screens S1 (262-17)
 * - Agentbadge_Fixer     — preview/apply/verify (262-26)
 */
class Agentbadge_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Agentbadge_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Hook `plugins_loaded` entry point — callbacks must not return values.
	 *
	 * @return void
	 */
	public static function boot(): void {
		self::instance();
	}

	/**
	 * Get (and lazily boot) the singleton.
	 *
	 * @return Agentbadge_Plugin
	 */
	public static function instance(): Agentbadge_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register_hooks();
		}
		return self::$instance;
	}

	/**
	 * Register WordPress hooks. Kept separate for testability —
	 * a test can subclass and observe registrations via Brain Monkey.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
	}

	/**
	 * Register the wp-admin menu entry (S1 surface root).
	 * One entry point; tabs live inside (Overview/Findings/Fixes/Settings).
	 *
	 * @return void
	 */
	public function register_admin_menu(): void {
		add_menu_page(
			__( 'AgentBadge', 'agentbadge' ),
			__( 'AgentBadge', 'agentbadge' ),
			'agentbadge_view',
			'agentbadge',
			array( $this, 'render_admin_page' ),
			'dashicons-shield-alt',
			80
		);
	}

	/**
	 * Render the admin page shell. Replaced by real views in 262-17.
	 *
	 * @return void
	 */
	public function render_admin_page(): void {
		if ( ! current_user_can( 'agentbadge_view' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'agentbadge' ) );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'AgentBadge — Agent Readiness', 'agentbadge' ) . '</h1>';
		echo '<p>' . esc_html__( 'Local scanner and Cloud integration bootstrap.', 'agentbadge' ) . '</p></div>';
	}

	/**
	 * Activation: schedule nothing yet; seed default options.
	 *
	 * @return void
	 */
	public static function activate(): void {
		if ( false === get_option( 'agentbadge_installed_at' ) ) {
			add_option( 'agentbadge_installed_at', gmdate( 'c' ), '', false );
		}
	}

	/**
	 * Deactivation: unschedule cron (job pull lands in 262-16).
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'agentbadge_job_pull' );
	}
}
