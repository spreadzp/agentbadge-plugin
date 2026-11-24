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
	 * POST helper — Bearer auth, JSON body, sane timeout.
	 *
	 * @param string     $path     API path.
	 * @param string     $site_key Bearer token.
	 * @param array|null $payload  JSON body (null = empty).
	 * @return array{status:int, body:array, error:?string}
	 */
	private function post( string $path, string $site_key, ?array $payload ): array {
		$url = $this->base_url() . $path;
		if ( ! str_starts_with( $url, 'https://' ) ) {
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
