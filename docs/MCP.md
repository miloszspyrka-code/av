# AlphaVault MCP — polityka, narzędzia i stan wdrożenia

> **Status: backend wdrożony w kodzie, nieopublikowany.** Działa serwer MCP (`mcp/public`), tokeny, propozycje zmian, zatwierdzanie w panelu i audyt. Brak wdrożenia na domenie MCP oraz brak serwera OAuth 2.1 — tokeny wydaje panel AlphaVault.
>
> Wersja: 2.0 · 2026-11-12
> Repozytorium: `miloszspyrka-code/av`

## 1. Zasada fundamentalna

MCP może odczytywać dane użytkownika, analizować je i przygotowywać propozycje zmian. Nie może samodzielnie zmieniać danych panelu ani wykonywać żadnych operacji giełdowych.

| Poziom | Zakres | Wymóg |
| --- | --- | --- |
| R0 | odczyt danych | brak |
| R1 | propozycja zmiany danych panelu | zatwierdzenie użytkownika w panelu |
| R2 | dane giełdowe, operacje na giełdzie, limity ryzyka, klucze API, usuwanie historii | brak dostępu; brak narzędzi |

Rozdzielenie wynika ze **źródła danych**, nie z tabeli:

- **exchange-owned** — rekordy zaimportowane z giełd (OKX, BingX, WEEX, MetaTrader): zlecenia, fill, ceny, ilości, prowizje, salda, PnL, SL/TP, leverage, historia. Tylko odczyt. Zapis w MySQL nie zmienia właściciela danych.
- **panel-owned** — plan dnia, check-in, notatka, recenzja transakcji, ręczny wpis, metadane setupu. Agent może zaproponować zmianę; zapis następuje po zatwierdzeniu.
- Metadane do transakcji giełdowej są przechowywane **osobno** w `state.tradeReviews[tradeId]`; oryginalny rekord nie jest modyfikowany.

## 2. Architektura

```text
klient MCP (ChatGPT / Grok / Inspector)
        │  HTTPS + Bearer (panel:read / panel:propose)
        ▼
mcp/public/index.php          POST /mcp (JSON-RPC 2.0, JSON)
        │
        ├── app/mcp/Mcp.php           narzędzia, propozycje, zatwierdzanie, audyt
        ├── app/routes/mcp.php        panel: tokeny i decyzje o propozycjach
        └── MySQL serwer445119_alphavault
              mcp_tokens, mcp_proposals, mcp_audit + users, user_states, wydarzenia
```

- PHP 8.3, bez Node.js na produkcji.
- MCP czyta wydarzenia z bazy synchronizowanej przez istniejący Cron; nie uruchamia synchronizacji.
- Docelowo osobna domena `mcp.alphavault.pl` (DNS wildcard już wskazuje LH; wymaga subdomeny i certyfikatu). `mcpalphavault.pl` nie jest zarejestrowana.

## 3. Protokół

- `POST /mcp` — jedyny endpoint. `GET`, `DELETE`, `OPTIONS` → 405.
- JSON-RPC 2.0, pojedyncze komunikaty; `initialize`, `notifications/initialized`, `ping`, `tools/list`, `tools/call`, `resources/list`, `prompts/list`.
- Odpowiedzi `application/json`, bez SSE i sesji serwerowej.
- Limit body: 64 KiB. Błędy protokołu: `-32600`, `-32601`, `-32602`, `-32603`.
- `Origin` z allowlisty (`chatgpt.com`, `chat.openai.com`, `platform.openai.com`, `grok.com`, `x.ai`) → inaczej 403. `Host` opcjonalnie przez `MCP_ALLOWED_HOSTS`.
- `Authorization: Bearer`; brak/nieprawidłowy token → 401 z `WWW-Authenticate`.

## 4. Uwierzytelnianie i uprawnienia

Tokeny wydaje użytkownik w panelu: **Konto i zasady → Agent MCP → Wygeneruj token**. Token jest widoczny jeden raz i zapisany wyłącznie jako `sha256`.

| Scope | Uprawnienie |
| --- | --- |
| `panel:read` | 8 narzędzi odczytu |
| `panel:propose` | 4 narzędzia propozycji (dodatkowo wymaga `MCP_WRITES_ENABLED=true`) |

- Brak `panel:read` → nie można utworzyć tokenu.
- Unieważnienie tokenu działa natychmiast.
- Jeden token = jeden użytkownik; `user_id` pochodzi wyłącznie z tokenu, nigdy z argumentów narzędzia.
- `MCP_PILOT_DISCORD_IDS` (opcjonalnie) ogranicza dostęp do listy kont; pusta wartość = wszyscy uwierzytelnieni.
- Serwer OAuth 2.1 (DCR, PKCE, authorize/token/refresh) jest **planowany**; do tego czasu klient MCP przyjmuje token Bearer wygenerowany w panelu.

## 5. Narzędzia

### 5.1. Odczyt — `readOnlyHint: true`

| Narzędzie | Wejście | Wyjście |
| --- | --- | --- |
| `get_profile` | `{}` | id, nazwa, plan, dostęp do, admin |
| `get_dashboard` | `{}` | dzisiejszy wynik, plan, gotowość, limity, kapitał, najbliższe wydarzenie, sync |
| `list_trades` | `status`, `pair`, `setup`, `grade`, `from`, `to`, `limit`, `offset` | transakcje bez screenów, `nextOffset` |
| `get_trade` | `id` | transakcja, `screensCount`, recenzja; bez base64 |
| `get_stats` | `period` = `7d\|30d\|90d\|all` | netto, win rate, PF, expectancy, plan rate, SL, prowizje, oceny |
| `get_events` | `category`, `from`, `to`, `limit` | wydarzenia z bazy + sync; bez `raw_payload` |
| `get_day_plan` | `date` | plan lub `null` |
| `list_setups` | `{}` | setupy + statystyki per setup |

### 5.2. Propozycje — `readOnlyHint: false`, `destructiveHint: false`

| Narzędzie | Zachowanie |
| --- | --- |
| `propose_panel_trade` | propozycja ręcznego wpisu; `source=manual`, brak zlecenia giełdowego |
| `propose_trade_review` | propozycja notatki/oceny/metadanych; osobny rekord `tradeReviews` |
| `propose_day_plan_change` | propozycja planu na dziś lub wczoraj |
| `propose_checkin` | propozycja check-inu na dziś |

Wspólne zasady:

- wymagany `idempotencyKey` (8–120 znaków); powtórzenie zwraca tę samą propozycję,
- narzędzia odrzucają pola giełdowe (`entry`/`size`/`sl`/`tp` przy recenzji, `exitPrice`, `fill`, `result`, `balance`, `leverage`, `source`, `fee`),
- propozycja niczego nie zmienia; zwraca `proposalId`, `status: pending` i termin ważności (30 min).

Brak narzędzi `create_trade`, `close_trade`, `delete_trade`, `update_exchange_trade`, `approve_change`.

## 6. Zatwierdzanie

1. Agent wywołuje narzędzie `propose_*` → rekord w `mcp_proposals` (`before_json`, `after_json`, `diff_json`, autor, TTL, `base_revision`).
2. Użytkownik widzi propozycję w panelu: typ, rekord, obecną i proponowaną wartość oraz różnice.
3. Zatwierdzenie lub odrzucenie wykonuje wyłącznie endpoint panelu.
4. Backend sprawdza sesję, właściciela, scope, TTL i rewizję `user_states`; zapis odbywa się w transakcji MySQL z `SELECT ... FOR UPDATE`.
5. Powstaje wpis audytowy.

| Endpoint panelu | Rola |
| --- | --- |
| `GET /api/mcp` | tokeny, propozycje, audyt, flagi |
| `POST /api/mcp/tokens` | wydanie tokenu (scope jawnie wybierany) |
| `POST /api/mcp/tokens/revoke` | unieważnienie tokenu |
| `POST /api/mcp/proposals/decide` | `approve` albo `reject` |

Wymagania endpointów decyzyjnych: sesja, `Origin`, rate limit, weryfikacja `user_id`, status `pending`, TTL, zgodność `base_revision` (niezgodność → 409).

## 7. Model danych

| Tabela | Przeznaczenie |
| --- | --- |
| `mcp_tokens` | hash tokenu, scopes, TTL, unieważnienie |
| `mcp_proposals` | propozycje: kind, target, before/after/diff, status, TTL, `base_revision`, idempotencja |
| `mcp_audit` | niezmienialny dziennik: autor, akcja, propozycja, szczegóły, czas |

Migracja: `db/migrations/20261112_mcp.sql`; tabele są także w `db/schema.sql` (tworzone przez `php app/db/migrate.php`). Tabele są addytywne.

## 8. Flagi i konfiguracja

| Zmienna | Domyślnie | Znaczenie |
| --- | --- | --- |
| `MCP_ENABLED` | `true` | kill switch całego endpointu |
| `MCP_WRITES_ENABLED` | `false` | propozycje zmian; dopóki `false`, narzędzia `propose_*` są ukryte |
| `MCP_PILOT_DISCORD_IDS` | puste | lista pilotowych kont |
| `MCP_ALLOWED_HOSTS` | puste | opcjonalna allowlista `Host` |
| `MCP_ALLOWED_ORIGINS` | lista klientów | allowlista `Origin` |

## 9. Audyt i logi

`mcp_audit` zapisuje: `mcp.token.create`, `mcp.token.revoke`, `mcp.proposal.create`, `mcp.proposal.approve`, `mcp.proposal.reject`. Pola: użytkownik, aktor (`agent|user`), propozycja, `detail_json`, czas. MCP nie ma narzędzia ani SQL do modyfikacji tych wpisów. Logi serwera nie zawierają tokenów, notatek ani argumentów.

## 10. Testy

```sh
php tests/mcp.test.php     # 17 scenariuszy bezpieczeństwa (npm run test:mcp)
```

Zakres: odczyt danych giełdowych, odmowa zmiany entry/fill/SL/TP/salda, notatka w osobnym rekordzie, propozycja planu, brak zmiany przed zatwierdzeniem, odrzucenie, zatwierdzenie, obca propozycja, brak narzędzia approve, idempotencja, konflikt rewizji, izolacja użytkowników, brak scope, audyt.

Smoke HTTP (wykonany lokalnie): `POST /mcp` bez tokenu → 401; `GET /mcp` → 405; zły `Origin` → 403; `tools/list` → 12 narzędzi; `tools/call get_profile` → dane użytkownika.

## 11. Wdrożenie — pozostałe kroki

1. Wybrać domenę (`mcp.alphavault.pl` albo rejestracja `mcpalphavault.pl`), dodać docroot i certyfikat TLS.
2. Wdrożyć `mcp/public` + `app/mcp` + `app/routes/mcp.php`; `config.json` poza docrootem.
3. Ustawić `MCP_WRITES_ENABLED=false` na start; włączyć po testach.
4. Dodać `MCP_PILOT_DISCORD_IDS`, monitoring 401/429/5xx i czas odpowiedzi.
5. Zaimplementować OAuth 2.1 (DCR, PKCE, token, refresh, revoke) i zastąpić tokeny panelowe, jeśli klienci tego wymagają.

Poza zakresem: operacje giełdowe, zmiana limitów ryzyka i kluczy API, usuwanie historii, dostęp do `raw_payload`, screenów base64 i sekretów.
