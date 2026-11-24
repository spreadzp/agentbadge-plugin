<?php
/**
 * WordPress environment seam — every WP call the scanner needs,
 * behind one mockable interface (SLICE-262-13).
 *
 * Checks stay pure: they read through Agentbadge_Env, never call
 * WP functions directly. Unit tests substitute a stub env — no
 * Brain Monkey needed for check logic.
 *
 * @package agentbadge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Live WordPress environment adapter.
 */
class Agentbadge_Env {

	/**
	 * WP-config constant value or null when undefined.
	 *
	 * @param string $name Constant name.
	 * @return mixed
	 */
	public function constant( string $name ) {
		return defined( $name ) ? constant( $name ) : null;
	}

	/**
	 * Get_option() passthrough.
	 *
	 * @param string $name    Option name.
	 * @param mixed  $fallback Default.
	 * @return mixed
	 */
	public function option( string $name, $fallback = false ) {
		return get_option( $name, $fallback );
	}

	/**
	 * File_exists() passthrough.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public function file_exists( string $path ): bool {
		return is_file( $path );
	}

	/**
	 * Read a small text file or null. Bounded read.
	 *
	 * @param string $path Absolute path.
	 * @param int    $max  Max bytes.
	 * @return string|null
	 */
	public function file_read( string $path, int $max = 65536 ): ?string {
		if ( ! $this->file_exists( $path ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file, bounded
		$data = file_get_contents( $path, false, null, 0, $max );
		return false === $data ? null : $data;
	}

	/**
	 * Ini_get() passthrough.
	 *
	 * @param string $name ini name.
	 * @return string|false
	 */
	public function ini_get( string $name ) {
		return ini_get( $name );
	}

	/**
	 * Home_url() passthrough.
	 *
	 * @return string
	 */
	public function home_url(): string {
		return (string) home_url();
	}

	/**
	 * Permalink_structure option.
	 *
	 * @return string
	 */
	public function permalink_structure(): string {
		return (string) get_option( 'permalink_structure', '' );
	}

	/**
	 * WordPress version.
	 *
	 * @return string
	 */
	public function wp_version(): string {
		global $wp_version;
		return (string) $wp_version;
	}

	/**
	 * PHP version.
	 *
	 * @return string
	 */
	public function php_version(): string {
		return PHP_VERSION;
	}

	/**
	 * Has_action() passthrough.
	 *
	 * @param string          $hook     Hook name.
	 * @param callable|string $callback Callback to look up.
	 * @return bool|int
	 */
	public function has_action( string $hook, $callback ) {
		return has_action( $hook, $callback );
	}

	/**
	 * Apply_filters() passthrough (single arg).
	 *
	 * @param string $hook  Filter name.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	public function filter( string $hook, $value ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- intentional seam: callers pass the hook name
		return apply_filters( $hook, $value );
	}

	/**
	 * Registered REST routes (names like /wp/v2/users).
	 *
	 * @return string[]
	 */
	public function rest_routes(): array {
		if ( ! function_exists( 'rest_get_server' ) ) {
			return array();
		}
		return array_keys( rest_get_server()->get_routes() );
	}

	/**
	 * Robots.txt content: physical file wins; otherwise the
	 * virtual robots.txt WordPress generates for public sites.
	 *
	 * @return string|null
	 */
	public function robots_txt(): ?string {
		$physical = ABSPATH . 'robots.txt';
		if ( $this->file_exists( $physical ) ) {
			return $this->file_read( $physical );
		}
		// Virtual robots.txt — core do_robots() text for public sites.
		$text = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";
		$text = (string) $this->filter( 'robots_txt', $text );
		return $text;
	}

	/**
	 * Whether WP core sitemaps are enabled.
	 *
	 * @return bool
	 */
	public function sitemaps_enabled(): bool {
		if ( ! function_exists( 'wp_sitemaps_get_server' ) ) {
			return false;
		}
		return (bool) $this->filter( 'wp_sitemaps_enabled', true );
	}

	/**
	 * Active theme directory (bounded JSON-LD scan).
	 *
	 * @return string
	 */
	public function stylesheet_dir(): string {
		return (string) get_stylesheet_directory();
	}

	/**
	 * Server software family (apache/nginx/litespeed/other)
	 * — normalized, never the raw banner string.
	 *
	 * @return string
	 */
	public function server_software(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalized below, never echoed raw
		$raw = strtolower( (string) ( $_SERVER['SERVER_SOFTWARE'] ?? '' ) );
		foreach ( array( 'apache', 'nginx', 'litespeed', 'caddy', 'iis' ) as $family ) {
			if ( str_contains( $raw, $family ) ) {
				return $family;
			}
		}
		return 'unknown';
	}

	/**
	 * Bounded scan of theme files for a marker string.
	 * Limited file count + size — never reads user uploads.
	 *
	 * @param string $dir    Directory to scan.
	 * @param string $needle Marker to find.
	 * @param int    $max_files File cap.
	 * @return bool
	 */
	public function theme_contains( string $dir, string $needle, int $max_files = 60 ): bool {
		if ( ! is_dir( $dir ) ) {
			return false;
		}
		$count = 0;
		$it    = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $it as $file ) {
			if ( $count++ >= $max_files ) {
				break;
			}
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$data = $this->file_read( $file->getPathname(), 32768 );
			if ( null !== $data && false !== stripos( $data, $needle ) ) {
				return true;
			}
		}
		return false;
	}
}
