# Audyt funkcjonalno-użytkowy Alpha Vault

**Data:** 13.11.2026  
**Wersja aplikacji:** 3.9.5 (`/api/health`)  
**Środowisko:** produkcja `https://alphavault.pl`  
**Repozytorium:** `miloszspyrka-code/av`, `origin/main`  
**Zakres:** 15 widoków aplikacji, desktop 1440/2560 px, mobile 390 px, PWA, API health, dokumentacja i kod źródłowy.

---

## 0. Jak czytać ten dokument

- **P0** — zaufanie, prawo, bezpieczeństwo. Do zamknięcia przed dalszym rozwojem.
- **P1** — poprawność danych i użyteczność rdzenia produktu.
- **P2** — polishing, treści, dostępność.
- **Minimalna zmiana** — bez nowych modułów: usunąć, ukryć, poprawić etykietę, podłączyć istniejące dane.
- **Demo** — dane lub obietnica bez pokrycia w produkcji.

Audyt jest read-only. Nie zmieniono danych konta, nie wykonano płatności, nie podłączono urządzeń ani giełd, nie uruchomiono lokalnego builda ani testów. Dowodem są zachowanie produkcji, odpowiedzi API, kod `origin/main` i dokumentacja.

---

## 1. Werdykt

Alpha Vault jest dojrzałym, funkcjonalnie szerokim produktem: ma prawdziwe logowanie Discord z weryfikacją roli, realne wydarzenia Akademii, notowania, kalendarz makro, dopracowany plan dnia, dziennik, kalkulator, prop challenge i read-only serwer MCP. Rdzeń „plan → transakcja → dziennik → statystyki” jest dobrze zaprojektowany i wart dalszej pracy.

Największym problemem nie jest brak funkcji, lecz **warstwa zaufania**. Wiele elementów demonstracyjnych lub statycznych jest prezentowanych jako rzeczywiste dane użytkownika: postęp kursu, data dołączenia, aktywne sesje, 2FA, kod przy zmianie kluczy API, płatność Stripe, historia transakcji bez SL, zgodność z planem i powiadomienia push. Dokumentacja `docs/FUNKCJE.md` uczciwie oznacza te elementy jako demo lub częściowe, ale UI tego nie komunikuje. To powoduje większe ryzyko niż brak kolejnej funkcji.

**Ocena ogólna: 3,3 / 5.** Po dziesięciu minimalnych poprawkach z tego dokumentu produkt osiąga 4,5 / 5 bez dodawania nowych modułów.

### 1.1. Dziesięć rzeczy do naprawy w pierwszej kolejności

1. Usunąć obietnicę płatności Stripe i e-maila potwierdzającego; ukryć demonstracyjny zakup.
2. Usunąć lub naprawić deklaracje bezpieczeństwa: 2FA, aktywne sesje, kod przy zmianie kluczy API.
3. Uporządkować MCP: polityka prywatności, zgoda, zakres read-only, usunięcie nieobsługiwanego Claude.
4. Usunąć demo-transakcję z formularza „Nowa transakcja” i pokazywać rzeczywiste ryzyko.
5. Naprawić puste statystyki: „1 transakcja bez SL”, „0,00 ÷ 0,00”, „Tak trzymaj”, „7/7”.
6. Naprawić logikę zgodności z planem i usunąć stałe „+31,40 / −42,10”.
7. Usunąć demo-seed z realnych kont: data dołączenia, 64% kursu, sesje, streaki.
8. Uzależnić badge „Plan dnia ✓” i „LIVE” od rzeczywistego stanu.
9. Naprawić mobile: formularz nowej transakcji, konto, karta konsultacji, nawigacja.
10. Poprawić obietnice: 25 min zamiast 30, powiadomienia lokalne zamiast push, nagrania bez fałszywego playera.

---

## 2. Jak działa aplikacja

### 2.1. Model techniczny

- **Frontend:** statyczny SPA w `public/index.html` i `public/js/*.js`, routing po hashu (`#/pulpit`, `#/plan`, `#/dziennik`, …).
- **Backend:** PHP 8.3 na LH.pl, MySQL/MariaDB, front controller `public/index.php`, API w `app/routes/api.php`.
- **Stan użytkownika:** jeden dokument JSON w `user_states`, zapis optymistyczny z `revision` i `baseRevision`; konflikt zwraca 409. `localStorage` jest fallbackiem offline.
- **Auth:** Discord OAuth2, rola Alpha sprawdzana przy logowaniu i cyklicznie co 5 min; sesje w tabeli `sessions`.
- **Automatyzacja:** jeden wpis LH Cron `*/5`, wrapper uruchamia Discord events, recheck roli, integracje giełd (realny sync max co 25 min), makro (15 min), urządzenia (30 min) i daty demo (60 min).
- **Dane zewnętrzne:** Discord Scheduled Events, Yahoo Finance (notowania), Forex Factory/TradingView (makro), Oura/Apple Health, API giełd, MetaApi, serwer MCP `mcp.alphavault.pl`.
- **PWA:** manifest, service worker, instalacja bez sklepu, lokalne powiadomienia przez Notification API. Brak serwerowego Web Push.

### 2.2. Co jest prawdziwe, a co demonstracyjne

| Warstwa | Prawdziwe dane | Demo / statyczne |
|---|---|---|
| Konto i dostęp | Discord OAuth, rola, sesja, recheck | 2FA „włączone”, lista sesji, kod przy kluczach API |
| Rynek | notowania Yahoo, makro, Discord events | część opisów i domyślnych wartości w formularzach |
| Dziennik | ręczne transakcje, import CSV, sync giełd | domyślna transakcja w formularzu, MAE/MFE dla wpisów ręcznych |
| Statystyki | obliczenia z transakcji | puste stany z liczbami, 7/7, stałe +31,40/−42,10 |
| Akademia | harmonogram live z Discorda | postęp kursu 64%, odtwarzacz nagrań |
| Mentor AI | serwer MCP, 8 narzędzi read-only | statyczny pill „SERWER MCP GOTOWY”, Claude w UI |
| Płatności | brak | Stripe, e-mail, aktywacja planu demo |
| Bezpieczeństwo | sesje w DB, szyfrowanie kluczy | „Wyloguj inne” tylko lokalnie, 2FA bez odczytu |

---

## 3. Mapa funkcji

| Obszar | Co robi | Źródło danych | Stan obserwowany | Ocena |
|---|---|---|---|---|
| Logowanie i rola | Discord OAuth, gate roli Alpha, sesja 30 dni | Discord + MySQL | działa; recheck 15:45, `stale=false` | 5/5 |
| Pulpit | wynik dnia, limity, plan, mentor, makro, kapitał 30 dni | stan + API | czytelny, ale pełen zer i stałych podpowiedzi | 3/5 |
| Plan dnia | rynek, bias, poziomy, scenariusze, limity, zatwierdzenie | `plans[data]` + MySQL | zapis i autosave działają; walidacja zatwierdzenia zbyt słaba | 3,5/5 |
| Dziennik | lista, filtry, dodawanie, edycja, zamykanie, CSV | transakcje + MySQL | pusty; formularz startuje z demo-transakcją | 2,5/5 |
| Playbook | setupy, statystyki, zasady, wykres | `setups` | pusty; formularz czysty | 4/5 |
| Kalkulator | risk/reward, wielkość pozycji, FX, live price | Yahoo | matematyka poprawna; błąd copy 1:3 = 34% | 4/5 |
| Prop challenge | szablony, limity, dzienny loss, trailing | `prop` | pusty; szablony kompletne | 4/5 |
| Statystyki | wynik, PF, expectancy, MAE/MFE, kalendarz | transakcje | puste stany kłamią; 7/7 przy 4 punktach | 2,5/5 |
| Podatki PIT-38 | przychód, koszty, 19%, zdarzenia, CSV | `flows`, `tax` | pusty; poprawny rok i termin | 4/5 |
| Mentor AI | integracja MCP, 8 narzędzi read-only | `mcp.alphavault.pl` | serwer żyje; UI obiecuje 3 platformy, w tym Claude | 2,5/5 |
| Akademia | live, konsultacje, nagrania, materiały | Discord + stan | live prawdziwy; nagrania i 64% demo | 3/5 |
| Kalendarz makro | tydzień, filtry, powiadomienia | FF/TradingView | dane prawdziwe; „push na telefon” bez pokrycia | 3,5/5 |
| Ciało i głowa | check-in, gotowość, Oura/Apple | urządzenia | check-in obecny; karta gotowości pusta | 3/5 |
| Giełdy i API | WEEX/OKX/BingX/MT/CSV | API giełd + szyfrowanie | brak połączeń; copy zachęca do dowolnych uprawnień | 3/5 |
| Konto i zasady | profil, cele, zasady, konta, wpłaty, dane | stan + MySQL | funkcje są, ale sekcja bezpieczeństwa jest teatralna | 2/5 |
| PWA | instalacja, offline shell, powiadomienia lokalne | manifest + SW | instalacja gotowa; brak push po zamknięciu | 3/5 |
| Mobile | widoki 390 px | CSS/HTML | konto 896 px, nowa transakcja 467 px, nav 2186 px | 2/5 |

---

## 4. Co działa dobrze i wymaga ochrony

- **Logowanie i rola** — prawdziwy OAuth, recheck co 5 min, czytelny gate przy braku roli.
- **Plan dnia** — autosave, historia, „kopiuj z wczoraj”, poziomy i scenariusze; to najmocniejszy element produktu.
- **Kalkulator** — poprawna matematyka dla krypto i FX, w tym wartość pipsa dla USD/JPY; live price z Yahoo.
- **Prop challenge** — szablony FTMO/FundedNext/FundingPips/Topstep z limitami i trailing; dobry punkt startowy.
- **Kalendarz makro** — prawdziwe wydarzenia, strefa `Europe/Warsaw`, statusy i filtr ★★.
- **Discord events** — prawdziwe live'y i konsultacje; brak wydarzenia = puste pola, bez zmyślonych godzin.
- **MCP** — serwer żyje (`ok=true`, 8 narzędzi, `writesEnabled=false`), OAuth i audyt są przygotowane.
- **PWA** — manifest, service worker, instalacja, lokalne powiadomienia po zamknięciu transakcji w panelu.
- **Wydajność** — TTFB 178 ms, DOMContentLoaded 727 ms, load 809 ms, 2427 węzłów DOM; brak błędów aplikacji w konsoli.
- **Spójność wizualna** — ciemny motyw, typografia, hierarchia; login page Lighthouse: a11y 0.91, best practices 1.0, SEO 1.0.

---

## 5. Findings P0 — zaufanie, prawo, bezpieczeństwo

### P0-1. Płatności: obietnica Stripe bez integracji

**Dowody:**
- Landing: „Płatności obsługuje Stripe. Po zakupie dostajesz rolę na Discordzie…”.
- Modal zakupu: „Przejdź do płatności · Stripe” oraz „Płatność kartą, Apple Pay lub Google Pay obsługuje Stripe. Potwierdzenie zakupu i zgód dostaniesz e-mailem.”
- Po kliknięciu CTA pojawia się modal „Stripe nie jest podłączony” i przycisk „Aktywuj plan demo”.
- W repo brak integracji Stripe; `docs/FUNKCJE.md` i `PRD.md` jawnie oznaczają płatności jako demo.
- Cena w panelu: 168 $/mies. (full) i 49 $/mies. (solo); strona sprzedaży `alphamoves.vercel.app`: 249 $/mies. lub 983 zł. Brak wyjaśnienia różnicy.
- „Zarządzaj subskrypcją” prowadzi do strony sprzedaży, nie do panelu klienta (`/login` istnieje, ale linkuje do root).

**Wpływ:** użytkownik oczekuje prawdziwego checkoutu, e-maila i nadania roli. Otrzymuje demo. To ryzyko reklamacyjne, wizerunkowe i zgodnościowe.

**Minimalna zmiana:** usunąć z UI wszelkie twierdzenia o Stripe i e-mailu; przycisk zmienić na „Sprzedaż prowadzi Alpha Moves” albo ukryć zakup za flagą `PAYMENTS_ENABLED`; pokazywać cenę tylko z jednego źródła (konfiguracja/API), a przy braku źródła — bez ceny; link „Zarządzaj subskrypcją” kierować do realnego panelu (`/login`) lub usunąć.

**Kryteria akceptacji:** żaden tekst w UI nie twierdzi, że Stripe jest podłączony; kliknięcie CTA wyjaśnia realny kanał sprzedaży; w produkcji nie da się aktywować planu demo; cena w panelu i na stronie sprzedaży jest spójna albo cena nie jest pokazywana.

**Nakład:** S.

---

### P0-2. Bezpieczeństwo: deklaracje bez pokrycia

**Dowody:**
- „2FA na Discordzie — włączone ✓” jest statyczne. Aplikacja nie odczytuje statusu 2FA z Discorda i nie ma na to dowodu w `/api/me`.
- „Aktywne sesje: MacBook · Chrome · teraz, iPhone · wczoraj 21:14” pochodzi z demo-seedu `meta.sessions`, nie z tabeli `sessions`.
- „Wyloguj inne” ustawia lokalnie listę sesji na jedną; nie wywołuje endpointu unieważnienia sesji w DB.
- „Kod przy zmianie kluczy API” (`apiCode`) zapisuje tylko toast „Ustawienie zapisane”; brak TOTP, brak weryfikacji przy połączeniu giełdy.
- Tabela `sessions` istnieje i jest używana przez auth, ale nie ma endpointu listowania/unieważniania innych sesji.

**Wpływ:** użytkownik widzi fałszywy obraz bezpieczeństwa konta. To najgroźniejszy typ długu: obietnica ochrony bez implementacji.

**Minimalna zmiana:** usunąć status 2FA i listę sesji; zastąpić „Sesja na tym urządzeniu” i „Wyloguj” (działa); usunąć toggle „Kod przy zmianie kluczy API” albo wdrożyć realne TOTP; jeśli sesje mają zostać, dodać endpoint list/revoke i podłączyć UI. Nie deklarować funkcji, której nie ma.

**Kryteria akceptacji:** każde twierdzenie o bezpieczeństwie ma pokrycie w kodzie i DB; „Wyloguj inne” unieważnia sesje serwerowo albo przycisk nie istnieje; 2FA jest pokazywane tylko po realnym odczycie statusu.

**Nakład:** S (usunięcie) / M (implementacja).

---

### P0-3. MCP a polityka prywatności i zgody

**Dowody:**
- Polityka prywatności: „Mentor w Alpha Vault działa na regułach aplikacji… Nie wysyłamy Twoich danych do zewnętrznego modelu językowego (OpenAI, Anthropic ani innego dostawcy AI).”
- Strona `#/mentor-ai`: „Połącz swoje konto z ChatGPT, Claude lub Grok. Jeden serwer MCP udostępnia asystentowi funkcje Alpha Vault.”
- `mcp.alphavault.pl/health`: `ok=true`, `mcp.enabled=true`, `writesEnabled=false`, 8 narzędzi.
- Polityka nie zawiera rozdziału o MCP, odbiorcach danych, zakresie, retencji i wycofaniu zgody.
- Landing: „Transakcje, notatki i klucze API widzisz tylko Ty” — bez zastrzeżenia o zewnętrznym asystencie.

**Wpływ:** dane transakcyjne i notatki opuszczają aplikację po stronie użytkownika, a dokument prawny temu zaprzecza. To ryzyko RODO i zaufania.

**Minimalna zmiana:** dodać sekcję MCP do polityki (kategorie danych, odbiorcy, podstawa prawna, retencja, wycofanie), ekran zgody przed OAuth, jasny zakres read-only i link do polityki z `#/mentor-ai`; poprawić landing. Nie dodawać zapisu przez MCP.

**Kryteria akceptacji:** polityka opisuje MCP i zewnętrznych asystentów; ekran zgody pokazuje zakres i odbiorcę; token jest wycofywany przez użytkownika; UI nie twierdzi, że dane nie opuszczają aplikacji.

**Nakład:** M.

---

### P0-4. Claude jest reklamowany, ale nieobsługiwany

**Dowody:**
- UI: „3 DOSTĘPNE PLATFORMY: ChatGPT, Claude, Grok” oraz instrukcja „Połącz Alpha Vault z ChatGPT…”.
- Allowlista OAuth w `mcp/public/index.php`: `chatgpt.com`, `chat.openai.com`, `platform.openai.com`, `grok.com`, `x.ai`. Brak `claude.ai`/`anthropic.com`.
- `docs/MCP.md` wymienia ChatGPT/Grok i MCP Inspector; Claude nie jest wspierany.
- Brak statycznego tokenu API jako fallbacku.

**Wpływ:** użytkownik przechodzi instrukcję, która nie kończy się połączeniem. To koszt wsparcia i utrata zaufania.

**Minimalna zmiana:** usunąć Claude z UI i instrukcji; pokazywać 2 platformy; zaktualizować `docs/FUNKCJE.md` i `docs/MCP.md`; ewentualne wsparcie Claude dodać dopiero po wdrożeniu allowlisty i testu OAuth.

**Kryteria akceptacji:** UI pokazuje wyłącznie platformy z potwierdzoną ścieżką połączenia; brak martwych kroków.

**Nakład:** S.

---

### P0-5. Klucze API: UI zachęca do uprawnień, regulamin ich zabrania

**Dowody:**
- `public/regulamin.html`: „Podłączasz wyłącznie klucze API **tylko do odczytu**… OKX i BingX są weryfikowane pod kątem braku uprawnień do handlu i wypłat; WEEX wymaga klucza z działającym odczytem konta.”
- Landing: „WEEX, OKX, BingX i MetaTrader 4/5. Klucz API z dowolnymi uprawnieniami.”
- `#/gieldy`: „Handel i wypłaty nie blokują połączenia — klucz przejdzie z każdym zestawem uprawnień.”
- OKX/BingX są weryfikowane pod kątem braku uprawnień; WEEX przyjmuje każdy klucz z działającym odczytem.

**Wpływ:** ryzyko przyznania kluczowi prawa wypłat, wbrew regulaminowi i dobrej praktyce. To ryzyko bezpieczeństwa i sporów.

**Minimalna zmiana:** zmienić copy na „klucz tylko do odczytu”; dla WEEX dodać ostrzeżenie i, jeśli API to umożliwia, walidację braku handlu/wypłat; usunąć „dowolne uprawnienia” z landingu; zaktualizować `docs/FUNKCJE.md`.

**Kryteria akceptacji:** UI i regulamin mówią to samo; żaden tekst nie zachęca do uprawnień handlowych/wypłat; nieczytelne uprawnienia są odrzucane lub wyraźnie ostrzegane.

**Nakład:** S/M.

---

## 6. Findings P1 — dane i rdzeń produktu

### P1-1. Formularz nowej transakcji startuje z demo-transakcją

**Dowody:**
- Domyślne wartości: para `BTC/USDT`, wejście `69 120`, wielkość `0,01`, SL `68 500`, TP `70 800`, notatka „Retest 68 500 z planu…”.
- Checklista `4/4`, ocena `SUGEROWANA: A`, mimo że użytkownik nic nie wpisał.
- „Ryzyko: 0,01 USDT (1,00%)” przy kapitale 0 USDT — to limit z ustawień, nie ryzyko transakcji.
- Zapis jest możliwy bez zmiany pól; transakcja trafi do dziennika jako ręczna.

**Wpływ:** przypadkowa fikcyjna transakcja psuje statystyki, mentor i zaufanie do dziennika. Auto-ocena A bez wysiłku łamie sens checklisty.

**Minimalna zmiana:** puste pola i placeholdery; checklista `0/N`; ocena nieselektrowana do momentu wyboru; ryzyko liczone jako `|entry − SL| × size`, procent od kapitału; ostrzeżenie/blokada przy przekroczeniu `maxRisk`; przy kapitale 0 pokazać „—”.

**Kryteria akceptacji:** świeży formularz nie zawiera transakcji; checklista jest pusta; nie da się zapisać bez pary, wejścia i wielkości; ryzyko w UI równa się wyliczeniu; ryzyko > limit jest blokowane lub wymaga świadomego potwierdzenia.

**Nakład:** M.

---

### P1-2. MAE/MFE i czas trwania są fabrykowane dla wpisów ręcznych

**Dowód:** `AV.saveTrade` dopisuje `mae: 0.3`, `mfe: 0.9`, `durationMin: 0`, `fee: 0` do każdej ręcznej transakcji. Statystyki MAE/MFE i „czas trwania” korzystają z tych wartości.

**Wpływ:** analiza jakości wejścia i wyjścia jest fikcyjna. Użytkownik podejmuje decyzje na podstawie wymyślonych punktów.

**Minimalna zmiana:** dla wpisów ręcznych ustawiać `null` i wyświetlać „—”; MAE/MFE pokazywać tylko dla transakcji z importu lub ręcznego uzupełnienia; nie wliczać null do średnich.

**Kryteria akceptacji:** nowa ręczna transakcja ma puste MAE/MFE; statystyki pomijają null; wykres MAE/MFE nie zawiera punktów 0,3/0,9.

**Nakład:** S.

---

### P1-3. Puste statystyki pokazują fałszywe liczby i pochwały

**Dowody (konto z 0 transakcji):**
- „Transakcje bez SL: 1” — z `const total = st.n || 1`, więc `1 − 0 = 1`.
- „PROFIT FACTOR 0,00 / 0,00 ÷ 0,00” — dzielenie przez zero zamiast „—”.
- „Nie masz w tym okresie stratnych transakcji C. Tak trzymaj.” — pochwała przy zerze danych.
- „Pełna checklista a wynik: 7/7 · 0 tr., ≤6/7 · 0 tr.” — użytkownik ma 4 punkty checklisty, nie 7.
- „Opłaty i funding −0,00” — liczba ujemnego zera.

**Wpływ:** użytkownik nie wie, które liczby są prawdziwe. To podważa całą analitykę.

**Minimalna zmiana:** `total = st.n` bez `|| 1`; przy `n = 0` pokazywać „—”, „brak danych” i neutralny opis; etykiety `full/part` liczyć z `settings.checklist.length`; zero jako `0,00`, nie `−0,00`; usunąć pochwały przy braku danych.

**Kryteria akceptacji:** 0 transakcji = brak liczb innych niż 0/—; brak „Tak trzymaj”; przy 4 punktach checklisty etykiety to 4/4 i ≤3/4.

**Nakład:** S.

---

### P1-4. „Zgodnie z planem” liczone zbyt szeroko

**Dowód:** `plan: !!(plan && plan.approvedAt && setup !== 'Bez setupu')`. Każda transakcja z dowolnym setupem i dowolnym zatwierdzonym planem dnia jest liczona jako zgodna z planem — bez sprawdzenia pary, kierunku, poziomu ani ryzyka.

**Wpływ:** wskaźnik „ZGODNIE Z PLANEM” i historia „TRZYMANIE SIĘ PLANU” zawyżają dyscyplinę. Mentor chwali zachowanie, którego nie było.

**Minimalna zmiana:** liczyć zgodność tylko gdy para i kierunek odpowiadają planowi (docelowo także poziom wejścia i limit ryzyka); rozdzielić „plan istnieje” od „transakcja zgodna z planem”; przy braku danych pokazywać „—”.

**Kryteria akceptacji:** transakcja na innej parze lub w przeciwnym kierunku nie zwiększa zgodności; 0 transakcji = „—”; opis w UI wyjaśnia definicję.

**Nakład:** M.

---

### P1-5. Formularz pokazuje limit ryzyka, nie ryzyko transakcji

**Dowody:**
- `renderNTMeta` liczy `riskAmt = kapitał × maxRisk%` i pokazuje to jako „Ryzyko: 0,01 USDT (1,00%)”.
- Rzeczywiste ryzyko `|entry − SL| × size` jest liczone w `saveTrade`, ale nie jest wyświetlane ani zapisywane w transakcji.
- Plan dnia ma „Max ryzyko dziś 3%” (budżet dzienny), a ustawienia „Max ryzyko na trade 1%”. Relacja 3% = 3 × 1% nie jest nigdzie wyjaśniona.
- Zatwierdzenie planu wymaga tylko rynku i uzasadnienia; można zatwierdzić plan bez biasu, poziomów i limitów.

**Wpływ:** użytkownik myli limit z rzeczywistym ryzykiem; nie wie, ile traci na pozycji. Plan bywa zatwierdzony bez treści.

**Minimalna zmiana:** pokazywać oba pola: „Limit na trade 1%” i „Ryzyko tej pozycji X USDT (Y%)”; zapisywać `risk` w transakcji; przy `Y > 1%` ostrzegać/blokować; w planie opisać 3% jako dzienny budżet; wymagać biasu i co najmniej jednego poziomu przy zatwierdzeniu.

**Kryteria akceptacji:** ryzyko w formularzu równa się `|entry − SL| × size`; przekroczenie limitu jest widoczne; plan bez biasu/poziomu nie przechodzi zatwierdzenia; etykiety dzienne/per-trade są jednoznaczne.

**Nakład:** S/M.

---

### P1-6. Demo-seed przenika do realnych kont

**Dowody:**
- `AV.buildEmpty()` startuje od `AV.buildDemo()` i czyści tylko część pól.
- W realnym koncie widoczne: `joined: 2026-03-01`, `courseProgress: 64`, `streakBase: 12`, dwie sesje demo. `buildEmpty` nie resetuje tych pól.
- UI pokazuje „Trader Alpha od marzec 2026”, „Kurs krypto od A do Z 64%”, „Aktywne sesje: MacBook/iPhone”.
- Konto ma 0 transakcji i 0 wpłat, więc te wartości nie pochodzą z danych użytkownika.

**Wpływ:** nowy użytkownik widzi cudzą historię i postęp. To najprostsza droga do utraty zaufania w pierwszej minucie.

**Minimalna zmiana:** `buildEmpty()` ma być naprawdę puste: bez daty dołączenia, postępu kursu, sesji, streaka i demo-połączeń; subskrypcja wyłącznie z `/api/me`; migracja czyszcząca istniejące konta; test „świeże konto nie zawiera demo-wartości”.

**Kryteria akceptacji:** świeże konto ma puste/„—” pola, brak 64%, brak „od marzec 2026”, brak sesji; istniejące konta po migracji nie pokazują demo-danych.

**Nakład:** M.

---

### P1-7. Statyczne badge „Plan dnia ✓” i „LIVE”

**Dowody:**
- HTML nawigacji zawiera na stałe `Plan dnia <span class="badge g">✓</span>`; badge nie zależy od `approvedAt`.
- `render1.js`/`render2.js` ustawiają badge Akademii na `LIVE` dla każdego planu innego niż `solo`, niezależnie od tego, czy live trwa.
- Konto ma plan w stanie `SZKIC` (bez `approvedAt`), a nawigacja pokazuje ✓.
- Najbliższy live jest 16.11, a nawigacja pokazuje LIVE 13.11.

**Wpływ:** użytkownik otrzymuje sprzeczny sygnał o stanie planu i Akademii.

**Minimalna zmiana:** ✓ tylko przy `approvedAt`; w szkicu bez badge lub `!`; `LIVE` tylko gdy `liveNow`, inaczej brak badge lub `najbliższy live` w karcie.

**Kryteria akceptacji:** szkic nie ma ✓; brak live nie ma LIVE; stan nawigacji zmienia się po zatwierdzeniu planu i w trakcie live.

**Nakład:** S.

---

### P1-8. Obietnice synchronizacji i powiadomień

**Dowody:**
- Dziennik: „Pozycje z podłączonych giełd wpadają tu same co 30 minut.” Dokumentacja i AGENTS mówią o realnym sync max co 25 min.
- Kalendarz: „30 min przed ★★★ — push na telefon”; konto: „Push na telefon — limit osiągnięty”; Akademia: „Przypomnienia przychodzą same”.
- Implementacja to lokalne `Notification` API; brak Web Push i subskrypcji push. Powiadomienie działa, gdy panel jest otwarty lub transakcję zamyka się w panelu.
- `docs/FUNKCJE.md` oznacza push serwerowy jako ⛔.

**Wpływ:** użytkownik liczy na przypomnienie po zamknięciu aplikacji i nie otrzymuje go.

**Minimalna zmiana:** copy „do 25 minut”; „powiadomienie lokalne, gdy panel jest otwarty”; usunąć/oznaczyć toggle push jako lokalny; nie obiecywać push po zamknięciu; ewentualny Web Push wdrożyć osobno.

**Kryteria akceptacji:** brak twierdzeń o push po zamknięciu; 25 min w UI; toggle opisuje rzeczywiste działanie.

**Nakład:** S.

---

### P1-9. Tożsamość konta: demo-handle, data dołączenia, sesje

**Dowody:**
- `render1.js`: `(u.email || 'kasia.trades')` — przy braku e-maila UI pokazuje „kasia.trades”.
- Data „od marzec 2026” pochodzi z demo-seedu, nie z Discorda.
- Lista sesji pochodzi z `meta.sessions`, nie z DB.
- `/api/me` zwraca prawdziwy `username` i `globalName`, ale UI ich nie używa w tym miejscu.

**Wpływ:** użytkownik widzi nie swoją tożsamość i nieprawdziwe informacje o koncie.

**Minimalna zmiana:** używać `username`/`globalName` z `/api/me`; datę dołączenia pobierać z Discorda albo ukryć; usunąć demo-sesje.

**Kryteria akceptacji:** konto pokazuje prawdziwy handle lub neutralny tekst; data jest prawdziwa albo jej nie ma; brak demo-sesji.

**Nakład:** S.

---

### P1-10. Mobile: kluczowe widoki są ucięte

**Dowody (viewport 390 px, `clientWidth 382`):**
- `#/konto`: wewnętrzny grid ma 896 px i wystaje poza ekran; przycisk „Podgląd: subskrypcja wygasła” jest ucięty.
- `#/nowa-transakcja`: grid ma kolumny 264 px + 203 px w kontenerze 350 px; karta „Screeny wykresu” jest poza ekranem.
- `#/akademia`: karta „Konsultacje 1:1” ma 424 px.
- Nawigacja: `aside` ma `scrollWidth 2186 px` przy 382 px szerokości; 15 pozycji w jednym poziomym pasku bez menu i bez wyraźnego wskaźnika przewijania.
- Nagłówek „Plan dnia ✓” jest ucięty na 390 px.

**Wpływ:** PWA na telefonie jest reklamowana jako główna forma użycia, a kluczowe ekrany są nieczytelne.

**Minimalna zmiana:** usunąć inline `grid-template-columns` na rzecz klas responsywnych; dodać `min-width: 0` dla dzieci gridów; kolaps do 1 kolumny poniżej 900/560 px; mobilne menu (istniejące pozycje w rozwijanym panelu) lub wyraźny wskaźnik przewijania; testy wizualne 390 px dla pulpit/plan/dziennik/nowa/konto/akademia.

**Kryteria akceptacji:** na 390 px żaden kluczowy widok nie ma treści poza ekranem; wszystkie pozycje nawigacji są osiągalne; formularz nowej transakcji i screeny są w jednej kolumnie.

**Nakład:** M.

---

## 7. Findings P2 — polishing i treści

| ID | Problem | Dowód | Minimalna zmiana | Akceptacja |
|---|---|---|---|---|
| P2-1 | Kalkulator: „Przy 1:3 wystarczy 34%” | `index.html` i `actions.js`; próg `rr >= 3` przy zaokrągleniu 3,00 daje 34% | Liczyć `100 / (1 + rr)` i zaokrąglać; poprawić copy na 25% | 1:3 = 25%; 1:2 = 33% |
| P2-2 | Nagrania bez playera | `openRecording` pokazuje „Odtwarzanie w wersji produkcyjnej” | Link do realnego nagrania (Discord/YouTube) albo usunąć play | Kliknięcie prowadzi do treści, nie do demo |
| P2-3 | Pusta karta gotowości | `#/cialo-i-glowa` pokazuje SEN/HRV/TĘTNO/BODY BATTERY bez wartości | Ukryć kartę do pierwszego pomiaru; pokazać CTA „Połącz urządzenie” | Brak pustych metryk |
| P2-4 | Stare trasy `#/plan3`, `#/dziennik3`, `#/playbook3`, `#/akad3` | `viewFromHash` mapuje tylko krótkie ID; nieznane slugi → pulpit | Dodać aliasy `*-3` do routera | Stare linki otwierają właściwy widok |
| P2-5 | Dziennik: „Brak transakcji spełniających filtry” przy 0 transakcji | Pusty stan niezależny od filtra | Rozróżnić „brak transakcji” i „brak wyników filtra” | Komunikat pasuje do stanu |
| P2-6 | Kontrast i hierarchia nagłówków | Lighthouse: 4,48:1 i 4,36:1; `h3` po `h1` | Poprawić tokeny kontrastu i kolejność nagłówków | Lighthouse a11y ≥ 0,95 |
| P2-7 | Trzy zapytania `/api/quotes` przy starcie | 799–801 ms, trzy wywołania | Jedno zapytanie współdzielone przez ticker/kalkulator | Jedno wywołanie na start |
| P2-8 | „Oznacz jako setup, którego nie powinnaś grać” | Playbook, forma żeńska przy profilu męskim | „…którego nie grasz” lub forma zależna od profilu | Neutralna copy |
| P2-9 | „PDF dla księgowej” otwiera druk | `exportTaxPdf` korzysta z `window.print` | Etykieta „Drukuj / zapisz PDF” | Użytkownik wie, co się stanie |
| P2-10 | Statyczny pill „SERWER MCP GOTOWY” | Brak requestu health z UI; serwer żyje | Pokazać realny status i „tylko odczyt” | Pill odzwierciedla `/health` |
| P2-11 | „Zgodnie z planem dziś 0 z 0” | Plan w szkicu, 0 transakcji | „—” albo „Plan niezatwierdzony” | Brak 0/0 |
| P2-12 | „Widzisz to tylko Ty” vs MCP | Landing i polityka vs integracja AI | Doprecyzować: „do momentu podłączenia asystenta” | Spójny przekaz |

---

## 8. Plan wdrożenia bez nowych funkcji

Zasada: jedna zmiana = jeden PR = jeden zakres. Każdy PR przechodzi `PHP + API + UI`, deploy i health. Kolejność jest ważna: najpierw zaufanie, potem dane, potem mobile, na końcu polishing.

| Kolejność | PR | Zakres | Pliki | Testy | Nakład |
|---|---|---|---|---|---|
| 1 | `fix(trust): usunac deklaracje platnosci i bezpieczenstwa` | P0-1, P0-2, P0-4, P0-5, P1-8 | `public/index.html`, `public/js/actions.js`, `public/js/render1.js`, `public/regulamin.html`, `docs/FUNKCJE.md` | asercje tekstów UI; brak „Stripe”, „2FA włączone”, „Claude”, „dowolne uprawnienia” | 1 dzień |
| 2 | `docs(privacy): MCP i zgody` | P0-3 | `public/polityka-prywatnosci.html`, `docs/MCP.md`, `docs/FUNKCJE.md`, strona `#/mentor-ai` | test linków i sekcji; checklist prawna | 1 dzień |
| 3 | `fix(core): puste formularze, ryzyko, statystyki` | P1-1, P1-2, P1-3, P1-5 | `public/js/actions.js`, `public/js/render1.js`, `public/js/render2.js`, `public/js/state.js`, `public/index.html` | nowy test UI: formularz pusty, risk = wzór, 0 transakcji = „—” | 2–3 dni |
| 4 | `fix(data): demo-seed i badge stanu` | P1-4, P1-6, P1-7, P1-9 | `public/js/state.js`, `public/js/render1.js`, `public/js/render2.js`, migracja | test świeżego konta; test planu i live badge | 1–2 dni |
| 5 | `fix(mobile): responsywne gridy i menu` | P1-10 | `public/index.html`, `public/js/*.js` | Playwright 390 px dla 6 widoków | 1–2 dni |
| 6 | `chore(ui): polishing i dostepnosc` | P2-1…P2-12 | `public/index.html`, `public/js/*.js`, `public/sw.js` | Lighthouse, test tras, test copy | 1–2 dni |

### 8.1. Kolejność wewnątrz PR 1

1. Usunąć „Stripe” i e-mail z landingu, modala i CTA; ukryć `data-buy` w produkcji.
2. Usunąć „2FA włączone”, listę sesji i toggle `apiCode`; zastąpić „Sesja na tym urządzeniu”.
3. Usunąć Claude z listy platform i instrukcji.
4. Zmienić copy kluczy API na read-only.
5. Zmienić „30 minut” na „do 25 minut”; „push” na „powiadomienie lokalne”.
6. Dodać testy tekstowe, które blokują powrót tych fraz.

### 8.2. Kolejność wewnątrz PR 3

1. Formularz: puste pola, `0/N` checklisty, brak auto-A.
2. `risk` do payloadu; `riskPct` liczony z kapitału; walidacja `maxRisk`.
3. MAE/MFE `null` dla ręcznych; statystyki pomijają null.
4. `st.n || 1` → `st.n`; puste stany; dynamiczne `checkTotal`.
5. Testy jednostkowe i UI dla 0 transakcji oraz ryzyka.

### 8.3. Bramki produkcji

- `CI` = `success`, wymagany check `PHP + API + UI`.
- `Deploy AlphaVault` = `success`, krok `Verify production E2E` zielony.
- `/api/health`: `ok=true`, `db.ok=true`, `sync.status=success`, `sync.stale=false`.
- `/docs/` = 200, `.app/config.json` = 403, `/api/events` bez sesji = 401.
- `monitor-production.yml` = `success`.

---

## 9. Czego nie budować teraz

- **Stripe i pełny checkout** — decyzja biznesowa i prawna; do tego czasu usunąć obietnice, nie dodawać integracji.
- **Czat AI w aplikacji** — MCP z zewnętrznym asystentem wystarcza; kolejny czat dubluje funkcję.
- **Claude, Garmin, Whoop** — nie reklamować, dopóki nie ma potwierdzonej ścieżki i testu.
- **Nowe moduły i dashboardy** — 15 widoków wystarcza; najpierw naprawić istniejące.
- **Grywalizacja, rankingi, funkcje społecznościowe** — poza rdzeniem produktu.
- **Zaawansowana analityka** — najpierw poprawność obecnych statystyk.
- **Kreator onboardingu** — wystarczy stanowa karta „następny krok” z istniejących elementów.
- **Web Push** — najpierw uczciwe copy; pełny push to osobny projekt z VAPID i workerem.
- **Kolejne metryki zdrowia** — dokończyć Apple/Oura i dopiero wtedy rozszerzać.

---

## 10. Metryki i bramki jakości po naprawie

| Obszar | Metryka | Cel |
|---|---|---|
| Zaufanie | liczba niepotwierdzonych deklaracji w UI | 0 |
| Dane | demo-wartości w świeżym koncie | 0 |
| Statystyki | 0 transakcji = brak liczb innych niż 0/— | 100% |
| Ryzyko | ryzyko w formularzu = `abs(entry - SL) * size` | 100% |
| Plan | zgodność z planem liczona po parze i kierunku | 100% |
| Mobile | kluczowe widoki bez treści poza ekranem przy 390 px | 100% |
| MCP | polityka + zgoda + wycofanie tokenu | 100% |
| A11y | Lighthouse a11y | ≥ 0,95 |
| Wydajność | load | < 1 s (obecnie 0,8 s) |
| Produkcja | health, sync, docs, `.app` 403 | zielone |

---

## 11. Załącznik — dowody i ograniczenia

### 11.1. Snapshot produkcji (13.11.2026)

- `/api/health`: `ok=true`, `db.ok=true`, `discordConfigured=true`, `demoAllowed=false`, `sync.status=success`, `sync.stale=false`, `eventsCount=2`, `integrations.connections=0`.
- `mcp.alphavault.pl/health`: `ok=true`, `mcp.enabled=true`, `writesEnabled=false`, `tools=8`.
- `/docs/` = 200, `.app/config.json` = 403, `/api/events` bez sesji = 401.
- Konto użyte w audycie: 0 transakcji, 1 szkic planu, 0 wpłat, 0 challenge'ów, 0 połączeń, 0 aktywnych połączeń urządzeń; `maxRisk=1%`, checklista 4 punkty.
- Mobile: `clientWidth=382` przy viewport 390; konto 896 px, nowa transakcja 467 px, akademia 424 px, nav 2186 px.
- Wydajność: TTFB 178 ms, DOMContentLoaded 727 ms, load 809 ms, 97 zasobów, 2427 węzłów DOM.
- Lighthouse (login page): accessibility 0.91, best practices 1.0, SEO 1.0; kontrast 4,48:1 i 4,36:1.

### 11.2. Ograniczenia audytu

- Nie testowano płatności, OAuth poza istniejącą sesją, połączeń giełd, Oura/Apple, MetaTrader, importu CSV ani pełnego Web Push.
- Nie uruchamiano lokalnego builda, testów, PHP, MySQL ani Playwright — zgodnie z kontraktem GitHub E2E.
- Lighthouse wykonano tylko dla strony logowania; widoki zalogowane nie mają pełnego audytu a11y.
- Audyt jest snapshotem; kolejne PR zmieniają linie i zachowania.

### 11.3. Pliki źródłowe do przeglądu

- `public/index.html` — UI, copy, stany puste, formularze, sekcja bezpieczeństwa.
- `public/js/actions.js` — routing, zapis transakcji, plan, zakup, sesje, nagrania.
- `public/js/render1.js` — pulpit, plan, dziennik, profil, badge.
- `public/js/render2.js` — statystyki, Akademia, makro, giełdy, ustawienia.
- `public/js/state.js` — `buildEmpty`, `buildDemo`, stan, ryzyko, compliance.
- `public/polityka-prywatnosci.html` — MCP i dane zdrowotne.
- `public/regulamin.html` — klucze API i płatności.
- `mcp/public/index.php` — allowlista OAuth MCP.
- `docs/FUNKCJE.md` — status funkcji (demo vs E2E).
- `docs/MCP.md` — zakres MCP i platformy.

---

**Koniec audytu.**
