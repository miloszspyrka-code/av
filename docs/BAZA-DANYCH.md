# Baza danych

Produkcja: MySQL/MariaDB na LH.pl (MariaDB 11.8). Lokalnie: MySQL 8.4 lub MariaDB.

Schemat: `db/schema.sql`. Migracja: `php app/db/migrate.php`.

## Tabele

| Tabela | Rola |
| --- | --- |
| `users` | konto Discord/demo, plan, dostęp, weryfikacja roli (`role_verified`, `role_checked_at`, `role_check_status`, `role_check_error`) |
| `sessions` | hash tokena sesji, IP, user agent, wygaśnięcie |
| `oauth_states` | jednorazowe stany OAuth2 (CSRF), 10 min |
| `user_states` | cały stan JSON + rewizja + rozmiar |
| `audit_log` | logowania, zapisy stanu, usunięcie konta, `access.role_revoked` |
| `role_check_state` | stan cronu weryfikacji roli: ostatni przebieg, liczniki, blokada |
| `rate_limits` | limity per bucket/IP/window |
| `discord_scheduled_events` | zsynchronizowane wydarzenia Discord (UPSERT, soft delete) |
| `discord_sync_state` | ostatnia próba/sukces, licznik, błąd, blokada CRON |
| `discord_sync_runs` | historia synchronizacji (30 dni) |
| `macro_events` | publikacje makro z feedów (UPSERT, soft delete, źródło FF/TradingView) |
| `macro_sync_state` | ostatnia próba/sukces synchronizacji makro, licznik, błąd |
| `wearable_connections` | jedno połączenie użytkownika z dostawcą; status, zaszyfrowane tokeny, `token_expires_at`, `refresh_token_expires_at`, token urządzenia Apple (hash), last sync |
| `wearable_daily_metrics` | dzienne metryki zdrowotne: sen, HRV, tętno, Body Battery, wynik dostawcy, surowa odpowiedź |
| `wearable_oauth_states` | jednorazowe stany OAuth2 dla Oura (10 min) wraz z `code_verifier` dla PKCE; Garmin/Whoop wyłączone |
| `wearable_pair_codes` | jednorazowe kody parowania Apple (hash, 15 min) |
| `mcp_clients` | klienci OAuth 2.1 (DCR): `client_id`, redirect URI, metoda auth |
| `mcp_auth_codes` | jednorazowe kody autoryzacyjne (PKCE, TTL 5 min) |
| `mcp_access_tokens` | access tokeny JWT: `jti`, user, client, scope, audience, TTL 1 h |
| `mcp_refresh_tokens` | refresh tokeny 30 dni z rotacją i unieważnianiem rodziny |
| `mcp_login_requests` | stan logowania Discord na domenie MCP |
| `mcp_consents` | ekran zgody OAuth: CSRF, scope, resource, TTL 30 min |
| `mcp_proposals` | propozycje zmian od agenta oczekujące na decyzję użytkownika; `payload_hash`, `base_revision`, TTL 30 min |
| `mcp_audit` | audyt operacji MCP (agent, użytkownik, system) |
| `mcp_idempotency` | rezerwa pod przyszłe operacje bezpośrednie |
| `schema_migrations` | wersje migracji |
| `exchange_connections` | połączenia giełd/brokerów, szyfrowane poświadczenia, maska, status, kursor backfillu; kod EA ważny 15 min przed pierwszym użyciem i 90 dni od ostatniej synchronizacji |
| `exchange_imports` | deduplikacja transakcji po `(user_id, provider, external_id)` |
| `exchange_sync_runs` | historia synchronizacji integracji (fetched/imported/error) |
| `exchange_sync_state` | stan schedulera integracji (ostatnia próba/sukces, liczniki) |

## Stan JSON

`user_states.state` to jeden dokument JSON. Limit `MAX_STATE_BYTES` = 12 MiB. Zapis jest optymistyczny: `revision` + `baseRevision`; konflikt → 409. Przy setkach screenów należy przenieść obrazy do osobnego storage i trzymać w stanie URL.

Dokument zawiera m.in. `prop.challenges` (konfiguracje challenge'ów) i `prop.selectedId`. Metryki prop (cel, dzienny limit, obsunięcie, dni) są liczone po stronie frontendu z `trades` — bez dodatkowych tabel i endpointów. Szczegóły: [PROP.md](PROP.md).

## Dane makro

- `macro_events.external_id` to SHA-1 z `source|currency|nazwa|data ISO`; unikalny klucz pozwala robić UPSERT bez duplikatów.
- `source` rozróżnia `forexfactory` (bieżący tydzień) i `tradingview` (następny tydzień).
- `starts_at_utc` służy do okien i sortowania; `starts_at_iso` zachowuje oryginalny offset źródła.
- `impact`: 3 = ★★★, 2 = ★★. Publikacje Low/Holiday nie są zapisywane.
- Brak wydarzenia w udanym feedzie → `is_deleted=1`; powrót → `is_deleted=0`.
- `raw_payload` nie jest przechowywany.

## Dane zdrowotne

- `wearable_connections` ma unikalny klucz `(user_id, provider)` i klucz obcy do `users` z `ON DELETE CASCADE`.
- `wearable_daily_metrics` ma unikalny klucz `(user_id, provider, metric_date)`, więc ponowna synchronizacja robi UPSERT.
- `access_token_enc` / `refresh_token_enc` to AES-256-GCM (`v1:` + nonce + tag + ciphertext); klucz nie jest w bazie.
- Klucz pochodzi wyłącznie z `WEARABLES_ENCRYPTION_KEY`; brak fallbacku na `SESSION_SECRET`. `WEARABLES_ENCRYPTION_KEY_PREVIOUS` pozwala odczytać tokeny zaszyfrowane starym kluczem w czasie rotacji.
- `code_verifier` w `wearable_oauth_states` jest usuwany razem ze `state` po callbacku; nie trafia do API ani logów.
- `refresh_token_expires_at` jest sprawdzane przed odświeżeniem; wygasły refresh token wymaga ponownego połączenia.
- `raw_payload` nie jest zwracany przez API.
- `DELETE /api/wearables/{provider}` najpierw próbuje wycofać dostęp u dostawcy, a potem usuwa wiersze metryk i połączenia w jednej transakcji.
- Apple `device_token_hash` to SHA-256; sam token jest zwracany tylko przy parowaniu.

## MariaDB

- charset `utf8mb4`, collation `utf8mb4_unicode_ci`,
- `JSON` w MariaDB jest aliasem LONGTEXT z walidacją; PHP koduje/dekoduje jawnie,
- `TIMESTAMP ... ON UPDATE CURRENT_TIMESTAMP` wspierane,
- klucze obce `ON DELETE CASCADE` / `SET NULL` wspierane.

## Migracje

1. Odczytaj schemat przez MySQL MCP / `SHOW CREATE TABLE`.
2. Dodaj nowy plik `db/migrations/RRRRMMDD_nazwa.sql` (idempotentny) i, dla nowych instalacji, uzupełnij `db/schema.sql`.
3. Przed destrukcyjną zmianą: backup i zgoda.
4. Zastosuj `php app/db/migrate.php`. Runner najpierw wykonuje `db/schema.sql` (z `ensureColumn`/`ensureIndex` dla nowych kolumn istniejących tabel), a potem wszystkie nieaplikowane pliki z `db/migrations/` i zapisuje wersje w `schema_migrations`.
5. Ponowne uruchomienie jest bezpieczne — zastosowane wersje są pomijane.
6. Zweryfikuj `/api/health` (`roleRecheck`) i testy.

Nie przechowuj dumpów produkcyjnych ani danych klientów w repo.

## Backup

```bash
mysqldump --single-transaction --routines --triggers \
  serwer445119_alphavault > ~/backups/alphavault-$(date +%F).sql
```

Retencja 14–30 dni, kopia off-site, test odtworzenia raz w miesiącu.
