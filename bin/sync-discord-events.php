#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);

try {
    [$config, $db] = require $root . '/app/bootstrap.php';
    require_once $root . '/app/DiscordApiException.php';
    require_once $root . '/app/DiscordEvents.php';

    $sync = new \AlphaVault\DiscordEvents($config, $db);
    $result = $sync->sync();

    if (!empty($result['skipped'])) {
        fwrite(STDOUT, "AlphaVault Discord sync: skipped (lock held)\n");
        exit(0);
    }

    fwrite(STDOUT, sprintf(
        "AlphaVault Discord sync: success events=%d updated=%d duration=%dms\n",
        (int) ($result['events_count'] ?? 0),
        (int) ($result['updated_count'] ?? 0),
        (int) ($result['duration_ms'] ?? 0)
    ));
    exit(0);
} catch (\Throwable $err) {
    fwrite(STDERR, 'AlphaVault Discord sync: error ' . $err->getMessage() . "\n");
    exit(1);
}
