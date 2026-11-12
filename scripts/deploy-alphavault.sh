#!/usr/bin/env bash
# Production deployment for AlphaVault (PHP 8.3) on LH.pl shared hosting.
#
# Required environment:
#   LH_SSH_HOST, LH_SSH_USER, LH_SSH_PRIVATE_KEY_PATH, LH_SSH_KNOWN_HOSTS_PATH,
#   LH_DEPLOY_PATH, LH_APP_PATH, APP_URL
#
# Optional:
#   LH_SSH_PORT (default 40022), DRY_RUN (default false), ROLLBACK (default false),
#   SESSION_SECRET, DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD,
#   DISCORD_CLIENT_ID, DISCORD_CLIENT_SECRET, DISCORD_REDIRECT_URI,
#   DISCORD_GUILD_ID, DISCORD_REQUIRED_ROLE_ID, DISCORD_DEFAULT_PLAN,
#   DISCORD_TRIAL_DAYS, ALLOW_DEMO_LOGIN, MAX_STATE_BYTES
#
# The script never prints secret values. Config is written over SSH stdin to
# <LH_APP_PATH>/config.json with mode 600, outside the public document root.

set -euo pipefail

log() { printf '[deploy-alphavault] %s\n' "$*"; }
fail() { printf '::error::%s\n' "$*" >&2; exit 1; }

require_var() {
  local name="$1"
  if [ -z "${!name:-}" ]; then
    fail "Missing required environment variable: ${name}"
  fi
}

for name in LH_SSH_HOST LH_SSH_USER LH_SSH_PRIVATE_KEY_PATH LH_SSH_KNOWN_HOSTS_PATH LH_DEPLOY_PATH LH_APP_PATH APP_URL; do
  require_var "$name"
done

LH_SSH_PORT="${LH_SSH_PORT:-40022}"
DRY_RUN="${DRY_RUN:-false}"
ROLLBACK="${ROLLBACK:-false}"
APP_ENV="${APP_ENV:-production}"
ALLOW_DEMO_LOGIN="${ALLOW_DEMO_LOGIN:-false}"
MAX_STATE_BYTES="${MAX_STATE_BYTES:-12582912}"
DISCORD_DEFAULT_PLAN="${DISCORD_DEFAULT_PLAN:-full}"
DISCORD_TRIAL_DAYS="${DISCORD_TRIAL_DAYS:-0}"

case "$LH_SSH_PORT" in
  ''|*[!0-9]*) fail "LH_SSH_PORT must be numeric." ;;
esac
case "$APP_URL" in
  https://*) ;;
  *) fail "APP_URL must use https:// in production." ;;
esac
if [ "$APP_URL" != "https://alphavault.pl" ]; then
  fail "APP_URL must be exactly https://alphavault.pl for this deployment."
fi

if [ "$DRY_RUN" != "true" ]; then
  if [ "${DISCORD_REDIRECT_URI:-}" != "https://alphavault.pl/auth/discord/callback" ]; then
    fail "DISCORD_REDIRECT_URI must be https://alphavault.pl/auth/discord/callback."
  fi
  for name in SESSION_SECRET DB_HOST DB_PORT DB_NAME DB_USER DB_PASSWORD DISCORD_CLIENT_ID DISCORD_CLIENT_SECRET DISCORD_GUILD_ID DISCORD_REQUIRED_ROLE_ID; do
    require_var "$name"
  done
fi

[ -f "$LH_SSH_PRIVATE_KEY_PATH" ] || fail "SSH private key not found: $LH_SSH_PRIVATE_KEY_PATH"
[ -f "$LH_SSH_KNOWN_HOSTS_PATH" ] || fail "known_hosts not found: $LH_SSH_KNOWN_HOSTS_PATH"
chmod 600 "$LH_SSH_PRIVATE_KEY_PATH" "$LH_SSH_KNOWN_HOSTS_PATH"

if ! ssh-keygen -F "$LH_SSH_HOST" -f "$LH_SSH_KNOWN_HOSTS_PATH" >/dev/null 2>&1 \
   && ! ssh-keygen -F "[$LH_SSH_HOST]:$LH_SSH_PORT" -f "$LH_SSH_KNOWN_HOSTS_PATH" >/dev/null 2>&1; then
  fail "Host key for $LH_SSH_HOST:$LH_SSH_PORT is missing from LH_SSH_KNOWN_HOSTS."
fi

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
RSYNC_SSH="ssh -p $LH_SSH_PORT -i $LH_SSH_PRIVATE_KEY_PATH -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=$LH_SSH_KNOWN_HOSTS_PATH -o ConnectTimeout=20"

log "Testing SSH connectivity..."
ssh "${SSH_OPTS[@]}" "$SSH_TARGET" true || fail "SSH connection failed. Check host, port, user, key and known_hosts."

REMOTE_HOME="$(ssh "${SSH_OPTS[@]}" "$SSH_TARGET" 'printf %s "$HOME"')"
[ -n "$REMOTE_HOME" ] || fail "Could not read remote HOME."

resolve_remote_path() {
  local value="$1"
  # shellcheck disable=SC2088
  local home_prefix='~/'
  case "$value" in
    "$home_prefix"*) printf '%s/%s' "$REMOTE_HOME" "${value#"$home_prefix"}" ;;
    /*) printf '%s' "$value" ;;
    *) fail "Path must be absolute or start with ~/: $value" ;;
  esac
}

DEPLOY_PATH="$(resolve_remote_path "$LH_DEPLOY_PATH")"
APP_PATH="$(resolve_remote_path "$LH_APP_PATH")"

for p in "$DEPLOY_PATH" "$APP_PATH"; do
  case "$p" in
    *..*) fail "Path must not contain '..': $p" ;;
    /) fail "Path must not be /." ;;
  esac
  if ! printf '%s' "$p" | grep -Eq '^/[A-Za-z0-9._/-]+$'; then
    fail "Path contains unsupported characters: $p"
  fi
done
while [ "$DEPLOY_PATH" != "/" ] && [ "${DEPLOY_PATH%/}" != "$DEPLOY_PATH" ]; do DEPLOY_PATH="${DEPLOY_PATH%/}"; done
while [ "$APP_PATH" != "/" ] && [ "${APP_PATH%/}" != "$APP_PATH" ]; do APP_PATH="${APP_PATH%/}"; done

case "$DEPLOY_PATH" in
  */public_html) fail "LH_DEPLOY_PATH must point to the domain directory, not public_html itself." ;;
  */alphavault.pl) ;;
  *) fail "LH_DEPLOY_PATH must end with /alphavault.pl for this deployment." ;;
esac
case "$APP_PATH/" in
  "$DEPLOY_PATH/"*) fail "LH_APP_PATH must be outside the public document root." ;;
esac
case "$APP_PATH" in
  */public_html/*) fail "LH_APP_PATH must not be inside public_html." ;;
esac

BACKUP_DIR="$REMOTE_HOME/alphavault-backups"
log "Public docroot: $DEPLOY_PATH"
log "Application dir: $APP_PATH"

health_check() {
  local body
  body="$(curl -fsS --max-time 25 "$APP_URL/api/health" 2>/dev/null || true)"
  if [ -z "$body" ]; then
    fail "Health check failed: no response from $APP_URL/api/health"
  fi
  if ! printf '%s' "$body" | jq -e '.ok == true and .db.ok == true and .discordConfigured == true and .roleCheckConfigured == true and .demoAllowed == false' >/dev/null 2>&1; then
    printf '%s\n' "$body"
    fail "Health check failed: API/DB/Discord configuration is not production-ready."
  fi
  local page
  page="$(curl -fsS --max-time 25 "$APP_URL/" 2>/dev/null || true)"
  case "$page" in
    *"Alpha Vault"*) ;;
    *) fail "Health check failed: $APP_URL/ does not serve the Alpha Vault UI." ;;
  esac
  local js_status
  js_status="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 25 "$APP_URL/js/state.js" 2>/dev/null || true)"
  [ "$js_status" = "200" ] || fail "Health check failed: /js/state.js returned $js_status."
  log "Health check passed."
}

if [ "$ROLLBACK" = "true" ]; then
  log "Rollback requested."
  LATEST_PUBLIC="$(ssh "${SSH_OPTS[@]}" "$SSH_TARGET" "ls -1t '$BACKUP_DIR'/*-public.tar.gz 2>/dev/null | head -1" || true)"
  LATEST_APP="$(ssh "${SSH_OPTS[@]}" "$SSH_TARGET" "ls -1t '$BACKUP_DIR'/*-app.tar.gz 2>/dev/null | head -1" || true)"
  [ -n "$LATEST_PUBLIC" ] || fail "No public backup found in $BACKUP_DIR."
  log "Restoring public backup: $LATEST_PUBLIC"
  ssh "${SSH_OPTS[@]}" "$SSH_TARGET" "tar -xzf '$LATEST_PUBLIC' -C '$(dirname "$DEPLOY_PATH")'"
  if [ -n "$LATEST_APP" ]; then
    log "Restoring app backup: $LATEST_APP"
    ssh "${SSH_OPTS[@]}" "$SSH_TARGET" "tar -xzf '$LATEST_APP' -C '$(dirname "$APP_PATH")'"
  fi
  health_check
  log "Rollback finished."
  exit 0
fi

RSYNC_FLAGS=(-rltz --delete --human-readable)
if [ "$DRY_RUN" = "true" ]; then
  RSYNC_FLAGS+=(--dry-run --itemize-changes)
  log "DRY RUN: no files will be written and no migration will run."
fi

if [ "$DRY_RUN" != "true" ]; then
  TS="$(date -u +%Y%m%dT%H%M%SZ)"
  log "Creating backups..."
  # shellcheck disable=SC2087
  ssh "${SSH_OPTS[@]}" "$SSH_TARGET" "bash -s" <<REMOTE
set -euo pipefail
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
if [ -d "$DEPLOY_PATH" ]; then
  tar -czf "$BACKUP_DIR/$TS-public.tar.gz" -C "$(dirname "$DEPLOY_PATH")" "$(basename "$DEPLOY_PATH")"
  chmod 600 "$BACKUP_DIR/$TS-public.tar.gz"
fi
if [ -d "$APP_PATH" ]; then
  tar -czf "$BACKUP_DIR/$TS-app.tar.gz" -C "$(dirname "$APP_PATH")" "$(basename "$APP_PATH")"
  chmod 600 "$BACKUP_DIR/$TS-app.tar.gz"
fi
ls -1t "$BACKUP_DIR"/*-public.tar.gz 2>/dev/null | tail -n +6 | xargs -r rm -f || true
ls -1t "$BACKUP_DIR"/*-app.tar.gz 2>/dev/null | tail -n +6 | xargs -r rm -f || true
REMOTE
fi

log "Preparing remote directories..."
ssh "${SSH_OPTS[@]}" "$SSH_TARGET" "mkdir -p '$DEPLOY_PATH' '$APP_PATH/app' '$APP_PATH/db'; chmod 755 '$DEPLOY_PATH' '$APP_PATH'"

log "Syncing public frontend..."
rsync "${RSYNC_FLAGS[@]}" \
  --exclude=.well-known \
  --exclude=uploads \
  --exclude=tmp \
  --exclude=app-path.php \
  -e "$RSYNC_SSH" public/ "$SSH_TARGET:$DEPLOY_PATH/"

log "Syncing PHP application..."
rsync "${RSYNC_FLAGS[@]}" \
  --exclude=config.json \
  -e "$RSYNC_SSH" app/ "$SSH_TARGET:$APP_PATH/app/"

log "Syncing schema and demo data..."
rsync "${RSYNC_FLAGS[@]}" \
  -e "$RSYNC_SSH" db/ "$SSH_TARGET:$APP_PATH/db/"

if [ "$DRY_RUN" = "true" ]; then
  log "Dry run finished. No server files were changed."
  exit 0
fi

log "Writing app path..."
printf "<?php\nreturn '%s';\n" "$APP_PATH/app" | ssh "${SSH_OPTS[@]}" "$SSH_TARGET" "umask 022; cat > '$DEPLOY_PATH/app-path.php'; chmod 644 '$DEPLOY_PATH/app-path.php'"

log "Writing production config.json (outside public docroot)..."
node scripts/render-config.mjs | ssh "${SSH_OPTS[@]}" "$SSH_TARGET" "umask 077; cat > '$APP_PATH/config.json'; chmod 600 '$APP_PATH/config.json'"

log "Applying database schema..."
ssh "${SSH_OPTS[@]}" "$SSH_TARGET" "php '$APP_PATH/app/db/migrate.php'"

health_check
log "Deployment finished successfully."
