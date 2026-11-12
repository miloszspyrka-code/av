# Discord OAuth2 â€” konfiguracja

Aplikacja uĹĽywa **Authorization Code Grant** i dwĂłch scope'Ăłw:

```text
identify guilds.members.read
```

Do weryfikacji roli **nie jest potrzebny bot**. Bot jest natomiast uĹĽywany wyĹ‚Ä…cznie do odczytu harmonogramu wydarzeĹ„ (`GET /guilds/{guild.id}/scheduled-events?with_user_count=true`) przez `DISCORD_BOT_TOKEN`. Bot ma tylko `View Channels`, bez uprawnieĹ„ do zarzÄ…dzania wydarzeniami i bez Privileged Gateway Intents.

Role sÄ… sprawdzane tokenem zalogowanego uĹĽytkownika:

```text
GET https://discord.com/api/v10/users/@me/guilds/{DISCORD_GUILD_ID}/member
Authorization: Bearer <access_token>
```

## 1. Discord Developer Portal

1. <https://discord.com/developers/applications> â†’ New Application.
2. OAuth2 â†’ Client ID â†’ `DISCORD_CLIENT_ID`.
3. OAuth2 â†’ Client Secret â†’ `DISCORD_CLIENT_SECRET`.
4. OAuth2 â†’ Redirects â†’ dodaj dokĹ‚adnie:

   ```text
   https://alphavault.pl/auth/discord/callback
   ```

5. Zapisz.

Nie dodawaj scope'Ăłw `email`, `guilds`, `messages` ani uprawnieĹ„ do wysyĹ‚ania wiadomoĹ›ci.

## 2. Serwer i rola

- `DISCORD_GUILD_ID`: PPM na serwerze Alpha Moves â†’ Copy Server ID.
- `DISCORD_REQUIRED_ROLE_ID`: Ustawienia serwera â†’ Role â†’ PPM na roli Alpha â†’ Copy Role ID.
- UĹĽytkownik musi byÄ‡ czĹ‚onkiem serwera i mieÄ‡ tÄ™ rolÄ™.

Backend **fail closed**: brak `DISCORD_GUILD_ID` lub `DISCORD_REQUIRED_ROLE_ID` oznacza odmowÄ™ dostÄ™pu, nie wejĹ›cie bez sprawdzania.

## 3. Bot do odczytu wydarzeĹ„

Bot jest potrzebny wyĹ‚Ä…cznie do `GET /guilds/{guild.id}/scheduled-events?with_user_count=true`.

1. Discord Developer Portal â†’ Bot â†’ Add Bot.
2. Skopiuj token â†’ `DISCORD_BOT_TOKEN` (GitHub secret / config poza docrootem).
3. Uprawnienia: tylko **View Channels**. Bez Manage Events, bez Administratora.
4. Nie wĹ‚Ä…czaj Privileged Gateway Intents.
5. Dodaj bota na serwer Alpha Moves.

Synchronizacja jest uruchamiana przez LH Cron co 5 minut. JeĹ›li token jest nieprawidĹ‚owy, wydarzenia w panelu pozostajÄ… ostatnim poprawnym zestawem, a `discord_sync_state.last_status=error`.

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

WartoĹ›ci nie sÄ… zapisywane w repo ani wysyĹ‚ane do przeglÄ…darki. `config.json` jest generowany na serwerze poza docrootem.

## 5. PrzepĹ‚yw

1. `GET /auth/discord?remember=1` tworzy `state` w `oauth_states` i cookie `av_oauth`.
2. Redirect do `https://discord.com/api/oauth2/authorize` ze scope'ami `identify guilds.members.read`.
3. `GET /auth/discord/callback` weryfikuje `state` (DB + cookie).
4. Wymiana `code` na token.
5. `GET /users/@me` â†’ profil.
6. `GET /users/@me/guilds/{guild}/member` â†’ role.
7. Brak czĹ‚onkostwa/roli â†’ HTTP 403, bez sesji.
8. Sukces â†’ `users` upsert, sesja w MySQL, cookie `av_session`, redirect `/#/pulpit`.

## 6. Test rÄ™czny

1. OtwĂłrz `https://alphavault.pl`.
2. Kliknij **Zaloguj przez Discord**.
3. Zaloguj konto z rolÄ… Alpha â†’ powinien pojawiÄ‡ siÄ™ pulpit.
4. Wyloguj siÄ™ i sprawdĹş, ĹĽe `/api/state` zwraca 401.
5. Zaloguj konto bez roli â†’ powinien pojawiÄ‡ siÄ™ ekran 403.
6. Zaloguj konto spoza serwera â†’ powinien pojawiÄ‡ siÄ™ ekran 403.

Automatyczne testy nie symulujÄ… logowania Discord. WymagajÄ… interaktywnego udziaĹ‚u uĹĽytkownika.

## 7. Typowe bĹ‚Ä™dy

| Objaw | Przyczyna | RozwiÄ…zanie |
| --- | --- | --- |
| `invalid_redirect_uri` | URL w panelu â‰  `DISCORD_REDIRECT_URI` | ustaw identyczny URL |
| `invalid_client` | zĹ‚y Client Secret | reset secret i aktualizacja GitHub secret |
| 403 â€žNie jesteĹ› czĹ‚onkiemâ€ť | konto spoza serwera | dodaj konto do serwera |
| 403 â€žBrak roli Alphaâ€ť | brak wymaganej roli | nadaj rolÄ™ |
| 403 â€žWeryfikacja roli nie jest skonfigurowanaâ€ť | brak Guild ID / Role ID | ustaw sekrety |
| 400 â€žSesja logowania wygasĹ‚aâ€ť | >10 min lub zĹ‚y state | odĹ›wieĹĽ stronÄ™ |
| brak cookie | inny origin/HTTPS | `APP_URL` musi byÄ‡ `https://alphavault.pl` |
