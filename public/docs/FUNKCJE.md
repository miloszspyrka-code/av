<!-- Wygenerowane z docs/FUNKCJE.md przez scripts/sync-docs.mjs. Nie edytuj ręcznie. -->

# Funkcje aplikacji

Aplikacja zachowuje dotychczasowy UI (ciemny motyw Alpha, wszystkie widoki i modale). Poniżej stan faktyczny: co działa end-to-end, co jest symulacją, a co wymaga przyszłej integracji.

Legenda:
- ✅ **E2E** — działa w UI, zapisuje się przez API do MySQL, odtwarza po odświeżeniu.
- 🟡 **Lokalnie** — działa w przeglądarce, ale bez zewnętrznego dostawcy (np. Stripe).
- ⚪ **Demo** — symulacja wizualna, do podłączenia w przyszłości.

---

## 1. Logowanie i konto

| Funkcja | Stan | Opis |
|---|---|---|
| Logowanie Discord OAuth2 | ✅ | `GET /auth/discord` → callback → sesja w MySQL; wymaga danych aplikacji Discord |
| Weryfikacja roli Alpha | ✅ (opcjonalna) | bot + `DISCORD_GUILD_ID` + `DISCORD_REQUIRED_ROLE_ID` |
| Logowanie demo | ✅ | `POST /auth/demo`, konto `demo-local`, dane z `db/demo-state.json` |
| Wylogowanie | ✅ | usuwa sesję z DB i cookie |
| „Nie wylogowuj mnie” | ✅ | zapis w stanie; cookie 30 dni |
| Profil (imię, e-mail, avatar) | ✅ | imię i avatar zapisują się w stanie; avatar Discord jest domyślny |
| Subskrypcja (plan, data) | ✅ | z tabeli `users`; ekran wygasłej subskrypcji |
| Zmiana planu demo | ✅ | przyciski podglądu; nadpisanie serwerowe po odświeżeniu |
| Usunięcie konta | ✅ | `DELETE /api/account` (kaskada) |
| Eksport danych ZIP | ✅ | JSON, CSV transakcji, CSV podatków, plany, screeny, avatar |
| Eksport JSON przez API | ✅ | `GET /api/me/export` |

> Płatności Stripe nie są podłączone. Modal zakupu jest demonstracyjny i nie pobiera pieniędzy.

---

## 2. Pulpit

| Funkcja | Stan | Opis |
|---|---|---|
| Powitanie z porą dnia | ✅ | ustawienie „Powitanie” |
| Check-in przed rynkiem | ✅ | nastrój, energia, notatka; zapis w stanie; bez sekcji gotowości i bez integracji ze sprzętem |
| Wynik dnia | ✅ | z transakcji dzisiejszych, przeliczony na walutę wyników |
| Limity strat/transakcji/ryzyka | ✅ | z planu dnia i ustawień |
| Plan dnia na pulpicie | ✅ | z zatwierdzonego planu |
| Makro-widget | ✅ | najbliższe wydarzenia ★★★, odświeżanie |
| Alarm mentora | ✅ | na podstawie ostatnich transakcji |
| Krzywa kapitału 30 dni | ✅ | liczona z transakcji |
| Szybkie przejście do statystyk | ✅ | |

---

## 3. Plan dnia

| Funkcja | Stan | Opis |
|---|---|---|
| Rynek, bias, uzasadnienie | ✅ | zapis do `plans[data]` |
| Kluczowe poziomy | ✅ | dodawanie/usuwanie, walidacja ceny |
| Scenariusze główny/awaryjny | ✅ | |
| Limity (ryzyko, max transakcji, godzina) | ✅ | wpływają na pulpit |
| „Czego dziś nie robię” | ✅ | |
| Zatwierdzenie planu | ✅ | timestamp, widoczny na pulpicie |
| Kopiuj z wczoraj | ✅ | |
| Dziś/Wczoraj/Historia | ✅ | historia planów, podgląd |
| Trzymanie się planu | ✅ | 5 dni wstecz |

---

## 4. Dziennik transakcji

| Funkcja | Stan | Opis |
|---|---|---|
| Lista 47 transakcji demo | ✅ | z MySQL |
| Filtry: wszystkie/zyskowne/stratne/otwarte | ✅ | |
| Filtr oceny A/B/C | ✅ | |
| Szukanie po parze/emocji/setupie | ✅ | |
| Szczegóły transakcji | ✅ | wykres, MAE/MFE, checklist, notatka, mentor |
| Screeny przed/po | ✅ | upload, drag&drop, Ctrl+V; JPEG w stanie |
| Dodanie transakcji | ✅ | ręcznie, walidacja kierunku SL/TP, screenshoty |
| Edycja transakcji | ✅ | formularz wypełniony danymi |
| Usunięcie transakcji | ✅ | potwierdzenie |
| Zamknięcie otwartej pozycji | ✅ | cena wyjścia lub wynik ręczny, R, czas trwania |
| Eksport CSV | ✅ | średnik + BOM (Excel PL) |
| Import CSV z giełdy | ✅ | parser WEEX/OKX/BingX/Bybit/MT, podgląd, deduplikacja |
| Tryb ochronny (limit strat) | ✅ | blokada, countdown, obejście z powodem |
| „Wpisuję wcześniejszą transakcję” | ✅ | zapis obejścia w `overrides` |

Import XLS/XLSX nie jest czytany — zapisz jako CSV (komunikat w UI).

---

## 5. Playbook

| Funkcja | Stan | Opis |
|---|---|---|
| Lista setupów | ✅ | 5 setupów demo |
| Statystyki setupu | ✅ | liczba, skuteczność, średni R, wynik z transakcji |
| Przykładowy wykres | ✅ | generowany SVG |
| Dodanie setupu | ✅ | nazwa, interwał, ocena, 3 zasady, „nie graj tego” |
| Edycja setupu | ✅ | |
| Przejście do transakcji setupu | ✅ | filtr dziennika |

---

## 6. Kalkulator Alpha

| Funkcja | Stan | Opis |
|---|---|---|
| Krypto (dźwignia, depozyt, wartość) | ✅ | |
| FX/indeksy/surowce (loty, pipsy) | ✅ | |
| Long/Short | ✅ | walidacja SL/TP |
| Szybkie kwoty | ✅ | saldo, dźwignia, ryzyko |
| Auto SL/TP 1:3 | ✅ | |
| Reset | ✅ | |
| Kroki obliczeń | ✅ | |

Wyniki kalkulatora nie są zapisywane jako transakcja — to narzędzie pomocnicze.

---

## 7. Statystyki

| Funkcja | Stan | Opis |
|---|---|---|
| Zakres 7/30/90 dni/wszystko | ✅ | |
| Wynik, skuteczność, PF, expectancy | ✅ | z transakcji |
| Max obsunięcie | ✅ | z krzywej kapitału |
| % ze SL, zgodność z planem, opłaty | ✅ | |
| Krzywa kapitału | ✅ | SVG z realnych danych |
| Kalendarz PnL | ✅ | 35 dni |
| Wynik wg oceny | ✅ | |
| Checklista a wynik | ✅ | |
| Koszt błędów | ✅ | |
| MAE/MFE | ✅ | punkty z transakcji |
| Sesje/emocje/czas trzymania | ✅ | |

---

## 8. Mentor AI

| Funkcja | Stan | Opis |
|---|---|---|
| Eksport kontekstu | ✅ | transakcje z 90 dni, plan, check-iny, gotowość, makro — czysty tekst do wklejenia |
| Instrukcje Grok / ChatGPT / Claude | ✅ | kroki wklejenia kontekstu u każdego dostawcy |
| Kopiowanie kontekstu | ✅ | schowek z fallbackiem, odświeżanie |
| Uwagi mentora | ✅ | generowane z danych (limit strat, odgrywanie, MFE, ocena A, sesja) |
| Proaktywna wiadomość przy limicie strat | ✅ | po zalogowaniu |

Mentor jest **regułowy** — nie wysyła danych do OpenAI, xAI ani Anthropic i nie przyjmuje kluczy API. Integracja polega na wyeksportowaniu kontekstu i wklejeniu go w Grok, ChatGPT albo Claude. Gotowość liczy się z check-inu i podłączonych urządzeń, jeśli są dostępne.

---

## 9. Prop challenge

| Funkcja | Stan | Opis |
|---|---|---|
| Szablony FTMO (2-Step/1-Step/Swing), FundedNext, FundingPips, Topstep, Apex, Breakout, własne | ✅ | limity, typ obsunięcia i min. dni; każde pole edytowalne |
| Nowy challenge | ✅ | nazwa, firma, konto źródłowe, rozmiar, saldo startowe, cel, limity, daty |
| Wybór challenge'u | ✅ | osobne metryki dla każdego challenge'u |
| Cel zysku | ✅ | liczone z zamkniętych transakcji na koncie |
| Dzienny limit straty | ✅ | podstawa: saldo startowe / początek dnia; alerty 75/90/100% |
| Max obsunięcie | ✅ | statyczne od salda startowego lub trailing od szczytu |
| Dni do końca i min. dni handlowych | ✅ | termin opcjonalny; firmy bez limitu czasu |
| Krzywa kapitału i wynik dzienny | ✅ | SVG, linia celu i linia stopu |
| Statusy | ✅ | aktywny / cel / zaliczony / limit / czas minął |
| Alerty + banner na pulpicie | ✅ | toast, powiadomienie, deduplikacja raz dziennie |
| Edycja, duplikat, archiwum, usuwanie | ✅ | widok archiwum w selektorze |
| Zapis w MySQL | ✅ | `prop.challenges` + `selectedId` w JSON stanu |

Limity liczone są z **zamkniętych** transakcji. Silnik nie zna ruchów śróddziennych ani equity z platformy. Szablony są punktem startowym — potwierdź aktualne zasady w regulaminie firmy. Szczegóły: [PROP.md](PROP.md).

---

## 10. Akademia

| Funkcja | Stan | Opis |
|---|---|---|
| Harmonogram live'ów | ✅ | synchronizacja Discord Scheduled Events → `/api/events`; grupowanie po `Europe/Warsaw`, `DZIŚ`, wiele wydarzeń dnia |
| Najbliższy live + countdown | ✅ | wyłącznie z realnego wydarzenia `live`; brak wydarzenia = puste pola |
| Liczba zainteresowanych | ✅ | z `user_count`, jeśli Discord je zwróci; brak = pole puste |
| Konsultacje 1:1 | ✅ | karta „Najbliższa” z wydarzenia `consultation`; prowadzący i czas trwania tylko z danych wydarzenia |
| Ręczne przypisanie kategorii | ✅ | admin `live` / `consultation` / `unassigned`; pierwszeństwo nad regułami |
| Puste pola | ✅ | brak tekstów zastępczych, brak domyślnego 20:00 |
| Błąd synchronizacji | ✅ | ostatni dobry zestaw pozostaje; UI pokazuje stan „błąd synchronizacji” |
| Rezerwacja konsultacji | ✅ | niezależna od synchronizacji, zapis w stanie |
| Dołącz / przypomnienie | ✅ | modal, powiadomienie przeglądarki (jeśli pozwolono) |
| Nagrania | 🟡 | karta i modal demo; brak playera |
| Materiały / postęp kursu | ✅ | postęp w stanie |
| Plan solo vs pełny | ✅ | podgląd, nadpisywany przez subskrypcję |

Szczegóły techniczne: [DOCUMENTATION.md §7](DOCUMENTATION.md#7-discord-scheduled-events--pełny-przepływ), [DOCUMENTATION.md §12](DOCUMENTATION.md#12-puste-pola-i-stany-brzegowe).

---

## 11. Podatki PIT-38

| Funkcja | Stan | Opis |
|---|---|---|
| Przychód/koszty/nadwyżka/podatek 19% | ✅ | z wpłat i wypłat |
| Zdarzenia podatkowe | ✅ | tabela |
| Eksport CSV | ✅ | |
| „PDF dla księgowej” | 🟡 | otwiera okno wydruku (Zapisz jako PDF); fallback HTML |
| Wpłaty/wypłaty | ✅ | dodawanie, usuwanie, wpływ na saldo konta |
| Neutralne podatkowo | ✅ | liczba transakcji futures |

To zestawienie pomocnicze, nie porada podatkowa.

---

## 12. Kalendarz makro

| Funkcja | Stan | Opis |
|---|---|---|
| Ten/następny tydzień | ✅ | |
| Filtr ★★ | ✅ | |
| Statusy (zakończone/dziś/za X min) | ✅ | odświeżanie |
| Następne ważne | ✅ | |
| Twoje dane w dni makro | ✅ | z transakcji |
| Powiadomienia 30 min | ✅ | toast + Notification API, jeśli pozwolono |
| Widget MAKRO | ✅ | |

---

## 13. Giełdy, MetaTrader i API

| Funkcja | Stan | Opis |
|---|---|---|
| WEEX/OKX/BingX — połączenie klucza | E2E | `POST /api/connections`; walidacja na żywo, szyfrowanie AES-256-GCM, brak sekretów w UI |
| Weryfikacja tylko-do-odczytu | E2E | OKX `perm`, BingX `apiPermissions`, WEEX bezpieczna próba zlecenia na nieistniejącym symbolu |
| Synchronizacja transakcji | E2E | OKX: zamknięte pozycje + spot fills; BingX: positionHistory; WEEX: fills → rekonstrukcja rund |
| Automatyczny import | E2E | wywoływany przez istniejący LH Cron (co 5 min) z blokadą „co 25 min”; ręcznie `bin/sync-exchange-trades.php` |
| Deduplikacja | E2E | `exchange_imports.external_id` + `extId` w transakcji; ponowna synchronizacja nie tworzy duplikatów |
| MetaTrader — hasło inwestora | E2E (wymaga `METAAPI_TOKEN`) | MetaApi cloud, wyłącznie `investorMode:true`; hasło z prawem handlu odrzucane |
| MetaTrader — EA | E2E | kod parowania `AV-XXXX-XXXX`, endpoint `POST /api/mt/ea`, EA w `integrations/mt5/AlphaVault.mq5` |
| Odłączenie | E2E | usuwa połączenie i poświadczenia; transakcje zostają w dzienniku |
| Import CSV | E2E | parser WEEX/OKX/BingX/Bybit/MT w przeglądarce, podgląd i deduplikacja |
| Import XLS/XLSX | — | komunikat „zapisz jako CSV” |
| Błąd dostawcy | E2E | status `error` i komunikat w panelu; ostatni dobry zestaw transakcji pozostaje |

Poświadczenia są szyfrowane po stronie serwera (`INTEGRATIONS_KEY`) i nigdy nie wracają do przeglądarki. Panel pokazuje wyłącznie maskę klucza i status.

## 14. Giełdy i API

| Funkcja | Stan | Opis |
|---|---|---|
| WEEX/OKX/BingX — formularz klucza | 🟡 | walidacja, zapis zamaskowanego klucza |
| Połączenie/odłączenie | 🟡 | status w UI, `lastSync` |
| Synchronizuj teraz | 🟡 | aktualizuje czas; nie pobiera transakcji z giełdy |
| MetaTrader — hasło inwestora | 🟡 | walidacja, zapis loginu/serwera (bez hasła) |
| Kod parowania EA | ✅ | generowany, kopiowanie, instrukcja TXT |
| Import CSV | ✅ | prawdziwy parser i zapis transakcji |
| Import XLS | ⚪ | komunikat „zapisz jako CSV” |

Realne pobieranie transakcji z giełd wymaga kluczy API i serwerowego workera — poza zakresem tego MVP.

---

## 15. Konto i zasady

| Funkcja | Stan | Opis |
|---|---|---|
| Profil (imię, waluta, cel, motto) | ✅ | |
| Personalizacja (płeć mentora, styl, rynki, akcent, strefa, godziny) | ✅ | |
| Wyłącznik bezpieczeństwa | ✅ | limit strat, limit dzienny, czas blokady, wymóg powodu |
| 3 zasady + punkty checklisty | ✅ | własny punkt checklisty |
| Konta i kapitał | ✅ | dodawanie/usuwanie, włączanie do statystyk |
| Wpłaty/wypłaty | ✅ | saldo + PIT |
| Odznaki | ✅ | postęp z danych |
| Bezpieczeństwo i sesje | ✅ | wylogowanie innych (lokalna lista), eksport, usunięcie konta |
| 2FA Discord | ⚪ | informacja; egzekwowane po stronie Discorda |
| Aplikacja na telefonie | ⚪ | instrukcja PWA; manifest nie jest jeszcze dodany |

---

## 16. Dokumenty prawne

| Funkcja | Stan | Opis |
|---|---|---|
| Polityka prywatności | ✅ | modal z treścią |
| Regulamin | ✅ | modal z treścią |
| „Czym jest Akademia?” | ✅ | modal |
| Zakup + zgody | 🟡 | modal, checkboxy, aktywacja planu demo; brak Stripe |

Treści prawne zawierają placeholdery `[data publikacji]` i wymagają weryfikacji prawnika przed produkcją.

---

## 17. Tryb offline

Gdy API/MySQL nie odpowiada (np. otwarcie `index.html` bez serwera):

- UI działa z `localStorage`,
- logowanie jest lokalne (bez Discord),
- badge u góry pokazuje **TRYB OFFLINE**,
- po powrocie serwera i zalogowaniu stan z MySQL zastępuje lokalny.

To celowy fallback, nie drugi równoległy backend.

## 18. Agent MCP

| Funkcja | Stan | Opis |
|---|---|---|
| Tokeny agenta | ✅ | `panel:read`, `panel:propose`; token widoczny raz, unieważnianie |
| 8 narzędzi odczytu | ✅ | profil, pulpit, transakcje, statystyki, wydarzenia, plan, setupy |
| 4 propozycje zmian | ✅ | ręczna transakcja, recenzja, plan dnia, check-in |
| Zatwierdzanie w panelu | ✅ | diff przed/po, zatwierdź/odrzuć, konflikt rewizji 409 |
| Audyt | ✅ | propozycje, decyzje, tokeny |
| Ochrona danych giełdowych | ✅ | tylko odczyt; metadane w osobnym rekordzie `tradeReviews` |
| OAuth 2.1 (DCR, PKCE) | ⏳ | plan; teraz tokeny wydawane w panelu |

Zasady: `MCP_ENABLED=true`, `MCP_WRITES_ENABLED=false` domyślnie; agent nie ma narzędzia zatwierdzającego. Szczegóły: [MCP.md](https://github.com/miloszspyrka-code/alphavault/blob/main/docs/MCP.md).
