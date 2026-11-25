<?php
/**
 * Signed keyset resolution (SLICE-262-16, ADR-INTEL-002 §2).
 *
 * /.well-known/agentbadge-keys.json carries {root_id, keysets[]}
 * SIGNED by the root intel key. The plugin pins the root public
 * key — never trusts a key_id from a job response. The jobs sub-key
 * (purpose:"jobs") verifies pull-job signatures; rotation rides the
 * signed keyset with overlap windows, root rotation only via a
 * trusted plugin release.
 *
 * Root pin resolution:
 *  1. AGENTBADGE_ROOT_PUBKEY wp-config constant — hardened override.
 *  2. agentbadge_root_pubkey option — set ONCE (TOFU) at the first
 *     successfully verified keyset, then immutable.
 *
 * A signature proves ORIGIN under a signing key — not that the job
 * is safe or legitimate (ADR §10, R4); every downstream check
 * (nonce, whitelist, consent-mirror) still applies.
 *
 * @package agentbadge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-agentbadge-jcs.php';
require_once __DIR__ . '/class-agentbadge-env.php';

/**
 * Fetch → verify → cache the signed keyset.
 */
class Agentbadge_Keyset {

	public const PIN_CONSTANT  = 'AGENTBADGE_ROOT_PUBKEY';
	public const PIN_OPTION    = 'agentbadge_root_pubkey';
	public const CACHE_OPTION  = 'agentbadge_keyset';
	public const KEYSET_PATH   = '/.well-known/agentbadge-keys.json';
	public const REFRESH_AFTER = 21600; // 6h — min refresh cadence (R2)

	/**
	 * Env seam.
	 *
	 * @var Agentbadge_Env
	 */
	private Agentbadge_Env $env;

	/**
	 * API base URL (from Agentbadge_Cloud conventions).
	 *
	 * @var string
	 */
	private string $base;

	/**
	 * Constructor.
	 *
	 * @param Agentbadge_Env|null $env  Env seam.
	 * @param string|null         $base API base (default: Cloud resolution).
	 */
	public function __construct( ?Agentbadge_Env $env = null, ?string $base = null ) {
		$this->env  = $env ?? new Agentbadge_Env();
		$override   = $this->env->constant( Agentbadge_Cloud::BASE_CONSTANT );
		$this->base = null !== $base ? rtrim( $base, '/' )
			: ( is_string( $override ) && '' !== $override
				? rtrim( $override, '/' )
				: Agentbadge_Cloud::DEFAULT_BASE );
	}

	/**
	 * Ed25519 signature verify — fail closed when sodium absent.
	 *
	 * @param string $message Canonical bytes.
	 * @param string $sig_b64 Base64 signature.
	 * @param string $pub_b64 Base64 raw 32-byte public key.
	 * @return bool
	 */
	public static function verify( string $message, string $sig_b64, string $pub_b64 ): bool {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Ed25519 signature decode, not obfuscation
		$sig = base64_decode( $sig_b64, true );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Ed25519 public key decode, not obfuscation
		$pub = base64_decode( $pub_b64, true );
		if ( false === $sig || false === $pub || 32 !== strlen( $pub ) ) {
			return false;
		}
		return sodium_crypto_sign_verify_detached( $sig, $message, $pub );
	}

	/**
	 * Jobs public key for a key_id, or null when the keyset cannot
	 * be trusted right now (fail closed — callers skip the poll).
	 *
	 * @param string $key_id Job's key_id.
	 * @param int    $now    Current epoch.
	 * @return string|null Base64 raw pubkey.
	 */
	public function jobs_pubkey( string $key_id, int $now ): ?string {
		$doc = $this->trusted_keyset( $now );
		if ( null === $doc ) {
			return null;
		}
		foreach ( $doc['keysets'] as $ks ) {
			if (
				( $ks['key_id'] ?? '' ) === $key_id &&
				( $ks['purpose'] ?? '' ) === 'jobs' &&
				( $ks['status'] ?? '' ) === 'active' &&
				$now >= strtotime( (string) ( $ks['not_before'] ?? '' ) ) &&
				$now <= strtotime( (string) ( $ks['not_after'] ?? '' ) )
			) {
				return is_string( $ks['public_key'] ?? null ) ? $ks['public_key'] : null;
			}
		}
		return null;
	}

	/**
	 * Trusted keyset doc: cached copy if fresh, else fetch + verify.
	 * Root pin mismatch → refuse (never overwrite the pin with an
	 * unverified key — that IS the root-compromise defense).
	 *
	 * @param int $now Current epoch.
	 * @return array|null Decoded {root_id, keysets, sig}.
	 */
	public function trusted_keyset( int $now ): ?array {
		$cached = get_option( self::CACHE_OPTION, null );
		if ( is_array( $cached ) && isset( $cached['doc'], $cached['fetched_at'] ) ) {
			if ( $now - (int) $cached['fetched_at'] < self::REFRESH_AFTER ) {
				return $cached['doc'];
			}
		}
		$fresh = $this->fetch_verified( $now );
		if ( null !== $fresh ) {
			return $fresh;
		}
		// Stale-cache fallback: bounded — beyond max-age the cache is
		// untrusted and the poll aborts (fail closed).
		if ( is_array( $cached ) && $now - (int) $cached['fetched_at'] < 86400 ) {
			return $cached['doc'];
		}
		return null;
	}

	/**
	 * HTTP fetch + root-signature verification + cache write.
	 *
	 * @param int $now Current epoch.
	 * @return array|null
	 */
	private function fetch_verified( int $now ): ?array {
		$response = wp_remote_get(
			$this->base . self::KEYSET_PATH,
			array( 'timeout' => 15 )
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$doc = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $doc ) || ! isset( $doc['root_id'], $doc['keysets'], $doc['sig'] ) || ! is_array( $doc['keysets'] ) ) {
			return null;
		}
		// Root pubkey for root_id comes from the SAME doc's keys[] —
		// but it is ONLY trusted once it matches the pinned root.
		$root_pub = null;
		foreach ( (array) ( $doc['keys'] ?? array() ) as $entry ) {
			if ( ( $entry['key_id'] ?? '' ) === $doc['root_id'] ) {
				$root_pub = $entry['public_key'] ?? null;
			}
		}
		if ( ! is_string( $root_pub ) ) {
			return null;
		}
		$pin = $this->root_pin();
		if ( null !== $pin && ! hash_equals( $pin, $root_pub ) ) {
			return null; // Pinned root says NO — possible forged keyset.
		}
		$body = array(
			'root_id' => $doc['root_id'],
			'keysets' => $doc['keysets'],
		);
		$sig  = $doc['sig'];
		if (
			( $sig['algorithm'] ?? '' ) !== 'ed25519' ||
			! self::verify( Agentbadge_Jcs::encode( $body ), (string) ( $sig['value'] ?? '' ), $root_pub )
		) {
			return null;
		}
		if ( null === $pin ) {
			// TOFU: first verified keyset pins the root pubkey; every
			// later keyset must match it (root rotation = plugin update).
			update_option( self::PIN_OPTION, $root_pub, false );
		}
		update_option(
			self::CACHE_OPTION,
			array(
				'doc'        => $doc,
				'fetched_at' => $now,
			),
			false
		);
		return $doc;
	}

	/**
	 * Pinned root pubkey — constant wins; else the TOFU option.
	 *
	 * @return string|null
	 */
	private function root_pin(): ?string {
		$const = $this->env->constant( self::PIN_CONSTANT );
		if ( is_string( $const ) && '' !== $const ) {
			return $const;
		}
		$stored = get_option( self::PIN_OPTION, null );
		return is_string( $stored ) && '' !== $stored ? $stored : null;
	}
}
