# Wdrożenie produkcyjne — LH.pl

## 1. Infrastruktura

- hosting współdzielony LH.pl, Debian 13, PHP 8.3.36,
- domena `https://alphavault.pl`,
- document root `~/public_html/alphavault.pl`,
- kod PHP i konfiguracja: `~/public_html/alphavault.pl/.app` (ukryty katalog, zablokowany przez `.htaccess`),
- `open_basedir` LH ogranicza PHP do `public_html/alphavault.pl` i `~/tmp`; dlatego `.app` jest w docroot, ale niedostępny z WWW,
- MySQL/MariaDB LH.pl (MariaDB 11.8),
- brak procesu Node na produkcji.

## 2. Baza danych

W panelu LH.pl (`https://panel.lh.pl/` → Serwery → serwer445119 → Bazy danych MySQL):

- baza `serwer445119_alphavault`, charset `utf8mb4`, collation `utf8mb4_unicode_ci`,
- użytkownik `serwer445119_alphavault` z silnym hasłem, dostęp tylko do tej bazy,
- uprawnienia: `SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, DROP`.

Nie używaj bazy ani użytkownika WordPressa.

**Stan 2026-11-12:** baza i użytkownik istnieją, logowanie działa, tabele utworzone przez deploy (`users`, `sessions`, `user_states`, `discord_scheduled_events`, `discord_sync_state`, `discord_sync_runs` i pozostałe).

Hasło użytkownika bazy trafia do GitHub secret `DB_PASSWORD` przez `gh secret set`; nie jest zapisywane w repo.

Migracja schematu:

```bash
php ~/public_html/alphavault.pl/.app/app/db/migrate.php
```

Wykonuje `db/schema.sql` (idempotentnie), a następnie nieaplikowane pliki z `db/migrations/` i zapisuje ich wersje w `schema_migrations`.

## 3. Sekrety GitHub

W `miloszspyrka-code/alphavault` → Settings → Secrets and variables → Actions:

| Sekret | Wartość |
| --- | --- |
| `LH_SSH_HOST` | `serwer445119.lh.pl` |
| `LH_SSH_PORT` | `40022` |
| `LH_SSH_USER` | `serwer445119` |
| `LH_SSH_PRIVATE_KEY` | prywatny klucz ED25519 wdrożeniowy |
| `LH_SSH_KNOWN_HOSTS` | `ssh-keyscan -p 40022 serwer445119.lh.pl` |
| `LH_DEPLOY_PATH` | `~/public_html/alphavault.pl` |
| `LH_APP_PATH` | `~/public_html/alphavault.pl/.app` |
| `SESSION_SECRET` | 64+ losowych znaków |
| `DB_HOST` | host MySQL z panelu LH.pl (np. `main195.lh.pl`); `localhost` działa dla procesów na serwerze |
| `DB_PORT` | `3306` |
| `DB_NAME` | `serwer445119_alphavault` |
| `DB_USER` | `serwer445119_alphavault` |
| `DB_PASSWORD` | hasło użytkownika bazy |
| `DISCORD_CLIENT_ID` | Application ID |
| `DISCORD_CLIENT_SECRET` | OAuth2 Client Secret |
| `DISCORD_REDIRECT_URI` | `https://alphavault.pl/auth/discord/callback` |
| `DISCORD_GUILD_ID` | ID serwera Alpha Moves |
| `DISCORD_REQUIRED_ROLE_ID` | ID roli Alpha |
| `DISCORD_BOT_TOKEN` | token bota read-only do scheduled events i rechecku roli |
| `DISCORD_ROLE_RECHECK_SECONDS` | próg rechecku roli (minimum 300, domyślnie 300) |
| `ADMIN_DISCORD_IDS` | opcjonalna lista Discord ID administratorów (po przecinku) |
| `OURA_CLIENT_ID`, `OURA_CLIENT_SECRET` | aplikacja OAuth2 w Oura Cloud (scope `daily`; bez nich kafelek jest nieaktywny) |
| `WHOOP_*`, `GARMIN_*` | nieaktywne — integracje Whoop i Garmin są wyłączone; zmienne pozostają dla szybkiego przywrócenia |
| `WEARABLES_ENCRYPTION_KEY` | wymagany klucz AES-256-GCM tokenów urządzeń; bez niego integracje OAuth są nieaktywne (brak fallbacku na `SESSION_SECRET`) |
| `WEARABLES_ENCRYPTION_KEY_PREVIOUS` | poprzedni klucz AES do odczytu starych tokenów w czasie rotacji |
| `QUOTES_BASE`, `QUOTES_TTL`, `QUOTES_CACHE` | notowania rynkowe: URL dostawcy, TTL cache w sekundach (0–3600), plik cache |

Zmienne repozytorium:

- `APP_URL=https://alphavault.pl`
- `DEPLOY_ENABLED=false` (ustaw `true` po odblokowaniu GitHub Actions)
- `ADMIN_DISCORD_IDS` (opcjonalnie, zamiast sekretu)

## 4. Discord

W Discord Developer Portal:

- OAuth2 → Redirects: dodaj `https://alphavault.pl/auth/discord/callback`,
- scopes: `identify`, `guilds.members.read`,
- logowanie sprawdza rolę tokenem użytkownika: `GET /users/@me/guilds/{guild_id}/member`,
- cron co 5 min sprawdza aktywne sesje botem: `GET /guilds/{guild_id}/members/{user_id}`,
- bot ma tylko `View Channels`, bez Manage Events i bez Privileged Gateway Intents.

Backend odmawia dostępu, gdy:

- użytkownik nie jest członkiem serwera,
- użytkownik nie ma wymaganej roli,
- `DISCORD_GUILD_ID` lub `DISCORD_REQUIRED_ROLE_ID` nie są ustawione (fail closed).

Odebranie roli w Discordzie usuwa wszystkie sesje użytkownika w ciągu maksymalnie 5 minut (`access.role_revoked`). Chwilowy błąd Discorda nie odbiera dostępu osobom już zweryfikowanym.

## 4a. Urządzenia zdrowotne

Redirect URI do zarejestrowania u dostawców:

```text
https://alphavault.pl/auth/wearables/oura/callback
```

Oura używa OAuth2 Authorization Code + PKCE (S256). Minimalny scope to `daily` (dzienne podsumowania snu, gotowości i aktywności); nie żądaj `sleep`, bo taki scope nie istnieje. Wymagany jest nagłówek `User-Agent`, a refresh token jest jednorazowy i rotowany.

Garmin i Whoop są wyłączone na razie: kod OAuth i konfiguracja pozostają w repo, ale `Wearables::PROVIDERS` zawiera tylko `apple` i `oura`, więc ich endpointy zwracają 404, a kafelki nie renderują się. Przywrócenie wymaga dodania dostawcy do `PROVIDERS`/`OAUTH_PROVIDERS`, przywrócenia trasy w `app/routes/auth.php` i `app/routes/api.php`, kafelka w `public/index.html` oraz wpisów w testach.

Apple Watch nie używa OAuth. Panel generuje kod `AV-XXXX-XXXX`; aplikacja iPhone wysyła nim `POST /api/wearables/apple/ingest` i dostaje token urządzenia. Sama aplikacja iPhone nie jest częścią tego repozytorium — backend jest gotowy, ale do realnego użycia potrzebna jest aplikacja klienta.

Synchronizacja OAuth2 może być uruchamiana ręcznie z panelu i przez cron:

```cron
*/30 * * * * /bin/bash /public_html/alphavault.pl/.app/cron/sync-wearables.sh >> /public_html/alphavault.pl/.app/logs/wearables-sync.log 2>&1
```

## 5. Synchronizacja Discord (CRON)

Panel LH.pl → Serwery → serwer445119 → Cron / Harmonogram zadań → zadanie:

```text
*/5 * * * * /bin/bash /public_html/alphavault.pl/.app/cron/sync-discord-events.sh
```

Skrypt uruchamia kolejno `bin/sync-discord-events.php` i `bin/check-discord-roles.php` (recheck roli co 5 min).

Ręcznie (bez panelu):

```bash
/usr/bin/php /home/platne/serwer445119/public_html/alphavault.pl/.app/bin/sync-discord-events.php
/usr/bin/php /home/platne/serwer445119/public_html/alphavault.pl/.app/bin/check-discord-roles.php
```

`crontab` przez SSH nie jest dostępny (`/var/spool/cron` nie istnieje), więc harmonogram zmieniaj tylko w panelu LH.

## 5a. Synchronizacja kalendarza makro (CRON)

Panel LH.pl → Serwery → serwer445119 → Cron / Harmonogram zadań → zadanie:

```text
*/15 * * * * /bin/bash /public_html/alphavault.pl/.app/cron/sync-macro-events.sh
```

Ręcznie (bez panelu): `/usr/bin/php /home/platne/serwer445119/public_html/alphavault.pl/.app/bin/sync-macro-events.php`.

- bieżący tydzień: `ff_calendar_thisweek.json` (ForexFactory),
- następny tydzień: `economic-calendar.tradingview.com` (TradingView),
- zapis tylko ★★/★★★, UPSERT + soft delete,
- log: `~/public_html/alphavault.pl/.app/logs/macro-sync.log`,
- deploy po migracji uruchamia jednorazowy sync; błąd nie blokuje wdrożenia.

Wymagania:

- `DISCORD_BOT_TOKEN`, `DISCORD_GUILD_ID` i `DISCORD_REQUIRED_ROLE_ID` w `~/public_html/alphavault.pl/.app/config.json`,
- bot z uprawnieniem `View Channels`, bez uprawnień do zarządzania wydarzeniami,
- brak Privileged Gateway Intents,
- katalog `~/public_html/alphavault.pl/.app/logs` z prawami 700.

Reguły klasyfikacji (opcjonalne):

- przykład: `config/discord_event_rules.example.json`,
- aktywny plik na serwerze: `~/public_html/alphavault.pl/.app/config/discord_event_rules.json`,
- deploy synchronizuje katalog `config/`, ale celowo **nie nadpisuje** aktywnego `discord_event_rules.json`.

Ręczny test:

```bash
/usr/bin/php ~/public_html/alphavault.pl/.app/bin/sync-discord-events.php
```

Log: `~/public_html/alphavault.pl/.app/logs/discord-sync.log`. Stan: tabele `discord_sync_state`, `discord_sync_runs`. Skrypt blokuje równoległe uruchomienia i przy błędzie API nie usuwa ostatniego dobrego zestawu wydarzeń.

## 6. Deployment

Workflow `.github/workflows/deploy-alphavault.yml`:

1. checkout i lint PHP,
2. weryfikacja sekretów,
3. zapis klucza SSH i `known_hosts` z `StrictHostKeyChecking=yes`,
4. backup `~/public_html/alphavault.pl` i `~/public_html/alphavault.pl/.app` do `~/alphavault-backups`,
5. `rsync` frontendu do docrootu i PHP/`bin`/`db`/`config` do `~/public_html/alphavault.pl/.app`,
6. wygenerowanie `public/app-path.php`,
7. wygenerowanie `~/public_html/alphavault.pl/.app/config.json` (600) z sekretów,
8. `php app/db/migrate.php`,
9. health check `GET /api/health` + UI + `/js/state.js`.

Uruchomienie ręczne:

```powershell
gh workflow run deploy-alphavault.yml --repo miloszspyrka-code/alphavault -f dry_run=true
gh workflow run deploy-alphavault.yml --repo miloszspyrka-code/alphavault -f dry_run=false
```

Automatyczny deploy uruchamia się dopiero po zielonym CI (`PHP + API + UI`) na `main`: `deploy-alphavault.yml` reaguje na `workflow_run` CI, nie na sam push, i wypakowuje dokładnie ten commit, który przeszedł testy. Bramkuje go zmienna repozytorium `DEPLOY_ENABLED`. Ustaw ją na `true` po skonfigurowaniu sekretów:

```powershell
gh variable set DEPLOY_ENABLED --repo miloszspyrka-code/alphavault --body true
```

Dopóki `DEPLOY_ENABLED` nie jest `true`, zielone CI na `main` nie uruchamia produkcyjnego zapisu; ręczny dry run nadal działa. Deploy z czerwonym lub nieuruchomionym CI na `main` nie startuje.

Bramka bezpieczeństwa: deploy akceptuje wyłącznie run CI z `event=push` na `main` w tym repozytorium (`head_repository` musi być `miloszspyrka-code/av`). Runy z PR-ów i forków — także forka z gałęzią nazwaną `main` — są ignorowane.

Alternatywa bez GitHub Actions (WSL2): `scripts/local-deploy.sh` — test DB, dry-run, deploy, rollback. Konfiguracja w `~/.config/alphavault/local.env` (600). Szczegóły: [LOCAL-DEPLOY.md](LOCAL-DEPLOY.md).

Rollback:

```powershell
gh workflow run deploy-alphavault.yml --repo miloszspyrka-code/alphavault -f rollback=true
```

lub ręcznie:

```bash
ls -1t ~/alphavault-backups
tar -xzf ~/alphavault-backups/<PLIK>-public.tar.gz -C ~/public_html
tar -xzf ~/alphavault-backups/<PLIK>-app.tar.gz -C ~
```

## 7. Weryfikacja

```bash
curl -fsS https://alphavault.pl/api/health
curl -fsS https://alphavault.pl/api/me
curl -fsS -o /dev/null -w '%{http_code}\n' https://alphavault.pl/
```

Oczekiwane:

- health: `ok=true`, `db.ok=true`, `discordConfigured=true`, `roleCheckConfigured=true`, `eventsConfigured=true`, `demoAllowed=false`,
- `/api/me` bez sesji: `authenticated=false`, `demoAllowed=false`,
- `/` zwraca UI Alpha Vault.

Po deployu:

1. zaloguj się kontem z rolą Alpha,
2. sprawdź `/api/events` (3 wydarzenia VOICE w dniu testu),
3. uruchom ręcznie sync i porównaj `events_count` z Discordem,
4. sprawdź wpis Cron w logu po 5 minutach,
5. sprawdź puste pola na środowisku testowym, nie na produkcji.

**Wynik 2026-11-12:** `/` 200, health wszystkie flagi `true`, `/docs/` 200, `.app` 403, sync 3/3 bez duplikatów, UI read-only PASS. Cron aktywny: logi `18:05:05` i `18:10:02`.

## 8. Bezpieczeństwo

- `config.json` w ukrytym `.app`, `600`, deny w `.htaccess`,
- brak `.env` i kluczy w repo,
- `public/.htaccess` blokuje `.app`, `app-path.php`, `.env`, `config.json`,
- HSTS i Secure cookies przy HTTPS,
- `Origin` check dla PUT/DELETE/POST,
- rate limiting w MySQL,
- sesje hashowane SHA-256, cookie HttpOnly/SameSite=Lax,
- deployment nie dotyka WordPressów ani innych domen.

## 9. Znane ograniczenia

- Hosting współdzielony: brak Node.js na produkcji, brak własnego procesu daemon.
- `rsync --delete` jest używany tylko w dedykowanych katalogach `alphavault.pl` i `.app`; przed nim wykonywany jest backup.
- Testy Discord wymagają interaktywnego logowania użytkownika z wymaganą rolą.
- GitHub Actions może być zablokowane przez billing — wtedy deploy nie startuje mimo poprawnych sekretów.

## 10. Monitoring

Workflow `.github/workflows/monitor-production.yml`:

- uruchamiany co 15 minut (`*/15 * * * *`) i ręcznie (`workflow_dispatch`),
- sprawdza `https://alphavault.pl/api/health`,
- wymaga: `ok`, `db.ok`, `discordConfigured`, `roleCheckConfigured`, `eventsConfigured`, `demoAllowed=false`,
- wymaga `sync.status=success` i `sync.stale=false`,
- sprawdza, że `/` zwraca UI Alpha Vault,
- nie używa sekretów; `/api/health` jest publiczny.

Ręcznie:

```powershell
gh workflow run monitor-production.yml --repo miloszspyrka-code/av
```

Błąd workflow oznacza powiadomienie GitHub do obserwujących repozytorium. Monitoring nie zastępuje LH Cron — tylko wykrywa zatrzymany sync lub awarię health.

## 19. Integracje giełd i MetaTrader — konfiguracja produkcji

### 19.1. Zmienne

W `.app/config.json` (600, deny w `.htaccess`):

| Zmienna | Wymagana | Opis |
|---|---|---|
| `INTEGRATIONS_KEY` | tak | 32+ losowych znaków; klucz AES-256-GCM do poświadczeń |
| `METAAPI_TOKEN` | dla MetaTrader | token MetaApi; bez niego panel zwraca `meta_not_configured` |
| `OKX_BASE`, `BINGX_BASE`, `WEEX_SPOT_BASE`, `WEEX_FUTURES_BASE` | nie | nadpisania adresów API (testy/mock) |
| `METAAPI_BASE`, `METAAPI_CLIENT_BASE` | nie | nadpisania MetaApi (testy/mock) |

Wygeneruj klucz:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

### 19.2. Migracja

```bash
/usr/bin/php ~/public_html/alphavault.pl/.app/app/db/migrate.php
```

Tworzy `exchange_connections`, `exchange_imports`, `exchange_sync_runs`, `exchange_sync_state`.

### 19.3. Cron

Istniejące zadanie co 5 min wywołuje także worker integracji i recheck roli:

```text
*/5 * * * * /bin/bash /public_html/alphavault.pl/.app/cron/sync-discord-events.sh
```

Kalendarz makro ma osobne zadanie co 15 min:

```text
*/15 * * * * /bin/bash /public_html/alphavault.pl/.app/cron/sync-macro-events.sh
```

Worker pilnuje progu 25 minut, więc realny import odbywa się co ok. 30 min. Ręcznie:

```bash
/usr/bin/php ~/public_html/alphavault.pl/.app/bin/sync-exchange-trades.php --force
```

### 19.4. Weryfikacja

```bash
curl -sS https://alphavault.pl/api/health
```

Oczekiwane: `integrations.configured=true`, `integrations.metaApiConfigured` zgodne z konfiguracją, `integrations.sync.status` = `success` po pierwszym przebiegu.

### 19.5. MetaTrader EA

Plik `integrations/mt5/AlphaVault.mq5` kompiluje się w MetaEditorze. Wymaga:

1. `WebRequest` dla `https://alphavault.pl`,
2. kodu parowania z panelu,
3. włączonego MetaTradera (lub VPS) — EA nie handluje.
