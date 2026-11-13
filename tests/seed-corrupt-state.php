<?php
declare(strict_types=1);

/**
 * Wstawia do MySQL uszkodzony (UTF-8 -> Windows-1250) stan użytkownika demo.
 * Używane przez tests/api.e2e.mjs do sprawdzenia naprawy przy GET /api/state.
 */

$root = dirname(__DIR__);
[$config, $db] = require $root . '/app/bootstrap.php';

$userId = isset($argv[1]) ? (int) $argv[1] : 0;
if ($userId <= 0) {
    fwrite(STDERR, "Brak ID użytkownika demo.\n");
    exit(1);
}
$row = $db->queryOne('SELECT id FROM users WHERE id = ? LIMIT 1', [$userId]);
if ($row === null) {
    fwrite(STDERR, "Nie ma użytkownika o ID {$userId}.\n");
    exit(1);
}

$correct = 'Cześć {imie}. Widzę dziś 0 straty i gotowość 66/100, więc jestem w pogotowiu.';
if (!function_exists('iconv')) {
    fwrite(STDERR, "Brak rozszerzenia iconv.\n");
    exit(1);
}
$broken = @iconv('CP1250', 'UTF-8', $correct);
if (!is_string($broken)) {
    fwrite(STDERR, "Nie można zbudować fixture mojibake.\n");
    exit(1);
}

$existing = $db->queryOne('SELECT state FROM user_states WHERE user_id = ?', [$userId]);
$state = $existing !== null ? json_decode((string) $existing['state'], true) : null;
if (!is_array($state) && is_file($config->demoStateFile)) {
    $demo = file_get_contents($config->demoStateFile);
    $state = $demo !== false ? json_decode($demo, true) : null;
}
if (!is_array($state)) {
    $state = [];
}

$state['chat'] = [['role' => 'a', 'html' => $broken, 'at' => 1]];
$state['user'] = is_array($state['user'] ?? null) ? $state['user'] : [];
$state['user']['name'] = 'Miłosz Spyrka';

$json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, "Nie można zakodować stanu.\n");
    exit(1);
}

$db->execute(
    'INSERT INTO user_states (user_id, state, revision, bytes) VALUES (?, ?, 1, ?)
     ON DUPLICATE KEY UPDATE state = VALUES(state), bytes = VALUES(bytes)',
    [$userId, $json, strlen($json)]
);

echo "Seeded corrupt state for user {$userId}.\n";
