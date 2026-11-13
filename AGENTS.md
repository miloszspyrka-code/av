# AGENTS.md — AV-engineer

> **Agent:** AV-engineer
> **Produkt:** Alpha Vault / Alpha Moves
> **Repozytorium:** `miloszspyrka-code/av` (publiczne)
> **Produkcja:** <https://alphavault.pl>
> **Wersja kontraktu:** 3.2 · 2026-11-12

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
3. Weryfikuje zmianę wyłącznie w GitHub Actions.
4. Aktualizuje dokumentację.
5. Przygotowuje PR i doprowadza CI do zieleni.
6. Wdraża przez GitHub Actions na LH.pl i weryfikuje E2E produkcji.

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

### Repozytorium i GitHub

- **Jedyna ścieżka weryfikacji i wdrożenia to GitHub Actions.** Lokalny build, testy, serwer PHP, baza, Playwright, SSH/rsync i `scripts/local-deploy.sh` są zabronione.
- Zakaz zostawiania zmian wyłącznie lokalnie. Każda zmiana = branch + commit + push + PR.
- Zakaz pracy bezpośrednio na `main`; `main` zmienia się wyłącznie przez merge PR.
- Po zakończeniu pracy `git status --porcelain` musi być czysty, a `git log --oneline @{u}..HEAD` pusty.
- Zaległe lokalne zmiany bez remote wypychaj w tej samej sesji; raport bez push nie zamyka zadania.
- Brak push (uprawnienia, sieć, blokada brancha) = BLOCKER; zadanie nie jest wtedy done.
- Sekrety i runtime (`.env`, `config.json`, `.runtime/`, cache) nie trafiają do repo.
- Dowodem są runy GitHub Actions (PR CI, deploy, monitor), nie lokalne polecenia.

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
scripts/deploy-alphavault.sh  deploy SSH/rsync (wywoływany wyłącznie przez GitHub Actions)
tests/                   PHP + Playwright + docs
docs/                    dokumentacja źródłowa
.github/workflows/       CI, deploy, monitoring
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
| DB host/port | `main195.lh.pl:3306` (panel LH); `localhost` też działa z procesów na serwerze |
| DB name/user | `serwer445119_alphavault` |
| PHP CLI | `/usr/bin/php` (8.3) |
| LH Cron (jedyny wpis) | `*/5 * * * * /bin/bash /public_html/alphavault.pl/.app/cron/sync-discord-events.sh` |
| Cron — zadania pochodne | wrapper uruchamia `bin/sync-discord-events.php` (Discord events + exchange `syncDue`) i `bin/check-discord-roles.php` (recheck roli) |
| Cron — ręcznie | `/usr/bin/php /home/platne/serwer445119/public_html/alphavault.pl/.app/bin/sync-discord-events.php` |
| Logi Cron | `~/public_html/alphavault.pl/.app/logs/discord-sync.log`, `~/public_html/alphavault.pl/.app/logs/macro-sync.log` |

`open_basedir` LH nie pozwala PHP czytać katalogu poza docrootem; dlatego kod i `config.json` są w `~/public_html/alphavault.pl/.app`, zablokowanym przez `.htaccess` (`Require all denied` + `^\.app`). Nie zmieniaj tej ścieżki bez testu health checku.

**Baza na LH (stan 2026-11-12):** istnieje, logowanie działa, tabele utworzone przez deploy.

**Sekrety GitHub:** wszystkie wymagane są ustawione (DB, Discord, SSH). Wartości są write-only — nie próbuj ich odczytać.

**SSH na LH:** dopuszczone wyłącznie do odczytu logów (break-glass), nigdy do deployu, rsync, migracji ani testów. Standardowa ścieżka to GitHub Actions.

### 5.1. LH Cron — jedno źródło automatyzacji

**Stan zweryfikowany 2026-11-12:** w panelu LH jest **jeden** wpis Cron:

```text
*/5 * * * * /bin/bash /public_html/alphavault.pl/.app/cron/sync-discord-events.sh
```

Wrapper `cron/sync-discord-events.sh` wykonuje kolejno:

| Automatyzacja | Skrypt | Kadencja | Dowód |
| --- | --- | --- | --- |
| Synchronizacja Discord events | `bin/sync-discord-events.php` | co 5 min | `logs/discord-sync.log` |
| Synchronizacja integracji giełd/MetaTrader | ten sam skrypt, `Integrations::syncDue()` | co 5 min, realny sync max co 25 min | `health.integrations.sync` |
| Recheck roli Alpha | `bin/check-discord-roles.php` | co 5 min po sync | `health.roleRecheck` |
| Kalendarz makro | `bin/sync-macro-events.php` | co 15 min (throttle `.runtime/macro.stamp`) | `logs/macro-sync.log` |
| Synchronizacja urządzeń | `bin/sync-wearables.php` | co 30 min (throttle `.runtime/wearables.stamp`) | `logs/wearables-sync.log` |
| Synchronizacja dat demo | `bin/sync-demo-state.php` | co 60 min (throttle `.runtime/demo.stamp`) | stdout Cron + `revision` w `user_states` |

**Wszystkie cykliczne zadania wynikają z jednego wpisu `*/5`.** Makro, urządzenia i daty demo są wywoływane przez wrapper z throttle; nie wymagają osobnych wpisów w panelu LH.

**Zasada:** wszystkie automatyzacje wynikają z jednego wpisu `*/5`. Nową cykliczną czynność dopinaj do `cron/sync-discord-events.sh` z własnym throttle i wpisem w tabeli powyżej; nie dodawaj drugiego wpisu w panelu LH. `crontab` przez SSH nie jest dostępny (`/var/spool/cron` nie istnieje) — harmonogram zmienia się wyłącznie w panelu.

**Weryfikacja E2E (bez sesji i sekretów):**

```powershell
curl.exe -sS https://alphavault.pl/api/health
```

Wymagane: `sync.lastSuccessAt` i `roleRecheck.lastSuccessAt` młodsze niż 10 min, `sync.status=success`, `roleRecheck.status=success`, `sync.stale=false`. Panel: LH.pl → Serwery → `serwer445119` → Cron / Harmonogram zadań.

## 6. GitHub E2E — jedyna ścieżka zmiany i wdrożenia

**Zasada:** lokalnie wolno wyłącznie czytać repo, edytować pliki i uruchomić generator `public/docs`. Build, testy, baza, serwer PHP, Playwright, SSH/rsync i deploy uruchamia tylko GitHub Actions.

### 6.1. Diagnoza i branch

```powershell
$repo = "C:\Users\milos\AppData\Local\Temp\opencode\alphavault-repo"
Set-Location $repo
git fetch origin
git switch -c <typ>/<slug> origin/main
```

Przed edycją ustal punkt styku P1–P14 i pliki. Nie zakładaj, że lokalny `main` jest aktualny.

### 6.2. Zmiana i generator dokumentacji

```powershell
# edytuj tylko źródła: app/, public/ (poza public/docs), tests/, docs/, pliki główne
node scripts/sync-docs.mjs
git status --short
```

`node scripts/sync-docs.mjs` to jedyny dozwolony lokalny generator. Nie uruchamiaj `npm test`, `npm run test:*`, `php -l`, `php -S`, MySQL, Playwright ani skryptów deployu.

### 6.3. Commit, push, PR

```powershell
git add -A
git commit -m "<typ>(<zakres>): <opis>"
git push -u origin HEAD

gh pr create --repo miloszspyrka-code/av --base main --head <branch> `
  --title "<typ>(<zakres>): <opis>" --body-file .github/pull_request_template.md
```

GitHub MCP (preferowane, gdy dostępne):

```text
tools.github.create_pull_request({ owner: "miloszspyrka-code", repo: "av", base: "main", head: "<branch>", title, body })
tools.github.pull_request_read({ owner: "miloszspyrka-code", repo: "av", pullNumber, method: "get_check_runs" })
tools.github.actions_list({ owner: "miloszspyrka-code", repo: "av", method: "list_workflow_runs", resource_id: "ci.yml" })
tools.github.get_job_logs({ owner: "miloszspyrka-code", repo: "av", run_id, failed_only: true, return_content: true, tail_lines: 200 })
```

### 6.4. Bramka CI

Wymagany check: `PHP + API + UI` w `.github/workflows/ci.yml`. PR nie jest gotowy, dopóki check nie jest `success`.

- Czerwone CI: przeczytaj logi failed jobs, popraw źródło, pushnij nowy commit. Nie odpalaj testów lokalnie.
- Nie dodawaj `continue-on-error`, nie usuwaj testów i nie osłabiaj progów, żeby przejść CI.
- `gh pr checks <PR> --repo miloszspyrka-code/av` uruchamiaj bez `--watch`; odpytuj co 30–60 s.

### 6.5. Merge i deploy

Merge wyłącznie przez PR, po zielonym CI:

```text
tools.github.merge_pull_request({ owner: "miloszspyrka-code", repo: "av", pullNumber, merge_method: "squash" })
```

Po merge do `main`:

1. CI na `main` musi być `success`.
2. `deploy-alphavault.yml` startuje z `workflow_run` CI i wymaga `DEPLOY_ENABLED=true`.
3. Deploy jest poprawny tylko wtedy, gdy run `Deploy AlphaVault` ma `success` i przeszedł krok `Verify production E2E`.

Ręczny deploy tylko dla dry-run, rollbacku lub po awarii automatu:

```powershell
gh workflow run deploy-alphavault.yml --repo miloszspyrka-code/av -f dry_run=true
gh workflow run deploy-alphavault.yml --repo miloszspyrka-code/av -f dry_run=false
gh workflow run deploy-alphavault.yml --repo miloszspyrka-code/av -f rollback=true
```

### 6.6. Weryfikacja produkcji

```powershell
curl.exe -sS https://alphavault.pl/api/health
curl.exe -sS -o NUL -w "%{http_code}" https://alphavault.pl/docs/
curl.exe -sS -o NUL -w "%{http_code}" https://alphavault.pl/.app/config.json
curl.exe -sS -o NUL -w "%{http_code}" https://alphavault.pl/api/events
gh workflow run monitor-production.yml --repo miloszspyrka-code/av
```

Wymagane wartości:

- health: `ok=true`, `db.ok=true`, `discordConfigured=true`, `roleCheckConfigured=true`, `eventsConfigured=true`, `demoAllowed=false`, `sync.status="success"`, `sync.stale=false`;
- `/docs/` = 200, `.app/config.json` = 403, `/api/events` bez sesji = 401;
- `monitor-production.yml` = `success`.

`/api/events` wymaga sesji — 401 bez logowania jest oczekiwane. Nie loguj się na produkcji bez zgody użytkownika.

### 6.7. Deploy MCP

`mcp.alphavault.pl` wdraża workflow `Deploy AlphaVault MCP` (`.github/workflows/deploy-mcp.yml`), uruchamiany z `main` z inputem `ref`:

```powershell
gh workflow run deploy-mcp.yml --repo miloszspyrka-code/av --ref main -f ref=<branch> -f dry_run=true
gh workflow run deploy-mcp.yml --repo miloszspyrka-code/av --ref main -f ref=<branch> -f dry_run=false
```

Workflow używa tych samych sekretów SSH/DB/Discord co `alphavault.pl`, uruchamia `composer install` i `scripts/deploy-mcp.sh`, wykonuje migrację, klucze OAuth, health check i nie dotyka innych domen. Deploy jest poprawny dopiero po zielonym runie, health checku `https://mcp.alphavault.pl/health` i discovery `/.well-known/oauth-protected-resource`.

MCP 2.1: 17 narzędzi odczytu zawsze; 11 narzędzi zapisu (`propose_*`, `approve_proposal`, `reject_proposal`) tylko przy `MCP_WRITES_ENABLED=true` i scope `alphavault.write`. Produkcja pozostaje na `MCP_WRITES_ENABLED=false` do czasu pilotażu. Zatwierdzanie w panelu `https://alphavault.pl` albo w rozmowie jednorazowym kodem z panelu (`POST /api/mcp/proposals/{id}/approval-code`, TTL 10 min, limit 5 prób). Agent nie może zatwierdzić własnej propozycji; `userConfirmed` nie jest dowodem zgody. Po włączeniu zapisu klient musi ponownie przejść OAuth, żeby uzyskać nowy scope.

## 7. Testy i Definition of Done

**Wszystkie testy uruchamia CI.** Nie uruchamiaj ich lokalnie i nie traktuj lokalnego wyniku jako dowodu. Zakres suites zależy od zmiany, ale bramką PR jest cały workflow `CI` (`PHP + API + UI`).

Zakres CI:

- dokumentacja: `sync-docs --check` + `tests/docs.test.mjs`,
- PHP lint `app/` i `public/`,
- migracja MySQL 8.4,
- role, Discord DB/sync, makro, notowania, daty demo, prop, router, izolacja, urządzenia, API, UI, empty-state, PWA, podatki, kodowanie,
- integracje giełd/MetaTrader/API i ich UI na mockach.

**Definition of Done:**

1. Zmiana na branchu i PR; brak pushu bezpośrednio na `main`.
2. CI `PHP + API + UI` = `success` (run ID/link w raporcie).
3. Nowe zachowanie ma test w CI albo jawne uzasadnienie, dlaczego nie.
4. Dokumentacja zaktualizowana wg §8; `docs:check` zielone w CI.
5. `public/docs` wygenerowane przez `node scripts/sync-docs.mjs` i zgodne w CI.
6. PR opisuje zmianę, ryzyko i weryfikację.
7. Merge przez PR (squash).
8. Deploy `Deploy AlphaVault` = `success` dla zmian produkcyjnych.
9. Produkcja: health OK, `/docs/` 200, `.app` 403, `sync.status=success`, `sync.stale=false`.
10. `monitor-production.yml` = `success`; raport zawiera run ID, nie lokalne PASS-y.

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
node scripts/sync-docs.mjs
```

`public/docs/` jest generowane — nie edytuj ręcznie. Weryfikację dryfu wykonuje CI (`node scripts/sync-docs.mjs --check` + `node tests/docs.test.mjs`).

## 9. Znane pułapki

| Pułapka | Rozwiązanie |
| --- | --- |
| Lokalny build/test | zakazany; dowodem jest run GitHub Actions, nie lokalny PASS |
| `execute` w Code Mode | nie ma tam `tools.shell`; `shell`, `read`, `edit` są narzędziami najwyższego poziomu, a `execute` służy do `tools.github.*` |
| `gh pr checks --watch` | potrafi wisieć; odpytywaj bez `--watch` co 30–60 s albo użyj MCP `pull_request_read` |
| Stary lokalny `main` | zawsze `git fetch origin` i branch od `origin/main` przed edycją |
| `public/docs` dryf | edytuj źródło i uruchom `node scripts/sync-docs.mjs`; CI sprawdza dryf |
| PowerShell nie jest POSIX | używaj `Select-String`, `Get-Content`, `;`, nie `grep/head/&&` |
| CRLF w skryptach | `.gitattributes` wymusza LF; nie zmieniaj liniowo zakończeń |
| GitHub Actions billing | repo publiczne — standard runners są darmowe; blokada billingu = BLOCKER, nie lokalny deploy |
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
| Testy (ostatni pełny CI) | prop 27/27, prop parity, router 18/18, API 23/23, UI 58/58, empty 12/12, events 24/24, roles 18/18, macro 21/21, quotes 14/14, demo-state 15/15, tax 8/8, wearables 42/42 + config 5/5, PWA 20/20, integracje 20/20, UI integracje 13/13, izolacja 9/9, MCP PHP 30/30, MCP E2E read+writes+approve, MCP login, MCP panel |
| Realny Discord API | aktualna liczba w `/api/health` → `sync.eventsCount` (2026-11-12 21:49 UTC: 2) |
| Baza LH | tabele utworzone (27), w tym 4 `wearable_*`, 4 `exchange_*` i 9 `mcp_*`; migracje `20261114_mcp2.sql` (`payload_hash`, `kind`) i `20261115_mcp_approval.sql` (`diff_hash`, kod jednorazowy, idempotencja decyzji) |
| Integracje | `configured=true` (`INTEGRATIONS_KEY`); `metaApiConfigured=false` (brak `METAAPI_TOKEN`); 0 połączeń |
| Cron integracji | `success` (health `integrations.sync`), 0 połączeń, 0 importów; wewnątrz 5-min wrappera, realny sync max co 25 min |
| Kalendarz makro | `macro_events` + `/api/macro-events`, sync FF/TradingView; wrapper `*/5` uruchamia sync co 15 min (throttle, bez osobnego wpisu) |
| `https://alphavault.pl` | **200** — wdrożona |
| GitHub Actions | działa — repo publiczne, standard runners free |
| `DEPLOY_ENABLED` | `true` |
| CI gate | `PHP + API + UI` wymagany; PR + branch protection na `main` |
| Auto-deploy | po zielonym CI na `main` (`workflow_run` CI → `deploy-alphavault.yml`) przy `DEPLOY_ENABLED=true`; environment `alphavault-production` tylko z `main` |
| Repo / security | publiczne `miloszspyrka-code/av`; secret scanning + push protection + Dependabot |
| Prod sync | `success`, liczba w `sync.eventsCount` (2026-11-12 21:49 UTC: 2) |
| Prod UI (read-only) | PASS — live, harmonogram, puste konsultacje |
| PWA produkcja | manifest 200 `application/manifest+json`, SW 200 `no-cache`, ikony `/assets/pwa/*` 200; `Page.getInstallabilityErrors` bez błędów |
| PWA na fizycznym telefonie | **niezweryfikowane** — wymaga instalacji i zgody na powiadomienia |
| Prod urządzenia | `/api/wearables` anon = 401; UI „Ciało” wdrożone; tabele utworzone; **Oura skonfigurowana** (`ouraConfigured=true`, deploy 3.8.0 z 2026-11-12); Garmin i Whoop wyłączone |
| Apple Health | backend + kod parowania gotowe i przetestowane; aplikacja iPhone poza repo |
| Cron urządzeń | wrapper `*/5` uruchamia `bin/sync-wearables.php` co 30 min (throttle, bez osobnego wpisu) |
| LH Cron | **1 wpis `*/5`**; `sync` i `roleRecheck` świeże (<10 min), `stale=false` |
| Monitoring | `monitor-production.yml` — co 15 min: health, sync, UI, docs, `.app` 403 |
| Stage | **niezweryfikowany** |
| OAuth w przeglądarce | **potwierdzony 2026-11-12** — konto z rolą → sesja; konto bez roli → 403 |

**Pozostałe czynności:**

1. Dokończ test OAuth: konto spoza serwera → 403. Konto z rolą i konto bez roli potwierdzone 2026-11-12.
2. Potwierdź Stage, jeśli pojawi się realne `STAGE_INSTANCE`.
3. Potwierdź instalację PWA i powiadomienia na fizycznym telefonie (iPhone Safari + Android Chrome).
4. Oura: redirect `https://alphavault.pl/auth/wearables/oura/callback` zarejestrowany w Oura Cloud (2026-11-12); wykonaj interaktywny test połączenia; sekrety `OURA_*` są wdrożone.
5. Dodaj sekrety `WHOOP_*`; dla Garmina przejdź onboarding Health API i ustaw `GARMIN_*` (obecnie oba wyłączone w UI/API).
6. Makro (15 min) i urządzenia (30 min) działają z wrappera `*/5` przez throttle; nowe cykliczne zadania dopinaj tam, bez nowego wpisu w panelu LH.
7. Włącz monitoring uptime i backup offsite (opcjonalne, poza pipeline).

## 11. Format raportu końcowego

Każdy run kończ w tym formacie:

```text
IMPLEMENTATION
- co zmieniono (pliki, tabele, endpointy)

TESTS
- run CI: ID/link, wynik `PHP + API + UI`
- które jobs padły i co poprawiono; czego nie uruchomiono i dlaczego

COMMIT
- branch, commit, PR (numer/link)

DELIVERY
- run `Deploy AlphaVault`: ID/wynik
- health, `/docs/`, `.app` 403, `sync.status`/`stale`
- Cron: `sync.lastSuccessAt`

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
- Testy: [docs/TESTOWANIE.md](docs/TESTOWANIE.md)
- Funkcje: [docs/FUNKCJE.md](docs/FUNKCJE.md)
