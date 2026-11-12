<?php
declare(strict_types=1);

/**
 * Przywraca stan demo z db/demo-state.json (tylko testy E2E).
 */

$root = dirname(__DIR__);
[$config, $db] = require $root . '/app/bootstrap.php';

$demo = $db->queryOne("SELECT id FROM users WHERE discord_id = 'demo-local' LIMIT 1");
if ($demo === null) {
    $demo = $db->queryOne('SELECT id FROM users WHERE is_demo = 1 ORDER BY id DESC LIMIT 1');
}
if ($demo === null) {
    fwrite(STDOUT, "Brak użytkownika demo\n");
    exit(0);
}
$userId = (int) $demo['id'];
$state = file_get_contents($root . '/db/demo-state.json');
if ($state === false) {
    fwrite(STDERR, "Brak db/demo-state.json\n");
    exit(1);
}
$db->execute('UPDATE user_states SET state = ?, bytes = ?, revision = revision + 1 WHERE user_id = ?', [$state, strlen($state), $userId]);
$db->execute('DELETE FROM exchange_connections WHERE user_id = ?', [$userId]);
$db->execute('DELETE FROM exchange_imports WHERE user_id = ?', [$userId]);
$db->execute("DELETE FROM rate_limits WHERE bucket LIKE 'connections-%' OR bucket IN ('connection-sync', 'mt-ea')");
fwrite(STDOUT, "Demo state reset for user $userId\n");
