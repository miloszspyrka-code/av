#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Cykliczna synchronizacja dat kont demo.
 *
 * Każde konto `is_demo = 1` ma stan z `db/demo-state.json`. Fixture ma stałe
 * daty, więc raz dziennie przesuwamy je na dziś (Europe/Warsaw), żeby demo
 * pokazywało dzisiejsze transakcje, plan dnia i tryb ochronny.
 * Operacja jest idempotentna — przy braku różnicy nic nie zapisuje.
 */

$root = dirname(__DIR__);

try {
    [$config, $db] = require $root . '/app/bootstrap.php';

    $today = \AlphaVault\DemoState::today();
    $rows = $db->query(
        'SELECT s.user_id, s.state FROM user_states s JOIN users u ON u.id = s.user_id WHERE u.is_demo = 1'
    );

    $shifted = 0;
    $skipped = 0;
    foreach ($rows as $row) {
        $json = (string) ($row['state'] ?? '');
        if ($json === '') {
            $skipped++;
            continue;
        }
        $next = \AlphaVault\DemoState::shiftToToday($json);
        if ($next === null) {
            $skipped++;
            continue;
        }
        $db->execute(
            'UPDATE user_states SET state = ?, bytes = ?, revision = revision + 1 WHERE user_id = ?',
            [$next, strlen($next), (int) $row['user_id']]
        );
        $shifted++;
    }

    fwrite(STDOUT, sprintf(
        "AlphaVault demo state: success date=%s shifted=%d skipped=%d\n",
        $today,
        $shifted,
        $skipped
    ));
    exit(0);
} catch (\Throwable $err) {
    fwrite(STDERR, 'AlphaVault demo state: error ' . $err->getMessage() . "\n");
    exit(1);
}
