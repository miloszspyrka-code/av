# Discord OAuth2 — konfiguracja

Aplikacja używa **Authorization Code Grant** i dwóch scope'ów:

```text
identify guilds.members.read
```

Do weryfikacji roli **nie jest potrzebny bot**. Bot jest natomiast używany wyłącznie do odczytu harmonogramu wydarzeń (`GET /guilds/{guild.id}/scheduled-events?with_user_count=true`) przez `DISCORD_BOT_TOKEN`. Bot ma tylko `View Channels`, bez uprawnień do zarządzania wydarzeniami i bez Privileged Gateway Intents.

Role są sprawdzane tokenem zalogowanego użytkownika:

```text
GET https://discord.com/api/v10/users/@me/guilds/{DISCORD_GUILD_ID}/member
Authorization: Bearer <access_token>
```

Szczegóły przepływu: [DOCUMENTATION.md §4](../DOCUMENTATION.md#4-uwierzytelnianie-sesje-i-csrf) i [§7](../DOCUMENTATION.md#7-discord-scheduled-events--pełny-przepływ).

## 1. Discord Developer Portal

1. <https://discord.com/developers/applications> → New Application.
2. OAuth2 → Client ID → `DISCORD_CLIENT_ID`.
3. OAuth2 → Client Secret → `DISCORD_CLIENT_SECRET`.
4. OAuth2 → Redirects → dodaj dokładnie:

   ```text
   https://alphavault.pl/auth/discord/callback
   ```

5. Zapisz.

Nie dodawaj scope'ów `email`, `guilds`, `messages` ani uprawnień do wysyłania wiadomości.

## 2. Serwer i rola

- `DISCORD_GUILD_ID`: PPM na serwerze Alpha Moves → Copy Server ID.
- `DISCORD_REQUIRED_ROLE_ID`: Ustawienia serwera → Role → PPM na roli Alpha → Copy Role ID.
- Użytkownik musi być członkiem serwera i mieć tę rolę.

Backend **fail closed**: brak `DISCORD_GUILD_ID` lub `DISCORD_REQUIRED_ROLE_ID` oznacza odmowę dostępu, nie wejście bez sprawdzania.

## 3. Bot do odczytu wydarzeń

Bot jest potrzebny wyłącznie do `GET /guilds/{guild.id}/scheduled-events?with_user_count=true`.

1. Discord Developer Portal → Bot → Add Bot.
2. Skopiuj token → `DISCORD_BOT_TOKEN` (GitHub secret / ukryty `.app`).
3. Uprawnienia: tylko **View Channels**. Bez Manage Events, bez Administratora.
4. Nie włączaj Privileged Gateway Intents.
5. Dodaj bota na serwer Alpha Moves.

Synchronizacja jest uruchamiana przez LH Cron co 5 minut. Jeśli token jest nieprawidłowy, wydarzenia w panelu pozostają ostatnim poprawnym zestawem, a `discord_sync_state.last_status=error`.

## 4. Sekrety

W GitHub Actions:

```text
DISCORD_CLIENT_ID
DISCORD_CLIENT_SECRET
DISCORD_REDIRECT_URI=https://alphavault.pl/auth/discord/callback
DISCORD_GUILD_ID
DISCORD_REQUIRED_ROLE_ID
DISCORD_BOT_TOKEN
ADMIN_DISCORD_IDS
```

Wartości nie są zapisywane w repo ani wysyłane do przeglądarki. `config.json` jest generowany na serwerze w ukrytym `.app`, zablokowanym przez `.htaccess`.

## 5. Przepływ

1. `GET /auth/discord?remember=1` tworzy `state` w `oauth_states` i cookie `av_oauth`.
2. Redirect do `https://discord.com/api/oauth2/authorize` ze scope'ami `identify guilds.members.read`.
3. `GET /auth/discord/callback` weryfikuje `state` (DB + cookie).
4. Wymiana `code` na token.
5. `GET /users/@me` → profil.
6. `GET /users/@me/guilds/{guild}/member` → role.
7. Brak członkostwa/roli → HTTP 403, bez sesji.
8. Sukces → `users` upsert, sesja w MySQL, cookie `av_session`, redirect `/#/pulpit`.

## 6. Test ręczny

1. Otwórz `https://alphavault.pl`.
2. Kliknij **Zaloguj przez Discord**.
3. Zaloguj konto z rolą Alpha → powinien pojawić się pulpit.
4. Wyloguj się i sprawdź, że `/api/state` zwraca 401.
5. Zaloguj konto bez roli → powinien pojawić się ekran 403.
6. Zaloguj konto spoza serwera → powinien pojawić się ekran 403.

Automatyczne testy nie symulują logowania Discord. Wymagają interaktywnego udziału użytkownika.

## 7. Typowe błędy

| Objaw | Przyczyna | Rozwiązanie |
| --- | --- | --- |
| `invalid_redirect_uri` | URL w panelu ≠ `DISCORD_REDIRECT_URI` | ustaw identyczny URL |
| `invalid_client` | zły Client Secret | reset secret i aktualizacja GitHub secret |
| 403 „Nie jesteś członkiem” | konto spoza serwera | dodaj konto do serwera |
| 403 „Brak roli Alpha” | brak wymaganej roli | nadaj rolę |
| 403 „Weryfikacja roli nie jest skonfigurowana” | brak Guild ID / Role ID | ustaw sekrety |
| 400 „Sesja logowania wygasła” | >10 min lub zły state | odśwież stronę |
| brak cookie | inny origin/HTTPS | `APP_URL` musi być `https://alphavault.pl` |
