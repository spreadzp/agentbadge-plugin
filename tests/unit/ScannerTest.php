<?php
/**
 * Scanner checks — pure logic over a stub env (SLICE-262-13).
 * No WP needed: checks read through Agentbadge_Env, so tests
 * substitute a stub. The Brain Monkey TestCase base is used for
 * the few WP functions reached outside env (wp_parse_url).
 *
 * @package agentbadge
 */

use Brain\Monkey\Functions;

require_once __DIR__ . '/TestCase.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-agentbadge-env.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-agentbadge-check-result.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-agentbadge-scanner.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/fake-wp/' );
}

/**
 * Stub env — every field settable, no WordPress.
 */
class Stub_Env extends Agentbadge_Env {
	public array $constants   = array();
	public array $options     = array();
	public array $files       = array(); // path => contents|null
	public array $inis        = array();
	public string $home       = 'https://example.test';
	public string $permalink  = '/%postname%/';
	public string $wpv        = '6.7';
	public array $actions     = array(); // "hook:cb" => bool|int
	public array $filters     = array(); // hook => return
	public array $routes      = array( '/wp/v2/posts' );
	public ?string $robots    = "User-agent: *\nDisallow: /wp-admin/\n";
	public bool $sitemaps     = true;
	public string $theme_dir  = '/nonexistent-theme';
	public bool $theme_marker = false;

	public function constant( string $name ) { return $this->constants[ $name ] ?? null; }
	public function option( string $name, $default = false ) { return $this->options[ $name ] ?? $default; }
	public function file_exists( string $path ): bool { return array_key_exists( $path, $this->files ) && null !== $this->files[ $path ]; }
	public function file_read( string $path, int $max = 65536 ): ?string { return $this->files[ $path ] ?? null; }
	public function ini_get( string $name ) { return $this->inis[ $name ] ?? false; }
	public function home_url(): string { return $this->home; }
	public function permalink_structure(): string { return $this->permalink; }
	public function wp_version(): string { return $this->wpv; }
	public function has_action( string $hook, $callback ) { return $this->actions[ "$hook:$callback" ] ?? false; }
	public function filter( string $hook, $value ) { return $this->filters[ $hook ] ?? $value; }
	public function rest_routes(): array { return $this->routes; }
	public function robots_txt(): ?string { return $this->robots; }
	public function sitemaps_enabled(): bool { return $this->sitemaps; }
	public function stylesheet_dir(): string { return $this->theme_dir; }
	public function theme_contains( string $dir, string $needle, int $max_files = 60 ): bool { return $this->theme_marker; }
}

final class ScannerTest extends TestCase {

	private Stub_Env $env;
	private Agentbadge_Scanner $scanner;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		$this->env     = new Stub_Env();
		$this->scanner = new Agentbadge_Scanner( $this->env );
	}

	private function check_result( string $id ): Agentbadge_Check_Result {
		foreach ( $this->scanner->run() as $r ) {
			if ( $r->id === $id ) {
				return $r;
			}
		}
		$this->fail( "check $id not produced" );
	}

	public function test_scan_returns_all_checks(): void {
		$results = $this->scanner->run();
		$this->assertCount( 16, $results );
		foreach ( $results as $r ) {
			$this->assertContains( $r->status, array( 'pass', 'warn', 'fail', 'unknown' ) );
			$this->assertSame( 'self_observed', Agentbadge_Check_Result::SOURCE );
		}
	}

	public function test_happy_path_all_axes_clean(): void {
		$results = $this->scanner->run();
		$statuses = array_count_values( array_map( fn( $r ) => $r->status, $results ) );
		$this->assertSame( 0, $statuses['fail'] ?? 0, 'default env must not produce fails' );
	}

	// ── readiness ──

	public function test_llms_present(): void {
		$this->assertSame( 'warn', $this->check_result( 'r_llms_txt' )->status );
		$this->env->files[ ABSPATH . 'llms.txt' ] = '# llms';
		$this->assertSame( 'pass', $this->check_result( 'r_llms_txt' )->status );
	}

	public function test_public_access_blocked(): void {
		$this->env->options['blog_public'] = '0';
		$r = $this->check_result( 'r_public_access' );
		$this->assertSame( 'fail', $r->status );
		$this->assertStringContainsString( 'Discourage', $r->reason );
	}

	public function test_robots_blocks_all_ai(): void {
		$this->env->robots = "User-agent: *\nDisallow: /\n";
		$r = $this->check_result( 'r_robots_ai' );
		$this->assertSame( 'fail', $r->status );
	}

	public function test_robots_blocks_some_ai(): void {
		$this->env->robots = "User-agent: GPTBot\nDisallow: /\nUser-agent: *\nDisallow: /wp-admin/\n";
		$r = $this->check_result( 'r_robots_ai' );
		$this->assertSame( 'warn', $r->status );
		$this->assertStringContainsString( 'gptbot', $r->reason );
	}

	public function test_robots_allows_all(): void {
		$r = $this->check_result( 'r_robots_ai' );
		$this->assertSame( 'pass', $r->status );
	}

	public function test_robots_undeterminable_is_unknown(): void {
		$this->env->robots = null;
		$this->assertSame( 'unknown', $this->check_result( 'r_robots_ai' )->status );
	}

	public function test_sitemap_paths(): void {
		$this->assertSame( 'pass', $this->check_result( 'r_sitemap' )->status ); // core sitemaps on
		$this->env->sitemaps = false;
		$this->assertSame( 'warn', $this->check_result( 'r_sitemap' )->status );
		$this->env->files[ ABSPATH . 'sitemap.xml' ] = '<xml/>';
		$this->assertSame( 'pass', $this->check_result( 'r_sitemap' )->status );
	}

	public function test_jsonld_unknown_not_pass(): void {
		// No theme marker → honest unknown, never pass (grill DoD).
		$this->assertSame( 'unknown', $this->check_result( 'r_jsonld' )->status );
		$this->env->theme_marker = true;
		$this->assertSame( 'pass', $this->check_result( 'r_jsonld' )->status );
	}

	public function test_https_required(): void {
		$this->assertSame( 'pass', $this->check_result( 'r_https' )->status );
		$this->env->home = 'http://example.test';
		$this->assertSame( 'fail', $this->check_result( 'r_https' )->status );
	}

	public function test_rest_api_routes(): void {
		$this->assertSame( 'pass', $this->check_result( 'r_rest_api' )->status );
		$this->env->routes = array();
		$this->assertSame( 'warn', $this->check_result( 'r_rest_api' )->status );
	}

	// ── exposure ──

	public function test_wp_debug_enabled_fails(): void {
		$this->env->constants['WP_DEBUG'] = true;
		$this->assertSame( 'fail', $this->check_result( 'x_wp_debug' )->status );
		$this->env->constants['WP_DEBUG'] = false;
		$this->assertSame( 'pass', $this->check_result( 'x_wp_debug' )->status );
	}

	public function test_debug_display(): void {
		$this->env->constants['WP_DEBUG_DISPLAY'] = true;
		$this->assertSame( 'warn', $this->check_result( 'x_debug_display' )->status );
		unset( $this->env->constants['WP_DEBUG_DISPLAY'] );
		$this->env->inis['display_errors'] = '1';
		$this->assertSame( 'warn', $this->check_result( 'x_debug_display' )->status );
		$this->env->inis['display_errors'] = '0';
		$this->assertSame( 'pass', $this->check_result( 'x_debug_display' )->status );
	}

	public function test_file_mods(): void {
		$this->assertSame( 'warn', $this->check_result( 'x_file_mods' )->status );
		$this->env->constants['DISALLOW_FILE_EDIT'] = true;
		$this->assertSame( 'pass', $this->check_result( 'x_file_mods' )->status );
	}

	public function test_generator_tag(): void {
		$this->assertSame( 'pass', $this->check_result( 'x_generator' )->status );
		$this->env->actions['wp_head:wp_generator'] = 10;
		$this->assertSame( 'warn', $this->check_result( 'x_generator' )->status );
	}

	public function test_readme_leak(): void {
		$this->assertSame( 'pass', $this->check_result( 'x_readme_leak' )->status );
		$this->env->files[ ABSPATH . 'readme.html' ] = '<html/>';
		$r = $this->check_result( 'x_readme_leak' );
		$this->assertSame( 'warn', $r->status );
		$this->assertStringContainsString( 'readme.html', $r->reason );
	}

	public function test_xmlrpc(): void {
		$this->assertSame( 'warn', $this->check_result( 'x_xmlrpc' )->status ); // default on
		$this->env->filters['xmlrpc_enabled'] = false;
		$this->assertSame( 'pass', $this->check_result( 'x_xmlrpc' )->status );
	}

	public function test_rest_users_route(): void {
		// users route not registered → pass
		$this->assertSame( 'pass', $this->check_result( 'x_rest_users' )->status );
		$this->env->routes[] = '/wp/v2/users';
		$r = $this->check_result( 'x_rest_users' );
		$this->assertSame( 'warn', $r->status ); // route registered → enumerable
	}

	public function test_open_registration(): void {
		$this->assertSame( 'pass', $this->check_result( 'x_open_registration' )->status );
		$this->env->options['users_can_register'] = '1';
		$this->env->options['default_role']       = 'subscriber';
		$this->assertSame( 'warn', $this->check_result( 'x_open_registration' )->status );
		$this->env->options['default_role'] = 'editor';
		$this->assertSame( 'fail', $this->check_result( 'x_open_registration' )->status );
	}

	public function test_result_serializes_with_source(): void {
		$arr = $this->check_result( 'r_https' )->to_array();
		$this->assertSame( 'self_observed', $arr['source'] );
		$this->assertSame( 'readiness', $arr['axis'] );
	}
}
