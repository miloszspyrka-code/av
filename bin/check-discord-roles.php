#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Cykliczna weryfikacja roli Alpha dla aktywnych sesji.
 * Uruchamiane z LH Cron razem z sync-discord-events.sh co 5 minut.
 */

$root = dirname(__DIR__);

try {
    [$config, $db, $auth, $discord] = require $root . '/app/bootstrap.php';
    if (!$config->roleRecheckConfigured) {
        fwrite(STDOUT, "AlphaVault role recheck: disabled (brak DISCORD_BOT_TOKEN / GUILD_ID / REQUIRED_ROLE_ID)\n");
        exit(0);
    }
    $guard = new \AlphaVault\RoleGuard($config, $db, $discord);
    $result = $guard->reapDue();
    if (!empty($result['skipped'])) {
        fwrite(STDOUT, 'AlphaVault role recheck: skipped (' . (string) ($result['reason'] ?? 'unknown') . ")\n");
        exit(0);
    }
    fwrite(STDOUT, sprintf(
        "AlphaVault role recheck: %s checked=%d revoked=%d errors=%d\n",
        (string) ($result['status'] ?? 'unknown'),
        (int) ($result['checked'] ?? 0),
        (int) ($result['revoked'] ?? 0),
        (int) ($result['errors'] ?? 0)
    ));
    exit(0);
} catch (\Throwable $err) {
    fwrite(STDERR, 'AlphaVault role recheck: error ' . $err->getMessage() . "\n");
    exit(1);
}
