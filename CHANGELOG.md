# Changelog

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
