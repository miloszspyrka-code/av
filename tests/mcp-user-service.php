<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
[$config, $db] = require $root . '/app/bootstrap.php';

$ids = ['mcp-e2e-account', 'mcp-e2e-account-2', 'mcp-e2e-same-name-old', 'mcp-e2e-same-name-new'];
$db->execute('DELETE FROM users WHERE discord_id IN (?, ?, ?, ?)', $ids);

$p1 = ['id' => 'mcp-e2e-account', 'username' => 'first-name', 'global_name' => 'First', 'email' => 'same@example.test', 'avatar_url' => null];
$u1 = \AlphaVault\UserService::upsertDiscord($config, $db, $p1, true);
$count1 = (int) ($db->queryOne('SELECT COUNT(*) AS n FROM users WHERE discord_id = ?', ['mcp-e2e-account'])['n'] ?? 0);

$p2 = ['id' => 'mcp-e2e-account', 'username' => 'second-name', 'global_name' => 'Second', 'email' => 'same@example.test', 'avatar_url' => null];
$u2 = \AlphaVault\UserService::upsertDiscord($config, $db, $p2, true);
$count2 = (int) ($db->queryOne('SELECT COUNT(*) AS n FROM users WHERE discord_id = ?', ['mcp-e2e-account'])['n'] ?? 0);
$row2 = $db->queryOne('SELECT username FROM users WHERE id = ?', [(int) $u2['id']]);

$old = ['id' => 'mcp-e2e-same-name-old', 'username' => 'identical', 'global_name' => 'Identical', 'email' => 'identical@example.test', 'avatar_url' => null];
$new = ['id' => 'mcp-e2e-same-name-new', 'username' => 'identical', 'global_name' => 'Identical', 'email' => 'identical@example.test', 'avatar_url' => null];
\AlphaVault\UserService::upsertDiscord($config, $db, $old, true);
$newUser = \AlphaVault\UserService::upsertDiscord($config, $db, $new, true);
$countSame = (int) ($db->queryOne(
    "SELECT COUNT(*) AS n FROM users WHERE username = 'identical' OR email = 'identical@example.test'"
)['n'] ?? 0);

$ok = $count1 === 1
    && $count2 === 1
    && (int) $u1['id'] === (int) $u2['id']
    && (string) $row2['username'] === 'second-name'
    && (int) $newUser['id'] !== (int) $db->queryOne('SELECT id FROM users WHERE discord_id = ?', ['mcp-e2e-same-name-old'])['id']
    && $countSame === 2;

$db->execute('DELETE FROM users WHERE discord_id IN (?, ?, ?, ?)', $ids);

if (!$ok) {
    fwrite(STDERR, "UserService FAIL\n");
    exit(1);
}
fwrite(STDOUT, "UserService PASS\n");
