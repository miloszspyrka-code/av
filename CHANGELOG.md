# Changelog

## 3.0.0 — 2026-11-12

### Zmienione
- Backend przepisany z Express 4 / Node.js na PHP 8.3.
- Produkcja na LH.pl: Apache + PHP-FPM, bez procesu Node.
- Schemat MySQL/MariaDB: `utf8mb4_unicode_ci`, tabela `rate_limits`.
- Weryfikacja roli Discord przez OAuth `guilds.members.read` (bez bota i `DISCORD_BOT_TOKEN`).
- Deployment GitHub Actions: SSH/rsync, backup, migracja PHP, health check, rollback.
- `config.json` generowany z sekretów poza publicznym docrootem.
- CI: PHP lint + MySQL 8.4 + API/UI e2e.

### Zachowane
- Dotychczasowy frontend, widoki, wykresy, modale i funkcje biznesowe.
- Kontrakty API `/api/health`, `/api/me`, `/api/state`, `/auth/discord`, `/auth/discord/callback`.
- Sesje, CSRF, rate limiting, audit log, eksport JSON.

## 2.1.0 — 2026-11-12

- Backend Node.js/Express, MySQL 8.4, Discord OAuth2, demo login, testy API/UI.
- Poprzednia wersja produkcyjna; kod zachowany w `legacy/`.
