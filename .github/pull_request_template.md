## Co zmieniono

<!-- Krótko: pliki, endpointy, tabele, UI. Wskaż punkt styku P1–P14, jeśli dotyczy. -->

## Dlaczego

<!-- Link do issue/PRD/FR-xx albo opis problemu. -->

## Ryzyko

<!-- Co może się zepsuć, jak rollback działa, czy zmiana dotyka danych/sekretów. -->

## Weryfikacja (wymagane)

- [ ] CI `PHP + API + UI` = `success` — link do runu:
- [ ] Deploy `Deploy AlphaVault` = `success` i krok `Verify production E2E` zielony — link:
- [ ] Produkcja: `/api/health` OK, `/docs/` 200, `.app/config.json` 403, `/api/events` 401
- [ ] `monitor-production.yml` = `success`
- [ ] Dokumentacja zaktualizowana wg `AGENTS.md` §8; `public/docs` wygenerowane przez `node scripts/sync-docs.mjs`
- [ ] Nie użyto lokalnego build/test/deploy jako dowodu

## Uwagi

<!-- Blokady, follow-upy, rzeczy do potwierdzenia przez człowieka. -->
