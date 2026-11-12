<?php
declare(strict_types=1);

$root = dirname(__DIR__);
[$config, $db] = require $root . '/app/bootstrap.php';
require_once $root . '/app/MacroEvents.php';

use AlphaVault\MacroEvents;

function check(bool $condition, string $label): void
{
    echo ($condition ? 'PASS' : 'FAIL') . '  ' . $label . "\n";
    if (!$condition) {
        $GLOBALS['failed'] = true;
    }
}

$GLOBALS['failed'] = false;
$macro = new MacroEvents($config, $db);
$db->execute('DELETE FROM macro_events');
$db->execute("UPDATE macro_sync_state SET last_status = 'never', last_success_at = NULL, events_count = 0 WHERE id = 1");

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
        'title' => 'Zapasy ropy EIA',
        'country' => 'USD',
        'date' => $nextMonday->modify('+1 day')->setTime(16, 0)->format('Y-m-d\TH:i:sP'),
        'impact' => 'Medium',
        'forecast' => '-0,6M',
        'previous' => '-0,9M',
    ],
    [
        'title' => 'Protokół FOMC',
        'country' => 'USD',
        'date' => $monday->modify('+4 days')->setTime(20, 0)->format('Y-m-d\TH:i:sP'),
        'impact' => 'High',
        'forecast' => '',
        'previous' => '',
    ],
    [
        'title' => 'Dane pomijane',
        'country' => 'USD',
        'date' => $nextMonday->modify('+3 days')->setTime(10, 0)->format('Y-m-d\TH:i:sP'),
        'impact' => 'Low',
        'forecast' => '',
        'previous' => '',
    ],
    [
        'title' => 'Holiday',
        'country' => 'All',
        'date' => $nextMonday->modify('+3 days')->setTime(10, 0)->format('Y-m-d\TH:i:sP'),
        'impact' => 'Holiday',
        'forecast' => '',
        'previous' => '',
    ],
    [
        'title' => 'Zły rekord',
        'country' => 'USD',
        'date' => 'to-nie-jest-data',
        'impact' => 'High',
        'forecast' => '',
        'previous' => '',
    ],
];

$normalized = MacroEvents::normalize($fixture);
check(count($normalized) === 3, 'normalize: tylko ★★ i ★★★, bez Low/Holiday/błędnych dat');
$impacts = array_column($normalized, 'impact');
check(in_array(3, $impacts, true) && in_array(2, $impacts, true), 'normalize: High=3, Medium=2');
check($normalized[0]['starts_at_utc'] === $monday->modify('+2 days')->setTime(13, 30)->format('Y-m-d H:i:s'), 'normalize: czas lokalny -> UTC');
check($normalized[0]['description'] === 'Prognoza 0,3% · poprzednio 0,6%', 'normalize: opis z prognozą i poprzednim odczytem');
check(strlen($normalized[0]['external_id']) === 40, 'normalize: stabilny identyfikator');

$first = $macro->syncPayload($normalized);
check($first['events_count'] === 3, 'syncPayload: pierwszy zapis 3 wydarzeń');
$count = $db->queryOne('SELECT COUNT(*) AS c FROM macro_events WHERE is_deleted = 0');
check((int) $count['c'] === 3, 'syncPayload: 3 aktywne rekordy w bazie');

$macro->syncPayload($normalized);
$count = $db->queryOne('SELECT COUNT(*) AS c FROM macro_events WHERE is_deleted = 0');
check((int) $count['c'] === 3, 'syncPayload: powtórka nie tworzy duplikatów');

$list = $macro->listForApi();
check(count($list['events']) === 3, 'listForApi: zwraca 3 wydarzenia z okna');
check(($list['events'][0]['startAt'] ?? '') !== '' && str_ends_with($list['events'][0]['startAt'], 'Z'), 'listForApi: startAt w UTC ISO');
check(($list['events'][0]['startIso'] ?? '') !== '', 'listForApi: oryginalne ISO zachowane');
check(($list['events'][0]['source'] ?? '') === 'forexfactory', 'listForApi: źródło zapisane');

$withoutFomc = array_values(array_filter($normalized, static fn (array $e): bool => $e['name'] !== 'Protokół FOMC'));
$fomcId = array_values(array_filter($normalized, static fn (array $e): bool => $e['name'] === 'Protokół FOMC'))[0]['external_id'];
$macro->syncPayload($withoutFomc);
$row = $db->queryOne('SELECT is_deleted FROM macro_events WHERE external_id = ?', [$fomcId]);
check((int) ($row['is_deleted'] ?? 0) === 1, 'syncPayload: brak w feedzie = soft delete');

$macro->syncPayload($normalized);
$row = $db->queryOne('SELECT is_deleted, deleted_at FROM macro_events WHERE external_id = ?', [$fomcId]);
check((int) ($row['is_deleted'] ?? 1) === 0 && $row['deleted_at'] === null, 'syncPayload: powrót wydarzenia przywraca rekord');

$macro->syncPayload([]);
$count = $db->queryOne('SELECT COUNT(*) AS c FROM macro_events WHERE is_deleted = 0');
check((int) $count['c'] === 3, 'syncPayload: pusta lista nie czyści widoku');

$tvUtc = new DateTimeZone('UTC');
$tvRows = [
    [
        'title' => 'Retail Sales MoM',
        'country' => 'US',
        'importance' => 1,
        'date' => $nextMonday->modify('+1 day')->setTime(14, 30)->setTimezone($tvUtc)->format('Y-m-d\TH:i:s.000\Z'),
        'forecast' => 0.4,
        'previous' => 0.2,
    ],
    [
        'title' => 'NY Empire State Manufacturing Index',
        'country' => 'US',
        'importance' => 0,
        'date' => $nextMonday->modify('+1 day')->setTime(14, 30)->setTimezone($tvUtc)->format('Y-m-d\TH:i:s.000\Z'),
        'forecast' => '',
        'previous' => 25.5,
    ],
    [
        'title' => '6-Month Bill Auction Bid-to-Cover',
        'country' => 'US',
        'importance' => 1,
        'date' => $nextMonday->modify('+1 day')->setTime(16, 30)->setTimezone($tvUtc)->format('Y-m-d\TH:i:s.000\Z'),
        'forecast' => '',
        'previous' => '',
    ],
    [
        'title' => 'Low impact',
        'country' => 'US',
        'importance' => -1,
        'date' => $nextMonday->modify('+2 days')->setTime(10, 0)->setTimezone($tvUtc)->format('Y-m-d\TH:i:s.000\Z'),
        'forecast' => '',
        'previous' => '',
    ],
];
$tv = MacroEvents::normalizeTradingView($tvRows);
check(count($tv) === 2, 'normalizeTradingView: importance 1/0, bez aukcji i -1');
check($tv[0]['currency'] === 'USD' && $tv[0]['impact'] === 3, 'normalizeTradingView: kraj -> waluta i impact');
check($tv[1]['impact'] === 2 && $tv[1]['previous'] === '25.5', 'normalizeTradingView: medium + liczba jako tekst');

$macro->syncPayload($tv, null, 'tradingview', false);
$count = $db->queryOne('SELECT COUNT(*) AS c FROM macro_events WHERE is_deleted = 0 AND source = ?', ['tradingview']);
check((int) $count['c'] === 2, 'syncPayload: wydarzenia TradingView zapisane');

// FF wygrywa w swoim oknie: TV w tym samym czasie ma zostać zastąpione przez FF.
$ffSameSlot = [[
    'title' => 'Retail Sales m/m',
    'country' => 'USD',
    'date' => $nextMonday->modify('+1 day')->setTime(14, 30)->format('Y-m-d\TH:i:sP'),
    'impact' => 'High',
    'forecast' => '0,4%',
    'previous' => '0,2%',
]];
$macro->syncPayload(MacroEvents::normalize($ffSameSlot));
$tvActive = $db->queryOne('SELECT COUNT(*) AS c FROM macro_events WHERE is_deleted = 0 AND source = ?', ['tradingview']);
check((int) $tvActive['c'] === 0, 'syncPayload: FF czyści TV w swoim oknie');

$db->execute(
    "UPDATE macro_sync_state SET last_status = 'success', last_success_at = UTC_TIMESTAMP(), events_count = 3 WHERE id = 1"
);
$state = $macro->syncState();
check($state['status'] === 'success' && $state['stale'] === false && $state['eventsCount'] === 3, 'syncState: sukces i świeżość');

if ($GLOBALS['failed']) {
    exit(1);
}
echo "Macro events: wszystkie testy PASS\n";
