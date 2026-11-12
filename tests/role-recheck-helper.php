<?php
declare(strict_types=1);

/**
 * Fixture dla tests/role-recheck.e2e.mjs. Nie uruchamiaj na produkcji.
 * Używa ALPHA_VAULT_CONFIG wskazanego przez test.
 */

$root = dirname(__DIR__);
[$config, $db] = require $root . '/app/bootstrap.php';

function out(array $data): never
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

$command = (string) ($argv[1] ?? '');
$discordId = (string) ($argv[2] ?? 'role-e2e-user');
$token = (string) ($argv[3] ?? 'role-e2e-token');

$findUser = static fn (): ?array => $db->queryOne('SELECT * FROM users WHERE discord_id = ? LIMIT 1', [$discordId]);

if ($command === 'seed') {
    $existing = $findUser();
    if ($existing !== null) {
        $db->execute('DELETE FROM sessions WHERE user_id = ?', [(int) $existing['id']]);
        $db->execute('DELETE FROM audit_log WHERE user_id = ?', [(int) $existing['id']]);
        $db->execute('DELETE FROM user_states WHERE user_id = ?', [(int) $existing['id']]);
        $db->execute('DELETE FROM users WHERE id = ?', [(int) $existing['id']]);
    }
    $db->execute(
        "INSERT INTO users (discord_id, username, global_name, is_demo, plan, access_until, role_verified, role_checked_at, role_check_status, last_login_at)
         VALUES (?, 'Role E2E', 'Role E2E', 0, 'full', NULL, 1, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 600 SECOND), 'ok', UTC_TIMESTAMP())",
        [$discordId]
    );
    $userId = $db->lastInsertId();
    $db->execute(
        'INSERT INTO sessions (id, user_id, remember, user_agent, ip, expires_at) VALUES (?, ?, 1, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY))',
        [hash('sha256', $token), $userId, 'role-e2e', '127.0.0.1']
    );
    $db->execute("UPDATE role_check_state SET lock_token = NULL, locked_until = NULL, last_status = 'never', last_error = NULL WHERE id = 1");
    out(['userId' => $userId, 'sessionId' => hash('sha256', $token)]);
}

if ($command === 'expire') {
    $db->execute(
        'UPDATE users SET role_checked_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 600 SECOND) WHERE discord_id = ?',
        [$discordId]
    );
    out(['ok' => true]);
}

if ($command === 'lock') {
    $db->execute(
        "UPDATE role_check_state SET lock_token = 'e2e-lock', locked_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE id = 1"
    );
    out(['ok' => true]);
}

if ($command === 'unlock') {
    $db->execute("UPDATE role_check_state SET lock_token = NULL, locked_until = NULL WHERE id = 1");
    out(['ok' => true]);
}

if ($command === 'state') {
    $user = $findUser();
    $sessions = $user === null ? 0 : (int) ($db->queryOne(
        'SELECT COUNT(*) AS c FROM sessions WHERE user_id = ? AND expires_at > UTC_TIMESTAMP()',
        [(int) $user['id']]
    )['c'] ?? 0);
    $audit = $user === null ? 0 : (int) ($db->queryOne(
        "SELECT COUNT(*) AS c FROM audit_log WHERE user_id = ? AND action = 'access.role_revoked'",
        [(int) $user['id']]
    )['c'] ?? 0);
    $check = $db->queryOne('SELECT last_status, last_error FROM role_check_state WHERE id = 1');
    out([
        'user' => $user,
        'sessions' => $sessions,
        'auditRevoked' => $audit,
        'checkStatus' => $check['last_status'] ?? null,
        'checkError' => $check['last_error'] ?? null,
    ]);
}

if ($command === 'cleanup') {
    $user = $findUser();
    if ($user !== null) {
        $db->execute('DELETE FROM sessions WHERE user_id = ?', [(int) $user['id']]);
        $db->execute('DELETE FROM audit_log WHERE user_id = ?', [(int) $user['id']]);
        $db->execute('DELETE FROM user_states WHERE user_id = ?', [(int) $user['id']]);
        $db->execute('DELETE FROM users WHERE id = ?', [(int) $user['id']]);
    }
    $db->execute("UPDATE role_check_state SET lock_token = NULL, locked_until = NULL WHERE id = 1");
    out(['ok' => true]);
}

fwrite(STDERR, 'Nieznana komenda: ' . $command . "\n");
exit(1);
