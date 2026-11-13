<?php
declare(strict_types=1);

namespace AlphaVault;

/**
 * Przesuwa daty fixture demo (`db/demo-state.json`) na bieżący dzień.
 *
 * Fixture ma stałe daty. Bez przesunięcia demo po kilku dniach pokazuje
 * wyłącznie historię: brak dzisiejszych transakcji, pusty plan dnia i brak
 * trybu ochronnego. Przesunięcie zachowuje względne odstępy między wpisami.
 *
 * Punkt zaczepienia to najnowsza transakcja (albo najnowszy plan), więc
 * operacja jest idempotentna: po pierwszym przebiegu delta wynosi 0.
 */
final class DemoState
{
    public const TIMEZONE = 'Europe/Warsaw';

    private const DATE_PATTERN = '/\d{4}-\d{2}-\d{2}/';

    /** Dzisiejsza data w strefie prezentacji. */
    public static function today(?string $timezone = null): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone($timezone ?: self::TIMEZONE)))->format('Y-m-d');
    }

    /**
     * Zwraca przesunięty JSON albo null, gdy nie ma czego zmieniać
     * (delta 0, brak dat, niepoprawny JSON).
     */
    public static function shift(string $json, string $today): ?string
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        $anchor = self::anchor($data);
        if ($anchor === null) {
            return null;
        }
        $delta = self::daysBetween($anchor, $today);
        if ($delta === 0) {
            return null;
        }
        $shifted = preg_replace_callback(self::DATE_PATTERN, static function (array $match) use ($delta): string {
            $timestamp = strtotime($match[0] . ' UTC');
            if ($timestamp === false) {
                return $match[0];
            }
            return gmdate('Y-m-d', $timestamp + $delta * 86400);
        }, $json);

        return is_string($shifted) ? $shifted : null;
    }

    /** Przesunięcie na dziś w `Europe/Warsaw`. */
    public static function shiftToToday(string $json): ?string
    {
        return self::shift($json, self::today());
    }

    /** @param array<string, mixed> $data */
    private static function anchor(array $data): ?string
    {
        $best = null;
        foreach ((array) ($data['trades'] ?? []) as $trade) {
            $date = is_array($trade) ? substr((string) ($trade['date'] ?? ''), 0, 10) : '';
            if (self::isDate($date) && ($best === null || $date > $best)) {
                $best = $date;
            }
        }
        if ($best !== null) {
            return $best;
        }
        foreach (array_keys((array) ($data['plans'] ?? [])) as $key) {
            $date = substr((string) $key, 0, 10);
            if (self::isDate($date) && ($best === null || $date > $best)) {
                $best = $date;
            }
        }

        return $best;
    }

    private static function isDate(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year);
    }

    private static function daysBetween(string $from, string $to): int
    {
        $utc = new \DateTimeZone('UTC');
        $start = new \DateTimeImmutable($from . ' 00:00:00', $utc);
        $end = new \DateTimeImmutable($to . ' 00:00:00', $utc);

        return (int) $start->diff($end)->format('%r%a');
    }
}
