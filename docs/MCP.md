# AlphaVault MCP — OAuth 2.1, narzędzia i stan wdrożenia

> **Status: FAZA 1 WDROŻONA (2026-11-12).** Działa `https://mcp.alphavault.pl/mcp` z OAuth 2.1, PKCE S256, DCR, consentem, refresh/revocation i 8 narzędziami read-only. Narzędzia zapisujące są przygotowane architektonicznie, ale **nie są zarejestrowane** przy `MCP_WRITES_ENABLED=false`.
>
> Wersja: 3.1 · 2026-11-12
> Repozytorium: `miloszspyrka-code/av`
> Dokumentacja techniczna (repo): `docs/MCP.md`
>
> To **prywatny, własny (custom) serwer MCP** AlphaVault. Nie jest publikowany w katalogu OpenAI/Grok, nie jest serwerem federacyjnym ani oficjalnym connectorem. Dodaje się go jako własny MCP server w ustawieniach klienta.

## 1. Decyzja architektoniczna

MCP jest osobnym front controllerem PHP 8.3 na domenie `mcp.alphavault.pl`, korzystającym z tej samej bazy MySQL co AlphaVault. Nie ma stałego procesu Node.js, drugiej bazy ani VPS.

```text
ChatGPT / Claude / Grok / MCP Inspector
        │ HTTPS + OAuth 2.1 Bearer
        ▼
https://mcp.alphavault.pl/mcp
        │
        ├── OAuth 2.1 Authorization Server + Resource Server
        ├── MCP Streamable HTTP (JSON-RPC 2.0, JSON)
        ├── 8 narzędzi read-only
        └── MySQL serwer445119_alphavault
              users, sessions, user_states, discord_scheduled_events,
              discord_sync_state, audit_log, rate_limits + tabele mcp_*
```

Biblioteki (wersje przypięte w `composer.lock`):

| Pakiet | Rola |
| --- | --- |
| `mcp/sdk` v0.10.0 | serwer MCP, Streamable HTTP, resource server, scope policy |
| `league/oauth2-server` 9.4.1 | authorization code + PKCE, refresh, revocation, JWT |
| `nyholm/psr7`, `nyholm/psr7-server` | PSR-7 |
| `laminas/laminas-httphandlerrunner` | emisja odpowiedzi PSR-7 |

`mcp/sdk` jest resource serverem, a nie authorization serverem. Dlatego OAuth 2.1 jest realizowany przez `league/oauth2-server` w `app/Mcp/OAuth`, a SDK waliduje tokeny i egzekwuje scope.

## 2. Domena i produkcja

| Element | Wartość |
| --- | --- |
| Endpoint | `https://mcp.alphavault.pl/mcp` |
| DNS | `mcp.alphavault.pl` → `195.2.223.143` (LH.pl) |
| TLS | Let's Encrypt, CN `mcp.alphavault.pl`, ważny do 2027-02-10 |
| Docroot | `~/public_html/mcp.alphavault.pl` |
| App | `~/public_html/mcp.alphavault.pl/.app` |
| Health | `https://mcp.alphavault.pl/health` |
| Discovery | `/.well-known/oauth-protected-resource`, `/.well-known/oauth-authorization-server` |
| Stan | `enabled=true`, `writesEnabled=false`, 8 narzędzi read-only |

`mcpalphavault.pl` nie jest używana. Wcześniejszy raport o braku domeny dotyczył `mcpalphavault.pl`; `mcp.alphavault.pl` zostało dodane do LH i ma aktywny certyfikat.

## 3. OAuth 2.1

### 3.1. Role

- **Authorization Server:** `https://mcp.alphavault.pl` (`app/Mcp/OAuth/OAuthServer.php`).
- **Resource Server:** ten sam host; tokeny walidowane przez `TokenValidator` i SDK.
- **Upstream identity provider:** Discord OAuth2 (`identify guilds.members.read`).
- Discord access token **nie jest** przekazywany do MCP. AlphaVault wydaje własny token MCP.

### 3.2. Endpointy

| Endpoint | Opis |
| --- | --- |
| `GET /.well-known/oauth-protected-resource` | RFC 9728 |
| `GET /.well-known/oauth-protected-resource/mcp` | wariant path-specific |
| `GET /.well-known/oauth-authorization-server` | RFC 8414 |
| `GET /oauth/jwks.json` | klucz publiczny RSA |
| `POST /oauth/register` | Dynamic Client Registration |
| `GET /oauth/authorize` | ekran zgody |
| `POST /oauth/authorize` | allow/deny |
| `POST /oauth/token` | code+PKCE, refresh |
| `POST /oauth/revoke` | unieważnienie tokenu |
| `GET /oauth/login` | brandowana strona logowania AlphaVault |
| `GET /oauth/login/start` | start logowania Discord |
| `GET /oauth/discord/callback` | callback Discord |
| `GET /oauth/connected` | lista połączonych klientów |
| `POST /oauth/connected/revoke` | unieważnienie wszystkich tokenów klienta |

### 3.3. Parametry

- Authorization Code + PKCE `S256`; public client (`token_endpoint_auth_method=none`) lub confidential (`client_secret_post`).
- `iss` w każdej odpowiedzi autoryzacyjnej; `authorization_response_iss_parameter_supported=true`.
- `resource` (RFC 8707) walidowany; token ma `aud=https://mcp.alphavault.pl/mcp`.
- Access token: 1 h, JWT RS256, `typ=at+jwt`, `kid`.
- Refresh token: 30 dni, rotacja; ponowne użycie starego tokenu kończy się `invalid_grant`.
- DCR: dokładne `redirect_uris`, HTTPS, allowlista hostów (`chatgpt.com`, `openai.com`, `grok.com`, `x.ai`, `claude.ai`, `claude.com`); `localhost` tylko poza produkcją. Claude używa `https://claude.ai/api/mcp/auth_callback`.
- Tokeny w `Authorization: Bearer`; nigdy w URL, logach, wynikach narzędzi ani repo.
- 401 z `WWW-Authenticate: Bearer resource_metadata="...", scope="alphavault.read"`.
- 403 z `insufficient_scope` przy braku uprawnienia.

### 3.4. Logowanie Discord i konta

1. Użytkownik trafia na `/oauth/authorize`.
2. Brak sesji MCP → `/oauth/login` (brandowana strona AlphaVault) → Discord.
3. Callback sprawdza `state` i cookie `mcp_login`.
4. `Discord::checkRole()` weryfikuje członkostwo w `DISCORD_GUILD_ID` i rolę `DISCORD_REQUIRED_ROLE_ID`.
5. Brak roli/członkostwa → **403, bez tworzenia konta**.
6. Sukces → `UserService::upsertDiscord()`:
   - dopasowanie **wyłącznie po Discord User ID**,
   - istniejące konto aktualizowane, bez duplikatu,
   - nowe konto tworzone automatycznie (plan z `DISCORD_DEFAULT_PLAN`, trial z `DISCORD_TRIAL_DAYS`).
7. Sesja `mcp_session` w tabeli `sessions`; użytkownik widzi consent.

Nie ma listy pilotowych Discord ID ani ręcznej allowlisty. Dostęp wynika z tej samej polityki co aplikacja: członkostwo + rola + zgoda OAuth.

## 4. Narzędzia MCP

### 4.1. Aktywne (read-only)

| Narzędzie | Wejście | Wyjście |
| --- | --- | --- |
| `get_profile` | `{}` | stabilne `id`, nazwa, plan, dostęp, rola, admin; `_meta["openai/profile"]=true` |
| `get_dashboard` | `{}` | dzisiejszy PnL, transakcje, otwarte pozycje, ryzyko, plan, gotowość, tryb ochronny, kapitał, najbliższy event, sync |
| `list_trades` | `status`, `pair`, `setup`, `grade`, `from`, `to`, `limit`, `offset` | lista bez screenów, `total`, `nextOffset` |
| `get_trade` | `id` | pełne szczegóły, `found`, bez base64 |
| `get_stats` | `period` (`7d/30d/90d/all`) | netto, win rate, PF, expectancy R, max DD, opłaty, plan rate, byGrade, bySetup |
| `get_events` | `category`, `from`, `to`, `limit` | live/konsultacje + `sync`; bez `raw_payload` |
| `get_day_plan` | `date` | plan lub `null` |
| `list_setups` | `{}` | setupy + statystyki |

Wszystkie narzędzia mają `inputSchema`, `outputSchema`, `annotations.readOnlyHint=true`, `openWorldHint=false` i `_meta.securitySchemes=[{type:"oauth2",scopes:["alphavault.read"]}]`.

### 4.2. Wyłączone (faza 2)

`create_trade`, `close_trade`, `save_day_plan`, `create_checkin` są zaprojektowane i mają osobny scope `alphavault.write`, ale **nie są rejestrowane**, dopóki `MCP_WRITES_ENABLED=false`. SDK nie wystawia ich w `tools/list`, a AS nie przyznaje scope write.

### 4.3. Izolacja danych

Każde narzędzie pobiera `sub` z zwalidowanego tokenu i ładuje wyłącznie `user_states` tego użytkownika. Nie istnieje parametr `user_id`. `get_trade` nie znajdzie transakcji innego konta. `get_stats`, `get_dashboard` i `list_trades` działają na tym samym `user_id`.

Wyniki nie zawierają:

- `raw_payload`,
- danych base64 screenów (tylko `screensCount`),
- tokenów, sekretów, `DISCORD_BOT_TOKEN`,
- SQL ani stack trace.

## 5. Walidacje i bezpieczeństwo

| Obszar | Reguła |
| --- | --- |
| Transport | `POST /mcp`, JSON-RPC 2.0, JSON, body ≤ 64 KiB, GET/DELETE/OPTIONS → 405 |
| Host/Origin | `DnsRebindingProtectionMiddleware`; duplikat `Host` z LH normalizowany, różne wartości → 400 |
| Token | JWT RS256, `typ=at+jwt`, `kid`, `iss`, `aud`, `exp`, `jti`, revocation w DB |
| Scope | `alphavault.read`; `alphavault.write` tylko po włączeniu fazy 2 |
| Rate limit | 120 req/min/token; DCR 10/h/IP; logowanie 20/min/IP |
| Stan | tylko odczyt w fazie 1; przyszłe zapisy: transakcja + `FOR UPDATE` + `revision` + idempotencja |
| Dane | puste = `[]`/`null`; brak tekstów zastępczych; czasy UTC + `Europe/Warsaw` |
| Sekrety | `config.json` 600 w `.app`; klucze 600; `.htaccess` deny; brak sekretów w repo/logach |

## 6. Baza danych

Nowe tabele (addytywne, w `db/schema.sql`):

| Tabela | Przeznaczenie |
| --- | --- |
| `mcp_clients` | klienci DCR, redirect URI, opcjonalny secret hash |
| `mcp_auth_codes` | kody autoryzacyjne, TTL, revocation |
| `mcp_access_tokens` | `jti`, user, scope, audience, expiry, revocation |
| `mcp_refresh_tokens` | rotacja refresh tokenów |
| `mcp_login_requests` | stan logowania Discord |
| `mcp_consents` | ekran zgody i CSRF |
| `mcp_idempotency` | przyszłe zapisy fazy 2 |

Współdzielone: `users`, `sessions`, `user_states`, `audit_log`, `rate_limits`, `discord_scheduled_events`, `discord_sync_state`.

## 7. Wdrożenie

Skrypt: `scripts/deploy-mcp.sh` (dry-run → deploy). Wymaga `LH_MCP_DEPLOY_PATH=~/public_html/mcp.alphavault.pl`, `LH_MCP_APP_PATH=.../.app`, `MCP_APP_URL=https://mcp.alphavault.pl`, sekretów DB/Discord i `SESSION_SECRET`.

Kroki:

1. `rsync mcp/public/` → docroot.
2. `rsync app/`, `db/`, `bin/`, `config/`, `vendor/` → `.app`.
3. `config.json` 600 z `render-mcp-config.mjs`; `SESSION_COOKIE=mcp_session`; `MCP_WRITES_ENABLED=false`.
4. `php .app/app/db/migrate.php`.
5. `php .app/bin/mcp-init.php` — generuje klucze RSA i klucz szyfrowania, jeśli brak.
6. Health check: `/health`, PRM, `POST /mcp` bez tokenu → 401.

Rollback: `MCP_ENABLED=false`, unieważnienie tokenów (`mcp_access_tokens.revoked=1`, `mcp_refresh_tokens.revoked=1`), restore `.app` z backupu. Tabele MCP są addytywne.

## 8. Testy i weryfikacja

Uruchomione 2026-11-12:

| Test | Wynik |
| --- | --- |
| `php -l` wszystkich plików MCP | PASS |
| `composer validate --strict` | PASS |
| `npm run test:mcp` (lokalnie) | PASS |
| `npm run test:mcp:login` (fake Discord: rola, brak roli, brak członkostwa, duplikat konta) | PASS |
| `npm run test:docs` | PASS |
| Produkcyjny E2E `MCP_E2E_REMOTE=1` (test users, OAuth, 8 tools, izolacja, refresh, revoke) | PASS |
| MCP Inspector CLI `tools/list --strict` | PASS, 0 errors/0 warnings |
| DCR + authorize + login → Discord (produkcja) | PASS, Discord 302, redirect URI zaakceptowany |
| Produkcja `/mcp` bez tokenu | 401 + `WWW-Authenticate` |

Nie wykonano interaktywnego logowania prawdziwym kontem Discord przez agenta (brak danych logowania). Produkcja ma potwierdzone wcześniej logowanie z rolą i odmowę bez roli w `audit_log`; MCP używa tej samej funkcji `Discord::checkRole()`.

## 9. Monitoring i utrzymanie

- `GET /health` → `{ok, service:"alphavault-mcp", db, mcp:{enabled,writesEnabled,issuer,resource,tools}}`.
- `monitor-production.yml` sprawdza health MCP, OAuth discovery i 401 bez tokenu co 15 min.
- `bin/mcp-maintenance.php` — dzienny Cron: usuwa wygasłe kody, consenty, login requesty i idempotencję.
- Logi: `.app/logs/mcp.log`; bez tokenów i treści notatek.

## 10. Pozostałe fazy

### Faza 2 — zapisy

- `create_trade`, `close_trade`, `save_day_plan`, `create_checkin`.
- Osobny scope `alphavault.write`, `MCP_WRITES_ENABLED=true` dopiero po testach.
- Transakcje MySQL, `FOR UPDATE`, `revision`, idempotencja, tryb ochronny, limity ryzyka.
- E2E na dwóch kontach; UI musi czytać stan zapisany przez MCP.

### Faza 3 — klienci

- Instrukcje połączenia: OpenCode, ChatGPT, Claude, Grok.
- Rzeczywisty test OAuth i wywołań narzędzi w każdym kliencie.
- Jeśli klient nie wspiera DCR/OAuth, udokumentować przyczynę i wariant zgodności.

### Faza 4 — produkcja

- Monitoring, rotacja kluczy, test obciążenia, finalne E2E.

## 11. Powiązania

- [PRD.md](../PRD.md) — wymagania produktowe.
- [DOCUMENTATION.md](../DOCUMENTATION.md) — działanie aplikacji.
- [docs/API.md](API.md) — HTTP API AlphaVault.
- [docs/BAZA-DANYCH.md](BAZA-DANYCH.md) — schemat MySQL.
- [docs/DEPLOY.md](DEPLOY.md) — wdrożenie LH.pl.
- [docs/TESTOWANIE.md](TESTOWANIE.md) — testy.
- [AGENTS.md](../AGENTS.md) — kontrakt pracy.

## 12. Instrukcje klientów

Wspólne:

- URL: `https://mcp.alphavault.pl/mcp`
- Metoda: OAuth 2.1 (Authorization Code + PKCE), bez API key.
- Po zatwierdzeniu zgody klient widzi 8 narzędzi read-only.
- Aby rozłączyć: `https://mcp.alphavault.pl/oauth/connected` → „Unieważnij dostęp”.

### OpenCode

```bash
opencode mcp add alphavault --url https://mcp.alphavault.pl/mcp
opencode mcp auth alphavault
```

Następnie `/mcps` → `alphavault` → sign in. OpenCode użyje DCR, bo serwer nie ogłasza CIMD. OAuth jest domyślny dla remote.

### ChatGPT

1. Ustawienia → Plugins/Connectors → dodaj własny MCP server.
2. URL: `https://mcp.alphavault.pl/mcp`.
3. Uwierzytelnianie: OAuth; zatwierdź consent w przeglądarce.
4. Sprawdź `tools/list` i wywołaj `get_profile`.

Custom connectors mogą wymagać odpowiedniego planu/workspace. To ograniczenie klienta, nie serwera.

### Claude

1. Ustawienia → Connectors → Add custom connector → Web.
2. URL: `https://mcp.alphavault.pl/mcp`.
3. Uwierzytelnianie: OAuth; zatwierdź consent w przeglądarce.
4. Callback Claude: `https://claude.ai/api/mcp/auth_callback` (dopuszczony w allowliście DCR).
5. Sprawdź `tools/list` i wywołaj `get_profile`.

### Grok

1. `https://grok.com/connectors` → New Connector → Custom.
2. URL: `https://mcp.alphavault.pl/mcp`.
3. Uwierzytelnianie: OAuth; zatwierdź consent.
4. Sprawdź narzędzia i `get_profile`.

Grok może wymagać API key zamiast OAuth w niektórych wariantach. Serwer nie wystawia obecnie statycznego PAT; API-key fallback jest w fazie 3. Nie deklaruj zgodności bez rzeczywistego testu w kliencie.
