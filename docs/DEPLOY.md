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

W `miloszspyrka-code/av` → Settings → Secrets and variables → Actions:

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
- `DEPLOY_ENABLED=true` (wymagane dla auto-deployu; produkcja ma `true`)
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

Synchronizacja OAuth2 działa z panelu i z wrappera Cron co 30 min (throttle). Nie ma osobnego wpisu LH Cron. Ręcznie:

```bash
/usr/bin/php /home/platne/serwer445119/public_html/alphavault.pl/.app/bin/sync-wearables.php
```

## 5. Synchronizacja Discord (CRON — jedyny wpis)

Panel LH.pl → Serwery → serwer445119 → Cron / Harmonogram zadań → zadanie:

```text
*/5 * * * * /bin/bash /public_html/alphavault.pl/.app/cron/sync-discord-events.sh
```

To **jedyny** wpis LH Cron. Skrypt uruchamia kolejno `bin/sync-discord-events.php` (Discord events + worker integracji `syncDue`, realny import max co 25 min), `bin/check-discord-roles.php` (recheck roli co 5 min) oraz z throttle `bin/sync-macro-events.php` (co 15 min), `bin/sync-wearables.php` (co 30 min) i `bin/sync-demo-state.php` (co 60 min). Znaczniki czasu: `.app/.runtime/*.stamp`.

Ręcznie (bez panelu):

```bash
/usr/bin/php /home/platne/serwer445119/public_html/alphavault.pl/.app/bin/sync-discord-events.php
/usr/bin/php /home/platne/serwer445119/public_html/alphavault.pl/.app/bin/check-discord-roles.php
/usr/bin/php /home/platne/serwer445119/public_html/alphavault.pl/.app/bin/sync-demo-state.php
```

`crontab` przez SSH nie jest dostępny (`/var/spool/cron` nie istnieje), więc harmonogram zmieniaj tylko w panelu LH.

## 5a. Synchronizacja kalendarza makro (wrapper)

Brak osobnego wpisu w LH Cron. 5-minutowy wrapper wywołuje `bin/sync-macro-events.php` co 15 min (throttle `.runtime/macro.stamp`). Ręcznie (bez panelu):

```bash
/usr/bin/php /home/platne/serwer445119/public_html/alphavault.pl/.app/bin/sync-macro-events.php
```

- bieżący tydzień: `ff_calendar_thisweek.json` (ForexFactory),
- następny tydzień: `economic-calendar.tradingview.com` (TradingView),
- zapis tylko ★★/★★★, UPSERT + soft delete,
- log: `~/public_html/alphavault.pl/.app/logs/macro-sync.log`,
- deploy po migracji uruchamia jednorazowy sync; błąd nie blokuje wdrożenia.

## 5b. Synchronizacja dat demo (wrapper)

Brak osobnego wpisu w LH Cron. 5-minutowy wrapper wywołuje `bin/sync-demo-state.php` co 60 min (throttle `.runtime/demo.stamp`). Ręcznie (bez panelu):

```bash
/usr/bin/php /home/platne/serwer445119/public_html/alphavault.pl/.app/bin/sync-demo-state.php
```

- dotyczy wyłącznie kont `users.is_demo = 1`,
- `DemoState` przesuwa wszystkie daty ISO w stanie na dziś (`Europe/Warsaw`), zachowując odstępy; punkt zaczepienia to najnowsza transakcja (albo plan),
- operacja jest idempotentna: przy dacie już aktualnej nic nie zapisuje (`shifted=0`),
- seeder `GET /api/state` i `tests/reset-demo.php` używają tego samego przesunięcia, więc nowe konto demo jest świeże od pierwszego odczytu,
- log: stdout zadania Cron (LH) oraz `revision` w `user_states`.

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

## 6. Deployment — wyłącznie GitHub Actions

Workflow `.github/workflows/deploy-alphavault.yml` jest jedyną ścieżką wdrożenia. Lokalny deploy, SSH/rsync i `scripts/local-deploy.sh` nie istnieją w procesie.

1. PR → CI (`ci.yml`) → wymagany check `PHP + API + UI`.
2. Merge do `main` (squash).
3. CI na `main` kończy się `success`.
4. `deploy-alphavault.yml` startuje z `workflow_run` CI i wypakowuje dokładnie ten commit, który przeszedł testy.
5. Deploy: walidacja sekretów → klucz SSH → backup → rsync → `config.json` (600) → migracja → health check.
6. Krok `Verify production E2E` sprawdza health, `/docs/`, `.app` 403, `/api/events` 401 i UI.

Bramki:

- deploy uruchamia się tylko dla CI z `event=push` na `main` w `miloszspyrka-code/av`;
- wymagane `DEPLOY_ENABLED=true`;
- environment `alphavault-production` dopuszcza tylko `main`;
- runy z PR-ów i forków są ignorowane.

Uruchomienie ręczne tylko dla dry-run, rollbacku lub po awarii automatu:

```powershell
gh workflow run deploy-alphavault.yml --repo miloszspyrka-code/av -f dry_run=true
gh workflow run deploy-alphavault.yml --repo miloszspyrka-code/av -f dry_run=false
gh workflow run deploy-alphavault.yml --repo miloszspyrka-code/av -f rollback=true
```

Rollback odtwarza ostatni backup `public` i `app`, a następnie wykonuje health check. Nie ma lokalnej alternatywy deployu; przy blokadzie GitHub Actions zgłoś BLOCKER.

## 7. Weryfikacja

Automatyczna, w runie deployu:

```text
GET /api/health          → ok, db.ok, discordConfigured, roleCheckConfigured,
                           eventsConfigured, demoAllowed=false,
                           sync.status=success, sync.stale=false
GET /docs/               → 200
GET /.app/config.json    → 403
GET /api/events          → 401 bez sesji
GET /                    → UI Alpha Vault
```

Ręcznie (tylko odczyt):

```bash
curl -fsS https://alphavault.pl/api/health
curl -fsS https://alphavault.pl/api/me
curl -fsS -o /dev/null -w '%{http_code}\n' https://alphavault.pl/
```

Po deployu:

1. sprawdź run `Deploy AlphaVault` — krok `Verify production E2E` musi być zielony,
2. sprawdź `monitor-production.yml` — musi być `success`,
3. zaloguj się kontem z rolą Alpha tylko za zgodą użytkownika,
4. sprawdź `/api/events` i porównaj `eventsCount` z Discorda,
5. sprawdź `sync.lastSuccessAt` — Cron co 5 min.

**Wynik 2026-11-12 21:49 UTC:** `/` 200, health wszystkie flagi `true`, `sync.status=success`, `/docs/` 200, `.app` 403, sync `eventsCount=2`, `lastSuccessAt=21:45:02`.

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
- GitHub Actions może być zablokowane przez billing — wtedy deploy nie startuje i jest to BLOCKER; nie ma lokalnej alternatywy.

## 10. Monitoring

Workflow `.github/workflows/monitor-production.yml`:

- uruchamiany co 15 minut (`*/15 * * * *`) i ręcznie (`workflow_dispatch`),
- sprawdza `https://alphavault.pl/api/health`,
- wymaga: `ok`, `db.ok`, `discordConfigured`, `roleCheckConfigured`, `eventsConfigured`, `demoAllowed=false`,
- wymaga `sync.status=success` i `sync.stale=false`,
- sprawdza, że `/` zwraca UI Alpha Vault,
- sprawdza `/docs/` = 200 i `.app/config.json` = 403,
- nie używa sekretów; `/api/health` jest publiczny.

Ręcznie:

```powershell
gh workflow run monitor-production.yml --repo miloszspyrka-code/av
```

Błąd workflow oznacza powiadomienie GitHub do obserwujących repozytorium. Monitoring nie zastępuje LH Cron — tylko wykrywa zatrzymany sync lub awarię health.

## 19. Integracje giełd i MetaTrader - konfiguracja produkcji
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

Jedyne zadanie LH Cron, co 5 min, wywołuje także worker integracji i recheck roli:

```text
*/5 * * * * /bin/bash /public_html/alphavault.pl/.app/cron/sync-discord-events.sh
```

Worker pilnuje progu 25 minut, więc realny import odbywa się co ok. 30 min. Makro i urządzenia są w tym samym wrapperze z throttle (15/30 min), bez osobnych wpisów. Ręcznie:

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

## 20. Serwer MCP (mcp.alphavault.pl)

- Osobny front controller: `mcp/public/index.php`; aplikacja w `~/public_html/mcp.alphavault.pl/.app`.
- Wdrożenie wyłącznie przez GitHub Actions: workflow `Deploy AlphaVault MCP` (`.github/workflows/deploy-mcp.yml`).
- Uruchomienie z `main` z refem do wdrożenia:

  ```powershell
  gh workflow run deploy-mcp.yml --repo miloszspyrka-code/av --ref main -f ref=<branch> -f dry_run=true
  gh workflow run deploy-mcp.yml --repo miloszspyrka-code/av --ref main -f ref=<branch> -f dry_run=false
  ```

- MCP 2.0 używa Composer (`league/oauth2-server`, `mcp/sdk`); deploy uruchamia `composer install`, jeśli `vendor/autoload.php` nie istnieje na runnerze.
- Migracje MCP są addytywne: `db/schema.sql` tworzy tabele `mcp_*`, `db/migrations/20261114_mcp2.sql` dodaje `payload_hash` i rozszerza `mcp_proposals.kind`, a `db/migrations/20261115_mcp_approval.sql` dodaje `diff_hash`, kolumny kodu jednorazowego i idempotencji decyzji.
- Domyślnie działa 17 narzędzi odczytu; 11 narzędzi zapisu (`propose_*`, `approve_proposal`, `reject_proposal`) pojawia się tylko przy `MCP_WRITES_ENABLED=true` i scope `alphavault.write`. Skrypt deployu wymusza `MCP_WRITES_ENABLED=false` w fazie 1.
- Zatwierdzanie w rozmowie wymaga kodu z panelu (`POST /api/mcp/proposals/{id}/approval-code`); po włączeniu zapisu klient musi ponownie przejść OAuth, żeby uzyskać nowy scope.
- Pilot: `MCP_PILOT_DISCORD_IDS` (CSV) ogranicza zapis do wybranych kont Discord; puste = wszyscy z scope `alphavault.write`. Allowlista jest egzekwowana przy każdym wywołaniu zapisu, nie tylko na ekranie zgody.
- Workflow sprawdza sekrety, uruchamia `php -l` i `scripts/deploy-mcp.sh`: backup, rsync, migracja, klucze OAuth, health check.
- `MCP_WRITES_ENABLED=false` jest wymuszone przez skrypt.
- Workflow nie dotyka `alphavault.pl` ani WordPressa; environment `alphavault-production`.
- Diagnostyka: workflow `MCP logs` (`mcp-logs.yml`) wypisuje ostatnie linie `.app/logs/mcp.log` i `php-error.log`; uruchamiaj z `main`.
