<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/Quotes.php';

use AlphaVault\Quotes;

function check(bool $condition, string $label): void
{
    echo ($condition ? 'PASS' : 'FAIL') . '  ' . $label . "\n";
    if (!$condition) {
        $GLOBALS['failed'] = true;
    }
}

$GLOBALS['failed'] = false;

$dir = sys_get_temp_dir() . '/av-quotes-' . bin2hex(random_bytes(4));
mkdir($dir, 0775, true);
$cache = $dir . '/quotes.json';
ini_set('error_log', $dir . '/test.log');

$symbols = ['BTC', 'ETH', 'SOL', 'NAS100', 'US500', 'XAUUSD', 'EUR/USD', 'OIL'];

$full = [];
foreach ($symbols as $index => $symbol) {
    $full[$symbol] = ['price' => 100.0 + $index, 'change' => 1.5];
}

// 1. Swiezy zestaw trafia do odpowiedzi i do cache.
$calls = 0;
$quotes = new Quotes($cache, 'https://quotes.test', 60, function () use (&$calls, $full): array {
    $calls++;
    return $full;
});
$result = $quotes->get();
check(count($result['quotes']) === count($symbols), 'fresh: wszystkie symbole');
check($result['stale'] === false, 'fresh: stale=false');
check(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $result['updatedAt']) === 1, 'fresh: updatedAt ISO 8601 UTC');
check($calls === 1 && is_file($cache), 'fresh: jedno pobranie i zapis cache');
check($result['quotes'][0]['symbol'] === 'BTC' && $result['quotes'][0]['price'] === 100.0, 'fresh: kolejnosc i cena');

// 2. W czasie TTL cache wystarcza; fetcher nie jest wolany.
$cached = new Quotes($cache, 'https://quotes.test', 60, function (): array {
    throw new RuntimeException('nie powinno byc wolane');
});
$hit = $cached->get();
check(count($hit['quotes']) === count($symbols) && $hit['stale'] === false, 'ttl: cache bez pobrania');

// 3. Blad dostawcy po TTL zwraca ostatni dobry zestaw ze stale=true.
$stale = new Quotes($cache, 'https://quotes.test', 0, function (): array {
    throw new RuntimeException('dostawca padl');
});
$fallback = $stale->get();
check(count($fallback['quotes']) === count($symbols) && $fallback['stale'] === true, 'stale: ostatni dobry zestaw');
check($fallback['updatedAt'] === $result['updatedAt'], 'stale: zachowana data cache');

// 4. Blad bez cache daje pusty payload bez wyjatku.
$empty = new Quotes($dir . '/missing.json', 'https://quotes.test', 0, function (): array {
    throw new RuntimeException('brak sieci');
});
$nothing = $empty->get();
check($nothing === ['quotes' => [], 'updatedAt' => null, 'stale' => true], 'error: pusty payload bez cache');

// 5. Czastkowe pobranie aktualizuje dostepne symbole, reszta zostaje z cache.
$partialCache = $dir . '/partial.json';
$seed = new Quotes($partialCache, 'https://quotes.test', 60, fn (): array => $full);
$seed->get();
$partial = new Quotes($partialCache, 'https://quotes.test', 0, fn (): array => ['BTC' => ['price' => 555.0, 'change' => -2.0]]);
$mixed = $partial->get();
$prices = [];
foreach ($mixed['quotes'] as $item) {
    $prices[$item['symbol']] = $item['price'];
}
check(count($mixed['quotes']) === count($symbols), 'partial: liczba symboli z cache');
check(($prices['BTC'] ?? null) === 555.0 && ($prices['ETH'] ?? null) === 101.0, 'partial: swieza cena BTC, stara ETH');

// 6. Pojedyncze notowanie starsze niz 900 s znika z odpowiedzi.
$agedCache = $dir . '/aged.json';
$aged = [
    'fetchedAt' => time(),
    'quotes' => [],
];
foreach ($symbols as $index => $symbol) {
    $aged['quotes'][$symbol] = ['price' => 10.0 + $index, 'change' => null, 'at' => time() - ($symbol === 'ETH' ? 1000 : 10)];
}
file_put_contents($agedCache, json_encode($aged));
$agedQuotes = new Quotes($agedCache, 'https://quotes.test', 0, fn (): array => ['BTC' => ['price' => 77.0, 'change' => null]]);
$agedResult = $agedQuotes->get();
$agedSymbols = array_column($agedResult['quotes'], 'symbol');
check(count($agedResult['quotes']) === count($symbols) - 1 && !in_array('ETH', $agedSymbols, true), 'age: przestarzale ETH usuniete');
check($agedResult['stale'] === true, 'age: stale=true przy brakujacym symbolu');

// 7. Uszkodzony cache jest ignorowany bez bledu krytycznego.
$broken = $dir . '/broken.json';
file_put_contents($broken, '{"fetchedAt":');
$brokenQuotes = new Quotes($broken, 'https://quotes.test', 60, fn (): array => ['BTC' => ['price' => 1.0, 'change' => null]]);
$brokenResult = $brokenQuotes->get();
check(count($brokenResult['quotes']) === 1 && $brokenResult['stale'] === true, 'broken: cache zignorowany, dostawca uzyty');

// 8. Filtr instrumentów kalkulatora: normalizacja etykiet UI i tylko zamówione symbole.
check(Quotes::normalize('NAS100 · Nasdaq 100') === 'NAS100', 'normalize: etykieta z separatorem');
check(Quotes::normalize('NAS100 – Nasdaq 100') === 'NAS100', 'normalize: etykieta z myślnikiem');
check(Quotes::normalize(' sol/usdt ') === 'SOL/USDT', 'normalize: spacje i wielkość liter');
check(Quotes::normalize('') === '', 'normalize: pusty string');
$supported = Quotes::supported();
check(in_array('BTC', $supported, true) && in_array('SOL/USDT', $supported, true) && in_array('XAGUSD', $supported, true), 'supported: ticker + instrumenty');

$filterCache = $dir . '/filter.json';
$filtered = new Quotes($filterCache, 'https://quotes.test', 60, fn (): array => array_replace($full, [
    'SOL/USDT' => ['price' => 164.9, 'change' => -2.1],
    'NAS100' => ['price' => 21426.0, 'change' => 0.4],
]));
$only = $filtered->get(['SOL/USDT', 'NAS100 · Nasdaq 100']);
check(array_column($only['quotes'], 'symbol') === ['SOL/USDT', 'NAS100'], 'filter: tylko zamówione instrumenty');
check($only['stale'] === false, 'filter: komplet danych bez stale');
check(($only['quotes'][0]['price'] ?? null) === 164.9, 'filter: cena SOL/USDT z dostawcy');
check(($only['quotes'][1]['price'] ?? null) === 21426.0, 'filter: cena NAS100 po normalizacji etykiety');

$unknown = $filtered->get(['NIEZNANY/PAIR']);
check($unknown === ['quotes' => [], 'updatedAt' => null, 'stale' => true], 'filter: nieznany symbol bez wyjątku');

foreach (glob($dir . '/*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($dir);

exit($GLOBALS['failed'] ? 1 : 0);
