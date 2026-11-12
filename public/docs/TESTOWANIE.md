<!-- Wygenerowane z docs/TESTOWANIE.md przez scripts/sync-docs.mjs. Nie edytuj ręcznie. -->

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
npm run test:pwa
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

## Urządzenia zdrowotne — 33 scenariusze

`npm run test:wearables` uruchamia własny serwer aplikacji oraz lokalny, deterministyczny dostawcę OAuth (`tests/fake-health-provider.php`). Nie używa prawdziwych kont ani danych zdrowotnych.

Zakres:

- `GET /api/wearables` bez sesji = 401,
- OAuth2 Garmin/Oura/Whoop: start → authorize → callback → token → dane → gotowość,
- poprawność snu (312 min), HRV (38 ms), tętna (61), Body Battery (34) i baseline (HRV 52),
- ręczna synchronizacja zwraca 14 dni,
- odłączenie usuwa połączenie, metryki i baseline,
- Apple: jednorazowy kod parowania, ingest dwóch dni, token urządzenia, odrzucenie powtórzonego kodu i złego tokenu,
- gotowość łączy urządzenie i check-in (73/100),
- UI: puste pola bez urządzenia, check-in 80/100, połączenie i metryki dla Garmin/Oura/Whoop, odłączenie czyści pola, Apple przez kod parowania, brak błędów JS,
- brak tokenów dostawców w odpowiedziach API.

Wymaga Chrome/Edge (Playwright). Jeśli brak przeglądarki, test API nadal przechodzi, a część UI jest jawnie pominięta.

## Notowania rynkowe - 14 scenariuszy

`tests/quotes.test.php` (`npm run test:quotes`) testuje `app/Quotes.php` bez sieci i bez bazy: świeży zestaw, cache w TTL, fallback po błędzie dostawcy, pusty payload bez cache, cząstkowe pobranie, wygasanie pojedynczego notowania (900 s) i uszkodzony plik cache. `npm run test:quotes:e2e` uruchamia `tests/quotes.e2e.mjs`: osobne serwery PHP z lokalnym dostawcą notowań sprawdzają odpowiedź HTTP, cache i pusty stan.

## Discord DB/sync - 24 scenariusze

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

## Prop challenge — 27 scenariuszy

`tests/prop.unit.mjs` (`npm run test:prop`) testuje czysty silnik `public/js/prop.js` bez serwera i bazy:

- katalog szablonów i normalizację danych,
- filtr konta i zakresu dat (włącznie z końcem),
- cel zysku, brak 1 centa, min. dni handlowe,
- dzienny limit 75/90/100%, podstawę initial/dayStart, limit 0,
- obsunięcie static i trailing, linię stopu i bufor,
- termin, brak terminu, min. dni przed deadlinem,
- statystyki, serię strat, najlepszy/najgorszy dzień,
- archiwum poza metrykami i zachowanie waluty.

## API — 21 scenariuszy

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
- round-trip `prop.challenges` i `selectedId` przez MySQL,
- konflikt rewizji = 409,
- CSRF / złe `Origin` = 403,
- UI `/`,
- `/docs/API.md`,
- eksport JSON,
- przywrócenie stanu,
- logout + unieważnienie sesji.

## UI — 51 scenariuszy

`tests/ui.e2e.mjs` używa Playwright + systemowego Chrome/Edge:

- start UI i modal logowania,
- demo login + sesja,
- 47 transakcji z MySQL,
- live i konsultacja z `/api/events`,
- harmonogram tygodnia i strip,
- nawigacja 15 widoków (w tym prop),
- plan dnia, filtry dziennika, szczegóły transakcji,
- kalkulator, statystyki, mentor, akademia,
- prop: 9 szablonów, nowy challenge + 4 KPI,
- prop: walidacja formularza i XSS w nazwie,
- prop: alarm limitu + banner na pulpicie,
- prop: archiwum, empty state i przywrócenie,
- prop: zapis w MySQL, reload i usunięcie,
- kalendarz, check-in, profil,
- 8 odznak niezależnie (próg, podpowiedź, pusty stan, poziom z licznika),
- nowa transakcja i zamknięcie,
- przywrócenie stanu demo,
- wylogowanie.

## UI empty-state — 12 scenariuszy

`tests/ui-empty.e2e.mjs` przy poprawnym sync zwracającym `[]` sprawdza:

- pusty tytuł, prowadzący i ukryta karta live,
- pustą kartę konsultacji,
- puste pola w strip,
- ukryty link „Dołącz”,
- 7 dni tygodnia bez wydarzeń,
- `events.length === 0`,
- dostępny stan synchronizacji.

## PWA mobile — 20 scenariuszy

`tests/pwa.e2e.mjs` używa Playwright + systemowego Chrome w emulacji iPhone 14 (390×844, touch, iOS UA). Sprawdza:

- manifest: `display=standalone`, `start_url`, ikony 192/512/maskable,
- meta iOS i `apple-touch-icon`,
- rejestrację service workera i przejęcie kontroli nad stroną,
- `Page.getAppManifest` i `Page.getInstallabilityErrors` bez błędów,
- brak poziomego przewijania na pulpicie,
- przycisk instalacji i stan powiadomień,
- zamknięcie transakcji → powiadomienie systemowe z deep linkiem `?qa=<id>`,
- deep link otwiera modal z prawdziwą transakcją,
- emocja + ocena + zdjęcie zapisane w ≤ 10 s (mierzone `AV.ui.qaLastSeconds`),
- stały przycisk zapisu na telefonie,
- push przez CDP `ServiceWorker.deliverPushMessage`,
- offline: powłoka aplikacji z cache service workera.

To emulacja Chromium. Instalacja na fizycznym iPhone (Safari → Do ekranu początkowego) i powiadomienia iOS wymagają testu ręcznego; patrz „Czego testy nie obejmują”.

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
- PWA e2e (emulacja iPhone 14, Chrome),
- empty-state na osobnym porcie.

## Produkcja — 2026-11-12

Wykonane na `https://alphavault.pl`:

| Test | Wynik |
| --- | --- |
| `GET /` | 200, UI Alpha Vault |
| `GET /api/health` | `ok=true`, DB 11.8.6-MariaDB, wszystkie flagi true |
| `GET /docs/` | 200, indeks PRD/DOCUMENTATION/API |
| `GET /.app/config.json` | 403 |
| `GET /api/events` bez sesji | 401 |
| Realny sync | 3 wydarzenia VOICE, 3 unikalne ID |
| Baza | 3 rekordy `live`, `sync.status=success` |
| Błąd 401 (zły token) | `sync.status=error`, 3 wydarzenia zachowane, `last_success_at` bez zmian |
| LH Cron | logi `18:05:05` i `18:10:02` — uruchomienie co 5 min |
| Monitoring | `monitor-production.yml` — health + `sync.status`/`stale`, co 15 min |
| Playwright (sesja testowa, read-only) | live + harmonogram widoczne, konsultacje puste, brak błędów JS |
| PWA manifest/SW/ikony | 200, `application/manifest+json`, ikony `/assets/pwa/*`, installability bez błędów |
| PWA mobile E2E | 20/20 lokalnie i w CI; fizyczny telefon — do potwierdzenia |

Sesja testowa została usunięta po weryfikacji. Nie pozostał żaden testowy użytkownik ani token.

## Czego testy nie obejmują

- prawdziwego logowania Discord (wymaga interakcji użytkownika),
- instalacji PWA na fizycznym telefonie i powiadomień iOS (wymaga urządzenia),
- realnego Stage (brak wydarzenia w API — tylko fixture),
- produkcyjnego SSH/rsync (sprawdzane przez dry run deployu),
- LH Cron (wymaga panelu LH),
- testów obciążeniowych.

Testy Discord wykonuj ręcznie na koncie z rolą Alpha, bez roli i spoza serwera. Nie deklaruj wyniku bez rzeczywistego udziału użytkownika.
﻿## Integracje giełd, MetaTrader i API

```powershell
npm run test:integrations      # API + mocki OKX/BingX/WEEX/MetaApi/EA
npm run test:ui:integrations   # Playwright: formularze i dziennik
```

Wymagania: `tests/config.integrations.json` (kopiuj z `config.test.json` i dodaj `INTEGRATIONS_KEY`, `METAAPI_TOKEN` oraz adresy `*_BASE` na lokalny mock). Testy uruchamiają własny serwer mocków na `127.0.0.1:4191` i aplikację na `4175`.

Zakres: OKX read-only i odrzucenie handlu, BingX `apiPermissions`, WEEX probe + rekonstrukcja fills, MetaApi `investorMode` i odrzucenie hasła z handlem, EA kod parowania + ingest + deduplikacja, UI formularze i licznik transakcji.
