<?php
/**
 * AgentBadge Cloud HTTP client (SLICE-262-14).
 *
 * Thin wrapper over wp_remote_post — the only component allowed
 * to make network calls. All calls carry the site_key as Bearer;
 * TLS is mandatory (no http:// endpoints except explicit local
 * dev override via AGENTBADGE_API_BASE).
 *
 * @package agentbadge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cloud API client.
 */
class Agentbadge_Cloud {

	public const DEFAULT_BASE   = 'https://agentbadge.xyz';
	public const BASE_CONSTANT  = 'AGENTBADGE_API_BASE';
	public const HANDSHAKE_PATH = '/api/intel/ownership/handshake';
	public const EVIDENCE_PATH  = '/api/intel/evidence';
	public const JOBS_PATH      = '/api/intel/jobs';

	/**
	 * Env seam.
	 *
	 * @var Agentbadge_Env
	 */
	private Agentbadge_Env $env;

	/**
	 * Constructor.
	 *
	 * @param Agentbadge_Env|null $env Env seam.
	 */
	public function __construct( ?Agentbadge_Env $env = null ) {
		$this->env = $env ?? new Agentbadge_Env();
	}

	/**
	 * API base URL — constant override for dev/staging.
	 *
	 * @return string
	 */
	public function base_url(): string {
		$base = $this->env->constant( self::BASE_CONSTANT );
		if ( is_string( $base ) && '' !== $base ) {
			return rtrim( $base, '/' );
		}
		return self::DEFAULT_BASE;
	}

	/**
	 * POST /ownership/handshake — proves installation control and
	 * grants audit consent inline. Returns decoded body + status.
	 *
	 * @param string $site_key absk_ plaintext.
	 * @return array{status:int, body:array, error:?string}
	 */
	public function handshake( string $site_key ): array {
		return $this->post( self::HANDSHAKE_PATH, $site_key, null );
	}

	/**
	 * POST /evidence — one CollectorSubmission per call.
	 *
	 * @param string $site_key   absk_ plaintext.
	 * @param array  $submission Submission DTO.
	 * @return array{status:int, body:array, error:?string}
	 */
	public function submit( string $site_key, array $submission ): array {
		return $this->post( self::EVIDENCE_PATH, $site_key, $submission );
	}

	/**
	 * GET /jobs?site= — read-only queue peek (SLICE-262-16).
	 *
	 * @param string $site_key absk_ plaintext.
	 * @param string $site_id  Authenticated site id.
	 * @return array{status:int, body:array, error:?string}
	 */
	public function jobs_peek( string $site_key, string $site_id ): array {
		return $this->get( self::JOBS_PATH . '?site=' . rawurlencode( $site_id ), $site_key );
	}

	/**
	 * POST /jobs/:id/claim — atomic lease claim.
	 *
	 * @param string $site_key absk_ plaintext.
	 * @param string $job_id   Job id.
	 * @return array{status:int, body:array, error:?string}
	 */
	public function job_claim( string $site_key, string $job_id ): array {
		return $this->post( self::JOBS_PATH . '/' . rawurlencode( $job_id ) . '/claim', $site_key, array() );
	}

	/**
	 * POST /jobs/:id/result — result body {lease_token, result}.
	 *
	 * @param string $site_key absk_ plaintext.
	 * @param string $job_id   Job id.
	 * @param array  $body     Result body.
	 * @return array{status:int, body:array, error:?string}
	 */
	public function job_result( string $site_key, string $job_id, array $body ): array {
		return $this->post( self::JOBS_PATH . '/' . rawurlencode( $job_id ) . '/result', $site_key, $body );
	}

	/**
	 * TLS gate — https always allowed; http ONLY behind an explicit
	 * dev escape hatch (AGENTBADGE_INSECURE_DEV constant AND an
	 * AGENTBADGE_API_BASE override — the default endpoint can never
	 * be reached insecurely). SLICE-262-18: the harness needs it;
	 * production never sets the constant.
	 *
	 * @param string $url Resolved URL.
	 * @return bool
	 */
	private function url_allowed( string $url ): bool {
		if ( str_starts_with( $url, 'https://' ) ) {
			return true;
		}
		if ( ! str_starts_with( $url, 'http://' ) ) {
			return false;
		}
		return true === (bool) $this->env->constant( 'AGENTBADGE_INSECURE_DEV' )
			&& null !== $this->env->constant( self::BASE_CONSTANT );
	}

	/**
	 * GET helper — Bearer auth, same refusal rules as post().
	 *
	 * @param string $path     API path (with query).
	 * @param string $site_key Bearer token.
	 * @return array{status:int, body:array, error:?string}
	 */
	private function get( string $path, string $site_key ): array {
		$url = $this->base_url() . $path;
		if ( ! $this->url_allowed( $url ) ) {
			return array(
				'status' => 0,
				'body'   => array(),
				'error'  => 'insecure endpoint refused',
			);
		}
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $site_key,
					'Accept'        => 'application/json',
					'User-Agent'    => 'AgentBadge-WP/' . ( defined( 'AGENTBADGE_VERSION' ) ? AGENTBADGE_VERSION : 'dev' ),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 0,
				'body'   => array(),
				'error'  => $response->get_error_message(),
			);
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return array(
			'status' => $status,
			'body'   => is_array( $body ) ? $body : array(),
			'error'  => null,
		);
	}

	/**
	 * POST helper — Bearer auth, JSON body, sane timeout.
	 *
	 * @param string     $path     API path.
	 * @param string     $site_key Bearer token.
	 * @param array|null $payload  JSON body (null = empty).
	 * @return array{status:int, body:array, error:?string}
	 */
	private function post( string $path, string $site_key, ?array $payload ): array {
		$url = $this->base_url() . $path;
		if ( ! $this->url_allowed( $url ) ) {
			return array(
				'status' => 0,
				'body'   => array(),
				'error'  => 'insecure endpoint refused',
			);
		}
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $site_key,
					'Content-Type'  => 'application/json',
					'User-Agent'    => 'AgentBadge-WP/' . ( defined( 'AGENTBADGE_VERSION' ) ? AGENTBADGE_VERSION : 'dev' ),
				),
				'body'    => null === $payload ? '{}' : wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 0,
				'body'   => array(),
				'error'  => $response->get_error_message(),
			);
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return array(
			'status' => $status,
			'body'   => is_array( $body ) ? $body : array(),
			'error'  => null,
		);
	}
}
