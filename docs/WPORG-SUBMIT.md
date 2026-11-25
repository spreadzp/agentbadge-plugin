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

## Шаг 3 — Submit (~15 мин)

1. Залогиниться на `wordpress.org` тем же аккаунтом.
2. `https://wordpress.org/plugins/developers/add/` → upload
   `dist/agentbadge.zip` (та же сборка, что прошла PCF!).
3. Форма: Plugin Name = `AgentBadge — Agent Readiness`
   (как в `=== ... ===` readme), slug предложат `agentbadge` —
   если занят, fallback `agentbadge-verify` (задокументировано
   в слайсе).
4. Подтвердить compliance-чекбоксы: GPLv2+ совместимость,
   следование guidelines, код — твой собственный.

## Шаг 4 — Review (3–14 дней)

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
