<?php
declare(strict_types=1);

$root = dirname(__DIR__);
[$config, $db] = require $root . '/app/bootstrap.php';
require_once $root . '/app/MacroEvents.php';

use AlphaVault\MacroEvents;

$macro = new MacroEvents($config, $db);
$db->execute('DELETE FROM macro_events');

$warsaw = new DateTimeZone('Europe/Warsaw');
$now = new DateTimeImmutable('now', $warsaw);
$weekday = (int) $now->format('N');
$monday = $now->modify('-' . ($weekday - 1) . ' days')->setTime(0, 0);
$nextMonday = $monday->modify('+7 days');

$fixture = [
    [
        'title' => 'Inflacja CPI w USA',
        'country' => 'USD',
        'date' => $monday->modify('+2 days')->setTime(14, 30)->format('Y-m-d\TH:i:sP'),
        'impact' => 'High',
        'forecast' => '0,3%',
        'previous' => '0,6%',
    ],
    [
        'title' => 'Nastroje konsumentów UoM',
        'country' => 'USD',
        'date' => $monday->modify('+3 days')->setTime(16, 0)->format('Y-m-d\TH:i:sP'),
        'impact' => 'High',
        'forecast' => '53,2',
        'previous' => '53,6',
    ],
    [
        'title' => 'Sprzedaż detaliczna w USA',
        'country' => 'USD',
        'date' => $nextMonday->modify('+1 day')->setTime(14, 30)->format('Y-m-d\TH:i:sP'),
        'impact' => 'High',
        'forecast' => '0,4%',
        'previous' => '0,2%',
    ],
    [
        'title' => 'Protokół FOMC',
        'country' => 'USD',
        'date' => $nextMonday->modify('+2 days')->setTime(20, 0)->format('Y-m-d\TH:i:sP'),
        'impact' => 'High',
        'forecast' => '',
        'previous' => '',
    ],
    [
        'title' => 'Zapasy ropy EIA',
        'country' => 'USD',
        'date' => $nextMonday->modify('+3 days')->setTime(16, 30)->format('Y-m-d\TH:i:sP'),
        'impact' => 'Medium',
        'forecast' => '-0,6M',
        'previous' => '-0,9M',
    ],
];

$result = $macro->syncPayload(MacroEvents::normalize($fixture));
$db->execute(
    "UPDATE macro_sync_state SET last_status = 'success', last_success_at = UTC_TIMESTAMP(), events_count = ?, duration_ms = 1 WHERE id = 1",
    [$result['events_count']]
);

echo 'seeded=' . $result['events_count'] . "\n";
