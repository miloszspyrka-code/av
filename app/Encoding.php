<?php
declare(strict_types=1);

namespace AlphaVault;

/**
 * Naprawa historycznego mojibake: UTF-8 odczytany jako Windows-1250.
 *
 * W bazie i starych stanach zdarzają się ciągi z polskimi znakami podwójnie
 * zakodowanymi przez UTF-8 → Windows-1250 → UTF-8. Odtwarzamy bajty UTF-8
 * z powrotem: każdy znak ciągu mapujemy na bajt CP1250 i sprawdzamy, czy
 * powstała sekwencja jest poprawnym UTF-8. Poprawny polski tekst i ASCII
 * pozostają bez zmian.
 */
final class Encoding
{
    /** Znaki typowe dla UTF-8 odczytanego jako Windows-1250. */
    private const LEAD = '/[\x{0139}\x{0102}\x{00C4}\x{00C2}\x{00C5}\x{00E2}\x{00C3}]/u';

    public static function repairMojibake(string $text): string
    {
        if ($text === '' || preg_match('/[^\x00-\x7F]/', $text) !== 1) {
            return $text;
        }

        $repaired = preg_replace_callback('/[^\x00-\x7F]+/u', static function (array $match): string {
            $run = $match[0];
            if (preg_match(self::LEAD, $run) !== 1) {
                return $run;
            }

            return self::decodeRun($run) ?? $run;
        }, $text);

        return is_string($repaired) ? $repaired : $text;
    }

    /**
     * Naprawia wszystkie stringi (także klucze) w stanie JSON.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function repairValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::repairMojibake($value);
        }
        if (!is_array($value)) {
            return $value;
        }

        $repaired = [];
        foreach ($value as $key => $item) {
            $newKey = is_string($key) ? self::repairMojibake($key) : $key;
            $repaired[$newKey] = self::repairValue($item);
        }

        return $repaired;
    }

    /**
     * Zamienia ciąg znaków CP1250 na oryginalne bajty UTF-8.
     * Zwraca null, gdy któryś znak nie istnieje w CP1250 albo bajty nie są UTF-8.
     */
    private static function decodeRun(string $run): ?string
    {
        $chars = preg_split('//u', $run, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($chars) || $chars === []) {
            return null;
        }

        $bytes = '';
        foreach ($chars as $char) {
            $byte = self::charToCp1250($char);
            if ($byte === null) {
                return null;
            }
            $bytes .= chr($byte);
        }

        return preg_match('//u', $bytes) === 1 ? $bytes : null;
    }

    private static function charToCp1250(string $char): ?int
    {
        if (function_exists('iconv')) {
            $byte = @iconv('UTF-8', 'Windows-1250', $char);
            if (!is_string($byte) || strlen($byte) !== 1) {
                return null;
            }

            return ord($byte);
        }

        if (function_exists('mb_convert_encoding')) {
            $byte = @mb_convert_encoding($char, 'Windows-1250', 'UTF-8');
            if (!is_string($byte) || strlen($byte) !== 1 || ($byte === '?' && $char !== '?')) {
                return null;
            }

            return ord($byte);
        }

        return null;
    }
}
