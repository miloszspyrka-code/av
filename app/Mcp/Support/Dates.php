<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Support;

final class Dates
{
    public const TZ = 'Europe/Warsaw';

    public static function tz(): \DateTimeZone
    {
        return new \DateTimeZone(self::TZ);
    }

    public static function nowUtc(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public static function todayKey(): string
    {
        return (new \DateTimeImmutable('now', self::tz()))->format('Y-m-d');
    }

    public static function dateKey(?string $iso): ?string
    {
        if ($iso === null || trim($iso) === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($iso))->setTimezone(self::tz())->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    public static function isValidKey(string $key): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $key) !== 1) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $key, self::tz());

        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $key;
    }

    public static function startOfLocalDay(string $key): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($key . ' 00:00:00', self::tz()))->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function localParts(?string $iso): array
    {
        if ($iso === null || trim($iso) === '') {
            return ['date' => null, 'time' => null, 'offset' => null, 'weekday' => null];
        }
        try {
            $local = (new \DateTimeImmutable($iso))->setTimezone(self::tz());
        } catch (\Throwable) {
            return ['date' => null, 'time' => null, 'offset' => null, 'weekday' => null];
        }

        return [
            'date' => $local->format('Y-m-d'),
            'time' => $local->format('H:i'),
            'offset' => $local->format('P'),
            'weekday' => $local->format('N'),
        ];
    }

    public static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
