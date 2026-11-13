# Dokumentacja Alpha Vault

| Dokument | Zawartość | Publiczna |
|---|---|---|
| [README projektu](../README.md) | szybki start, status, skrypty | nie |
| [PRD.md](../PRD.md) | wymagania produktowe, FR-xx, kryteria akceptacji | tak |
| [DOCUMENTATION.md](../DOCUMENTATION.md) | jak działa aplikacja, punkty styku P1–P14 | tak |
| [AGENTS.md](../AGENTS.md) | kontrakt agenta AV-engineer | nie |
| [API.md](API.md) | endpointy, OAuth Discord, sesje, błędy, przykłady | tak |
| [openapi.yaml](openapi.yaml) | specyfikacja OpenAPI 3.1 | tak |
| [ARCHITEKTURA.md](ARCHITEKTURA.md) | warstwy, przepływy, decyzje, rozszerzenia | tak |
| [BAZA-DANYCH.md](BAZA-DANYCH.md) | MySQL, schemat, backup, bezpieczeństwo | tak |
| [FUNKCJE.md](FUNKCJE.md) | co działa e2e, co jest demo | tak |
| [AUDYT-UZYTECZNOSCI-2026-11-13.md](AUDYT-UZYTECZNOSCI-2026-11-13.md) | audyt funkcjonalno-użytkowy, priorytety P0/P1/P2 | nie |
| [PROP.md](PROP.md) | prop challenge: model danych, limity, statusy, testy | tak |
| [TESTOWANIE.md](TESTOWANIE.md) | testy prop, API i UI, checklisty | tak |
| [DISCORD.md](DISCORD.md) | konfiguracja aplikacji i roli Alpha | nie |
| [DEPLOY.md](DEPLOY.md) | produkcja, reverse proxy, backup | nie |
| [MCP.md](MCP.md) | MCP: OAuth 2.1, 8 narzędzi read-only, walidacje, wdrożenie | nie |
| [CHANGELOG.md](../CHANGELOG.md) | historia zmian | nie |

Dokumentacja publiczna jest serwowana pod `/docs/`. Katalog `public/docs/` jest generowany z `docs/` oraz dokumentów głównych:

```powershell
node scripts/sync-docs.mjs
```

CI sprawdza dryf (`node scripts/sync-docs.mjs --check`) i linki (`node tests/docs.test.mjs`). Nie edytuj `public/docs/` ręcznie. Dokumenty oznaczone „nie” są dostępne wyłącznie w repozytorium (linki w wersji publicznej prowadzą do GitHub).
