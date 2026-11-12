<!-- Wygenerowane z docs/BAZA-DANYCH.md przez scripts/sync-docs.mjs. Nie edytuj ręcznie. -->

# Baza danych

Produkcja: MySQL/MariaDB na LH.pl (MariaDB 11.8). Lokalnie: MySQL 8.4 lub MariaDB.

Schemat: `db/schema.sql`. Migracja: `php app/db/migrate.php`.

## Tabele

| Tabela | Rola |
| --- | --- |
| `users` | konto Discord/demo, plan, dostęp, weryfikacja roli |
| `sessions` | hash tokena sesji, IP, user agent, wygaśnięcie |
| `oauth_states` | jednorazowe stany OAuth2 (CSRF), 10 min |
| `user_states` | cały stan JSON + rewizja + rozmiar |
| `audit_log` | logowania, zapisy stanu, usunięcie konta |
| `rate_limits` | limity per bucket/IP/window |
| `discord_scheduled_events` | zsynchronizowane wydarzenia Discord (UPSERT, soft delete) |
| `discord_sync_state` | ostatnia próba/sukces, licznik, błąd, blokada CRON |
| `discord_sync_runs` | historia synchronizacji (30 dni) |
| `wearable_connections` | jedno połączenie użytkownika z dostawcą; status, zaszyfrowane tokeny, token urządzenia Apple (hash), last sync |
| `wearable_daily_metrics` | dzienne metryki zdrowotne: sen, HRV, tętno, Body Battery, wynik dostawcy, surowa odpowiedź |
| `wearable_oauth_states` | jednorazowe stany OAuth2 dla Garmin/Oura/Whoop (10 min) |
| `wearable_pair_codes` | jednorazowe kody parowania Apple (hash, 15 min) |
| `mcp_clients` | klienci MCP (DCR), redirect URI, opcjonalny secret hash |
| `mcp_auth_codes` | kody autoryzacyjne OAuth 2.1, TTL, revocation |
| `mcp_access_tokens` | `jti`, user, scope, audience, expiry, revocation |
| `mcp_refresh_tokens` | refresh tokeny i rotacja |
| `mcp_login_requests` | stan logowania Discord na domenie MCP |
| `mcp_consents` | ekran zgody OAuth i CSRF |
| `mcp_idempotency` | klucze idempotencji przyszłych zapisów MCP |
| `schema_migrations` | wersje migracji |
| `exchange_connections` | połączenia giełd/brokerów, szyfrowane poświadczenia, maska, status, kursor backfillu |
| `exchange_imports` | deduplikacja transakcji po `(user_id, provider, external_id)` |
| `exchange_sync_runs` | historia synchronizacji integracji (fetched/imported/error) |
| `exchange_sync_state` | stan schedulera integracji (ostatnia próba/sukces, liczniki) |

## Stan JSON

`user_states.state` to jeden dokument JSON. Limit `MAX_STATE_BYTES` = 12 MiB. Zapis jest optymistyczny: `revision` + `baseRevision`; konflikt → 409. Przy setkach screenów należy przenieść obrazy do osobnego storage i trzymać w stanie URL.

Dokument zawiera m.in. `prop.challenges` (konfiguracje challenge'ów) i `prop.selectedId`. Metryki prop (cel, dzienny limit, obsunięcie, dni) są liczone po stronie frontendu z `trades` — bez dodatkowych tabel i endpointów. Szczegóły: [PROP.md](PROP.md).

## Dane zdrowotne

- `wearable_connections` ma unikalny klucz `(user_id, provider)` i klucz obcy do `users` z `ON DELETE CASCADE`.
- `wearable_daily_metrics` ma unikalny klucz `(user_id, provider, metric_date)`, więc ponowna synchronizacja robi UPSERT.
- `access_token_enc` / `refresh_token_enc` to AES-256-GCM (`v1:` + nonce + tag + ciphertext); klucz nie jest w bazie.
- `raw_payload` nie jest zwracany przez API.
- `DELETE /api/wearables/{provider}` usuwa wiersze metryk i połączenia w jednej transakcji.
- Apple `device_token_hash` to SHA-256; sam token jest zwracany tylko przy parowaniu.

## MariaDB

- charset `utf8mb4`, collation `utf8mb4_unicode_ci`,
- `JSON` w MariaDB jest aliasem LONGTEXT z walidacją; PHP koduje/dekoduje jawnie,
- `TIMESTAMP ... ON UPDATE CURRENT_TIMESTAMP` wspierane,
- klucze obce `ON DELETE CASCADE` / `SET NULL` wspierane.

## Migracje

1. Odczytaj schemat przez MySQL MCP / `SHOW CREATE TABLE`.
2. Dodaj nowy plik w `db/migrations/` (jeśli projekt go używa) lub zmień `db/schema.sql` tylko dla nowych tabel.
3. Przed destrukcyjną zmianą: backup i zgoda.
4. Zastosuj `php app/db/migrate.php` (idempotentne `CREATE TABLE IF NOT EXISTS`).
5. Zweryfikuj `/api/health` i testy.

Nie przechowuj dumpów produkcyjnych ani danych klientów w repo.

## Backup

```bash
mysqldump --single-transaction --routines --triggers \
  serwer445119_alphavault > ~/backups/alphavault-$(date +%F).sql
```

Retencja 14–30 dni, kopia off-site, test odtworzenia raz w miesiącu.
