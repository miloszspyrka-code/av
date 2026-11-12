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

Test pustych pól wymaga drugiego serwera z gildią bez wydarzeń:

```powershell
$env:ALPHA_VAULT_CONFIG = "tests/config.test.json"
$env:DISCORD_GUILD_ID = "empty-guild"
php -S 127.0.0.1:4174 -t public public/router.php
```

```powershell
$env:APP_URL = "http://127.0.0.1:4174"
npm run test:ui:empty
```

## Discord DB/sync — 24 scenariusze

`tests/discord-events.test.php` sprawdza:

- DST: `2026-10-25T00:30:00Z`, `2026-10-25T01:30:00Z`, `2027-03-28T00:30:00Z`, `2027-03-28T01:30:00Z`,
- prowadzącego z nazwy `X - Host - Y`,
- brak prowadzącego, gdy nie ma separatora,
- pierwszy sync: 4 wydarzenia (VOICE, konsultacja, EXTERNAL, Stage),
- mapowanie `STAGE_INSTANCE`,
- brak duracji przy braku `scheduled_end_time`,
- `user_count = NULL` pozostaje `NULL`,
- `EXTERNAL` + `entity_metadata.location`,
- listę i klasyfikację `live` / `consultation`,
- `nextConsultation`,
- drugi sync bez duplikatów,
- aktualizację zmienionych pól (UPSERT),
- zachowanie `manual_category` po synchronizacji,
- błędny payload nie zmienia bazy,
- pusty sukces czyści listę i soft-deletuje rekordy,
- powrót wydarzenia przywraca rekord.

## API — 20 scenariuszy

`tests/api.e2e.mjs` sprawdza:

- health + MySQL,
- `/api/me` bez sesji,
- `/api/state` i `/api/events` bez sesji = 401,
- demo login + cookie HttpOnly/SameSite,
- OAuth start bez konfiguracji = 503,
- callback ze złym `state` = 400,
- `/api/me` z sesją,
- `/api/events` z sesją,
- `/api/admin/events` bez uprawnień = 403,
- GET/PUT state + MySQL,
- konflikt rewizji = 409,
- CSRF / złe `Origin` = 403,
- UI `/`,
- `/docs/API.md`,
- eksport JSON,
- przywrócenie stanu,
- logout + unieważnienie sesji.

## UI — 23 scenariusze

`tests/ui.e2e.mjs` używa Playwright + systemowego Chrome/Edge:

- start UI i modal logowania,
- demo login + sesja,
- 47 transakcji z MySQL,
- live i konsultacja z `/api/events`,
- harmonogram tygodnia i strip,
- nawigacja 14 widoków,
- plan dnia, filtry dziennika, szczegóły transakcji,
- kalkulator, statystyki, mentor, akademia,
- kalendarz, check-in, profil,
- nowa transakcja i zamknięcie,
- przywrócenie stanu demo,
- wylogowanie.

## UI empty-state — 10 scenariuszy

`tests/ui-empty.e2e.mjs` przy poprawnym sync zwracającym `[]` sprawdza:

- pusty tytuł, prowadzący i countdown,
- pustą kartę konsultacji,
- puste pola w strip,
- ukryty przycisk „Dołącz”,
- 7 dni tygodnia bez wydarzeń,
- `events.length === 0`,
- dostępny stan synchronizacji.

## Dokumentacja — 16 scenariuszy

`tests/docs.test.mjs` sprawdza:

- względne linki w `README.md`, `PRD.md`, `DOCUMENTATION.md`, `AGENTS.md` i `docs/*.md`,
- względne linki w `public/docs`,
- kodowanie UTF-8 (brak mojibake),
- wymagane rozdziały PRD/DOCUMENTATION/AGENTS,
- wzajemne linki dokumentów głównych,
- indeks `docs/README.md`,
- synchronizację `public/docs` z źródłami,
- obecność wszystkich generowanych plików.

## Live Discord

`.github/workflows/discord-sync-live-test.yml`:

- uruchamiany ręcznie (`workflow_dispatch`),
- tworzy tymczasowy MySQL 8.4,
- używa sekretów `DISCORD_*`,
- wykonuje dwie synchronizacje,
- wypisuje `LIVE_SYNC_EVENTS`, `LIVE_SYNC_UNIQUE`, `EVENT=` i `SYNC_STATE=`,
- kończy się błędem przy duplikatach.

## CI

`.github/workflows/ci.yml`:

- MySQL 8.4 service,
- PHP 8.3 + Node 24,
- dokumentacja (`docs:check` + `test:docs`),
- PHP lint,
- migracja,
- Discord DB/sync,
- API e2e,
- UI e2e (systemowy Chrome),
- empty-state na osobnym porcie.

## Czego testy nie obejmują

- prawdziwego logowania Discord (wymaga interakcji użytkownika),
- realnego Stage (brak wydarzenia w API — tylko fixture),
- produkcyjnego SSH/rsync (sprawdzane przez dry run deployu),
- LH Cron (wymaga panelu LH),
- testów obciążeniowych.

Testy Discord wykonuj ręcznie na koncie z rolą Alpha, bez roli i spoza serwera. Nie deklaruj wyniku bez rzeczywistego udziału użytkownika.
