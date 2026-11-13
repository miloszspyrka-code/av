<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

try {
    [, $db] = require $root . '/app/bootstrap.php';
    $db->execute('DELETE FROM mcp_auth_codes WHERE expires_at < UTC_TIMESTAMP() OR revoked = 1');
    $db->execute('DELETE FROM mcp_consents WHERE expires_at < UTC_TIMESTAMP()');
    $db->execute('DELETE FROM mcp_login_requests WHERE expires_at < UTC_TIMESTAMP()');
    $db->execute('DELETE FROM mcp_idempotency WHERE expires_at < UTC_TIMESTAMP()');
    $db->execute('DELETE FROM mcp_access_tokens WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 30 DAY');
    $db->execute('DELETE FROM mcp_refresh_tokens WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 30 DAY');
    fwrite(STDOUT, "MCP maintenance done\n");
    exit(0);
} catch (\Throwable $err) {
    fwrite(STDERR, 'MCP maintenance failed: ' . $err->getMessage() . "\n");
    exit(1);
}
