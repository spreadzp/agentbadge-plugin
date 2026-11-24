<?php
/**
 * EPIC-262 (SLICE-262-18): minimal WordPress stubs for e2e — enough
 * surface for the plugin classes to load and run against the REAL
 * fake backend over actual HTTP. wp_remote_* are implemented with
 * streams (not mocked) — this is the whole point of the harness:
 * real wire, real signatures, real JCS.
 *
 * NOT a WordPress — anything beyond the option store + HTTP is a
 * no-op passthrough. Brain Monkey remains the unit-test layer;
 * these stubs exist so e2e exercises the plugin end-to-end.
 *
 * @package agentbadge
 */

// phpcs:disable WordPress.NamingConventions -- stub names must match WP core.

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}
if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}

// ─── option store (process-local) ────────────────────────────────
$GLOBALS['__ab_options'] = array();

/**
 * Read an option.
 *
 * @param string $name    Option name.
 * @param mixed  $default Default.
 * @return mixed
 */
function get_option( string $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['__ab_options'] )
		? $GLOBALS['__ab_options'][ $name ]
		: $default;
}

/**
 * Write an option.
 *
 * @param string $name     Option name.
 * @param mixed  $value    Value.
 * @param mixed  $autoload Unused.
 * @return bool
 */
function update_option( string $name, $value, $autoload = null ): bool {
	$GLOBALS['__ab_options'][ $name ] = $value;
	return true;
}

/**
 * Delete an option.
 *
 * @param string $name Option name.
 * @return bool
 */
function delete_option( string $name ): bool {
	unset( $GLOBALS['__ab_options'][ $name ] );
	return true;
}

/**
 * Add an option (no-op if present).
 *
 * @param string $name     Option name.
 * @param mixed  $value    Value.
 * @param mixed  $x        Unused.
 * @param mixed  $autoload Unused.
 * @return bool
 */
function add_option( string $name, $value = '', $x = '', $autoload = 'yes' ): bool {
	if ( ! array_key_exists( $name, $GLOBALS['__ab_options'] ) ) {
		$GLOBALS['__ab_options'][ $name ] = $value;
	}
	return true;
}

/**
 * Site option = option here (single site harness).
 *
 * @param string $name    Option name.
 * @param mixed  $default Default.
 * @return mixed
 */
function get_site_option( string $name, $default = false ) {
	return get_option( $name, $default );
}

/** Reset the option store between tests. */
function __ab_reset_options(): void {
	$GLOBALS['__ab_options'] = array();
}

// ─── real HTTP transport (streams) ───────────────────────────────

/**
 * Error object.
 */
class WP_Error {
	/**
	 * Message.
	 *
	 * @var string
	 */
	private string $message;

	/**
	 * Constructor.
	 *
	 * @param string $message Message.
	 */
	public function __construct( string $message ) {
		$this->message = $message;
	}

	/**
	 * Message getter.
	 *
	 * @return string
	 */
	public function get_error_message(): string {
		return $this->message;
	}
}

/**
 * Error type check.
 *
 * @param mixed $thing Value.
 * @return bool
 */
function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

/**
 * Real HTTP request via streams.
 *
 * @param string $method Method.
 * @param string $url    URL.
 * @param array  $args   WP-style args (headers/body/timeout).
 * @return array|WP_Error
 */
function __ab_http( string $method, string $url, array $args ) {
	$headers = $args['headers'] ?? array();
	$header_lines = array();
	foreach ( $headers as $k => $v ) {
		$header_lines[] = "$k: $v";
	}
	$ctx = stream_context_create(
		array(
			'http' => array(
				'method'        => $method,
				'header'        => implode( "\r\n", $header_lines ),
				'content'       => $args['body'] ?? '',
				'timeout'       => $args['timeout'] ?? 15,
				'ignore_errors' => true,
			),
		)
	);
	$body = @file_get_contents( $url, false, $ctx ); // phpcs:ignore
	if ( false === $body ) {
		return new WP_Error( 'http request failed' );
	}
	$code = 0;
	$raw_headers = $http_response_header ?? array();
	foreach ( $raw_headers as $h ) {
		if ( preg_match( '/^HTTP\/\S+\s+(\d+)/', $h, $m ) ) {
			$code = (int) $m[1];
		}
	}
	return array(
		'headers'  => $raw_headers,
		'body'     => $body,
		'response' => array( 'code' => $code, 'message' => '' ),
		'cookies'  => array(),
	);
}

/**
 * wp_remote_post — real POST.
 *
 * @param string $url  URL.
 * @param array  $args Args.
 * @return array|WP_Error
 */
function wp_remote_post( string $url, array $args = array() ) {
	return __ab_http( 'POST', $url, $args );
}

/**
 * wp_remote_get — real GET.
 *
 * @param string $url  URL.
 * @param array  $args Args.
 * @return array|WP_Error
 */
function wp_remote_get( string $url, array $args = array() ) {
	return __ab_http( 'GET', $url, $args );
}

/**
 * Response code reader.
 *
 * @param array|WP_Error $response Response.
 * @return int|string
 */
function wp_remote_retrieve_response_code( $response ) {
	return is_array( $response ) ? ( $response['response']['code'] ?? 0 ) : '';
}

/**
 * Response body reader.
 *
 * @param array|WP_Error $response Response.
 * @return string
 */
function wp_remote_retrieve_body( $response ): string {
	return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
}

/**
 * Response headers reader.
 *
 * @param array|WP_Error $response Response.
 * @return array
 */
function wp_remote_retrieve_headers( $response ): array {
	return is_array( $response ) ? (array) ( $response['headers'] ?? array() ) : array();
}

// ─── misc WP surface — passthrough/no-op ─────────────────────────

/**
 * JSON encode.
 *
 * @param mixed $data Data.
 * @return string|false
 */
function wp_json_encode( $data ) {
	return json_encode( $data );
}

/**
 * KSES passthrough — harness content is trusted.
 *
 * @param string $s      String.
 * @param array  $unused Unused.
 * @return string
 */
function wp_kses( string $s, array $unused = array() ): string {
	return $s;
}

/**
 * Sanitize text field (minimal).
 *
 * @param string $s String.
 * @return string
 */
function sanitize_text_field( string $s ): string {
	return trim( (string) preg_replace( '/[\r\n\t\0\x0B]/', '', $s ) );
}

/**
 * HTML escape.
 *
 * @param string $s String.
 * @return string
 */
function esc_html( string $s ): string {
	return htmlspecialchars( $s, ENT_QUOTES );
}

/**
 * Attribute escape.
 *
 * @param string $s String.
 * @return string
 */
function esc_attr( string $s ): string {
	return htmlspecialchars( $s, ENT_QUOTES );
}

/**
 * URL escape.
 *
 * @param string $s String.
 * @return string
 */
function esc_url( string $s ): string {
	return $s;
}

/**
 * Parse args vs defaults.
 *
 * @param mixed $args     Args.
 * @param array $defaults Defaults.
 * @return array
 */
function wp_parse_args( $args, array $defaults = array() ): array {
	if ( is_object( $args ) ) {
		$args = get_object_vars( $args );
	}
	return is_array( $args ) ? array_merge( $defaults, $args ) : $defaults;
}

/**
 * Filter passthrough.
 *
 * @param string $hook  Hook.
 * @param mixed  $value Value.
 * @return mixed
 */
function apply_filters( string $hook, $value ) {
	return $value;
}

/**
 * Action no-op.
 *
 * @param string $hook Hook.
 * @param mixed  ...$a Args.
 * @return void
 */
function do_action( string $hook, ...$a ): void {}

/**
 * add_action no-op.
 *
 * @param string $hook     Hook.
 * @param mixed  $cb       Callback.
 * @param int    $priority Priority.
 * @param int    $args     Arg count.
 * @return bool
 */
function add_action( string $hook, $cb, int $priority = 10, int $args = 1 ): bool {
	return true;
}

/**
 * add_filter no-op.
 *
 * @param string $hook     Hook.
 * @param mixed  $cb       Callback.
 * @param int    $priority Priority.
 * @param int    $args     Arg count.
 * @return bool
 */
function add_filter( string $hook, $cb, int $priority = 10, int $args = 1 ): bool {
	return true;
}

/**
 * register_activation_hook no-op.
 *
 * @param string $file File.
 * @param mixed  $cb   Callback.
 * @return void
 */
function register_activation_hook( string $file, $cb ): void {}

/**
 * Cron schedule check (never scheduled).
 *
 * @param string $hook Hook.
 * @return bool
 */
function wp_next_scheduled( string $hook ) {
	return false;
}

/**
 * Cron schedule no-op.
 *
 * @param int    $time      Time.
 * @param string $recurrence Recurrence.
 * @param string $hook      Hook.
 * @return bool
 */
function wp_schedule_event( int $time, string $recurrence, string $hook ): bool {
	return true;
}

/**
 * Single cron event no-op.
 *
 * @param int    $time Time.
 * @param string $hook Hook.
 * @param array  $args Args.
 * @return bool
 */
function wp_schedule_single_event( int $time, string $hook, array $args = array() ): bool {
	return true;
}

/**
 * Cron clear no-op.
 *
 * @param string $hook Hook.
 * @return int
 */
function wp_clear_scheduled_hook( string $hook ): int {
	return 0;
}

/**
 * Home URL.
 *
 * @param string $path Path.
 * @return string
 */
function home_url( string $path = '' ): string {
	return 'https://fake-site.local' . $path;
}

/**
 * Site URL.
 *
 * @param string $path Path.
 * @return string
 */
function site_url( string $path = '' ): string {
	return home_url( $path );
}

/**
 * Admin URL.
 *
 * @param string $path Path.
 * @return string
 */
function admin_url( string $path = '' ): string {
	return home_url( '/wp-admin/' . $path );
}

/**
 * Admin check.
 *
 * @return bool
 */
function is_admin(): bool {
	return true;
}

/**
 * Plugin dir path.
 *
 * @param string $file File.
 * @return string
 */
function plugin_dir_path( string $file ): string {
	return dirname( $file ) . '/';
}

/**
 * Current user — nobody in the harness.
 *
 * @return int
 */
function get_current_user_id(): int {
	return 0;
}

/**
 * Capability — allow (harness runs privileged).
 *
 * @param string $cap Capability.
 * @return bool
 */
function current_user_can( string $cap ): bool {
	return true;
}

/**
 * Slashing passthrough.
 *
 * @param mixed $v Value.
 * @return mixed
 */
function wp_unslash( $v ) {
	return $v;
}

/**
 * Absint.
 *
 * @param mixed $v Value.
 * @return int
 */
function absint( $v ): int {
	return abs( (int) $v );
}

/**
 * Nonce — static in harness.
 *
 * @param string $action Action.
 * @return string
 */
function wp_create_nonce( string $action ): string {
	return 'nonce_' . $action;
}

/**
 * Nonce verify — always true.
 *
 * @param string $nonce  Nonce.
 * @param string $action Action.
 * @return int
 */
function wp_verify_nonce( string $nonce, string $action ): int {
	return 1;
}
