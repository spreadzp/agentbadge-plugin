<?php
/**
 * EPIC-262 (SLICE-262-18): end-to-end pull-cycle tests against the
 * REAL fake backend — wire-level coverage of every fail-closed
 * branch ADR-INTEL-002 enumerates. Each scenario is seeded through
 * /__seed before Agentbadge_Pull_Runner::run() executes for real:
 * HTTP → keyset fetch+verify → signature verify → claim → execute
 * → result. The backend's /__calls log proves the plugin never
 * claimed/executed what it should have skipped (G2-class honesty).
 *
 * @package agentbadge
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-agentbadge-env.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-agentbadge-jcs.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-agentbadge-key-store.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-agentbadge-cloud.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-agentbadge-keyset.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-agentbadge-nonce-store.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-agentbadge-pull-executor.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-agentbadge-reporter.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-agentbadge-pull-runner.php';

/**
 * Pull-runner e2e vs fake backend.
 */
class E2eTest extends TestCase {

	/**
	 * Runner under test.
	 *
	 * @var Agentbadge_Pull_Runner
	 */
	private Agentbadge_Pull_Runner $runner;

	/**
	 * Site key the fake authenticates.
	 */
	private const SITE_KEY = 'absk_fakesite0000000000000000000001';
	/**
	 * Its site id.
	 */
	private const SITE_ID = 'site_fake1';

	/**
	 * Reset fake state + options, connect the site, build the runner.
	 */
	protected function setUp(): void {
		parent::setUp();
		__ab_reset_options();
		$this->fake( '/__reset', array() );

		// site is connected (Reporter reads the option)
		update_option(
			Agentbadge_Reporter::CONNECT_OPTION,
			array( 'site_id' => self::SITE_ID, 'connected_at' => gmdate( 'c' ) ),
			false
		);
		// key store holds the site key (constant wins, but the option
		// path must also work — set it explicitly)
		update_option( Agentbadge_Key_Store::OPTION, self::SITE_KEY, false );

		$env = new Agentbadge_Env();
		$this->runner = new Agentbadge_Pull_Runner(
			$env,
			new Agentbadge_Key_Store( $env ),
			new Agentbadge_Cloud( $env ),
			new Agentbadge_Keyset( $env ),
			new Agentbadge_Nonce_Store(),
			new Agentbadge_Pull_Executor( $env )
		);
	}

	/**
	 * Fake-backend control call.
	 *
	 * @param string $path Path.
	 * @param array  $body Body.
	 * @return array Decoded.
	 */
	private function fake( string $path, array $body ): array {
		$base = getenv( 'E2E_AGENTBADGE_API_BASE' );
		$ctx  = stream_context_create(
			array(
				'http' => array(
					'method'        => 'POST',
					'header'        => "Content-Type: application/json",
					'content'       => json_encode( $body ),
					'ignore_errors' => true,
				),
			)
		);
		$res = @file_get_contents( $base . $path, false, $ctx ); // phpcs:ignore
		return is_string( $res ) ? (array) json_decode( $res, true ) : array();
	}

	/**
	 * Fake call log.
	 *
	 * @return array
	 */
	private function calls(): array {
		$base = getenv( 'E2E_AGENTBADGE_API_BASE' );
		$res  = @file_get_contents( $base . '/__calls' ); // phpcs:ignore
		$doc  = is_string( $res ) ? (array) json_decode( $res, true ) : array();
		return (array) ( $doc['calls'] ?? array() );
	}

	/**
	 * Count backend calls matching a path substring.
	 *
	 * @param string $needle Path substring.
	 * @return int
	 */
	private function call_count( string $needle ): int {
		$n = 0;
		foreach ( $this->calls() as $c ) {
			if ( str_contains( (string) ( $c['path'] ?? '' ), $needle ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Seed one pull job with overrides.
	 *
	 * @param array $over Seed overrides.
	 */
	private function seed_job( array $over = array() ): void {
		$this->fake( '/__seed', array( 'jobs' => array( $over ) ) );
	}

	/** Happy path: peek → claim → execute → result 201 → done:1. */
	public function test_happy_path_pull_cycle(): void {
		$this->seed_job( array( 'probe_id' => 'const_read', 'params' => array( 'name' => 'WP_DEBUG' ) ) );
		$res = $this->runner->run();
		$this->assertSame( 1, $res['polled'] );
		$this->assertSame( 1, $res['done'] );
		$this->assertSame( 0, $res['skipped'] );
		$this->assertSame( 1, $this->call_count( '/claim' ) );
		$this->assertSame( 1, $this->call_count( '/result' ) );
	}

	/** Expired job → skipped, backend never sees a claim (G2-class). */
	public function test_expired_job_never_claimed(): void {
		$this->seed_job( array( 'exp' => '2020-01-01T00:00:00Z' ) );
		$res = $this->runner->run();
		$this->assertSame( 1, $res['skipped'] );
		$this->assertSame( 0, $this->call_count( '/claim' ) );
	}

	/** Bad signature → skipped before claim (forge-proof). */
	public function test_bad_signature_never_claimed(): void {
		$this->seed_job( array( 'sig' => 'bad' ) );
		$res = $this->runner->run();
		$this->assertSame( 1, $res['skipped'] );
		$this->assertSame( 0, $this->call_count( '/claim' ) );
	}

	/** Rogue-key signature (valid ed25519, wrong key) → skipped. */
	public function test_rogue_key_signature_never_claimed(): void {
		$this->seed_job( array( 'sig' => 'rogue' ) );
		$res = $this->runner->run();
		$this->assertSame( 1, $res['skipped'] );
		$this->assertSame( 0, $this->call_count( '/claim' ) );
	}

	/** Unknown key_id → skipped (keyset has no entry). */
	public function test_unknown_key_id_never_claimed(): void {
		$this->seed_job( array( 'key_id' => 'no-such-key' ) );
		$res = $this->runner->run();
		$this->assertSame( 1, $res['skipped'] );
		$this->assertSame( 0, $this->call_count( '/claim' ) );
	}

	/** Off-contract probe_id → skipped before claim. */
	public function test_unknown_probe_never_claimed(): void {
		$this->seed_job( array( 'probe_id' => 'shell_exec' ) );
		$res = $this->runner->run();
		$this->assertSame( 1, $res['skipped'] );
		$this->assertSame( 0, $this->call_count( '/claim' ) );
	}

	/** Foreign site_id → skipped even when the backend leaks it
	 *  through peek (defense-in-depth: plugin rechecks site_id). */
	public function test_foreign_site_never_claimed(): void {
		$this->seed_job( array( 'site_id' => 'site_other', 'leak' => true ) );
		$res = $this->runner->run();
		$this->assertSame( 1, $res['skipped'] );
		$this->assertSame( 0, $this->call_count( '/claim' ) );
	}

	/** Claim 403 (consent revoked) → aborts the whole run. */
	public function test_consent_revoked_aborts_run(): void {
		$this->seed_job( array( 'claimScenario' => 'consent_denied' ) );
		$this->seed_job( array() ); // second job must never be reached
		$res = $this->runner->run();
		$this->assertSame( 'aborted', $res['reason'] );
		$this->assertSame( 1, $this->call_count( '/claim' ) ); // stopped after first
		$this->assertSame( 0, $this->call_count( '/result' ) );
	}

	/** Result 409 (stale lease/conflict) → terminal skip, nonce marked
	 *  so a re-poll does not re-execute. */
	public function test_result_conflict_terminal_skip(): void {
		$this->seed_job( array( 'resultScenario' => 'conflict', 'nonce' => 'n_seen_1' ) );
		$res = $this->runner->run();
		$this->assertSame( 1, $res['skipped'] );
		$this->assertSame( 1, $this->call_count( '/result' ) );
		// a NEW job replaying the SAME nonce → seen → skip before claim
		$this->seed_job( array( 'nonce' => 'n_seen_1' ) );
		$this->runner->run();
		$this->assertSame( 1, $this->call_count( '/claim' ) ); // no second claim
	}

	/** No connection state → honest not_connected, zero wire calls. */
	public function test_disconnected_site_short_circuits(): void {
		delete_option( Agentbadge_Reporter::CONNECT_OPTION );
		$res = $this->runner->run();
		$this->assertSame( 'not_connected', $res['reason'] );
		$this->assertSame( 0, $this->call_count( '/jobs' ) );
	}
}
