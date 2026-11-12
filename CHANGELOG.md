# Changelog

## 3.1.0 — 2026-11-12

### Dodane
- `AGENTS.md` — kontrakt agenta AV-engineer.
- `PRD.md` — wymagania produktowe, FR-xx i kryteria akceptacji.
- `DOCUMENTATION.md` — działanie aplikacji i punkty styku P1–P14.
- `scripts/sync-docs.mjs` — generowanie `public/docs` ze źródeł.
- `tests/docs.test.mjs` — test linków, kodowania i spójności dokumentacji.
- `config/discord_event_rules.example.json` — przykład reguł klasyfikacji.

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
- LH Cron pozostaje do dodania w panelu LH.

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
