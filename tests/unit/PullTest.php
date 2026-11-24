<?php
/**
 * Diagnostic pull runtime (SLICE-262-16) — executor, nonce store,
 * keyset verify, and the runner's fail-closed pipeline including
 * gate G2 (stale/untrusted trust material cannot execute).
 *
 * @package agentbadge
 */

use Brain\Monkey\Functions;

require_once __DIR__ . '/TestCase.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-agentbadge-env.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-agentbadge-check-result.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-agentbadge-jcs.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-agentbadge-key-store.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-agentbadge-cloud.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-agentbadge-keyset.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-agentbadge-nonce-store.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-agentbadge-pull-executor.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-agentbadge-reporter.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-agentbadge-pull-runner.php';
require_once __DIR__ . '/ScannerTest.php'; // Stub_Env

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/fake-wp/' );
}

/** Registry mirror for tests — the contract fields the executor reads. */
function pull_test_registry(): array {
	return array(
		'const_whitelist'  => array( 'WP_DEBUG', 'DISALLOW_FILE_MODS' ),
		'option_whitelist' => array(
			'blog_public'  => array( 'type' => 'integer', 'enum' => array( 0, 1 ) ),
			'siteurl'      => array( 'type' => 'reduced', 'emit' => 'siteurl_https' ),
			'default_role' => array( 'type' => 'enum', 'values' => array( 'subscriber', 'administrator' ) ),
		),
		'capabilities'     => array(
			array( 'probe_id' => 'const_read' ),
			array( 'probe_id' => 'option_read' ),
			array( 'probe_id' => 'phpinfo_summary' ),
		),
	);
}

/** Fake keyset — returns a programmable pubkey per key_id. */
class Fake_Keyset extends Agentbadge_Keyset {
	public ?string $pub = null;
	public function __construct() {}
	public function jobs_pubkey( string $key_id, int $now ): ?string {
		return $this->pub;
	}
	public function trusted_keyset( int $now ): ?array {
		return null === $this->pub ? null : array( 'root_id' => 'r', 'keysets' => array() );
	}
}

/** Fake cloud — programmable peek/claim/result + call log. */
class Fake_Pull_Cloud extends Agentbadge_Cloud {
	public array $peek_jobs = array();
	public int $peek_status = 200;
	public int $claim_status = 200;
	public int $result_status = 201;
	public array $status_body = array();
	public int $status_status = 404;
	public array $calls = array();
	public function __construct() {}
	public function status_snapshot( string $site_key ): array {
		$this->calls[] = 'status';
		return array( 'status' => $this->status_status, 'body' => $this->status_body, 'error' => null );
	}
	public function jobs_peek( string $site_key, string $site_id ): array {
		$this->calls[] = 'peek';
		return array( 'status' => $this->peek_status, 'body' => array( 'jobs' => $this->peek_jobs ), 'error' => null );
	}
	public function job_claim( string $site_key, string $job_id ): array {
		$this->calls[] = 'claim:' . $job_id;
		return array(
			'status' => $this->claim_status,
			'body'   => 200 === $this->claim_status
				? array( 'lease_token' => 'lt_test', 'lease_generation' => 1 )
				: array( 'error' => 'denied' ),
			'error'  => null,
		);
	}
	public function job_result( string $site_key, string $job_id, array $body ): array {
		$this->calls[] = 'result:' . $job_id;
		return array( 'status' => $this->result_status, 'body' => array(), 'error' => null );
	}
}

/** Sign a wire job the way the server does (jobs sub-key). */
function sign_wire_job( array $body, string $jobs_seed ): array {
	$kp               = sodium_crypto_sign_seed_keypair( $jobs_seed );
	$body['sig']      = array(
		'algorithm' => 'ed25519',
		'value'     => base64_encode( sodium_crypto_sign_detached( Agentbadge_Jcs::encode( $body ), sodium_crypto_sign_secretkey( $kp ) ) ),
	);
	$body['status']   = 'queued';
	$body['lease_generation'] = 1;
	$body['lease_expires_at'] = null;
	$body['created_at']       = '2026-11-24T12:00:00Z';
	return $body;
}

function wire_job_body( array $over = array() ): array {
	return array_merge(
		array(
			'job_id'   => 'pjob_1',
			'site_id'  => 'site_t',
			'probe_id' => 'const_read',
			'params'   => array( 'name' => 'WP_DEBUG' ),
			'nonce'    => 'n_1',
			'exp'      => '2030-01-01T00:00:00Z',
			'key_id'   => 'intel-jobs-v1',
		),
		$over
	);
}

final class PullTest extends TestCase {

	private Stub_Env $env;
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		$this->env     = new Stub_Env();
		$this->options = array();
		$opts          = &$this->options;
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) use ( &$opts ) {
				return $opts[ $name ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) use ( &$opts ) {
				$opts[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) use ( &$opts ) {
				unset( $opts[ $name ] );
				return true;
			}
		);
	}

	private function connect(): void {
		$this->options['agentbadge_site_key']  = 'absk_0123456789abcdefXX';
		$this->options['agentbadge_connected'] = array( 'site_id' => 'site_t', 'connected_at' => 'x' );
	}

	private function runner( Fake_Pull_Cloud $cloud, Fake_Keyset $ks, Agentbadge_Nonce_Store $nonces, Agentbadge_Pull_Executor $ex ): Agentbadge_Pull_Runner {
		return new Agentbadge_Pull_Runner(
			$this->env,
			new Agentbadge_Key_Store( $this->env ),
			$cloud,
			$ks,
			$nonces,
			$ex
		);
	}

	// ── JCS ──

	public function test_jcs_canonicalizes_key_order_and_escapes(): void {
		$doc = array( 'b' => 1, 'a' => "q\"x\n", 'c' => array( 'z' => true, 'y' => null ) );
		$this->assertSame(
			'{"a":"q\\"x\\u000a","b":1,"c":{"y":null,"z":true}}',
			Agentbadge_Jcs::encode( $doc )
		);
		$this->assertSame( '[1,"x",false]', Agentbadge_Jcs::encode( array( 1, 'x', false ) ) );
	}

	// ── executor ──

	public function test_executor_const_read(): void {
		$ex = new Agentbadge_Pull_Executor( $this->env, pull_test_registry() );
		$this->env->constants['WP_DEBUG'] = true;
		$this->assertSame(
			array( 'name' => 'WP_DEBUG', 'defined' => true, 'value' => true ),
			$ex->execute( 'const_read', array( 'name' => 'WP_DEBUG' ) )
		);
		$this->assertSame(
			array( 'name' => 'DISALLOW_FILE_MODS', 'defined' => false, 'value' => null ),
			$ex->execute( 'const_read', array( 'name' => 'DISALLOW_FILE_MODS' ) )
		);
	}

	public function test_executor_option_read_reduced_and_enum(): void {
		$ex = new Agentbadge_Pull_Executor( $this->env, pull_test_registry() );
		$this->env->options['siteurl'] = 'https://example.com';
		$r = $ex->execute( 'option_read', array( 'name' => 'siteurl' ) );
		$this->assertTrue( $r['present'] );
		$this->assertTrue( $r['siteurl_https'] );
		$this->assertNull( $r['value'] ); // URL never leaves the box
		$this->assertArrayNotHasKey( 'home_https', $r );

		$this->env->options['default_role'] = 'editor'; // out-of-enum → redacted
		$r = $ex->execute( 'option_read', array( 'name' => 'default_role' ) );
		$this->assertNull( $r['value'] );
	}

	public function test_executor_refuses_off_whitelist(): void {
		$ex = new Agentbadge_Pull_Executor( $this->env, pull_test_registry() );
		$this->assertNull( $ex->execute( 'const_read', array( 'name' => 'DB_PASSWORD' ) ) );
		$this->assertNull( $ex->execute( 'option_read', array( 'name' => 'admin_email' ) ) );
		$this->assertNull( $ex->execute( 'plugin_list', array() ) );
		$this->assertNull( $ex->execute( 'phpinfo_summary', array( 'evil' => 1 ) ) );
	}

	// ── nonce store ──

	public function test_nonce_store_marks_terminal(): void {
		$n = new Agentbadge_Nonce_Store();
		$this->assertFalse( $n->seen( 'n_1' ) );
		$n->mark( 'n_1', time() );
		$this->assertTrue( $n->seen( 'n_1' ) );
	}

	// ── runner pipeline ──

	private function seed_jobs_key(): array {
		$seed      = str_repeat( "\x07", 32 );
		$jobs_seed = hash( 'sha256', 'intel:jobs' . $seed, true );
		$kp        = sodium_crypto_sign_seed_keypair( $jobs_seed );
		return array( $jobs_seed, base64_encode( sodium_crypto_sign_publickey( $kp ) ) );
	}

	public function test_run_executes_signed_job_end_to_end(): void {
		$this->connect();
		list( $jobs_seed, $pub ) = $this->seed_jobs_key();
		$cloud  = new Fake_Pull_Cloud();
		$cloud->peek_jobs = array( sign_wire_job( wire_job_body(), $jobs_seed ) );
		$ks     = new Fake_Keyset();
		$ks->pub = $pub;
		$nonces = new Agentbadge_Nonce_Store();
		$ex     = new Agentbadge_Pull_Executor( $this->env, pull_test_registry() );
		$this->env->constants['WP_DEBUG'] = true;

		$r = $this->runner( $cloud, $ks, $nonces, $ex )->run();
		$this->assertSame( 1, $r['done'] );
		$this->assertSame( array( 'status', 'peek', 'claim:pjob_1', 'result:pjob_1' ), $cloud->calls );
		$this->assertTrue( $nonces->seen( 'n_1' ) );
	}

	public function test_run_skips_forged_signature(): void {
		$this->connect();
		list( $jobs_seed ) = $this->seed_jobs_key();
		$cloud  = new Fake_Pull_Cloud();
		// signed by an UNRELATED key — keyset pub doesn't match
		$cloud->peek_jobs = array( sign_wire_job( wire_job_body(), str_repeat( "\x09", 32 ) ) );
		$ks     = new Fake_Keyset();
		$ks->pub = base64_encode( sodium_crypto_sign_publickey( sodium_crypto_sign_seed_keypair( $jobs_seed ) ) );
		$r = $this->runner( $cloud, $ks, new Agentbadge_Nonce_Store(), new Agentbadge_Pull_Executor( $this->env, pull_test_registry() ) )->run();
		$this->assertSame( 1, $r['skipped'] );
		$this->assertSame( array( 'status', 'peek' ), $cloud->calls ); // never claimed
	}

	public function test_run_skips_foreign_site_and_expired_and_seen_nonce(): void {
		$this->connect();
		list( $jobs_seed, $pub ) = $this->seed_jobs_key();
		$nonces = new Agentbadge_Nonce_Store();
		$nonces->mark( 'n_seen', time() );
		$cloud  = new Fake_Pull_Cloud();
		$cloud->peek_jobs = array(
			sign_wire_job( wire_job_body( array( 'job_id' => 'pjob_a', 'site_id' => 'site_OTHER' ) ), $jobs_seed ),
			sign_wire_job( wire_job_body( array( 'job_id' => 'pjob_b', 'exp' => '2000-01-01T00:00:00Z' ) ), $jobs_seed ),
			sign_wire_job( wire_job_body( array( 'job_id' => 'pjob_c', 'nonce' => 'n_seen' ) ), $jobs_seed ),
			sign_wire_job( wire_job_body( array( 'job_id' => 'pjob_d', 'params' => array( 'name' => 'DB_PASSWORD' ) ) ), $jobs_seed ),
		);
		$ks      = new Fake_Keyset();
		$ks->pub = $pub;
		$r       = $this->runner( $cloud, $ks, $nonces, new Agentbadge_Pull_Executor( $this->env, pull_test_registry() ) )->run();
		$this->assertSame( 4, $r['skipped'] );
		$this->assertSame( array( 'status', 'peek' ), $cloud->calls );
	}

	public function test_run_aborts_on_consent_403_and_marks_nonce_on_409(): void {
		$this->connect();
		list( $jobs_seed, $pub ) = $this->seed_jobs_key();
		$ks      = new Fake_Keyset();
		$ks->pub = $pub;
		$ex      = new Agentbadge_Pull_Executor( $this->env, pull_test_registry() );

		// 403 at claim → abort, second job never polled further
		$cloud              = new Fake_Pull_Cloud();
		$cloud->peek_jobs   = array(
			sign_wire_job( wire_job_body(), $jobs_seed ),
			sign_wire_job( wire_job_body( array( 'job_id' => 'pjob_2', 'nonce' => 'n_2' ) ), $jobs_seed ),
		);
		$cloud->claim_status = 403;
		$nonces              = new Agentbadge_Nonce_Store();
		$r = $this->runner( $cloud, $ks, $nonces, $ex )->run();
		$this->assertSame( 'aborted', $r['reason'] );
		$this->assertSame( array( 'status', 'peek', 'claim:pjob_1' ), $cloud->calls );

		// 409 at result → terminal for the nonce, run continues
		$cloud               = new Fake_Pull_Cloud();
		$cloud->peek_jobs    = array( sign_wire_job( wire_job_body(), $jobs_seed ) );
		$cloud->result_status = 409;
		$nonces              = new Agentbadge_Nonce_Store();
		$r = $this->runner( $cloud, $ks, $nonces, $ex )->run();
		$this->assertSame( 1, $r['skipped'] );
		$this->assertTrue( $nonces->seen( 'n_1' ) );
	}

	public function test_run_not_connected_and_peek_failure(): void {
		$cloud = new Fake_Pull_Cloud();
		$ks    = new Fake_Keyset();
		$ex    = new Agentbadge_Pull_Executor( $this->env, pull_test_registry() );
		$this->assertSame( 'not_connected', $this->runner( $cloud, $ks, new Agentbadge_Nonce_Store(), $ex )->run()['reason'] );

		$this->connect();
		$cloud->peek_status = 401;
		$this->assertSame( 'peek_failed', $this->runner( $cloud, $ks, new Agentbadge_Nonce_Store(), $ex )->run()['reason'] );
	}

	public function test_run_refreshes_status_snapshot_option(): void {
		$this->connect();
		$cloud                = new Fake_Pull_Cloud();
		$cloud->status_status = 200;
		$cloud->status_body   = array(
			'site_id'     => 'site_t',
			'snapshot_at' => '2026-11-24T12:00:00Z',
			'attestation' => array( 'attestation_id' => 'att_1', 'effective_status' => 'active' ),
			'report'      => null,
		);
		$this->runner( $cloud, new Fake_Keyset(), new Agentbadge_Nonce_Store(), new Agentbadge_Pull_Executor( $this->env, pull_test_registry() ) )->run();
		$this->assertSame( $cloud->status_body, $this->options['agentbadge_status_snapshot'] ?? null );

		// Non-200 or malformed body leaves the stored snapshot untouched.
		$cloud->status_status = 500;
		$this->options['agentbadge_status_snapshot'] = 'stale-value';
		$this->runner( $cloud, new Fake_Keyset(), new Agentbadge_Nonce_Store(), new Agentbadge_Pull_Executor( $this->env, pull_test_registry() ) )->run();
		$this->assertSame( 'stale-value', $this->options['agentbadge_status_snapshot'] );
	}
}
