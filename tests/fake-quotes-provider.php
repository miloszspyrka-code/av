<?php
declare(strict_types=1);

/**
 * Lokalny, deterministyczny dostawca notowań do testów E2E.
 * Naśladuje kształt odpowiedzi Yahoo Finance Chart API.
 *
 * Uruchomienie:
 *   php -S 127.0.0.1:4194 tests/fake-quotes-provider.php
 */

$hitsFile = (string) (getenv('QUOTES_HITS_FILE') ?: sys_get_temp_dir() . '/av-quotes-hits.txt');
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($path === '/__hits') {
    out(['hits' => (int) @file_get_contents($hitsFile)]);
}

if (preg_match('#^/v8/finance/chart/(.+)$#', $path, $matches) === 1) {
    $symbol = urldecode($matches[1]);
    $bases = [
        'BTC-USD' => 71411.5,
        'ETH-USD' => 3407.25,
        'SOL-USD' => 164.27,
        '^NDX' => 21426.0,
        '^GSPC' => 6192.0,
        'GC=F' => 2722.4,
        'EURUSD=X' => 1.0775,
        'CL=F' => 71.78,
    ];
    if (!isset($bases[$symbol])) {
        out(['chart' => ['result' => null, 'error' => ['code' => 'Not Found', 'description' => 'No data found']]], 404);
    }
    $handle = @fopen($hitsFile, 'c+');
    if ($handle === false) {
        $hits = 1;
    } else {
        @flock($handle, LOCK_EX);
        $hits = (int) stream_get_contents($handle) + 1;
        @ftruncate($handle, 0);
        @rewind($handle);
        @fwrite($handle, (string) $hits);
        @fflush($handle);
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
    $change = $symbol === 'ETH-USD' ? -0.7 : 1.2;
    out([
        'chart' => [
            'result' => [[
                'meta' => [
                    'symbol' => $symbol,
                    'regularMarketPrice' => $bases[$symbol] + $hits,
                    'regularMarketChangePercent' => $change,
                ],
            ]],
            'error' => null,
        ],
    ]);
}

out(['error' => 'not_found'], 404);
