<!-- Wygenerowane z docs/PROP.md przez scripts/sync-docs.mjs. Nie edytuj ręcznie. -->

# Prop challenge

Tryb pilnowania limitów prop firmy. Challenge nie wykonuje transakcji — liczy metryki z dziennika na podstawie wybranego konta i daty startu.

## 1. Model danych

Challenge jest zapisany w dokumencie stanu:

```json
{
  "prop": {
    "selectedId": "prop_ftmo_10k",
    "challenges": [
      {
        "id": "prop_ftmo_10k",
        "name": "FTMO 10K · Phase 1",
        "firm": "FTMO",
        "templateId": "ftmo-2step",
        "phase": "Phase 1",
        "account": "WEEX",
        "currency": "USD",
        "accountSize": 10000,
        "startBalance": 10000,
        "targetPct": 10,
        "dailyLossPct": 5,
        "maxLossPct": 10,
        "drawdownType": "static",
        "dailyBasis": "initial",
        "minDays": 4,
        "startDate": "2026-08-14",
        "endDate": "2026-11-22",
        "notes": "",
        "archived": false,
        "createdAt": "2026-11-12T08:00:00.000Z"
      }
    ]
  }
}
```

Pola:

| Pole | Znaczenie |
|---|---|
| `account` | nazwa konta z `state.accounts`; transakcje są dopasowane po nazwie (bez wielkości liter) |
| `accountSize` | rozmiar konta z regulaminu (informacyjny) |
| `startBalance` | saldo, od którego liczone są cel i limity |
| `targetPct` | cel zysku w % salda startowego |
| `dailyLossPct` | dzienny limit straty w % (0 = brak limitu) |
| `maxLossPct` | maksymalne obsunięcie w % |
| `drawdownType` | `static` — od salda startowego; `trailing` — od szczytu kapitału |
| `dailyBasis` | `initial` — limit od salda startowego; `dayStart` — od salda z początku dnia |
| `minDays` | minimalna liczba dni handlowych |
| `startDate` / `endDate` | zakres dat; `endDate` puste = firma bez limitu czasu |
| `archived` | ukryty w głównym widoku, dostępny przez „Archiwum” |

## 2. Szablony

Wbudowane szablony: FTMO 2-Step, FTMO 1-Step, FTMO Swing, FundedNext Stellar, FundingPips 2-Step, Topstep 50K Combine, Apex 50K Evaluation, Breakout 10K, własne zasady.

Szablony są **punktem startowym**, nie gwarancją regulaminu. Limity prop firm zmieniają się; każde pole można edytować przed zapisem. UI pokazuje zastrzeżenie przy wyborze szablonu i w karcie zarządzania.

## 3. Metryki

Dla challenge'u brane są **zamknięte** transakcje (`result != null`) spełniające wszystkie warunki: konto, data od `startDate` do `endDate` włącznie.

- **Kapitał** = `startBalance + suma wyników`.
- **Postęp celu** = `(kapitał - startBalance) / (startBalance * targetPct / 100) * 100%`.
- **Dzienny limit** = `max(0, -wynik_dzisiaj) / limit_dzienny * 100%`; limit zależy od `dailyBasis`.
- **Obsunięcie statyczne** = `max(0, startBalance - kapitał)`; **trailing** = `max(0, szczyt - kapitał)`.
- **Dni do końca** = liczba dni kalendarzowych do `endDate` włącznie; brak daty = brak terminu.
- **Dni handlowe** = liczba unikalnych dni z zamkniętą transakcją.

## 4. Statusy

| Status | Warunek |
|---|---|
| `active` | brak breacha, cel nieosiągnięty, termin nie minął |
| `target` | cel osiągnięty, ale brakuje min. dni handlowych |
| `passed` | cel osiągnięty i min. dni spełnione |
| `failed` | dzienny limit lub max obsunięcie osiągnięte (także po odbiciu) |
| `expired` | termin minął, cel nieosiągnięty |

## 5. Alerty

- 75% dziennego limitu lub obsunięcia — ostrzeżenie.
- 90% — alarm.
- 100% — breach, status `failed`.
- Cel osiągnięty, koniec terminu ≤ 3 dni, brak szans na min. dni — odpowiednie ostrzeżenia.

Alerty są widoczne w widoku prop, jako banner na pulpicie oraz (raz dziennie na challenge) jako toast i powiadomienie przeglądarki. Deduplikacja: `state.meta.propNotified[challengeId:data:kod]`.

## 6. Ograniczenia

- Silnik zna tylko zamknięte transakcje z dziennika. Nie widzi ruchów śróddziennych, MAE/MFE ani equity z platformy — przy ciasnych limitach może zaniżyć obsunięcie.
- Transakcja jest przypisywana po **nazwie konta**. Zmiana nazwy konta w ustawieniach wymaga edycji challenge'u.
- Trailing drawdown nie modeluje zatrzymania linii stopu po osiągnięciu progu (np. Apex) — to świadome uproszczenie; opisz wyjątek w notatce challenge'u.
- Szablony nie są poradą inwestycyjną ani kopią regulaminu.

## 7. Testy

- `npm run test:prop` — 27 przypadków jednostkowych silnika (limity, daty, static/trailing, statystyki).
- `npm run test:ui` — pełny przepływ: szablon → create → edycja → walidacja → XSS → alarm → archiwum → empty state → reload → delete.
- `npm test` — round-trip `state.prop` przez MySQL.

Testy można uruchomić na osobnej bazie i porcie:

```powershell
$env:DB_NAME="alpha_vault_prop"
$env:PORT="4187"
$env:APP_URL="http://127.0.0.1:4187"
npm start
# drugi terminal:
$env:APP_URL="http://127.0.0.1:4187"; npm run test:all
```
