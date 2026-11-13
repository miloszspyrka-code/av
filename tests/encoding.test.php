<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Encoding.php';

use AlphaVault\Encoding;

$failed = 0;

function check(bool $ok, string $label, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . '  ' . $label . ($detail !== '' ? ' - ' . $detail : '') . "\n";
    if (!$ok) {
        $failed++;
    }
}

/** Tworzy mojibake: bajty UTF-8 odczytane jako Windows-1250. */
function mojibake(string $text): string
{
    if (!function_exists('iconv')) {
        fwrite(STDERR, "Brak rozszerzenia iconv.\n");
        exit(1);
    }
    $broken = @iconv('CP1250', 'UTF-8', $text);
    if (!is_string($broken)) {
        fwrite(STDERR, "Nie można zbudować fixture mojibake.\n");
        exit(1);
    }

    return $broken;
}

$samples = [
    'Cześć {imie}. Widzę dziś 0 straty i gotowość 66/100, więc jestem w pogotowiu.',
    'Zażółć gęślą jaźń.',
    'Konto główne',
    'Miłosz Spyrka',
];

foreach ($samples as $correct) {
    $broken = mojibake($correct);
    check($broken !== $correct, 'fixture differs: ' . $correct);
    check(Encoding::repairMojibake($broken) === $correct, 'repair: ' . $correct, $broken);
    check(Encoding::repairMojibake($correct) === $correct, 'idempotent: ' . $correct);
}

check(Encoding::repairMojibake('plain ASCII') === 'plain ASCII', 'ASCII unchanged');
$lead = "\u{00C4}";
check(Encoding::repairMojibake($lead) === $lead, 'single lead character unchanged');

$state = [
    'chat' => [['role' => 'a', 'html' => mojibake('Cześć {imie}')]],
    'user' => ['name' => 'Miłosz Spyrka'],
    'screens' => ['x' => base64_encode(random_bytes(32))],
];
$repaired = Encoding::repairValue($state);
check($repaired['chat'][0]['html'] === 'Cześć {imie}', 'nested string repaired');
check($repaired['user']['name'] === 'Miłosz Spyrka', 'correct name unchanged');
check($repaired['screens'] === $state['screens'], 'base64 unchanged');

if ($failed > 0) {
    fwrite(STDERR, "\n{$failed} test(s) failed.\n");
    exit(1);
}

echo "\nAll PHP encoding tests passed.\n";
