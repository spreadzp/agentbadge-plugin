=== AgentBadge — Agent Readiness ===
Contributors: agentbadge
Tags: ai, llms.txt, agents, seo, security
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Measures how ready your site is for AI agents — and how controlled your agent-facing surface is.

== Description ==

AI agents (ChatGPT, Claude, crawlers, MCP clients) already visit your site.
AgentBadge measures two things:

* **Agent Readiness** — can agents find, understand and use your site?
  (llms.txt, robots.txt, sitemaps, .well-known, JSON-LD, WP REST API)
* **Agent Surface Exposure** — is your agent-facing surface under control?
  (exposed endpoints, debug output, unintended access)

The free scanner runs fully locally inside WordPress — no account, no
external calls. Optional AgentBadge Cloud adds independent external
verification: checks from outside your hosting, signed reports, history
and monitoring.

== Privacy / External services ==

The free scanner makes NO external calls.

When you explicitly connect AgentBadge Cloud (opt-in), the plugin
communicates with api.agentbadge.xyz: it sends a site identity key and
self-reported diagnostic results, and pulls signed diagnostic jobs.
No content, credentials or personal data are sent. Inside diagnostics
run a fixed, reviewed capability set — the Cloud never executes code
on your site. Full details: https://agentbadge.xyz/plugin-privacy

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` or install via Plugins → Add New.
2. Activate.
3. Open AgentBadge in the admin menu and run your first local scan.

== Frequently Asked Questions ==

= Does it require an account? =
No. The local scanner works standalone. Cloud features are opt-in.

= Does it change my site? =
No changes without your explicit approval. Fixes show a preview diff
first and are verifiable after applying.

= What data is sent to AgentBadge Cloud? =
Only when you connect: a site key and diagnostic results from a fixed
capability set — never post content, options secrets or credentials.

== Changelog ==

= 0.1.0 =
* Initial scaffold — bootstrap, admin entry point, toolchain.

== Upgrade Notice ==

0.1.0 — initial development scaffold, not yet feature-complete.
