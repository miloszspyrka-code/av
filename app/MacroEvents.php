<?php
declare(strict_types=1);

namespace AlphaVault;

/**
 * Kalendarz makro: publiczne feedy -> MySQL -> /api/macro-events -> UI.
 *
 * Bieżący tydzień: oficjalny JSON ForexFactory (impact High/Medium).
 * Następny tydzień: publiczne events API TradingView (importance 1/0),
 * bo ForexFactory publikuje JSON tylko dla bieżącego tygodnia.
 *
 * Zapisujemy wyłącznie publikacje ★★ i ★★★. Sukces synchronizacji
 * z pustą listą czyści okno; błąd zachowuje ostatni dobry zestaw, bo
 * soft delete wykonuje się tylko po udanym pobraniu danego feedu.
 */
final class MacroEvents
{
    private const FF_THIS_WEEK_URL = 'https://nfs.faireconomy.media/ff_calendar_thisweek.json';
    private const TV_EVENTS_URL = 'https://economic-calendar.tradingview.com/events';
    private const IMPACT_MAP = ['high' => 3, 'medium' => 2];
    private const TV_IMPORTANCE_MAP = [1 => 3, 0 => 2];
    private const TV_COUNTRIES = ['US', 'EU', 'GB', 'JP', 'CN', 'CA', 'AU', 'CH', 'NZ'];
    private const TV_CURRENCY_MAP = [
        'US' => 'USD', 'EU' => 'EUR', 'GB' => 'GBP', 'JP' => 'JPY', 'CN' => 'CNY',
        'CA' => 'CAD', 'AU' => 'AUD', 'CH' => 'CHF', 'NZ' => 'NZD',
    ];
    private const TV_NOISE = [
        'auction', 'bill', 'bond', 'note', 'speaks', 'holiday', 'reopening',
        'settlement', 'redemption', 'allotted', 'bid-to-cover', 'total accepted',
    ];
    private const FETCH_WINDOW_DAYS_BACK = 8;
    private const FETCH_WINDOW_DAYS_FORWARD = 21;
    private const STALE_AFTER_SECONDS = 1800;
    private const LOCK_NAME = 'alphavault_macro_sync';

    /** @param list<string> $feedUrls */
    public function __construct(
        private readonly Config $config,
        private readonly Db $db,
        private readonly array $feedUrls = [],
    ) {
    }

    public function sync(): array
    {
        if (!$this->acquireLock()) {
            $this->log('sync skipped: another run holds the lock');
            return ['skipped' => true];
        }

        $started = microtime(true);
        try {
            $this->db->execute(
                'UPDATE macro_sync_state
                    SET last_attempt_at = UTC_TIMESTAMP(), last_status = ?, last_error = NULL,
                        updated_at = UTC_TIMESTAMP()
                  WHERE id = 1',
                ['running']
            );
        } catch (\Throwable $err) {
            $this->log('cannot persist sync start: ' . $err->getMessage());
        }

        try {
            $ffRows = $this->fetchForexFactory();
            $ffNormalized = self::normalizeForexFactory($ffRows);
            if ($ffRows !== [] && $ffNormalized === []) {
                throw new \RuntimeException('Feed ForexFactory nie zawiera rozpoznanych publikacji.');
            }

            $tvRows = null;
            $tvNormalized = [];
            try {
                $tvRows = $this->fetchTradingViewNextWeek();
                $tvNormalized = self::normalizeTradingView($tvRows);
            } catch (\Throwable $err) {
                $this->log('TradingView next week unavailable: ' . $err->getMessage());
            }

            $result = $this->syncPayload(
                $ffNormalized,
                $started,
                'forexfactory',
                true,
                self::currentWeekWindowUtc()[0],
                self::currentWeekWindowUtc()[1]
            );
            if ($tvRows !== null && $tvNormalized !== []) {
                [$tvFrom, $tvTo] = self::nextWeekWindowUtc();
                $tvResult = $this->syncPayload($tvNormalized, $started, 'tradingview', false, $tvFrom, $tvTo);
                $result['events_count'] += $tvResult['events_count'];
                $result['updated_count'] += $tvResult['updated_count'];
            }

            $status = $tvRows === null ? 'partial' : 'success';
            $this->db->execute(
                'UPDATE macro_sync_state
                    SET last_success_at = UTC_TIMESTAMP(), last_status = ?, last_error = NULL,
                        events_count = ?, updated_count = ?, duration_ms = ?, updated_at = UTC_TIMESTAMP()
                  WHERE id = 1',
                [$status, $result['events_count'], $result['updated_count'], $result['duration_ms']]
            );
            $this->log(sprintf(
                'sync %s: events=%d updated=%d duration=%dms',
                $status,
                $result['events_count'],
                $result['updated_count'],
                $result['duration_ms']
            ));
            return $result;
        } catch (\Throwable $err) {
            $message = substr($err->getMessage(), 0, 500);
            try {
                $this->db->execute(
                    'UPDATE macro_sync_state
                        SET last_status = ?, last_error = ?, duration_ms = ?, updated_at = UTC_TIMESTAMP()
                      WHERE id = 1',
                    ['error', $message, (int) round((microtime(true) - $started) * 1000)]
                );
            } catch (\Throwable $dbErr) {
                $this->log('cannot persist sync error: ' . $dbErr->getMessage());
            }
            $this->log('sync error: ' . $message);
            throw $err;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * @param list<array<string, mixed>> $rows znormalizowane rekordy
     */
    public function syncPayload(
        array $rows,
        ?float $started = null,
        string $source = 'forexfactory',
        bool $replaceAllSources = true,
        ?string $windowStart = null,
        ?string $windowEnd = null,
    ): array {
        $started ??= microtime(true);
        if ($rows === []) {
            return ['events_count' => 0, 'updated_count' => 0, 'duration_ms' => (int) round((microtime(true) - $started) * 1000)];
        }

        $ids = array_map(static fn (array $event): string => $event['external_id'], $rows);
        $starts = array_map(static fn (array $event): string => $event['starts_at_utc'], $rows);
        $windowStart ??= min($starts);
        if ($windowEnd === null) {
            $max = new \DateTimeImmutable(max($starts), new \DateTimeZone('UTC'));
            $windowEnd = $max->modify('+1 second')->format('Y-m-d H:i:s');
        }

        $this->db->withTransaction(function (Db $db) use ($rows, $ids, $windowStart, $windowEnd, $source, $replaceAllSources): void {
            foreach ($rows as $event) {
                $db->execute(
                    'INSERT INTO macro_events
                        (external_id, source, name, description, currency, impact, starts_at_utc, starts_at_iso,
                         forecast, previous, first_seen_at, last_seen_at, updated_at, is_deleted, deleted_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP(), 0, NULL)
                     ON DUPLICATE KEY UPDATE
                        source = VALUES(source),
                        name = VALUES(name),
                        description = VALUES(description),
                        currency = VALUES(currency),
                        impact = VALUES(impact),
                        starts_at_utc = VALUES(starts_at_utc),
                        starts_at_iso = VALUES(starts_at_iso),
                        forecast = VALUES(forecast),
                        previous = VALUES(previous),
                        last_seen_at = UTC_TIMESTAMP(),
                        updated_at = UTC_TIMESTAMP(),
                        is_deleted = 0,
                        deleted_at = NULL',
                    [
                        $event['external_id'],
                        $source,
                        $event['name'],
                        $event['description'],
                        $event['currency'],
                        $event['impact'],
                        $event['starts_at_utc'],
                        $event['starts_at_iso'],
                        $event['forecast'],
                        $event['previous'],
                    ]
                );
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sourceClause = $replaceAllSources ? '' : ' AND source = ?';
            $params = [$windowStart, $windowEnd, ...$ids];
            if (!$replaceAllSources) {
                $params[] = $source;
            }
            $db->execute(
                'UPDATE macro_events
                    SET is_deleted = 1, deleted_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
                  WHERE is_deleted = 0
                    AND starts_at_utc >= ? AND starts_at_utc < ?
                    AND external_id NOT IN (' . $placeholders . ')' . $sourceClause,
                $params
            );
        });

        return [
            'events_count' => count($rows),
            'updated_count' => count($rows),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function normalize(array $rows): array
    {
        return self::normalizeForexFactory($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function normalizeForexFactory(array $rows): array
    {
        $byId = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['title'] ?? ''));
            $currency = strtoupper(trim((string) ($row['country'] ?? '')));
            $dateIso = trim((string) ($row['date'] ?? ''));
            $impactKey = strtolower(trim((string) ($row['impact'] ?? '')));
            if ($name === '' || $dateIso === '' || !isset(self::IMPACT_MAP[$impactKey])) {
                continue;
            }
            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                continue;
            }

            try {
                $date = new \DateTimeImmutable($dateIso);
            } catch (\Throwable) {
                continue;
            }
            $startsAtUtc = $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

            $forecast = trim((string) ($row['forecast'] ?? ''));
            $previous = trim((string) ($row['previous'] ?? ''));
            $byId[sha1(strtolower('forexfactory|' . $currency . '|' . $name . '|' . $dateIso))] = [
                'external_id' => sha1(strtolower('forexfactory|' . $currency . '|' . $name . '|' . $dateIso)),
                'name' => $name,
                'description' => self::description($forecast, $previous),
                'currency' => $currency,
                'impact' => self::IMPACT_MAP[$impactKey],
                'starts_at_utc' => $startsAtUtc,
                'starts_at_iso' => $dateIso,
                'forecast' => $forecast,
                'previous' => $previous,
            ];
        }

        return self::sorted(array_values($byId));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function normalizeTradingView(array $rows): array
    {
        $byId = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['title'] ?? ''));
            $country = strtoupper(trim((string) ($row['country'] ?? '')));
            $dateIso = trim((string) ($row['date'] ?? ''));
            $importance = (int) ($row['importance'] ?? -1);
            if ($name === '' || $dateIso === '' || !isset(self::TV_IMPORTANCE_MAP[$importance])) {
                continue;
            }
            $currency = self::TV_CURRENCY_MAP[$country] ?? '';
            if ($currency === '') {
                continue;
            }
            $lower = strtolower($name);
            foreach (self::TV_NOISE as $noise) {
                if (str_contains($lower, $noise)) {
                    continue 2;
                }
            }

            try {
                $date = new \DateTimeImmutable($dateIso);
            } catch (\Throwable) {
                continue;
            }
            $startsAtUtc = $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

            $forecast = self::scalar($row['forecast'] ?? '');
            $previous = self::scalar($row['previous'] ?? '');
            $id = sha1(strtolower('tradingview|' . $currency . '|' . $name . '|' . $dateIso));
            $byId[$id] = [
                'external_id' => $id,
                'name' => $name,
                'description' => self::description($forecast, $previous),
                'currency' => $currency,
                'impact' => self::TV_IMPORTANCE_MAP[$importance],
                'starts_at_utc' => $startsAtUtc,
                'starts_at_iso' => $dateIso,
                'forecast' => $forecast,
                'previous' => $previous,
            ];
        }

        return self::sorted(array_values($byId));
    }

    public function listForApi(): array
    {
        $rows = $this->db->query(
            'SELECT external_id, source, name, description, currency, impact, starts_at_utc, starts_at_iso, forecast, previous
               FROM macro_events
              WHERE is_deleted = 0
                AND starts_at_utc BETWEEN UTC_TIMESTAMP() - INTERVAL ' . self::FETCH_WINDOW_DAYS_BACK . ' DAY
                                      AND UTC_TIMESTAMP() + INTERVAL ' . self::FETCH_WINDOW_DAYS_FORWARD . ' DAY
              ORDER BY starts_at_utc ASC'
        );

        $events = array_map(static fn (array $row): array => [
            'id' => (string) $row['external_id'],
            'source' => (string) $row['source'],
            'name' => (string) $row['name'],
            'description' => (string) ($row['description'] ?? ''),
            'currency' => (string) $row['currency'],
            'impact' => (int) $row['impact'],
            'startAt' => self::utcIso((string) $row['starts_at_utc']),
            'startIso' => (string) $row['starts_at_iso'],
            'forecast' => $row['forecast'] !== null ? (string) $row['forecast'] : '',
            'previous' => $row['previous'] !== null ? (string) $row['previous'] : '',
        ], $rows);

        return ['events' => $events, 'sync' => $this->syncState()];
    }

    public function syncState(): array
    {
        $row = $this->db->queryOne(
            'SELECT last_attempt_at, last_success_at, last_status, events_count, duration_ms
               FROM macro_sync_state WHERE id = 1'
        );
        if ($row === null) {
            return [
                'status' => 'never',
                'lastSuccessAt' => null,
                'eventsCount' => 0,
                'stale' => true,
            ];
        }

        $lastSuccess = $row['last_success_at'] !== null ? self::utcIso((string) $row['last_success_at']) : null;
        $stale = $row['last_success_at'] === null
            || strtotime((string) $row['last_success_at'] . ' UTC') < time() - self::STALE_AFTER_SECONDS;

        return [
            'status' => (string) ($row['last_status'] ?? 'never'),
            'lastSuccessAt' => $lastSuccess,
            'eventsCount' => (int) ($row['events_count'] ?? 0),
            'stale' => $stale,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function fetchForexFactory(): array
    {
        return $this->fetchJson(self::FF_THIS_WEEK_URL, []);
    }

    /** @return list<array<string, mixed>> */
    private function fetchTradingViewNextWeek(): array
    {
        $utc = new \DateTimeZone('UTC');
        $nextSunday = (new \DateTimeImmutable('now', $utc))->modify('next sunday')->setTime(0, 0);
        $nextSaturday = $nextSunday->modify('+6 days')->setTime(23, 59, 59);
        $url = self::TV_EVENTS_URL
            . '?from=' . rawurlencode($nextSunday->format('Y-m-d\TH:i:s.000\Z'))
            . '&to=' . rawurlencode($nextSaturday->format('Y-m-d\TH:i:s.000\Z'))
            . '&countries=' . rawurlencode(implode(',', self::TV_COUNTRIES));

        $rows = $this->fetchJson($url, [
            'Origin: https://www.tradingview.com',
            'Referer: https://www.tradingview.com/',
        ]);
        $decoded = $rows['result'] ?? null;
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new \RuntimeException('TradingView zwrócił nieprawidłową odpowiedź kalendarza.');
        }
        return $decoded;
    }

    /** @return array<string, mixed> */
    private function fetchJson(string $url, array $extraHeaders): array
    {
        $attempt = 0;
        $lastStatus = 0;
        $lastError = '';

        while ($attempt < 2) {
            $attempt++;
            $ch = curl_init($url);
            if ($ch === false) {
                throw new \RuntimeException('Nie można zainicjować cURL dla kalendarza makro.');
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'User-Agent: AlphaVault/3.4 (https://alphavault.pl)',
                    ...$extraHeaders,
                ],
            ]);
            $raw = curl_exec($ch);
            $lastStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $lastError = curl_error($ch);
            curl_close($ch);

            if ($raw === false || $lastStatus !== 200) {
                if ($lastStatus === 429) {
                    break;
                }
                if ($attempt < 2) {
                    sleep(1);
                }
                continue;
            }

            $decoded = json_decode((string) $raw, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('Kalendarz makro zwrócił nieprawidłowy JSON.');
            }
            return $decoded;
        }

        throw new \RuntimeException(sprintf(
            'Nie można pobrać kalendarza makro (HTTP %d%s).',
            $lastStatus,
            $lastError !== '' ? ': ' . $lastError : ''
        ));
    }

    private function acquireLock(): bool
    {
        $row = $this->db->queryOne('SELECT GET_LOCK(?, 1) AS acquired', [self::LOCK_NAME]);
        return (int) ($row['acquired'] ?? 0) === 1;
    }

    private function releaseLock(): void
    {
        try {
            $this->db->queryOne('SELECT RELEASE_LOCK(?) AS released', [self::LOCK_NAME]);
        } catch (\Throwable) {
            // Zwolnienie blokady nie może wywrócić synchronizacji.
        }
    }

    private static function description(string $forecast, string $previous): string
    {
        $parts = [];
        if ($forecast !== '') {
            $parts[] = 'Prognoza ' . $forecast;
        }
        if ($previous !== '') {
            $parts[] = 'poprzednio ' . $previous;
        }
        return implode(' · ', $parts);
    }

    private static function scalar(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return rtrim(rtrim((string) $value, '0'), '.');
        }
        return trim((string) $value);
    }

    /** @param list<array<string, mixed>> $events */
    private static function sorted(array $events): array
    {
        usort($events, static fn (array $a, array $b): int => strcmp($a['starts_at_utc'], $b['starts_at_utc']));
        return $events;
    }

    /** @return array{0:string,1:string} start i koniec okna (koniec wyłączny) */
    private static function currentWeekWindowUtc(): array
    {
        $utc = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $utc);
        $daysSinceSunday = (int) $now->format('w');
        $start = $now->modify('-' . $daysSinceSunday . ' days')->setTime(0, 0);
        return [$start->format('Y-m-d H:i:s'), $start->modify('+7 days')->format('Y-m-d H:i:s')];
    }

    /** @return array{0:string,1:string} start i koniec okna (koniec wyłączny) */
    private static function nextWeekWindowUtc(): array
    {
        $utc = new \DateTimeZone('UTC');
        $nextSunday = (new \DateTimeImmutable('now', $utc))->modify('next sunday')->setTime(0, 0);
        return [$nextSunday->format('Y-m-d H:i:s'), $nextSunday->modify('+7 days')->format('Y-m-d H:i:s')];
    }

    private static function utcIso(string $utcDateTime): string
    {
        try {
            $date = new \DateTimeImmutable($utcDateTime, new \DateTimeZone('UTC'));
            return $date->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable) {
            return '';
        }
    }

    private function log(string $message): void
    {
        $line = gmdate('c') . ' ' . $message . "\n";
        error_log('[macro-sync] ' . $message);
        try {
            $dir = $this->config->root . '/logs';
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            file_put_contents($dir . '/macro-sync.log', $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Logowanie nie może przerwać synchronizacji.
        }
    }
}
