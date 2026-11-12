<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
[$config, $db] = require $root . '/app/bootstrap.php';

$db->execute("DELETE FROM rate_limits WHERE bucket LIKE 'mcp-%'");

$demo = json_decode((string) file_get_contents($root . '/db/demo-state.json'), true);
if (!is_array($demo)) {
    fwrite(STDERR, "Brak demo-state.json\n");
    exit(1);
}

$users = [
    'mcp-e2e-a' => ['name' => 'MCP E2E A', 'state' => $demo],
    'mcp-e2e-b' => ['name' => 'MCP E2E B', 'state' => $demo],
];
$users['mcp-e2e-b']['state']['trades'] = [[
    'id' => 'trade-b-only',
    'date' => '2026-11-12T09:00:00.000Z',
    'pair' => 'BTC/USDT',
    'dir' => 'l',
    'entry' => '60000',
    'exit' => '61000',
    'sl' => '59000',
    'tp' => '63000',
    'size' => '1',
    'result' => 1000,
    'r' => 1,
    'grade' => 'A',
    'checklist' => 7,
    'checkTotal' => 7,
    'errors' => [],
    'emotion' => 'Skupienie',
    'note' => 'Tylko konto B',
    'mentor' => 'Test',
    'setup' => 'Retest wybicia',
    'session' => 'Londyn',
    'mae' => 0.1,
    'mfe' => 1.2,
    'durationMin' => 60,
    'fee' => 1,
    'plan' => true,
    'source' => 'TEST',
    'account' => 'WEEX',
    'screens' => [],
]];

$result = [];
foreach ($users as $discordId => $data) {
    $row = $db->queryOne('SELECT * FROM users WHERE discord_id = ? LIMIT 1', [$discordId]);
    if ($row === null) {
        $db->execute(
            'INSERT INTO users (discord_id, username, global_name, email, is_demo, plan, access_until, role_verified, last_login_at)
             VALUES (?, ?, ?, ?, 1, ?, ?, 1, CURRENT_TIMESTAMP)',
            [$discordId, $discordId, $data['name'], $discordId . '@example.test', 'full', '2099-12-31']
        );
        $userId = $db->lastInsertId();
    } else {
        $userId = (int) $row['id'];
    }

    $json = json_encode($data['state'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $db->execute(
        'INSERT INTO user_states (user_id, state, revision, bytes) VALUES (?, ?, 1, ?)
         ON DUPLICATE KEY UPDATE state = VALUES(state), revision = revision + 1, bytes = VALUES(bytes)',
        [$userId, $json, strlen($json)]
    );

    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $db->execute(
        'INSERT INTO sessions (id, user_id, remember, user_agent, ip, expires_at) VALUES (?, ?, 1, ?, ?, UTC_TIMESTAMP() + INTERVAL 2 DAY)',
        [hash('sha256', $token), $userId, 'mcp-e2e', '127.0.0.1']
    );
    $result[$discordId] = ['userId' => $userId, 'session' => $token];
}

$target = $root . '/tests/mcp-test-sessions.json';
file_put_contents($target, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
chmod($target, 0600);
fwrite(STDOUT, "MCP E2E users ready\n");
