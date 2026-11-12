<!-- Wygenerowane z docs/README.md przez scripts/sync-docs.mjs. Nie edytuj ręcznie. -->

# Dokumentacja Alpha Vault

| Dokument | Zawartość | Publiczna |
|---|---|---|
| [README projektu](https://github.com/miloszspyrka-code/alphavault/blob/main/README.md) | szybki start, status, skrypty | nie |
| [PRD.md](PRD.md) | wymagania produktowe, FR-xx, kryteria akceptacji | tak |
| [DOCUMENTATION.md](DOCUMENTATION.md) | jak działa aplikacja, punkty styku P1–P14 | tak |
| [AGENTS.md](https://github.com/miloszspyrka-code/alphavault/blob/main/AGENTS.md) | kontrakt agenta AV-engineer | nie |
| [API.md](API.md) | endpointy, OAuth Discord, sesje, błędy, przykłady | tak |
| [openapi.yaml](openapi.yaml) | specyfikacja OpenAPI 3.1 | tak |
| [ARCHITEKTURA.md](ARCHITEKTURA.md) | warstwy, przepływy, decyzje, rozszerzenia | tak |
| [BAZA-DANYCH.md](BAZA-DANYCH.md) | MySQL, schemat, backup, bezpieczeństwo | tak |
| [FUNKCJE.md](FUNKCJE.md) | co działa e2e, co jest demo | tak |
| [PROP.md](PROP.md) | prop challenge: model danych, limity, statusy, testy | tak |
| [TESTOWANIE.md](TESTOWANIE.md) | testy prop, API i UI, checklisty | tak |
| [DISCORD.md](https://github.com/miloszspyrka-code/alphavault/blob/main/docs/DISCORD.md) | konfiguracja aplikacji i roli Alpha | nie |
| [DEPLOY.md](https://github.com/miloszspyrka-code/alphavault/blob/main/docs/DEPLOY.md) | produkcja, reverse proxy, backup | nie |
| [MCP.md](https://github.com/miloszspyrka-code/alphavault/blob/main/docs/MCP.md) | OAuth 2.1, narzędzia MCP, walidacje, wdrożenie | nie |
| [LOCAL-DEPLOY.md](https://github.com/miloszspyrka-code/alphavault/blob/main/docs/LOCAL-DEPLOY.md) | deploy z WSL2 bez GitHub Actions | nie |
| [CHANGELOG.md](https://github.com/miloszspyrka-code/alphavault/blob/main/CHANGELOG.md) | historia zmian | nie |

Dokumentacja publiczna jest serwowana pod `/docs/`. Katalog `public/docs/` jest generowany z `docs/` oraz dokumentów głównych:

```powershell
npm run docs:sync
npm run test:docs
```

Nie edytuj `public/docs/` ręcznie. Dokumenty oznaczone „nie” są dostępne wyłącznie w repozytorium (linki w wersji publicznej prowadzą do GitHub).
