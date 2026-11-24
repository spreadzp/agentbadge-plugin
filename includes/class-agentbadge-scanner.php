<?php
/**
 * Free local scanner (SLICE-262-13).
 *
 * Runs ~16 self-observable checks — what the plugin can honestly
 * see from inside WordPress. NO external requests, ever
 * (D-262-Q18): no HTTP, no loopback, no DNS.
 *
 * Results are self_observed facts, not independent verification —
 * the admin UI must say so (provenance honesty mirrors
 * PLUGIN_SELF_REPORTED on the cloud side).
 *
 * @package agentbadge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-agentbadge-env.php';
require_once __DIR__ . '/class-agentbadge-check-result.php';

/**
 * Check registry + runner.
 */
class Agentbadge_Scanner {

	/**
	 * AI crawler user-agents probed in robots.txt.
	 *
	 * @var string[]
	 */
	private const AI_USER_AGENTS = array( 'gptbot', 'claudebot', 'ccbot', 'oai-searchbot', 'perplexitybot' );

	/**
	 * Environment seam.
	 *
	 * @var Agentbadge_Env
	 */
	private Agentbadge_Env $env;

	/**
	 * Constructor.
	 *
	 * @param Agentbadge_Env|null $env Env seam (live WP by default).
	 */
	public function __construct( ?Agentbadge_Env $env = null ) {
		$this->env = $env ?? new Agentbadge_Env();
	}

	/**
	 * Run all checks.
	 *
	 * @return Agentbadge_Check_Result[]
	 */
	public function run(): array {
		return array(
			// ── Readiness: can agents discover/read ──
			$this->check_llms_txt(),
			$this->check_public_access(),
			$this->check_robots_ai(),
			$this->check_sitemap(),
			$this->check_jsonld(),
			$this->check_permalink(),
			$this->check_https(),
			$this->check_rest_api(),
			// ── Exposure: what is needlessly open ──
			$this->check_wp_debug(),
			$this->check_debug_display(),
			$this->check_file_modifications(),
			$this->check_generator_tag(),
			$this->check_readme_leak(),
			$this->check_xmlrpc(),
			$this->check_rest_users(),
			$this->check_open_registration(),
		);
	}

	// ─── Readiness ───────────────────────────────────────────────

	/**
	 * Check: llms.txt file present in site root.
	 *
	 * @return Agentbadge_Check_Result
	 */
	private function check_llms_txt(): Agentbadge_Check_Result {
		$id = 'r_llms_txt';
		$t  = 'llms.txt present';
		if ( $this->env->file_exists( ABSPATH . 'llms.txt' ) ) {
			return Agentbadge_Check_Result::pass( $id, $t, 'readiness', 'llms.txt exists in site root' );
		}
		return Agentbadge_Check_Result::warn(
			$id,
			$t,
			'readiness',
			'no llms.txt file found in site root',
			'Publish /llms.txt — a curated index of your site for AI agents.'
		);
	}

	/**
	 * Check: blog_public allows indexing.
	 *
	 * @return Agentbadge_Check_Result
	 */
	private function check_public_access(): Agentbadge_Check_Result {
		$id   = 'r_public_access';
		$t    = 'site open to indexing';
		$blog = $this->env->option( 'blog_public', '1' );
		if ( '0' === (string) $blog ) {
			return Agentbadge_Check_Result::fail(
				$id,
				$t,
				'readiness',
				'"Discourage search engines" is enabled — virtual robots.txt blocks all crawlers',
				'Settings → Reading → uncheck "Discourage search engines".'
			);
		}
		return Agentbadge_Check_Result::pass( $id, $t, 'readiness', 'blog_public allows indexing' );
	}

	/**
	 * Check: robots.txt does not block known AI agents.
	 *
	 * @return Agentbadge_Check_Result
	 */
	private function check_robots_ai(): Agentbadge_Check_Result {
		$id   = 'r_robots_ai';
		$t    = 'robots.txt does not block AI agents';
		$text = $this->env->robots_txt();
		if ( null === $text || '' === trim( $text ) ) {
			return Agentbadge_Check_Result::unknown( $id, $t, 'readiness', 'robots.txt content not determinable locally' );
		}
		$blocked = $this->ai_agents_blocked( $text );
		if ( count( $blocked ) === count( self::AI_USER_AGENTS ) ) {
			return Agentbadge_Check_Result::fail(
				$id,
				$t,
				'readiness',
				'robots.txt disallows all known AI agents (' . implode( ', ', $blocked ) . ')'
			);
		}
		if ( count( $blocked ) > 0 ) {
			return Agentbadge_Check_Result::warn(
				$id,
				$t,
				'readiness',
				'robots.txt disallows some AI agents: ' . implode( ', ', $blocked )
			);
		}
		return Agentbadge_Check_Result::pass( $id, $t, 'readiness', 'no AI-agent disallow rules found' );
	}

	/**
	 * Check: sitemap exists (physical or WP core).
	 *
	 * @return Agentbadge_Check_Result
	 */
	private function check_sitemap(): Agentbadge_Check_Result {
		$id = 'r_sitemap';
		$t  = 'sitemap configured';
		if ( $this->env->file_exists( ABSPATH . 'sitemap.xml' ) ) {
			return Agentbadge_Check_Result::pass( $id, $t, 'readiness', 'physical sitemap.xml exists' );
		}
		if ( $this->env->sitemaps_enabled() ) {
			return Agentbadge_Check_Result::pass( $id, $t, 'readiness', 'WP core sitemaps enabled (/wp-sitemap.xml)' );
		}
		return Agentbadge_Check_Result::warn(
			$id,
			$t,
			'readiness',
			'no physical sitemap.xml and WP core sitemaps disabled',
			'Enable wp-sitemap or an SEO plugin sitemap.'
		);
	}

	/**
	 * Check: theme emits JSON-LD (unknown when undeterminable).
	 *
	 * @return Agentbadge_Check_Result
	 */
	private function check_jsonld(): Agentbadge_Check_Result {
		$id = 'r_jsonld';
		$t  = 'structured data (JSON-LD)';
		if ( $this->env->theme_contains( $this->env->stylesheet_dir(), 'application/ld+json' ) ) {
			return Agentbadge_Check_Result::pass( $id, $t, 'readiness', 'active theme emits application/ld+json' );
		}
		return Agentbadge_Check_Result::unknown(
			$id,
			$t,
			'readiness',
			'no ld+json marker found in theme files — may still be emitted by a plugin at runtime',
			'Verify in page source; only an external check can confirm.'
		);
	}

	/**
	 * Check: pretty permalinks enabled.
	 *
	 * @return Agentbadge_Check_Result
	 */
	private function check_permalink(): Agentbadge_Check_Result {
		$id = 'r_permalink';
		$t  = 'readable permalinks';
		if ( '' !== $this->env->permalink_structure() ) {
			return Agentbadge_Check_Result::pass( $id, $t, 'readiness', 'pretty permalinks enabled' );
		}
		return Agentbadge_Check_Result::warn(
			$id,
			$t,
			'readiness',
			'plain ?p= permalinks — less readable for agents and humans',
			'Settings → Permalinks → choose a structured format.'
		);
	}

	/**
	 * Check: home URL is HTTPS.
	 *
	 * @return Agentbadge_Check_Result
	 */
	private function check_https(): Agentbadge_Check_Result {
		$id     = 'r_https';
		$t      = 'served over HTTPS';
		$scheme = wp_parse_url( $this->env->home_url(), PHP_URL_SCHEME );
		if ( 'https' === $scheme ) {
			return Agentbadge_Check_Result::pass( $id, $t, 'readiness', 'home URL uses https' );
		}
		return Agentbadge_Check_Result::fail(
			$id,
			$t,
			'readiness',
			'home URL is not https (' . (string) $scheme . ')',
			'Move the site to HTTPS — agents deprioritize plain http.'
		);
	}

	/**
	 * Check: WP REST routes registered.
	 *
	 * @return Agentbadge_Check_Result
	 */
	private function check_rest_api(): Agentbadge_Check_Result {
		$id     = 'r_rest_api';
		$t      = 'WP REST API available';
		$routes = $this->env->rest_routes();
		if ( count( $routes ) > 0 ) {
			return Agentbadge_Check_Result::pass(
				$id,
				$t,
				'readiness',
				count( $routes ) . ' REST routes registered — structured content is agent-readable'
			);
		}
		return Agentbadge_Check_Result::warn(
			$id,
			$t,
			'readiness',
			'no REST routes registered — API may be disabled by a plugin'
		);
	}

	// ─── Exposure ────────────────────────────────────────────────

	/**
	 * Check: WP_DEBUG off.
	 *
	 * @return Agentbadge_Check_Result
	 */
	private function check_wp_debug(): Agentbadge_Check_Result {
		$id    = 'x_wp_debug';
		$t     = 'WP_DEBUG disabled';
		$debug = $this->env->constant( 'WP_DEBUG' );
		if ( true === $debug ) {
			return Agentbadge_Check_Result::fail(
				$id,
				$t,
				'exposure',
				'WP_DEBUG is enabled — debug output may leak internals',
				'Set WP_DEBUG to false on production.'
			);
		}
		if ( null === $debug ) {
			return Agentbadge_Check_Result::pass( $id, $t, 'exposure', 'WP_DEBUG not defined (defaults to off)' );
		}
		return Agentbadge_Check_Result::pass( $id, $t, 'exposure', 'WP_DEBUG is false' );
	}

	/**
	 * Check: error display off.
	 *
	 * @return Agentbadge_Check_Result
	 */
	private function check_debug_display(): Agentbadge_Check_Result {
		$id      = 'x_debug_display';
		$t       = 'error display off';
		$display = $this->env->constant( 'WP_DEBUG_DISPLAY' );
		$ini     = $this->env->ini_get( 'display_errors' );
		if ( true === $display || '1' === (string) $ini || 'On' === $ini ) {
			return Agentbadge_Check_Result::warn(
				$id,
				$t,
				'exposure',
				'PHP errors may render to visitors (WP_DEBUG_DISPLAY or display_errors on)',
				'Set WP_DEBUG_DISPLAY false and display_errors=Off in production.'
			);
		}
		return Agentbadge_Check_Result::pass( $id, $t, 'exposure', 'error display disabled' );
	}

	/**
	 * Check: file edits disabled.
	 *
	 * @return Agentbadge_Check_Result
	 */
	private function check_file_modifications(): Agentbadge_Check_Result {
		$id   = 'x_file_mods';
		$t    = 'in-dashboard file edits locked';
		$edit = $this->env->constant( 'DISALLOW_FILE_EDIT' );
		$mods = $this->env->constant( 'DISALLOW_FILE_MODS' );
		if ( true === $edit || true === $mods ) {
			return Agentbadge_Check_Result::pass( $id, $t, 'exposure', 'file edits/modifications disabled via wp-config' );
		}
		return Agentbadge_Check_Result::warn(
			$id,
			$t,
			'exposure',
			'theme/plugin editor and installer active in wp-admin',
			'Consider DISALLOW_FILE_EDIT (and DISALLOW_FILE_MODS on locked-down hosts).'
		);
	}

	/**
	 * Check: generator meta not hooked.
	 *
	 * @return Agentbadge_Check_Result
	 */
	private function check_generator_tag(): Agentbadge_Check_Result {
		$id     = 'x_generator';
		$t      = 'version not advertised';
		$hooked = $this->env->has_action( 'wp_head', 'wp_generator' );
		if ( false === $hooked ) {
			return Agentbadge_Check_Result::pass( $id, $t, 'exposure', 'wp_generator removed from wp_head' );
		}
		return Agentbadge_Check_Result::warn(
			$id,
			$t,
			'exposure',
			'<meta name="generator"> advertises WordPress ' . $this->env->wp_version(),
			'Remove wp_generator or keep WordPress updated — version disclosure aids targeted scans.'
		);
	}

	/**
	 * Check: no version-revealing root files.
	 *
	 * @return Agentbadge_Check_Result
	 */
	private function check_readme_leak(): Agentbadge_Check_Result {
		$id    = 'x_readme_leak';
		$t     = 'no readme/version files exposed';
		$found = array();
		foreach ( array( 'readme.html', 'license.txt' ) as $f ) {
			if ( $this->env->file_exists( ABSPATH . $f ) ) {
				$found[] = $f;
			}
		}
		if ( array() === $found ) {
			return Agentbadge_Check_Result::pass( $id, $t, 'exposure', 'no version-revealing files in site root' );
		}
		return Agentbadge_Check_Result::warn(
			$id,
			$t,
			'exposure',
			'version-revealing files present: ' . implode( ', ', $found ),
			'Delete or block readme.html/license.txt — they disclose the WP version.'
		);
	}

	/**
	 * Check: XML-RPC off.
	 *
	 * @return Agentbadge_Check_Result
	 */
	private function check_xmlrpc(): Agentbadge_Check_Result {
		$id      = 'x_xmlrpc';
		$t       = 'XML-RPC disabled';
		$enabled = $this->env->filter( 'xmlrpc_enabled', true );
		if ( false === $enabled ) {
			return Agentbadge_Check_Result::pass( $id, $t, 'exposure', 'xmlrpc_enabled filtered off' );
		}
		return Agentbadge_Check_Result::warn(
			$id,
			$t,
			'exposure',
			'xmlrpc.php is enabled (brute-force amplification surface)',
			'Disable XML-RPC unless a client needs it (add_filter xmlrpc_enabled → false).'
		);
	}

	/**
	 * Check: users REST route not enumerable.
	 *
	 * @return Agentbadge_Check_Result
	 */
	private function check_rest_users(): Agentbadge_Check_Result {
		$id     = 'x_rest_users';
		$t      = 'user enumeration via REST';
		$routes = $this->env->rest_routes();
		$has    = in_array( '/wp/v2/users', $routes, true ) || in_array( '/wp/v2/users/(?P<id>[\\d]+)', $routes, true );
		if ( ! $has ) {
			return Agentbadge_Check_Result::pass( $id, $t, 'exposure', 'wp/v2/users route not registered' );
		}
		return Agentbadge_Check_Result::warn(
			$id,
			$t,
			'exposure',
			'/wp-json/wp/v2/users route registered — author names may be enumerable',
			'Restrict the users endpoint or verify externally; local check cannot confirm public visibility.'
		);
	}

	/**
	 * Check: registration closed or low-privilege.
	 *
	 * @return Agentbadge_Check_Result
	 */
	private function check_open_registration(): Agentbadge_Check_Result {
		$id       = 'x_open_registration';
		$t        = 'open registration controlled';
		$register = $this->env->option( 'users_can_register', '0' );
		if ( '1' !== (string) $register ) {
			return Agentbadge_Check_Result::pass( $id, $t, 'exposure', 'registration closed' );
		}
		$role = (string) $this->env->option( 'default_role', 'subscriber' );
		if ( in_array( $role, array( 'subscriber', 'contributor' ), true ) ) {
			return Agentbadge_Check_Result::warn(
				$id,
				$t,
				'exposure',
				'open registration with low-privilege default role (' . $role . ')'
			);
		}
		return Agentbadge_Check_Result::fail(
			$id,
			$t,
			'exposure',
			'open registration with privileged default role (' . $role . ')',
			'Close registration or set default role to subscriber.'
		);
	}

	// ─── helpers ─────────────────────────────────────────────────

	/**
	 * Parse robots.txt: which known AI agents get Disallow: /.
	 *
	 * @param string $text robots.txt body.
	 * @return string[] blocked agent names.
	 */
	private function ai_agents_blocked( string $text ): array {
		// Groups: consecutive User-agent lines then their rules.
		$groups   = array();
		$agents   = array();
		$in_rules = false;
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$line = trim( (string) preg_replace( '/#.*$/', '', $line ) );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '/^user-agent:\s*(.+)$/i', $line, $m ) ) {
				if ( $in_rules ) {
					$agents   = array();
					$in_rules = false;
				}
				$agents[] = strtolower( trim( $m[1] ) );
				continue;
			}
			if ( preg_match( '/^disallow:\s*(.*)$/i', $line, $m ) ) {
				$in_rules = true;
				foreach ( $agents as $ua ) {
					$groups[ $ua ][] = trim( $m[1] );
				}
			}
		}
		$blocked = array();
		foreach ( self::AI_USER_AGENTS as $agent ) {
			$rules = array_merge( $groups[ $agent ] ?? array(), $groups['*'] ?? array() );
			if ( in_array( '/', $rules, true ) ) {
				$blocked[] = $agent;
			}
		}
		return $blocked;
	}
}
