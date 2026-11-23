# AgentBadge — Agent Readiness (WordPress plugin)

Free local scanner for how ready a WordPress site is for AI agents —
plus optional AgentBadge Cloud (independent external verification,
signed reports, history).

Part of **Agent Surface Intelligence** (EPIC-262): the plugin is the
first execution adapter — a thin collector + fixed-capability
diagnostic endpoint. All scoring and signing happens backend-side;
the plugin never self-certifies.

## Repo map

| Path | What |
|---|---|
| `agentbadge.php` | plugin header + bootstrap |
| `includes/` | `class-agentbadge-*.php` — plugin, scanner, cloud client, admin, fixer |
| `templates/` | wp-admin views (WP-native UI) |
| `tests/` | PHPUnit + Brain Monkey (no WP install needed) |
| `docs/EPICS/` | plugin-local docs; product epic lives in hedera `docs/EPICS/262-*` |
| `creds/` | gitignored local creds — never commit |

## Dev loop

```bash
composer install
composer run test    # PHPUnit + Brain Monkey
composer run lint    # PHPCS WordPress-Extra + Docs
composer run stan    # PHPStan level 5
composer run check   # all three
```

Graphs after commit (indexes = committed code):

```bash
codegraph index --path . --exclude vendor --exclude node_modules --exclude graphify-out --exclude .codegraph
rm -rf /tmp/ab-graph && mkdir -p /tmp/ab-graph/includes
cp agentbadge.php uninstall.php /tmp/ab-graph/ && cp includes/*.php /tmp/ab-graph/includes/
graphify /tmp/ab-graph/ --out . --no-viz && graphify cluster-only . && rm -rf /tmp/ab-graph
```

## Rules

- Prefixes `agentbadge_`/`Agentbadge_`/`AGENTBADGE_`, text domain `agentbadge`
- `agentbadge_*` capabilities on every admin action — not bare `manage_options`
- Nonces on forms/AJAX, `esc_*()` on output, `$wpdb->prepare()` on queries
- No secrets in code/commits — `creds/` and `.env*` are gitignored
- Cloud never sends code to execute — fixed `probe_id` capabilities only
