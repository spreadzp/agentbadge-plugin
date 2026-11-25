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
require_once __DIR__ . '/class-agentbadge-key-store.php';
require_once __DIR__ . '/class-agentbadge-cloud.php';
require_once __DIR__ . '/class-agentbadge-reporter.php';
require_once __DIR__ . '/class-agentbadge-jcs.php';
require_once __DIR__ . '/class-agentbadge-keyset.php';
require_once __DIR__ . '/class-agentbadge-nonce-store.php';
require_once __DIR__ . '/class-agentbadge-pull-executor.php';
require_once __DIR__ . '/class-agentbadge-pull-runner.php';

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
		add_action( 'admin_post_agentbadge_connect', array( $this, 'handle_connect' ) );
		add_action( 'admin_post_agentbadge_disconnect', array( $this, 'handle_disconnect' ) );
		add_action( 'admin_post_agentbadge_report_now', array( $this, 'handle_report_now' ) );
		add_action( 'agentbadge_self_report', array( $this, 'cron_self_report' ) );
		add_action( 'agentbadge_self_report_once', array( $this, 'cron_self_report_once' ) );
		add_action( 'agentbadge_job_pull', array( $this, 'cron_job_pull' ) );
		add_action( 'agentbadge_job_pull_once', array( $this, 'cron_job_pull_once' ) );
		add_action( 'admin_init', array( $this, 'visit_trigger' ) );
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.WP.CronInterval.CronSchedulesInterval -- core filter; 10-min cadence is the documented D-262-Q27 poll interval
		if ( ! wp_next_scheduled( 'agentbadge_self_report' ) ) {
			wp_schedule_event( time() + 300, 'daily', 'agentbadge_self_report' );
		}
		if ( ! wp_next_scheduled( 'agentbadge_job_pull' ) ) {
			wp_schedule_event( time() + 120, 'agentbadge_ten_minutes', 'agentbadge_job_pull' );
		}
	}

	/**
	 * Custom cron interval — 10 minutes (D-262-Q27 poll cadence).
	 *
	 * @param array $schedules Core schedules.
	 * @return array
	 */
	public function cron_schedules( array $schedules ): array {
		$schedules['agentbadge_ten_minutes'] = array(
			// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- D-262-Q27 poll cadence; 10 min is the documented job-pull interval
			'interval' => 600,
			'display'  => 'Every Ten Minutes (AgentBadge)',
		);
		return $schedules;
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
		$keys = new Agentbadge_Key_Store();
		$view = array(
			'tab'   => in_array( $tab, array( 'scan', 'cloud' ), true ) ? $tab : 'scan',
			'scan'  => get_option( 'agentbadge_last_scan', null ),
			'cloud' => array(
				'key'         => $keys->resolve(),
				'connected'   => ( new Agentbadge_Reporter() )->connected(),
				'last_report' => get_option( 'agentbadge_last_report_at', null ),
				'facts'       => array_keys( Agentbadge_Reporter::fact_readers() ),
				'flash'       => isset( $_GET['ab'] ) ? sanitize_key( wp_unslash( $_GET['ab'] ) ) : null, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash
			),
		);
		require dirname( __DIR__ ) . '/templates/admin-page.php';
	}

	/**
	 * Connect: handshake with the pasted site_key, store it on
	 * success, then send the baseline self-report.
	 *
	 * @return void
	 */
	public function handle_connect(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'agentbadge' ) );
		}
		check_admin_referer( 'agentbadge_connect' );
		$site_key = isset( $_POST['site_key'] ) ? sanitize_text_field( wp_unslash( $_POST['site_key'] ) ) : '';
		$flash    = 'bad_key';
		if ( str_starts_with( $site_key, Agentbadge_Key_Store::KEY_PREFIX ) ) {
			$r = ( new Agentbadge_Cloud() )->handshake( $site_key );
			if ( 200 === $r['status'] && isset( $r['body']['proof']['siteId'] ) ) {
				$keys = new Agentbadge_Key_Store();
				$keys->store( $site_key );
				$reporter = new Agentbadge_Reporter();
				$reporter->mark_connected( (string) $r['body']['proof']['siteId'] );
				$reporter->report( true ); // baseline.
				$flash = 'connected';
			} else {
				$flash = 'handshake_failed';
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=agentbadge&tab=cloud&ab=' . $flash ) );
		exit;
	}

	/**
	 * Disconnect: forget key + connection state.
	 *
	 * @return void
	 */
	public function handle_disconnect(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'agentbadge' ) );
		}
		check_admin_referer( 'agentbadge_disconnect' );
		( new Agentbadge_Key_Store() )->forget();
		( new Agentbadge_Reporter() )->disconnect();
		wp_safe_redirect( admin_url( 'admin.php?page=agentbadge&tab=cloud&ab=disconnected' ) );
		exit;
	}

	/**
	 * Manual self-report trigger.
	 *
	 * @return void
	 */
	public function handle_report_now(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'agentbadge' ) );
		}
		check_admin_referer( 'agentbadge_report_now' );
		( new Agentbadge_Reporter() )->report( true );
		wp_safe_redirect( admin_url( 'admin.php?page=agentbadge&tab=cloud&ab=reported' ) );
		exit;
	}

	/**
	 * Daily cron: periodic re-observe (D-262-Q33).
	 *
	 * @return void
	 */
	public function cron_self_report(): void {
		( new Agentbadge_Reporter() )->report( true );
	}

	/**
	 * Visit-triggered freshness: admin_init with a throttle —
	 * covers low-traffic sites where wp-cron never fires
	 * (D-262-Q33). Schedules a single immediate cron event so
	 * the HTTP call never blocks an admin page load.
	 *
	 * @return void
	 */
	public function visit_trigger(): void {
		$last = (int) get_option( 'agentbadge_visit_check_at', 0 );
		if ( time() - $last < 300 ) {
			return;
		}
		update_option( 'agentbadge_visit_check_at', time(), false );
		if ( ! wp_next_scheduled( 'agentbadge_self_report_once' ) ) {
			wp_schedule_single_event( time(), 'agentbadge_self_report_once' );
		}
		if ( ! wp_next_scheduled( 'agentbadge_job_pull_once' ) ) {
			wp_schedule_single_event( time() + 30, 'agentbadge_job_pull_once' );
		}
	}

	/**
	 * One-shot self-report for the visit trigger.
	 *
	 * @return void
	 */
	public function cron_self_report_once(): void {
		( new Agentbadge_Reporter() )->report( false );
	}

	/**
	 * Cron pull: bounded job processing — the runner's own
	 * peek→claim→execute→submit cycle is bounded by the backend's
	 * 90s scan window implicitly (jobs expire server-side).
	 *
	 * @return void
	 */
	public function cron_job_pull(): void {
		( new Agentbadge_Pull_Runner() )->run();
	}

	/**
	 * Visit-triggered pull — same cycle, once.
	 *
	 * @return void
	 */
	public function cron_job_pull_once(): void {
		( new Agentbadge_Pull_Runner() )->run();
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
		// Upgrade marker: stored code-version lets future migrations
		// detect "installed under X, now running Y" (SLICE-262-33).
		// Idempotent — re-activation on the same version is a no-op.
		if ( defined( 'AGENTBADGE_VERSION' ) ) {
			update_option( 'agentbadge_version', AGENTBADGE_VERSION, false );
		}
		// Grant the menu capability: add_menu_page() gates on
		// 'agentbadge_view', and nobody holds it until granted —
		// without this the admin page 403s for every role.
		$role = get_role( 'administrator' );
		if ( null !== $role ) {
			$role->add_cap( 'agentbadge_view' );
		}
	}

	/**
	 * Deactivation: unschedule cron (job pull lands in 262-16).
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'agentbadge_self_report' );
		wp_clear_scheduled_hook( 'agentbadge_self_report_once' );
		wp_clear_scheduled_hook( 'agentbadge_job_pull' );
		wp_clear_scheduled_hook( 'agentbadge_job_pull_once' );
		$role = get_role( 'administrator' );
		if ( null !== $role ) {
			$role->remove_cap( 'agentbadge_view' );
		}
	}
}
