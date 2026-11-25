<?php
/**
 * Self-report emitter (SLICE-262-14, D-262-Q20/Q21/Q33).
 *
 * Sends the v1 fact set as Collector Submissions
 * (wp_option_read payloads) through POST /api/intel/evidence.
 * Trust boundary unchanged: plugin facts arrive as untrusted
 * DTOs and are stamped PLUGIN_SELF_REPORTED — never verified.
 *
 * Cadence: baseline at handshake + on-change (checksum-diff)
 * + manual rescan + periodic re-observe (~24h target via
 * wp-cron + visit-trigger throttle; wp-cron is best-effort).
 * Three timestamps stay distinct: value_changed_at (local
 * state), collected_at (per submission), received_at (backend).
 *
 * Redaction is whitelist-positive: ONLY the listed fact names
 * leave the site — no option values, paths, emails, user data.
 *
 * @package agentbadge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-agentbadge-env.php';
require_once __DIR__ . '/class-agentbadge-key-store.php';
require_once __DIR__ . '/class-agentbadge-cloud.php';

/**
 * Fact collection → submissions → send.
 */
class Agentbadge_Reporter {

	public const STATE_OPTION   = 'agentbadge_report_state';
	public const CONNECT_OPTION = 'agentbadge_connected';

	/**
	 * Facts v1 (D-262-Q20) — name → reader(env).
	 * Names match the server whitelist contract where
	 * constant-backed; all sent as wp_option_read payloads.
	 *
	 * @return array<string, callable(Agentbadge_Env): mixed>
	 */
	public static function fact_readers(): array {
		return array(
			'WP_DEBUG'            => fn( $e ) => true === $e->constant( 'WP_DEBUG' ),
			'WP_DEBUG_LOG'        => fn( $e ) => true === $e->constant( 'WP_DEBUG_LOG' ),
			'WP_DEBUG_DISPLAY'    => fn( $e ) => true === $e->constant( 'WP_DEBUG_DISPLAY' ),
			'DISALLOW_FILE_EDIT'  => fn( $e ) => true === $e->constant( 'DISALLOW_FILE_EDIT' ),
			'DISALLOW_FILE_MODS'  => fn( $e ) => true === $e->constant( 'DISALLOW_FILE_MODS' ),
			'WP_ENVIRONMENT_TYPE' => fn( $e ) => $e->constant( 'WP_ENVIRONMENT_TYPE' ) ?? 'production',
			'php_version'         => fn( $e ) => $e->php_version(),
			'wp_version'          => fn( $e ) => $e->wp_version(),
			'display_errors'      => fn( $e ) => (string) $e->ini_get( 'display_errors' ),
			'is_ssl'              => fn( $e ) => 'https' === wp_parse_url( $e->home_url(), PHP_URL_SCHEME ),
			'permalink_structure' => fn( $e ) => '' !== $e->permalink_structure(),
			'server_software'     => fn( $e ) => $e->server_software(),
		);
	}

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
	 * Constructor.
	 *
	 * @param Agentbadge_Env|null       $env   Env seam.
	 * @param Agentbadge_Key_Store|null $keys  Key store.
	 * @param Agentbadge_Cloud|null     $cloud Cloud client.
	 */
	public function __construct(
		?Agentbadge_Env $env = null,
		?Agentbadge_Key_Store $keys = null,
		?Agentbadge_Cloud $cloud = null
	) {
		$this->env   = $env ?? new Agentbadge_Env();
		$this->keys  = $keys ?? new Agentbadge_Key_Store( $this->env );
		$this->cloud = $cloud ?? new Agentbadge_Cloud( $this->env );
	}

	/**
	 * Collect the current fact map — whitelist only.
	 *
	 * @return array<string, mixed>
	 */
	public function collect_facts(): array {
		$facts = array();
		foreach ( self::fact_readers() as $name => $reader ) {
			$facts[ $name ] = $reader( $this->env );
		}
		return $facts;
	}

	/**
	 * Build Collector Submissions for a fact map.
	 *
	 * @param array<string,mixed> $facts    Fact map.
	 * @param string              $site_id  Site ref from handshake.
	 * @param string              $scan_id  This report run id.
	 * @param string              $now      ISO collected_at.
	 * @return array<int,array>
	 */
	public static function submissions( array $facts, string $site_id, string $scan_id, string $now ): array {
		$out = array();
		foreach ( $facts as $name => $value ) {
			$out[] = array(
				'collector_id'  => 'wp_plugin@' . $site_id,
				'scan_id'       => $scan_id,
				'site_ref'      => $site_id,
				'collected_at'  => $now,
				'subject'       => array(
					'type' => 'wp_option',
					'name' => $name,
				),
				'payload'       => array(
					'type'     => 'wp_option_read',
					'name'     => $name,
					'value'    => $value,
					'autoload' => false,
				),
				'evidence_refs' => array(),
			);
		}
		return $out;
	}

	/**
	 * Send a report run.
	 *
	 * @param bool $all Send every fact (baseline / periodic
	 *                  re-observe) vs only changed since last run.
	 * @return array{sent:int, skipped:int, errors:string[], reason:?string}
	 */
	public function report( bool $all = false ): array {
		$res   = array(
			'sent'    => 0,
			'skipped' => 0,
			'errors'  => array(),
			'reason'  => null,
		);
		$key   = $this->keys->resolve();
		$state = $this->connected();
		if ( null === $key['key'] || null === $state ) {
			$res['reason'] = 'not_connected';
			return $res;
		}

		$facts = $this->collect_facts();
		$prior = (array) get_option( self::STATE_OPTION, array() );

		$send = array();
		foreach ( $facts as $name => $value ) {
			$hash = md5( wp_json_encode( $value ) );
			if ( $all || ( $prior[ $name ] ?? null ) !== $hash ) {
				$send[ $name ] = $value;
			} else {
				++$res['skipped'];
			}
		}

		if ( array() === $send ) {
			update_option( self::STATE_OPTION, $this->hash_state( $facts ), false );
			update_option( 'agentbadge_last_report_at', gmdate( 'c' ), false );
			return $res;
		}

		$scan_id = 'self_' . gmdate( 'Ymd\THis' );
		// Backend validates collected_at as strict ISO-8601 ("Z" suffix
		// only, zod .datetime()); gmdate('c') emits "+00:00" → rejected.
		$collected_at = gmdate( 'Y-m-d\TH:i:s\Z' );
		foreach ( self::submissions( $send, $state['site_id'], $scan_id, $collected_at ) as $sub ) {
			$r = $this->cloud->submit( $key['key'], $sub );
			if ( $r['status'] >= 200 && $r['status'] < 300 ) {
				++$res['sent'];
			} else {
				$res['errors'][] = 'HTTP ' . $r['status'];
				break; // fail closed — do not hammer a rejecting backend.
			}
		}
		update_option( self::STATE_OPTION, $this->hash_state( $facts ), false );
		update_option( 'agentbadge_last_report_at', $collected_at, false );
		return $res;
	}

	/**
	 * Whether the site is connected (handshake completed).
	 *
	 * @return array{site_id:string, connected_at:string}|null
	 */
	public function connected(): ?array {
		$s = get_option( self::CONNECT_OPTION, null );
		return is_array( $s ) && isset( $s['site_id'] ) ? $s : null;
	}

	/**
	 * Mark connected after a successful handshake.
	 *
	 * @param string $site_id Site id from handshake proof.
	 * @return void
	 */
	public function mark_connected( string $site_id ): void {
		update_option(
			self::CONNECT_OPTION,
			array(
				'site_id'      => $site_id,
				'connected_at' => gmdate( 'c' ),
			),
			false
		);
	}

	/**
	 * Disconnect — forget connection + report state (key removal
	 * is the key store's job). The TOFU root pin and cached keyset
	 * must also go: they are bound to the backend we talked to, and
	 * keeping them would pin the OLD deployment's root so a later
	 * connect to a different backend (e.g. staging → prod) can never
	 * verify its keyset (found live on the pryme.studio prod switch).
	 *
	 * @return void
	 */
	public function disconnect(): void {
		delete_option( self::CONNECT_OPTION );
		delete_option( self::STATE_OPTION );
		delete_option( Agentbadge_Keyset::PIN_OPTION );
		delete_option( Agentbadge_Keyset::CACHE_OPTION );
	}

	/**
	 * Hash map for change detection.
	 *
	 * @param array<string,mixed> $facts Fact map.
	 * @return array<string,string>
	 */
	private function hash_state( array $facts ): array {
		$out = array();
		foreach ( $facts as $name => $value ) {
			$out[ $name ] = md5( wp_json_encode( $value ) );
		}
		return $out;
	}
}
