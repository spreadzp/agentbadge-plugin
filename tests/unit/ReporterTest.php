<?php
/**
 * Key store + self-report emitter (SLICE-262-14).
 *
 * @package agentbadge
 */

use Brain\Monkey\Functions;

require_once __DIR__ . '/TestCase.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-agentbadge-env.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-agentbadge-check-result.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-agentbadge-key-store.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-agentbadge-cloud.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-agentbadge-reporter.php';
require_once __DIR__ . '/ScannerTest.php'; // Stub_Env

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/fake-wp/' );
}

/**
 * Fake cloud — records submissions, programmable statuses.
 */
class Fake_Cloud extends Agentbadge_Cloud {
	public array $sent = array();
	public int $status = 201;
	public function __construct() {}
	public function handshake( string $site_key ): array {
		return array( 'status' => 200, 'body' => array( 'proof' => array( 'siteId' => 'site_t' ) ), 'error' => null );
	}
	public function submit( string $site_key, array $submission ): array {
		$this->sent[] = $submission;
		return array( 'status' => $this->status, 'body' => array(), 'error' => null );
	}
}

final class ReporterTest extends TestCase {

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

	private function reporter( Fake_Cloud $cloud ): Agentbadge_Reporter {
		return new Agentbadge_Reporter( $this->env, new Agentbadge_Key_Store( $this->env ), $cloud );
	}

	// ── key store (D-262-Q19/Q30) ──

	public function test_key_resolution_option(): void {
		$this->options['agentbadge_site_key'] = 'absk_0123456789abcdefXX';
		$r = ( new Agentbadge_Key_Store( $this->env ) )->resolve();
		$this->assertSame( 'option', $r['source'] );
		$this->assertSame( 'ok', $r['status'] );
	}

	public function test_constant_wins_over_option(): void {
		$this->options['agentbadge_site_key'] = 'absk_opt_0123456789abcdef';
		$this->env->constants['AGENTBADGE_SITE_KEY'] = 'absk_const_0123456789abcdef';
		$r = ( new Agentbadge_Key_Store( $this->env ) )->resolve();
		$this->assertSame( 'constant', $r['source'] );
		$this->assertSame( 'absk_const_0123456789abcdef', $r['key'] );
		$this->assertTrue( $r['conflict'] ); // values differ → surfaced
		$this->assertSame( 'absk_opt_0123456789abcdef', $this->options['agentbadge_site_key'] ); // option NOT overwritten
	}

	public function test_empty_constant_is_error_not_fallback(): void {
		// D-262-Q30 — the security-grill point: invalid constant must
		// be loud, never a silent fallback to the stored option.
		$this->options['agentbadge_site_key'] = 'absk_opt_0123456789abcdef';
		$this->env->constants['AGENTBADGE_SITE_KEY'] = '';
		$r = ( new Agentbadge_Key_Store( $this->env ) )->resolve();
		$this->assertNull( $r['key'] );
		$this->assertSame( 'invalid_constant', $r['status'] );

		$this->env->constants['AGENTBADGE_SITE_KEY'] = 'not-a-key';
		$r = ( new Agentbadge_Key_Store( $this->env ) )->resolve();
		$this->assertSame( 'invalid_constant', $r['status'] );
	}

	public function test_missing_key(): void {
		$r = ( new Agentbadge_Key_Store( $this->env ) )->resolve();
		$this->assertSame( 'missing', $r['status'] );
		$this->assertNull( $r['key'] );
	}

	// ── submissions shape (contract) ──

	public function test_submissions_match_collector_schema(): void {
		$subs = Agentbadge_Reporter::submissions(
			array( 'WP_DEBUG' => true ),
			'site_t',
			'self_t',
			'2026-11-24T10:00:00Z'
		);
		$this->assertCount( 1, $subs );
		$s = $subs[0];
		$this->assertSame( 'wp_plugin@site_t', $s['collector_id'] ); // channel-derived id contract
		$this->assertSame( 'site_t', $s['site_ref'] );
		$this->assertSame( array( 'type' => 'wp_option', 'name' => 'WP_DEBUG' ), $s['subject'] );
		$this->assertSame( 'wp_option_read', $s['payload']['type'] );
		$this->assertSame( 'WP_DEBUG', $s['payload']['name'] );
		$this->assertTrue( $s['payload']['value'] );
		$this->assertSame( array(), $s['evidence_refs'] );
		// No trust fields may leak into the untrusted DTO.
		foreach ( array( 'provenance', 'received_at', 'signature', 'envelope_id', 'verifier' ) as $forbidden ) {
			$this->assertArrayNotHasKey( $forbidden, $s );
		}
	}

	public function test_fact_whitelist_only(): void {
		$names = array_keys( Agentbadge_Reporter::fact_readers() );
		// v1 facts (D-262-Q20) — active_plugins NOT in v1 (privacy).
		$this->assertNotContains( 'active_plugins', $names );
		$this->assertContains( 'WP_DEBUG', $names );
		$this->assertContains( 'wp_version', $names );
		$this->assertLessThanOrEqual( 16, count( $names ) );
	}

	// ── report flow ──

	public function test_report_not_connected_sends_nothing(): void {
		$cloud = new Fake_Cloud();
		$res   = $this->reporter( $cloud )->report( true );
		$this->assertSame( 'not_connected', $res['reason'] );
		$this->assertCount( 0, $cloud->sent );
	}

	public function test_baseline_sends_all_facts(): void {
		$this->options['agentbadge_site_key'] = 'absk_0123456789abcdefXX';
		$cloud = new Fake_Cloud();
		$rep   = $this->reporter( $cloud );
		$rep->mark_connected( 'site_t' );
		$res = $rep->report( true );
		$this->assertSame( count( Agentbadge_Reporter::fact_readers() ), $res['sent'] );
		$this->assertSame( 0, $res['errors'] === array() ? 0 : 1 ); // no errors
	}

	public function test_on_change_sends_only_delta(): void {
		$this->options['agentbadge_site_key'] = 'absk_0123456789abcdefXX';
		$cloud = new Fake_Cloud();
		$rep   = $this->reporter( $cloud );
		$rep->mark_connected( 'site_t' );
		$rep->report( true ); // baseline
		$cloud->sent = array();

		$res = $rep->report( false ); // nothing changed
		$this->assertSame( 0, $res['sent'] );

		$this->env->constants['WP_DEBUG'] = true; // flip one fact
		$res = $rep->report( false );
		$this->assertSame( 1, $res['sent'] );
		$this->assertSame( 'WP_DEBUG', $cloud->sent[0]['payload']['name'] );
	}

	public function test_reobserve_sends_all_even_unchanged(): void {
		// D-262-Q21: periodic re-observe refreshes collected_at on
		// unchanged values — freshness ≠ change.
		$this->options['agentbadge_site_key'] = 'absk_0123456789abcdefXX';
		$cloud = new Fake_Cloud();
		$rep   = $this->reporter( $cloud );
		$rep->mark_connected( 'site_t' );
		$rep->report( true );
		$cloud->sent = array();
		$res = $rep->report( true ); // all=true → fresh observation
		$this->assertSame( count( Agentbadge_Reporter::fact_readers() ), $res['sent'] );
	}

	public function test_backend_error_stops_batch_fail_closed(): void {
		$this->options['agentbadge_site_key'] = 'absk_0123456789abcdefXX';
		$cloud         = new Fake_Cloud();
		$cloud->status = 401;
		$rep           = $this->reporter( $cloud );
		$rep->mark_connected( 'site_t' );
		$res = $rep->report( true );
		$this->assertSame( 0, $res['sent'] );
		$this->assertCount( 1, $cloud->sent ); // stopped after first rejection
		$this->assertNotEmpty( $res['errors'] );
	}
}
