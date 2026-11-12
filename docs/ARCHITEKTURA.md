# Architektura

## Produkcja

```text
Przeglądarka
   │ HTTPS, cookie HttpOnly
   ▼
Apache + PHP 8.3 (LH.pl)
   ├── public/  → document root domeny alphavault.pl
   │     ├── index.php   front controller
   │     ├── index.html  UI
   │     ├── js/         frontend
   │     └── docs/       dokumentacja publiczna
   ├── ~/alphavault-app/  kod PHP + config.json (poza docrootem)
   └── MySQL/MariaDB LH.pl
```

Brak procesu Node na produkcji. PHP-FPM obsługuje żądania per request.

## Warstwy PHP

| Plik | Rola |
| --- | --- |
| `app/Config.php` | konfiguracja z `config.json` / env, walidacja produkcji |
| `app/Db.php` | PDO MySQL, migracje, health |
| `app/Auth.php` | cookie, sesje, CSRF, rate limit, audit, public user |
| `app/Discord.php` | OAuth2, profil, członkostwo i rola |
| `app/Http.php` | JSON/HTML, nagłówki, błędy |
| `app/routes/api.php` | `/api/health`, `/api/me`, `/api/state`, konto, eksport |
| `app/routes/auth.php` | `/auth/discord`, callback, demo, logout |
| `public/index.php` | routing i serwowanie UI |

## Dane

`user_states.state` to dokument JSON całego stanu aplikacji. Zapisy są optymistyczne: `revision` + `baseRevision`, konflikt → 409. Tabele: `users`, `sessions`, `oauth_states`, `user_states`, `audit_log`, `rate_limits`, `schema_migrations`.

## Frontend

Bez zmian względem poprzedniego UI: `index.html`, `js/core.js`, `js/data.js`, `js/state.js`, `js/render1.js`, `js/render2.js`, `js/actions.js`.

## Deployment

`main` → GitHub Actions → SSH/rsync → `~/public_html/alphavault.pl` + `~/alphavault-app` → migracja PHP → health check. `config.json` jest generowany z sekretów na serwerze.
