# Changelog

## 3.8.1 — 2026-11-12

### Zmienione
- Urządzenia: Garmin Connect i Whoop wyłączone na razie — brak kafelków w „Ciało”, brak wpisów w `GET /api/wearables`, endpointy OAuth/sync/DELETE zwracają 404. Pozostają Apple Watch i Oura Ring.
- Implementacja i konfiguracja Garmin/Whoop zachowane w kodzie (`app/Wearables.php`, `app/Config.php`, zmienne env) do szybkiego przywrócenia.
- Testy urządzeń przepisane na Oura + Apple z asercją braku Garmin/Whoop w API i UI.
- Dokumentacja: API, architektura, baza, funkcje, testy, PRD.

### Testy
- `npm run test:wearables` — OAuth2/PKCE Oura, Apple, rotacja tokenów i klucza, wyłączenie Garmin/Whoop, UI.
- `tests/wearables-config.test.php` — lista dostawców i wymóg klucza szyfrowania.

## 3.8.0 — 2026-11-12

### Dodane
- Cykliczna weryfikacja roli Alpha: cron co 5 minut (`bin/check-discord-roles.php`) sprawdza aktywne sesje botem (`GET /guilds/{id}/members/{user_id}`) i usuwa wszystkie sesje przy braku roli lub członkostwa (`access.role_revoked`).
- `users.role_checked_at`, `role_check_status`, `role_check_error` oraz tabela `role_check_state` z blokadą cronu i licznikami; stan w `/api/health.roleRecheck`.
- Frontend: pełnoekranowa bramka `#roleGate` przy 401 lub `roleVerified=false`, komunikat o wymaganej roli, przycisk „Sprawdź ponownie” i kontrola dostępu co 5 minut.
- `DISCORD_ROLE_RECHECK_SECONDS` (minimum 300 s) i `DISCORD_API_BASE` (tylko testy/mock).

### Zmienione
- Logowanie: brak roli/członkostwa = 403; chwilowy błąd Discorda (429/5xx) = 503 dla nowych kont, ale zweryfikowane konto dostaje dostęp z grace, żeby kupujący nie tracili panelu.
- `sync-discord-events.sh` uruchamia po synchronizacji recheck roli; bez dodatkowego wpisu w LH Cron.
- Dokumentacja: API, Discord, baza, deploy, funkcje, testy.

### Testy
- `npm run test:roles` — 18 scenariuszy: decyzje login, fail-open, 404, 429 z Retry-After, revoke sesji, audit, blokada cronu.
- UI e2e: bramka roli blokuje panel.

## 3.7.1 — 2026-11-12

### Naprawione
- PIT-38: zdarzenia podatkowe i transakcje są liczone wyłącznie z wybranego roku podatkowego; zmiana roku w stanie nie miesza lat.
- Nadwyżka kosztów z poprzedniego roku jest edytowalna w panelu i zapisywana w stanie; nowe konta startują z 0,00 zł zamiast demo 380,00 zł. Stare stany bez wersji schematu są migrowane przy logowaniu i zapisie.
- „Neutralne podatkowo” liczy transakcje z dziennika (futures w USDT i krypto–krypto), a „Forex i CFD (MT5)” rozpoznaje konto/źródło MT4/MT5 zamiast pola `source`.
- „Do przeniesienia na kolejny rok” liczy się z kosztów, nadwyżki i przychodu roku podatkowego; CSV i wydruk PDF mają rok w nazwie i podsumowaniu, a także liczbę transakcji neutralnych i MT4/MT5.
- Formularz wpłaty/wypłaty wymaga kursu NBP dla waluty obcej i nie wlicza zdarzenia spoza roku podatkowego do zestawienia.

### Dodane
- `npm run test:tax` — 8 scenariuszy: odczyt z MySQL, edycja nadwyżki, CSV, PDF, formularz wpłaty/wypłaty, puste konto i brak błędów JS.


## 3.7.0 - 2026-11-12

### Zmienione
- Oura: OAuth2 Authorization Code + PKCE S256, scope `daily` zamiast nieistniejącego `sleep`, paginacja API v2 i wymagany `User-Agent`.
- WHOOP: API v2 (`/developer/v2/recovery`, `/developer/v2/activity/sleep`), scope `read:recovery read:sleep offline`, obsługa rotacji refresh tokenów.
- Garmin: OAuth2 PKCE S256 z tokenem `https://diauth.garmin.com/di-oauth2-service/oauth/token`, pobieranie User ID i uprawnień, wyrejestrowanie przez `DELETE /wellness-api/rest/user/registration`. Business Starter jest push-only; do czasu adaptera push status to `awaiting_provider_spec`, bez udawania pull.
- Tokeny: wymagany `WEARABLES_ENCRYPTION_KEY` bez fallbacku na `SESSION_SECRET`, rotacja przez `WEARABLES_ENCRYPTION_KEY_PREVIOUS`, `refresh_token_expires_at`, blokada `GET_LOCK` i atomowy zapis nowego refresh tokenu.
- Statusy urządzeń: `not_configured`, `ready_to_connect`, `pending_authorization`, `connected`, `syncing`, `sync_error`, `token_expired`, `revoked`, `awaiting_provider_access`, `awaiting_provider_spec`.
- Odłączenie wycofuje dostęp u dostawcy, a potem usuwa tokeny i metryki.
- Migracja addytywna: `wearable_oauth_states.code_verifier`, `wearable_connections.refresh_token_expires_at`.

### Testy
- `npm run test:wearables`: 42/42 PASS (PKCE, state, rotacja tokenów i klucza, błędy 401/429/JSON, izolacja użytkowników, Garmin push pending, UI).
- `tests/wearables-config.test.php`: 4/4 PASS (brak fallbacku klucza, start OAuth 503).


## 3.6.0 - 2026-11-12

### Dodane
- Kalendarz makro w MySQL: `macro_events` + `macro_sync_state` oraz `GET /api/macro-events`.
- Sync `bin/sync-macro-events.php`: bieżący tydzień ForexFactory, następny tydzień TradingView; UPSERT, soft delete, tylko ★★/★★★.
- Widget „MAKRO · ten tydzień” i strona kalendarza korzystają z tego samego API; usunięto demo z `data.js`.
- Testy: `npm run test:macro` 21/21; API 23/23; UI 52/52.

### Zmienione
- Puste dane makro = puste pola (bez „Brak wydarzeń”, „spokojnie do końca tygodnia”).
- Deploy uruchamia jednorazowy sync makro po migracji; LH Cron co 15 min.

## 3.5.0 - 2026-11-12

### Zmienione
- WEEX: każdy klucz API przechodzi połączenie. Usunięte odrzucanie kluczy z prawem handlu lub wypłat (`read_only_required`) i próba zlecenia na nieistniejącym symbolu; wymagany jest wyłącznie działający odczyt konta. Połączenie zapisuje `permissions: any`.
- Giełdy UI: instrukcja WEEX bez nakazu „tylko odczyt”, nota o warunkach per giełda, wiersz uprawnień pokazuje „dowolne (odczyt wystarcza)”.
- Polityka prywatności i Regulamin: usunięte zdanie o odrzucaniu kluczy z handlem/wypłatami; Regulamin wskazuje WEEX jako akceptujący dowolne uprawnienia, OKX i BingX nadal tylko odczyt.
- Dokumentacja: API, architektura, funkcje, testowanie.

### Testy
- Integracje: WEEX z kluczem `weex-trade` = 201 i `permissions: any` (było 422).
- UI integracje: formularz WEEX przyjmuje klucz z handlem i pokazuje uprawnienia „dowolne (odczyt wystarcza)”.


## 3.4.0 — 2026-11-12

### Dodane
- Integracje giełd: WEEX, OKX i BingX — klucze tylko do odczytu, szyfrowanie AES-256-GCM, walidacja uprawnień i automatyczna synchronizacja transakcji.
- MetaTrader 4/5 przez MetaApi (hasło inwestora, `investorMode`) oraz przez EA z kodem parowania (`integrations/mt5/AlphaVault.mq5`).
- Endpointy `/api/connections*`, `/api/mt/ea`, worker `bin/sync-exchange-trades.php` i tabela deduplikacji `exchange_imports`.
- Testy: integracje 19/19, UI integracje 11/11; dokumentacja API, bazy, deployu i testów.

## 3.3.0 — 2026-11-12

### Dodane
- Prop challenge: 9 szablonów (FTMO 2-Step/1-Step/Swing, FundedNext, FundingPips, Topstep, Apex, Breakout, własne), cel zysku, dzienny limit, obsunięcie static/trailing, dni, krzywa kapitału, alerty 75/90/100%, banner na pulpicie, archiwum i pełna edycja.
- `public/js/prop.js` — czysty, testowalny silnik limitów; `public/js/propui.js` — widok, edytor, szablony i powiadomienia.
- `prop.challenges` + `prop.selectedId` w JSON stanu; demo ma 3 przykładowe challenge'e.
- Testy: `npm run test:prop` (27), API 21/21, UI 51/51; dokumentacja [docs/PROP.md](docs/PROP.md).

## 3.2.0 — 2026-11-12

### Dodane
- Integracje Garmin Connect, Oura Ring i Whoop (OAuth2, szyfrowane tokeny, ręczny i cronowy sync).
- Apple Watch · Zdrowie: kod parowania i ingest z tokenem urządzenia.
- Gotowość łącząca sen, HRV, tętno, Body Battery, regenerację i check-in.
- UI „Ciało”: urządzenia, metryki, wykres sen → wynik dnia, odłączenie z usunięciem danych.
- `npm run test:wearables` — 33 scenariusze API + UI na lokalnym dostawcy OAuth.

### Naprawione
- `Db::migrate()` nie rozcina komentarzy SQL na bajcie `0x85` (litera ą/ń w UTF-8).

## 3.1.2 — 2026-11-12

### Zmienione
- Akademia: usunięty countdown „Start za”; karta live ukrywa się bez wydarzenia.
- „Dołącz” to link do `https://discord.com/events/{guild}/{event}`; usunięty modal z `discord.com/app`.
- Usunięte noty techniczne o kluczu API tylko do odczytu (Giełdy, Konto).

### Dodane
- Pole `url` w `/api/events` — kanoniczny link wydarzenia Discord.
- Testy API/UI/empty: format linku, brak countdownu, ukryta karta live.

## 3.1.1 — 2026-11-12

### Produkcja / CI-CD
- `main` chroniony: wymagany PR i check `PHP + API + UI`, linear history, `enforce_admins`, bez force push/delete; merge commit wyłączony.
- `DEPLOY_ENABLED=true`; merge/push do `main` auto-deployuje. Environment `alphavault-production` dopuszcza tylko `main`.
- Repo publiczne `miloszspyrka-code/av`; secret scanning, push protection i Dependabot security updates.
- LH Cron potwierdzony: `*/5 * * * *`, log `18:10:02`; wrapper `.app/cron/sync-discord-events.sh`.
- Deploy health: `/api/health` wszystkie flagi `true`, `/docs/` 200, `.app` i `config.json` → 403.

## 3.1.0 — 2026-11-12

### Dodane
- `AGENTS.md` — kontrakt agenta AV-engineer.
- `PRD.md` — wymagania produktowe, FR-xx i kryteria akceptacji.
- `DOCUMENTATION.md` — działanie aplikacji i punkty styku P1–P14.
- `scripts/sync-docs.mjs` — generowanie `public/docs` ze źródeł.
- `tests/docs.test.mjs` — test linków, kodowania i spójności dokumentacji.
- `config/discord_event_rules.example.json` — przykład reguł klasyfikacji.
- `monitor-production.yml` — monitoring health i synchronizacji co 15 min; `/api/health` zwraca skrót `sync`.

### Zmienione
- Deploy synchronizuje katalog `config/`, bez nadpisywania aktywnego `discord_event_rules.json`.
- CI uruchamia test dokumentacji przed testami PHP.
- README, API, architektura, baza, funkcje, testy i deploy wskazują na nowe dokumenty.
- Kod i `config.json` przeniesione do `~/public_html/alphavault.pl/.app` z powodu `open_basedir` LH; katalog blokowany przez `.htaccess`.
- `/docs/` kierowane do indeksu dokumentacji (wcześniej Apache zwracał 403).

### Wdrożone
- Produkcja `https://alphavault.pl` → 200, health OK.
- Realny sync Discord → 3 wydarzenia VOICE, bez duplikatów.
- Prod UI read-only: live, harmonogram, puste konsultacje.
- LH Cron działa co 5 min (logi 18:05:05, 18:10:02).

## 3.0.0 — 2026-11-12

### Zmienione
- Backend przepisany z Express 4 / Node.js na PHP 8.3.
- Produkcja na LH.pl: Apache + PHP-FPM, bez procesu Node.
- Schemat MySQL/MariaDB: `utf8mb4_unicode_ci`, tabela `rate_limits`.
- Weryfikacja roli Discord przez OAuth `guilds.members.read` (bez bota i `DISCORD_BOT_TOKEN`).
- Deployment GitHub Actions: SSH/rsync, backup, migracja PHP, health check, rollback.
- `config.json` generowany z sekretów poza routingiem (`.app`, deny).
- CI: PHP lint + MySQL 8.4 + API/UI e2e.

### Zachowane
- Dotychczasowy frontend, widoki, wykresy, modale i funkcje biznesowe.
- Kontrakty API `/api/health`, `/api/me`, `/api/state`, `/auth/discord`, `/auth/discord/callback`.
- Sesje, CSRF, rate limiting, audit log, eksport JSON.

## 2.1.0 — 2026-11-12

- Backend Node.js/Express, MySQL 8.4, Discord OAuth2, demo login, testy API/UI.
- Poprzednia wersja produkcyjna; kod zachowany w `legacy/`.
