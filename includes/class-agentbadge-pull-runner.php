<?php
/**
 * Diagnostic pull runner (SLICE-262-16, ADR-INTEL-002).
 *
 * Poll → per job: verify signed body under the pinned-root keyset
 * → skip seen nonces → claim (lease + generation) → execute the
 * whitelisted capability → submit the result once. EVERY doubt is
 * a skip: bad signature, unknown key_id, foreign site_id, expired
 * job, params outside the contract, missing lease — never execute,
 * never submit a fabricated result (fail-closed, ADR §6).
 *
 * Consent: the local mirror is a cache, not authorization — the
 * backend re-checks at claim AND submit; a 403 here stops the run.
 *
 * @package agentbadge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-agentbadge-env.php';
require_once __DIR__ . '/class-agentbadge-jcs.php';
require_once __DIR__ . '/class-agentbadge-key-store.php';
require_once __DIR__ . '/class-agentbadge-cloud.php';
require_once __DIR__ . '/class-agentbadge-keyset.php';
require_once __DIR__ . '/class-agentbadge-nonce-store.php';
require_once __DIR__ . '/class-agentbadge-pull-executor.php';
require_once __DIR__ . '/class-agentbadge-reporter.php';

/**
 * Poll/claim/execute/submit loop.
 */
class Agentbadge_Pull_Runner {

	/**
	 * Env seam.
	 *
	 * @var Agentbadge_Env
	 */
	private Agentbadge_Env $env;
	/**
	 * Key store.
	 *
	 * @var Agentbadge_Key_Store
	 */
	private Agentbadge_Key_Store $keys;
	/**
	 * Cloud client.
	 *
	 * @var Agentbadge_Cloud
	 */
	private Agentbadge_Cloud $cloud;
	/**
	 * Keyset resolver.
	 *
	 * @var Agentbadge_Keyset
	 */
	private Agentbadge_Keyset $keyset;
	/**
	 * Nonce store.
	 *
	 * @var Agentbadge_Nonce_Store
	 */
	private Agentbadge_Nonce_Store $nonces;
	/**
	 * Capability executor.
	 *
	 * @var Agentbadge_Pull_Executor
	 */
	private Agentbadge_Pull_Executor $executor;

	/**
	 * Constructor — all collaborators injectable for tests.
	 *
	 * @param Agentbadge_Env|null           $env      Env seam.
	 * @param Agentbadge_Key_Store|null     $keys     Key store.
	 * @param Agentbadge_Cloud|null         $cloud    Cloud client.
	 * @param Agentbadge_Keyset|null        $keyset   Keyset resolver.
	 * @param Agentbadge_Nonce_Store|null   $nonces   Nonce store.
	 * @param Agentbadge_Pull_Executor|null $executor Capability executor.
	 */
	public function __construct(
		?Agentbadge_Env $env = null,
		?Agentbadge_Key_Store $keys = null,
		?Agentbadge_Cloud $cloud = null,
		?Agentbadge_Keyset $keyset = null,
		?Agentbadge_Nonce_Store $nonces = null,
		?Agentbadge_Pull_Executor $executor = null
	) {
		$this->env      = $env ?? new Agentbadge_Env();
		$this->keys     = $keys ?? new Agentbadge_Key_Store( $this->env );
		$this->cloud    = $cloud ?? new Agentbadge_Cloud( $this->env );
		$this->keyset   = $keyset ?? new Agentbadge_Keyset( $this->env );
		$this->nonces   = $nonces ?? new Agentbadge_Nonce_Store();
		$this->executor = $executor ?? new Agentbadge_Pull_Executor( $this->env );
	}

	/**
	 * Signed-body field list — the exact shape covered by sig.
	 *
	 * @param array $job Wire job.
	 * @return array|null Canonical body, or null on shape failure.
	 */
	public static function signed_body( array $job ): ?array {
		foreach ( array( 'job_id', 'site_id', 'probe_id', 'nonce', 'exp', 'key_id' ) as $f ) {
			if ( ! is_string( $job[ $f ] ?? null ) ) {
				return null;
			}
		}
		if ( ! isset( $job['params'] ) || ! is_array( $job['params'] ) ) {
			return null;
		}
		return array(
			'job_id'   => $job['job_id'],
			'site_id'  => $job['site_id'],
			'probe_id' => $job['probe_id'],
			'params'   => $job['params'],
			'exp'      => $job['exp'],
			'nonce'    => $job['nonce'],
			'key_id'   => $job['key_id'],
		);
	}

	/**
	 * Run one poll cycle.
	 *
	 * @param int|null $now Epoch override (tests).
	 * @return array{polled:int, done:int, skipped:int, reason:?string}
	 */
	public function run( ?int $now = null ): array {
		$now  = $now ?? time();
		$res  = array(
			'polled'  => 0,
			'done'    => 0,
			'skipped' => 0,
			'reason'  => null,
		);
		$key  = $this->keys->resolve();
		$conn = ( new Agentbadge_Reporter( $this->env, $this->keys, $this->cloud ) )->connected();
		if ( null === $key['key'] || null === $conn ) {
			$res['reason'] = 'not_connected';
			return $res;
		}

		$peek = $this->cloud->jobs_peek( $key['key'], $conn['site_id'] );
		if ( 200 !== $peek['status'] ) {
			$res['reason'] = 'peek_failed';
			return $res;
		}
		$jobs = $peek['body']['jobs'] ?? array();
		if ( ! is_array( $jobs ) ) {
			$res['reason'] = 'peek_malformed';
			return $res;
		}

		foreach ( $jobs as $job ) {
			if ( ! is_array( $job ) ) {
				continue;
			}
			++$res['polled'];
			$outcome = $this->handle_job( $job, $key['key'], $conn['site_id'], $now );
			if ( 'done' === $outcome ) {
				++$res['done'];
			} elseif ( 'abort' === $outcome ) {
				// consent denied / backend hard-reject — do not hammer.
				$res['reason'] = 'aborted';
				break;
			} else {
				++$res['skipped'];
			}
		}
		return $res;
	}

	/**
	 * Per-job pipeline — every check fails closed.
	 *
	 * @param array  $job     Wire job from peek.
	 * @param string $site_key Bearer.
	 * @param string $site_id Authenticated site id.
	 * @param int    $now     Epoch.
	 * @return string done|skipped|abort
	 */
	private function handle_job( array $job, string $site_key, string $site_id, int $now ): string {
		$body = self::signed_body( $job );
		if ( null === $body || $body['site_id'] !== $site_id ) {
			return 'skipped';
		}
		if ( $now >= strtotime( $body['exp'] ) ) {
			return 'skipped'; // Expired — backend requeues/expires, never execute.
		}
		$pub = $this->keyset->jobs_pubkey( $body['key_id'], $now );
		if ( null === $pub ) {
			return 'skipped'; // Unknown/inactive key — never trust the body.
		}
		$sig = $job['sig'] ?? array();
		if (
			! is_array( $sig ) ||
			( $sig['algorithm'] ?? '' ) !== 'ed25519' ||
			! Agentbadge_Keyset::verify(
				Agentbadge_Jcs::encode( $body ),
				(string) ( $sig['value'] ?? '' ),
				$pub
			)
		) {
			return 'skipped'; // Bad signature — forged or corrupted.
		}
		if ( $this->nonces->seen( $body['nonce'] ) ) {
			return 'skipped'; // Already terminal on this host.
		}
		if ( ! $this->executor->validate_params( $body['probe_id'], $body['params'] ) ) {
			return 'skipped'; // Outside the contract — never execute.
		}

		$claim = $this->cloud->job_claim( $site_key, $body['job_id'] );
		if ( 403 === $claim['status'] ) {
			return 'abort'; // Consent revoked — stop the whole run.
		}
		if ( 200 !== $claim['status'] || ! is_string( $claim['body']['lease_token'] ?? null ) ) {
			return 'skipped'; // Lost the claim race or job gone — retry next poll.
		}

		$result = $this->executor->execute( $body['probe_id'], $body['params'] );
		if ( null === $result ) {
			return 'skipped';
		}
		$submit = $this->cloud->job_result(
			$site_key,
			$body['job_id'],
			array(
				'lease_token' => $claim['body']['lease_token'],
				'result'      => $result,
			)
		);
		if ( $submit['status'] >= 200 && $submit['status'] < 300 ) {
			$this->nonces->mark( $body['nonce'], $now );
			return 'done';
		}
		if ( 409 === $submit['status'] ) {
			// Terminal: conflict/lease-expired/job-closed — do NOT
			// re-execute; backend owns the final state.
			$this->nonces->mark( $body['nonce'], $now );
			return 'skipped';
		}
		if ( 403 === $submit['status'] ) {
			return 'abort';
		}
		return 'skipped'; // Transient — lease lapses, backend requeues.
	}
}
