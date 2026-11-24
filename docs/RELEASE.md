# Release & rollback runbook (SLICE-262-33)

D-262-Q12: плагин — отдельный публичный repo, свой релиз-цикл,
SVN-mirror для wp.org. Схемы/fixtures — один авторитетный источник
(server `spec/`); PHP потребляет копию `contracts/` без Node.

## Pre-release checklist

```bash
make check        # phpcs + phpstan
make test         # unit
make e2e          # fake-backend harness (pull-jobs, fault injection)
make contracts    # contracts/ ≡ server spec verbatim + checksums
make release      # dist/release-manifest.json
```

`make contracts` — **protocol-compat gate**: contract drift =
плагин и backend не согласны о wire-формате. Любое изменение
`contracts/` идёт только через `bin/sync-contracts.sh` —
правка копии руками запрещена (MANIFEST.md ловит).

## Release (одна атомарная точка)

1. **Version bump** — одновременно: `agentbadge.php` header
   `Version:`, `AGENTBADGE_VERSION`, `readme.txt` `Stable tag:` —
   все три места (manifest ловит рассинхрон plugin-header↔readme).
2. **Changelog** — `readme.txt` `== Changelog ==` новый блок
   `= X.Y.Z =` + `== Upgrade Notice ==` строка; manifest парсит
   обе секции verbatim.
3. `make release` → коммитить `dist/release-manifest.json` —
   это документ релиза (version, protocol v, WP/PHP range,
   changelog, sha256 каждого shipped-файла, security contact).
4. Tag `vX.Y.Z`; zip собирается по `.distignore` ship-list
   (dev-файлы не пакуются — тот же список что у checksums,
   списки не должны расходиться).
5. wp.org: SVN trunk = новая версия, `Stable tag:` указывает на
   tag-dir — svn-копия readme+trunk, не перепись истории.

## Rollback

- **wp.org** — нативный механизм: `Stable tag:` в trunk readme.txt
  откатывается на предыдущий tag; users получают «downgrade» как
  обычное обновление. Публиковать фикс-forward предпочтительнее.
- **Локально** — zip прошлого тега поверх; `agentbadge_version`
  option остаётся маркером «под какой версией стояли» —
  миграции ключуются от него, не от installed_at.
- **Данные** — `uninstall.php` сносит все plugin options включая
  credentials; rollback ≠ uninstall (данные переживают downgrade,
  это по дизайну).
- **Протокол** — rollback плагина НЕ откатывает backend protocol:
  server держит совместимость по `registry_version` (v1), новые
  capabilities — extension-филды, не breaking.

## Security contact

`SECURITY.md` → `security@agentbadge.xyz`; попадает в
`release-manifest.json → security_contact` автоматически.

## wp.org submission (SLICE-262-36)

Listing materials живут в `wordpress-org/` (не шипуется в zip —
wp.org берёт assets из SVN `assets/` отдельно):

- `readme.txt` (plugin root — wp.org-формат, ВХОДИТ в zip)
- `wordpress-org/assets/` — `icon-256x256.png`, `banner-772x250.png`,
  `screenshot-1..3.png`
- `docs/EPICS/.../SLICE-262-36-wporg-submission.md` — полный scope
  + publish gate

**Порядок:** чистая-инсталляция e2e (install→scan→connect→snapshot)
→ Plugin Check (PCF) 0 errors → zip upload на
`wordpress.org/plugins/developers/add/` → review 3-7 дней →
`svn co https://plugins.svn.wordpress.org/agentbadge` →
`trunk/` = dev, `tags/<ver>/` = frozen копия на каждый релиз,
`assets/` = listing-файлы. Fallback-слаг: `agentbadge-verify`.
