# Alpha Vault

Prywatna strefa tradera Alpha Moves. Produkcja: <https://alphavault.pl>.

Aplikacja działa na hostingu współdzielonym LH.pl:

- frontend: dotychczasowy HTML/CSS/JS (`public/`),
- backend: PHP 8.3 (`app/`),
- baza: MySQL/MariaDB,
- logowanie: Discord OAuth2 (`identify guilds.members.read`),
- sesje: token w cookie HttpOnly + hash w MySQL,
- wdrożenia: GitHub Actions → SSH → `alphavault.pl`.

Node.js 24 jest używany tylko lokalnie i w CI do testów Playwright. Na serwerze produkcyjnym nie działa proces Node.

## Struktura

```text
public/            # document root: index.php, index.html, js/, docs/
app/               # PHP: Config, Db, Auth, Discord, routes
db/                # schema.sql, demo-state.json
tests/             # API i UI e2e (Node + Playwright)
docs/              # dokumentacja techniczna
legacy/            # poprzedni backend Express (referencja, nie jest wdrażany)
scripts/           # deploy produkcyjny, render configu
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
npm test        # API: 17 scenariuszy
npm run test:ui # UI: 19 scenariuszy
```

Konfiguracja lokalna może też pochodzić ze zmiennych środowiskowych `APP_URL`, `DB_*`, `DISCORD_*`, `SESSION_SECRET`, `ALLOW_DEMO_LOGIN`. Plik `config.json` jest ignorowany przez Git.

## Produkcja

- Domena: `https://alphavault.pl`
- Document root: `~/public_html/alphavault.pl`
- Kod aplikacji i `config.json`: `~/alphavault-app` (poza publicznym katalogiem)
- Deployment: push/merge do `main` uruchamia `.github/workflows/deploy-alphavault.yml`.
- Rollback: ręczne uruchomienie workflow z `rollback=true` (przywraca ostatni backup z `~/alphavault-backups`).

Wymagane sekrety GitHub:

`LH_SSH_HOST`, `LH_SSH_PORT`, `LH_SSH_USER`, `LH_SSH_PRIVATE_KEY`, `LH_SSH_KNOWN_HOSTS`, `LH_DEPLOY_PATH`, `LH_APP_PATH`, `SESSION_SECRET`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DISCORD_CLIENT_ID`, `DISCORD_CLIENT_SECRET`, `DISCORD_REDIRECT_URI`, `DISCORD_GUILD_ID`, `DISCORD_REQUIRED_ROLE_ID`.

`config.json` jest generowany na serwerze z sekretów podczas deployu, z uprawnieniami `600`, poza `public_html`. Nie ma go w repozytorium.

## Bezpieczeństwo

- brak haseł użytkowników; logowanie wyłącznie Discord OAuth2,
- rola Alpha jest sprawdzana przez `guilds.members.read` i musi być obecna; brak konfiguracji = brak dostępu (fail closed),
- cookie `av_session`: HttpOnly, SameSite=Lax, Secure przy HTTPS,
- CSRF: kontrola `Origin` dla mutujących żądań,
- rate limiting w MySQL,
- CSP, HSTS, `X-Frame-Options: DENY`, `nosniff`,
- `config.json` poza docrootem, `.env` i klucze w `.gitignore`.

## CI/CD

- `ci.yml`: PHP lint, MySQL 8.4, migracja, API e2e, UI e2e.
- `deploy-alphavault.yml`: lint, SSH, backup, rsync, migracja, health check.
- Deployment nigdy nie dotyka katalogów WordPressa ani innych domen.

## Legacy

`legacy/` zawiera poprzedni backend Express 4 i skrypty lokalnego MySQL. Nie jest częścią produkcyjnego deployu; pozostaje jako referencja migracji.
