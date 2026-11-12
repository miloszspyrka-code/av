<?php
declare(strict_types=1);

$root = dirname(__DIR__);
[$config, $db] = require $root . '/app/bootstrap.php';
require_once $root . '/app/DiscordEvents.php';

use AlphaVault\DiscordEvents;

$guild = 'test-guild';
$db->execute('DELETE FROM discord_scheduled_events WHERE guild_id = ?', [$guild]);

$utc = new DateTimeZone('UTC');
$now = new DateTimeImmutable('now', $utc);
$liveStart = $now->modify('+2 hours');
$liveEnd = $liveStart->modify('+1 hour');
$consultStart = $now->modify('+2 days')->setTime(17, 0);
$consultEnd = $consultStart->modify('+1 hour');
$externalStart = $now->modify('+3 days')->setTime(18, 0);

$fixture = [
    [
        'id' => '9001', 'guild_id' => $guild, 'name' => 'AT - Marcin - AKADEMIA',
        'description' => 'Analiza rynku', 'scheduled_start_time' => $liveStart->format(DATE_ATOM),
        'scheduled_end_time' => $liveEnd->format(DATE_ATOM), 'status' => 1, 'entity_type' => 2,
        'channel_id' => '111', 'creator' => ['id' => '9', 'username' => 'bot', 'bot' => true],
        'user_count' => 8, 'entity_metadata' => null,
    ],
    [
        'id' => '9002', 'guild_id' => $guild, 'name' => 'Konsultacja 1:1 - Marcin',
        'description' => 'Konsultacja indywidualna', 'scheduled_start_time' => $consultStart->format(DATE_ATOM),
        'scheduled_end_time' => $consultEnd->format(DATE_ATOM), 'status' => 1, 'entity_type' => 2,
        'channel_id' => '112', 'creator' => ['id' => '9', 'username' => 'bot', 'bot' => true],
        'user_count' => 3, 'entity_metadata' => null,
    ],
    [
        'id' => '9003', 'guild_id' => $guild, 'name' => 'Wydarzenie zewnętrzne - Hubert',
        'description' => 'Spotkanie', 'scheduled_start_time' => $externalStart->format(DATE_ATOM),
        'status' => 1, 'entity_type' => 3, 'channel_id' => null,
        'creator' => ['id' => '9', 'username' => 'bot', 'bot' => true], 'user_count' => 3,
        'entity_metadata' => ['location' => 'Discord'],
    ],
];

$sync = new DiscordEvents($config, $db);
$result = $sync->syncPayload($fixture);
echo 'seeded=' . $result['events_count'] . "\n";
