#!/usr/bin/env bash
# Local AlphaVault DB test / deployment without GitHub Actions.
#
# Usage:
#   scripts/local-deploy.sh --test-db
#   scripts/local-deploy.sh --dry-run
#   scripts/local-deploy.sh --deploy
#   scripts/local-deploy.sh --rollback
#
# Configuration:
#   $HOME/.config/alphavault/local.env (chmod 600)
#   override with ALPHAVAULT_LOCAL_ENV=/path/to/local.env
#
# The DB login test always runs before any deploy and blocks it on failure.
# The password is passed to the LH host over SSH stdin, never in argv/logs.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${ALPHAVAULT_LOCAL_ENV:-$HOME/.config/alphavault/local.env}"
MODE="${1:---test-db}"

log() { printf '[local-deploy] %s\n' "$*"; }
fail() { printf '::error::%s\n' "$*" >&2; exit 1; }

usage() {
  cat <<'EOF'
Usage: scripts/local-deploy.sh [--test-db|--dry-run|--deploy|--rollback]

  --test-db   tylko test logowania MySQL na LH (domyślne)
  --dry-run   test DB + rsync --dry-run, bez zapisu i bez migracji
  --deploy    test DB + pełny deploy (zapis, config, migracja, health check)
  --rollback  przywrócenie ostatniego backupu

Env file: $HOME/.config/alphavault/local.env (override ALPHAVAULT_LOCAL_ENV).
EOF
}

case "$MODE" in
  --test-db|--dry-run|--deploy|--rollback) ;;
  -h|--help) usage; exit 0 ;;
  *) usage; exit 2 ;;
esac

[ -f "$ENV_FILE" ] || fail "Brak pliku konfiguracji: $ENV_FILE"
# shellcheck disable=SC1090
set -a
. "$ENV_FILE"
set +a

expand_path() {
  case "$1" in
    "~/"*) printf '%s/%s' "$HOME" "${1:2}" ;;
    *) printf '%s' "$1" ;;
  esac
}

for name in LH_SSH_HOST LH_SSH_USER LH_SSH_PRIVATE_KEY_PATH LH_SSH_KNOWN_HOSTS_PATH LH_DEPLOY_PATH LH_APP_PATH APP_URL DB_HOST DB_PORT DB_NAME DB_USER DB_PASSWORD; do
  if [ -z "${!name:-}" ]; then
    fail "Brak zmiennej w $ENV_FILE: $name"
  fi
done

LH_SSH_PORT="${LH_SSH_PORT:-40022}"
LH_SSH_PRIVATE_KEY_PATH="$(expand_path "$LH_SSH_PRIVATE_KEY_PATH")"
LH_SSH_KNOWN_HOSTS_PATH="$(expand_path "$LH_SSH_KNOWN_HOSTS_PATH")"

[ -f "$LH_SSH_PRIVATE_KEY_PATH" ] || fail "Brak klucza SSH: $LH_SSH_PRIVATE_KEY_PATH"
[ -f "$LH_SSH_KNOWN_HOSTS_PATH" ] || fail "Brak known_hosts: $LH_SSH_KNOWN_HOSTS_PATH"
chmod 600 "$LH_SSH_PRIVATE_KEY_PATH" "$LH_SSH_KNOWN_HOSTS_PATH"

SSH_OPTS=(
  -p "$LH_SSH_PORT"
  -i "$LH_SSH_PRIVATE_KEY_PATH"
  -o IdentitiesOnly=yes
  -o BatchMode=yes
  -o StrictHostKeyChecking=yes
  -o UserKnownHostsFile="$LH_SSH_KNOWN_HOSTS_PATH"
  -o ConnectTimeout=20
  -o ServerAliveInterval=15
  -o ServerAliveCountMax=3
)
SSH_TARGET="$LH_SSH_USER@$LH_SSH_HOST"

log "Test SSH: $SSH_TARGET:$LH_SSH_PORT"
ssh "${SSH_OPTS[@]}" "$SSH_TARGET" true || fail "SSH nie działa. Sprawdź host/port/user/klucz/known_hosts."

log "Test MySQL na LH: user=$DB_USER db=$DB_NAME host=$DB_HOST port=$DB_PORT (hasło z pliku, nie jest wypisywane)"
set +e
{
  printf 'DB_HOST=%q\n' "$DB_HOST"
  printf 'DB_PORT=%q\n' "$DB_PORT"
  printf 'DB_NAME=%q\n' "$DB_NAME"
  printf 'DB_USER=%q\n' "$DB_USER"
  printf 'DB_PASSWORD=%q\n' "$DB_PASSWORD"
  cat <<'REMOTE'
set -u
out=$(MYSQL_PWD="$DB_PASSWORD" mysql --protocol=TCP --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" --connect-timeout=10 -N -e "SELECT CONCAT('OK user=',CURRENT_USER(),' db=',IFNULL(DATABASE(),'NULL'))" "$DB_NAME" 2>&1); rc=$?
printf '%s\n' "$out"
if [ "$rc" -ne 0 ]; then
  echo "DB_LOGIN=FAIL"
  exit 1
fi
echo "DB_LOGIN=OK"
REMOTE
} | ssh "${SSH_OPTS[@]}" "$SSH_TARGET" 'bash -s'
DB_RC=$?
set -e
if [ "$DB_RC" -ne 0 ]; then
  fail "Logowanie MySQL nie powiodło się. Deploy zablokowany."
fi
log "DB_LOGIN=OK"

if [ "$MODE" = "--test-db" ]; then
  log "Test zakończony. Nic nie zostało wdrożone."
  exit 0
fi

export DRY_RUN=false
export ROLLBACK=false
case "$MODE" in
  --dry-run) export DRY_RUN=true ;;
  --deploy) ;;
  --rollback) export ROLLBACK=true ;;
esac

log "Przekazuję do scripts/deploy-alphavault.sh (DRY_RUN=$DRY_RUN ROLLBACK=$ROLLBACK)"
exec "$ROOT_DIR/scripts/deploy-alphavault.sh"
