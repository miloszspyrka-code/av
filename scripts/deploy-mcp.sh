#!/usr/bin/env bash
# Deploy AlphaVault MCP (PHP 8.3) to LH.pl.
#
# Required:
#   LH_SSH_HOST, LH_SSH_USER, LH_SSH_PRIVATE_KEY_PATH, LH_SSH_KNOWN_HOSTS_PATH,
#   LH_MCP_DEPLOY_PATH (e.g. ~/public_html/mcp.alphavault.pl),
#   LH_MCP_APP_PATH    (e.g. ~/public_html/mcp.alphavault.pl/.app),
#   MCP_APP_URL        (https://mcp.alphavault.pl),
#   SESSION_SECRET, DB_*, DISCORD_CLIENT_ID, DISCORD_CLIENT_SECRET,
#   DISCORD_GUILD_ID, DISCORD_REQUIRED_ROLE_ID.
#
# Optional:
#   LH_SSH_PORT (40022), DRY_RUN (false), MCP_WRITES_ENABLED (false),
#   DISCORD_BOT_TOKEN (not required by MCP), ADMIN_DISCORD_IDS.

set -euo pipefail

log() { printf '[deploy-mcp] %s\n' "$*"; }
fail() { printf '::error::%s\n' "$*" >&2; exit 1; }

require_var() {
  local name="$1"
  if [ -z "${!name:-}" ]; then fail "Missing required environment variable: ${name}"; fi
}

for name in LH_SSH_HOST LH_SSH_USER LH_SSH_PRIVATE_KEY_PATH LH_SSH_KNOWN_HOSTS_PATH LH_MCP_DEPLOY_PATH LH_MCP_APP_PATH MCP_APP_URL; do
  require_var "$name"
done

LH_SSH_PORT="${LH_SSH_PORT:-40022}"
DRY_RUN="${DRY_RUN:-false}"
MCP_WRITES_ENABLED="${MCP_WRITES_ENABLED:-false}"
APP_ENV="${APP_ENV:-production}"
ALLOW_DEMO_LOGIN="${ALLOW_DEMO_LOGIN:-false}"
MAX_STATE_BYTES="${MAX_STATE_BYTES:-12582912}"

[ "$MCP_APP_URL" = "https://mcp.alphavault.pl" ] || fail "MCP_APP_URL must be exactly https://mcp.alphavault.pl."
[ "$DRY_RUN" = "true" ] || [ "$MCP_WRITES_ENABLED" = "false" ] || fail "MCP_WRITES_ENABLED must stay false for phase 1 deployment."

if [ ! -f vendor/autoload.php ]; then
  command -v composer >/dev/null 2>&1 || fail "Brak vendor/ i Composer. Uruchom composer install przed deployem."
  composer install --no-dev --no-interaction --prefer-dist
fi

[ -f "$LH_SSH_PRIVATE_KEY_PATH" ] || fail "SSH private key not found: $LH_SSH_PRIVATE_KEY_PATH"
[ -f "$LH_SSH_KNOWN_HOSTS_PATH" ] || fail "known_hosts not found: $LH_SSH_KNOWN_HOSTS_PATH"
chmod 600 "$LH_SSH_PRIVATE_KEY_PATH" "$LH_SSH_KNOWN_HOSTS_PATH"

SSH_OPTS=(
  -p "$LH_SSH_PORT"
  -i "$LH_SSH_PRIVATE_KEY_PATH"
  -o IdentitiesOnly=yes
  -o BatchMode=yes
  -o StrictHostKeyChecking=yes
  -o UserKnownHostsFile="$LH_SSH_KNOWN_HOSTS_PATH"
  -o ConnectTimeout=20
)
SSH_TARGET="$LH_SSH_USER@$LH_SSH_HOST"
RSYNC_SSH="ssh -p $LH_SSH_PORT -i $LH_SSH_PRIVATE_KEY_PATH -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=$LH_SSH_KNOWN_HOSTS_PATH"

ssh "${SSH_OPTS[@]}" "$SSH_TARGET" true || fail "SSH connection failed."
REMOTE_HOME="$(ssh "${SSH_OPTS[@]}" "$SSH_TARGET" 'printf %s "$HOME"')"
[ -n "$REMOTE_HOME" ] || fail "Could not read remote HOME."

resolve_remote_path() {
  local value="$1"
  case "$value" in
    "~/"*) printf '%s/%s' "$REMOTE_HOME" "${value#"~/"}" ;;
    /*) printf '%s' "$value" ;;
    *) fail "Path must be absolute or start with ~/: $value" ;;
  esac
}

DEPLOY_PATH="$(resolve_remote_path "$LH_MCP_DEPLOY_PATH")"
APP_PATH="$(resolve_remote_path "$LH_MCP_APP_PATH")"
while [ "${DEPLOY_PATH%/}" != "$DEPLOY_PATH" ]; do DEPLOY_PATH="${DEPLOY_PATH%/}"; done
while [ "${APP_PATH%/}" != "$APP_PATH" ]; do APP_PATH="${APP_PATH%/}"; done
case "$DEPLOY_PATH" in */mcp.alphavault.pl) ;; *) fail "LH_MCP_DEPLOY_PATH must end with /mcp.alphavault.pl." ;; esac
case "$APP_PATH/" in "$DEPLOY_PATH/.app/") ;; *) fail "LH_MCP_APP_PATH must be exactly <deploy>/.app." ;; esac

BACKUP_DIR="$REMOTE_HOME/alphavault-backups"
TS="$(date -u +%Y%m%dT%H%M%SZ)"

if [ "$DRY_RUN" != "true" ]; then
  log "Creating backups..."
  ssh "${SSH_OPTS[@]}" "$SSH_TARGET" "bash -s" <<REMOTE
set -euo pipefail
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
[ -d "$DEPLOY_PATH" ] && tar -czf "$BACKUP_DIR/$TS-mcp-public.tar.gz" -C "$(dirname "$DEPLOY_PATH")" "$(basename "$DEPLOY_PATH")" || true
[ -d "$APP_PATH" ] && tar -czf "$BACKUP_DIR/$TS-mcp-app.tar.gz" -C "$(dirname "$APP_PATH")" "$(basename "$APP_PATH")" || true
chmod 600 "$BACKUP_DIR/$TS"-mcp-*.tar.gz 2>/dev/null || true
ls -1t "$BACKUP_DIR"/*-mcp-public.tar.gz 2>/dev/null | tail -n +6 | xargs -r rm -f || true
ls -1t "$BACKUP_DIR"/*-mcp-app.tar.gz 2>/dev/null | tail -n +6 | xargs -r rm -f || true
REMOTE
fi

log "Preparing remote directories..."
ssh "${SSH_OPTS[@]}" "$SSH_TARGET" "mkdir -p '$DEPLOY_PATH' '$APP_PATH/app' '$APP_PATH/bin' '$APP_PATH/config' '$APP_PATH/db' '$APP_PATH/logs' '$APP_PATH/vendor'; chmod 755 '$DEPLOY_PATH' '$APP_PATH'; chmod 700 '$APP_PATH/logs'"
printf 'Require all denied\n' | ssh "${SSH_OPTS[@]}" "$SSH_TARGET" "umask 022; cat > '$APP_PATH/.htaccess'; chmod 644 '$APP_PATH/.htaccess'"

RSYNC_FLAGS=(-rltz --delete --human-readable)
if [ "$DRY_RUN" = "true" ]; then RSYNC_FLAGS+=(--dry-run --itemize-changes); fi

log "Syncing public front controller..."
rsync "${RSYNC_FLAGS[@]}" --exclude='.app' --exclude='app-path.php' -e "$RSYNC_SSH" mcp/public/ "$SSH_TARGET:$DEPLOY_PATH/"
log "Syncing PHP application..."
rsync "${RSYNC_FLAGS[@]}" --exclude=config.json -e "$RSYNC_SSH" app/ "$SSH_TARGET:$APP_PATH/app/"
log "Syncing schema..."
rsync "${RSYNC_FLAGS[@]}" -e "$RSYNC_SSH" db/ "$SSH_TARGET:$APP_PATH/db/"
log "Syncing bin..."
rsync "${RSYNC_FLAGS[@]}" --exclude=config.json -e "$RSYNC_SSH" bin/ "$SSH_TARGET:$APP_PATH/bin/"
log "Syncing config..."
rsync "${RSYNC_FLAGS[@]}" --exclude=discord_event_rules.json -e "$RSYNC_SSH" config/ "$SSH_TARGET:$APP_PATH/config/"
log "Syncing Composer vendor..."
rsync "${RSYNC_FLAGS[@]}" -e "$RSYNC_SSH" vendor/ "$SSH_TARGET:$APP_PATH/vendor/"

if [ "$DRY_RUN" = "true" ]; then
  log "Dry run finished."
  exit 0
fi

log "Writing app path..."
printf "<?php\nreturn '%s';\n" "$APP_PATH/app" | ssh "${SSH_OPTS[@]}" "$SSH_TARGET" "umask 022; cat > '$DEPLOY_PATH/app-path.php'; chmod 644 '$DEPLOY_PATH/app-path.php'"

log "Writing MCP config..."
APP_URL="$MCP_APP_URL" \
SESSION_COOKIE="mcp_session" \
MCP_ENABLED="true" \
MCP_WRITES_ENABLED="$MCP_WRITES_ENABLED" \
MCP_ISSUER="$MCP_APP_URL" \
MCP_RESOURCE="$MCP_APP_URL/mcp" \
MCP_PRIVATE_KEY_PATH="$APP_PATH/keys/oauth-private.pem" \
MCP_PUBLIC_KEY_PATH="$APP_PATH/keys/oauth-public.pem" \
MCP_ENCRYPTION_KEY_PATH="$APP_PATH/keys/oauth-encryption.key" \
MCP_ALLOWED_REDIRECT_HOSTS="${MCP_ALLOWED_REDIRECT_HOSTS:-chatgpt.com,chat.openai.com,platform.openai.com,claude.ai,grok.com,x.ai}" \
MCP_LOG_FILE="$APP_PATH/logs/mcp.log" \
APP_ENV="$APP_ENV" \
ALLOW_DEMO_LOGIN="$ALLOW_DEMO_LOGIN" \
MAX_STATE_BYTES="$MAX_STATE_BYTES" \
DISCORD_REDIRECT_URI="$MCP_APP_URL/oauth/discord/callback" \
node scripts/render-mcp-config.mjs | ssh "${SSH_OPTS[@]}" "$SSH_TARGET" "umask 077; cat > '$APP_PATH/config.json'; chmod 600 '$APP_PATH/config.json'"

log "Applying schema..."
ssh "${SSH_OPTS[@]}" "$SSH_TARGET" "php '$APP_PATH/app/db/migrate.php'"
log "Ensuring OAuth keys..."
ssh "${SSH_OPTS[@]}" "$SSH_TARGET" "php '$APP_PATH/bin/mcp-init.php'"
log "Running maintenance..."
ssh "${SSH_OPTS[@]}" "$SSH_TARGET" "php '$APP_PATH/bin/mcp-maintenance.php'"

health_check() {
  local body
  body="$(curl -fsS --max-time 25 "$MCP_APP_URL/health" 2>/dev/null || true)"
  printf '%s' "$body" | grep -q '"ok":true' || fail "MCP health check failed: $body"
  printf '%s' "$body" | grep -q '"enabled":true' || fail "MCP is not enabled: $body"
  printf '%s' "$body" | grep -q '"writesEnabled":false' || fail "MCP writes must be disabled in phase 1: $body"
  local prm
  prm="$(curl -fsS --max-time 25 "$MCP_APP_URL/.well-known/oauth-protected-resource" 2>/dev/null || true)"
  printf '%s' "$prm" | grep -q '"resource":"https://mcp.alphavault.pl/mcp"' || fail "Protected Resource Metadata invalid: $prm"
  local status
  status="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 25 -X POST "$MCP_APP_URL/mcp" -H 'Content-Type: application/json' -d '{}' 2>/dev/null || true)"
  [ "$status" = "401" ] || fail "POST /mcp without token returned $status, expected 401."
  log "Health check passed."
}

health_check
log "MCP deployment finished."
