## Co zmieniono

MCP 2.0 na aktualnym `main`:

- OAuth 2.1 + PKCE S256, DCR, refresh rotation, revoke, discovery, logowanie Discord na `mcp.alphavault.pl`.
- 15 narzędzi odczytu: `get_macro_calendar`, `get_prop_challenges`, `get_checkin_history`, `get_accounts`, `get_capital_summary`, `get_connection_status`, `get_trade_context` oraz dotychczasowe 8.
- 7 narzędzi `propose_*`: transakcja, zamknięcie, recenzja, plan dnia, check-in, setup, prop challenge.
- `mcp_proposals` z `payload_hash`, TTL 30 min, idempotencją, `SELECT ... FOR UPDATE`, kontrolą `base_revision` i audytem.
- Panel AlphaVault: lista propozycji, diff, Zatwierdź/Odrzuć, obsługa konfliktu rewizji.
- Ochrona danych giełdowych: brak zmian pól finansowych, recenzja w `tradeReviews`, odmowa dla `exchange_imports`.
- Prompts: `prepare_trading_day`, `review_trade`, `weekly_review`.
- `MCP_WRITES_ENABLED=false` ukrywa narzędzia zapisujące i blokuje scope `alphavault.write`.
- Migracja addytywna `20261114_mcp2.sql`; tabele OAuth w `db/schema.sql`.
- Testy PHP, MCP E2E read/write, mock logowania Discord, panel Playwright, parytet prop PHP/JS.
- Dokumentacja: `docs/MCP.md`, API, baza, funkcje, architektura, testy, deploy, PRD, AGENTS.

Punkty styku: P1 (UI), P2 (API), P6 (Discord OAuth/login), P10 (baza), P12 (deploy), P13 (testy), P14 (MCP).

## Dlaczego

FR-21: agenci AI mają czytać pełny kontekst tradingowy i przygotowywać zmiany, ale zapis wymaga decyzji użytkownika. Dane giełdowe pozostają niezmienne.

## Ryzyko

- Nowe tabele i migracja są addytywne; rollback kodu nie usuwa danych.
- `MCP_WRITES_ENABLED=false` w produkcji; włączenie zapisów wymaga osobnej zgody i deployu.
- Zmiana `DISCORD_API_BASE`/`DISCORD_OAUTH_BASE`/`DISCORD_USER_BASE` zachowuje dotychczasowe wartości domyślne.
- `alphavault.pl` nie wdraża `vendor/`; panel MCP wymaga tylko `app/Mcp/Proposals` i `Dates`.

## Weryfikacja (wymagane)

- [ ] CI `PHP + API + UI` = `success` — link do runu:
- [ ] Deploy `Deploy AlphaVault` = `success` i krok `Verify production E2E` zielony — link:
- [ ] Produkcja: `/api/health` OK, `/docs/` 200, `.app/config.json` 403, `/api/events` 401
- [ ] `monitor-production.yml` = `success`
- [ ] Dokumentacja zaktualizowana wg `AGENTS.md` §8; `public/docs` wygenerowane przez `node scripts/sync-docs.mjs`
- [ ] Nie użyto lokalnego build/test/deploy jako dowodu

## Uwagi

MCP deploy pozostaje `MCP_WRITES_ENABLED=false`; 7 narzędzi `propose_*` będzie ukryte do czasu osobnej decyzji.
