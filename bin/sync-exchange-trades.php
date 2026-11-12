#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$force = in_array('--force', $argv ?? [], true);

try {
    [$config, $db] = require $root . '/app/bootstrap.php';
    if (!$config->integrationsConfigured) {
        fwrite(STDOUT, "AlphaVault exchange sync: skipped (INTEGRATIONS_KEY not configured)\n");
        exit(0);
    }
    $bundle = \AlphaVault\Integrations\Integrations::make($config, $db);
    if ($bundle === null) {
        fwrite(STDOUT, "AlphaVault exchange sync: skipped (integrations unavailable)\n");
        exit(0);
    }
    $result = $bundle['sync']->syncDue($force);
    if (!empty($result['skipped'])) {
        fwrite(STDOUT, "AlphaVault exchange sync: skipped (" . ($result['reason'] ?? 'not due') . ")\n");
        exit(0);
    }
    fwrite(STDOUT, sprintf(
        "AlphaVault exchange sync: %s connections=%d errors=%d imported=%d\n",
        !empty($result['ok']) ? 'success' : 'partial',
        (int) ($result['connections'] ?? 0),
        (int) ($result['errors'] ?? 0),
        (int) ($result['imported'] ?? 0)
    ));
    exit(0);
} catch (\Throwable $err) {
    fwrite(STDERR, 'AlphaVault exchange sync: error ' . $err->getMessage() . "\n");
    exit(1);
}
