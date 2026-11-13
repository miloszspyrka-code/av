<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Support;

final class Numbers
{
    public static function pnum(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : 0.0;
        }
        $text = (string) ($value ?? '');
        $text = str_replace(["\u{00a0}", ' '], '', $text);
        $text = preg_replace('/[$€£%x×]/iu', '', $text) ?? $text;
        $text = preg_replace('/,(?=\d)/', '.', $text) ?? $text;
        $text = str_replace(',', '', $text);
        $number = (float) $text;

        return is_finite($number) ? $number : 0.0;
    }

    public static function round(float $value, int $decimals = 2): float
    {
        return round($value, $decimals, PHP_ROUND_HALF_UP);
    }

    public static function fxToUsdt(string $currency): float
    {
        return match (strtoupper($currency)) {
            'EUR' => 1.08,
            'PLN' => 0.25,
            default => 1.0,
        };
    }
}
