<?php
declare(strict_types=1);

namespace AlphaVault;

/**
 * Notowania rynkowe do paska tickera.
 *
 * Źródło: Yahoo Finance Chart API (bez klucza, tylko odczyt).
 * Cache: plik JSON w katalogu aplikacji; TTL ogranicza liczbę zapytań do dostawcy.
 * Błąd dostawcy nie czyści ostatniego dobrego zestawu — zwraca go ze starą datą.
 */
final class Quotes
{
    /** @var array<string,string> etykieta UI => symbol Yahoo */
    private const SYMBOLS = [
        'BTC' => 'BTC-USD',
        'ETH' => 'ETH-USD',
        'SOL' => 'SOL-USD',
        'NAS100' => '^NDX',
        'US500' => '^GSPC',
        'XAUUSD' => 'GC=F',
        'EUR/USD' => 'EURUSD=X',
        'OIL' => 'CL=F',
    ];

    /** Po tym czasie nieaktualne pojedyncze notowanie znika z odpowiedzi. */
    private const MAX_ITEM_AGE = 900;

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    /**
     * @param ?\Closure():array<string,array{price:float,change:?float}> $fetcher
     */
    public function __construct(
        private readonly string $cacheFile,
        private readonly string $baseUrl,
        private readonly int $ttl,
        private readonly ?\Closure $fetcher = null,
    ) {
    }

    /**
     * @return array{quotes:list<array{symbol:string,price:float,changePercent:?float}>,updatedAt:?string,stale:bool}
     */
    public function get(): array
    {
        $state = $this->readCache();
        $now = time();
        if ($state !== null && $this->ttl > 0 && $now - (int) $state['fetchedAt'] < $this->ttl) {
            return $this->payload($state, false);
        }

        $this->ensureCacheDir();
        $lock = @fopen($this->cacheFile . '.lock', 'c');
        if ($lock === false || !@flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock !== false) {
                fclose($lock);
            }
            return $state !== null
                ? $this->payload($state, true)
                : ['quotes' => [], 'updatedAt' => null, 'stale' => true];
        }

        try {
            // Inny proces mógł odświeżyć cache, zanim przejęliśmy blokadę.
            $state = $this->readCache();
            $now = time();
            if ($state !== null && $this->ttl > 0 && $now - (int) $state['fetchedAt'] < $this->ttl) {
                return $this->payload($state, false);
            }

            try {
                $fresh = $this->fetch();
            } catch (\Throwable $err) {
                error_log('[quotes] ' . $err->getMessage());
                $fresh = [];
            }

            if ($fresh === []) {
                return $state !== null
                    ? $this->payload($state, true)
                    : ['quotes' => [], 'updatedAt' => null, 'stale' => true];
            }

            $quotes = [];
            foreach (array_keys(self::SYMBOLS) as $symbol) {
                if (isset($fresh[$symbol])) {
                    $quotes[$symbol] = $fresh[$symbol] + ['at' => $now];
                    continue;
                }
                $old = $state['quotes'][$symbol] ?? null;
                if (is_array($old) && is_numeric($old['price'] ?? null) && $now - (int) ($old['at'] ?? 0) <= self::MAX_ITEM_AGE) {
                    $quotes[$symbol] = $old;
                }
            }

            $state = ['fetchedAt' => $now, 'quotes' => $quotes];
            $this->writeCache($state);

            return $this->payload($state, count($quotes) < count(self::SYMBOLS));
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param array{fetchedAt:int,quotes:array<string,array{price:float,change:?float,at:int}>} $state
     * @return array{quotes:list<array{symbol:string,price:float,changePercent:?float}>,updatedAt:?string,stale:bool}
     */
    private function payload(array $state, bool $stale): array
    {
        $quotes = [];
        $latest = 0;
        foreach (array_keys(self::SYMBOLS) as $symbol) {
            $item = $state['quotes'][$symbol] ?? null;
            if (!is_array($item) || !is_numeric($item['price'] ?? null)) {
                continue;
            }
            $quotes[] = [
                'symbol' => $symbol,
                'price' => (float) $item['price'],
                'changePercent' => is_numeric($item['change'] ?? null) ? (float) $item['change'] : null,
            ];
            $latest = max($latest, (int) ($item['at'] ?? 0));
        }

        return [
            'quotes' => $quotes,
            'updatedAt' => $latest > 0 ? gmdate('Y-m-d\TH:i:s\Z', $latest) : null,
            'stale' => $stale,
        ];
    }

    /**
     * @return array<string,array{price:float,change:?float}>
     */
    private function fetch(): array
    {
        if ($this->fetcher !== null) {
            return ($this->fetcher)();
        }
        if (!function_exists('curl_multi_init')) {
            throw new \RuntimeException('Brak rozszerzenia curl.');
        }

        $multi = curl_multi_init();
        if ($multi === false) {
            throw new \RuntimeException('Nie można uruchomić klienta HTTP.');
        }

        $handles = [];
        foreach (self::SYMBOLS as $symbol => $yahooSymbol) {
            $url = $this->baseUrl . '/v8/finance/chart/' . rawurlencode($yahooSymbol) . '?interval=1d&range=2d';
            $handle = curl_init($url);
            if ($handle === false) {
                continue;
            }
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPGET => true,
                CURLOPT_HTTPHEADER => [
                    'User-Agent: ' . self::USER_AGENT,
                    'Accept: application/json',
                ],
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            curl_multi_add_handle($multi, $handle);
            $handles[$symbol] = $handle;
        }

        $deadline = microtime(true) + 12.0;
        do {
            $status = curl_multi_exec($multi, $active);
            if ($active > 0) {
                curl_multi_select($multi, 0.5);
            }
        } while ($active > 0 && $status === CURLM_OK && microtime(true) < $deadline);

        $out = [];
        foreach ($handles as $symbol => $handle) {
            $body = curl_multi_getcontent($handle);
            $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
            if ($code !== 200 || !is_string($body) || $body === '') {
                continue;
            }
            $quote = self::parse($body);
            if ($quote !== null) {
                $out[$symbol] = $quote;
            }
        }
        curl_multi_close($multi);

        if ($out === []) {
            throw new \RuntimeException('Dostawca notowań nie zwrócił danych.');
        }

        return $out;
    }

    /**
     * @return ?array{price:float,change:?float}
     */
    private static function parse(string $body): ?array
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }
        $result = $data['chart']['result'][0] ?? null;
        if (!is_array($result)) {
            return null;
        }
        $meta = is_array($result['meta'] ?? null) ? $result['meta'] : [];
        $closes = [];
        foreach (($result['indicators']['quote'][0]['close'] ?? []) as $close) {
            if (is_numeric($close)) {
                $closes[] = (float) $close;
            }
        }

        $price = is_numeric($meta['regularMarketPrice'] ?? null)
            ? (float) $meta['regularMarketPrice']
            : (count($closes) > 0 ? $closes[count($closes) - 1] : null);
        if ($price === null || $price <= 0) {
            return null;
        }

        if (is_numeric($meta['regularMarketChangePercent'] ?? null)) {
            $change = (float) $meta['regularMarketChangePercent'];
        } elseif (count($closes) >= 2) {
            $previous = $closes[count($closes) - 2];
            $change = $previous > 0 ? (($closes[count($closes) - 1] - $previous) / $previous) * 100 : null;
        } else {
            $change = null;
        }

        return [
            'price' => round($price, 6),
            'change' => $change === null ? null : round($change, 3),
        ];
    }

    private function ensureCacheDir(): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    /**
     * @return ?array{fetchedAt:int,quotes:array<string,array{price:float,change:?float,at:int}>}
     */
    private function readCache(): ?array
    {
        if (!is_file($this->cacheFile)) {
            return null;
        }
        $raw = @file_get_contents($this->cacheFile);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !is_numeric($data['fetchedAt'] ?? null) || !is_array($data['quotes'] ?? null)) {
            return null;
        }
        return ['fetchedAt' => (int) $data['fetchedAt'], 'quotes' => $data['quotes']];
    }

    /**
     * @param array{fetchedAt:int,quotes:array<string,array{price:float,change:?float,at:int}>} $state
     */
    private function writeCache(array $state): void
    {
        $this->ensureCacheDir();
        $tmp = $this->cacheFile . '.' . getmypid() . '.tmp';
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        if (@file_put_contents($tmp, $json) === false) {
            return;
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, $this->cacheFile)) {
            @unlink($tmp);
        }
    }
}
