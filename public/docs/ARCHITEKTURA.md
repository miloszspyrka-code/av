<!-- Wygenerowane z docs/ARCHITEKTURA.md przez scripts/sync-docs.mjs. Nie edytuj ręcznie. -->

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
   │     └── docs/       dokumentacja publiczna (generowana)
   ├── ~/public_html/alphavault.pl/.app/  app/, bin/, db/, config/, config.json, logs/
   └── MySQL/MariaDB LH.pl
        ▲
        │ co 5 minut
   LH Cron → bin/sync-discord-events.php → Discord REST API
   LH Cron → bin/sync-wearables.php → Oura / Whoop / Garmin API
```

Brak procesu Node na produkcji. PHP-FPM obsługuje żądania per request. Szczegółowe przepływy: [DOCUMENTATION.md](DOCUMENTATION.md). Wymagania: [PRD.md](PRD.md).

## Warstwy PHP

| Plik | Rola |
| --- | --- |
| `app/Config.php` | konfiguracja z `config.json` / env, walidacja produkcji |
| `app/Db.php` | PDO MySQL, migracje, transakcje, health |
| `app/Auth.php` | cookie, sesje, CSRF, rate limit, audit, public user |
| `app/Discord.php` | OAuth2, profil, członkostwo i rola |
| `app/DiscordEvents.php` | Discord Scheduled Events, klasyfikacja, UPSERT, soft delete |
| `app/Wearables.php` | OAuth2 urządzeń, szyfrowanie tokenów, ingest Apple, metryki i gotowość |
| `app/Http.php` | JSON/HTML, nagłówki, błędy |
| `app/routes/api.php` | `/api/health`, `/api/me`, `/api/state`, `/api/events`, `/api/wearables`, admin, konto, eksport |
| `app/routes/auth.php` | `/auth/discord`, `/auth/wearables/*`, callback, demo, logout |
| `bin/sync-discord-events.php` | skrypt LH Cron (co 5 min) |
| `bin/sync-wearables.php` | odświeżanie tokenów i metryk urządzeń (cron) |
| `public/index.php` | routing i serwowanie UI |
| `scripts/sync-docs.mjs` | generowanie `public/docs` z `docs/` |

## Dane

`user_states.state` to dokument JSON całego stanu aplikacji. Zapisy są optymistyczne: `revision` + `baseRevision`, konflikt → 409.

Tabele:

- użytkownicy i dostęp: `users`, `sessions`, `oauth_states`, `audit_log`, `rate_limits`,
- stan: `user_states`,
- wydarzenia: `discord_scheduled_events`, `discord_sync_state`, `discord_sync_runs`,
- urządzenia: `wearable_connections`, `wearable_daily_metrics`, `wearable_oauth_states`, `wearable_pair_codes`,
- migracje: `schema_migrations`.

Schemat i indeksy: [BAZA-DANYCH.md](BAZA-DANYCH.md).

## Frontend

Bez frameworka: `index.html`, `js/core.js`, `js/data.js`, `js/state.js`, `js/render1.js`, `js/render2.js`, `js/actions.js`.

- `state.js` ładuje `/api/me`, `/api/state`, `/api/events`, `/api/wearables` i utrzymuje rewizję,
- `render2.js` renderuje Akademię, konsultacje, harmonogram, urządzenia i panel admina,
- `actions.js` obsługuje timery, przypomnienia i interakcje.

Puste pola i stany brzegowe: [DOCUMENTATION.md §12](DOCUMENTATION.md#12-puste-pola-i-stany-brzegowe).

## Deployment

`main` → GitHub Actions → SSH/rsync → `~/public_html/alphavault.pl` + `~/public_html/alphavault.pl/.app` → migracja PHP → health check. `config.json` jest generowany z sekretów na serwerze. Alternatywa: `scripts/local-deploy.sh` (WSL2) bez GitHub Actions.

- deploy: [DEPLOY.md](https://github.com/miloszspyrka-code/alphavault/blob/main/docs/DEPLOY.md)
- deploy lokalny: [LOCAL-DEPLOY.md](https://github.com/miloszspyrka-code/alphavault/blob/main/docs/LOCAL-DEPLOY.md)
- testy: [TESTOWANIE.md](TESTOWANIE.md)

## Integracje giełd i brokerów

```text
app/Integrations/
  Crypto.php            AES-256-GCM envelope (INTEGRATIONS_KEY)
  HttpClient.php        cURL/stream, limity, bez logowania sekretów
  Normalizer.php        pary, liczby, UTC ISO
  OKXProvider.php       account/config + positions-history + fills-history
  BingXProvider.php     apiPermissions + income + positionHistory
  WEEXProvider.php      probe read-only + userTrades + rekonstrukcja
  MetaApiProvider.php   provisioning + account-information + history-deals
  PositionReconstructor.php  fills -> zamknięte transakcje
  ConnectionStore.php   szyfrowane połączenia, maski, statusy
  ExchangeSync.php      okna 30 dni, deduplikacja, merge do user_states
  MtEa.php              kod parowania + ingest z EA
```

Przepływ: UI → `/api/connections` → provider → `ConnectionStore`; worker → `ExchangeSync` → `exchange_imports` + `user_states.trades`.
