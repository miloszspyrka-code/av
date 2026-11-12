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
| `DISCORD_BOT_TOKEN` | token bota read-only do scheduled events |
| `ADMIN_DISCORD_IDS` | opcjonalna lista Discord ID administratorów (po przecinku) |
| `OURA_CLIENT_ID`, `OURA_CLIENT_SECRET` | aplikacja Oura (opcjonalne; bez nich kafelek jest nieaktywny) |
| `WHOOP_CLIENT_ID`, `WHOOP_CLIENT_SECRET` | aplikacja Whoop (opcjonalne) |
| `GARMIN_CLIENT_ID`, `GARMIN_CLIENT_SECRET` | aplikacja Garmin Health API (opcjonalne; wymaga zatwierdzenia partnera) |
| `GARMIN_AUTHORIZE_URL`, `GARMIN_TOKEN_URL`, `GARMIN_API_BASE` | endpointy przydzielone przez Garmin |
| `WEARABLES_ENCRYPTION_KEY` | opcjonalny klucz AES tokenów urządzeń |

Zmienne repozytorium:

- `APP_URL=https://alphavault.pl`
- `DEPLOY_ENABLED=false` (ustaw `true` po odblokowaniu GitHub Actions)
- `ADMIN_DISCORD_IDS` (opcjonalnie, zamiast sekretu)

## 4. Discord

W Discord Developer Portal:

- OAuth2 → Redirects: dodaj `https://alphavault.pl/auth/discord/callback`,
- scopes: `identify`, `guilds.members.read`,
- weryfikacja roli używa tokenu użytkownika i `GET /users/@me/guilds/{guild_id}/member` — bot nie jest do tego potrzebny,
- bot jest potrzebny wyłącznie do odczytu scheduled events; ma tylko `View Channels`, bez Manage Events i bez Privileged Gateway Intents.

Backend odmawia dostępu, gdy:

- użytkownik nie jest członkiem serwera,
- użytkownik nie ma wymaganej roli,
- `DISCORD_GUILD_ID` lub `DISCORD_REQUIRED_ROLE_ID` nie są ustawione (fail closed).

## 4a. Urządzenia zdrowotne

Redirect URI do zarejestrowania u dostawców:

```text
https://alphavault.pl/auth/wearables/garmin/callback
https://alphavault.pl/auth/wearables/oura/callback
https://alphavault.pl/auth/wearables/whoop/callback
```

Oura: scope `daily sleep heartrate personal`. Whoop: `read:recovery read:sleep read:profile offline`. Garmin: wartości `GARMIN_*` pochodzą z programu Garmin Health API i są przydzielane po zatwierdzeniu partnera; bez nich kafelek Garmin jest nieaktywny, a reszta działa.

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

Ręcznie (bez panelu): `/usr/bin/php /home/platne/serwer445119/public_html/alphavault.pl/.app/bin/sync-discord-events.php`.

`crontab` przez SSH nie jest dostępny (`/var/spool/cron` nie istnieje), więc harmonogram zmieniaj tylko w panelu LH.

Wymagania:

- `DISCORD_BOT_TOKEN` i `DISCORD_GUILD_ID` w `~/public_html/alphavault.pl/.app/config.json`,
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

Automatyczny deploy przy pushu do `main` jest sterowany zmienną repozytorium `DEPLOY_ENABLED`. Ustaw ją na `true` po skonfigurowaniu sekretów:

```powershell
gh variable set DEPLOY_ENABLED --repo miloszspyrka-code/alphavault --body true
```

Dopóki `DEPLOY_ENABLED` nie jest `true`, push do `main` nie uruchamia produkcyjnego zapisu; ręczny dry run nadal działa.

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
