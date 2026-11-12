<?php
declare(strict_types=1);

/**
 * Przywraca stan demo z db/demo-state.json (tylko testy E2E).
 * Działa dla wszystkich kont demo, bo każde demo ma teraz własny wiersz.
 */

$root = dirname(__DIR__);
[$config, $db] = require $root . '/app/bootstrap.php';

$users = $db->query('SELECT id FROM users WHERE is_demo = 1 ORDER BY id');
if ($users === []) {
    fwrite(STDOUT, "Brak użytkownika demo\n");
    exit(0);
}
$state = file_get_contents($root . '/db/demo-state.json');
if ($state === false) {
    fwrite(STDERR, "Brak db/demo-state.json\n");
    exit(1);
}
$ids = [];
foreach ($users as $user) {
    $userId = (int) $user['id'];
    $ids[] = $userId;
    $db->execute(
        'INSERT INTO user_states (user_id, state, revision, bytes) VALUES (?, ?, 1, ?)
         ON DUPLICATE KEY UPDATE state = VALUES(state), bytes = VALUES(bytes), revision = revision + 1',
        [$userId, $state, strlen($state)]
    );
    $db->execute('DELETE FROM exchange_connections WHERE user_id = ?', [$userId]);
    $db->execute('DELETE FROM exchange_imports WHERE user_id = ?', [$userId]);
}
$db->execute("DELETE FROM rate_limits WHERE bucket LIKE 'connections-%' OR bucket IN ('connection-sync', 'mt-ea')");
fwrite(STDOUT, 'Demo state reset for users: ' . implode(',', $ids) . "\n");
