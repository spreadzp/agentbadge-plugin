<?php
/**
 * Site_key resolution (SLICE-262-14, D-262-Q19/Q30).
 *
 * Sources, in precedence order:
 *  1. AGENTBADGE_SITE_KEY wp-config constant — hardened override,
 *     always wins when defined.
 *  2. agentbadge_site_key option (protected, autoload=no).
 *
 * Rules from the security grill:
 *  - an EMPTY or malformed constant is an ERROR, not a silent
 *    fallback to the option — hiding a misconfiguration is worse
 *    than being loud;
 *  - a constant/option conflict is surfaced, never logged with
 *    the key value (status + fact of conflict is enough);
 *  - the option is never overwritten by the constant.
 *
 * @package agentbadge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the active site_key with full source status.
 */
class Agentbadge_Key_Store {

	public const OPTION     = 'agentbadge_site_key';
	public const CONSTANT   = 'AGENTBADGE_SITE_KEY';
	public const KEY_PREFIX = 'absk_';

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
	 * Resolve the effective key.
	 *
	 * @return array{key: ?string, source: string, status: string, conflict: bool}
	 *   source: constant|option|none|invalid_constant
	 *   status: ok|missing|invalid_constant|conflict
	 */
	public function resolve(): array {
		$const  = $this->env->constant( self::CONSTANT );
		$option = $this->stored_key();

		if ( null !== $const ) {
			$const = trim( (string) $const );
			if ( ! $this->valid( $const ) ) {
				// Misconfiguration must be loud — never fall back.
				return $this->result( null, 'invalid_constant', 'invalid_constant', false );
			}
			$conflict = null !== $option && $option !== $const;
			return $this->result( $const, 'constant', $conflict ? 'conflict' : 'ok', $conflict );
		}

		if ( null !== $option ) {
			return $this->result( $option, 'option', 'ok', false );
		}
		return $this->result( null, 'none', 'missing', false );
	}

	/**
	 * Persist a key in the protected option.
	 *
	 * @param string $key Plaintext key (stored once, never echoed).
	 * @return bool
	 */
	public function store( string $key ): bool {
		return (bool) update_option( self::OPTION, $key, false );
	}

	/**
	 * Remove the stored key (disconnect).
	 *
	 * @return bool
	 */
	public function forget(): bool {
		return (bool) delete_option( self::OPTION );
	}

	/**
	 * Stored option value or null.
	 *
	 * @return string|null
	 */
	private function stored_key(): ?string {
		$v = get_option( self::OPTION, null );
		return is_string( $v ) && '' !== $v ? $v : null;
	}

	/**
	 * Key shape check — prefix + enough entropy characters.
	 *
	 * @param string $key Candidate.
	 * @return bool
	 */
	private function valid( string $key ): bool {
		return str_starts_with( $key, self::KEY_PREFIX ) && strlen( $key ) > strlen( self::KEY_PREFIX ) + 16;
	}

	/**
	 * Result tuple helper.
	 *
	 * @param string|null $key      Effective key.
	 * @param string      $source   Resolution source.
	 * @param string      $status   Status code.
	 * @param bool        $conflict Constant≠option conflict.
	 * @return array
	 */
	private function result( ?string $key, string $source, string $status, bool $conflict ): array {
		return array(
			'key'      => $key,
			'source'   => $source,
			'status'   => $status,
			'conflict' => $conflict,
		);
	}
}
