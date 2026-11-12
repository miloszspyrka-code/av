<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

try {
    [$config, $db] = require $root . '/app/bootstrap.php';
    $db->migrate($root . '/db/schema.sql');
    fwrite(STDOUT, "AlphaVault schema ready\n");
    exit(0);
} catch (\Throwable $err) {
    fwrite(STDERR, 'AlphaVault migration failed: ' . $err->getMessage() . "\n");
    exit(1);
}
