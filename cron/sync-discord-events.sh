#!/bin/bash
# Jedyny wpis LH Cron (*/5). Kolejne cykliczne zadania dopinaj tutaj z wlasnym throttle.
APP_DIR=/home/platne/serwer445119/public_html/alphavault.pl/.app
RUNTIME_DIR="$APP_DIR/.runtime"

mkdir -p "$RUNTIME_DIR"

# Uruchamia polecenie, jesli od ostatniego sukcesu minelo co najmniej N minut.
# Znacznik czasu jest zapisywany tylko po sukcesie, wiec blad jest ponawiany przy kolejnym cyklu.
run_if_due() {
  due_name="$1"
  due_minutes="$2"
  shift 2

  due_stamp="$RUNTIME_DIR/$due_name.stamp"
  due_now=$(date +%s)
  due_last=0
  if [ -f "$due_stamp" ]; then
    due_last=$(cat "$due_stamp" 2>/dev/null || echo 0)
    case "$due_last" in
      ''|*[!0-9]*) due_last=0 ;;
    esac
  fi

  if [ $((due_now - due_last)) -lt $((due_minutes * 60)) ]; then
    return 0
  fi

  "$@"
  due_status=$?
  if [ "$due_status" -eq 0 ]; then
    echo "$due_now" > "$due_stamp"
  fi
  return "$due_status"
}

/usr/bin/php "$APP_DIR/bin/sync-discord-events.php"
sync_status=$?
/usr/bin/php "$APP_DIR/bin/check-discord-roles.php"
role_status=$?

macro_status=0
run_if_due macro 15 /usr/bin/php "$APP_DIR/bin/sync-macro-events.php" || macro_status=$?
wearables_status=0
run_if_due wearables 30 /usr/bin/php "$APP_DIR/bin/sync-wearables.php" || wearables_status=$?
demo_status=0
run_if_due demo 60 /usr/bin/php "$APP_DIR/bin/sync-demo-state.php" || demo_status=$?

if [ "$sync_status" -ne 0 ]; then
  exit "$sync_status"
fi
if [ "$role_status" -ne 0 ]; then
  exit "$role_status"
fi
if [ "$macro_status" -ne 0 ]; then
  exit "$macro_status"
fi
if [ "$wearables_status" -ne 0 ]; then
  exit "$wearables_status"
fi
exit "$demo_status"
