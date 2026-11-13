<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

try {
    [$config] = require $root . '/app/bootstrap.php';
    $keys = \AlphaVault\Mcp\Keys::ensure($config);
    fwrite(STDOUT, "MCP keys ready\n");
    fwrite(STDOUT, 'private: ' . $keys['privateKeyPath'] . "\n");
    fwrite(STDOUT, 'public: ' . $keys['publicKeyPath'] . "\n");
    fwrite(STDOUT, 'encryption: ' . $keys['encryptionKeyPath'] . "\n");
    fwrite(STDOUT, 'kid: ' . $keys['keyId'] . "\n");
    exit(0);
} catch (\Throwable $err) {
    fwrite(STDERR, 'MCP init failed: ' . $err->getMessage() . "\n");
    exit(1);
}
