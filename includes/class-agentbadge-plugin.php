<?php
/**
 * Main plugin singleton.
 *
 * @package agentbadge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-agentbadge-env.php';
require_once __DIR__ . '/class-agentbadge-check-result.php';
require_once __DIR__ . '/class-agentbadge-scanner.php';

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
		add_action( 'admin_post_agentbadge_scan', array( $this, 'handle_scan' ) );
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
	 * Render the admin page (S1): Scan tab runs the free local
	 * scanner, Cloud tab is an honest stub until 262-14.
	 *
	 * @return void
	 */
	public function render_admin_page(): void {
		if ( ! current_user_can( 'agentbadge_view' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'agentbadge' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab param
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'scan';
		$view = array(
			'tab'  => in_array( $tab, array( 'scan', 'cloud' ), true ) ? $tab : 'scan',
			'scan' => get_option( 'agentbadge_last_scan', null ),
		);
		require dirname( __DIR__ ) . '/templates/admin-page.php';
	}

	/**
	 * Admin-post handler: run the local scan, persist, redirect.
	 * No JS dependency — plain POST + nonce + capability.
	 *
	 * @return void
	 */
	public function handle_scan(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'agentbadge' ) );
		}
		check_admin_referer( 'agentbadge_scan' );
		$scanner = new Agentbadge_Scanner();
		$results = array();
		foreach ( $scanner->run() as $r ) {
			$results[] = $r->to_array();
		}
		update_option(
			'agentbadge_last_scan',
			array(
				'scanned_at' => gmdate( 'c' ),
				'results'    => $results,
			),
			false
		);
		wp_safe_redirect( admin_url( 'admin.php?page=agentbadge&tab=scan' ) );
		exit;
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
