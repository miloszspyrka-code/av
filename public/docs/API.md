<!-- Wygenerowane z docs/API.md przez scripts/sync-docs.mjs. Nie edytuj ręcznie. -->

# Alpha Vault — API i logowanie

Wersja: **3.1.0** · backend: **PHP 8.3** · baza: **MySQL/MariaDB** · sesje: **HttpOnly cookie** · OAuth: **Discord, Garmin, Oura, Whoop**

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

Każde wydarzenie zawiera m.in. `id`, `url` (kanoniczny link `https://discord.com/events/{guild_id}/{event_id}`), `name`, `description`, `entityType`, `status`, `startAt` (UTC ISO), `startLocal` (`Europe/Warsaw` z offsetem), `startLabel`, `localDate`, `localTime`, `weekday`, `endAt`, `durationMinutes`, `host`, `userCount`, `category`, `categorySource`, `recurrenceRule`. `url` jest `null` wyłącznie dla rekordów z identyfikatorem spoza formatu Snowflake (fixtury testowe). `raw_payload` nie jest udostępniany.

### 3.9. `GET /api/admin/events`

Wymaga zalogowania i Discord ID z `ADMIN_DISCORD_IDS`. Zwraca wszystkie rekordy (także soft-deleted), stan synchronizacji i listę do przypisania.

### 3.10. `PUT /api/admin/events/{id}/category`

Wymaga administratora i poprawnego `Origin`.

```json
{ "category": "live" }
```

Dozwolone: `live`, `consultation`, `unassigned`. Ręczne przypisanie (`manual_category`) ma pierwszeństwo przed regułami i przetrwa kolejne synchronizacje.

### 3.11. Urządzenia zdrowotne

Wszystkie endpointy poza ingestem Apple wymagają sesji. Tokeny dostawców są szyfrowane AES-256-GCM i nigdy nie trafiają do odpowiedzi API.

```text
GET    /api/wearables                       lista urządzeń, dzisiejsze metryki, baseline, seria 90 dni, gotowość
POST   /api/wearables/{provider}/sync       ręczna synchronizacja (Garmin/Oura/Whoop/Apple)
DELETE /api/wearables/{provider}            odłączenie + usunięcie danych zdrowotnych
POST   /api/wearables/apple/pair            jednorazowy kod parowania dla iPhone (15 min)
POST   /api/wearables/apple/ingest          ingest z iPhone: kod parowania albo Bearer <deviceToken>
```

`GET /api/wearables` zwraca:

```json
{
  "devices": {
    "garmin": { "provider": "garmin", "label": "Garmin Connect", "configured": true, "connected": true, "lastSyncAt": "2026-11-12T19:48:00Z", "lastSyncStatus": "ok", "lastError": null },
    "apple": { "provider": "apple", "label": "Apple Watch · Zdrowie", "configured": true, "connected": false },
    "oura": { "provider": "oura", "label": "Oura Ring", "configured": true, "connected": false },
    "whoop": { "provider": "whoop", "label": "Whoop", "configured": true, "connected": false }
  },
  "today": {
    "date": "2026-11-12", "sleepMinutes": 312, "hrvMs": 38, "restingHr": 61,
    "bodyBattery": 34, "providerScore": null, "readiness": 65
  },
  "baseline": { "days": 14, "sleepMinutes": 450, "hrvMs": 52, "restingHr": 56 },
  "series": [ { "date": "2026-10-14", "sleepMinutes": 450, "hrvMs": 52, "restingHr": 56 } ],
  "readiness": {
    "score": 65, "title": "Dobra forma", "class": "win", "source": "devices",
    "components": { "sleep": 65, "hrv": 73, "restingHr": 75, "bodyBattery": 34, "physiology": 65 }
  }
}
```

Gotowość urządzenia to ważona średnia dostępnych składników: sen 40%, HRV 25%, tętno 10%, wynik regeneracji dostawcy 15%, Body Battery 10%. Wagi są renormalizowane do dostępnych danych. Check-in (jeśli istnieje) wnosi 30%: `0,7 × urządzenie + 0,3 × check-in`.

Apple ingest:

```text
POST /api/wearables/apple/ingest
Authorization: Bearer avw_...        # od drugiego żądania

{"deviceName":"iPhone","days":[{"date":"2026-11-12","sleepMinutes":312,"hrvMs":38,"restingHr":61,"bodyBattery":null,"providerScore":null}]}
```

Pierwsze żądanie zawiera `"code":"AV-XXXX-XXXX"` zamiast Bearer. Odpowiedź zawiera `deviceToken` tylko raz. Kod parowania jest jednorazowy, ważny 15 minut. Zakres pól: sen 0–1440 min, HRV 0–400 ms, tętno 20–220, Body Battery i wynik dostawcy 0–100; maks. 90 dni na żądanie.

Synchronizacja OAuth2:

```text
GET /auth/wearables/{garmin|oura|whoop}/start
GET /auth/wearables/{garmin|oura|whoop}/callback?code=...&state=...
```

`state` jest jednorazowy (10 min), powiązany z użytkownikiem i dostawcą. Po sukcesie redirect na `/?connected={provider}#cialo`; błąd synchronizacji nie blokuje połączenia i jest widoczny w `lastError`.

## 4. Bezpieczeństwo

- brak haseł użytkowników; tylko Discord OAuth2,
- sesje: token losowy, w DB tylko SHA-256, cookie HttpOnly/SameSite/Secure,
- tokeny urządzeń: AES-256-GCM z kluczem poza repo; token urządzenia Apple w DB tylko jako SHA-256,
- odłączenie urządzenia usuwa tokeny i wszystkie metryki zdrowotne z bazy,
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
| `WEARABLES_ENCRYPTION_KEY` | opcjonalny klucz AES dla tokenów urządzeń; domyślnie pochodna `SESSION_SECRET` |
| `OURA_CLIENT_ID`, `OURA_CLIENT_SECRET` | aplikacja Oura |
| `WHOOP_CLIENT_ID`, `WHOOP_CLIENT_SECRET` | aplikacja Whoop |
| `GARMIN_CLIENT_ID`, `GARMIN_CLIENT_SECRET` | aplikacja Garmin Health API |
| `GARMIN_AUTHORIZE_URL`, `GARMIN_TOKEN_URL`, `GARMIN_API_BASE` | endpointy przydzielone przez Garmin po zatwierdzeniu partnera |
| `OURA_AUTHORIZE_URL`, `OURA_TOKEN_URL`, `OURA_API_BASE` | opcjonalne nadpisania endpointów Oura |
| `WHOOP_AUTHORIZE_URL`, `WHOOP_TOKEN_URL`, `WHOOP_API_BASE` | opcjonalne nadpisania endpointów Whoop |
