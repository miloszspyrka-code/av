# Testy

## Lokalnie

Wymagania: PHP 8.3 (`pdo_mysql`, `curl`, `mbstring`), MySQL/MariaDB, Node.js 24.

```powershell
Copy-Item tests/config.test.example.json tests/config.test.json
$env:ALPHA_VAULT_CONFIG = "tests/config.test.json"
php app/db/migrate.php
php -S 127.0.0.1:4173 -t public public/router.php
```

W drugim terminalu:

```powershell
npm ci
$env:APP_URL = "http://127.0.0.1:4173"
npm test
npm run test:ui
```

## API — 17 scenariuszy

`tests/api.e2e.mjs` sprawdza:

- health + MySQL,
- `/api/me` bez sesji,
- `/api/state` bez sesji = 401,
- demo login + cookie HttpOnly/SameSite,
- OAuth start bez konfiguracji = 503,
- callback z złym state = 400,
- `/api/me` z sesją,
- GET/PUT state + MySQL,
- konflikt rewizji = 409,
- CSRF / złe Origin = 403,
- UI `/`,
- `/docs/API.md`,
- eksport JSON,
- przywrócenie stanu,
- logout + unieważnienie sesji.

## UI — 19 scenariuszy

`tests/ui.e2e.mjs` używa Playwright + systemowego Chrome/Edge:

- start UI i modal logowania,
- demo login + sesja,
- 47 transakcji z MySQL,
- nawigacja 14 widoków,
- plan dnia, filtry dziennika, szczegóły transakcji,
- kalkulator, statystyki, mentor, akademia,
- kalendarz, check-in, profil,
- nowa transakcja i zamknięcie,
- przywrócenie stanu demo,
- wylogowanie.

## CI

`.github/workflows/ci.yml`:

- MySQL 8.4 service,
- PHP 8.3 + Node 24,
- PHP lint,
- migracja,
- API e2e,
- UI e2e (systemowy Chrome).

## Czego testy nie obejmują

- prawdziwego logowania Discord (wymaga interakcji użytkownika),
- produkcyjnego SSH/rsync (sprawdzane przez dry run deployu),
- testów obciążeniowych.

Testy Discord wykonuj ręcznie na koncie z rolą Alpha, bez roli i spoza serwera.
