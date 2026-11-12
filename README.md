# Alpha Vault

Prywatna strefa tradera Alpha Moves. Produkcja: <https://alphavault.pl>. Repozytorium: <https://github.com/miloszspyrka-code/av> (publiczne).

Aplikacja działa na hostingu współdzielonym LH.pl:

- frontend: dotychczasowy HTML/CSS/JS (`public/`),
- backend: PHP 8.3 (`app/`),
- baza: MySQL/MariaDB,
- logowanie: Discord OAuth2 (`identify guilds.members.read`),
- sesje: token w cookie HttpOnly + hash w MySQL,
- prop challenge: szablony FTMO i innych firm, limity, alerty i statystyki per challenge ([docs/PROP.md](docs/PROP.md)),
- wdrożenia: GitHub Actions → SSH → `alphavault.pl` (alternatywnie `scripts/local-deploy.sh`).

Node.js 24 jest używany tylko lokalnie i w CI do testów Playwright. Na serwerze produkcyjnym nie działa proces Node.

## Dokumentacja

- [PRD.md](PRD.md) — wymagania produktowe, FR-xx, kryteria akceptacji.
- [DOCUMENTATION.md](DOCUMENTATION.md) — jak działa aplikacja, punkty styku P1–P14.
- [AGENTS.md](AGENTS.md) — kontrakt agenta AV-engineer.
- [docs/README.md](docs/README.md) — indeks dokumentacji technicznej.

## Struktura

```text
public/            # document root: index.php, index.html, js/, docs/
app/               # PHP: Config, Db, Auth, Discord, DiscordEvents, routes
bin/               # sync-discord-events.php (LH Cron)
config/            # przykładowe reguły klasyfikacji wydarzeń
db/                # schema.sql, demo-state.json, migrations
tests/             # API, UI, empty-state, Discord, dokumentacja
docs/              # dokumentacja techniczna
legacy/            # poprzedni backend Express (referencja, nie jest wdrażany)
scripts/           # deploy produkcyjny, local-deploy, sync-docs
.github/workflows/ # CI i deployment
```

## Lokalnie

Wymagania: PHP 8.3 z `pdo_mysql`, `curl`, `mbstring`; MySQL 8.x/MariaDB; Node.js 24 do testów.

```powershell
Copy-Item tests/config.test.example.json tests/config.test.json
# uzupełnij DB_* w tests/config.test.json (lokalne dane, plik jest ignorowany)

$env:ALPHA_VAULT_CONFIG = "tests/config.test.json"
php app/db/migrate.php
php -S 127.0.0.1:4173 -t public public/router.php

npm ci
npm test                 # API: 23 scenariusze
npm run test:prop        # silnik prop challenge: 27 scenariuszy
npm run test:ui          # UI: 52 scenariusze
npm run test:pwa         # PWA mobile: 20 scenariuszy
npm run test:ui:empty    # puste pola: 12 scenariuszy (osobny serwer)
npm run test:events      # Discord DB/sync + DST: 24 scenariusze
npm run test:macro       # kalendarz makro DB/sync: 21 scenariuszy
npm run test:quotes      # notowania rynkowe: 14 scenariuszy
npm run test:wearables   # urządzenia zdrowotne API + UI: 42 scenariusze
npm run test:docs        # linki i spójność dokumentacji
npm run test:encoding    # UTF-8, brak mojibake w kodzie i testach
```

Konfiguracja lokalna może też pochodzić ze zmiennych środowiskowych `APP_URL`, `DB_*`, `DISCORD_*`, `SESSION_SECRET`, `ALLOW_DEMO_LOGIN`. Plik `config.json` jest ignorowany przez Git.

## Produkcja

- Domena: `https://alphavault.pl`
- Document root: `~/public_html/alphavault.pl`
- Kod aplikacji i `config.json`: `~/public_html/alphavault.pl/.app` (ukryty katalog, deny)
- Deployment: deploy na `main` uruchamia `.github/workflows/deploy-alphavault.yml` dopiero po zielonym CI (`workflow_run`) i przy `DEPLOY_ENABLED=true`.
- CI gate: PR do `main` wymaga zielonego `PHP + API + UI`; branch protection blokuje bezpośredni push, force push i usuwanie gałęzi.
- Environment `alphavault-production` dopuszcza deploy tylko z `main`; merge commit jest wyłączony (squash/rebase).
- Repo jest publiczne; włączone secret scanning, push protection i Dependabot security updates.
- Rollback: workflow z `rollback=true` przywraca ostatni backup z `~/alphavault-backups`.

**Stan 2026-11-12:** produkcja wdrożona, `/api/health` OK (DB + Discord flagi `true`), sync Discord 3/3, `/docs/` 200, LH Cron działa co 5 min (log `18:10:02`) oraz co 15 min dla makro, CI gate `PHP + API + UI` aktywny.

Wymagane sekrety GitHub:

`LH_SSH_HOST`, `LH_SSH_PORT`, `LH_SSH_USER`, `LH_SSH_PRIVATE_KEY`, `LH_SSH_KNOWN_HOSTS`, `LH_DEPLOY_PATH`, `LH_APP_PATH`, `SESSION_SECRET`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DISCORD_CLIENT_ID`, `DISCORD_CLIENT_SECRET`, `DISCORD_REDIRECT_URI`, `DISCORD_GUILD_ID`, `DISCORD_REQUIRED_ROLE_ID`, `DISCORD_BOT_TOKEN`, `ADMIN_DISCORD_IDS`.

`config.json` jest generowany na serwerze z sekretów podczas deployu, z uprawnieniami `600`, w ukrytym `.app` wewnątrz docrootu (deny w `.htaccess`). Nie ma go w repozytorium.

## Bezpieczeństwo

- brak haseł użytkowników; logowanie wyłącznie Discord OAuth2,
- rola Alpha jest sprawdzana przez `guilds.members.read` i musi być obecna; brak konfiguracji = brak dostępu (fail closed),
- cookie `av_session`: HttpOnly, SameSite=Lax, Secure przy HTTPS,
- CSRF: kontrola `Origin` dla mutujących żądań,
- rate limiting w MySQL,
- CSP, HSTS, `X-Frame-Options: DENY`, `nosniff`,
- `config.json` w ukrytym `.app` (deny), `.env` i klucze w `.gitignore`,
- repo publiczne: secret scanning + push protection; Dependabot security updates.

## Discord Scheduled Events

Harmonogram live'ów i konsultacje są synchronizowane z Discorda przez `bin/sync-discord-events.php` uruchamiany z LH Cron co 5 minut. Skrypt pobiera `GET /guilds/{guild.id}/scheduled-events?with_user_count=true`, zapisuje wydarzenia w `discord_scheduled_events` (UPSERT, soft delete) i aktualizuje `discord_sync_state`.

- kategorie: `live`, `consultation`, `unassigned`;
- ręczne przypisanie event ID ma pierwszeństwo;
- czasy są przechowywane w UTC i prezentowane w `Europe/Warsaw`;
- przy błędzie API ostatni poprawny zestaw wydarzeń pozostaje w bazie;
- panel pobiera dane z `/api/events`, bez odpytywania Discorda przy każdym otwarciu.

Konfiguracja Cron: panel LH → Cron / Harmonogram zadań:
```cron
*/5 * * * * /bin/bash /public_html/alphavault.pl/.app/cron/sync-discord-events.sh
```
Ręcznie: `/usr/bin/php /home/platne/serwer445119/public_html/alphavault.pl/.app/bin/sync-discord-events.php`. Log: `~/public_html/alphavault.pl/.app/logs/discord-sync.log`. Szczegóły: `docs/DEPLOY.md`.

## CI/CD

- `ci.yml`: dokumentacja, PHP lint, MySQL 8.4, migracja, Discord DB/sync, API e2e, UI e2e, empty-state.
- `monitor-production.yml`: co 15 min health + `sync.status`/`stale`.
- `deploy-alphavault.yml`: lint, SSH, backup, rsync, migracja, health check.
- `main` jest chroniony: wymagany PR i check `PHP + API + UI`, linear history, `enforce_admins`, bez force push/delete.
- `DEPLOY_ENABLED=true`; deploy startuje po zielonym CI na `main` (`workflow_run`). Environment `alphavault-production` dopuszcza tylko `main`.
- Deployment nigdy nie dotyka katalogów WordPressa ani innych domen.

## Legacy

`legacy/` zawiera poprzedni backend Express 4 i skrypty lokalnego MySQL. Nie jest częścią produkcyjnego deployu; pozostaje jako referencja migracji.
﻿## Integracje

```powershell
npm run test:integrations
npm run test:ui:integrations
```

Wymagają lokalnego mocka (`tests/fixtures/mock-providers.php`) i MySQL z `tests/config.test.json`. Szczegóły: [docs/TESTOWANIE.md](docs/TESTOWANIE.md).
