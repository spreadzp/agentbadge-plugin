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

### PCF env (воспроизводимо, ~2 мин)

```bash
docker run -d --name wp-pcf-db -p 127.0.0.1:33060:3306 \
  -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wpcheck mariadb:10.6
mkdir /tmp/wpcheck && cd /tmp/wpcheck
wp core download && wp config create --dbname=wpcheck --dbuser=root \
  --dbpass=root --dbhost=127.0.0.1:33060
wp core install --url=http://localhost:8585 --title=PCF \
  --admin_user=admin --admin_password=admin --admin_email=admin@example.com
wp plugin install plugin-check --activate
wp plugin install dist/agentbadge.zip --activate
wp plugin check agentbadge          # gate: 0 errors
```

Gate-прогон 2026-11-24: **0 errors / 0 warnings** (PCF 2.2.0,
WP 7.1.5). Первый прогон поймал: устаревший `Tested up to:`
(6.8→7.1), `wp_unslash` на `$_SERVER`, template-vars phpcs-scope.

### e2e gate (чистая установка, 2026-11-24)

- zip install → activate → Agentbadge menu ✓
- handshake `absk_` → proof `installation_control/verified` +
  audit consent `granted` ✓
- self-report cron → 12 envelopes на прод ✓
- `agentbadge_job_pull` → snapshot option заполнена
  (`site_id`, `snapshot_at`; attestation null до первого скана — по дизайну) ✓
- `wp plugin uninstall` → 8 options + 2 cron hooks вычищены полностью ✓

### wp.org аккаунт (ручной шаг перед submit)

`readme.txt` `Contributors:` должен быть реальным wp.org username —
`agentbadge` и `apryymak` на profiles.wordpress.org не существуют
(validator warning). Зарегистрировать аккаунт на
`login.wordpress.org/register`, обновить `Contributors:` на этот
handle ДО submit — wp.org привязывает плагин к этому профилю.
