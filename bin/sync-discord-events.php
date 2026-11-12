#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);

$exchangeLine = null;
$discordError = null;
$discordLine = null;

try {
    [$config, $db] = require $root . '/app/bootstrap.php';
    require_once $root . '/app/DiscordApiException.php';
    require_once $root . '/app/DiscordEvents.php';

    if ($config->integrationsConfigured) {
        try {
            $bundle = \AlphaVault\Integrations\Integrations::make($config, $db);
            if ($bundle !== null) {
                $result = $bundle['sync']->syncDue();
                if (empty($result['skipped'])) {
                    $exchangeLine = sprintf(
                        'AlphaVault exchange sync: %s connections=%d errors=%d imported=%d',
                        !empty($result['ok']) ? 'success' : 'partial',
                        (int) ($result['connections'] ?? 0),
                        (int) ($result['errors'] ?? 0),
                        (int) ($result['imported'] ?? 0)
                    );
                }
            }
        } catch (\Throwable $err) {
            $exchangeLine = 'AlphaVault exchange sync: error ' . $err->getMessage();
        }
    }

    try {
        $sync = new \AlphaVault\DiscordEvents($config, $db);
        $result = $sync->sync();
        if (!empty($result['skipped'])) {
            $discordLine = 'AlphaVault Discord sync: skipped (lock held)';
        } else {
            $discordLine = sprintf(
                'AlphaVault Discord sync: success events=%d updated=%d duration=%dms',
                (int) ($result['events_count'] ?? 0),
                (int) ($result['updated_count'] ?? 0),
                (int) ($result['duration_ms'] ?? 0)
            );
        }
    } catch (\Throwable $err) {
        $discordError = $err;
    }
} catch (\Throwable $err) {
    fwrite(STDERR, 'AlphaVault cron: error ' . $err->getMessage() . "\n");
    exit(1);
}

if ($exchangeLine !== null) {
    fwrite(str_starts_with($exchangeLine, 'AlphaVault exchange sync: error') ? STDERR : STDOUT, $exchangeLine . "\n");
}
if ($discordError !== null) {
    fwrite(STDERR, 'AlphaVault Discord sync: error ' . $discordError->getMessage() . "\n");
    exit(1);
}
if ($discordLine !== null) {
    fwrite(STDOUT, $discordLine . "\n");
}
exit(0);
