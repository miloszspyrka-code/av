<?php
declare(strict_types=1);

namespace AlphaVault\Integrations;

/**
 * Normalizacja danych z giełd do formatu dziennika Alpha Vault.
 * Wszystkie czasy są przechowywane jako UTC ISO 8601.
 */
final class Normalizer
{
    private const QUOTES = ['USDT', 'USDC', 'BUSD', 'FDUSD', 'USD', 'EUR', 'GBP', 'JPY', 'PLN', 'BTC', 'ETH'];

    public static function toMs(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            $n = (float) $value;
            if ($n <= 0) {
                return null;
            }
            return $n > 1000000000000 ? (int) $n : (int) round($n * 1000);
        }
        $ts = strtotime((string) $value);
        return $ts === false ? null : $ts * 1000;
    }

    public static function iso(?int $ms): ?string
    {
        if ($ms === null || $ms <= 0) {
            return null;
        }
        $seconds = intdiv($ms, 1000);
        $millis = $ms % 1000;
        return gmdate('Y-m-d\TH:i:s', $seconds) . sprintf('.%03dZ', $millis);
    }

    public static function num(mixed $value): ?float
    {
        if ($value === null || $value === '' || is_bool($value)) {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        $clean = str_replace([' ', "\u{00a0}", ','], ['', '', '.'], (string) $value);
        return is_numeric($clean) ? (float) $clean : null;
    }

    public static function pair(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return '';
        }
        $symbol = preg_replace('/\.(?:r|raw|pro|ecn|cash|spot|m)$/i', '', $symbol) ?? $symbol;
        $symbol = str_replace(['_', '-', '/'], '-', $symbol);
        $parts = array_values(array_filter(explode('-', $symbol), static fn (string $p): bool => $p !== ''));
        if (count($parts) >= 2) {
            $base = $parts[0];
            $quote = $parts[1];
            if ($quote === 'SWAP' || $quote === 'PERP') {
                return $base;
            }
            return $base . '/' . $quote;
        }
        $flat = $parts[0] ?? $symbol;
        foreach (self::QUOTES as $quote) {
            if (str_ends_with($flat, $quote) && strlen($flat) > strlen($quote)) {
                return substr($flat, 0, -strlen($quote)) . '/' . $quote;
            }
        }
        return $flat;
    }

    public static function price(?float $value): string
    {
        return self::decimal($value, 8);
    }

    public static function size(?float $value): string
    {
        return self::decimal($value, 8);
    }

    public static function money(?float $value): string
    {
        return self::decimal($value, 8);
    }

    private static function decimal(?float $value, int $maxDecimals): string
    {
        if ($value === null || !is_finite($value)) {
            return '';
        }
        $formatted = number_format($value, $maxDecimals, ',', '');
        $formatted = rtrim(rtrim($formatted, '0'), ',');
        if ($formatted === '' || $formatted === '-') {
            $formatted = '0';
        }
        return $formatted;
    }

    /** @param array<string,mixed> $trade */
    public static function direction(?string $side, ?string $positionSide = null): string
    {
        $positionSide = strtolower((string) $positionSide);
        if ($positionSide === 'long') {
            return 'l';
        }
        if ($positionSide === 'short') {
            return 's';
        }
        $side = strtolower((string) $side);
        return $side === 'sell' || $side === 'short' || $side === 's' ? 's' : 'l';
    }

    public static function mask(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (strlen($value) <= 8) {
            return substr($value, 0, 2) . '••••' . substr($value, -2);
        }
        return substr($value, 0, 4) . '••••' . substr($value, -4);
    }
}
