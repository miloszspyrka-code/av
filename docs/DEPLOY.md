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

Wykonuje `db/schema.sql` (idempotentnie) i wpis w `schema_migrations`.

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
| `WHOOP_CLIENT_ID`, `WHOOP_CLIENT_SECRET` | aplikacja OAuth2 w WHOOP Developer Dashboard (scope `read:recovery read:sleep offline`) |
| `GARMIN_CLIENT_ID`, `GARMIN_CLIENT_SECRET` | aplikacja w Garmin Connect Developer Program (Business Starter/Enterprise; Personal API nie jest dozwolone dla wielu użytkowników) |
| `GARMIN_AUTHORIZE_URL`, `GARMIN_TOKEN_URL`, `GARMIN_API_BASE` | domyślnie `https://connect.garmin.com/oauth2Confirm`, `https://diauth.garmin.com/di-oauth2-service/oauth/token`, `https://apis.garmin.com` |
| `WEARABLES_ENCRYPTION_KEY` | wymagany klucz AES-256-GCM tokenów urządzeń; bez niego integracje OAuth są nieaktywne (brak fallbacku na `SESSION_SECRET`) |
| `WEARABLES_ENCRYPTION_KEY_PREVIOUS` | poprzedni klucz AES do odczytu starych tokenów w czasie rotacji |
| `GARMIN_ACCESS_PLAN` | `starter` lub `enterprise`; puste oznacza brak zatwierdzonego dostępu |
| `GARMIN_DATA_DELIVERY`, `GARMIN_PUSH_READY` | tryb dostarczania danych Garmin; Starter jest push-only, adapter push nie jest jeszcze wdrożony |
| `GARMIN_REVOKE_URL` | endpoint wyrejestrowania Garmin (`/wellness-api/rest/user/registration`) |
| `QUOTES_BASE`, `QUOTES_TTL`, `QUOTES_CACHE` | notowania rynkowe: URL dostawcy, TTL cache w sekundach (0–3600), plik cache |

Zmienne repozytorium:

- `APP_URL=https://alphavault.pl`
- `DEPLOY_ENABLED=true` (wymagane dla auto-deployu; produkcja ma `true`)
- `ADMIN_DISCORD_IDS` (opcjonalnie, zamiast sekretu)
- `GARMIN_ACCESS_PLAN=starter|enterprise` (po zatwierdzeniu dostępu Garmin)
- `GARMIN_DATA_DELIVERY=push` i `GARMIN_PUSH_READY=true` dopiero po wdrożeniu adaptera push
- `GARMIN_AUTHORIZE_URL`, `GARMIN_TOKEN_URL`, `GARMIN_API_BASE`, `GARMIN_REVOKE_URL` (opcjonalne nadpisania domyślnych endpointów)

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
https://alphavault.pl/auth/wearables/garmin/callback
https://alphavault.pl/auth/wearables/oura/callback
https://alphavault.pl/auth/wearables/whoop/callback
```

Oura używa OAuth2 Authorization Code + PKCE (S256). Minimalny scope to `daily` (dzienne podsumowania snu, gotowości i aktywności); nie żądaj `sleep`, bo taki scope nie istnieje. Wymagany jest nagłówek `User-Agent`, a refresh token jest jednorazowy i rotowany.

WHOOP używa OAuth2 Authorization Code i API v2 (`/developer/v2/recovery`, `/developer/v2/activity/sleep`). Scope: `read:recovery read:sleep offline`. `offline` jest wymagany do otrzymania refresh tokenu; WHOOP unieważnia poprzedni token po odświeżeniu, więc kod zapisuje nowy zestaw atomowo.

Garmin używa OAuth2 PKCE (S256), `code_verifier` jest przechowywany po stronie serwera. Authorization: `https://connect.garmin.com/oauth2Confirm`; token: `https://diauth.garmin.com/di-oauth2-service/oauth/token`; API: `https://apis.garmin.com`. Po autoryzacji kod pobiera User ID i przyznane uprawnienia oraz wyrejestrowuje użytkownika przy odłączaniu. **Business Starter jest push-only i nie daje historii** — obecny adapter danych Garmina jest oznaczony jako `awaiting_provider_spec`; nie jest używany pull. Szczegółowe schematy push są dostępne dopiero w Garmin Developer Portal. Enterprise może zapewnić push lub Ping/Pull i historię, ale wymaga odrębnego dostępu.

Samo ustawienie `GARMIN_CLIENT_ID`/`GARMIN_CLIENT_SECRET` nie aktywuje Garmina. Wymagane jest zatwierdzenie planu (`GARMIN_ACCESS_PLAN=starter|enterprise`) i ukończenie adaptera push. Personal API key jest przeznaczony wyłącznie do własnych danych i nie może być używany w aplikacji wieloużytkownikowej.

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
