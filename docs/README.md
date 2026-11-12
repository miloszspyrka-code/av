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
| [TESTOWANIE.md](TESTOWANIE.md) | testy API i UI, checklisty | tak |
| [DISCORD.md](DISCORD.md) | konfiguracja aplikacji i roli Alpha | nie |
| [DEPLOY.md](DEPLOY.md) | produkcja, reverse proxy, backup | nie |
| [MCP.md](MCP.md) | mapa narzędzi MCP, walidacje, OAuth, plan wdrożenia | nie |
| [LOCAL-DEPLOY.md](LOCAL-DEPLOY.md) | deploy z WSL2 bez GitHub Actions | nie |
| [CHANGELOG.md](../CHANGELOG.md) | historia zmian | nie |

Dokumentacja publiczna jest serwowana pod `/docs/`. Katalog `public/docs/` jest generowany z `docs/` oraz dokumentów głównych:

```powershell
npm run docs:sync
npm run test:docs
```

Nie edytuj `public/docs/` ręcznie. Dokumenty oznaczone „nie” są dostępne wyłącznie w repozytorium (linki w wersji publicznej prowadzą do GitHub).
