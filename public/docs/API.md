<!-- Wygenerowane z docs/API.md przez scripts/sync-docs.mjs. Nie edytuj ręcznie. -->

# Alpha Vault — API i logowanie

Wersja: **3.0.0** · backend: **PHP 8.3** · baza: **MySQL/MariaDB** · sesje: **HttpOnly cookie** · OAuth: **Discord**

Aplikacja jest serwowana z tego samego originu co API. Frontend zapisuje cały stan użytkownika przez `PUT /api/state`; `localStorage` jest tylko fallbackiem offline.

## 1. Adresy

| Środowisko | Bazowy URL |
| --- | --- |
| Lokalne | `http://127.0.0.1:4173` |
| Produkcja | `https://alphavault.pl` |

API: `/api/*` · logowanie: `/auth/*` · UI: `/` · dokumentacja: `/docs/`.

## 2. Logowanie Discord

### 2.1. Przepływ

1. Użytkownik klika **Zaloguj przez Discord**.
2. `GET /auth/discord?remember=1` tworzy jednorazowy `state` w `oauth_states` i cookie `av_oauth`.
3. Redirect do `https://discord.com/api/oauth2/authorize` ze scope'ami:

   ```text
   identify guilds.members.read
   ```

4. Discord wraca na `GET /auth/discord/callback?code=...&state=...`.
5. Serwer sprawdza `state` (DB + cookie), wymienia `code` na token i pobiera `GET https://discord.com/api/users/@me`.
6. Sprawdza członkostwo i rolę:

   ```text
   GET https://discord.com/api/v10/users/@me/guilds/{DISCORD_GUILD_ID}/member
   Authorization: Bearer <access_token>
   ```

7. Brak członkostwa lub roli → HTTP 403, bez sesji.
8. Sukces → upsert `users`, sesja w MySQL, cookie `av_session`, redirect `/#/pulpit`.

### 2.2. Weryfikacja roli

Wymagane są `DISCORD_GUILD_ID` i `DISCORD_REQUIRED_ROLE_ID`. Backend **fail closed**: brak konfiguracji oznacza odmowę dostępu.

Do weryfikacji roli **nie jest używany** `DISCORD_BOT_TOKEN`; scope `guilds.members.read` pozwala odczytać członkostwo i role zalogowanego użytkownika. Token bota służy wyłącznie do synchronizacji scheduled events.

### 2.3. Tryb demo

Gdy `ALLOW_DEMO_LOGIN=true` (tylko lokalnie/CI):

```text
POST /auth/demo
Content-Type: application/json

{"remember": true}
```

Produkcja: `ALLOW_DEMO_LOGIN=false`, endpoint zwraca 403.

### 2.4. Wylogowanie

```text
POST /auth/logout
GET  /auth/logout
```

Usuwa sesję z MySQL i czyści cookie. GET przekierowuje na `/`.

### 2.5. Cookie sesji

| Atrybut | Wartość |
| --- | --- |
| Nazwa | `av_session` |
| Wartość | losowy token 32 B; w DB tylko SHA-256 |
| HttpOnly | tak |
| SameSite | `Lax` |
| Secure | tak przy HTTPS |
| Max-Age | 30 dni |
| Path | `/` |

## 3. Endpointy API

Wszystkie odpowiedzi API są JSON. Błędy:

```json
{ "error": "revision_conflict", "message": "Dane zmienione w innej karcie.", "revision": 12 }
```

### 3.1. `GET /api/health`

Publiczny. Sprawdza MySQL i konfigurację.

```json
{
  "ok": true,
  "service": "alpha-vault",
  "version": "3.0.0",
  "db": { "ok": true, "version": "11.8.6-MariaDB", "latencyMs": 1 },
  "uptime": 123,
  "now": "2026-11-12T14:30:00Z",
  "discordConfigured": true,
  "roleCheckConfigured": true,
  "eventsConfigured": true,
  "demoAllowed": false,
  "sync": {
    "status": "success",
    "lastSuccessAt": "2026-11-12 18:15:02",
    "eventsCount": 3,
    "stale": false
  }
}
```

Baza niedostępna → `503 {"ok":false,"error":"db_unavailable"}`.

`sync` to skrót stanu synchronizacji Discord: `status` (`success`/`error`/`never`), `lastSuccessAt` (UTC), `eventsCount` i `stale` (brak sukcesu > 15 min). Jest publiczny, bo zawiera wyłącznie metadane operacyjne — bez tokenów, treści wydarzeń i danych użytkowników.

### 3.2. `GET /api/me`

Publiczny. Zawsze `200`, także bez sesji.

```json
{
  "authenticated": true,
  "discordConfigured": true,
  "roleCheckConfigured": true,
  "eventsConfigured": true,
  "demoAllowed": false,
  "isAdmin": false,
  "db": "serwer445119_alphavault",
  "user": {
    "id": 1,
    "username": "kasia",
    "globalName": "Kasia",
    "avatarUrl": "https://cdn.discordapp.com/avatars/...",
    "email": null,
    "isDemo": false,
    "subscription": {
      "plan": "full",
      "accessUntil": "2026-12-03",
      "expired": false,
      "roleVerified": true
    }
  }
}
```

### 3.3. `GET /api/state`

Wymaga sesji (`401` bez). Zwraca stan JSON i rewizję.

```json
{
  "state": { "version": 2, "user": {}, "trades": [], "...": "..." },
  "revision": 12,
  "bytes": 41487,
  "updatedAt": "2026-11-12 14:30:00"
}
```

Nowe konto Discord: `state=null`. Konto demo bez stanu: seed z `db/demo-state.json`.

### 3.4. `PUT /api/state`

Wymaga sesji i poprawnego `Origin`.

```json
{
  "state": { "...": "..." },
  "baseRevision": 12
}
```

Odpowiedź:

```json
{ "ok": true, "revision": 13, "bytes": 41500, "updatedAt": "2026-11-12T14:31:00Z" }
```

| Kod | Znaczenie |
| --- | --- |
| 200 | zapisano |
| 400 | `state` nie jest obiektem |
| 401 | brak sesji |
| 403 | złe `Origin` |
| 409 | nieaktualne `baseRevision` |
| 413 | przekroczony `MAX_STATE_BYTES` |
| 429 | limit 180 zapisów/min |

### 3.5. `DELETE /api/state`

Czyści stan zalogowanego użytkownika. `200 {"ok":true}`.

### 3.6. `DELETE /api/account`

Trwale usuwa użytkownika (kaskadowo sesje i stan) i czyści cookie. `200 {"ok":true}`.

### 3.7. `GET /api/me/export`

Załącznik JSON z profilem, rewizją i całym stanem.

### 3.8. `GET /api/events`

Wymaga sesji. Zwraca wydarzenia Discord zsynchronizowane przez CRON oraz stan synchronizacji.

```json
{
  "events": [],
  "live": [],
  "consultations": [],
  "unassigned": [],
  "nextConsultation": null,
  "sync": {
    "lastAttemptAt": "2026-11-12 18:00:00",
    "lastSuccessAt": "2026-11-12 18:00:00",
    "status": "success",
    "eventsCount": 3,
    "updatedCount": 3,
    "durationMs": 240,
    "lastHttpStatus": 200,
    "lastError": null,
    "stale": false
  }
}
```

Każde wydarzenie zawiera m.in. `id`, `name`, `description`, `entityType`, `status`, `startAt` (UTC ISO), `startLocal` (`Europe/Warsaw` z offsetem), `startLabel`, `localDate`, `localTime`, `weekday`, `endAt`, `durationMinutes`, `host`, `userCount`, `category`, `categorySource`, `recurrenceRule`. `raw_payload` nie jest udostępniany.

### 3.9. `GET /api/admin/events`

Wymaga zalogowania i Discord ID z `ADMIN_DISCORD_IDS`. Zwraca wszystkie rekordy (także soft-deleted), stan synchronizacji i listę do przypisania.

### 3.10. `PUT /api/admin/events/{id}/category`

Wymaga administratora i poprawnego `Origin`.

```json
{ "category": "live" }
```

Dozwolone: `live`, `consultation`, `unassigned`. Ręczne przypisanie (`manual_category`) ma pierwszeństwo przed regułami i przetrwa kolejne synchronizacje.

### 3.11. `GET /api/connections`

Wymaga sesji. Zwraca połączenia użytkownika bez sekretów — tylko maskę klucza, status, uprawnienia i licznik importu.

```json
{
  "configured": true,
  "metaApiConfigured": true,
  "connections": [
    {
      "id": 12,
      "provider": "okx",
      "label": "OKX",
      "accountRef": "123456",
      "keyMask": "abcd••••wxyz",
      "permissions": "read-only",
      "status": "connected",
      "lastSyncAt": "2026-11-12 20:00:00",
      "lastSyncStatus": "success",
      "lastError": null,
      "importedCount": 2,
      "platform": null,
      "serverRef": null
    }
  ]
}
```

`configured:false` oznacza brak `INTEGRATIONS_KEY` na serwerze. Endpoint nie zwraca poświadczeń, pełnych kluczy ani hasła inwestora.

### 3.12. `POST /api/connections`

Wymaga sesji i poprawnego `Origin`. Body:

```json
{
  "provider": "okx",
  "fields": { "apiKey": "...", "secretKey": "...", "passphrase": "..." }
}
```

Obsługiwane providery: `weex`, `okx`, `bingx`, `mt4`, `mt5`. Dla MetaTrader pola: `platform`, `server`, `login`, `password`, `type`.

Serwer:

1. waliduje klucz na żywo u dostawcy,
2. odrzuca klucz z prawem handlu lub wypłat (`422 read_only_required`),
3. szyfruje poświadczenia AES-256-GCM (`INTEGRATIONS_KEY`),
4. zapisuje połączenie i zwraca `201` z zamaskowanym kluczem.

MetaTrader jest akceptowany wyłącznie przez MetaApi, gdy `investorMode:true`. Hasło z prawem handlu jest odrzucane, a konto usuwane z MetaApi.

### 3.13. `POST /api/connections/sync`

Wymaga sesji i `Origin`. Synchronizuje wszystkie połączenia użytkownika (opcjonalnie `{"provider":"okx"}`). Zwraca podsumowanie per połączenie: `fetched`, `imported`, `skipped`, `done`, `error`.

Import jest idempotentny — deduplikacja po `external_id` w `exchange_imports` i `extId` w stanie dziennika.

### 3.14. `POST /api/connections/{id}/sync`

Synchronizuje jedno połączenie. Używane po podłączeniu klucza i przez przycisk „Synchronizuj teraz”.

### 3.15. `DELETE /api/connections/{id}`

Wymaga sesji i `Origin`. Usuwa połączenie i poświadczenia. Transakcje pozostają w dzienniku; deduplikacja zachowuje się przy ponownym podłączeniu.

### 3.16. `POST /api/connections/mt/pair`

Wymaga sesji i `Origin`. Body `{"platform":"mt5"}`. Zwraca jednorazowo kod `AV-XXXX-XXXX` do EA. W bazie znajduje się tylko SHA-256 kodu.

### 3.17. `POST /api/mt/ea`

Publiczny endpoint dla Expert Advisor, uwierzytelniany kodem parowania (nie sesją). Body:

```json
{
  "code": "AV-7K3Q-92XD",
  "trades": [
    {
      "ticket": "123456",
      "symbol": "EURUSD",
      "side": "buy",
      "openTime": 1763000000,
      "closeTime": 1763003600,
      "lots": 0.5,
      "openPrice": 1.1,
      "closePrice": 1.105,
      "profit": 250,
      "commission": -2,
      "swap": -1
    }
  ]
}
```

Maks. 200 transakcji w żądaniu. Deduplikacja po `ticket`. Kod można wymienić, generując nowy; nowy kod zastępuje poprzednie połączenie MetaTrader.

### 3.18. `GET /api/health` — integracje

Pole `integrations` zawiera:

```json
{
  "configured": true,
  "metaApiConfigured": true,
  "sync": {
    "status": "success",
    "lastAttemptAt": "2026-11-12 20:00:00",
    "lastSuccessAt": "2026-11-12 20:00:00",
    "connections": 3,
    "imported": 7,
    "lastError": null
  }
}
```

## 4. Bezpieczeństwo

- brak haseł użytkowników; tylko Discord OAuth2,
- sesje: token losowy, w DB tylko SHA-256, cookie HttpOnly/SameSite/Secure,
- CSRF: `Origin` dla mutujących żądań + SameSite=Lax,
- rate limiting w MySQL,
- CSP, HSTS, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`,
- `config.json` w ukrytym `.app`, `600`, deny w `.htaccess`,
- brak sekretów w frontendzie i repo.

## 5. Zmienne konfiguracji

| Zmienna | Opis |
| --- | --- |
| `APP_URL` | publiczny URL, np. `https://alphavault.pl` |
| `APP_ENV` | `production` na produkcji |
| `SESSION_SECRET` | 64+ losowych znaków |
| `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASSWORD`, `DB_NAME` | MySQL |
| `DISCORD_CLIENT_ID` | Application ID |
| `DISCORD_CLIENT_SECRET` | OAuth2 Client Secret |
| `DISCORD_REDIRECT_URI` | `https://alphavault.pl/auth/discord/callback` |
| `DISCORD_GUILD_ID` | serwer Alpha Moves |
| `DISCORD_REQUIRED_ROLE_ID` | rola Alpha |
| `DISCORD_BOT_TOKEN` | bot read-only do scheduled events |
| `ADMIN_DISCORD_IDS` | Discord ID administratorów (po przecinku) |
| `DISCORD_DEFAULT_PLAN` | `solo` lub `full` |
| `DISCORD_TRIAL_DAYS` | 0 = bez daty końca |
| `ALLOW_DEMO_LOGIN` | `false` na produkcji |
| `MAX_STATE_BYTES` | limit stanu (12 MiB) |
| `INTEGRATIONS_KEY` | 32+ znaków; klucz AES-256-GCM do szyfrowania poświadczeń giełd |
| `METAAPI_TOKEN` | token MetaApi (tylko serwer); brak = MetaTrader niedostępny |
| `OKX_BASE`, `BINGX_BASE`, `WEEX_SPOT_BASE`, `WEEX_FUTURES_BASE` | opcjonalne nadpisania adresów API (testy/mock) |
| `METAAPI_BASE`, `METAAPI_CLIENT_BASE` | opcjonalne nadpisania MetaApi (testy/mock) |

## 12. MCP — tokeny i propozycje

Endpointy panelu wymagają sesji i poprawnego `Origin`. Nie wymagają CSRF tokenu (ochrona przez `Origin`), zgodnie z resztą API.

| Metoda | Ścieżka | Rola |
| --- | --- | --- |
| `GET` | `/api/mcp` | tokeny, oczekujące propozycje, audyt, flagi |
| `POST` | `/api/mcp/tokens` | wydanie tokenu; body: `{name, scopes:[\"panel:read\",\"panel:propose\"]}`; token widoczny raz |
| `POST` | `/api/mcp/tokens/revoke` | unieważnienie; body: `{id}` |
| `POST` | `/api/mcp/proposals/decide` | `{id, action: \"approve\"|\"reject\"}` |

Zatwierdzenie sprawdza właściciela propozycji, TTL, status `pending` i `base_revision`; niezgodność rewizji zwraca `409 revision_conflict`. Szczegóły polityki i narzędzi: [MCP.md](https://github.com/miloszspyrka-code/alphavault/blob/main/docs/MCP.md).
