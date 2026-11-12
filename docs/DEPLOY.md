# Wdrożenie produkcyjne — LH.pl

## 1. Infrastruktura

- hosting współdzielony LH.pl, Debian 13, PHP 8.3.36,
- domena `https://alphavault.pl`,
- document root `~/public_html/alphavault.pl`,
- kod PHP i konfiguracja `~/alphavault-app` (poza publicznym katalogiem),
- MySQL/MariaDB LH.pl,
- brak procesu Node na produkcji.

## 2. Baza danych

W panelu LH.pl (`https://panel.lh.pl/` → Serwery → serwer445119 → Bazy danych MySQL) utwórz:

- bazę `serwer445119_alphavault`, charset `utf8mb4`, collation `utf8mb4_unicode_ci`,
- użytkownika `serwer445119_alphavault` z silnym hasłem, dostęp tylko do tej bazy,
- uprawnienia: `SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, DROP`.

Nie używaj bazy ani użytkownika WordPressa.

Hasło użytkownika bazy trafia do GitHub secret `DB_PASSWORD` przez `gh secret set`; nie jest zapisywane w repo.

Migracja schematu:

```bash
php ~/alphavault-app/app/db/migrate.php
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
| `LH_APP_PATH` | `~/alphavault-app` |
| `SESSION_SECRET` | 64+ losowych znaków |
| `DB_HOST` | `localhost` |
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

Zmienne repozytorium:

- `APP_URL=https://alphavault.pl`
- `DEPLOY_ENABLED=false` (ustaw `true` po utworzeniu bazy)
- `ADMIN_DISCORD_IDS` (opcjonalnie, zamiast sekretu)

## 4. Discord

W Discord Developer Portal:

- OAuth2 → Redirects: dodaj `https://alphavault.pl/auth/discord/callback`,
- scopes: `identify`, `guilds.members.read`,
- aplikacja nie potrzebuje bota do sprawdzania roli; backend używa tokenu użytkownika i endpointu `GET /users/@me/guilds/{guild_id}/member`.

Backend odmawia dostępu, gdy:

- użytkownik nie jest członkiem serwera,
- użytkownik nie ma wymaganej roli,
- `DISCORD_GUILD_ID` lub `DISCORD_REQUIRED_ROLE_ID` nie są ustawione (fail closed).

## 5. Synchronizacja Discord (CRON)

Panel LH.pl → Serwery → serwer445119 → Cron / Harmonogram zadań → dodaj zadanie:

```text
*/5 * * * * /usr/bin/php /home/platne/serwer445119/alphavault-app/bin/sync-discord-events.php
```

Wymagania:

- `DISCORD_BOT_TOKEN` i `DISCORD_GUILD_ID` w `~/alphavault-app/config.json`,
- bot z uprawnieniem `View Channels`, bez uprawnień do zarządzania wydarzeniami,
- brak Privileged Gateway Intents.

Ręczny test:

```bash
/usr/bin/php ~/alphavault-app/bin/sync-discord-events.php
```

Log: `~/alphavault-app/logs/discord-sync.log`. Stan: tabele `discord_sync_state`, `discord_sync_runs`. Skrypt blokuje równoległe uruchomienia i przy błędzie API nie usuwa ostatniego dobrego zestawu wydarzeń.

## 6. Deployment

Workflow `.github/workflows/deploy-alphavault.yml`:

1. checkout i lint PHP,
2. weryfikacja sekretów,
3. zapis klucza SSH i `known_hosts` z `StrictHostKeyChecking=yes`,
4. backup `~/public_html/alphavault.pl` i `~/alphavault-app` do `~/alphavault-backups`,
5. `rsync` frontendu do docrootu i PHP do `~/alphavault-app`,
6. wygenerowanie `public/app-path.php`,
7. wygenerowanie `~/alphavault-app/config.json` (600) z sekretów,
8. `php app/db/migrate.php`,
9. health check `GET /api/health` + UI + `/js/state.js`.

Uruchomienie ręczne:

```powershell
gh workflow run deploy-alphavault.yml --repo miloszspyrka-code/alphavault -f dry_run=true
gh workflow run deploy-alphavault.yml --repo miloszspyrka-code/alphavault -f dry_run=false
```

Automatyczny deploy przy pushu do `main` jest sterowany zmienną repozytorium `DEPLOY_ENABLED`. Ustaw ją na `true` po utworzeniu bazy i skonfigurowaniu sekretów:

```powershell
gh variable set DEPLOY_ENABLED --repo miloszspyrka-code/alphavault --body true
```

Dopóki `DEPLOY_ENABLED` nie jest `true`, push do `main` nie uruchamia produkcyjnego zapisu; ręczny dry run nadal działa.

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

## 6. Weryfikacja

```bash
curl -fsS https://alphavault.pl/api/health
curl -fsS https://alphavault.pl/api/me
curl -fsS -o /dev/null -w '%{http_code}\n' https://alphavault.pl/
```

Oczekiwane:

- health: `ok=true`, `db.ok=true`, `discordConfigured=true`, `roleCheckConfigured=true`, `demoAllowed=false`,
- `/api/me` bez sesji: `authenticated=false`, `demoAllowed=false`,
- `/` zwraca UI Alpha Vault.

## 7. Bezpieczeństwo

- `config.json` poza docrootem, `600`,
- brak `.env` i kluczy w repo,
- `public/.htaccess` blokuje `app-path.php`, `.env`, `config.json`,
- HSTS i Secure cookies przy HTTPS,
- `Origin` check dla PUT/DELETE/POST,
- rate limiting w MySQL,
- sesje hashowane SHA-256, cookie HttpOnly/SameSite=Lax,
- deployment nie dotyka WordPressów ani innych domen.

## 8. Znane ograniczenia

- Hosting współdzielony: brak Node.js na produkcji, brak własnego procesu daemon.
- `rsync --delete` jest używany tylko w dedykowanych katalogach `alphavault.pl` i `alphavault-app`; przed nim wykonywany jest backup.
- Testy Discord wymagają interaktywnego logowania użytkownika z wymaganą rolą.
