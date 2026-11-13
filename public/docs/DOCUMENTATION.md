<!-- Wygenerowane z DOCUMENTATION.md przez scripts/sync-docs.mjs. Nie edytuj ręcznie. -->

# DOCUMENTATION — Alpha Vault

**Jak działa aplikacja, punkt po punkcie.**

| Pole | Wartość |
| --- | --- |
| Wersja | 3.1 |
| Data | 2026-11-12 |
| Backend | PHP 8.3 |
| Baza | MySQL / MariaDB |
| Frontend | HTML/CSS/JS (bez frameworka) |
| Produkcja | <https://alphavault.pl> (LH.pl) |

**Dokumenty powiązane**

- [PRD.md](PRD.md) — wymagania produktowe i kryteria akceptacji (FR-xx, AC-xx).
- [AGENTS.md](https://github.com/miloszspyrka-code/av/blob/main/AGENTS.md) — kontrakt agenta AV-engineer.
- [docs/API.md](API.md) — endpointy i przykłady.
- [docs/ARCHITEKTURA.md](ARCHITEKTURA.md) — warstwy i decyzje.
- [docs/BAZA-DANYCH.md](BAZA-DANYCH.md) — schemat i backup.
- [docs/DISCORD.md](https://github.com/miloszspyrka-code/av/blob/main/docs/DISCORD.md) — OAuth2, bot, rola.
- [docs/DEPLOY.md](https://github.com/miloszspyrka-code/av/blob/main/docs/DEPLOY.md) — wdrożenie.
- [docs/TESTOWANIE.md](TESTOWANIE.md) — testy.
- [docs/FUNKCJE.md](FUNKCJE.md) — stan funkcji.

---

## 1. Jak czytać ten dokument

Dokument opisuje **rzeczywiste przepływy**, nie plan. Każdy rozdział ma tę samą strukturę:

1. **Aktor i trigger** — kto zaczyna.
2. **Żądanie** — metoda, ścieżka, dane.
3. **Walidacja** — co musi być poprawne.
4. **Skutki** — co zmienia się w bazie/UI.
5. **Błąd** — co widzi użytkownik i co zostaje w systemie.
6. **Gdzie sprawdzić** — log, tabela, endpoint.

Jeśli szukasz wymagania produktowego, przejdź do [PRD.md](PRD.md). Jeśli szukasz komend i zasad pracy, przejdź do [AGENTS.md](https://github.com/miloszspyrka-code/av/blob/main/AGENTS.md).

## 2. Mapa systemu i punkty styku

```text
Przeglądarka
  │  HTTPS, cookie av_session
  ▼
Apache + PHP 8.3 (LH.pl)
  ├── public/            document root: index.php, index.html, js/, docs/
  ├── ~/public_html/alphavault.pl/.app/  app/, bin/, db/, config.json, logs/  (ukryty .app, deny)
  └── MySQL/MariaDB      users, sessions, user_states, wydarzenia Discord
        ▲
        │  co 5 minut
  LH Cron → bin/sync-discord-events.php → Discord REST API
  LH Cron → bin/check-discord-roles.php → Discord guild member (recheck roli)
```

### 2.1. Macierz punktów styku

| # | Punkt styku | Inicjator → odbiorca | Protokół | Uwierzytelnienie | Szczegóły |
| --- | --- | --- | --- | --- | --- |
| P1 | UI aplikacji | przeglądarka → Apache/PHP | HTTPS | brak (strona publiczna) | [§3](#3-cykl-życia-żądania-http) |
| P2 | Stan użytkownika | przeglądarka → `/api/state` | JSON/HTTPS | cookie sesji + Origin | [§5](#5-stan-aplikacji) |
| P3 | Wydarzenia | przeglądarka → `/api/events` | JSON/HTTPS | cookie sesji | [§6](#6-api--kontrakt-i-zachowanie) |
| P4 | Start OAuth | przeglądarka → `/auth/discord` → Discord | 302 | cookie `av_oauth` + `state` | [§4](#4-uwierzytelnianie-sesje-i-csrf) |
| P5 | Powrót OAuth | Discord → `/auth/discord/callback` | 302 + code | `state` w DB i cookie | [§4](#4-uwierzytelnianie-sesje-i-csrf) |
| P6 | Profil i rola | PHP → Discord API | HTTPS | Bearer użytkownika | [§4](#4-uwierzytelnianie-sesje-i-csrf) |
| P7 | Dane aplikacji | PHP → MySQL | PDO | dane z `config.json` | [§10](#10-baza-danych-i-transakcje) |
| P8 | Uruchomienie sync | LH Cron → `bin/sync-discord-events.php` | CLI | użytkownik systemowy | [§9](#9-cron-i-niezawodność) |
| P9 | Odczyt wydarzeń | sync → Discord REST | HTTPS | `Bot DISCORD_BOT_TOKEN` | [§7](#7-discord-scheduled-events--pełny-przepływ) |
| P10 | Zapis wydarzeń | sync → MySQL | PDO + transakcja | dane z `config.json` | [§7](#7-discord-scheduled-events--pełny-przepływ) |
| P11 | Administracja | admin → `/api/admin/events*` | JSON/HTTPS | cookie + `ADMIN_DISCORD_IDS` | [§13](#13-administracja-wydarzeniami) |
| P12 | Offline | przeglądarka → `localStorage` | JS | brak | [§11](#11-frontend--od-bootstrapu-do-renderu) |
| P13 | Deploy | GitHub Actions / WSL → LH | SSH + rsync | klucz ED25519 | [§14](#14-deployment) |
| P14 | Migracja i health | deploy → PHP/MySQL/HTTP | CLI + HTTPS | config na serwerze | [§14](#14-deployment) |

## 3. Cykl życia żądania HTTP

**Aktor:** przeglądarka. **Trigger:** wejście na adres.

1. Apache kieruje żądanie do `public/index.php` (front controller).
2. `index.php` ustala katalog aplikacji:
   - `public/app-path.php` (tworzony przy deployu) albo
   - `dirname(__DIR__) . '/app'` lokalnie.
3. Ładowany jest `app/bootstrap.php`:
   - `Config::load()` — `config.json` lub zmienne środowiskowe,
   - `Db` — PDO,
   - `Auth` — sesje i CSRF,
   - `Discord` — OAuth2.
4. `Http::securityHeaders()` ustawia CSP, HSTS, `X-Frame-Options`, `nosniff`.
5. Routing:
   - `/` → `public/index.html`,
   - `/docs` → `/docs/`, `/docs/` → strona indeksu,
   - `/api/*` → `app/routes/api.php`,
   - `/auth/*` → `app/routes/auth.php`,
   - istniejący plik statyczny → `readfile`,
   - inne → 404.
6. Pliki `app-path.php`, `.env`, `config.json` są blokowane przed serwowaniem.

**Błąd bootstrapu:** HTTP 500, `error_log('[bootstrap] ...')`, bez szczegółów konfiguracji w odpowiedzi.

**Gdzie sprawdzić:** `public/index.php`, `app/bootstrap.php`, log Apache/PHP.

## 4. Uwierzytelnianie, sesje i CSRF

### 4.1. Logowanie Discord — krok po kroku

**Aktor:** użytkownik bez sesji. **Trigger:** „Zaloguj przez Discord”.

1. `GET /auth/discord?remember=1`.
2. Rate limit `discord-start`: 30 żądań / 60 s.
3. Serwer czyści wygasłe `oauth_states`.
4. Generuje losowy `state`, zapisuje w `oauth_states` (`state`, `remember`, `redirect_to`, `expires_at` = +10 min).
5. Ustawia cookie `av_oauth` (HttpOnly, krótki czas życia).
6. Przekierowuje do `https://discord.com/api/oauth2/authorize` ze scope'ami:

   ```text
   identify guilds.members.read
   ```

7. Użytkownik akceptuje; Discord wraca na `GET /auth/discord/callback?code=...&state=...`.
8. Rate limit `discord-callback`: 30 / 60 s.
9. Serwer sprawdza `state` w DB i cookie; niezgodność → 400.
10. Wymienia `code` na token (client ID + secret).
11. Pobiera `GET https://discord.com/api/users/@me`.
12. Pobiera członkostwo `GET /guilds/{guild.id}/member` i sprawdza rolę `DISCORD_REQUIRED_ROLE_ID`.
13. `RoleGuard::loginDecision()`: brak roli/członkostwa → 403. Chwilowy błąd Discord (429/5xx) → 503, ale konto już zweryfikowane (`role_verified=1`) dostaje dostęp z grace, żeby kupujący nie tracił panelu podczas awarii Discorda.
14. Tworzy/aktualizuje użytkownika w `users` i zapisuje `role_checked_at`, `role_check_status`, `role_check_error`.
15. Tworzy sesję: losowy token → hash w `sessions`, cookie `av_session`.
16. `remember=1` → cookie 30 dni; `0` → sesja przeglądarki.

**Błąd:** brak konfiguracji OAuth → 503; brak roli/członkostwa → odmowa i brak sesji; błąd Discord → komunikat, bez ujawniania szczegółów.

**Powiązanie:** FR-01, FR-02; [docs/DISCORD.md](https://github.com/miloszspyrka-code/av/blob/main/docs/DISCORD.md), [docs/API.md](API.md).

### 4.2. Sesja

- Cookie: `av_session`, HttpOnly, SameSite=Lax, Secure przy HTTPS.
- W bazie tylko hash tokena.
- `Auth::readSession()` dołącza użytkownika i sprawdza wygaśnięcie.
- `requireAuth()` zwraca 401 dla `/api/state`, `/api/events`, `/api/account`, `/api/me/export`.
- Wylogowanie usuwa sesję z DB i czyści cookie.

### 4.2a. Cykliczna weryfikacja roli (co 5 min)

- `cron/sync-discord-events.sh` uruchamia po synchronizacji `bin/check-discord-roles.php` (LH Cron co 5 min).
- `RoleGuard::reapDue()` bierze aktywne sesje, których `role_checked_at` jest starsze niż próg (domyślnie 300 s, minimum 300 s) i sprawdza członka bota: `GET /guilds/{guild.id}/members/{user.id}`.
- Rola obecna → `role_verified=1`, `role_check_status=ok`.
- Brak roli lub brak członkostwa → `role_verified=0`, `role_check_status=missing`, usunięcie wszystkich sesji użytkownika i wpis `access.role_revoked` w `audit_log`.
- Błąd Discorda (429 po 3 próbach, 5xx, timeout) → `role_check_status=error`, dostęp pozostaje (fail open), żeby awaria Discorda nie odcięła kupujących.
- Blokada `role_check_state.lock_token` chroni przed nakładaniem się cronów; stan i liczniki są widoczne w `/api/health.roleRecheck`.
- Frontend co 5 min wywołuje `GET /api/me`; 401 lub `roleVerified=false` pokazuje pełnoekranową bramkę `#roleGate` bez możliwości wejścia do panelu.

### 4.3. CSRF

- Każde mutujące żądanie (`PUT`, `POST`, `DELETE`) sprawdza `Origin` (`requireSameOrigin`).
- Niezgodny lub brakujący `Origin` → 403.
- Cookie `SameSite=Lax` jest drugą linią obrony.

### 4.4. Rate limiting

| Bucket | Limit | Okno |
| --- | --- | --- |
| `discord-start` | 30 | 60 s |
| `discord-callback` | 30 | 60 s |
| `demo-login` | 20 | 60 s |
| zapis stanu | limit w `Auth` | 60 s |

Dane w `rate_limits` (bucket, IP, window_start, count). Przekroczenie → 429.

### 4.5. Demo login

- `POST /auth/demo` tylko gdy `ALLOW_DEMO_LOGIN=true`.
- Każda przeglądarka dostaje osobne konto `demo-<24 hex>`; identyfikatorem jest cookie `av_demo` (HttpOnly, 30 dni). Stan z `db/demo-state.json`.
- Daty stanu są zakotwiczone w fixture; przy pierwszym odczycie i cyklicznie (wrapper Cron, co 60 min) `DemoState` przesuwa je na dziś (`Europe/Warsaw`), więc demo pokazuje dzisiejsze transakcje, plan dnia i tryb ochronny.
- Na produkcji `ALLOW_DEMO_LOGIN=false`; health check wymaga `demoAllowed=false`.
- Demo nie jest produkcyjną metodą logowania.

## 5. Stan aplikacji

**Aktor:** zalogowany użytkownik. **Trigger:** zmiana w UI.

1. Frontend trzyma cały stan w `AV.state`.
2. `AV.schedulePush()` zbiera zmiany i wysyła `PUT /api/state` z `{ state, baseRevision, ownerId }`.
3. Serwer sprawdza sesję, `Origin`, rozmiar (`MAX_STATE_BYTES`, domyślnie 12 MiB), typ JSON oraz `ownerId`; stan z karty innego konta → 409 `session_changed`.
4. `UPDATE user_states ... WHERE user_id = ? AND revision = ?`.
5. Sukces → `revision + 1`, odpowiedź z nową rewizją i `userId`.
6. Konflikt → 409; frontend pobiera świeży stan tylko wtedy, gdy `userId` zgadza się z sesją.
7. `GET /api/state` zwraca `{ state, revision, updatedAt, userId }`. Historyczny mojibake (UTF-8 odczytany jako Windows-1250) jest naprawiany przed odpowiedzią i zapisywany z powrotem do `user_states` bez zmiany rewizji; zdarzenie trafia do `audit_log` jako `state.repair_encoding`.
8. `DELETE /api/state` czyści stan (reset).
9. `localStorage` jest fallbackiem offline i jest namespace'owany po `user.id`; po wylogowaniu cache użytkownika jest usuwany.

**Błąd:** brak sesji → 401; zły JSON → 400; za duży stan → 413; konflikt → 409.

**Gdzie sprawdzić:** `user_states`, `audit_log`, `public/js/state.js`.

## 6. API — kontrakt i zachowanie

Pełny kontrakt: [docs/API.md](API.md). Specyfikacja: [docs/openapi.yaml](openapi.yaml).

| Metoda | Ścieżka | Auth | Rola |
| --- | --- | --- | --- |
| GET | `/api/health` | nie | health, DB, konfiguracja |
| GET | `/api/me` | nie | tożsamość, flagi konfiguracji, `isAdmin` |
| GET | `/api/state` | tak | stan + rewizja |
| PUT | `/api/state` | tak | zapis optymistyczny |
| DELETE | `/api/state` | tak | reset stanu |
| DELETE | `/api/account` | tak | usunięcie konta |
| GET | `/api/me/export` | tak | eksport JSON |
| GET | `/api/quotes` | nie | notowania rynkowe z cache i fallbackiem |
| GET | `/api/events` | tak | wydarzenia, live, konsultacje, sync |
| GET | `/api/macro-events` | tak | publikacje makro + sync |
| GET | `/api/admin/events` | admin | wszystkie wydarzenia + sync |
| PUT | `/api/admin/events/{id}/category` | admin | ręczne przypisanie |
| GET | `/api/wearables` | tak | urządzenia, metryki, baseline, gotowość |
| POST | `/api/wearables/{provider}/sync` | tak | ręczna synchronizacja |
| DELETE | `/api/wearables/{provider}` | tak | odłączenie + usunięcie danych |
| POST | `/api/wearables/apple/pair` | tak | kod parowania iPhone |
| POST | `/api/wearables/apple/ingest` | kod/token | ingest danych Apple Health |
| GET | `/auth/wearables/{provider}/start` | tak | start OAuth2 |
| GET | `/auth/wearables/{provider}/callback` | tak | callback OAuth2 |

**Zasady:**

- API jest tego samego originu co UI; brak CORS.
- Odpowiedzi są JSON z `Content-Type: application/json; charset=utf-8`.
- Błędy mają `{ error, message }`.
- `/api/events` nie zwraca `raw_payload`.
- `isAdmin` wynika wyłącznie z `ADMIN_DISCORD_IDS`.
- `/api/wearables` nigdy nie zwraca tokenów dostawców ani `raw_payload`.

### 6.1. Urządzenia zdrowotne

Oura używa OAuth2 z jednorazowym `state` i PKCE S256 (`code_verifier` w `wearable_oauth_states`). Tokeny są szyfrowane AES-256-GCM wyłącznie kluczem `WEARABLES_ENCRYPTION_KEY` (brak fallbacku na `SESSION_SECRET`), a `WEARABLES_ENCRYPTION_KEY_PREVIOUS` obsługuje rotację. Odświeżanie respektuje `refresh_token_expires_at` i jest serializowane blokadą `GET_LOCK` na użytkowniku i dostawcy; nowy refresh token jest zapisywany atomowo. Apple Watch używa kodu parowania i tokenu urządzenia. Połączenie zapisuje metryki w `wearable_daily_metrics`, a `GET /api/wearables` liczy gotowość z dostępnych składników (sen 40%, HRV 25%, tętno 10%, regeneracja 15%, Body Battery 10%) i łączy ją z check-inem w proporcji 70/30. Odłączenie najpierw próbuje wycofać dostęp u dostawcy, a potem usuwa tokeny i metryki w transakcji. Garmin i Whoop są wyłączone na razie: `Wearables::PROVIDERS` zawiera tylko `apple` i `oura`, więc ich endpointy zwracają 404, a kafelki nie renderują się.

### 6.2. MCP 2.0 — panel i propozycje

Panel MCP jest dostępny w widoku `#/mentor-ai`. Endpointy panelu:

- `GET /api/mcp` — status, scope, oczekujące propozycje, audyt; wymaga sesji.
- `POST /api/mcp/proposals/decide` — `{id, action}`; sesja, `requireSameOrigin`, rate limit 60/min.

MCP (`mcp.alphavault.pl`) ma własny OAuth 2.1 i nie zapisuje stanu bezpośrednio. Narzędzia
`propose_*` tworzą wiersz `mcp_proposals`; zatwierdzenie w panelu wykonuje transakcję MySQL
z `SELECT ... FOR UPDATE`, sprawdza `base_revision`, inkrementuje `revision` i zapisuje audyt.
Brak danych = puste tablice. Dane giełdowe są niezmienne. Szczegóły: [docs/MCP.md](https://github.com/miloszspyrka-code/av/blob/main/docs/MCP.md).

## 7. Discord Scheduled Events — pełny przepływ

### 7.1. Cel

Discord jest źródłem prawdy. MySQL jest lokalną, odpytywalną kopią. Panel nie pyta Discorda przy każdym otwarciu.

### 7.2. Wywołanie API

```http
GET https://discord.com/api/v10/guilds/{guild.id}/scheduled-events?with_user_count=true
Authorization: Bot <DISCORD_BOT_TOKEN>
Accept: application/json
User-Agent: AlphaVault/3.0 (https://alphavault.pl)
```

Timeouty: connect 10 s, całość 25 s. Maksymalnie 3 próby. `429` → `Retry-After` (1–30 s). `5xx` → backoff wykładniczy. `401/403/404` → natychmiastowy błąd bez retry.

### 7.3. Walidacja odpowiedzi

- HTTP 200,
- poprawny JSON,
- tablica (lista) wydarzeń,
- każde wydarzenie ma numeryczne `id`,
- `scheduled_start_time` jest poprawną datą ISO 8601,
- `scheduled_end_time`, jeśli istnieje, jest poprawną datą,
- `status` ∈ `{1,2,3,4}`,
- `entity_type` ∈ `{1,2,3}` (inny typ nie wywala całej synchronizacji, ale trafia do `unassigned`).

Błąd walidacji **całej odpowiedzi** przerywa sync przed transakcją — baza pozostaje nietknięta.

### 7.4. Normalizacja pól

| Pole Discord | Kolumna MySQL | Uwagi |
| --- | --- | --- |
| `id` | `discord_event_id` | unikalny, `VARCHAR(32)` |
| `guild_id` | `guild_id` | |
| `name` | `name` | |
| `description` | `description` | może być `NULL` |
| `entity_type` | `event_type` | 1/2/3 |
| `status` | `status` | 1–4 |
| `channel_id` | `channel_id` | może być `NULL` |
| `scheduled_start_time` | `scheduled_start_time` + `scheduled_start_iso` | UTC + oryginał ISO |
| `scheduled_end_time` | `scheduled_end_time` + `scheduled_end_iso` | może być `NULL` |
| `creator` | `creator_id`, `creator_json` | może być `NULL` |
| `user_count` | `user_count` | może być `NULL` |
| `entity_metadata.location` | `location` | tylko `EXTERNAL` |
| `entity_id` | `entity_id` | |
| `entity_metadata` | `entity_metadata` | JSON |
| `image` | `image` | |
| `recurrence_rule` | `recurrence_rule` | JSON, bez uproszczeń |
| całość | `raw_payload` | JSON, tylko do diagnostyki |

Dodatkowo wyliczane:

- `duration_minutes` — tylko gdy istnieje `scheduled_end_time`,
- `host_name` — z nazwy `X - Host - Y` lub z `creator`, jeśli nie jest botem,
- `category` i `category_source` — patrz §7.6.

### 7.5. Zapis — krok po kroku

**Aktor:** LH Cron. **Trigger:** harmonogram `*/5 * * * *`.

1. `php ~/public_html/alphavault.pl/.app/bin/sync-discord-events.php`.
2. `bootstrap.php` ładuje `config.json` (ukryty .app, deny).
3. **Blokada:** `UPDATE discord_sync_state SET lock_token=?, locked_until=UTC_TIMESTAMP()+INTERVAL 10 MINUTE WHERE id=1 AND (locked_until IS NULL OR locked_until < UTC_TIMESTAMP())`.
4. Brak wiersza → inny sync trwa; skrypt kończy się kodem 0 i loguje `skipped`.
5. `last_attempt_at = UTC_TIMESTAMP()`, `last_status='running'`.
6. Pobranie i walidacja odpowiedzi (§7.2–7.3).
7. Normalizacja wszystkich wydarzeń do tablicy.
8. Odczyt `manual_category` dla istniejących ID.
9. **Transakcja:**
   - `INSERT ... ON DUPLICATE KEY UPDATE` dla każdego wydarzenia,
   - `category = COALESCE(manual_category, VALUES(category))`,
   - `is_deleted=0`, `deleted_at=NULL`, `last_seen_at=now`, `last_synced_at=now`,
   - wydarzenia nieobecne w odpowiedzi → `is_deleted=1`, `deleted_at=now`,
   - `discord_sync_state`: `last_success_at`, `events_count`, `updated_count`, `duration_ms`, `last_http_status=200`, czyszczenie błędu.
10. `discord_sync_runs` — wpis `success`.
11. Log `sync success: events=N updated=N duration=Xms`.
12. Zwolnienie blokady w `finally`.

**Puste wydarzenia:** poprawna odpowiedź `[]` jest sukcesem. Wszystkie dotychczasowe wydarzenia dostają `is_deleted=1`; UI pokazuje puste pola. To nie jest błąd.

**Błąd:** żadne wydarzenie nie jest usuwane; `last_success_at` pozostaje bez zmian; `last_status='error'`, `last_error_code`, `last_error_message`, `last_http_status`; wpis `error` w `discord_sync_runs`; skrypt kończy się kodem 1.

### 7.6. Klasyfikacja

```text
manual_category ustawione?           → ta kategoria (source=manual)
reguła event_categories[id]?         → ta kategoria (source=rule)
wzorzec name_patterns?               → ta kategoria (source=rule)
"konsultacj" / "1:1" w tekście?      → consultation (source=rule)
"live" / "webinar" / "warsztat" /
"akademi" / "analiza" / "rynek"?     → live (source=rule)
brak nazwy lub nieznany typ?         → unassigned (source=none)
w pozostałych przypadkach             → live (source=default)
```

Reguły z pliku `config/discord_event_rules.json`:

```json
{
  "event_categories": {
    "1570153900022173746": "consultation"
  },
  "name_patterns": [
    { "pattern": "/\\bkonsultacj/i", "category": "consultation" },
    { "pattern": "/\\bQ&A\\b/i", "category": "live" }
  ]
}
```

Plik jest opcjonalny. Reguły biznesowe w kodzie działają także bez niego.

### 7.7. Odczyt przez panel

1. Po zalogowaniu frontend wywołuje `GET /api/events`.
2. `DiscordEvents::listForApi()` pobiera rekordy `is_deleted=0`, sortuje po `scheduled_start_time ASC`.
3. Buduje:
   - `events` — wszystkie,
   - `live` — kategoria `live`, status ≠ COMPLETED/CANCELED,
   - `consultations` — kategoria `consultation`, status ≠ COMPLETED/CANCELED,
   - `unassigned` — kategoria `unassigned`,
   - `nextConsultation` — pierwsza przyszła konsultacja (z tolerancją −1 h),
   - `sync` — stan synchronizacji.
4. Każde wydarzenie ma `startAt` (UTC ISO), `startLocal` (z offsetem), `startLabel`, `localDate`, `localTime`, `weekday`, `endAt`, `durationMinutes`, `host`, `userCount`, `category`, `categorySource`, `recurrenceRule`.

**Wydajność:** panel nie odpytuje Discorda. Jedno zapytanie do MySQL na otwarcie i co 5 minut w tle.

## 8. Czas, DST i recurrence

### 8.1. Potok czasu

```text
Discord ISO 8601 (np. 2026-11-12T19:00:00+00:00)
  → DateTimeImmutable (UTC)
  → scheduled_start_time DATETIME (UTC)
  → scheduled_start_iso VARCHAR (oryginał)
  → DateTimeZone('Europe/Warsaw')
  → startLocal (ISO z offsetem), startLabel, localDate, localTime, weekday
```

Nigdy nie zapisujemy samego lokalnego tekstu jako źródła. Frontend nie przelicza stref poza `Intl` dla nagłówków pomocniczych.

### 8.2. Wymagane przypadki DST

| Wejście UTC | Oczekiwana godzina PL | Offset |
| --- | --- | --- |
| `2026-10-25T00:30:00Z` | `02:30` | `+02:00` |
| `2026-10-25T01:30:00Z` | `02:30` | `+01:00` |
| `2027-03-28T00:30:00Z` | `01:30` | `+01:00` |
| `2027-03-28T01:30:00Z` | `03:30` | `+02:00` |

Testy: `tests/discord-events.test.php`. Sortowanie odbywa się po UTC, nie po tekście godziny lokalnej.

### 8.3. Recurrence

- `recurrence_rule` jest przechowywane jako JSON.
- Aplikacja **nie generuje** wystąpień, których Discord nie zwrócił.
- Brak obsługi wariantu nie może psuć harmonogramu; wydarzenie nadrzędne nadal jest widoczne.
- Przyszłe rozszerzenie: pokazywanie kolejnych wystąpień na podstawie oficjalnego zestawu reguł, bez fikcyjnych danych.

## 9. Cron i niezawodność

### 9.1. Wpis w LH

Panel LH.pl → Serwery → `serwer445119` → Cron / Harmonogram zadań:

```text
*/5 * * * * /bin/bash /public_html/alphavault.pl/.app/cron/sync-discord-events.sh
```

To jedyny wpis w LH Cron. Wrapper uruchamia kolejno `bin/sync-discord-events.php` (Discord events + worker integracji `syncDue`, realny import max co 25 min), `bin/check-discord-roles.php` (recheck roli co 5 min) oraz z throttle `bin/sync-macro-events.php` (co 15 min), `bin/sync-wearables.php` (co 30 min) i `bin/sync-demo-state.php` (co 60 min). Znaczniki czasu: `.app/.runtime/*.stamp`.

Ręcznie (bez panelu):

```bash
/usr/bin/php /home/platne/serwer445119/public_html/alphavault.pl/.app/bin/sync-discord-events.php
/usr/bin/php /home/platne/serwer445119/public_html/alphavault.pl/.app/bin/check-discord-roles.php
/usr/bin/php /home/platne/serwer445119/public_html/alphavault.pl/.app/bin/sync-macro-events.php
/usr/bin/php /home/platne/serwer445119/public_html/alphavault.pl/.app/bin/sync-demo-state.php
```

Wymagania: PHP 8.3 CLI (na LH `/usr/bin/php`), `config.json` w `~/public_html/alphavault.pl/.app`, katalog `logs/` z prawami 700.

**Stan 2026-11-12:** jedyny wpis Cron jest aktywny; `/api/health` potwierdza świeże (<10 min) `sync.lastSuccessAt` i `roleRecheck.lastSuccessAt` oraz `stale=false`. Na serwerze znajduje się wrapper `~/public_html/alphavault.pl/.app/cron/sync-discord-events.sh`, który od wersji 3.9.1 uruchamia też makro (co 15 min) i urządzenia (co 30 min), a od 3.9.4 synchronizację dat demo (co 60 min).

### 9.2. Blokada

- Jedna kolumna `lock_token` i `locked_until` w `discord_sync_state`.
- Blokada wygasa po 10 minutach (awaria procesu nie blokuje na zawsze).
- Recheck roli ma osobną blokadę w `role_check_state` (4 min) i limit czasu 45 s na przebieg.
- Równoległe uruchomienie kończy się `skipped` i kodem 0.

### 9.3. Obsługa błędów

| Sytuacja | Zachowanie |
| --- | --- |
| 401 | błąd, brak retry, `last_error_code=http_401`, dane nietknięte |
| 403 | błąd, brak retry, dane nietknięte |
| 404 | błąd (zły guild ID), dane nietknięte |
| 429 | retry po `Retry-After`, maks. 3 próby |
| 5xx | retry z backoffem, maks. 3 próby |
| timeout | retry, potem błąd `timeout` |
| zły JSON | błąd `invalid_json`, dane nietknięte |
| brak pól | błąd `invalid_payload`, dane nietknięte |
| błąd MySQL | transakcja rollback, dane nietknięte, błąd w logu |
| pusty wynik | sukces, soft delete wszystkich |

### 9.4. Logi

- `~/public_html/alphavault.pl/.app/logs/discord-sync.log` (700, ukryty .app),
- `error_log('[discord-sync] ...')`,
- token jest redagowany (`[redacted]`) przed zapisem,
- historia w `discord_sync_runs` (30 dni).

### 9.5. Monitoring

- `/api/events` → `sync.stale=true` po 900 s od sukcesu,
- `sync.status='error'` → UI pokazuje „błąd synchronizacji”,
- `/api/health` → publiczny skrót `sync` (`status`, `lastSuccessAt`, `eventsCount`, `stale`),
- `.github/workflows/monitor-production.yml` → co 15 min sprawdza health, flagi konfiguracji i `sync.status=success` oraz `sync.stale=false`,
- `workflow_dispatch`: `gh workflow run monitor-production.yml`,
- błąd workflow = powiadomienie GitHub do obserwujących repo,
- health check deployu nadal sprawdza tylko konfigurację, nie wiek sync — deploy nie może zależeć od harmonogramu Cron.

Monitoring nie zapisuje tokenów ani treści wydarzeń. `/api/health` udostępnia wyłącznie metadane operacyjne.

### 9.6. Kalendarz makro

- `bin/sync-macro-events.php` pobiera bieżący tydzień z `ff_calendar_thisweek.json` (ForexFactory) i następny tydzień z `economic-calendar.tradingview.com` (TradingView).
- Zapisuje tylko publikacje ★★ i ★★★; Low/Holiday oraz szum (aukcje, wystąpienia) są pomijane.
- UPSERT po `external_id`, soft delete po udanym pobraniu danego feedu.
- Błąd ForexFactory → `status=error`, dane nietknięte. Błąd TradingView → `status=partial`, bieżący tydzień nadal się zapisuje.
- `stale=true` po 1800 s od ostatniego sukcesu.
- Log: `~/public_html/alphavault.pl/.app/logs/macro-sync.log`.

## 10. Baza danych i transakcje

### 10.1. Tabele

| Tabela | Rola | Klucz |
| --- | --- | --- |
| `users` | konto Discord/demo, plan, rola, `role_checked_at`, `role_check_status`, `role_check_error` | `id`, `discord_id` |
| `sessions` | hash sesji, IP, UA, wygaśnięcie | `token_hash` |
| `oauth_states` | jednorazowe `state` OAuth | `state` |
| `user_states` | JSON stanu + rewizja | `user_id` |
| `audit_log` | logowania, zapisy, usunięcia, odebranie roli | `id` |
| `role_check_state` | stan i blokada cronu weryfikacji roli | `id=1` |
| `rate_limits` | limity | `(bucket, ip)` |
| `discord_scheduled_events` | wydarzenia Discord | `discord_event_id` (UNIQUE) |
| `discord_sync_state` | stan i blokada sync | `id=1` |
| `discord_sync_runs` | historia sync (30 dni) | `id` |
| `macro_events` | publikacje makro (FF/TradingView, UPSERT, soft delete) | `external_id` (UNIQUE) |
| `macro_sync_state` | stan synchronizacji makro | `id=1` |
| `wearable_connections` | połączenie z urządzeniem, zaszyfrowane tokeny | `(user_id, provider)` |
| `wearable_daily_metrics` | dzienne metryki zdrowotne | `(user_id, provider, metric_date)` |
| `wearable_oauth_states` | jednorazowe stany OAuth2 urządzeń | `state` |
| `wearable_pair_codes` | jednorazowe kody Apple | `code_hash` |
| `mcp_clients` | klienci OAuth 2.1 (DCR) | `client_id` |
| `mcp_auth_codes` | kody autoryzacyjne PKCE | `id`, `user_id` |
| `mcp_access_tokens` | access tokeny JWT | `id`, `user_id`, `expires_at` |
| `mcp_refresh_tokens` | refresh tokeny i rotacja | `id`, `user_id` |
| `mcp_login_requests` | logowanie Discord na domenie MCP | `id`, `discord_state` |
| `mcp_consents` | ekran zgody OAuth | `id`, `user_id` |
| `mcp_proposals` | propozycje agenta do zatwierdzenia | `id`, `(user_id, idempotency_key)` |
| `mcp_audit` | audyt agenta i użytkownika | `id`, `proposal_id` |
| `mcp_idempotency` | rezerwa pod operacje bezpośrednie | `(user_id, idem_key)` |
| `mcp_audit` | audyt MCP | `id` |
| `schema_migrations` | wersje migracji | `version` |

### 10.2. Indeksy wydarzeń

- `UNIQUE uq_discord_event_id (discord_event_id)`,
- `idx_discord_guild (guild_id)`,
- `idx_discord_status (status)`,
- `idx_discord_type (event_type)`,
- `idx_discord_start (scheduled_start_time)`,
- `idx_discord_category (category)`,
- `idx_discord_deleted (is_deleted)`.

### 10.2a. Indeksy makro

- `UNIQUE uq_macro_external (external_id)`,
- `idx_macro_start (starts_at_utc)`,
- `idx_macro_impact (impact)`,
- `idx_macro_source (source)`,
- `idx_macro_deleted (is_deleted)`.

### 10.3. Soft delete

- Brak fizycznego `DELETE` przy synchronizacji.
- `is_deleted=1`, `deleted_at` ustawiane tylko po pełnej, poprawnej odpowiedzi HTTP 200.
- Wydarzenie, które wróci, jest przywracane (`is_deleted=0`, `deleted_at=NULL`).
- Historia i `raw_payload` pozostają do diagnostyki.

### 10.4. Transakcja

`Db::withTransaction()` obejmuje upsert, soft delete i update stanu. Błąd w dowolnym miejscu → rollback. `discord_sync_runs` jest zapisywane poza transakcją, żeby błąd historii nie wywrócił synchronizacji.

## 11. Frontend — od bootstrapu do renderu

### 11.1. Ładowanie

```text
index.html
  → core.js       (AV, $, $$, esc, fmt)
  → pwa.js        (instalacja PWA, powiadomienia, deep link)
  → data.js       (dane demo/statyczne)
  → prop.js       (silnik prop challenge: szablony, limity, metryki)
  → state.js      (stan, API, rewizje, wydarzenia)
  → render1.js    (widoki, pulpit, dziennik)
  → render2.js    (statystyki, mentor, akademia, admin)
  → propui.js     (widok prop, edytor, szablony, alerty)
  → actions.js    (zdarzenia, timery, modale)
```

Skrypty mają `defer`; kolejność jest istotna.

### 11.1a. Routing i adresy podstron

Routing jest po stronie przeglądarki: jeden `public/index.html`, widoki przełączane bez przeładowania, adres w `location.hash`.

- `AV.showView(id)` — ustawia aktywny widok, renderuje go i zapisuje adres przez `history.replaceState` (bez wpisu w historii).
- `AV.viewFromHash()` — tłumaczy hash na id widoku; nieznany adres sprowadza do `pulpit`.
- `AV.viewSlugs` — mapa `id widoku → slug`; `AV.slugViews` — mapa odwrotna razem z aliasami starych, krótkich adresów.

| Widok (`id`) | Adres | Nawigacja |
| --- | --- | --- |
| `pulpit` | `#/pulpit` | Pulpit |
| `plan` | `#/plan` | Plan dnia |
| `trans` | `#/dziennik` | Dziennik |
| `play` | `#/playbook` | Playbook |
| `nowa` | `#/nowa-transakcja` | Nowa transakcja |
| `kalk` | `#/kalkulator` | Kalkulator Alpha |
| `prop` | `#/prop-challenge` | Prop challenge |
| `akad` | `#/akademia` | Akademia Alpha |
| `stat` | `#/statystyki` | Statystyki |
| `tax` | `#/podatki` | Podatki PIT-38 |
| `mentor` | `#/mentor-ai` | Mentor AI |
| `kal` | `#/kalendarz` | Kalendarz makro |
| `cialo` | `#/cialo-i-glowa` | Ciało i głowa |
| `gieldy` | `#/gieldy` | Giełdy i API |
| `ust` | `#/konto` | Konto i zasady |

Widok `#/mentor-ai` to strona integracji MCP: adres `https://mcp.alphavault.pl/mcp` z kopiowaniem, przepływ „jak to działa”, zakładki ChatGPT/Claude/Grok z krokami konfiguracji, karty korzyści i bezpieczeństwa oraz nota o dostępności integracji. Claude korzysta z callbacku `https://claude.ai/api/mcp/auth_callback`, który musi być dopuszczony w allowliście OAuth. Karta wskazuje tryb tylko do odczytu i linkuje do polityki prywatności §13.2. Połączenie wymaga ekranu zgody na domenie MCP; token unieważnia się w `/oauth/connected`. Pill statusu czyta `/api/mcp` i pokazuje `MCP · TYLKO ODCZYT`, `MCP · ZAPIS` albo `MCP · WYŁĄCZONY`; nie jest statyczny. Widok nie zawiera czatu ani analiz.

Plan dnia (`#/plan`) trzyma dane w `user_states.state.plans[YYYY-MM-DD]`. Kliknięcie rynku lub biasu, dodanie/usunięcie poziomu, zatwierdzenie i "Skopiuj z wczoraj" zapisują szkic i wysyłają `PUT /api/state`; zatwierdzenie czeka na odpowiedź serwera i pokazuje wynik ("Zapisano w bazie" albo błąd). Zatwierdzenie wymaga rynku, kierunku, uzasadnienia i co najmniej jednego poziomu. Tekst planu ma autosave z debounce 700 ms. `renderPlan()` nie nadpisuje formularza, gdy fokus jest wewnątrz edytora, więc render po notowaniach nie kasuje wpisywanej treści.

Dziennik (`#/nowa-transakcja`) startuje z pustymi polami i checklistą 0/N. Ryzyko pozycji jest liczone jako `abs(entry - SL) * size` i pokazywane obok limitu `maxRisk`; przekroczenie limitu wymaga świadomego potwierdzenia. Wpisy ręczne mają `mae = null`, `mfe = null` — statystyki MAE/MFE korzystają wyłącznie z importów. Przy zerze transakcji statystyki pokazują „—”, a etykiety checklisty wynikają z liczby punktów użytkownika.

Zgodność z planem jest liczona po dacie transakcji, parze i kierunku (`AV.planMatchesTrade`), a nie po samym setupie. Badge „Plan dnia ✓” pojawia się tylko dla zatwierdzonego planu, a badge „LIVE” tylko wtedy, gdy live trwa. `buildEmpty()` nie dziedziczy demo-seedu (data dołączenia, postęp kursu, sesje, streak, połączenia); `cleanLegacyDemoSeed()` czyści te wartości w istniejących stanach. Tożsamość w panelu pochodzi z `/api/me` (`username`, `globalName`, `joinedAt`), bez zastępczego handle.

Zasady:

- Slugi są ASCII, małymi literami, bez diakrytyków; myślnik zamiast spacji.
- Stare adresy (`#/akad`, `#/trans`, `#/ust`, …) oraz warianty `*3` (`#/plan3`, `#/dziennik3`, `#/playbook3`, `#/akad3`) nadal działają jako aliasy i są przepisywane na czytelny slug przy pierwszym `showView`.
- Hash jest porównywany bez wielkości liter i z pominięciem końcowego `/`.
- Id widoków pozostają wewnętrznym kontraktem (`data-v`, `data-go`, `#id` sekcji, `AV.showView('akad')`) — nie zmieniaj ich przy zmianie adresu; zmieniaj tylko `AV.viewSlugs`.

### 11.2. Bootstrap

1. `AV.bootstrap()` → `GET /api/me`.
2. Brak sesji → ekran logowania.
3. Sesja, ale `roleVerified=false` lub 401 → pełnoekranowa bramka `#roleGate` (bez wejścia do panelu).
4. Sesja → `GET /api/state`; brak stanu → `buildEmpty()` lub `buildDemo()` dla demo.
5. `AV.applyServerUser()`.
6. `AV.loadEvents()` → `GET /api/events`.
7. `AV.loadMacroEvents()` → `GET /api/macro-events` (błąd nie czyści ostatniego dobrego zestawu).
8. `AV.loadWearables()` → `GET /api/wearables` (błąd nie blokuje panelu).
9. `AV.renderAll()` → `renderAcademy()`, `renderBody()`.
10. Co 5 min `AV.checkAccess()` → `GET /api/me`; 401/brak roli zamyka panel bramką.

### 11.3. Render Akademii

- `AV.nextLiveEvent()` — aktywne lub najbliższe przyszłe wydarzenie `live`.
- `AV.nextConsultationEvent()` — z `nextConsultation`.
- `AV.liveInfo()` - `liveNow` i pozostały czas (bez countdownu w UI).
- `renderAcademy()` ustawia:
  - `#liveTitle`, `#liveWho`, `#liveWhen`, `#liveCd`,
  - `#nextConsult`, `#nextConsultWho`, `#consultMonth`,
  - `#liveTop`, `#consultTopWrap`,
  - `#acad` — 7 dni tygodnia, wydarzenia pogrupowane po `localDate`,
  - `#acadUpdated` — czas ostatniego sukcesu / „nieaktualne” / „błąd synchronizacji”,
  - `#adminEventsCard` — tylko dla admina i tylko gdy są `unassigned`.
- Dni tygodnia: `PN WT ŚR CZW PT SB ND`; bieżący dzień: `· DZIŚ`.

### 11.4. Odświeżanie

| Timer | Akcja |
| --- | --- |
| 1 s | countdown blokady ochronnej |
| 60 s | ticker notowań i ceny instrumentów (`GET /api/quotes`) |
| 60 s | kalendarz makro, powiadomienia |
| 300 s | `loadEvents()` |
| 300 s | `loadMacroEvents()` |
| `visibilitychange` | `loadEvents()`, `loadMacroEvents()` po powrocie do karty |
| 4 s po starcie | `checkLiveReminder()` |

### 11.5. Przypomnienia

- `liveReminder` w stanie użytkownika,
- okno 15 minut przed startem (840–900 s),
- jednorazowy `liveNotified`,
- toast + `AV.pwa.notify` (service worker, jeśli zarejestrowany; w przeciwnym razie `Notification`).

### 11.6. Offline

- `localStorage` przechowuje ostatni stan i sesję UI w kluczach `alphavault.state.v3.<user.id>.*`; klucze są usuwane przy wylogowaniu i przy zmianie konta.
- Po utracie sieci UI działa, ale zapisy są odkładane.
- Po powrocie stan serwera ma pierwszeństwo; konflikt rewizji rozwiązuje 409.

### 11.7. PWA i powiadomienia

- `manifest.webmanifest` + ikony + meta iOS czynią panel instalowalnym bez App Store.
- Na ekranach do 900 px boczna nawigacja jest schowana za przyciskiem „☰ Menu”; wybór widoku zamyka panel. Inline `grid-template-columns` są zastępowane jedną kolumną, a `min-width:0` zapobiega przepełnieniu konta, nowej transakcji i Akademii.
- `pwa.js` rejestruje `/sw.js`, obsługuje `beforeinstallprompt`, `appinstalled` i przyciski `[data-install]`, `[data-pwa-notify]`.
- `sw.js` cache'uje powłokę (HTML/JS/ikony), ale **nie** cache'uje `/api/` ani `/auth/`; nawigacja jest network-first z fallbackiem offline.
- `AV.pwa.notify()` używa `registration.showNotification()` (iOS/Android) albo `Notification` w przeglądarce; `notifyTradeClosed()` wysyła deep link `/?qa=<tradeId>`.
- Nagrania Akademii nie mają playera: modal pokazuje link do Discorda, gdzie znajduje się materiał.
- Notowania instrumentów są pobierane dopiero po wejściu w `#/kalkulator`; start nie wysyła trzech zapytań `/api/quotes`.
- `notificationclick` fokusuje istniejące okno albo otwiera nowe z deep linkiem; `AV.quickAddFromUrl()` otwiera modal i czyści parametr z URL.
- `AV.openQuickAdd()` wybiera transakcję: wskazany `id` → najnowsza bez emocji/oceny → najnowsza zamknięta → najnowsza. Modal pokazuje prawdziwe dane, nie statyczny BTC.
- Zapis quick add: emocja + ocena wymagane, zdjęcie opcjonalne (`capture="environment"`). Czas od otwarcia do zapisu jest mierzony w `AV.ui.qaLastSeconds`.
- Push serwerowy: SW ma handler `push` i `notificationclick`; nie ma jeszcze serwerowego źródła zdarzeń i VAPID. Do wdrożenia z auto-importem.

### 11.8. Nagłówek panelu

- `#ticker` — `GET /api/quotes` co 60 s; renderuje wyłącznie symbole zwrócone przez API; błąd zachowuje ostatni dobry zestaw, brak danych zostawia pasek pusty.
- `#envBadge` (pasek nad aplikacją) — po zalogowaniu ukryty; pokazuje się tylko przy błędzie ostatniego zapisu, a przed logowaniem lub offline informuje o logowaniu albo trybie offline. Nie zawiera technicznych nazw bazy.
- `#syncBadge` (chip w nagłówku) — widoczny tylko przy aktywnym połączeniu giełdy albo MetaTrader: nazwa dostawcy i godzina ostatniej synchronizacji; bez połączeń ukryty.

### 11.1. Panel MCP

`AV.renderMentor()` renderuje przewodnik integracji oraz listę propozycji MCP. `AV.loadMcpProposals()`
pobiera `GET /api/mcp`; `AV.decideProposal(id, action)` wysyła decyzję do
`POST /api/mcp/proposals/decide`. Po zatwierdzeniu `AV.reloadStateFromServer()` odświeża stan
z MySQL, a lista propozycji jest pobierana ponownie. Diff pokazuje `before → after` dla każdego
pola; konflikt rewizji wyświetla komunikat o konieczności przygotowania nowej propozycji.

## 12. Puste pola i stany brzegowe

To wymaganie produktowe (FR-08), realizowane w `renderAcademy()` i `state.js`:

| Sytuacja | UI |
| --- | --- |
| brak live | `#liveTitle`, `#liveWho`, `#liveWhen`, `#liveCd`, `#liveTop` puste; przyciski ukryte |
| brak konsultacji | `#nextConsult`, `#nextConsultWho`, `#consultMonth`, `#consultTopWrap` puste |
| brak wydarzeń w tygodniu | 7 dni, zero wpisów |
| sukces z `[]` | wszystkie pola puste, `sync.status=success` |
| błąd API | ostatnie dane pozostają, `sync.status=error`, `stale=true` |
| brak `user_count` | nie pokazuj „0 os.” |
| brak `end_time` | nie pokazuj wymyślonych 60 min |
| brak prowadzącego | puste, bez „Marcin” z nazwy na siłę |

Zakazane teksty zastępcze: „Brak wydarzeń”, „Niedostępne”, „--”, „Wkrótce”, domyślne `20:00`.

## 13. Administracja wydarzeniami

**Aktor:** użytkownik z Discord ID w `ADMIN_DISCORD_IDS`.

1. `GET /api/me` → `isAdmin=true`.
2. `GET /api/admin/events` → wszystkie rekordy (także soft-deleted) + `sync`.
3. UI pokazuje `#adminEventsCard` tylko gdy są `unassigned`.
4. Klik „live” / „konsultacja” → `PUT /api/admin/events/{id}/category`.
5. Serwer sprawdza sesję, admina i `Origin`.
6. `manual_category`, `category`, `category_source='manual'`.
7. Kolejne synchronizacje zachowują ręczne przypisanie (`COALESCE`).
8. `loadEvents()` odświeża UI.

**Błąd:** brak admina → 403; brak wydarzenia → 404; zła kategoria → 422.

## 14. Deployment

### 14.1. GitHub Actions

Plik: `.github/workflows/deploy-alphavault.yml`.

1. Checkout.
2. PHP 8.3 + Node.
3. Walidacja sekretów.
4. Przygotowanie klucza SSH i `known_hosts`.
5. `php -l` wszystkich plików.
6. `scripts/deploy-alphavault.sh`:
   - test SSH,
   - backup `public` i `app` (5 ostatnich),
   - `rsync public/` → `~/public_html/alphavault.pl`,
   - `rsync app/`, `db/`, `bin/`, `config/` → `~/public_html/alphavault.pl/.app`,
   - `app-path.php`,
   - `config.json` z sekretów (600, ukryty .app),
   - `php app/db/migrate.php`,
   - health check,
   - rollback przy błędzie.

**Stan 2026-11-12:** deploy zakończony sukcesem; `https://alphavault.pl` → 200, `/api/health` → `ok=true`, `db.ok=true`, `discordConfigured=true`, `roleCheckConfigured=true`, `eventsConfigured=true`, `demoAllowed=false`; `/.app/config.json` → 403; `/docs/` → 200.

### 14.2. Health check

`GET /api/health` musi zwrócić:

```json
{
  "ok": true,
  "db": { "ok": true },
  "discordConfigured": true,
  "roleCheckConfigured": true,
  "eventsConfigured": true,
  "demoAllowed": false
}
```

Dodatkowo `/` musi zawierać „Alpha Vault”, a `/js/state.js` musi zwracać 200.

### 14.3. Deploy wyłącznie przez GitHub

`deploy-alphavault.yml` jest jedyną ścieżką wdrożenia. Uruchamia się po zielonym CI na `main` (`workflow_run`), wymaga `DEPLOY_ENABLED=true` i przechodzi krok `Verify production E2E`. Lokalny deploy, SSH/rsync i `scripts/local-deploy.sh` nie są używane.

### 14.4. Rollback

Workflow `deploy-alphavault.yml` z `rollback=true` przywraca ostatni backup `public` i `app`, potem uruchamia health check.

### 14.5. CI/CD gates i branch protection

- `main` wymaga PR i zielonego checku `PHP + API + UI`; bez bezpośredniego push, force push i usuwania gałęzi.
- `enforce_admins=true`; merge commit wyłączony — dozwolone squash/rebase.
- `DEPLOY_ENABLED=true`; deploy na `main` startuje dopiero po zielonym CI (`workflow_run`), na commicie zweryfikowanym przez `PHP + API + UI`.
- Environment `alphavault-production` dopuszcza deploy tylko z `main`.
- Repo jest publiczne; secret scanning, push protection i Dependabot security updates są włączone.

### 14.6. Serwer MCP

- `mcp.alphavault.pl` ma osobny front controller `mcp/public/index.php` i `.app` w `~/public_html/mcp.alphavault.pl/.app`.
- Wdrożenie: workflow `Deploy AlphaVault MCP` (`.github/workflows/deploy-mcp.yml`), uruchamiany z `main` z inputem `ref` (branch/tag do wdrożenia).
- `scripts/deploy-mcp.sh` wykonuje backup, rsync, migrację, klucze OAuth i health check; `MCP_WRITES_ENABLED=false` jest wymuszone.
- Workflow nie dotyka `alphavault.pl` ani WordPressa; używa environment `alphavault-production`.

MCP 2.0: `Deploy AlphaVault MCP` uruchamia `composer install`, `scripts/deploy-mcp.sh`, migrację
`db/schema.sql` + `db/migrations/20261114_mcp2.sql`, klucze OAuth i health check. Skrypt wymusza
`MCP_WRITES_ENABLED=false`; 7 narzędzi `propose_*` pozostaje ukryte. Deploy MCP nie dotyka
`alphavault.pl` ani WordPressa.

## 15. Bezpieczeństwo

| Obszar | Mechanizm |
| --- | --- |
| Sekrety | `config.json` w ukrytym .app, deny + 600; brak w repo; `.env` ignorowany |
| Token bota | tylko PHP/CLI; redagowany w logach; nigdy w HTML/JS/URL |
| Bot | `View Channels`, bez Manage Events, bez Privileged Intents |
| Sesja | cookie HttpOnly, SameSite=Lax, Secure, hash w DB |
| CSRF | `Origin` dla mutacji |
| Rate limit | MySQL, per bucket/IP |
| Nagłówki | CSP, HSTS, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy` |
| Payloady | `raw_payload` niewysyłany do klienta |
| Urządzenia | tokeny dostawców AES-256-GCM; token Apple tylko SHA-256; odłączenie usuwa metryki |
| Admin | lista Discord ID, nie rola w UI |
| Logi | bez tokenów, haseł i pełnych danych osobowych |
| WordPress | deploy nie dotyka innych katalogów ani baz |

## 16. Testy i weryfikacja

| Suita | Plik | Zakres | Wynik CI |
| --- | --- | --- | --- |
| Discord DB/sync | `tests/discord-events.test.php` | DST, UPSERT, soft delete, klasyfikacja, Stage/EXTERNAL | 24/24 PASS |
| Makro DB/sync | `tests/macro-events.test.php` | normalizacja FF/TradingView, UTC, UPSERT, soft delete, okno źródła | 21/21 PASS |
| Rola / cron | `tests/role-guard.test.php` + `tests/role-recheck.e2e.mjs` | decyzje login, fail-open, 404, 429, lock, revoke sesji | 18/18 PASS |
| Prop challenge | `tests/prop.unit.mjs` | szablony, limity, static/trailing, daty, statystyki | 27/27 PASS |
| Prop parity | `tests/prop-parity.mjs` + `tests/prop-parity.php` | PHP ↔ JS: te same fixtures i metryki | PASS |
| MCP propozycje | `tests/mcp.test.php` | idempotencja, konflikt rewizji, TTL, ochrona giełdy, izolacja, audyt | PASS |
| MCP E2E | `tests/mcp.e2e.mjs` | OAuth 2.1, PKCE, DCR, 15 read + 7 propose, prompts, writes on/off | PASS |
| MCP login | `tests/mcp-login.e2e.mjs` | mock Discord: rola, brak roli, duplikat konta | PASS |
| MCP panel | `tests/mcp-ui.e2e.mjs` | diff, approve, reject, reload, trwałość | PASS |
| Routing | `tests/router.unit.mjs` | slugi widoków, aliasy starych adresów, `viewFromHash`, `showView` | 18/18 PASS |
| Notowania | `tests/quotes.test.php` | cache, TTL, fallback, cząstkowe pobranie, uszkodzony cache | 14/14 PASS |
| Daty demo | `tests/demo-state.test.php` | przesunięcie fixture na dziś, idempotencja, plany jako punkt zaczepienia, błędne dane | 15/15 PASS |
| API | `tests/api.e2e.mjs` | health, sesja, CSRF, stan, events, macro, admin, eksport, prop | 23/23 PASS |
| UI | `tests/ui.e2e.mjs` | 15 widoków, live, prop, makro, pusty stan, zapisy, plan dnia z reloadem z MySQL, 8 odznak, nagłówek | 60/60 PASS |
| UI empty | `tests/ui-empty.e2e.mjs` | puste pola po sukcesie z `[]` | 12/12 PASS |
| PWA mobile | `tests/pwa.e2e.mjs` | manifest, SW, instalacja, powiadomienie, deep link, 10 s, push, offline | 20/20 PASS |
| Urządzenia | `tests/wearables.e2e.mjs` | OAuth2 + PKCE Oura, rotacja tokenów i klucza, statusy, Apple, UI, bezpieczeństwo, wyłączenie Garmin/Whoop | PASS |
| Konfiguracja urządzeń | `tests/wearables-config.test.php` | lista dostawców, brak fallbacku klucza, start OAuth 503, gotowość po ustawieniu klucza | 5/5 PASS |
| Dokumentacja | `tests/docs.test.mjs` | linki, sekcje, spójność kopii publicznych | PASS |
| Integracje giełd/API | `tests/integrations.e2e.mjs` | OKX, BingX, WEEX, MetaApi, EA, mocki | 20/20 PASS |
| Integracje UI | `tests/ui-integrations.e2e.mjs` | formularze, dziennik, licznik transakcji, chip nagłówka | 13/13 PASS |
| Live Discord | `discord-sync-live-test.yml` | realne API → MySQL, 2 synchronizacje | 3/3, bez duplikatów |
| Prod health/docs | `curl` + deploy verify | `/api/health`, `/docs/`, `.app` deny | PASS |
| Prod sync | `bin/sync-discord-events.php` | realne API → produkcyjny MySQL | `success`, liczba w `sync.eventsCount` |
| Prod Cron | LH Cron | health `sync.lastSuccessAt` 21:45:02 UTC | co 5 min, PASS |
| Prod monitor | `monitor-production.yml` | health, sync, UI, docs, `.app` 403 | co 15 min |
| Prod UI read-only | Playwright + sesja testowa | live, harmonogram, puste konsultacje | 10/10 PASS |

Wszystkie suites uruchamia `.github/workflows/ci.yml` (MySQL 8.4, migracja, integracje na mockach, empty-state na osobnym porcie; dodatkowo router, izolacja użytkowników i encoding guard). Lokalne polecenia nie są dowodem i nie są częścią workflow AV-engineer.

**Ograniczenia:** Stage nie wystąpił w realnym API (tylko fixture); OAuth Discord nie był testowany interaktywnie bez udziału użytkownika; LH Cron jest skonfigurowany w panelu LH (SSH nie ma dostępu do `crontab`, więc zmiany harmonogramu tylko przez panel).

## 17. Zarządzanie dokumentacją

### 17.1. Zasada jednego źródła

- Wymagania: [PRD.md](PRD.md).
- Działanie: ten dokument.
- Kontrakt API: [docs/API.md](API.md) + [docs/openapi.yaml](openapi.yaml).
- Schemat: [docs/BAZA-DANYCH.md](BAZA-DANYCH.md).
- Deploy: [docs/DEPLOY.md](https://github.com/miloszspyrka-code/av/blob/main/docs/DEPLOY.md).
- Zasady agenta: [AGENTS.md](https://github.com/miloszspyrka-code/av/blob/main/AGENTS.md).

Nie duplikuj szczegółów, których źródło jest w innym pliku — linkuj.

### 17.2. Co aktualizować przy jakiej zmianie

| Zmiana | Dokumenty |
| --- | --- |
| nowy endpoint | `docs/API.md`, `docs/openapi.yaml`, §6, PRD (jeśli funkcja) |
| nowa tabela/kolumna | `docs/BAZA-DANYCH.md`, §10, `db/schema.sql` |
| nowa zmienna env | `.env.example`, `docs/DEPLOY.md`, `docs/API.md`, §4/§14, `scripts/render-config.mjs` |
| nowa funkcja UI | `docs/FUNKCJE.md`, §11, PRD |
| zmiana klasyfikacji | §7.6, `docs/DISCORD.md`, testy |
| zmiana testów | `docs/TESTOWANIE.md`, §16, README |
| zmiana deployu | `docs/DEPLOY.md`, §14, AGENTS.md |
| nowy dokument | `docs/README.md`, ten rozdział, test linków |
| strona prawna | `docs/FUNKCJE.md` §16, `public/index.html`, `public/index.php`, `tests/docs.test.mjs` |

Audyty wewnętrzne (np. `docs/AUDYT-UZYTECZNOSCI-2026-11-13.md`) są dokumentami repo-only: wpis w `docs/README.md`, bez kopii w `public/docs`.

### 17.3. Automatyczna spójność

```powershell
node scripts/sync-docs.mjs   # generuje public/docs z docs/ i root
```

CI sprawdza linki, sekcje i brak dryfu (`node scripts/sync-docs.mjs --check` + `node tests/docs.test.mjs`). `public/docs/` jest generowane — nie edytuj plików ręcznie, zmień źródło i uruchom generator.

## 18. Runbook

| Objaw | Diagnoza | Działanie |
| --- | --- | --- |
| `https://alphavault.pl` → 403 | brak plików w docroot | deploy; sprawdź `~/public_html/alphavault.pl` |
| `/api/health` → 503 | DB niedostępna | sprawdź `config.json`, login MySQL, `db.ok` |
| health: `discordConfigured=false` | brak client ID/secret | uzupełnij sekrety i redeploy |
| health: `eventsConfigured=false` | brak bota/guild ID | uzupełnij `DISCORD_BOT_TOKEN`, `DISCORD_GUILD_ID` |
| brak wydarzeń w panelu | sync nie działa | `php bin/sync-discord-events.php`, log, `discord_sync_state` |
| `sync.status=error`, `http_401` | zły token | rotacja tokenu, redeploy |
| `sync.status=error`, `http_404` | zły guild ID | popraw `DISCORD_GUILD_ID` |
| `stale=true` | brak sukcesu > 15 min | sprawdź Cron, log, blokadę |
| duplikaty | brak UNIQUE / ręczne INSERT | sprawdź `uq_discord_event_id` |
| złe godziny | stały offset zamiast strefy | sprawdź `DateTimeZone('Europe/Warsaw')` |
| puste pola mimo wydarzeń | filtr kategorii/statusu | `/api/events`, `category`, `status` |
| 409 przy zapisie | konflikt rewizji | odśwież stan, scal |
| 403 przy zapisie | zły `Origin` | sprawdź domenę i proxy |
| deploy: billing error | limit GitHub Actions | napraw billing; brak lokalnej alternatywy, zgłoś BLOCKER |
| deploy: health fail | config/DB/Discord | przeczytaj odpowiedź health, popraw sekrety |

## 19. Słownik

| Pojęcie | Znaczenie |
| --- | --- |
| Scheduled Event | wydarzenie Discord z datą, typem i statusem |
| `live` | wydarzenie trafiające do harmonogramu live'ów |
| `consultation` | wydarzenie trafiające do karty konsultacji 1:1 |
| `unassigned` | wydarzenie nierozpoznane, wymaga decyzji admina |
| soft delete | oznaczenie `is_deleted=1` zamiast fizycznego usunięcia |
| UPSERT | `INSERT ... ON DUPLICATE KEY UPDATE` |
| `stale` | brak udanego sync od ponad 15 minut |
| `manual_category` | ręczne przypisanie, ma pierwszeństwo nad regułami |
| `raw_payload` | pełny JSON z Discorda, tylko do diagnostyki |
| DST | zmiana czasu letni/zimowy |

## 20. Integracje giełd, MetaTrader i API

### 20.1. Warstwy

```text
public/js/state.js          loadConnections, connectExchange, syncConnections, reloadStateFromServer
public/js/render2.js        panel Giełdy, formularze WEEX/OKX/BingX, MetaTrader, EA
app/Integrations/           Crypto, HttpClient, providers, ConnectionStore, ExchangeSync, MtEa
app/routes/api.php          /api/connections*, /api/mt/ea
bin/sync-exchange-trades.php  ręczny/CRON worker
```

### 20.2. Połączenie klucza

1. Przeglądarka wysyła `POST /api/connections` z polami klucza.
2. Serwer waliduje klucz u dostawcy i sprawdza uprawnienia.
3. OKX i BingX odrzucają klucz z prawem handlu/wypłat (`read_only_required`). WEEX akceptuje każdy klucz, który przechodzi odczyt konta — również z handlem i wypłatami (`permissions: any`).
4. Poświadczenia są szyfrowane AES-256-GCM kluczem `INTEGRATIONS_KEY` i zapisywane w `exchange_connections`.
5. Do UI wraca wyłącznie maska, status i liczniki.

### 20.3. Synchronizacja

- Pierwsze 30 dni pobierane jest od razu, starsze paczki (do 90 dni) w kolejnych przebiegach.
- Kolejne przebiegi pobierają okno od ostatniej synchronizacji minus 1 dzień.
- Każda transakcja ma `extId`; `exchange_imports` gwarantuje idempotencję.
- Transakcja trafia do `user_states.state.trades` i jest widoczna w dzienniku.
- Błąd dostawcy nie usuwa ostatniego dobrego zestawu; połączenie dostaje status `error` i komunikat.

### 20.4. Harmonogram

`bin/sync-exchange-trades.php` jest wywoływany z istniejącego zadania LH Cron (co 5 min) i sam pilnuje, żeby realna synchronizacja nie częściej niż co 25 min. Ręcznie:

```bash
/usr/bin/php ~/public_html/alphavault.pl/.app/bin/sync-exchange-trades.php --force
tail -20 ~/public_html/alphavault.pl/.app/logs/discord-sync.log
```

### 20.5. MetaTrader

- Hasło inwestora: MetaApi cloud; akceptacja tylko przy `investorMode:true`.
- EA: kod parowania `AV-XXXX-XXXX` (w bazie tylko SHA-256), endpoint `POST /api/mt/ea`, plik `integrations/mt5/AlphaVault.mq5`.
- EA wysyła wyłącznie zamknięte transakcje; nie handluje.

### 20.6. Bezpieczeństwo

- `INTEGRATIONS_KEY` i `METAAPI_TOKEN` tylko po stronie serwera.
- Brak publicznego endpointu uruchamiającego synchronizację; `/api/mt/ea` wymaga kodu parowania.
- API nie zwraca sekretów; maska klucza jest jedynym identyfikatorem w UI.
- Rate limiting: połączenia 12/min, synchronizacja 20/min, EA 60/min.
