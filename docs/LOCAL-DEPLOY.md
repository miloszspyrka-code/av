# Lokalny test i deploy AlphaVault (WSL2, bez GitHub Actions)

Skrypt `scripts/local-deploy.sh` robi test logowania MySQL na LH i dopiero po sukcesie może uruchomić istniejący `scripts/deploy-alphavault.sh`. Nie używa GitHub Actions ani minut Actions.

## Wymagania

- WSL2 Ubuntu (na tym komputerze jest zainstalowany),
- `ssh`, `rsync`, `node`, `jq`, `curl` w WSL — obecne,
- klucz deploy i `known_hosts` w `~/.ssh`,
- plik konfiguracji `~/.config/alphavault/local.env` z prawami `600`.

## 1. Klucz SSH i known_hosts

W WSL:

```bash
mkdir -p ~/.ssh ~/.config/alphavault
cp /mnt/c/Users/milos/AppData/Local/Temp/opencode/alphavault-deploy/lh_deploy_key ~/.ssh/alphavault_lh_deploy_key
cp /mnt/c/Users/milos/AppData/Local/Temp/opencode/alphavault-known-hosts/known_hosts.txt ~/.ssh/alphavault_lh_known_hosts
chmod 600 ~/.ssh/alphavault_lh_deploy_key ~/.ssh/alphavault_lh_known_hosts
```

Klucz jest już dodany do `authorized_keys` na LH. Nie kopiuj go do repo.

## 2. Konfiguracja lokalna

```bash
cp scripts/local.env.example ~/.config/alphavault/local.env
chmod 600 ~/.config/alphavault/local.env
nano ~/.config/alphavault/local.env
```

Uzupełnij:

- `DB_PASSWORD` — dokładnie hasło użytkownika MySQL z panelu LH,
- `SESSION_SECRET` — `openssl rand -hex 32`,
- `DISCORD_CLIENT_ID`, `DISCORD_CLIENT_SECRET`, `DISCORD_GUILD_ID`, `DISCORD_REQUIRED_ROLE_ID`, `DISCORD_BOT_TOKEN` — wartości produkcyjnej aplikacji Discord.

Nie wklejaj tych wartości do czatu, issue ani repo. Plik `local.env` jest poza repo i ma prawa `600`.

## 3. Użycie

Z katalogu repo:

```bash
./scripts/local-deploy.sh --test-db
./scripts/local-deploy.sh --dry-run
./scripts/local-deploy.sh --deploy
./scripts/local-deploy.sh --rollback
```

- `--test-db` — tylko SSH i logowanie MySQL. Nic nie jest wdrażane.
- `--dry-run` — test DB + `rsync --dry-run`; bez zapisu plików, bez migracji, bez configu.
- `--deploy` — pełny deploy: backup, rsync, `config.json`, migracja, health check.
- `--rollback` — przywrócenie ostatniego backupu.

Test DB zawsze wykonuje się przed deployem. Jeśli logowanie MySQL nie przejdzie, skrypt kończy się błędem i nie uruchamia `deploy-alphavault.sh`.

## 4. Co sprawdza test DB

- SSH na `LH_SSH_HOST:LH_SSH_PORT` kluczem `LH_SSH_PRIVATE_KEY_PATH`,
- `mysql` na LH z `DB_USER`, `DB_PASSWORD`, `DB_HOST`, `DB_PORT`, `DB_NAME`,
- `CURRENT_USER()` i `DATABASE()`.

Hasło jest przekazywane do LH przez stdin SSH, nie przez argumenty procesu i nie jest wypisywane.

## 5. Bezpieczeństwo

- `local.env` poza repo, prawa `600`,
- klucz deploy poza repo, prawa `600`,
- brak `set -x`, brak echo sekretów,
- deploy nie rusza WordPressów ani innych domen,
- `--deploy` wykonuje backup przed zapisem.

## 6. GitHub Actions

Ten skrypt jest niezależny od GitHub Actions. Blokada billingowa Actions nie wpływa na `--test-db` ani `--deploy`. Główne CI/deploy w repo nadal mogą używać `ubuntu-latest` po naprawie billing.
