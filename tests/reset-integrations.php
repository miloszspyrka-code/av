<?php
declare(strict_types=1);

/**
 * Czyści wyłącznie dane integracji dla konta demo (test E2E).
 * Nie usuwa ręcznych transakcji ani ustawień użytkownika.
 */

$root = dirname(__DIR__);
[$config, $db] = require $root . '/app/bootstrap.php';

$userId = 1;
$demo = $db->queryOne("SELECT id FROM users WHERE discord_id = 'demo-local' LIMIT 1");
if ($demo === null) {
    $demo = $db->queryOne('SELECT id FROM users WHERE is_demo = 1 ORDER BY id DESC LIMIT 1');
}
if ($demo !== null) {
    $userId = (int) $demo['id'];
}
$envUser = (int) getenv('TEST_USER_ID');
if ($envUser > 0) {
    $userId = $envUser;
}
$db->execute('DELETE FROM exchange_connections WHERE user_id = ?', [$userId]);
$db->execute('DELETE FROM exchange_imports WHERE user_id = ?', [$userId]);
$db->execute('DELETE FROM exchange_sync_state WHERE id = 1');
$db->execute("INSERT INTO exchange_sync_state (id, last_status) VALUES (1, 'never')");
$db->execute("DELETE FROM rate_limits WHERE bucket LIKE 'connections-%' OR bucket IN ('connection-sync', 'mt-ea')");

$row = $db->queryOne('SELECT state FROM user_states WHERE user_id = ?', [$userId]);
if ($row !== null) {
    $state = json_decode((string) $row['state'], true);
    if (is_array($state)) {
        $trades = is_array($state['trades'] ?? null) ? $state['trades'] : [];
        $state['trades'] = array_values(array_filter($trades, static function (array $trade): bool {
            return empty($trade['extId']);
        }));
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            $db->execute('UPDATE user_states SET state = ?, bytes = ?, revision = revision + 1 WHERE user_id = ?', [$json, strlen($json), $userId]);
        }
    }
}

fwrite(STDOUT, "Integrations test data reset\n");
