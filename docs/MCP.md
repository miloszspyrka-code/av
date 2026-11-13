# AlphaVault MCP 2.0 — OAuth 2.1, narzędzia i propozycje zmian

> **Status:** kod w `main` (MCP 2.0), endpoint produkcyjny `https://mcp.alphavault.pl/mcp`.
> Domyślnie `MCP_ENABLED=true` na domenie MCP, `MCP_WRITES_ENABLED=false` — narzędzia zapisujące
> nie są wtedy widoczne, a scope `alphavault.write` nie może zostać przyznany.
>
> Repozytorium: `miloszspyrka-code/av` · Aplikacja: <https://alphavault.pl>

## 1. Architektura

MCP jest osobnym front controllerem PHP 8.3 na `mcp.alphavault.pl`. Korzysta z tej samej bazy MySQL
i tego samego konta LH.pl co AlphaVault. Nie ma stałego procesu Node.js.

```text
ChatGPT / Claude / Grok
          │ HTTPS + OAuth 2.1 Bearer
          ▼
https://mcp.alphavault.pl/mcp
          ├── OAuth 2.1 AS (authorize, token, register, revoke, discovery)
          ├── MCP Streamable HTTP (JSON-RPC 2.0)
          ├── Tools (odczyt + propozycje zmian)
          ├── Prompts (scenariusze pracy)
          └── MySQL: users, user_states, mcp_*, macro_events, exchange_*
```

Zasady:

- tylko dane właściciela tokenu OAuth; `user_id` nigdy nie jest argumentem narzędzia,
- brak zewnętrznej synchronizacji Discord, giełd i makro z MCP,
- brak narzędzia zatwierdzającego propozycję,
- dane giełdowe są niezmienne,
- puste dane = puste tablice lub `null`, bez tekstów zastępczych.

## 2. OAuth 2.1

| Endpoint | Rola |
| --- | --- |
| `GET /.well-known/oauth-protected-resource` | metadata resource servera |
| `GET /.well-known/oauth-authorization-server` | metadata AS |
| `POST /oauth/register` | Dynamic Client Registration |
| `GET/POST /oauth/authorize` | ekran zgody i decyzja |
| `POST /oauth/token` | code + PKCE S256, refresh |
| `POST /oauth/revoke` | unieważnienie tokenu |
| `GET /oauth/login` | logowanie Discord na domenie MCP |
| `GET /oauth/discord/callback` | powrót z Discorda |
| `GET /oauth/connected` | aktywne połączenia i unieważnienie |

- Authorization Code + PKCE `S256`, public client (`none`) lub `client_secret_post`.
- Dokładne `redirect_uri`; allowlista hostów: `chatgpt.com`, `chat.openai.com`, `platform.openai.com`,
  `claude.ai`, `grok.com`, `x.ai` (localhost tylko poza produkcją).
- Access token: JWT RS256, 1 h, `aud=https://mcp.alphavault.pl/mcp`; w bazie `mcp_access_tokens`.
- Refresh token: 30 dni, rotacja przy każdym użyciu; ponowne użycie unieważnia rodzinę.
- tokeny wyłącznie w `Authorization: Bearer`; brak tokenu → `401` z `WWW-Authenticate`,
  brak scope → `403 insufficient_scope`.

### 2.1. Scope

| Scope | Znaczenie |
| --- | --- |
| `alphavault.read` | wszystkie narzędzia odczytu |
| `alphavault.write` | przygotowywanie propozycji zmian; **nie** zapis bezpośredni |

`alphavault.write` jest przyznawany tylko wtedy, gdy:

1. `MCP_WRITES_ENABLED=true`,
2. klient poprosi o ten scope,
3. użytkownik zaznaczy na ekranie zgody „Zezwalam na przygotowywanie propozycji zmian”.

Brak checkboxa = token tylko z `alphavault.read`.

### 2.2. Feature flags

| Zmienna | Domyślnie | Działanie |
| --- | --- | --- |
| `MCP_ENABLED` | `false` (aplikacja), `true` (domena MCP) | kill switch całego MCP |
| `MCP_WRITES_ENABLED` | `false` | `false` ukrywa 7 narzędzi `propose_*`, blokuje scope write i bezpośrednie wywołania |
| `MCP_PILOT_DISCORD_IDS` | puste | opcjonalna allowlista pilotowa |

## 3. Narzędzia odczytu (15)

| Narzędzie | Źródło | Uwagi |
| --- | --- | --- |
| `get_profile` | `users` | plan, dostęp, rola, admin |
| `get_dashboard` | `user_states`, `discord_*` | wynik dnia, plan, gotowość, limity, kapitał, sync |
| `list_trades` | `user_states.trades` | filtry i paginacja; bez screenów |
| `get_trade` | `user_states.trades` | szczegóły + recenzja |
| `get_stats` | transakcje zamknięte | netto, WR, PF, expectancy, DD, oceny, setupy |
| `get_events` | `discord_scheduled_events` | UTC + `Europe/Warsaw`; bez `raw_payload` |
| `get_day_plan` | `user_states.plans` | plan lub `null` |
| `list_setups` | `user_states.setups` | zasady + statystyki |
| `get_macro_calendar` | `macro_events` | `forecast`, `previous`, `impact`; bez `raw_payload` |
| `get_prop_challenges` | `user_states.prop.challenges` | metryki z `PropChallengeService` (parytet z `public/js/prop.js`) |
| `get_checkin_history` | `user_states.checkins` | nastrój, energia, notatka, score |
| `get_accounts` | `user_states.accounts` | typ, broker, waluta, saldo, `include`, `primary`; bez poświadczeń |
| `get_capital_summary` | konta + `flows` | kapitał, start, przepływy, waluty; przeliczenia USDT orientacyjne |
| `get_connection_status` | `exchange_connections` | status, permissions, `lastSyncAt`, `importedCount`, oczyszczony błąd |
| `get_trade_context` | transakcja + recenzja + setup + plan | zgodność z planem, statystyki setupu |

Każde narzędzie ma `inputSchema` (`additionalProperties: false`), `outputSchema`, `annotations`
i `_meta.securitySchemes`. Limity: 100 pozycji na stronę.

## 4. Propozycje zmian (7)

| Narzędzie | Model docelowy |
| --- | --- |
| `propose_create_trade` | nowa ręczna transakcja: `pair`, `dir`, `entry`, `size`, `sl`, `tp`, `setup`, `emotion`, `grade`, `checklist`, `checkTotal`, `errors`, `note`, `date`, `account` |
| `propose_close_trade` | zamknięcie ręcznej pozycji: `tradeId`, `exitPrice`; wynik i R liczone jak w UI |
| `propose_trade_review` | `note`, `grade`, `setup`, `emotion`, `checklist`, `errors`; osobny `tradeReviews` |
| `propose_save_day_plan` | `market`, `bias`, `why`, `levels`, `ifMain/thenMain`, `ifAlt/thenAlt`, `riskPercent`, `maxTrades`, `until`, `notDo`; bez `approvedAt` |
| `propose_save_checkin` | `mood`, `energy`, opcjonalnie `note`; score jak w `public/js/state.js` |
| `propose_save_setup` | `id?`, `name`, `tf`, `grade`, `rules` (2–5), `bad`, `seed`; bez usuwania Playbooka |
| `propose_save_prop_challenge` | konfiguracja zgodna z `user_states.prop.challenges` i `public/js/prop.js` |

Wszystkie wymagają `idempotencyKey` (8–120 znaków). Ponowienie z tym samym kluczem i tym samym
wnioskiem zwraca istniejącą propozycję. Ten sam klucz z inną treścią → `409 idempotency_conflict`.

## 5. Przepływ zatwierdzania

```text
Agent AI → propose_* → walidacja → mcp_proposals (pending)
                                      │
                                      ▼
                          Panel AlphaVault → podgląd diff
                                      │
                         Zatwierdź / Odrzuć (sesja + CSRF)
                                      │
                                      ▼
                     transakcja MySQL + SELECT ... FOR UPDATE
                     → nowa revision + audit → user_states
```

- TTL propozycji: 30 minut.
- Zatwierdzenie sprawdza: właściciela, status, TTL, `base_revision`, target i rozmiar stanu.
- Konflikt rewizji → `409 revision_conflict`; użytkownik prosi agenta o nową propozycję.
- Brak częściowego zapisu: błąd = rollback.
- Zatwierdzanie wyłącznie `POST /api/mcp/proposals/decide` w sesji panelu; brak takiego narzędzia w MCP.

## 6. Model danych

Tabele addytywne (szczegóły w [BAZA-DANYCH.md](BAZA-DANYCH.md)):

| Tabela | Przeznaczenie |
| --- | --- |
| `mcp_clients` | klienci DCR |
| `mcp_auth_codes` | kody autoryzacyjne |
| `mcp_access_tokens` | access tokeny (JWT, `jti`) |
| `mcp_refresh_tokens` | refresh tokeny i rotacja |
| `mcp_login_requests` | stan logowania Discord |
| `mcp_consents` | ekran zgody, CSRF, TTL |
| `mcp_proposals` | propozycje zmian, diff, idempotencja, TTL |
| `mcp_audit` | audyt agenta i użytkownika |
| `mcp_idempotency` | rezerwa pod przyszłe operacje bezpośrednie |

`mcp_proposals`:

| Kolumna | Opis |
| --- | --- |
| `id` | UUID |
| `user_id` | właściciel |
| `client_name` | klient OAuth |
| `kind` | `manual_trade`, `close_trade`, `trade_review`, `day_plan`, `checkin`, `setup`, `prop_challenge` |
| `target_ref` | `trade:new`, `trade:<id>`, `plan:<data>`, `checkin:<data>`, `setup:new`, `setup:<id>`, `prop:new`, `prop:<id>` |
| `base_revision` | rewizja `user_states` w chwili utworzenia |
| `payload_hash` | hash wniosku do idempotencji |
| `before_json`, `after_json`, `diff_json` | podgląd zmiany |
| `status` | `pending`, `approved`, `rejected`, `expired` |
| `idempotency_key` | unikalny per użytkownik |
| `expires_at`, `decided_at`, `decided_by` | TTL i audyt |

## 7. Ochrona danych giełdowych

MCP nigdy nie zmienia: `entry`, `exit`, `exitPrice`, `price`, `fill`, `fillPrice`, `result`, `pnl`,
`size`, `qty`, `quantity`, `sl`, `tp`, `leverage`, `fee`, `fees`, `balance`, `equity`, `source`,
`exchangeId`, `externalId`.

- `propose_create_trade` tworzy wyłącznie nową transakcję ręczną (`source = Ręcznie`).
- `propose_close_trade` odmawia dla transakcji powiązanej z `exchange_imports` oraz dla źródła
  innego niż ręczne.
- `propose_trade_review` zapisuje recenzję w `user_states.tradeReviews`, nie w transakcji.
- Próba podania pola finansowego w argumencie → `403 exchange_data_readonly`.
- Zatwierdzenie ponownie sprawdza target i odrzuca nieaktualne dane.

## 8. Panel AlphaVault

Sekcja „Mentor AI → Integracje MCP” zawiera listę oczekujących propozycji:

- typ zmiany, klient, data utworzenia, TTL, target,
- czytelny diff `before → after`,
- Zatwierdź / Odrzuć,
- status po decyzji i komunikat konfliktu rewizji.

Endpointy panelu:

| Metoda | Ścieżka | Opis |
| --- | --- | --- |
| `GET` | `/api/mcp` | propozycje pending + audyt + informacje o scope |
| `POST` | `/api/mcp/proposals/decide` | `{id, action: approve\|reject}` |

Wymagane: sesja panelu (`requireAuth`), `requireSameOrigin`, rate limit 60/min, własność propozycji.

## 9. Prompty

| Prompt | Zawartość |
| --- | --- |
| `prepare_trading_day` | dashboard, plan, makro, wydarzenia, gotowość, limity |
| `review_trade` | transakcja, setup, plan, checklista, emocje, błędy |
| `weekly_review` | wyniki, WR, PF, setupy, emocje, błędy, challenge'e |

Prompty nie wykonują zapisu. Mogą zawierać wyłącznie instrukcje i identyfikatory przekazane przez
użytkownika.

## 10. Testy

| Test | Zakres |
| --- | --- |
| `php tests/mcp.test.php` | propozycje, idempotencja, konflikt rewizji, TTL, ochrona giełdy, izolacja, audyt |
| `node tests/mcp.e2e.mjs` | OAuth, PKCE, DCR, tokeny, 15 read + 7 propose, prompts, writes on/off |
| `node tests/mcp-login.e2e.mjs` | logowanie Discord na mocku: rola, brak roli, duplikat konta |
| `node tests/mcp-ui.e2e.mjs` | panel: diff, approve, reject, reload, trwałość |
| `node tests/prop-parity.mjs` | parytet PHP ↔ JS silnika prop challenge |
| `node tests/mcp-user-service.php` | idempotentne dopasowanie konta po Discord ID |

CI: `.github/workflows/ci.yml` — krok `MCP security tests`, `MCP prop parity`, `MCP E2E`,
`MCP login E2E`, `MCP panel E2E`.

## 11. Wdrożenie

- `alphavault.pl`: `deploy-alphavault.yml` (public + app + db, bez `vendor/`).
- `mcp.alphavault.pl`: `Deploy AlphaVault MCP` → `scripts/deploy-mcp.sh`, ref `main`.
- Deploy MCP nie dotyka `alphavault.pl` ani WordPressa.
- `MCP_WRITES_ENABLED=false` jest wymuszane przez skrypt deployu w fazie 1.
- Rollback: `MCP_ENABLED=false`, unieważnienie tokenów, restore `.app` z backupu.

## 12. Zmienne środowiskowe MCP

| Zmienna | Domyślnie | Opis |
| --- | --- | --- |
| `MCP_ENABLED` | `false` | główny kill switch |
| `MCP_WRITES_ENABLED` | `false` | propozycje zmian |
| `MCP_ISSUER` | `APP_URL` | issuer OAuth |
| `MCP_RESOURCE` | `APP_URL/mcp` | audience/resource |
| `MCP_PRIVATE_KEY_PATH` | `keys/oauth-private.pem` | klucz RSA |
| `MCP_PUBLIC_KEY_PATH` | `keys/oauth-public.pem` | klucz publiczny |
| `MCP_ENCRYPTION_KEY_PATH` | `keys/oauth-encryption.key` | klucz szyfrowania tokenów |
| `MCP_ALLOWED_REDIRECT_HOSTS` | lista klientów AI | allowlista redirect URI |
| `MCP_ACCESS_TOKEN_TTL` | `3600` | sekundy |
| `MCP_REFRESH_TOKEN_TTL` | `2592000` | sekundy |
| `MCP_AUTH_CODE_TTL` | `300` | sekundy |
| `MCP_BODY_LIMIT` | `65536` | bajty |
| `MCP_LOG_FILE` | `logs/mcp.log` | log zgody i błędów |
| `SESSION_COOKIE` | `av_session` / `mcp_session` | cookie sesji |

### 12.1. Prywatność

Dane tradingowe są przekazywane wyłącznie klientowi AI, którego połączenie użytkownik autoryzował.
Polityka prywatności §13.2 opisuje zakres MCP, zgody i wycofanie tokenu:
[/polityka-prywatnosci](https://alphavault.pl/polityka-prywatnosci). Ekran zgody pokazuje zakres
dostępu i link do polityki; token można unieważnić na `/oauth/connected`.

## 13. Powiązania

- [PRD.md](../PRD.md)
- [DOCUMENTATION.md](../DOCUMENTATION.md)
- [API.md](API.md)
- [ARCHITEKTURA.md](ARCHITEKTURA.md)
- [BAZA-DANYCH.md](BAZA-DANYCH.md)
- [DEPLOY.md](DEPLOY.md)
- [TESTOWANIE.md](TESTOWANIE.md)
- [AGENTS.md](../AGENTS.md)
- [Polityka prywatności](https://alphavault.pl/polityka-prywatnosci)
