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
npm run test:macro
npm run test:router
npm run test:ui
npm run test:tax
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

## Podatki PIT-38 — 8 scenariuszy

`npm run test:tax` (`tests/tax.e2e.mjs`) zapisuje deterministyczny stan do MySQL, wczytuje go ponownie i klika panel Podatki:

- przychód, koszty, nadwyżka, dochód i podatek 19% dla roku 2026,
- zdarzenie z 2025 nie wchodzi do zestawienia 2026,
- edycja nadwyżki kosztów z poprzedniego roku,
- neutralne podatkowo (47) i forex/MT5 (2) liczone z dziennika,
- eksport CSV z podsumowaniem i rokiem w nazwie,
- wydruk PDF z rokiem, nadwyżką i licznikami,
- formularz wpłaty/wypłaty wymaga kursu NBP dla EUR i zasila PIT-38,
- puste konto nie dziedziczy demo 380 zł; brak błędów JS.

Test przywraca poprzedni stan demo po zakończeniu.

## Urządzenia zdrowotne — 42 scenariusze + 6 testów konfiguracji

`npm run test:wearables` uruchamia własny serwer aplikacji oraz lokalny, deterministyczny dostawcę OAuth (`tests/fake-health-provider.php`). Nie używa prawdziwych kont ani danych zdrowotnych.

Zakres:

- `GET /api/wearables` bez sesji = 401,
- OAuth2 + PKCE: start → authorize → callback → token → dane → gotowość dla Oura,
- scope Oura `daily`,
- jednorazowy `state`, wygasły `state`, błędny `code_verifier` = 502,
- rotacja refresh tokenów, brak podwójnego odświeżania, wygasły refresh token = 401,
- mapowanie 401/429/niepoprawnego JSON, powrót do `connected`,
- rotacja klucza AES z `WEARABLES_ENCRYPTION_KEY_PREVIOUS`, błędny klucz = `token_expired`,
- izolacja użytkowników: odczyt i DELETE nie dotykają cudzego połączenia,
- Garmin i Whoop wyłączone: brak w `devices`, start/sync/DELETE = 404,
- poprawność snu (312 min), HRV (38 ms), tętna (61) i baseline (HRV 52) dla Oura,
- ręczna synchronizacja zwraca 14 dni (paginacja Oura),
- odłączenie wycofuje dostęp u dostawcy i usuwa połączenie, metryki i baseline,
- Apple: jednorazowy kod parowania, ingest dwóch dni, token urządzenia, odrzucenie powtórzonego kodu i złego tokenu,
- gotowość łączy urządzenie i check-in (73/100),
- UI: tylko Oura i Apple w „Urządzeniach”, puste pola bez urządzenia, check-in 80/100, połączenie i metryki Oura, odłączenie czyści pola, Apple przez kod parowania, brak błędów JS,
- brak tokenów dostawców w odpowiedziach API i logach serwera.

`tests/wearables-config.test.php` (5/5) sprawdza listę dostawców (tylko Apple i Oura), brak maskowania `WEARABLES_ENCRYPTION_KEY` przez `SESSION_SECRET`, start OAuth = 503 bez klucza oraz gotowość Oura po ustawieniu klucza.

Wymaga Chrome/Edge (Playwright). Jeśli brak przeglądarki, test API nadal przechodzi, a część UI jest jawnie pominięta.

## Notowania rynkowe - 14 scenariuszy

`tests/quotes.test.php` (`npm run test:quotes`) testuje `app/Quotes.php` bez sieci i bez bazy: świeży zestaw, cache w TTL, fallback po błędzie dostawcy, pusty payload bez cache, cząstkowe pobranie, wygasanie pojedynczego notowania (900 s) i uszkodzony plik cache.

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

## Rola i recheck — 18 scenariuszy

`npm run test:roles`:

- `tests/role-guard.test.php` — interpretacja odpowiedzi Discord (`ok`, `missing`, `not_member`, 429, 5xx, 403 bota) i decyzje logowania (403, 503, grace dla zweryfikowanych, fail closed bez konfiguracji),
- `tests/role-recheck.e2e.mjs` — lokalny mock Discord + MySQL: rola obecna utrzymuje sesję, brak roli usuwa sesję i dodaje `access.role_revoked`, błąd 500 nie odbiera dostępu, 404 odbiera, 429 jest ponawiane, blokada cronu pomija równoległy przebieg.

Test nie używa prawdziwego Discorda ani danych klientów.

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

## Routing i adresy podstron — 18 scenariuszy

`tests/router.unit.mjs` (`npm run test:router`) ładuje `public/js/actions.js` w izolowanym kontekście `node:vm` (stuby DOM, bez serwera i przeglądarki) i sprawdza:

- każdy z 15 widoków ma slug, slugi są unikalne i zgodne z `^[a-z0-9]+(-[a-z0-9]+)*$`,
- brak starych krótkich adresów (`trans`, `akad`, `ust`, …) w `AV.viewSlugs`,
- mapowanie w obie strony `id → slug → id` oraz aliasy (`#/akad`, `#/trans`),
- `AV.viewFromHash()`: wielkość liter, końcowy `/`, nieznany slug i pusty hash → `pulpit`,
- `AV.showView()` zapisuje czytelny slug w `location.hash` i sprowadza nieznane id do `pulpit`.

Test nie wymaga Chrome ani PHP — chroni kontrakt adresów przed regresją przy zmianach w routingu.

## Makro — 21 scenariuszy

`npm run test:macro` uruchamia `tests/macro-events.test.php`:

- normalizacja ForexFactory: High→3, Medium→2, Low/Holiday pomijane,
- konwersja oryginalnego ISO z offsetem na UTC,
- stabilny `external_id` i opis z prognozą/poprzednim odczytem,
- normalizacja TradingView: importance 1→3, 0→2, filtr szumu i mapowanie kraju na walutę,
- UPSERT bez duplikatów,
- soft delete po braku w feedzie i przywrócenie po powrocie,
- okno źródła: FF czyści TV w swoim zakresie,
- pusta lista nie czyści widoku,
- `syncState` (status, świeżość, licznik).

Test używa lokalnej bazy testowej; nie pobiera feedów z sieci.

## API — 23 scenariusze

`tests/api.e2e.mjs` sprawdza:
- health + MySQL,
- `/api/me` bez sesji,
- `/api/state`, `/api/events` i `/api/macro-events` bez sesji = 401,
- demo login + cookie HttpOnly/SameSite,
- OAuth start bez konfiguracji = 503,
- callback ze złym `state` = 400,
- `/api/me` z sesją,
- `/api/events` z sesją,
- `/api/macro-events` z sesją (kształt, impact 2/3, UTC ISO),
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

## Izolacja użytkowników - 9 scenariuszy

`tests/isolation.e2e.mjs` (`npm run test:isolation`) używa dwóch niezależnych jarów cookie:

- osobne konto demo per przeglądarka (różne `user.id` i `av_demo`),
- powrót w tej samej przeglądarce do tego samego demo,
- `/api/me` zwraca właściwe konto,
- stan dziennika A i B jest rozdzielony,
- zapis z karty A z `ownerId` konta B = 409 `session_changed` i brak zmiany stanu,
- zapis stanu A bez `ownerId` do sesji B = 409 (zgodność ze starym klientem),
- listy połączeń integracji są rozdzielone,
- tokeny MCP A nie są widoczne w B,
- wylogowanie A nie wpływa na B.

Migracje: `php app/db/migrate.php` na czystej bazie tworzy wszystkie tabele z `db/schema.sql` oraz `db/migrations/*.sql` (w tym `mcp_*`). Runner jest idempotentny.

## UI - 55 scenariusze

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
- kalendarz + makro-widget z API, check-in, profil,
- 8 odznak niezależnie (próg, podpowiedź, pusty stan, poziom z licznika),
- nowa transakcja i zamknięcie,
- przywrócenie stanu demo,
- wylogowanie i usunięcie prywatnego cache z `localStorage`.

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

## Kodowanie — 7 scenariuszy

`tests/encoding.test.mjs` + `scripts/check-encoding.mjs`:

- naprawa mojibake `UTF-8 → Windows-1250 → UTF-8` (np. „Wartość” i „dźwignia 50×” po uszkodzonym zapisie),
- brak zmian w poprawnym tekście,
- brak mojibake w `public/js/actions.js`, `tests/prop.unit.mjs` i `tests/discord-events.test.php`,
- skan całego repo: niepoprawne UTF-8 i pozostałości mojibake,
- wyjątki tylko dla celowych fixture'ów testowych.

Naprawa istniejących plików: `node scripts/fix-mojibake.mjs --write <plik>`.

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
- kodowanie (`test:encoding`),
- PHP lint,
- migracja (schema + `db/migrations/*.sql` + `ensureColumn`/`ensureIndex`),
- rola: decyzje login + cron na mocku Discord,
- MCP security,
- prop challenge,
- Discord DB/sync,
- API e2e,
- izolacja użytkowników,
- integracje e2e + UI integracje,
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
| LH Cron | 1 wpis `*/5`; `sync.lastSuccessAt` i `roleRecheck.lastSuccessAt` = 22:00:02 UTC, `stale=false` |
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

Zakres: OKX read-only i odrzucenie handlu, BingX `apiPermissions`, WEEX akceptacja klucza z handlem + rekonstrukcja fills, MetaApi `investorMode` i odrzucenie hasła z handlem, EA kod parowania + ingest + deduplikacja, UI formularze i licznik transakcji.
