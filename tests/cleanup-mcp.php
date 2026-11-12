<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
[, $db] = require $root . '/app/bootstrap.php';

$db->execute("DELETE FROM users WHERE discord_id IN ('mcp-e2e-a', 'mcp-e2e-b')");
$db->execute("DELETE FROM mcp_clients WHERE client_name IN ('MCP E2E Client', 'MCP E2E Confidential')");
$file = $root . '/tests/mcp-test-sessions.json';
if (is_file($file)) {
    unlink($file);
}
fwrite(STDOUT, "MCP E2E cleanup done\n");
