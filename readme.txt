=== AgentBadge — Agent Readiness ===
Contributors: paul2029
Tags: security, ai, verification, agents, scanner
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Signed, third-party verifiable evidence about how ready your site is for AI agents — local scanning plus optional cloud attestation.

== Description ==

AgentBadge measures two surfaces of your site from an AI agent's point of view:

* **Readiness** — can agents actually use you? llms.txt, robots.txt AI rules, sitemaps, .well-known agent cards, JSON-LD, REST index, sitemap freshness, and more (~40 checks).
* **Exposure** — what do agents accidentally see? readme/debug files, xmlrpc.php, user enumeration, version leaks, backup artifacts, directory listing, SQL error leaks (~15 checks).

The local scanner runs entirely inside WordPress — nothing leaves your site until you opt in.

= Optional cloud verification =

Connect the plugin to AgentBadge Cloud with a site key and the picture becomes independently verifiable:

* **Self-report channel** — your scan results are shipped as signed, timestamped evidence envelopes.
* **External scan** — the cloud re-checks your public surface from the outside (no trust in self-report alone).
* **Signed report + attestation** — findings from both channels are assembled into a signed report; an attestation binds your scores to a validity window.
* **Public verification** — with your explicit consent, a public verify endpoint lets anyone check the attestation and its signature. Off by default.
* **Pull jobs** — operators can ask your site for whitelisted, read-only diagnostics (e.g. "is DISALLOW_FILE_EDIT defined?"). Every job is signed, expires quickly, executes nothing outside a fixed capability list, and can be switched off by revoking the `inside_diagnostics` consent at any time.
* **Status snapshot** — the plugin periodically pulls its own latest attestation and report findings and caches them in a WP option, so themes and portal integrations can render your status without a live API call.

= Consent model =

Every data flow is behind an explicit, per-purpose consent toggle you can revoke at any time. Revocation stops the flow on the next cycle — the plugin mirrors consent locally and the server re-checks it server-side on every call.

= Security =

* Signed envelopes (Ed25519) end-to-end — the cloud cannot fake your evidence, you cannot fake theirs.
* TOFU-pinned keyset: the trusted signing root is captured on first connect and cleared on disconnect.
* Pull jobs are read-only by construction: signed bodies, nonce deduplication, expiry, whitelisted capabilities, fail-closed on any doubt.
* Your site key stays in a protected WP option and is removed by uninstall.
* Responsible disclosure: see SECURITY.md in the repository.

== Installation ==

1. Upload the plugin zip (Plugins → Add New → Upload) or install via wp.org directory.
2. Activate — an "AgentBadge" menu appears under Tools.
3. Run a local scan to see readiness and exposure findings immediately.
4. (Optional) Paste your `absk_` site key from AgentBadge Cloud to enable evidence shipping, pull jobs, and attestations.

== Frequently Asked Questions ==

= Does anything leave my site without consent? =

No. The local scanner is fully offline. Cloud features require connecting with a site key, and each data flow (self-report, pull jobs, publication) has its own consent toggle.

= What exactly is collected? =

Facts your site already exposes publicly (robots.txt, headers, well-known files, sitemaps) plus plugin-side configuration facts for diagnostics (e.g. whether WP_DEBUG is defined). Content, users, and secrets are never collected. Evidence rows carry a retention deadline — expired evidence is swept on each intake.

= Can a pull job change my site? =

No. The protocol is whitelisted read-only probes — "read a constant", "summarize phpinfo flags". Job bodies are signed, expire in minutes, are deduplicated by nonce, and any malformed or unsigned job is skipped without executing.

= How do I disconnect or revoke? =

Disconnect clears the stored site key and the pinned trust keyset. Individual consents can be revoked independently in the Cloud tab. Uninstalling removes every `agentbadge_*` option, cron hook, and the capability the plugin granted at activation.

= Is it multisite-compatible? =

Not tested on multisite — per-site state is stored in per-site options, but the plugin is only validated on single installs so far.

= Is it free? =

The plugin is free. The optional cloud service runs in private beta — tiers are defined on agentbadge.xyz, no upsell code inside the plugin.

== External Services ==

This plugin can optionally connect to the AgentBadge Cloud API at `agentbadge.xyz` (service operated by the plugin author). The local scanner works fully offline — the connection is only used after you paste a site key in the Cloud tab.

* **What the service is:** a verification backend that receives your site's scan evidence, runs an independent external scan of your public surface, and returns a signed report and attestation.
* **What data is sent and when:** signed evidence envelopes containing scan check results (public-surface facts like robots.txt, headers, well-known files, sitemaps, plus plugin-side diagnostics such as whether WP_DEBUG is defined). Sent on the configured report cycle and only while the matching consent toggle is on. Content, user data, and secrets are never sent.
* **Account:** no account registration is required — a site key (issued via agentbadge.xyz or the onboarding flow) identifies the site.
* **Terms and policies:** terms of service — https://agentbadge.xyz/terms ; privacy policy — https://agentbadge.xyz/privacy

== Screenshots ==

1. Local scan results — readiness and exposure findings with evidence counts.
2. Cloud connect — paste the site key, review the consent toggles.
3. Connected state — last report, snapshot freshness, pull-job activity.

== Changelog ==

= 0.1.0 =
* Initial release: local readiness/exposure scanner, cloud evidence channel, signed pull jobs, attestation verification, local status snapshot.
