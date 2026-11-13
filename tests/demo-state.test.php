<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/DemoState.php';

use AlphaVault\DemoState;

function check(bool $condition, string $label): void
{
    echo ($condition ? 'PASS' : 'FAIL') . '  ' . $label . "\n";
    if (!$condition) {
        $GLOBALS['failed'] = true;
    }
}

$GLOBALS['failed'] = false;

$fixture = (string) file_get_contents($root . '/db/demo-state.json');

// 1. Fixture jest zakotwiczona w dacie 2026-11-12, wiec przesuniecie na ten dzien nic nie zmienia.
check(DemoState::shift($fixture, '2026-11-12') === null, 'fixture: delta 0 bez zapisu');

// 2. Przesuniecie na pozniejszy dzien zmienia wszystkie daty i zachowuje JSON.
$shifted = DemoState::shift($fixture, '2026-12-01');
check(is_string($shifted), 'shift: zwraca JSON');
$data = is_string($shifted) ? json_decode($shifted, true) : null;
check(is_array($data), 'shift: poprawny JSON');
check(($data['trades'][0]['date'] ?? '') !== (json_decode($fixture, true)['trades'][0]['date'] ?? ''), 'shift: data transakcji zmieniona');
$newest = '';
foreach (($data['trades'] ?? []) as $trade) {
    $date = substr((string) ($trade['date'] ?? ''), 0, 10);
    if ($date > $newest) {
        $newest = $date;
    }
}
check($newest === '2026-12-01', 'shift: najnowsza transakcja to dzis');
check(isset($data['plans']['2026-12-01']), 'shift: klucz planu na dzis');
check(($data['checkins'][0]['date'] ?? '') === '2026-11-17', 'shift: check-in przesuniety o 19 dni');
check(str_contains($shifted, '2026-12-22'), 'shift: data przyszla (subskrypcja) przesunieta');

// 3. Idempotencja: drugi przebieg na ten sam dzien nie zmienia stanu.
check(DemoState::shift((string) $shifted, '2026-12-01') === null, 'shift: idempotentny');

// 4. Zakotwiczenie na planach, gdy brak transakcji.
$plansOnly = json_encode(['plans' => ['2026-11-10' => ['date' => '2026-11-10'], '2026-11-05' => ['date' => '2026-11-05']]]);
$moved = is_string($plansOnly) ? DemoState::shift($plansOnly, '2026-11-15') : null;
check(is_string($moved) && isset(json_decode($moved, true)['plans']['2026-11-15']), 'shift: plan jako punkt zaczepienia');

// 5. Przesuniecie wstecz, gdy dzis jest przed fixture.
$back = DemoState::shift($fixture, '2026-11-01');
$backData = is_string($back) ? json_decode($back, true) : null;
check(is_array($backData) && substr((string) ($backData['trades'][0]['date'] ?? ''), 0, 10) === '2026-08-07', 'shift: przesuniecie wstecz');

// 6. Niepoprawne dane nie wywracaja synchronizacji.
check(DemoState::shift('to nie jest json', '2026-12-01') === null, 'shift: niepoprawny JSON');
check(DemoState::shift('{"trades":[]}', '2026-12-01') === null, 'shift: brak dat');

// 7. Strefa prezentacji i format daty.
check(preg_match('/^\d{4}-\d{2}-\d{2}$/', DemoState::today()) === 1, 'today: format Y-m-d');
check(DemoState::TIMEZONE === 'Europe/Warsaw', 'today: strefa Europe/Warsaw');

echo "\n" . ($GLOBALS['failed'] ? 'FAIL' : 'PASS') . "\n";
exit($GLOBALS['failed'] ? 1 : 0);
