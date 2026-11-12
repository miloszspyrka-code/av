<?php
declare(strict_types=1);

namespace AlphaVault;

/**
 * Notowania rynkowe do paska tickera i kalkulatora.
 *
 * Źródło: Yahoo Finance Chart API (bez klucza, tylko odczyt).
 * Cache: plik JSON w katalogu aplikacji; TTL ogranicza liczbę zapytań do dostawcy.
 * Błąd dostawcy nie czyści ostatniego dobrego zestawu — zwraca go ze starą datą.
 */
final class Quotes
{
    /** @var array<string,string> etykieta UI => symbol Yahoo (pasek tickera) */
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

    /** @var array<string,string> instrumenty kalkulatora => symbol Yahoo */
    private const INSTRUMENTS = [
        'BTC/USDT' => 'BTC-USD',
        'ETH/USDT' => 'ETH-USD',
        'SOL/USDT' => 'SOL-USD',
        'XRP/USDT' => 'XRP-USD',
        'BNB/USDT' => 'BNB-USD',
        'DOGE/USDT' => 'DOGE-USD',
        'LINK/USDT' => 'LINK-USD',
        'AVAX/USDT' => 'AVAX-USD',
        'GBP/USD' => 'GBPUSD=X',
        'USD/JPY' => 'JPY=X',
        'AUD/USD' => 'AUDUSD=X',
        'USD/CHF' => 'CHF=X',
        'USD/CAD' => 'CAD=X',
        'NZD/USD' => 'NZDUSD=X',
        'EUR/GBP' => 'EURGBP=X',
        'US30' => '^DJI',
        'GER40' => '^GDAXI',
        'UK100' => '^FTSE',
        'JPN225' => '^N225',
        'XAGUSD' => 'SI=F',
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
     * @param string[] $requested etykiety instrumentów; pusto = zestaw tickera
     * @return array{quotes:list<array{symbol:string,price:float,changePercent:?float}>,updatedAt:?string,stale:bool}
     */
    public function get(array $requested = []): array
    {
        $symbols = $requested === [] ? array_keys(self::SYMBOLS) : $this->resolve($requested);
        if ($symbols === []) {
            return ['quotes' => [], 'updatedAt' => null, 'stale' => true];
        }

        $state = $this->readCache();
        if ($this->isFresh($state, $symbols)) {
            return $this->payload($state, $symbols, false);
        }

        $this->ensureCacheDir();
        $lock = @fopen($this->cacheFile . '.lock', 'c');
        if ($lock === false || !@flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock !== false) {
                fclose($lock);
            }
            return $state !== null
                ? $this->payload($state, $symbols, true)
                : ['quotes' => [], 'updatedAt' => null, 'stale' => true];
        }

        try {
            // Inny proces mógł odświeżyć cache, zanim przejęliśmy blokadę.
            $state = $this->readCache();
            if ($this->isFresh($state, $symbols)) {
                return $this->payload($state, $symbols, false);
            }

            $now = time();
            try {
                $fresh = $this->fetch($symbols);
            } catch (\Throwable $err) {
                error_log('[quotes] ' . $err->getMessage());
                $fresh = [];
            }

            if ($fresh === []) {
                return $state !== null
                    ? $this->payload($state, $symbols, true)
                    : ['quotes' => [], 'updatedAt' => null, 'stale' => true];
            }

            $quotes = is_array($state['quotes'] ?? null) ? $state['quotes'] : [];
            foreach ($fresh as $symbol => $quote) {
                if (is_numeric($quote['price'] ?? null) && (float) $quote['price'] > 0) {
                    $quotes[$symbol] = $quote + ['at' => $now];
                }
            }

            $state = ['fetchedAt' => $now, 'quotes' => $quotes];
            $this->writeCache($state);

            return $this->payload($state, $symbols, false);
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Etykieta z UI („NAS100 · Nasdaq 100”) na klucz notowań („NAS100”). */
    public static function normalize(string $raw): string
    {
        $parts = preg_split('/[\x{2013}\x{2014}\x{00B7}]/u', $raw);
        $value = is_array($parts) && isset($parts[0]) ? $parts[0] : $raw;
        $value = preg_replace('/\s+/u', '', trim($value));

        return strtoupper((string) $value);
    }

    /** @return string[] obsługiwane symbole (ticker + instrumenty) */
    public static function supported(): array
    {
        return array_keys(self::all());
    }

    /** @return array<string,string> */
    private static function all(): array
    {
        return self::SYMBOLS + self::INSTRUMENTS;
    }

    /**
     * @param string[] $requested
     * @return string[]
     */
    private function resolve(array $requested): array
    {
        $known = self::all();
        $out = [];
        foreach ($requested as $label) {
            $symbol = self::normalize((string) $label);
            if ($symbol !== '' && isset($known[$symbol]) && !in_array($symbol, $out, true)) {
                $out[] = $symbol;
            }
            if (count($out) >= 40) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param ?array{fetchedAt:int,quotes:array<string,array{price:float,change:?float,at:int}>} $state
     * @param string[] $symbols
     */
    private function isFresh(?array $state, array $symbols): bool
    {
        if ($state === null || $this->ttl <= 0 || time() - (int) $state['fetchedAt'] >= $this->ttl) {
            return false;
        }
        foreach ($symbols as $symbol) {
            if (!is_numeric($state['quotes'][$symbol]['price'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{fetchedAt:int,quotes:array<string,array{price:float,change:?float,at:int}>} $state
     * @param string[] $symbols
     * @return array{quotes:list<array{symbol:string,price:float,changePercent:?float}>,updatedAt:?string,stale:bool}
     */
    private function payload(array $state, array $symbols, bool $stale): array
    {
        $quotes = [];
        $latest = 0;
        $now = time();
        foreach ($symbols as $symbol) {
            $item = $state['quotes'][$symbol] ?? null;
            if (!is_array($item) || !is_numeric($item['price'] ?? null)) {
                continue;
            }
            if ($now - (int) ($item['at'] ?? 0) > self::MAX_ITEM_AGE) {
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
            'stale' => $stale || count($quotes) < count($symbols),
        ];
    }

    /**
     * @param string[] $symbols
     * @return array<string,array{price:float,change:?float}>
     */
    private function fetch(array $symbols): array
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

        $known = self::all();
        $handles = [];
        foreach ($symbols as $symbol) {
            $yahooSymbol = $known[$symbol] ?? null;
            if ($yahooSymbol === null) {
                continue;
            }
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
