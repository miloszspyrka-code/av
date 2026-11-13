<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use AlphaVault\Mcp\Support\Dates;
use AlphaVault\Mcp\Support\PropChallengeService;

$fixtureFile = __DIR__ . '/fixtures/prop-parity.json';
$fixture = json_decode((string) file_get_contents($fixtureFile), true);
if (!is_array($fixture)) {
    fwrite(STDERR, "Brak fixtures prop-parity.json\n");
    exit(1);
}

$now = new DateTimeImmutable((string) $fixture['now']);
$accountNames = array_values(array_map('strval', (array) ($fixture['accountNames'] ?? [])));
$engine = new PropChallengeService();
$out = [];

foreach ((array) ($fixture['cases'] ?? []) as $case) {
    $metrics = $engine->compute(
        (array) ($case['challenge'] ?? []),
        (array) ($case['trades'] ?? []),
        $now,
        $accountNames
    );
    $out[] = [
        'name' => (string) ($case['name'] ?? ''),
        'metrics' => $metrics,
    ];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
