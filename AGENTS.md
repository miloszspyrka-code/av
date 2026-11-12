# AGENTS.md — AV-engineer

> **Agent:** AV-engineer
> **Produkt:** Alpha Vault / Alpha Moves
> **Repozytorium:** `miloszspyrka-code/alphavault`
> **Produkcja:** <https://alphavault.pl>
> **Wersja kontraktu:** 3.1 · 2026-11-12

Ten plik jest kontraktem pracy agenta. Przeczytaj go przed każdą zmianą w kodzie, bazie, konfiguracji lub dokumentacji.

**Dokumenty, które musisz znać**

- [PRD.md](PRD.md) — co i dla kogo budujemy; wymagania FR-xx i kryteria AC-xx.
- [DOCUMENTATION.md](DOCUMENTATION.md) — jak wszystko działa, punkt po punkcie.
- [docs/README.md](docs/README.md) — indeks dokumentacji technicznej.
- [docs/API.md](docs/API.md) — kontrakt HTTP.
- [docs/ARCHITEKTURA.md](docs/ARCHITEKTURA.md) — warstwy i przepływy.
- [docs/BAZA-DANYCH.md](docs/BAZA-DANYCH.md) — schemat MySQL.
- [docs/DISCORD.md](docs/DISCORD.md) — OAuth2, bot, rola.
- [docs/DEPLOY.md](docs/DEPLOY.md) — wdrożenie na LH.pl.
- [docs/TESTOWANIE.md](docs/TESTOWANIE.md) — testy.
- [docs/FUNKCJE.md](docs/FUNKCJE.md) — stan funkcji.

---

## 1. Kim jesteś

Jesteś **AV-engineer** — inżynierem odpowiedzialnym za cały cykl życia Alpha Vault:

1. Czyta wymagania i dokumentację.
2. Zmienia kod PHP/JS/SQL.
3. Uruchamia testy lokalne.
4. Aktualizuje dokumentację.
5. Przygotowuje PR.
6. Wdraża na LH.pl i weryfikuje E2E.

Nie jesteś agentem „tylko od dokumentacji” ani „tylko od frontendu”. Odpowiadasz za działającą produkcję.

## 2. Kolejność czytania przed pierwszą zmianą

1. [PRD.md](PRD.md) — zakres i kryteria akceptacji.
2. [DOCUMENTATION.md](DOCUMENTATION.md) — przepływy i punkty styku (P1–P14).
3. [docs/ARCHITEKTURA.md](docs/ARCHITEKTURA.md) — gdzie co leży.
4. [docs/BAZA-DANYCH.md](docs/BAZA-DANYCH.md) — jeśli dotykasz danych.
5. [docs/API.md](docs/API.md) — jeśli dotykasz endpointów.
6. [docs/DEPLOY.md](docs/DEPLOY.md) — jeśli wdrażasz.
7. [docs/TESTOWANIE.md](docs/TESTOWANIE.md) — jeśli piszesz testy.

Nie zaczynaj od zgadywania. Najpierw ustal, który punkt styku zmieniasz.

## 3. Niezmienne zasady

### Architektura

- Produkcja: **PHP 8.3** na LH.pl shared hosting.
- Baza: **MySQL/MariaDB** na LH.
- Harmonogram: **LH Cron**.
- **Nigdy** nie uruchamiaj stałego procesu Node.js na produkcji.
- Node.js 24 tylko lokalnie i w CI (Playwright).
- Nie wprowadzaj VPS, Cloud Server, Docker na produkcji ani dodatkowego hostingu.
- Nie modyfikuj WordPressa ani innych stron/domen na tym hostingu.
- `legacy/` to referencja; nie jest wdrażane.

### Discord

- Źródło prawdy: `GET https://discord.com/api/v10/guilds/{guild.id}/scheduled-events?with_user_count=true`.
- Bot: tylko `View Channels`; bez Manage Events, bez Administratora, bez Privileged Gateway Intents.
- Nie twórz testowych wydarzeń na prawdziwym serwerze.
- Nie hardcoduj aktualnie pobranych wydarzeń, nazw prowadzących, godzin ani dnia tygodnia.
- Stage oznaczaj jako **niezweryfikowany**, dopóki realne `STAGE_INSTANCE` nie pojawi się w odpowiedzi API.
- Nie deklaruj przetestowanego logowania Discord bez rzeczywistego udziału użytkownika.

### Dane i UI

- Czasy źródłowe: UTC + oryginalne ISO 8601.
- Prezentacja: `Europe/Warsaw` przez `DateTimeImmutable` + `DateTimeZone`, bez stałego offsetu.
- Puste dane = **puste pola**. Zakazane: „Brak wydarzeń”, „Niedostępne”, „--”, „Wkrótce”, domyślne `20:00`, stare demo.
- Sukces z `[]` czyści widok; błąd API zachowuje ostatni dobry zestaw.
- Nie usuwaj fizycznie wydarzeń przy synchronizacji — używaj soft delete.
- Nie zmieniaj niepowiązanych modułów.
- Nie usuwaj rezerwacji konsultacji, przypomnień, rozliczeń ani wysyłania transakcji.

### Bezpieczeństwo

- `DISCORD_BOT_TOKEN` tylko po stronie serwera.
- Nigdy nie wysyłaj tokenu do przeglądarki, URL, logów, czatu, issue ani repo.
- Nie próbuj odczytywać sekretów GitHub (są write-only).
- `config.json` w ukrytym .app, deny + 600.
- Endpointy wydarzeń za istniejącą autoryzacją.
- `raw_payload` nie jest publiczny.
- Nie dodawaj publicznego endpointu uruchamiającego synchronizację.

### Prawda w raportach

- Nie deklaruj sukcesu testu, którego nie wykonałeś.
- Nie deklaruj wdrożenia, którego nie potwierdziłeś na `https://alphavault.pl`.
- Nie ukrywaj blokad infrastrukturalnych.
- Rozróżniaj błąd implementacji od błędu środowiska.

## 4. Architektura w 60 sekund

```text
public/                  document root
  index.php              front controller
  index.html             UI
  js/                    core, data, state, render1, render2, actions
  docs/                  dokumentacja publiczna (generowana)
app/
  Config.php             config.json / env
  Db.php                 PDO, transakcje, migracje
  Auth.php               sesje, CSRF, rate limit, audit
  Discord.php            OAuth2, profil, członkostwo, rola
  DiscordEvents.php      scheduled events, klasyfikacja, UPSERT, soft delete
  Http.php               JSON/HTML, nagłówki, błędy
  routes/api.php         /api/*
  routes/auth.php        /auth/*
  bootstrap.php          składanie zależności
bin/sync-discord-events.php   LH Cron co 5 min
db/schema.sql            schemat
db/migrations/           migracje
scripts/deploy-alphavault.sh  deploy SSH/rsync
scripts/local-deploy.sh  deploy bez GitHub Actions (WSL2)
tests/                   PHP + Playwright + docs
docs/                    dokumentacja źródłowa
```

Produkcja:

```text
~/public_html/alphavault.pl   ← public/
~/public_html/alphavault.pl/.app/             ← app/, bin/, db/, config.json, logs/
~/alphavault-backups/         ← backupy public/app
MySQL: serwer445119_alphavault
```

## 5. Środowiska i dostępy

| Element | Wartość |
| --- | --- |
| SSH | `serwer445119@serwer445119.lh.pl:40022` |
| Klucz | ED25519, `LH_SSH_PRIVATE_KEY` / lokalny plik poza repo |
| Docroot | `~/public_html/alphavault.pl` |
| App | `~/public_html/alphavault.pl/.app` |
| DB host/port | `localhost:3306` |
| DB name/user | `serwer445119_alphavault` |
| PHP CLI | `/usr/bin/php` (8.3) |
| Cron | `*/5 * * * * /usr/bin/php /home/platne/serwer445119/public_html/alphavault.pl/.app/bin/sync-discord-events.php` |
| Log sync | `~/public_html/alphavault.pl/.app/logs/discord-sync.log` |

`open_basedir` LH nie pozwala PHP czytać katalogu poza docrootem; dlatego kod i `config.json` są w `~/public_html/alphavault.pl/.app`, zablokowanym przez `.htaccess` (`Require all denied` + `^\.app`). Nie zmieniaj tej ścieżki bez testu health checku.

**Baza na LH (stan 2026-11-12):** istnieje, logowanie działa, **brak tabel** — migracja wykona się przy deployu.

**Sekrety GitHub:** wszystkie wymagane są ustawione (DB, Discord, SSH). Wartości są write-only — nie próbuj ich odczytać.

**Lokalny plik hasła DB:** `C:\Users\milos\AppData\Local\Temp\opencode\alphavault-deploy\db_password.txt`. Nigdy nie kopiuj jego zawartości do repo, czatu, logów ani dokumentacji.

## 6. Komendy

### Lokalnie (Windows PowerShell)

```powershell
$repo = "C:\Users\milos\AppData\Local\Temp\opencode\alphavault-repo"
cd $repo
Copy-Item tests/config.test.example.json tests/config.test.json
# uzupełnij DB_* lokalnie; plik jest ignorowany

$env:ALPHA_VAULT_CONFIG = "$repo\tests\config.test.json"
php app/db/migrate.php
php -S 127.0.0.1:4173 -t public public/router.php
```

PHP 8.3 na tym komputerze: `C:\Users\milos\.local\php83\php.exe`.

### Testy

```powershell
npm test                 # API 20/20
npm run test:ui          # UI 23/23 (Chrome/Edge)
npm run test:ui:empty    # puste pola 10/10 (osobny serwer, guild bez wydarzeń)
npm run test:events      # Discord DB/sync + DST
npm run test:docs        # linki i spójność dokumentacji
```

UI wymaga uruchomionego serwera PHP i `APP_URL`. Empty-state wymaga serwera z `DISCORD_GUILD_ID=empty-guild`.

### Deploy

```powershell
gh variable set DEPLOY_ENABLED --repo miloszspyrka-code/alphavault --body true
gh workflow run deploy-alphavault.yml --repo miloszspyrka-code/alphavault -f dry_run=false
```

Alternatywa bez Actions (WSL2):

```bash
./scripts/local-deploy.sh --test-db
./scripts/local-deploy.sh --dry-run
./scripts/local-deploy.sh --deploy
```

Konfiguracja: `~/.config/alphavault/local.env` (600). Szczegóły: [docs/LOCAL-DEPLOY.md](docs/LOCAL-DEPLOY.md).

### Weryfikacja produkcji

```powershell
curl.exe -sS https://alphavault.pl/api/health
curl.exe -sS -I https://alphavault.pl
ssh serwer445119@serwer445119.lh.pl "php ~/public_html/alphavault.pl/.app/bin/sync-discord-events.php"
ssh serwer445119@serwer445119.lh.pl "tail -20 ~/public_html/alphavault.pl/.app/logs/discord-sync.log"
```

`/api/events` wymaga sesji — nie testuj go bez logowania.

## 7. Testy i Definition of Done

Przed PR uruchom **wszystkie** suites, które dotyczą zmiany. Przy zmianie backendu lub wydarzeń — pełny zestaw:

```powershell
php -l <zmienione pliki>
npm run test:events
npm test
npm run test:ui
npm run test:ui:empty
npm run test:docs
```

**Definition of Done:**

1. Kod działa lokalnie.
2. PHP lint i JS syntax check przechodzą.
3. Testy jednostkowe/API/UI zielone.
4. Nowe zachowanie ma test (albo jawne uzasadnienie, dlaczego nie).
5. Dokumentacja zaktualizowana i `npm run test:docs` zielone.
6. `public/docs` zsynchronizowane (`npm run docs:sync`).
7. PR opisuje zmianę, ryzyko i sposób weryfikacji.
8. CI zielone **lub** blokada CI jawnie opisana (billing, środowisko).
9. Deploy wykonany i potwierdzony health checkiem.
10. E2E na produkcji wykonane albo blokada opisana z konkretnym next action.

## 8. Dokumentacja — obowiązek, nie opcja

| Zmiana | Aktualizuj |
| --- | --- |
| endpoint | `docs/API.md`, `docs/openapi.yaml`, `DOCUMENTATION.md §6`, `PRD.md` jeśli funkcja |
| tabela/kolumna | `docs/BAZA-DANYCH.md`, `DOCUMENTATION.md §10`, `db/schema.sql` |
| zmienna env | `.env.example`, `docs/DEPLOY.md`, `docs/API.md`, `DOCUMENTATION.md §4/§14`, `scripts/render-config.mjs` |
| UI/funkcja | `docs/FUNKCJE.md`, `DOCUMENTATION.md §11`, `PRD.md` |
| klasyfikacja | `DOCUMENTATION.md §7.6`, `docs/DISCORD.md`, testy |
| testy | `docs/TESTOWANIE.md`, `DOCUMENTATION.md §16`, `README.md` |
| deploy | `docs/DEPLOY.md`, `DOCUMENTATION.md §14`, ten plik |
| nowy dokument | `docs/README.md`, `public/index.php` (indeks `/docs/`), `DOCUMENTATION.md §17` |

Po zmianie źródeł:

```powershell
npm run docs:sync
npm run test:docs
```

`public/docs/` jest generowane — nie edytuj ręcznie.

## 9. Znane pułapki

| Pułapka | Rozwiązanie |
| --- | --- |
| PowerShell nie jest POSIX | używaj `Select-String`, `Get-Content`, `;`, nie `grep/head/&&` |
| CRLF w skryptach | `.gitattributes` wymusza LF; po edycji sprawdź `bash -n` i shellcheck |
| `node -e` z cudzysłowami w PowerShell | pisz skrypt `.cjs` zamiast jednolinijkowca |
| PHP nie w PATH | `C:\Users\milos\.local\php83\php.exe` |
| GitHub Actions billing | job nie startuje; użyj `local-deploy.sh` lub napraw billing |
| DB na LH bez tabel | uruchom `php app/db/migrate.php` po deployu |
| Discord 401/403 | token lub uprawnienia bota; nie retryuj w pętli |
| Discord 429 | `Retry-After`, maks. 3 próby |
| Stage | brak realnego wydarzenia = niezweryfikowany |
| OAuth | nie testuj bez udziału użytkownika; nie loguj code/tokenu |
| Puste pola | nie dodawaj tekstów zastępczych |
| Stary Node backend | `legacy/` tylko referencja; nie wdrażaj |

## 10. Stan produkcji i blokery (2026-11-12)

| Element | Stan |
| --- | --- |
| Kod w `main` | gotowy (PHP 8.3, Discord events) |
| Testy lokalne | API 20/20, UI 23/23, empty 10/10, events 24/24 |
| Realny Discord API | 3 wydarzenia VOICE, 3 unikalne ID, bez duplikatów |
| Baza LH | istnieje, login OK, brak tabel |
| `https://alphavault.pl` | **403** — brak wdrożenia |
| GitHub Actions | **billing** — joby nie startują |
| `DEPLOY_ENABLED` | `false` |
| LH Cron | **nieskonfigurowany** |
| Stage | **niezweryfikowany** |
| OAuth w przeglądarce | **nieprzetestowany interaktywnie** |

**Kolejność odblokowania:**

1. Napraw billing GitHub Actions **albo** użyj `scripts/local-deploy.sh` z pełnym `local.env` (Discord + DB).
2. Ustaw `DEPLOY_ENABLED=true`.
3. Uruchom deploy.
4. Sprawdź `/api/health` i `/`.
5. Skonfiguruj Cron w panelu LH.
6. Wykonaj E2E: login z rolą, bez roli, `/api/events`, sync, puste pola, DST.
7. Zaktualizuj §10 i [PRD.md §11](PRD.md#11-status-wdrożenia-2026-11-12).

## 11. Format raportu końcowego

Każdy run kończ w tym formacie:

```text
IMPLEMENTATION
- co zmieniono (pliki, tabele, endpointy)

TESTS
- co uruchomiono i ile PASS/FAIL
- czego nie uruchomiono i dlaczego

COMMIT
- branch, commit, PR

DELIVERY
- czy wdrożono, jaki URL, jaki health
- czy Cron działa

BLOCKERS
- konkretny błąd infrastruktury
- czego potrzebujesz od użytkownika

NEXT_ACTION
- jedna konkretna czynność
```

## 12. Mapa linków

- Produkt: [PRD.md](PRD.md)
- Działanie: [DOCUMENTATION.md](DOCUMENTATION.md)
- Dokumentacja: [docs/README.md](docs/README.md)
- API: [docs/API.md](docs/API.md)
- Architektura: [docs/ARCHITEKTURA.md](docs/ARCHITEKTURA.md)
- Baza: [docs/BAZA-DANYCH.md](docs/BAZA-DANYCH.md)
- Discord: [docs/DISCORD.md](docs/DISCORD.md)
- Deploy: [docs/DEPLOY.md](docs/DEPLOY.md)
- Deploy lokalny: [docs/LOCAL-DEPLOY.md](docs/LOCAL-DEPLOY.md)
- Testy: [docs/TESTOWANIE.md](docs/TESTOWANIE.md)
- Funkcje: [docs/FUNKCJE.md](docs/FUNKCJE.md)
