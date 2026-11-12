# AlphaVault MCP — mapa funkcji, walidacje i plan wdrożenia

> **Status: PLAN.** Ten dokument nie oznacza wdrożenia. Nie zmieniono kodu aplikacji, nie dodano domeny, nie uruchomiono migracji i nie opublikowano żadnego endpointu MCP.
>
> Wersja planu: 1.0 · 2026-11-12
> Repozytorium: `miloszspyrka-code/av`
> Produkcja aplikacji: <https://alphavault.pl>

## 1. Decyzja architektoniczna

MCP zostanie zbudowany jako **osobny front controller PHP 8.3** na wydzielonej domenie, korzystający z tej samej bazy MySQL i tego samego konta LH.pl co AlphaVault.

```text
ChatGPT / Grok / MCP Inspector
              │  HTTPS + OAuth 2.1 Bearer
              ▼
https://mcpalphavault.pl/mcp
              │
              ├── OAuth 2.1 AS (authorize, token, register, revoke)
              ├── MCP Streamable HTTP (JSON-RPC 2.0, JSON responses)
              ├── Tool layer (walidacja + uprawnienia)
              └── State repository (MySQL, transakcje, rewizje)
                            │
                            ▼
                  MySQL serwer445119_alphavault
                  users, user_states, discord_scheduled_events,
                  discord_sync_state, audit_log, rate_limits
```

Zasady nienaruszalne:

- **PHP 8.3 + Apache na LH.pl**, bez stałego procesu Node.js.
- Osobny docroot i osobny `.app`; wdrożenie MCP nie może dotknąć `alphavault.pl` ani WordPressa.
- `DISCORD_BOT_TOKEN` nie jest potrzebny na domenie MCP. Wydarzenia są czytane z bazy synchronizowanej przez istniejący Cron.
- MCP nie uruchamia synchronizacji Discord. Nie powstaje publiczny ani „wygodny” endpoint sync.
- Zapisy do stanu aplikacji są atomowe, z rewizją i idempotencją; nie nadpisują bezwarunkowo stanu z przeglądarki.
- Puste dane pozostają puste. Narzędzia nie generują „Brak wydarzeń”, „—”, „Wkrótce” ani domyślnych godzin.

## 2. Ustalenia o domenie i hostingu

Sprawdzone 2026-11-12:

| Element | Stan |
| --- | --- |
| `alphavault.pl` | 200, PHP 8.3, LH.pl, IP `195.2.223.143` |
| `mcp.alphavault.pl` | DNS A → `195.2.223.143` (wildcard `*.alphavault.pl`), ale certyfikat TLS jeszcze nie obejmuje tej nazwy |
| `mcpalphavault.pl` | brak rekordu DNS; RDAP `.pl` zwraca 404 — domena nie jest zarejestrowana |

Wniosek:

1. **Wariant A (rekomendowany, szybki):** uruchomić MCP pod `https://mcp.alphavault.pl`. DNS już wskazuje na LH; trzeba dodać subdomenę w panelu LH i wystawić certyfikat. Zero zakupu domeny.
2. **Wariant B (docelowy, zgodny z prośbą):** zarejestrować `mcpalphavault.pl`, dodać domenę do LH, ustawić DNS i certyfikat. Dopiero wtedy URL jest dokładnie taki, jak w wymaganiu.
3. **Wariant C:** oba adresy — `mcpalphavault.pl` jako kanoniczny, `mcp.alphavault.pl` jako alias techniczny lub staging.

Do decyzji użytkownika należy wybór wariantu. Bez rejestracji `mcpalphavault.pl` nie da się go uruchomić.

## 3. Protokół MCP

### 3.1. Transport

- Jeden endpoint: `POST /mcp` (kanoniczny `https://<host>/mcp`).
- Odpowiedzi `application/json`; bez strumienia SSE i bez długich połączeń — shared hosting nie jest do tego stworzony.
- `GET /mcp` → 405; `DELETE /mcp` → 405; `OPTIONS` → 405.
- Obsługa `initialize`, `notifications/initialized`, `ping`, `tools/list`, `tools/call`, `resources/list` i `prompts/list` (puste listy).
- Zgodność z wersjami: `2025-03-26`, `2025-06-18`, `2025-11-25` oraz stateless `2026-07-28` (bez obowiązkowego `initialize`).
- `MCP-Protocol-Version` walidowany, jeśli występuje. Brak nagłówka = `2025-03-26`.
- Bez `Mcp-Session-Id` po stronie serwera; każdy request jest samodzielny. To upraszcza skalowanie i unika stanu w PHP.
- `Origin` walidowany, jeśli występuje. Dozwolone originy klientów: `chatgpt.com`, `chat.openai.com`, `platform.openai.com`, `grok.com`, `x.ai`. Brak `Origin` jest normalny dla klientów serwerowych.
- `Host` musi być zgodny z domeną MCP (ochrona przed host-header injection).
- Limit body: 64 KiB dla `tools/call`, 16 KiB dla OAuth; limit stanu bez zmian: 12 MiB.

### 3.2. JSON-RPC

- Tylko pojedyncze komunikaty, bez batchy.
- Błędy protokołu: `-32700` parse, `-32600` invalid request, `-32601` method not found, `-32602` invalid params, `-32603` internal.
- Błędy narzędzia: wynik `isError: true` z czytelnym komunikatem, bez stack trace i bez SQL.
- Każde `tools/call` zwraca `content` (tekst dla modelu) oraz `structuredContent` (dane do walidacji i dalszych kroków).

## 4. Uwierzytelnianie i autoryzacja

### 4.1. OAuth 2.1

MCP jest resource serverem i jednocześnie własnym authorization serverem. Nie dodajemy zewnętrznego IdP ani VPS.

| Endpoint | Rola |
| --- | --- |
| `GET /.well-known/oauth-protected-resource` | metadata resource servera |
| `GET /.well-known/oauth-protected-resource/mcp` | wariant path-specific |
| `GET /.well-known/oauth-authorization-server` | metadata AS |
| `GET /oauth/authorize` | ekran zgody |
| `POST /oauth/authorize` | zatwierdzenie/odrzucenie |
| `POST /oauth/token` | code + PKCE, refresh |
| `POST /oauth/register` | DCR dla ChatGPT/Grok |
| `POST /oauth/revoke` | unieważnienie tokenu |
| `GET /oauth/login` | logowanie Discord na domenie MCP |
| `GET /oauth/discord/callback` | powrót z Discorda |
| `GET /oauth/connected` | lista aktywnych połączeń i unieważnienie |

Wymagania:

- Authorization Code + PKCE `S256`, public client (`token_endpoint_auth_method=none`).
- `authorization_response_iss_parameter_supported: true` i parametr `iss` w każdej odpowiedzi autoryzacyjnej.
- `resource` (RFC 8707) przyjmowany i zapisywany w tokenie; akceptowane audience: `https://<host>` i `https://<host>/mcp`.
- Access token: 1 h, losowy 32 B, w bazie tylko HMAC-SHA-256.
- Refresh token: 30 dni, rotacja przy każdym użyciu; ponowne użycie starego tokenu unieważnia rodzinę.
- DCR: dokładne `redirect_uris`, tylko HTTPS i tylko hosty z allowlisty (`chatgpt.com`, `openai.com`, `grok.com`, `x.ai`; `localhost` wyłącznie poza produkcją). Bez dzikich wildcardów.
- Tokeny w `Authorization: Bearer`, nigdy w URL, logach, opisach narzędzi ani wynikach.
- 401 z `WWW-Authenticate: Bearer resource_metadata="...", scope="alphavault.read alphavault.write"`.
- 403 z `error="insufficient_scope"` przy braku uprawnienia do zapisu.

### 4.2. Logowanie użytkownika

Rekomendacja MVP: **osobne logowanie Discord na domenie MCP**, z tym samym `DISCORD_CLIENT_ID` i `DISCORD_CLIENT_SECRET`, ale z dodatkowym redirect URI:

```text
https://<host>/oauth/discord/callback
```

Po callbacku użytkownik jest dopasowywany po `discord_id` do istniejącego rekordu w `users`, więc ma ten sam stan i subskrypcję. Sesja przeglądarki MCP jest w tej samej tabeli `sessions`, ale pod cookie `mcp_session`.

Alternatywa na później (faza 2): jednorazowy handoff z `alphavault.pl` (SSO), aby uniknąć drugiego logowania. Wymaga zmiany istniejącej trasy `/auth/discord` i jest większym ryzykiem dla produkcji, dlatego nie jest częścią MVP.

### 4.3. Uprawnienia

- `alphavault.read` — wszystkie narzędzia odczytu.
- `alphavault.write` — tworzenie/zamykanie transakcji, plan dnia, check-in.
- Admin nie jest osobnym scope: `set_event_category` sprawdza `ADMIN_DISCORD_IDS` po stronie serwera.
- `MCP_PILOT_DISCORD_IDS` — lista pilotowych użytkowników. Pusta lista w produkcji = dostęp odmówiony (fail closed).
- `MCP_ENABLED` i `MCP_WRITES_ENABLED` — kill switch. Odczyt i zapis można wyłączyć niezależnie.

## 5. Mapa funkcji aplikacji na narzędzia MCP

Legenda: **R** = read-only, **W** = zapis, **A** = admin. Fazy: 1 = MVP, 2 = po pilocie, 3 = opcjonalne.

### 5.1. Faza 1 — MVP (12 narzędzi)

| # | Narzędzie | Typ | Funkcja aplikacji | Wejście | Wyjście |
| --- | --- | --- | --- | --- | --- |
| 1 | `get_profile` | R | konto, plan, subskrypcja | `{}` | stabilne `id`, `name`, `nickname`, plan, `accessUntil`, `isAdmin` |
| 2 | `get_dashboard` | R | pulpit: wynik dnia, gotowość, plan, limity, najbliższy live | `{}` | `today`, `plan`, `readiness`, `protection`, `capital`, `nextEvent`, `sync` |
| 3 | `list_trades` | R | dziennik transakcji | `status`, `pair`, `setup`, `grade`, `from`, `to`, `limit`, `offset` | lista transakcji bez screenów, `nextOffset` |
| 4 | `get_trade` | R | szczegóły transakcji | `id` | pełne pola transakcji, `screensCount`, bez base64 |
| 5 | `get_stats` | R | statystyki | `period` = `7d\|30d\|90d\|all` | netto, win rate, PF, expectancy, max DD, plan rate, fees |
| 6 | `get_events` | R | harmonogram live/konsultacje | `category`, `from`, `to`, `limit` | wydarzenia + `sync`, bez `raw_payload` |
| 7 | `get_day_plan` | R | plan dnia | `date` | plan lub `null`, bez tekstów zastępczych |
| 8 | `list_setups` | R | playbook | `{}` | setupy + statystyki per setup |
| 9 | `create_trade` | W | dodanie transakcji | para, kierunek, entry, size, SL/TP, setup, emocja, ocena, checklista, błędy, notatka, data, konto, `idempotencyKey` | `id`, `revision`, podsumowanie |
| 10 | `close_trade` | W | zamknięcie pozycji | `id`, `exitPrice`, opcjonalnie `result`, `idempotencyKey` | wynik, R, duration, nowa rewizja |
| 11 | `save_day_plan` | W | zapis/approve planu dnia | data, rynek, bias, poziomy, scenariusze, ryzyko, max transakcji, godzina, „czego nie robię”, `approve` | plan + `approvedAt` |
| 12 | `create_checkin` | W | check-in ciało/głowa | nastrój, energia 1–5, notatka | check-in + score |

### 5.2. Faza 2 — po pilocie

| # | Narzędzie | Typ | Funkcja | Uwagi |
| --- | --- | --- | --- | --- |
| 13 | `update_trade` | W | edycja metadanych transakcji | tylko notatka, setup, emocja, ocena, checklista, błędy; bez zmiany ceny po zamknięciu |
| 14 | `delete_trade` | W | usunięcie transakcji | `confirm: true`, `destructiveHint: true`; bez kasowania masowego |
| 15 | `list_checkins` | R | historia check-inów | limit, zakres dat |
| 16 | `save_setup` | W | dodanie/edycja setupu | nazwa, interwał, ocena, 3 zasady, „nie graj tego” |
| 17 | `set_event_category` | W+A | ręczne przypisanie kategorii wydarzenia | tylko `ADMIN_DISCORD_IDS`; `live\|consultation\|unassigned` |
| 18 | `get_capital` | R | konta, salda, wpłaty/wypłaty | bez danych wrażliwych kluczy API |
| 19 | `get_tax_summary` | R | PIT-38: przychód, koszty, nadwyżka, podatek | pomocnicze, nie porada podatkowa |

### 5.3. Faza 3 — opcjonalne

| # | Narzędzie | Typ | Funkcja | Uwagi |
| --- | --- | --- | --- | --- |
| 20 | `get_mentor_insights` | R | regułowy mentor: limit strat, odgrywanie, MFE, ocena A | bez LLM po stronie serwera |
| 21 | `get_macro_calendar` | R | kalendarz makro | wymaga przeniesienia danych makro do backendu; teraz są w JS |
| 22 | `export_data` | R | eksport JSON/CSV | duże; raczej jako MCP resource niż tool |

### 5.4. Świadomie poza zakresem

- Uruchamianie synchronizacji Discord z MCP.
- Zmiana ustawień bezpieczeństwa, limitów strat, zasad i kluczy API.
- Usuwanie konta.
- Dostęp do `raw_payload`, screenów w base64, tokenów, sekretów.
- Płatności/Stripe.
- Jakiekolwiek działanie na innych domenach na hostingu.

## 6. Walidacje

### 6.1. Warstwa transportu i auth

| Obszar | Reguła | Odpowiedź |
| --- | --- | --- |
| Brak tokenu | brak `Authorization: Bearer` | 401 + `WWW-Authenticate` |
| Zły token | hash nie istnieje, wygasł, unieważniony | 401 |
| Złe audience | `aud` nie zawiera domeny MCP | 401 |
| Brak scope | token tylko read, wywołanie write | 403 + `insufficient_scope` |
| Origin | obecny i spoza allowlisty | 403 |
| Host | niezgodny z domeną | 400 |
| Content-Type | nie `application/json` dla POST | 415 |
| Body | > limit | 413 |
| JSON-RPC | brak `jsonrpc`, zły `id`, batch | błąd `-32600` |
| Rate limit | per token i per IP | 429 + `Retry-After` |
| Metoda HTTP | GET/DELETE/OPTIONS na `/mcp` | 405 |

Limity:

- Odczyt: 120 req/min/token.
- Zapis: 30 req/min/token, dodatkowo 10/min dla `create_trade` i `close_trade`.
- Logowanie: 20/min/IP.
- Token endpoint: 30/min/IP.
- DCR: 10/hour/IP.

### 6.2. Stan i współbieżność

- Każdy zapis: `SELECT ... FOR UPDATE` na `user_states` w transakcji.
- Brak rekordu → `INSERT` z rewizją 1.
- Jest rekord → inkrementacja `revision`, zapis całego JSON, `updated_at`.
- Opcjonalny `expectedRevision`: niezgodność → `409 revision_conflict` z aktualną rewizją.
- Brak `expectedRevision`: zapis atomowy i zwrot nowej rewizji. Przeglądarka z otwartą kartą dostanie 409 przy swoim zapisie i odświeży stan — to istniejące zachowanie.
- `idempotencyKey` dla `create_trade` i `close_trade`: ten sam klucz + to samo narzędzie + ten sam użytkownik zwraca zapisany wynik, nie tworzy duplikatu. TTL 24 h.
- Maksymalny rozmiar stanu: 12 MiB. Przekroczenie → `413`, bez zapisu.
- Walidacja całego obiektu przed zapisem; przy błędzie żadna część stanu nie jest zmieniana.

### 6.3. `create_trade`

| Pole | Walidacja |
| --- | --- |
| `pair` | 2–20 znaków, `A-Z0-9._/-`, wymagane, uppercase |
| `side` | `long` lub `short` (mapowane na `l`/`s` w stanie) |
| `entry` | liczba > 0, skończona, maks. 1e9 |
| `size` | liczba > 0, skończona, maks. 1e9 |
| `sl` | opcjonalne; > 0; long: `< entry`; short: `> entry` |
| `tp` | opcjonalne; > 0; long: `> entry`; short: `< entry` |
| `setup` | istniejący setup z playbooka albo `Bez setupu` |
| `emotion` | jedna z: Spokój, Skupienie, Pewność, Nuda, Zmęczenie, Strach, FOMO, Złość, Chciwość |
| `grade` | `A`, `B`, `C`; brak → wylicz z checklisty |
| `checklist` | 0–`checkTotal`; `checkTotal` 1–20, domyślnie 7 |
| `errors` | maks. 10 stringów, każdy ≤ 60 znaków |
| `note` | ≤ 2000 znaków |
| `date` | ISO 8601; domyślnie teraz; nie z przyszłości > 5 min; nie starsze niż 2 lata bez `allowBackdate=true` |
| `account` | istniejące konto; domyślnie pierwsze z `include=true` |
| `screens` | niedostępne przez MCP; zapis `{}` |

Dodatkowo:

- **Tryb ochronny:** jeśli `lockInfo.active`, `create_trade` zwraca `protection_active` i nie zapisuje. MCP nie omija limitu strat; obejście zostaje w aplikacji.
- **Ryzyko:** `risk = |entry - sl| * size`; jeśli `sl` brak, `0`. Jeśli `risk%` kapitału > `settings.maxRisk`, narzędzie zwraca ostrzeżenie i wymaga `acknowledgeRisk=true`; bez tego nie zapisuje. To zachowanie jest bardziej ostrożne niż UI, które tylko ostrzega.
- **Duplikat:** ten sam użytkownik, para, entry, size i data w oknie 60 s → `possible_duplicate`; wymaga `allowDuplicate=true`.

Wynik zawiera `id`, `revision`, `risk`, `riskPercent`, `planMatched`, `protection` i zwięzłe podsumowanie.

### 6.4. `close_trade`

| Pole | Walidacja |
| --- | --- |
| `id` | istniejąca transakcja użytkownika |
| stan | `result === null`; już zamknięta → `already_closed` (idempotentne, jeśli wynik ten sam) |
| `exitPrice` | > 0, skończone; wymagane, jeśli brak `result` |
| `result` | opcjonalne; skończone, `abs(result) ≤ 1e9`; nadpisuje wyliczenie |
| `idempotencyKey` | opcjonalne |

Wyliczenia:

- `result = (exit - entry) * size * (side === 'l' ? 1 : -1)`.
- `risk = |entry - sl| * size` albo `|entry * 0.01 * size|` dla `sl = "brak"`.
- `r = round(result / risk, 2)`; `risk = 0` → `r = 0`.
- `mfe = max(mfe, |r|)`.
- `durationMin = max(1, round((now - date) / 60000))`.
- `mentor` — dokładnie teksty z UI (zysk/strata).

### 6.5. `save_day_plan`

| Pole | Walidacja |
| --- | --- |
| `date` | `YYYY-MM-DD`; domyślnie dziś `Europe/Warsaw`; dozwolone dziś i wczoraj w MVP |
| `market` | 1–20 znaków, wymagane przy `approve=true` |
| `bias` | `long`, `short`, `neutral` |
| `why` | ≤ 2000 znaków; wymagane przy `approve=true` |
| `levels` | maks. 10; typ `support`/`resistance`; cena > 0; notatka ≤ 200 |
| `ifMain`, `thenMain`, `ifAlt`, `thenAlt` | ≤ 2000 znaków |
| `riskPercent` | 0.1–100; zapis `"3%"` |
| `maxTrades` | 1–50; zapis `"5"` |
| `until` | `HH:MM`; zapis `"21:00"` |
| `notDo` | ≤ 2000 znaków |
| `approve` | bool; `true` ustawia `approvedAt` |

Zapis zachowuje istniejące `approvedAt`, jeśli `approve=false` — tak jak UI. `approve=true` wymaga rynku i uzasadnienia.

### 6.6. `create_checkin`

| Pole | Walidacja |
| --- | --- |
| `mood` | dokładnie jeden z 9 nastrojów UI |
| `energy` | liczba całkowita 1–5 |
| `note` | ≤ 500 znaków |
| `date` | tylko dziś `Europe/Warsaw`; wpis z tego dnia jest zastępowany |

`score = clamp(50 + (energy - 3) * 10 + moodMod, 0, 100)`, gdzie `moodMod` jest identyczny jak w `public/js/state.js`.

### 6.7. `get_events` i `get_dashboard`

- Tylko wydarzenia z bazy, z guild ID z konfiguracji; bez wywołań Discord API.
- `raw_payload`, `creator_json` i `recurrence_rule` nie są zwracane.
- `description` jest danymi niezaufanymi: narzędzie opisuje to wprost, a model nie może traktować jej jako instrukcji.
- Brak wydarzeń → `events: []`, `nextEvent: null`, bez tekstów zastępczych.
- `sync.stale` liczony jak w `/api/health` (15 min).
- Czasy: `startAt` UTC ISO, `startLocal` `Europe/Warsaw` z offsetem.

### 6.8. `get_stats`

- Zakres liczony od początku dnia `Europe/Warsaw`.
- Tylko transakcje zamknięte i tylko konta z `include=true`.
- `profitFactor`: `gp/gl`; `gl=0` i `gp>0` → `99` (sentinel jak w UI); brak zysków i strat → `0`.
- `maxDD` i `maxDDpct` liczone od `startCapital`; wartości ujemne.
- `planRate`, `withSL`, `fees` jak w UI.
- `byGrade` zawsze 3 pozycje; `bySetup` maks. 5 pozycji sortowanych po netto.

## 7. Model danych

Nowe tabele (addytywne, `CREATE TABLE IF NOT EXISTS`):

| Tabela | Przeznaczenie |
| --- | --- |
| `mcp_clients` | klienci DCR: `client_id`, nazwa, redirect URI, metoda auth, daty |
| `mcp_auth_codes` | kody autoryzacyjne: hash, klient, user, redirect, scope, PKCE, resource, TTL |
| `mcp_tokens` | access/refresh: hash, klient, user, scope, audience, TTL, rotacja, revocation |
| `mcp_login_requests` | stan logowania OAuth przez Discord (powrót do authorize) |
| `mcp_consents` | ekran zgody: klient, user, scope, CSRF, TTL |
| `mcp_idempotency` | klucze idempotencji zapisów, odpowiedź, TTL |

Współdzielone bez zmian: `users`, `sessions`, `user_states`, `audit_log`, `rate_limits`, `discord_scheduled_events`, `discord_sync_state`.

Indeksy:

- `mcp_tokens(user_id, kind, expires_at)`, `mcp_tokens(token_hash)`.
- `mcp_auth_codes(expires_at)`, `mcp_consents(expires_at)`.
- `mcp_idempotency(user_id, idem_key)`.
- `mcp_clients(client_id)`.

Migracja: dopisanie tabel do `db/schema.sql` + osobny plik `db/migrations/20261112_mcp.sql` dla dokumentacji. `app/db/migrate.php` uruchamia `schema.sql`; nowe tabele muszą być w nim, bo katalog `migrations/` nie jest wykonywany automatycznie.

## 8. Bezpieczeństwo

- CSRF na ekranie zgody: token powiązany z `mcp_consents`, ważny 10 min.
- Kod autoryzacyjny: jednorazowy, 5 min, powiązany z klientem, redirect URI, PKCE i resource.
- Refresh token: rotacja; wykrycie ponownego użycia unieważnia rodzinę.
- DCR: rate limit, allowlista hostów redirect URI, dokładne dopasowanie.
- Brak tokenu w URL, logach, `structuredContent`, opisach narzędzi i błędach.
- Logi: `request_id`, tool, user_id, status, duration; bez argumentów, notatek i tokenów.
- `audit_log`: `mcp.oauth.authorize`, `mcp.oauth.token`, `mcp.tool.call`, `mcp.tool.error`, `mcp.token.revoke`.
- `raw_payload`, screeny base64, `DISCORD_BOT_TOKEN`, `DISCORD_CLIENT_SECRET`, hasło DB — nigdy w wyniku MCP.
- Wyniki przycinane: lista transakcji 100, wydarzenia 100, notatki 2000 znaków, opisy wydarzeń 2000 znaków.
- `Content-Security-Policy`, `X-Frame-Options: DENY`, `nosniff`, HSTS — jak w aplikacji.
- `config.json` w `.app`, 600, `.htaccess` deny.
- Brak `Access-Control-Allow-Origin: *`; przeglądarka nie potrzebuje CORS do MCP.

## 9. Plan wdrożenia

### Faza 0 — decyzje i domena

1. Wybrać wariant domeny: `mcp.alphavault.pl` (szybki) albo `mcpalphavault.pl` (zgodny z prośbą; wymaga rejestracji).
2. Dodać domenę/subdomenę w panelu LH z docrootem `~/public_html/<host>`.
3. Wystawić certyfikat TLS i potwierdzić HTTPS.
4. Dodać redirect URI MCP w aplikacji Discord.
5. Potwierdzić, że `Authorization` dociera do PHP; jeśli nie, dodać regułę `.htaccess`.

**Gate G0:** `https://<host>/.well-known/oauth-protected-resource` zwraca 200 i poprawny JSON; certyfikat ważny.

### Faza 1 — szkielet i OAuth

1. Dodać `mcp/public/index.php`, routing, `.htaccess`, `app/mcp/*`.
2. Dodać tabele i migrację.
3. Zaimplementować discovery, DCR, authorize, consent, token, refresh, revoke.
4. Dodać logowanie Discord na domenie MCP.
5. Dodać `MCP_ENABLED`, `MCP_WRITES_ENABLED`, `MCP_PILOT_DISCORD_IDS`.

**Gate G1:** MCP Inspector przechodzi `initialize`, `tools/list`, `tools/call` na tokenie testowym; OAuth code+PKCE działa lokalnie; brak tokenu daje 401 z poprawnym `WWW-Authenticate`.

### Faza 2 — narzędzia odczytu

1. `get_profile`, `get_dashboard`, `list_trades`, `get_trade`, `get_stats`, `get_events`, `get_day_plan`, `list_setups`.
2. Wspólny `StateRepository` i serwisy obliczeń.
3. Testy puste/pełne, DST, pagination, brak `raw_payload`/screenów.

**Gate G2:** ChatGPT connector widzi 8 narzędzi read-only i poprawnie odpowiada na pytania o dzisiejszy wynik, transakcje, statystyki i najbliższy live. Grok connector widzi te same narzędzia.

### Faza 3 — zapisy

1. `create_trade`, `close_trade`, `save_day_plan`, `create_checkin`.
2. Idempotencja, `expectedRevision`, tryb ochronny, limity ryzyka.
3. Testy współbieżności i zgodności z UI.

**Gate G3:** pilot tworzy transakcję przez ChatGPT, widzi ją w UI po odświeżeniu; zamyka ją i statystyki się zgadzają; plan i check-in działają; próba obejścia limitu strat jest odrzucana.

### Faza 4 — produkcja i monitoring

1. Wdrożyć przez `scripts/deploy-mcp.sh` (dry-run → deploy).
2. Włączyć `MCP_WRITES_ENABLED=false` na start; tylko odczyt.
3. Dodać pilotowe Discord ID; włączyć zapisy dla pilota.
4. Monitorować 24 h: 5xx, 401, 429, błędy narzędzi, czas odpowiedzi.
5. Rozszerzyć na wszystkich; opcjonalnie faza 2 narzędzi.
6. Dodać `monitor-mcp.yml` co 15 min: health, discovery, 401 challenge.

### Rollback

- `MCP_ENABLED=false` — natychmiastowe wyłączenie endpointu.
- `UPDATE mcp_tokens SET revoked_at=UTC_TIMESTAMP()` — unieważnienie wszystkich tokenów.
- Restore `.app` z backupu; tabele MCP są addytywne, można je zostawić.
- Usunięcie DNS/domeny nie wpływa na `alphavault.pl`.

## 10. Testy

### Lokalne

- `php -l` wszystkich nowych plików.
- `npm run test:docs`.
- Nowy `npm run test:mcp`:
  - JSON-RPC: initialize, tools/list, tools/call, notifications, błędy.
  - OAuth: DCR, authorize, consent, PKCE, token, refresh, revoke, reuse detection.
  - Walidacje: każde pole, granice, złe typy, brak tokenu, zły scope, zły origin.
  - Zapisy: create/close/plan/checkin, revision conflict, idempotencja.
  - Współbieżność: dwa równoległe zapisy bez utraty danych.
  - Bezpieczeństwo: brak `raw_payload`, screenów, tokenów w odpowiedziach i logach.
- `npm test`, `npm run test:ui`, `npm run test:ui:empty`, `npm run test:events` — bez regresji.
- Nowy test UI: transakcja utworzona przez MCP pojawia się w dzienniku i statystykach.

### Produkcja

- `curl` discovery + health.
- MCP Inspector na `https://<host>/mcp`.
- ChatGPT: dodanie connectora, OAuth, `tools/list`, odczyt, zapis, potwierdzenie.
- Grok: custom connector, OAuth lub token fallback, `tools/list`, odczyt.
- Unieważnienie tokenu i próba ponownego wywołania → 401.
- Sprawdzenie logów: brak tokenów, brak notatek, brak `raw_payload`.

## 11. Monitoring i utrzymanie

- `GET /health` → `{ok, service:"alphavault-mcp", version, db:{ok}, mcp:{enabled,writesEnabled,clients,tokens}}`.
- `GET /.well-known/oauth-protected-resource` → 200.
- `POST /mcp` bez tokenu → 401 z `WWW-Authenticate`.
- Cron dzienny `bin/mcp-maintenance.php`: usuwa wygasłe kody, consenty, idempotencję; czyści stare logi.
- GitHub Actions `monitor-mcp.yml` co 15 min.
- Alert, gdy health != 200 przez 3 kolejne próby.

## 12. Ryzyka i otwarte decyzje

| Ryzyko / decyzja | Wpływ | Mitigacja |
| --- | --- | --- |
| `mcpalphavault.pl` niezarejestrowana | brak możliwości użycia dokładnego URL | rejestracja albo `mcp.alphavault.pl` |
| Drugie logowanie Discord | tarcie przy pierwszym połączeniu | osobny redirect URI; później SSO handoff |
| Brak OAuth w Grok dla custom connectora | Grok nie połączy się | fallback: personal access token (Bearer) |
| Współbieżność z otwartą kartą UI | 409 przy zapisie w przeglądarce | istniejąca obsługa konfliktu; MCP zawsze atomowy |
| Niezgodność schematu stanu | uszkodzenie danych UI | testy kontraktu z `db/demo-state.json` i UI |
| Prompt injection w opisach Discord | model wykona niechcianą akcję | opisy jako dane, zapisy tylko za wyraźną intencją, potwierdzenia |
| DCR/open redirect | phishing przez fałszywego klienta | allowlista redirect hostów, ekran zgody, rate limit |
| Rozszerzenie zakresu na „wszystko” | ryzyko i złożoność | fazy 1–3; brak ustawień bezpieczeństwa i sync |
| Prywatność | dane tradingowe trafiają do OpenAI/xAI | aktualizacja polityki prywatności, zgoda, minimalizacja danych |

**Decyzje wymagane przed implementacją:**

1. Domena: `mcp.alphavault.pl` czy zakup `mcpalphavault.pl`.
2. Zakres MVP: 12 narzędzi z tabeli 5.1 — akceptacja.
3. Logowanie: osobny Discord redirect URI (rekomendowane) czy SSO handoff.
4. Pilot: lista Discord ID.
5. Czy dopuścić `delete_trade` w fazie 2.
6. Aktualizacja polityki prywatności/regulaminu pod ChatGPT/Grok.

## 13. Definition of Done dla wdrożenia MCP

1. Domena i TLS działają; discovery 200.
2. OAuth code+PKCE, refresh, revoke i 401 challenge przetestowane.
3. Wszystkie narzędzia mają `inputSchema`, `outputSchema`, `annotations` i `securitySchemes`.
4. Walidacje i testy współbieżności zielone.
5. UI AlphaVault czyta stan zapisany przez MCP bez błędów JS.
6. `npm test`, `test:ui`, `test:ui:empty`, `test:events`, `test:docs` zielone.
7. Logi nie zawierają tokenów, notatek, `raw_payload` ani screenów.
8. Monitoring i rollback opisane i sprawdzone.
9. Polityka prywatności i regulamin zaktualizowane.
10. Pilot 24 h bez błędów; dopiero potem pełne włączenie.

## 14. Powiązania

- [PRD.md](../PRD.md) — wymagania produktowe.
- [DOCUMENTATION.md](../DOCUMENTATION.md) — działanie aplikacji i punkty styku.
- [docs/API.md](API.md) — istniejący kontrakt HTTP.
- [docs/BAZA-DANYCH.md](BAZA-DANYCH.md) — schemat MySQL.
- [docs/DEPLOY.md](DEPLOY.md) — wdrożenie LH.pl.
- [docs/TESTOWANIE.md](TESTOWANIE.md) — testy.
- [AGENTS.md](../AGENTS.md) — kontrakt pracy.
