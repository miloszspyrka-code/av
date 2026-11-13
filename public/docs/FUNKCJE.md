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
| Weryfikacja roli Alpha | ✅ | logowanie tokenem użytkownika; cron co 5 min botem, odebranie roli usuwa sesje i pokazuje bramkę |
| Logowanie demo | ✅ | `POST /auth/demo`, osobne konto `demo-<hash>` per przeglądarka (cookie `av_demo`), dane z `db/demo-state.json`; daty stanu przesuwane na dziś przy seedzie i cyklicznie co 60 min |
| Wylogowanie | ✅ | usuwa sesję z DB i cookie |
| „Nie wylogowuj mnie” | ✅ | zapis w stanie; cookie 30 dni |
| Profil (imię, e-mail, avatar) | ✅ | imię i avatar zapisują się w stanie; avatar Discord jest domyślny |
| Subskrypcja (plan, data) | ✅ | z tabeli `users`; data pokazywana tylko, gdy jest ustawiona; ekran wygasłej subskrypcji |
| Zmiana planu demo | ✅ | przyciski podglądu; nadpisanie serwerowe po odświeżeniu |
| Usunięcie konta | ✅ | `DELETE /api/account` (kaskada) |
| Eksport danych ZIP | ✅ | JSON, CSV transakcji, CSV podatków, plany, screeny, avatar |
| Eksport JSON przez API | ✅ | `GET /api/me/export` |
| Ticker notowań | ✅ | `GET /api/quotes` co 60 s; realne ceny Yahoo Finance; `stale` i ostatni dobry zestaw przy błędzie; bez danych pasek pusty |
| Statusy w nagłówku | ✅ | bez technicznych etykiet bazy; chip giełd (nazwa + czas sync) tylko przy aktywnym połączeniu, bez połączeń ukryty |

> Płatności Stripe nie są podłączone. Modal zakupu jest demonstracyjny i nie pobiera pieniędzy.

---

## 2. Pulpit

| Funkcja | Stan | Opis |
|---|---|---|
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
| Rynek, bias, uzasadnienie | ✅ | klikalne chipy rynku i biasu; zapis do `plans[data]` i natychmiastowy PUT `/api/state` |
| Kluczowe poziomy | ✅ | dodawanie/usuwanie, walidacja ceny; pusty wiersz zostaje w szkicu |
| Scenariusze główny/awaryjny | ✅ | |
| Limity (ryzyko, max transakcji, godzina) | ✅ | wpływają na pulpit |
| „Czego dziś nie robię” | ✅ | |
| Zatwierdzenie planu | ✅ | wymaga rynku, kierunku, uzasadnienia i co najmniej jednego poziomu; timestamp i potwierdzenie zapisu w MySQL |
| Zgodność transakcji z planem | ✅ | liczona po dacie, parze i kierunku (`planMatchesTrade`); sam setup nie wystarcza |
| Kopiuj z wczoraj | ✅ | natychmiastowy zapis w MySQL |
| Autosave szkicu | ✅ | debounce 700 ms; błąd zapisu pokazany toastem |
| Trwały zapis w MySQL | ✅ | `user_states.state.plans[YYYY-MM-DD]`; przeładowanie odtwarza plan z bazy |
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
| Dodanie transakcji | ✅ | formularz startuje pusty; walidacja kierunku SL/TP; ryzyko = \|entry − SL\| × size; ostrzeżenie przy przekroczeniu limitu |
| Edycja transakcji | ✅ | formularz wypełniony danymi |
| Usunięcie transakcji | ✅ | potwierdzenie |
| Zamknięcie otwartej pozycji | ✅ | cena wyjścia lub wynik ręczny, R, czas trwania; MAE/MFE tylko z importu |
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
| Cena instrumentu live | ✅ | `GET /api/quotes?symbols=…`, Yahoo Finance, odświeżanie co 60 s; brak notowań = `—`, bez wartości demo |
| Long/Short | ✅ | walidacja SL/TP |
| Szybkie kwoty | ✅ | saldo, dźwignia, ryzyko |
| Auto SL/TP 1:3 | ✅ | tylko z aktualnej ceny rynkowej |
| Reset | ✅ | |
| Kroki obliczeń | ✅ | |

Wyniki kalkulatora nie są zapisywane jako transakcja — to narzędzie pomocnicze.

---

## 7. Statystyki

| Funkcja | Stan | Opis |
|---|---|---|
| Zakres 7/30/90 dni/wszystko | ✅ | |
| Wynik, skuteczność, PF, expectancy | ✅ | z transakcji; przy 0 transakcji „—”, bez dzielenia przez zero |
| Max obsunięcie | ✅ | z krzywej kapitału |
| % ze SL, zgodność z planem, opłaty | ✅ | przy 0 transakcji „—”; opłaty 0,00 bez ujemnego zera |
| Krzywa kapitału | ✅ | SVG z realnych danych |
| Kalendarz PnL | ✅ | 35 dni |
| Wynik wg oceny | ✅ | bez pochwał przy braku danych |
| Checklista a wynik | ✅ | etykiety z długości checklisty użytkownika, nie stałe 7/7 |
| Koszt błędów | ✅ | pusty stan bez „Tak trzymaj” |
| MAE/MFE | ✅ | tylko transakcje z importu; wpisy ręczne mają null |
| Sesje/emocje/czas trwania | ✅ | średnie pomijają null |

---

## 8. Mentor AI — integracje MCP

| Funkcja | Stan | Opis |
|---|---|---|
| Adres serwera MCP | ✅ | `https://mcp.alphavault.pl/mcp` z przyciskiem „Kopiuj adres” |
| Jak to działa | ✅ | przepływ Alpha Vault → Serwer MCP → Asystent AI |
| Zakładki klientów | ✅ | ChatGPT, Claude, Grok — po 4 krokach konfiguracji; Claude przez `https://claude.ai/api/mcp/auth_callback` |
| Korzyści i bezpieczeństwo | ✅ | kontekst konta, wspólny endpoint, zakres uprawnień |
| Status serwera MCP | ✅ | statyczny pill „MCP · TYLKO ODCZYT” |
| Proaktywna wiadomość przy limicie strat | ✅ | przycisk prowadzi do integracji MCP |

Widok `#/mentor-ai` nie zawiera czatu ani analiz — tylko stronę integracji zewnętrznego asystenta z MCP. Karta nie wysyła danych użytkownika; adres i kroki są statyczne.

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
| Najbliższy live (bez countdownu) | ✅ | wyłącznie z realnego wydarzenia `live`; karta ukryta, gdy brak wydarzenia |
| Liczba zainteresowanych | ✅ | z `user_count`, jeśli Discord je zwróci; brak = pole puste |
| Konsultacje 1:1 | ✅ | karta „Najbliższa” z wydarzenia `consultation`; prowadzący i czas trwania tylko z danych wydarzenia |
| Ręczne przypisanie kategorii | ✅ | admin `live` / `consultation` / `unassigned`; pierwszeństwo nad regułami |
| Puste pola | ✅ | brak tekstów zastępczych, brak domyślnego 20:00 |
| Błąd synchronizacji | ✅ | ostatni dobry zestaw pozostaje; UI pokazuje stan „błąd synchronizacji” |
| Rezerwacja konsultacji | ✅ | niezależna od synchronizacji, zapis w stanie |
| Dołącz / przypomnienie | ✅ | link do `https://discord.com/events/{guild}/{event}`; przypomnienie przeglądarki bez zmian |
| Nagrania | 🟡 | karta i modal demo; brak playera |
| Materiały / postęp kursu | ✅ | postęp w stanie |
| Plan solo vs pełny | ✅ | podgląd, nadpisywany przez subskrypcję |

Szczegóły techniczne: [DOCUMENTATION.md §7](DOCUMENTATION.md#7-discord-scheduled-events--pełny-przepływ), [DOCUMENTATION.md §12](DOCUMENTATION.md#12-puste-pola-i-stany-brzegowe).

---

## 11. Podatki PIT-38

| Funkcja | Stan | Opis |
|---|---|---|
| Przychód/koszty/nadwyżka/podatek 19% | ✅ | z wpłat i wypłat roku podatkowego |
| Zakres roku podatkowego | ✅ | zdarzenia i transakcje tylko z `tax.year`; rok i termin w nagłówku liczone dynamicznie |
| Nadwyżka kosztów z poprzedniego roku | ✅ | edycja w panelu („zmień”), zapis w stanie; nowe konto = 0,00 zł |
| Zdarzenia podatkowe | ✅ | tabela; waluty obce wymagają kursu NBP |
| Eksport CSV | ✅ | zdarzenia, podsumowanie, transakcje neutralne/MT4-MT5, rok w nazwie |
| „Drukuj / zapisz PDF” | 🟡 | otwiera okno wydruku (Zapisz jako PDF); fallback HTML; rok i podsumowanie |
| Wpłaty/wypłaty | ✅ | dodawanie, usuwanie, wpływ na saldo konta; walidacja kursu NBP |
| Neutralne podatkowo | ✅ | liczba transakcji z dziennika roku (futures/krypto, bez MT4/MT5) |
| Forex i CFD (MT4/MT5) | ✅ | liczba transakcji roku rozpoznana po koncie/źródle MT4/MT5 |
| Do przeniesienia na kolejny rok | ✅ | nadwyżka kosztów roku po odjęciu przychodu i wykorzystanej nadwyżki |

To zestawienie pomocnicze, nie porada podatkowa.

---

## 12. Kalendarz makro

| Funkcja | Stan | Opis |
|---|---|---|
| Ten/następny tydzień | ✅ | `macro_events` + `/api/macro-events`; FF (bieżący) i TradingView (następny) |
| Filtr ★★ | ✅ | toggle `macroTwo` |
| Statusy (zakończone/dziś/za X min) | ✅ | odświeżanie |
| Następne ważne | ✅ | |
| Twoje dane w dni makro | ✅ | z transakcji |
| Powiadomienia 30 min | ✅ | toast + Notification API, jeśli pozwolono |
| Widget MAKRO | ✅ | te same dane co kalendarz |
| Synchronizacja | ✅ | `bin/sync-macro-events.php`; wrapper LH Cron `*/5`, throttle 15 min |

---

## 13. Ciało i głowa

| Funkcja | Stan | Opis |
|---|---|---|
| Check-in przed rynkiem | ✅ | nastrój, energia, notatka; zapis w stanie i wpływ na gotowość oraz podpowiedzi mentora |
| Apple Watch · Zdrowie | ✅ | kod parowania + `POST /api/wearables/apple/ingest` z tokenem urządzenia; aplikacja iPhone poza repo |
| Oura Ring | ✅ | OAuth2 + PKCE, `daily_readiness` + `sleep` (scope `daily`), rotacja refresh tokenów |
| Garmin Connect | ⏸️ | wyłączony na razie — kod i konfiguracja zachowane, brak wpisu w UI/API |
| Whoop | ⏸️ | wyłączony na razie — kod i konfiguracja zachowane, brak wpisu w UI/API |
| Gotowość | ✅ | sen 40%, HRV 25%, tętno 10%, regeneracja 15%, Body Battery 10%; check-in 30% po połączeniu |
| Wykres sen → wynik dnia | ✅ | liczone z realnych metryk i transakcji; brak danych = ukryty wykres |
| Odłączenie urządzenia | ✅ | wycofuje dostęp u dostawcy (jeśli API to wspiera), usuwa tokeny i metryki z bazy |

Pełny E2E: `npm run test:wearables` (35 scenariuszy API + UI dla Oura i Apple, lokalny dostawca OAuth, bez prawdziwych danych zdrowotnych) oraz `tests/wearables-config.test.php` (5/5, lista dostawców i wymóg klucza szyfrowania).

---

## 14. Giełdy, MetaTrader i API

| Funkcja | Stan | Opis |
|---|---|---|
| WEEX/OKX/BingX — połączenie klucza | E2E | `POST /api/connections`; walidacja na żywo, szyfrowanie AES-256-GCM, brak sekretów w UI |
| Uprawnienia klucza | E2E | OKX `perm` i BingX `apiPermissions` wymagają odczytu; WEEX przyjmuje każdy klucz z działającym odczytem konta |
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

## 15. Konto i zasady

| Funkcja | Stan | Opis |
|---|---|---|
| Profil (imię, waluta, cel) | ✅ | |
| Personalizacja (rynki, akcent, strefa, godziny) | ✅ | |
| Wyłącznik bezpieczeństwa | ✅ | limit strat, limit dzienny, czas blokady, wymóg powodu |
| 3 zasady + punkty checklisty | ✅ | własny punkt checklisty |
| Konta i kapitał | ✅ | dodawanie/usuwanie, włączanie do statystyk |
| Wpłaty/wypłaty | ✅ | saldo + PIT |
| Odznaki | ✅ | 8 progów liczbowych z realnych danych; licznik „X z 8” i poziom wyliczane z liczby zdobytych; data zdobycia lub pozostała wartość w podpowiedzi |
| Bezpieczeństwo i dane | ✅ | prawdziwa sesja serwerowa, wylogowanie, eksport, usunięcie konta; bez deklaracji 2FA i listy sesji |
| Świeże konto | ✅ | `buildEmpty()` zeruje datę dołączenia, postęp kursu, sesje, streak i demo-połączenia; migracja czyści stare demo-wartości |
| Tożsamość | ✅ | nazwa i e-mail z `/api/me`; `joinedAt` z bazy; brak zastępczego `kasia.trades` |
| Aplikacja na telefonie (PWA) | ✅ | manifest, service worker, instalacja bez App Store; szczegóły w §17 |

---

## 16. Dokumenty prawne

| Funkcja | Stan | Opis |
|---|---|---|
| Polityka prywatności | ✅ | osobna strona `/polityka-prywatnosci`; linki w stopce, logowaniu, zakupie, modalu Akademii oraz ekranach wygaśnięcia i braku roli |
| Regulamin | ✅ | osobna strona `/regulamin`; linki w stopce, logowaniu, zakupie, modalu Akademii oraz ekranach wygaśnięcia i braku roli |
| „Czym jest Akademia?” | ✅ | modal |
| Zakup + zgody | 🟡 | modal, checkboxy, aktywacja planu demo; brak Stripe |

Dokumenty prawne: wersja 1.0 z 12.11.2026, bez placeholderów. Wymagają okresowej weryfikacji prawnej. Pliki: `public/regulamin.html` i `public/polityka-prywatnosci.html`.

---

## 17. Tryb offline

Gdy API/MySQL nie odpowiada (np. otwarcie `index.html` bez serwera):

- UI działa z `localStorage`,
- logowanie jest lokalne (bez Discord),
- badge u góry pokazuje **TRYB OFFLINE**,
- po powrocie serwera i zalogowaniu stan z MySQL zastępuje lokalny.

To celowy fallback, nie drugi równoległy backend.

---

## 18. Aplikacja na telefonie (PWA)

| Element | Stan | Opis |
|---|---|---|
| Manifest | ✅ | `/manifest.webmanifest`: `display: standalone`, ikony 192/512/maskable, skrót `/?qa=1` |
| Instalacja bez App Store | ✅ | Chrome/Edge: `beforeinstallprompt`; iPhone: Safari → Udostępnij → Do ekranu początkowego |
| Service worker | ✅ | `/sw.js`: cache powłoki, offline fallback, `notificationclick` → `/?qa=<id>` |
| Powiadomienie lokalne po zamknięciu w panelu | ✅ | po zamknięciu pozycji w panelu i zapisie stanu; treść: para, kierunek, wynik; klik otwiera szybkie dodanie |
| Szybkie dodanie | ✅ | emocja + ocena + zdjęcie wykresu (`capture="environment"`), zapis do stanu i MySQL; E2E 0,6–0,7 s |
| Push serwerowy | ⛔ | brak serwerowego źródła zdarzeń o zamknięciu transakcji; handler `push` w service workerze jest gotowy i testowany przez CDP. Wdrożyć razem z auto-importem z giełd. |

Powiadomienie o zamknięciu powstaje po stronie klienta, więc działa, gdy panel jest otwarty albo gdy transakcję zamyka się w panelu. Powiadomienie „po zamknięciu na giełdzie” przy zamkniętej aplikacji wymaga workera giełdowego i Web Push z VAPID — to osobny zakres.

---

## 19. Adresy podstron

Każdy widok ma własny, czytelny adres w hashu, więc link można wysłać lub dodać do zakładek:

`#/pulpit` · `#/plan` · `#/dziennik` · `#/playbook` · `#/nowa-transakcja` · `#/kalkulator` · `#/prop-challenge` · `#/akademia` · `#/statystyki` · `#/podatki` · `#/mentor-ai` · `#/kalendarz` · `#/cialo-i-glowa` · `#/gieldy` · `#/konto`

Stare krótkie adresy (`#/trans`, `#/akad`, `#/ust`, `#/stat`, `#/kal`, `#/cialo`, …) nadal otwierają właściwy widok i są automatycznie przepisywane na czytelny slug. Pełna tabela i zasady: `DOCUMENTATION.md §11.1a`.
