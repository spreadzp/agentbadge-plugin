<?php
/**
 * Capability executor (SLICE-262-16, ADR-INTEL-002 §5/§6).
 *
 * Executes the three v1 pull capabilities — const_read,
 * option_read, phpinfo_summary — INSIDE WordPress, against the
 * versioned whitelist contract shipped verbatim in
 * contracts/pull-protocol/capability-registry.v1.json (source of
 * truth — the file is a byte-copy of the server spec, D-262-Q29).
 *
 * Fail-closed matrix: unknown probe / params outside whitelist /
 * missing name → REFUSE, never a fabricated or widened result.
 * Reductions are applied HERE, at collection: siteurl/home never
 * leave the box as URLs — only the https-scheme flag.
 *
 * @package agentbadge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-agentbadge-env.php';

/**
 * Whitelist-bound capability executor.
 */
class Agentbadge_Pull_Executor {

	public const REGISTRY_PATH = 'contracts/pull-protocol/capability-registry.v1.json';

	/**
	 * Env seam.
	 *
	 * @var Agentbadge_Env
	 */
	private Agentbadge_Env $env;

	/**
	 * Decoded registry contract.
	 *
	 * @var array|null
	 */
	private ?array $registry = null;

	/**
	 * Constructor.
	 *
	 * @param Agentbadge_Env|null $env      Env seam.
	 * @param array|null          $registry Registry override (tests).
	 */
	public function __construct( ?Agentbadge_Env $env = null, ?array $registry = null ) {
		$this->env      = $env ?? new Agentbadge_Env();
		$this->registry = $registry;
	}

	/**
	 * Whether a probe exists in the contract.
	 *
	 * @param string $probe_id Probe id.
	 * @return bool
	 */
	public function supports( string $probe_id ): bool {
		return in_array(
			$probe_id,
			array_column( (array) ( $this->registry()['capabilities'] ?? array() ), 'probe_id' ),
			true
		);
	}

	/**
	 * Validate params against the contract — same boundary the
	 * server applies at create; defense-in-depth against a forged
	 * or corrupted job body reaching execution.
	 *
	 * @param string $probe_id Probe id.
	 * @param array  $params   Signed params.
	 * @return bool
	 */
	public function validate_params( string $probe_id, array $params ): bool {
		$r = $this->registry();
		switch ( $probe_id ) {
			case 'const_read':
				$name = $params['name'] ?? null;
				return is_string( $name )
					&& in_array( $name, (array) ( $r['const_whitelist'] ?? array() ), true )
					&& 1 === count( $params );
			case 'option_read':
				$name = $params['name'] ?? null;
				return is_string( $name )
					&& isset( ( $r['option_whitelist'] ?? array() )[ $name ] )
					&& 1 === count( $params );
			case 'phpinfo_summary':
				return array() === $params;
			default:
				return false;
		}
	}

	/**
	 * Execute a validated capability. Returns the wire result
	 * shape per contract, or null on refusal.
	 *
	 * @param string $probe_id Probe id.
	 * @param array  $params   Signed params.
	 * @return array|null Result map.
	 */
	public function execute( string $probe_id, array $params ): ?array {
		if ( ! $this->validate_params( $probe_id, $params ) ) {
			return null;
		}
		switch ( $probe_id ) {
			case 'const_read':
				return $this->const_read( (string) $params['name'] );
			case 'option_read':
				return $this->option_read( (string) $params['name'] );
			case 'phpinfo_summary':
				return $this->phpinfo_summary();
			default:
				return null;
		}
	}

	/**
	 * Capability const_read — raw constant value, only when defined.
	 *
	 * @param string $name Constant name (whitelisted).
	 * @return array{name:string,defined:bool,value:mixed}
	 */
	private function const_read( string $name ): array {
		$defined = $this->env->constant( $name ) !== null;
		$value   = $defined ? $this->env->constant( $name ) : null;
		return array(
			'name'    => $name,
			'defined' => $defined,
			'value'   => is_bool( $value ) || is_string( $value ) || null === $value ? $value : (string) $value,
		);
	}

	/**
	 * Capability option_read — typed per contract field spec.
	 * Reduced fields emit ONLY the https-scheme flag (never the URL).
	 *
	 * @param string $name Option name (whitelisted).
	 * @return array
	 */
	private function option_read( string $name ): array {
		$spec = ( $this->registry()['option_whitelist'] ?? array() )[ $name ];
		$raw  = $this->env->option( $name, null );
		$out  = array(
			'name'    => $name,
			'present' => null !== $raw && false !== $raw,
		);
		if ( ! $out['present'] ) {
			$out['value'] = null;
			return $out;
		}
		switch ( $spec['type'] ?? '' ) {
			case 'reduced':
				$out[ (string) $spec['emit'] ] = 'https' === wp_parse_url( (string) $raw, PHP_URL_SCHEME );
				$out['value']                  = null;
				break;
			case 'integer':
				$v            = (int) $raw;
				$out['value'] = in_array( $v, (array) ( $spec['enum'] ?? array() ), true ) ? $v : null;
				break;
			case 'enum':
				$out['value'] = in_array( (string) $raw, (array) ( $spec['values'] ?? array() ), true )
					? (string) $raw
					: null;
				break;
			default: // String.
				$out['value'] = substr( (string) $raw, 0, (int) ( $spec['max'] ?? 256 ) );
		}
		return $out;
	}

	/**
	 * Capability phpinfo_summary — 8-field config fingerprint,
	 * strings only. loaded_extensions deliberately excluded
	 * (damage budget).
	 *
	 * @return array<string,string>
	 */
	private function phpinfo_summary(): array {
		$ini = function ( string $k ): string {
			$v = $this->env->ini_get( $k );
			return false === $v ? '' : (string) $v;
		};
		return array(
			'php_version'         => $this->env->php_version(),
			'memory_limit'        => $ini( 'memory_limit' ),
			'max_execution_time'  => $ini( 'max_execution_time' ),
			'upload_max_filesize' => $ini( 'upload_max_filesize' ),
			'post_max_size'       => $ini( 'post_max_size' ),
			'display_errors'      => $ini( 'display_errors' ),
			'expose_php'          => $ini( 'expose_php' ),
			'allow_url_fopen'     => $ini( 'allow_url_fopen' ),
		);
	}

	/**
	 * Lazy-load the verbatim contract copy. A missing/invalid
	 * contract yields an EMPTY registry — every whitelist check
	 * then refuses (fail closed on contract absence).
	 *
	 * @return array
	 */
	private function registry(): array {
		if ( null !== $this->registry ) {
			return $this->registry;
		}
		$path           = dirname( __DIR__ ) . '/' . self::REGISTRY_PATH;
		$raw            = $this->env->file_read( $path, 65536 );
		$doc            = null !== $raw ? json_decode( $raw, true ) : null;
		$this->registry = is_array( $doc ) ? $doc : array();
		return $this->registry;
	}
}
