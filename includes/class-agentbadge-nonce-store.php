<?php
/**
 * Pull-job nonce store (SLICE-262-16, ADR-INTEL-002 §3).
 *
 * Bounded per-site set of job nonces that have reached a terminal
 * outcome (result accepted, or job rejected with a final status).
 * Purpose is RE-EXECUTION prevention on this host only — the
 * backend is the source of truth for one-time execution; a restored
 * backup can replay a job legitimately, and the backend's
 * lease/generation binding decides.
 *
 * @package agentbadge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bounded seen-nonce set in a protected option.
 */
class Agentbadge_Nonce_Store {

	public const OPTION = 'agentbadge_pull_nonces';
	public const MAX    = 200;
	public const TTL    = 7200; // 2h — outlives job TTL (60min)

	/**
	 * Whether the nonce already reached a terminal outcome.
	 *
	 * @param string $nonce Job nonce.
	 * @return bool
	 */
	public function seen( string $nonce ): bool {
		return isset( $this->load()[ $nonce ] );
	}

	/**
	 * Mark a nonce terminal (accepted / finally rejected).
	 *
	 * @param string $nonce Job nonce.
	 * @param int    $now   Current epoch.
	 * @return void
	 */
	public function mark( string $nonce, int $now ): void {
		$all           = $this->load();
		$all[ $nonce ] = $now;
		$this->save( $all );
	}

	/**
	 * Load + prune expired entries.
	 *
	 * @return array<string,int>
	 */
	private function load(): array {
		$all = get_option( self::OPTION, array() );
		if ( ! is_array( $all ) ) {
			return array();
		}
		$cutoff = time() - self::TTL;
		return array_filter(
			$all,
			function ( $ts ) use ( $cutoff ): bool {
				return is_int( $ts ) && $ts > $cutoff;
			}
		);
	}

	/**
	 * Persist bounded — drop oldest timestamps beyond MAX.
	 *
	 * @param array<string,int> $all Nonce map.
	 * @return void
	 */
	private function save( array $all ): void {
		if ( count( $all ) > self::MAX ) {
			asort( $all );
			$all = array_slice( $all, -self::MAX, null, true );
		}
		update_option( self::OPTION, $all, false );
	}
}
