#!/bin/bash
APP_DIR=/home/platne/serwer445119/public_html/alphavault.pl/.app
/usr/bin/php "$APP_DIR/bin/sync-discord-events.php"
sync_status=$?
/usr/bin/php "$APP_DIR/bin/check-discord-roles.php"
role_status=$?
if [ "$sync_status" -ne 0 ]; then
  exit "$sync_status"
fi
exit "$role_status"
