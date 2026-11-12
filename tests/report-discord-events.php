<?php
declare(strict_types=1);

$root = dirname(__DIR__);
[$config, $db] = require $root . '/app/bootstrap.php';
require_once $root . '/app/DiscordEvents.php';

use AlphaVault\DiscordEvents;

$guild = $config->discord['guildId'];
$rows = $db->query(
    'SELECT discord_event_id, name, event_type, status, scheduled_start_iso, user_count, category, host_name
       FROM discord_scheduled_events WHERE guild_id = ? ORDER BY scheduled_start_time ASC',
    [$guild]
);
$duplicates = $db->queryOne(
    'SELECT COUNT(*) AS total, COUNT(DISTINCT discord_event_id) AS unique_ids FROM discord_scheduled_events WHERE guild_id = ?',
    [$guild]
);

echo 'LIVE_SYNC_GUILD=' . $guild . "\n";
echo 'LIVE_SYNC_EVENTS=' . count($rows) . "\n";
echo 'LIVE_SYNC_UNIQUE=' . (int) ($duplicates['unique_ids'] ?? 0) . "\n";
foreach ($rows as $row) {
    $parts = DiscordEvents::localParts((string) $row['scheduled_start_iso']);
    $types = [1 => 'STAGE_INSTANCE', 2 => 'VOICE', 3 => 'EXTERNAL'];
    $statuses = [1 => 'SCHEDULED', 2 => 'ACTIVE', 3 => 'COMPLETED', 4 => 'CANCELED'];
    echo 'EVENT=' . json_encode([
        'id' => $row['discord_event_id'],
        'name' => $row['name'],
        'type' => $types[(int) $row['event_type']] ?? 'UNKNOWN',
        'status' => $statuses[(int) $row['status']] ?? 'UNKNOWN',
        'startUtc' => $row['scheduled_start_iso'],
        'startLocal' => $parts['label'],
        'userCount' => $row['user_count'] !== null ? (int) $row['user_count'] : null,
        'category' => $row['category'],
        'host' => $row['host_name'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

$state = (new DiscordEvents($config, $db))->syncState();
echo 'SYNC_STATE=' . json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

if ((int) ($duplicates['total'] ?? 0) !== (int) ($duplicates['unique_ids'] ?? 0)) {
    fwrite(STDERR, "Duplicate Discord event IDs detected.\n");
    exit(1);
}
