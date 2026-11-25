# wp.org submission — ручной runbook (SLICE-262-36 → submit)

Пошаговая инструкция от «готового zip» до живого листинга.
Код-часть закрыта (PCF 0/0, e2e, uninstall, validator 0 errors) —
дальше только руки.

## Шаг 0 — пререквизиты (один раз)

```bash
sudo apt install subversion          # нужен для post-approval SVN
cd /home/dev/Projects/PRYME/WP-plugins/agentbadge
make check && make test              # регрессия перед заморозкой
bash bin/build-zip.sh dist/agentbadge.zip
```

## Шаг 1 — wp.org аккаунт (~10 мин)

1. `https://login.wordpress.org/register` — email, username.
   Username станет `Contributors:` в readme и владельцем плагина.
   Рекомендация: **личный, не «agentbadge»** — ревьюеры смотрят
   на профиль; свежий пустой аккаунт норм, но имя должно быть
   человеческим (или корп-аккаунтом с сайтом).
2. Подтвердить email, зайти в `profiles.wordpress.org/<handle>` —
   заполнить display name + website = `agentbadge.xyz` (доверие
   при ревью).

## Шаг 2 — Contributors в readme (~5 мин)

```bash
# readme.txt, строка 2:
Contributors: <твой_handle>
bash bin/build-zip.sh dist/agentbadge.zip   # пересобрать
```

Прогнать validator ещё раз — warning про Contributors уйдёт.

## Шаг 3 — Submit ✅ DONE 2026-11-25

Выполнено через Playwright (аккаунт `paul2029` / Pavel Spread):

- readme.txt: добавлена секция `== External Services ==`
  (guideline 6 — декларация agentbadge.xyz API, data flows,
  consent gating, terms/privacy) — **перед** сабмитом, commit
  `2ac736b`, validator 0 errors / 0 warnings.
- Upload `dist/agentbadge.zip` (sha256 `83b45475…`, 49 файлов)
  через multipart-POST на `/plugins/developers/add/` —
  file input не кликался из-под автоматизации, обошлось
  `fetch(File)` + `FormData`.
- 8/8 requirement-чекбоксов + comment для ревьюеров
  (serviceware-декларация, PCF 0/0, fresh-install e2e 7.1.5,
  uninstall чист, repo public).
- **Slug**: auto-assigned `agentbadge-agent-readiness` →
  сменил на **`agentbadge`** через REST
  `PATCH /plugins/wp-json/plugins/v1/upload/<id>/slug`
  (поле `post_name` + `_wporg_action` nonce; смена доступна
  только ДО старта review).
- Подтверждение: «Thank you for uploading AgentBadge — Agent
  Readiness», queue ~260 plugins, review email уйдёт на
  spread2009@gmail.com от plugins@wordpress.org.

Профиль аккаунта: Name `Pavel Spread`, bio про AgentBadge,
website agentbadge.xyz, location USA, interests AI/Blockchain.
Подводный камень profiles.wordpress.org: buddypress-форма
падает с «problem updating» если required checkbox-поле
(field_8 Personal/Business) снято — сейв идёт частями по
field_ids либо с отмеченными чекбоксами.

## Шаг 4 — Review (1–10 дней, ~260 в очереди)

- Статус приходит на email аккаунта + виден на
  `wordpress.org/plugins/developers/` (очередь pending).
- **Типичные вопросы ревьюеров** (готовые ответы):
  - «remote calls» → readme секция «What data leaves the site» +
    FAQ — сервис-декларация guideline 6 уже покрыта
  - «trialware?» → весь плагин работает без ключа (local scan
    free), cloud — отдельный API поверх (Akismet-модель)
  - «prefix» → везде `agentbadge_`/`Agentbadge_`/`AGENTBADGE_`
  - «uninstall» → `uninstall.php` чистит 15 options + 4 cron + cap
    (прогнано на чистой WP — 0 остатков)
- Отвечать по email треда, быстро — ревью идёт по очереди.

## Шаг 5 — SVN layout после approve (один раз)

```bash
svn co https://plugins.svn.wordpress.org/agentbadge agentbadge-svn
cd agentbadge-svn        # внутри: trunk/  tags/  assets/
# trunk/ = РАБОЧАЯ копия = содержимое zip БЕЗ обёртки-папки:
unzip -q ../agentbadge/dist/agentbadge.zip -d /tmp/rel
rsync -a --delete /tmp/rel/agentbadge/ trunk/
# assets (НЕ в trunk): иконки/баннеры/скриншоты
cp ../agentbadge/wordpress-org/assets/* assets/
svn add trunk assets --force
svn ci -m "Initial release 0.1.0"
```

## Шаг 6 — Первый tag + Stable tag

```bash
svn cp trunk tags/0.1.0
svn ci -m "Tag 0.1.0"
# readme.txt trunk: `Stable tag: 0.1.0` — УЖЕ стоит (version-sync gate)
```

`Stable tag:` в trunk/readme.txt указывает на `tags/0.1.0` —
парсер берёт ВСЁ (readme + код) из тега. Правки в trunk без
нового тега на листинге невидимы.

## Шаг 7 — Проверка листинга (~15 мин, кэш ~15 мин на пропагацию)

- `wordpress.org/plugins/agentbadge/` — description/FAQ/
  screenshots/icon/banner из `assets/` + tag-readme.
- Чистая WP → `wp plugin install agentbadge` → activate →
  smoke-scan — финальная проверка «как у юзера».

## Релиз-цикл после этого (каждая версия)

```bash
# 1) bump 3 мест: Version header + AGENTBADGE_VERSION + Stable tag
#    (build-release.php гейтит рассинхрон)
# 2) readme.txt: новый блок == Changelog == + == Upgrade Notice ==
# 3) make zip → e2e smoke → PCF
# 4) git tag vX.Y.Z
cd agentbadge-svn && svn up
rsync -a --delete /tmp/rel/agentbadge/ trunk/
svn cp trunk tags/X.Y.Z
svn ci -m "Release X.Y.Z"
```

Rollback на wp.org = вернуть `Stable tag:` на прошлый тег +
`svn ci` readme — юзеры получат «downgrade» как обновление.

## Частые грабли (из Context7 handbook + нашего прогона)

- **`Stable tag: trunk` — запрещён**, ломает rollback; всегда тег.
- Readme правится в **trunk и tag** — tag-readme указывает сам
  на себя; иначе листинг не обновится.
- Не паковать `wordpress-org/` в zip — assets только в SVN assets/.
- Не коммитить в SVN dev-файлы — ship-list = `.distignore`.
- Кэш wp.org ~15 мин — не паниковать, если листинг не сразу новый.
