#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);

try {
    [$config, $db, $auth] = require $root . '/app/bootstrap.php';
    $wearables = new \AlphaVault\Wearables($config, $db, $auth);
    $result = $wearables->syncAll();
    fwrite(STDOUT, sprintf(
        "AlphaVault wearables sync: ok=%d failed=%d total=%d\n",
        (int) $result['ok'],
        (int) $result['failed'],
        (int) $result['total']
    ));
    exit(0);
} catch (\Throwable $err) {
    fwrite(STDERR, 'AlphaVault wearables sync: error ' . $err->getMessage() . "\n");
    exit(1);
}
