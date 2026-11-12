<!-- Wygenerowane z PRD.md przez scripts/sync-docs.mjs. Nie edytuj ręcznie. -->

# PRD — Alpha Vault

| Pole | Wartość |
| --- | --- |
| Produkt | Alpha Vault — prywatna strefa tradera Alpha Moves |
| Domena | <https://alphavault.pl> |
| Wersja dokumentu | 3.1 |
| Data | 2026-11-12 |
| Właściciel produktu | Alpha Moves |
| Odbiorca | agent **AV-engineer**, zespół techniczny |
| Status | kod i testy gotowe; produkcja oczekuje na deploy (szczegóły w §11) |

**Dokumenty powiązane**

- [DOCUMENTATION.md](DOCUMENTATION.md) — jak aplikacja działa, punkt po punkcie, wraz z pełną listą punktów styku.
- [AGENTS.md](https://github.com/miloszspyrka-code/alphavault/blob/main/AGENTS.md) — kontrakt pracy agenta AV-engineer, komendy, zakazy i Definition of Done.
- [docs/FUNKCJE.md](FUNKCJE.md) — stan każdej funkcji (E2E / lokalnie / demo).
- [docs/API.md](API.md) — kontrakt HTTP i przykłady odpowiedzi.
- [docs/ARCHITEKTURA.md](ARCHITEKTURA.md) — warstwy i przepływy techniczne.
- [docs/BAZA-DANYCH.md](BAZA-DANYCH.md) — schemat MySQL i backup.
- [docs/DISCORD.md](https://github.com/miloszspyrka-code/alphavault/blob/main/docs/DISCORD.md) — konfiguracja OAuth2, bota i roli.
- [docs/DEPLOY.md](https://github.com/miloszspyrka-code/alphavault/blob/main/docs/DEPLOY.md) — wdrożenie na LH.pl.
- [docs/TESTOWANIE.md](TESTOWANIE.md) — testy i checklisty.

---

## 1. Streszczenie

Alpha Vault to aplikacja webowa dla społeczności traderów Alpha Moves. Łączy dziennik transakcji, plan dnia, statystyki, materiały edukacyjne oraz harmonogram live'ów i konsultacji 1:1. Dostęp jest zamknięty: użytkownik loguje się przez Discord i musi mieć właściwą rolę na serwerze Alpha Moves.

W wersji 3.0 backend został zmigrowany z Express/Node.js na PHP 8.3, a harmonogram Akademii jest synchronizowany z Discord Scheduled Events przez LH Cron. Discord jest źródłem prawdy o wydarzeniach; aplikacja przechowuje ich kopię w MySQL i prezentuje w strefie `Europe/Warsaw`.

## 2. Problem i cel

**Problem**

- Harmonogram live'ów i konsultacji był wpisywany ręcznie w kodzie lub prezentowany jako dane demonstracyjne.
- Zmiany w Discordzie nie trafiały do panelu; użytkownik widział nieaktualne godziny.
- Ręczna edycja powodowała literówki, duplikaty i niespójne strefy czasowe.
- Hosting współdzielony LH.pl nie pozwala na stały proces Node.js.

**Cel**

- Jedno źródło prawdy: Discord Scheduled Events.
- Automatyczna synchronizacja co 5 minut, bez otwierania panelu przez użytkownika.
- Poprawne godziny polskie, również przy zmianie czasu letniego/zimowego.
- Puste pola, gdy nie ma wydarzeń — bez danych zastępczych.
- Zachowanie dotychczasowych funkcji tradingowych i Akademii.

## 3. Użytkownicy i role

| Rola | Kim jest | Co robi |
| --- | --- | --- |
| Trader (`full`) | członek społeczności z rolą Alpha | panel tradingowy, live'y, konsultacje, materiały |
| Trader (`solo`) | plan bez Akademii | panel tradingowy; Akademia zablokowana |
| Administrator wydarzeń | Discord ID z `ADMIN_DISCORD_IDS` | przypisuje wydarzenia do `live` / `consultation` / `unassigned` |
| Bot Discord | aplikacja, nie człowiek | odczyt `GET /guilds/{id}/scheduled-events`; brak uprawnień do zarządzania |
| LH Cron | system | uruchamia `bin/sync-discord-events.php` co 5 minut |

## 4. Zakres

### 4.1. W zakresie

- logowanie Discord OAuth2 i weryfikacja roli,
- zachowanie istniejącego UI i funkcji tradingowych,
- synchronizacja Discord Scheduled Events do MySQL,
- klasyfikacja wydarzeń i ręczne przypisanie przez administratora,
- harmonogram live'ów i karta konsultacji 1:1 zasilane rzeczywistymi danymi,
- puste pola przy braku wydarzeń,
- strefa `Europe/Warsaw` i DST,
- LH Cron, blokada równoległych uruchomień, diagnostyka synchronizacji,
- testy jednostkowe, API, UI, empty-state i DST,
- dokumentacja spójna i wersjonowana.

### 4.2. Poza zakresem

- WordPress i inne strony na tym hostingu — nie modyfikować,
- Discord Gateway / WebSocket i stały proces Node.js na produkcji,
- zarządzanie wydarzeniami z panelu (bot read-only),
- płatności Stripe (modal zakupu jest demonstracyjny),
- wysyłka e-mail/SMS przypomnień poza aplikacją,
- generowanie kolejnych wystąpień wydarzeń cyklicznych (przechowujemy `recurrence_rule`, ale nie tworzymy fikcyjnych wystąpień),
- natywna aplikacja mobilna w App Store/Google Play; PWA jest w zakresie (FR-17).

## 5. Model dostępu i konta

- Logowanie wyłącznie Discord OAuth2 (`identify guilds.members.read`).
- Wymagane: członkostwo w serwerze `DISCORD_GUILD_ID` i rola `DISCORD_REQUIRED_ROLE_ID`.
- Rola jest sprawdzana przy logowaniu i cyklicznie co 5 minut; jej odebranie usuwa sesje i blokuje panel.
- Chwilowy błąd Discorda nie odbiera dostępu kontom już zweryfikowanym (fail open); brak roli i brak konfiguracji = brak dostępu (fail closed).
- Sesja: cookie `av_session` HttpOnly, SameSite=Lax, Secure na HTTPS, 30 dni przy „Nie wylogowuj mnie”.
- CSRF: kontrola `Origin` dla żądań mutujących.
- Rate limiting w MySQL dla logowania i operacji wrażliwych.
- Demo login (`POST /auth/demo`) tylko lokalnie, gdy `ALLOW_DEMO_LOGIN=true`; na produkcji wyłączony.

## 6. Wymagania funkcjonalne

### FR-01 — Logowanie Discord i rola

Użytkownik klika „Zaloguj przez Discord”, przechodzi OAuth2, a serwer sprawdza członkostwo i rolę. Brak roli lub członkostwa kończy się czytelnym komunikatem i brakiem sesji. Cron co 5 minut ponawia sprawdzenie aktywnych sesji; odebranie roli blokuje panel. Chwilowy błąd Discorda nie odbiera dostępu kontom już zweryfikowanym.

### FR-02 — Sesja, CSRF, rate limit

Sesja jest przechowywana jako hash w MySQL, nie w cookie. Mutacje wymagają poprawnego `Origin`. Limity chronią logowanie i zapis stanu.

### FR-03 — Trwały stan aplikacji

Cały stan użytkownika jest dokumentem JSON w `user_states`. Zapis jest optymistyczny: `revision` + `baseRevision`; konflikt zwraca 409 bez nadpisania danych. `localStorage` służy tylko jako fallback offline.

### FR-04 — Panel tradingowy bez regresji

Zachowane: pulpit, plan dnia, dziennik, playbook, nowa transakcja, kalkulator, prop challenge, statystyki, mentor AI, podatki PIT-38, kalendarz makro, ciało i głowa, giełdy, ustawienia, eksport i usunięcie konta.

### FR-05 — Harmonogram live'ów z Discorda

- źródło: `GET https://discord.com/api/v10/guilds/{guild.id}/scheduled-events?with_user_count=true`,
- grupowanie po lokalnej dacie `Europe/Warsaw`,
- polskie dni tygodnia, oznaczenie `DZIŚ`,
- sortowanie chronologiczne po rzeczywistym timestampie,
- wiele wydarzeń jednego dnia,
- godzina `HH:mm`, liczba zainteresowanych, jeśli dostępna,
- brak wpisanych na stałe godzin i tytułów.

### FR-06 — Karta konsultacji 1:1

- „Najbliższa konsultacja” pokazuje wyłącznie wydarzenie sklasyfikowane jako `consultation`,
- prowadzący tylko z wiarygodnego pola wydarzenia lub reguły (nie ze zgadywania),
- czas trwania tylko gdy istnieje `scheduled_end_time`,
- brak wydarzenia = puste pola,
- rezerwacja, przypomnienia i rozliczenia działają niezależnie od synchronizacji.

### FR-07 — Klasyfikacja i ręczne przypisanie

Priorytet:

1. ręczne przypisanie konkretnego Discord Event ID (`manual_category`),
2. jawne reguły z `config/discord_event_rules.json` (ID lub wzorzec nazwy/opisu),
3. reguły biznesowe aplikacji (konsultacja, 1:1, live, webinar, warsztat, akademia, analiza, rynek),
4. domyślnie `live`,
5. wydarzenie bez nazwy lub z nieznanym typem → `unassigned`.

Wydarzenia `unassigned` są widoczne na liście administracyjnej; nie są ukrywane.

### FR-08 — Puste pola

Po udanej synchronizacji z zerem wydarzeń:

- karta live jest ukryta; tytuł, prowadzący i godzina pozostają puste,
- karta konsultacji pozostaje pusta,
- brak tekstów „Brak wydarzeń”, „Niedostępne”, „--”, „Wkrótce”,
- brak starych danych demo.

Po błędzie synchronizacji ostatni poprawny zestaw pozostaje w bazie i UI.

### FR-09 — Czas i DST

- źródłowe czasy przechowywane jako UTC + oryginalne ISO 8601,
- konwersja do `Europe/Warsaw` po stronie PHP,
- brak stałego UTC+1/UTC+2,
- poprawne przejścia DST (patrz AC-10).

### FR-10 — Wydarzenia cykliczne

`recurrence_rule` jest przechowywane bez uproszczeń. Aplikacja nie generuje wystąpień, których nie ma w odpowiedzi Discord. Nieobsługiwany wariant nie może psuć harmonogramu.

### FR-11 — Synchronizacja CRON

- skrypt `bin/sync-discord-events.php` uruchamiany co 5 minut,
- blokada równoległych uruchomień,
- transakcja MySQL,
- aktualizacja stanu dopiero po sukcesie,
- bezpieczne logi bez tokenu,
- brak publicznego endpointu uruchamiającego synchronizację.

### FR-12 — Odporność na błędy

Obsłużone: 401, 403, 404, 429 (z `Retry-After`), 5xx, timeout, zły JSON, brak wymaganych pól, błąd MySQL, przerwane połączenie. Przy błędzie: brak usuwania wydarzeń, brak aktualizacji `last_successful_sync`, zapis kodu i czasu błędu.

### FR-13 — Diagnostyka

Panel administratora i `/api/events` pokazują: ostatnią próbę, ostatni sukces, liczbę wydarzeń, liczbę aktualizacji, czas wykonania, status, ostatni błąd i znacznik nieaktualności.

### FR-14 — Bezpieczeństwo

- `DISCORD_BOT_TOKEN` tylko po stronie serwera,
- brak tokenu w przeglądarce, URL, logach i repo,
- `config.json` w ukrytym .app, deny + 600,
- bot bez Privileged Gateway Intents i bez zarządzania wydarzeniami,
- endpointy wydarzeń za istniejącą autoryzacją,
- surowe payloady nie są publiczne.

### FR-15 — Eksport i usunięcie konta

`GET /api/me/export` zwraca JSON; UI potrafi wygenerować archiwum z danymi użytkownika. `DELETE /api/account` usuwa konto i dane kaskadowo.

### FR-16 — Dokumentacja jako część produktu

Każda zmiana funkcjonalna aktualizuje właściwy dokument. Dokumenty wskazują na siebie i są weryfikowane automatycznie (`npm run test:docs`).

### FR-17 — Aplikacja na telefonie (PWA)

Panel jest instalowalny z przeglądarki bez App Store i Google Play (`manifest.webmanifest`, service worker, ikony 192/512/maskable). Po zamknięciu transakcji w panelu użytkownik dostaje powiadomienie z deep linkiem do szybkiego dodania, gdzie w ≤ 10 s zapisuje emocję, ocenę i zdjęcie wykresu. Powłoka aplikacji działa offline; `/api/` i `/auth/` nie są cache'owane. Powiadomienia o transakcjach zamkniętych na giełdzie przy zamkniętej aplikacji wymagają serwerowego workera giełdowego i Web Push — poza zakresem do czasu auto-importu.

### FR-18 — Integracje urządzeń i gotowość

Użytkownik łączy Oura Ring przez OAuth2, a Apple Watch przez jednorazowy kod parowania i ingest z iPhone. Alpha Vault pobiera sen, HRV, tętno spoczynkowe, Body Battery i wynik regeneracji, liczy gotowość oraz pokazuje, jak sen wpływa na wynik dnia. Tokeny są szyfrowane, dane o zdrowiu przetwarzane tylko za zgodą, a odłączenie urządzenia usuwa je z bazy. Check-in przed rynkiem wnosi 30% gotowości. Integracje Garmin i Whoop są wyłączone na razie (kod zachowany, brak wpisu w UI/API).

### FR-19 — Prop challenge

Panel prowadzi wiele challenge'ów prop firm równolegle i pilnuje ich limitów na podstawie dziennika.

- szablony popularnych firm (FTMO, FundedNext, FundingPips, Topstep, Apex, Breakout) oraz własne zasady,
- cel zysku, dzienny limit straty i maksymalne obsunięcie (static/trailing) liczone z zamkniętych transakcji wybranego konta,
- dni do końca i minimalna liczba dni handlowych,
- alerty przy 75/90/100% limitu, banner na pulpicie i powiadomienie (raz dziennie),
- osobne statystyki, krzywa kapitału i status dla każdego challenge'u,
- archiwum, duplikat i usuwanie konfiguracji bez wpływu na transakcje.

### FR-20 — Kalendarz makro z bazy

Kalendarz makro i widget „MAKRO · ten tydzień” korzystają z jednego, wspólnego zestawu publikacji zapisanego w MySQL i udostępnianego przez `GET /api/macro-events`.

- bieżący tydzień z feedu ForexFactory, następny z TradingView,
- tylko publikacje ★★ i ★★★ (Low/Holiday pomijane), czasy w UTC + oryginalne ISO,
- UPSERT bez duplikatów i soft delete po braku w feedzie,
- błąd API nie czyści ostatniego dobrego zestawu; sukces z `[]` czyści widok,
- synchronizacja przez LH Cron co 15 minut i ręcznie `bin/sync-macro-events.php`.

## 7. Wymagania niefunkcjonalne

| Obszar | Wymaganie |
| --- | --- |
| Backend | PHP 8.3, `pdo_mysql`, `curl`, `mbstring`; brak stałego procesu Node na produkcji |
| Hosting | LH.pl shared hosting, MySQL/MariaDB, LH Cron |
| Czas odpowiedzi API | < 300 ms dla `/api/events` przy 3–50 wydarzeniach (bez zimnego startu) |
| Synchronizacja | co 5 minut; tolerancja opóźnienia do 15 minut; `stale=true` po 900 s |
| Idempotencja | ponowny sync nie tworzy duplikatów (`UNIQUE discord_event_id`) |
| Retencja | historia synchronizacji 30 dni; wydarzenia soft-delete bez fizycznego kasowania |
| Dostępność | awaria Discord nie blokuje panelu; UI korzysta z ostatniego dobrego stanu |
| Monitoring | workflow co 15 min sprawdza `/api/health`, flagi konfiguracji i `sync`; błąd = powiadomienie GitHub |
| Bezpieczeństwo | OWASP: sesje, CSRF, CSP, HSTS, rate limit, brak sekretów w kliencie |
| Rozmiar stanu | `MAX_STATE_BYTES` = 12 MiB |
| Przeglądarki | aktualne Chrome/Edge/Firefox/Safari; UI responsywne |

## 8. Kryteria akceptacji

| ID | Kryterium | Weryfikacja |
| --- | --- | --- |
| AC-01 | Discord API zwraca rzeczywiste wydarzenia | live test `discord-sync-live-test.yml` |
| AC-02 | PHP synchronizuje je do MySQL | test DB + `LIVE_SYNC_EVENTS` |
| AC-03 | Synchronizacja działa automatycznie przez LH Cron | wpis CRON + logi |
| AC-04 | Ponowne uruchomienia nie tworzą duplikatów | `LIVE_SYNC_UNIQUE == LIVE_SYNC_EVENTS` |
| AC-05 | Zmiany i usunięcia są obsługiwane | test UPSERT + soft delete |
| AC-06 | Panel pokazuje rzeczywiste wydarzenia | UI E2E |
| AC-07 | Konsultacje i live'y trafiają do właściwych modułów | UI E2E + klasyfikacja |
| AC-08 | Puste dane = puste pola | `ui-empty.e2e.mjs` 12/12 |
| AC-09 | Godziny poprawne w `Europe/Warsaw` | testy DST + UI |
| AC-10 | Przejście czasu letni/zimowy przetestowane | 4 przypadki DST |
| AC-11 | Token bota pozostaje bezpieczny | audyt sekretów i logów |
| AC-12 | Produkcyjne E2E przechodzi | health + events + login + sync na `alphavault.pl` |
| AC-13 | Dokumentacja spójna i aktualna | `npm run test:docs` |
| AC-14 | PWA jest instalowalna, a szybkie dodanie z powiadomienia zajmuje ≤ 10 s | `tests/pwa.e2e.mjs` 20/20 |
| AC-15 | OAuth2 + PKCE Oura kończy się zapisem metryk; Apple Watch ingestion działa; Garmin i Whoop niedostępne w UI/API | `npm run test:wearables` |
| AC-16 | Apple Health przyjmuje dane po kodzie parowania i tokenie urządzenia | `npm run test:wearables` |
| AC-17 | Gotowość łączy urządzenie z check-inem i czyści się po odłączeniu | test API + UI E2E |
| AC-18 | Tokeny dostawców nie są widoczne w API ani logach | test odpowiedzi API + audyt |
| AC-19 | Kalendarz makro i widget korzystają z DB oraz są odporne na błąd feedu | `npm run test:macro` 21/21 + API/UI E2E |

## 9. Metryki sukcesu

- 0 ręcznych wpisów wydarzeń w kodzie.
- 100% wydarzeń z Discorda widocznych w panelu w ciągu 5 minut.
- 0 duplikatów po dowolnej liczbie synchronizacji.
- 0 przypadków wyświetlenia nieaktualnej godziny po zmianie DST.
- 0 wycieków tokenu do frontendu, logów lub repo.
- 100% testów AC zielonych na produkcji.

## 10. Ograniczenia i ryzyka

| Ryzyko | Wpływ | Mitygacja |
| --- | --- | --- |
| Ryzyko blokady billingu GitHub Actions | brak deployu i CI | repo publiczne — standard runners free; monitoruj Billing; alternatywnie `local-deploy.sh` |
| Brak danych Discord w środowisku lokalnym | nie da się testować OAuth lokalnie | sekrety w GitHub / `local.env` poza repo |
| LH Cron zatrzymany | brak automatycznej synchronizacji | monitoring logów i `stale=true` |
| Stage nie wystąpił w realnym API | brak potwierdzenia Stage | fixture + oznaczenie „niezweryfikowany” |
| Zmiana odpowiedzi Discord | utrata pól | walidacja i `raw_payload` |
| Współdzielony hosting | limity procesów/DB | brak długich procesów, sync krótki i blokowany |
| Przypadkowe modyfikacje WordPressa | awaria innych stron | deploy wyłącznie do `alphavault.pl` |

## 11. Status wdrożenia (2026-11-12)

| Element | Status |
| --- | --- |
| Kod PHP 8.3 | gotowy, w `main` |
| Synchronizacja Discord | gotowa, test realny 3/3 VOICE |
| Testy DB/sync | PASS |
| API / UI / empty-state | PASS |
| Prop challenge (frontend) | gotowy, `prop 27/27`, `API 23/23`, `UI 53/53`; deploy po merge |
| Baza `serwer445119_alphavault` | istnieje, tabele utworzone |
| Deploy na `alphavault.pl` | **200** — wdrożony |
| App path | `~/public_html/alphavault.pl/.app` (open_basedir, deny) |
| GitHub Actions | działa (repo publiczne, standard runners free) |
| CI gate / branch protection | `PHP + API + UI` wymagany; PR, linear history, admins enforced |
| Repo / security | publiczne `miloszspyrka-code/av`; secret scanning + push protection + Dependabot |
| Auto-deploy | merge do `main` przy `DEPLOY_ENABLED=true`; env tylko z `main` |
| Prod sync | success, 3 wydarzenia, 3 unikalne ID |
| Prod UI (read-only) | PASS — live, harmonogram, puste konsultacje |
| LH Cron | **działa** — co 5 min, logi 18:05:05 i 18:10:02 |
| Monitoring | `monitor-production.yml` — co 15 min |
| Stage | niezweryfikowany |
| OAuth Discord w przeglądarce | nieprzetestowany interaktywnie |

## 12. Otwarte decyzje

1. Czy `config/discord_event_rules.json` ma być utrzymywany w repo, czy tylko na serwerze.
2. Czy pokazywać w panelu nadchodzące wystąpienia `recurrence_rule`, czy wyłącznie wydarzenia zwrócone przez Discord.
3. Czy dodać osobną kolumnę „kategoria” w admin UI, czy wystarczą przyciski live/konsultacja.
4. Czy historia `discord_sync_runs` ma być widoczna w panelu, czy tylko w bazie.

## 13. Powiązanie wymagań z dokumentacją

| Wymaganie | Dokument techniczny |
| --- | --- |
| FR-01, FR-02 | [DOCUMENTATION.md §4](DOCUMENTATION.md#4-uwierzytelnianie-sesje-i-csrf), [docs/API.md](API.md) |
| FR-03 | [DOCUMENTATION.md §5](DOCUMENTATION.md#5-stan-aplikacji), [docs/BAZA-DANYCH.md](BAZA-DANYCH.md) |
| FR-04 | [docs/FUNKCJE.md](FUNKCJE.md) |
| FR-05, FR-06, FR-07, FR-08 | [DOCUMENTATION.md §7](DOCUMENTATION.md#7-discord-scheduled-events--pełny-przepływ), [docs/DISCORD.md](https://github.com/miloszspyrka-code/alphavault/blob/main/docs/DISCORD.md) |
| FR-09, FR-10 | [DOCUMENTATION.md §8](DOCUMENTATION.md#8-czas-dst-i-recurrence) |
| FR-11, FR-12, FR-13 | [DOCUMENTATION.md §9](DOCUMENTATION.md#9-cron-i-niezawodność), [docs/DEPLOY.md](https://github.com/miloszspyrka-code/alphavault/blob/main/docs/DEPLOY.md) |
| FR-14 | [DOCUMENTATION.md §15](DOCUMENTATION.md#15-bezpieczeństwo) |
| FR-15 | [docs/API.md](API.md) |
| FR-16 | [DOCUMENTATION.md §17](DOCUMENTATION.md#17-zarządzanie-dokumentacją), [AGENTS.md](https://github.com/miloszspyrka-code/alphavault/blob/main/AGENTS.md) |
| FR-19 | [docs/PROP.md](PROP.md), [docs/FUNKCJE.md](FUNKCJE.md) |
