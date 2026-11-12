<?php
declare(strict_types=1);

$root = dirname(__DIR__);
[$config, $db] = require $root . '/app/bootstrap.php';
require_once $root . '/app/DiscordEvents.php';

use AlphaVault\DiscordEvents;

function check(bool $condition, string $label): void
{
    echo ($condition ? 'PASS' : 'FAIL') . '  ' . $label . "\n";
    if (!$condition) {
        $GLOBALS['failed'] = true;
    }
}

$GLOBALS['failed'] = false;
$events = new DiscordEvents($config, $db);
$guild = (string) ($config->discord['guildId'] ?? '');

$db->execute('DELETE FROM discord_scheduled_events WHERE guild_id = ?', [$guild]);

// DST: source timestamps are UTC, display is Europe/Warsaw.
$dst = [
    '2026-10-25T00:30:00Z' => ['02:30', '+02:00'],
    '2026-10-25T01:30:00Z' => ['02:30', '+01:00'],
    '2027-03-28T00:30:00Z' => ['01:30', '+01:00'],
    '2027-03-28T01:30:00Z' => ['03:30', '+02:00'],
];
foreach ($dst as $iso => $expected) {
    $parts = DiscordEvents::localParts($iso);
    check($parts['time'] === $expected[0] && $parts['offset'] === $expected[1], 'DST ' . $iso . ' -> ' . $expected[0] . ' ' . $expected[1]);
}

check(DiscordEvents::hostFromName('AT - Marcin - AKADEMIA') === 'Marcin', 'host from event name');
check(DiscordEvents::hostFromName('Poranna Analiza Krypto - Marcin - AKADEMIA') === 'Marcin', 'host from long event name');
check(DiscordEvents::hostFromName('Rynek - Hubert - AMADEMIA') === 'Hubert', 'host from event name 2');
check(DiscordEvents::hostFromName('Bez separatora') === null, 'host missing returns null');

$fixture = [
    [
        'id' => '1001', 'guild_id' => $guild, 'name' => 'AT - Marcin - AKADEMIA',
        'description' => 'Analiza rynku', 'scheduled_start_time' => '2026-11-12T19:00:00+00:00',
        'scheduled_end_time' => '2026-11-12T20:00:00+00:00', 'status' => 1, 'entity_type' => 2,
        'channel_id' => '111', 'creator' => ['id' => '9', 'username' => 'bot', 'bot' => true],
        'user_count' => 8, 'entity_metadata' => null,
    ],
    [
        'id' => '1002', 'guild_id' => $guild, 'name' => 'Konsultacja 1:1 - Marcin',
        'description' => 'Konsultacja indywidualna', 'scheduled_start_time' => '2026-11-13T17:00:00+00:00',
        'status' => 1, 'entity_type' => 2, 'channel_id' => '112',
        'creator' => ['id' => '9', 'username' => 'bot', 'bot' => true], 'user_count' => 3, 'entity_metadata' => null,
    ],
    [
        'id' => '1003', 'guild_id' => $guild, 'name' => 'Wydarzenie zewnÄ™trzne - Hubert',
        'description' => 'Spotkanie', 'scheduled_start_time' => '2026-11-16T18:00:00+00:00',
        'status' => 1, 'entity_type' => 3, 'channel_id' => null,
        'creator' => ['id' => '9', 'username' => 'bot', 'bot' => true], 'user_count' => 3,
        'entity_metadata' => ['location' => 'Discord'],
    ],
    [
        'id' => '1004', 'guild_id' => $guild, 'name' => 'Stage - Marcin - AKADEMIA',
        'description' => 'Stage wydarzenie', 'scheduled_start_time' => '2026-11-18T18:00:00+00:00',
        'status' => 1, 'entity_type' => 1, 'channel_id' => '113',
        'creator' => ['id' => '9', 'username' => 'bot', 'bot' => true],
        'entity_metadata' => null,
    ],
];

$result = $events->syncPayload($fixture);
check($result['events_count'] === 4, 'first sync inserts 4 events');
$list = $events->listForApi(false);
check(count($list['events']) === 4, 'list returns 4 events');
check($list['live'] && $list['consultations'], 'classification live + consultation');
check(($list['nextConsultation']['id'] ?? null) === '1002', 'next consultation is 1002');
$stage = null; $external = null;
foreach ($list['events'] as $item) {
    if ($item['id'] === '1004') $stage = $item;
    if ($item['id'] === '1003') $external = $item;
}
check($stage !== null && $stage['entityType'] === 'STAGE_INSTANCE', 'STAGE_INSTANCE mapping');
check($stage !== null && $stage['durationMinutes'] === null, 'missing end time gives no duration');
check($stage !== null && $stage['userCount'] === null, 'missing user_count stays null');
check($external !== null && $external['entityType'] === 'EXTERNAL' && $external['location'] === 'Discord', 'EXTERNAL location mapping');
check(($list['events'][0]['startLabel'] ?? '') !== '', 'start label generated');

$events->syncPayload($fixture);
$count = $db->queryOne('SELECT COUNT(*) AS c FROM discord_scheduled_events WHERE guild_id = ?', [$guild]);
check((int) $count['c'] === 4, 'second sync does not duplicate');

$changed = $fixture;
$changed[0]['name'] = 'AT - Marcin - AKADEMIA (zmienione)';
$changed[0]['scheduled_start_time'] = '2026-11-12T20:00:00+00:00';
$changed[0]['user_count'] = 9;
$events->syncPayload($changed);
$row = $db->queryOne('SELECT name, user_count, category, category_source FROM discord_scheduled_events WHERE discord_event_id = ?', ['1001']);
check($row['name'] === 'AT - Marcin - AKADEMIA (zmienione)' && (int) $row['user_count'] === 9, 'upsert updates changed fields');

$events->adminSetCategory('1001', 'consultation');
$events->syncPayload($changed);
$row = $db->queryOne('SELECT category, category_source FROM discord_scheduled_events WHERE discord_event_id = ?', ['1001']);
check($row['category'] === 'consultation' && $row['category_source'] === 'manual', 'manual category survives sync');

$before = $db->queryOne('SELECT COUNT(*) AS c FROM discord_scheduled_events WHERE guild_id = ? AND is_deleted = 0', [$guild]);
$invalidFailed = false;
try {
    $events->syncPayload([['id' => 'bad']]);
} catch (\Throwable) {
    $invalidFailed = true;
}
$after = $db->queryOne('SELECT COUNT(*) AS c FROM discord_scheduled_events WHERE guild_id = ? AND is_deleted = 0', [$guild]);
check($invalidFailed && (int) $before['c'] === (int) $after['c'], 'invalid payload preserves existing events');

$events->syncPayload([]);
$list = $events->listForApi(false);
check(count($list['events']) === 0, 'empty successful sync returns no events');
$deleted = $db->queryOne('SELECT COUNT(*) AS c FROM discord_scheduled_events WHERE guild_id = ? AND is_deleted = 1', [$guild]);
check((int) $deleted['c'] === 4, 'empty sync soft-deletes old events');

$events->syncPayload($fixture);
$list = $events->listForApi(false);
check(count($list['events']) === 4, 'reappearing events are restored');

$db->execute('DELETE FROM discord_scheduled_events WHERE guild_id = ?', [$guild]);

if ($GLOBALS['failed']) {
    fwrite(STDERR, "Discord events tests failed.\n");
    exit(1);
}
echo "Discord events tests: all PASS\n";
