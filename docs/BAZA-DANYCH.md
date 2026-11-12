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
| `schema_migrations` | wersje migracji |

## Stan JSON

`user_states.state` to jeden dokument JSON. Limit `MAX_STATE_BYTES` = 12 MiB. Zapis jest optymistyczny: `revision` + `baseRevision`; konflikt → 409. Przy setkach screenów należy przenieść obrazy do osobnego storage i trzymać w stanie URL.

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
