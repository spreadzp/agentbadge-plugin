<?php
/**
 * RFC 8785 (JCS) canonical JSON serializer (SLICE-262-16).
 *
 * The plugin must reproduce the server's canonical byte stream to
 * verify Ed25519 job signatures — signature input is JCS over the
 * signed body, never PHP's default json_encode (key order differs).
 *
 * Scope: JSON primitives + objects + arrays. Object keys sort as
 * UTF-16 code units per RFC 8785 §3.2.3 — implemented by comparing
 * the UTF-16BE encoding of each key byte-wise.
 *
 * @package agentbadge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal JCS encoder.
 */
class Agentbadge_Jcs {

	/**
	 * Canonical UTF-8 serialization.
	 *
	 * @param mixed $value JSON-serializable value.
	 * @return string
	 */
	public static function encode( $value ): string {
		if ( null === $value ) {
			return 'null';
		}
		if ( true === $value ) {
			return 'true';
		}
		if ( false === $value ) {
			return 'false';
		}
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		if ( is_float( $value ) ) {
			// PHP's default precision (-1) emits the shortest
			// round-trip representation — matches ECMAScript for the
			// finite values the protocol carries (byte sizes, secs).
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- JCS canonical bytes; wp_json_encode's sanity pass could alter them
			$enc = json_encode( $value );
			return false === $enc ? '0' : $enc;
		}
		if ( is_string( $value ) ) {
			return self::string( $value );
		}
		if ( is_array( $value ) ) {
			return self::is_list( $value )
				? '[' . implode( ',', array_map( array( self::class, 'encode' ), $value ) ) . ']'
				: self::object( $value );
		}
		if ( is_object( $value ) ) {
			return self::object( get_object_vars( $value ) );
		}
		return 'null';
	}

	/**
	 * Whether an array is a sequential list.
	 *
	 * @param array $a Array.
	 * @return bool
	 */
	private static function is_list( array $a ): bool {
		return array() === $a || array_keys( $a ) === range( 0, count( $a ) - 1 );
	}

	/**
	 * Object serialization — keys sorted by UTF-16 code units.
	 *
	 * @param array<string,mixed> $a Assoc array.
	 * @return string
	 */
	private static function object( array $a ): string {
		$pairs = array();
		foreach ( $a as $k => $v ) {
			$pairs[] = array( (string) $k, $v );
		}
		usort(
			$pairs,
			function ( array $x, array $y ): int {
				return strcmp( self::utf16be( $x[0] ), self::utf16be( $y[0] ) );
			}
		);
		$out = '{';
		foreach ( $pairs as $i => $p ) {
			$out .= ( $i ? ',' : '' ) . self::string( $p[0] ) . ':' . self::encode( $p[1] );
		}
		return $out . '}';
	}

	/**
	 * UTF-16BE encoding for key ordering (RFC 8785 §3.2.3).
	 *
	 * @param string $s UTF-8 string.
	 * @return string
	 */
	private static function utf16be( string $s ): string {
		$be = function_exists( 'mb_convert_encoding' )
			? mb_convert_encoding( $s, 'UTF-16BE', 'UTF-8' )
			: false;
		if ( is_string( $be ) ) {
			return $be;
		}
		// Fallback without mbstring — ASCII keys are already ordered.
		return $s;
	}

	/**
	 * String literal with minimal escaping (RFC 8785 §3.2.2.2):
	 * control chars \uXXXX, plus \" and \\ — NOTHING else.
	 *
	 * @param string $s String.
	 * @return string
	 */
	private static function string( string $s ): string {
		$out = '"';
		$len = strlen( $s );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $s[ $i ];
			$o = ord( $c );
			if ( '"' === $c ) {
				$out .= '\\"';
			} elseif ( '\\' === $c ) {
				$out .= '\\\\';
			} elseif ( $o < 0x20 ) {
				$out .= sprintf( '\\u%04x', $o );
			} else {
				$out .= $c; // Raw UTF-8 bytes pass through unescaped.
			}
		}
		return $out . '"';
	}
}
