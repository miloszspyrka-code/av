#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);

try {
    [$config, $db] = require $root . '/app/bootstrap.php';
    require_once $root . '/app/MacroEvents.php';

    $sync = new \AlphaVault\MacroEvents($config, $db);
    $result = $sync->sync();
    if (!empty($result['skipped'])) {
        fwrite(STDOUT, "AlphaVault macro sync: skipped (lock held)\n");
        exit(0);
    }

    fwrite(STDOUT, sprintf(
        "AlphaVault macro sync: success events=%d updated=%d duration=%dms\n",
        (int) ($result['events_count'] ?? 0),
        (int) ($result['updated_count'] ?? 0),
        (int) ($result['duration_ms'] ?? 0)
    ));
    exit(0);
} catch (\Throwable $err) {
    fwrite(STDERR, 'AlphaVault macro sync: error ' . $err->getMessage() . "\n");
    exit(1);
}
