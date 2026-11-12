<?php
declare(strict_types=1);

namespace AlphaVault;

final class DiscordEvents
{
    private const TZ = 'Europe/Warsaw';
    private const TYPES = [1 => 'STAGE_INSTANCE', 2 => 'VOICE', 3 => 'EXTERNAL'];
    private const STATUSES = [1 => 'SCHEDULED', 2 => 'ACTIVE', 3 => 'COMPLETED', 4 => 'CANCELED'];
    private const WEEKDAYS = [
        1 => 'poniedziałek', 2 => 'wtorek', 3 => 'środa', 4 => 'czwartek',
        5 => 'piątek', 6 => 'sobota', 7 => 'niedziela',
    ];
    private const WEEKDAYS_SHORT = [
        1 => 'pon.', 2 => 'wt.', 3 => 'śr.', 4 => 'czw.', 5 => 'pt.', 6 => 'sob.', 7 => 'niedz.',
    ];
    private const MONTHS = [
        1 => 'stycznia', 2 => 'lutego', 3 => 'marca', 4 => 'kwietnia', 5 => 'maja', 6 => 'czerwca',
        7 => 'lipca', 8 => 'sierpnia', 9 => 'września', 10 => 'października', 11 => 'listopada', 12 => 'grudnia',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly Db $db,
    ) {
    }

    public function fetchScheduledEvents(): array
    {
        $token = $this->config->discord['botToken'];
        $guildId = $this->config->discord['guildId'];
        if ($token === '' || $guildId === '') {
            throw new DiscordApiException(0, 'not_configured', 'DISCORD_BOT_TOKEN lub DISCORD_GUILD_ID nie są ustawione.');
        }

        $url = 'https://discord.com/api/v10/guilds/' . rawurlencode($guildId) . '/scheduled-events?with_user_count=true';
        $attempt = 0;
        $maxAttempts = 3;

        while (true) {
            $attempt++;
            $ch = curl_init($url);
            if ($ch === false) {
                throw new DiscordApiException(0, 'curl_init', 'Nie można zainicjować cURL.');
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bot ' . $token,
                    'Accept: application/json',
                    'User-Agent: AlphaVault/3.0 (https://alphavault.pl)',
                ],
            ]);
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            $retryAfter = 0;
            if ($status === 429) {
                $retryHeader = defined('CURLINFO_RETRY_AFTER') ? (string) curl_getinfo($ch, CURLINFO_RETRY_AFTER) : '';
                $retryAfter = max(1, min(30, (int) ($retryHeader !== '' ? $retryHeader : 5)));
            }
            curl_close($ch);

            if ($raw === false) {
                if ($attempt < $maxAttempts) {
                    sleep(min(8, 2 ** $attempt));
                    continue;
                }
                throw new DiscordApiException(0, 'timeout', 'Błąd połączenia z Discordem: ' . $error);
            }

            if ($status === 429 && $attempt < $maxAttempts) {
                sleep($retryAfter > 0 ? $retryAfter : min(30, 2 ** $attempt));
                continue;
            }
            if ($status >= 500 && $attempt < $maxAttempts) {
                sleep(min(8, 2 ** $attempt));
                continue;
            }

            if ($status !== 200) {
                $decoded = json_decode((string) $raw, true);
                $message = is_array($decoded) && isset($decoded['message']) ? (string) $decoded['message'] : 'Discord API error';
                throw new DiscordApiException($status, 'http_' . $status, $message, $retryAfter);
            }

            $decoded = json_decode((string) $raw, true);
            if (!is_array($decoded) || !array_is_list($decoded)) {
                throw new DiscordApiException($status, 'invalid_json', 'Discord zwrócił nieprawidłową odpowiedź JSON.');
            }
            return $decoded;
        }
    }

    public function sync(): array
    {
        $lock = $this->acquireLock();
        if ($lock === null) {
            $this->log('sync skipped: another run holds the lock');
            return ['skipped' => true];
        }

        $started = microtime(true);
        $this->db->execute(
            'UPDATE discord_sync_state
                SET last_attempt_at = UTC_TIMESTAMP(), last_status = ?, last_error_code = NULL,
                    last_error_message = NULL, updated_at = UTC_TIMESTAMP()
              WHERE id = 1',
            ['running']
        );

        try {
            $events = $this->fetchScheduledEvents();
            $result = $this->syncPayload($events, 200, $started);
            $this->recordRun('success', 200, $result, null);
            $this->log(sprintf('sync success: events=%d updated=%d duration=%dms', $result['events_count'], $result['updated_count'], $result['duration_ms']));
            return $result;
        } catch (\Throwable $err) {
            $status = $err instanceof DiscordApiException ? $err->httpStatus : 0;
            $code = $err instanceof DiscordApiException ? $err->errorCode : 'sync_error';
            $message = $this->redact($err->getMessage());
            try {
                $this->db->execute(
                    'UPDATE discord_sync_state
                        SET last_status = ?, last_error_code = ?, last_error_message = ?, last_http_status = ?,
                            duration_ms = ?, updated_at = UTC_TIMESTAMP()
                      WHERE id = 1',
                    ['error', $code, substr($message, 0, 500), $status ?: null, (int) round((microtime(true) - $started) * 1000)]
                );
            } catch (\Throwable $dbErr) {
                $this->log('cannot persist sync error: ' . $dbErr->getMessage());
            }
            $this->recordRun('error', $status, [
                'events_count' => 0,
                'updated_count' => 0,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ], $code . ': ' . $message);
            $this->log('sync error: ' . $code . ' ' . $message);
            throw $err;
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function syncPayload(array $events, int $httpStatus = 200, ?float $started = null): array
    {
        $started ??= microtime(true);
        $guildId = $this->config->discord['guildId'];
        if ($guildId === '') {
            throw new \RuntimeException('DISCORD_GUILD_ID nie jest ustawione.');
        }

        $normalized = [];
        foreach ($events as $event) {
            $normalized[] = $this->normalizeEvent($event, $guildId);
        }

        $existingRows = $this->db->query(
            'SELECT discord_event_id, manual_category FROM discord_scheduled_events WHERE guild_id = ?',
            [$guildId]
        );
        $manual = [];
        foreach ($existingRows as $row) {
            if ($row['manual_category'] !== null && $row['manual_category'] !== '') {
                $manual[(string) $row['discord_event_id']] = (string) $row['manual_category'];
            }
        }

        $ids = array_map(static fn (array $event): string => $event['discord_event_id'], $normalized);
        $now = gmdate('Y-m-d H:i:s');
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        $this->db->withTransaction(function (Db $db) use ($normalized, $manual, $ids, $guildId, $httpStatus, $durationMs): void {
            foreach ($normalized as $event) {
                $classification = $this->classify($event, $manual[$event['discord_event_id']] ?? null);
                $db->execute(
                    'INSERT INTO discord_scheduled_events
                        (discord_event_id, guild_id, name, description, event_type, status, channel_id,
                         scheduled_start_time, scheduled_start_iso, scheduled_end_time, scheduled_end_iso,
                         creator_id, creator_json, user_count, location, entity_id, entity_metadata, image,
                         recurrence_rule, raw_payload, category, category_source, host_name, host_source,
                         duration_minutes, first_seen_at, last_seen_at, updated_at, last_synced_at, is_deleted, deleted_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP(), 0, NULL)
                     ON DUPLICATE KEY UPDATE
                        guild_id = VALUES(guild_id),
                        name = VALUES(name),
                        description = VALUES(description),
                        event_type = VALUES(event_type),
                        status = VALUES(status),
                        channel_id = VALUES(channel_id),
                        scheduled_start_time = VALUES(scheduled_start_time),
                        scheduled_start_iso = VALUES(scheduled_start_iso),
                        scheduled_end_time = VALUES(scheduled_end_time),
                        scheduled_end_iso = VALUES(scheduled_end_iso),
                        creator_id = VALUES(creator_id),
                        creator_json = VALUES(creator_json),
                        user_count = VALUES(user_count),
                        location = VALUES(location),
                        entity_id = VALUES(entity_id),
                        entity_metadata = VALUES(entity_metadata),
                        image = VALUES(image),
                        recurrence_rule = VALUES(recurrence_rule),
                        raw_payload = VALUES(raw_payload),
                        category = COALESCE(manual_category, VALUES(category)),
                        category_source = IF(manual_category IS NULL, VALUES(category_source), \'manual\'),
                        host_name = VALUES(host_name),
                        host_source = VALUES(host_source),
                        duration_minutes = VALUES(duration_minutes),
                        last_seen_at = UTC_TIMESTAMP(),
                        updated_at = UTC_TIMESTAMP(),
                        last_synced_at = UTC_TIMESTAMP(),
                        is_deleted = 0,
                        deleted_at = NULL',
                    [
                        $event['discord_event_id'], $event['guild_id'], $event['name'], $event['description'],
                        $event['event_type'], $event['status'], $event['channel_id'],
                        $event['scheduled_start_time'], $event['scheduled_start_iso'],
                        $event['scheduled_end_time'], $event['scheduled_end_iso'],
                        $event['creator_id'], $event['creator_json'], $event['user_count'], $event['location'],
                        $event['entity_id'], $event['entity_metadata'], $event['image'],
                        $event['recurrence_rule'], $event['raw_payload'],
                        $classification['category'], $classification['source'],
                        $event['host_name'], $event['host_source'], $event['duration_minutes'],
                    ]
                );
            }

            if ($ids === []) {
                $db->execute(
                    'UPDATE discord_scheduled_events
                        SET is_deleted = 1, deleted_at = UTC_TIMESTAMP(), last_synced_at = UTC_TIMESTAMP()
                      WHERE guild_id = ? AND is_deleted = 0',
                    [$guildId]
                );
            } else {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $params = array_merge([$guildId], $ids);
                $db->execute(
                    'UPDATE discord_scheduled_events
                        SET is_deleted = 1, deleted_at = UTC_TIMESTAMP(), last_synced_at = UTC_TIMESTAMP()
                      WHERE guild_id = ? AND is_deleted = 0 AND discord_event_id NOT IN (' . $placeholders . ')',
                    $params
                );
            }

            $db->execute(
                'UPDATE discord_sync_state
                    SET last_status = ?, last_success_at = UTC_TIMESTAMP(), events_count = ?, updated_count = ?,
                        duration_ms = ?, last_http_status = ?, last_error_code = NULL, last_error_message = NULL,
                        updated_at = UTC_TIMESTAMP()
                  WHERE id = 1',
                ['success', count($normalized), count($normalized), $durationMs, $httpStatus]
            );
        });

        return [
            'events_count' => count($normalized),
            'updated_count' => count($normalized),
            'duration_ms' => $durationMs,
        ];
    }

    public function listForApi(bool $includeDeleted = false): array
    {
        $guildId = $this->config->discord['guildId'];
        $sql = 'SELECT * FROM discord_scheduled_events WHERE guild_id = ?';
        if (!$includeDeleted) {
            $sql .= ' AND is_deleted = 0';
        }
        $sql .= ' ORDER BY scheduled_start_time ASC, id ASC';
        $rows = $this->db->query($sql, [$guildId]);
        $events = array_map(fn (array $row): array => $this->toApiEvent($row), $rows);

        $consultations = array_values(array_filter($events, static fn (array $e): bool => $e['category'] === 'consultation' && !in_array($e['statusCode'], [3, 4], true)));
        $live = array_values(array_filter($events, static fn (array $e): bool => $e['category'] === 'live' && !in_array($e['statusCode'], [3, 4], true)));
        $unassigned = array_values(array_filter($events, static fn (array $e): bool => $e['category'] === 'unassigned'));

        $nextConsultation = null;
        $now = time();
        foreach ($consultations as $event) {
            $ts = strtotime($event['startAt']);
            if ($ts !== false && $ts >= $now - 3600) {
                $nextConsultation = $event;
                break;
            }
        }

        return [
            'events' => $events,
            'live' => $live,
            'consultations' => $consultations,
            'unassigned' => $unassigned,
            'nextConsultation' => $nextConsultation,
            'sync' => $this->syncState(),
        ];
    }

    public function syncState(): array
    {
        $row = $this->db->queryOne('SELECT * FROM discord_sync_state WHERE id = 1');
        if ($row === null) {
            return [
                'lastAttemptAt' => null, 'lastSuccessAt' => null, 'status' => 'never',
                'eventsCount' => 0, 'updatedCount' => 0, 'durationMs' => null,
                'lastHttpStatus' => null, 'lastError' => null, 'stale' => true,
            ];
        }
        $lastSuccess = $row['last_success_at'] ?? null;
        $stale = true;
        if ($lastSuccess !== null) {
            $stale = (time() - strtotime((string) $lastSuccess . ' UTC')) > 900;
        }
        return [
            'lastAttemptAt' => $row['last_attempt_at'],
            'lastSuccessAt' => $lastSuccess,
            'status' => $row['last_status'],
            'eventsCount' => (int) $row['events_count'],
            'updatedCount' => (int) $row['updated_count'],
            'durationMs' => $row['duration_ms'] !== null ? (int) $row['duration_ms'] : null,
            'lastHttpStatus' => $row['last_http_status'] !== null ? (int) $row['last_http_status'] : null,
            'lastError' => $row['last_error_code'] !== null
                ? ['code' => $row['last_error_code'], 'message' => $row['last_error_message']]
                : null,
            'stale' => $stale,
        ];
    }

    public function adminSetCategory(string $eventId, string $category): bool
    {
        if (!in_array($category, ['live', 'consultation', 'unassigned'], true)) {
            throw new HttpException(422, 'bad_category', 'Kategoria musi być live, consultation lub unassigned.');
        }
        $updated = $this->db->execute(
            'UPDATE discord_scheduled_events
                SET manual_category = ?, category = ?, category_source = ?, updated_at = UTC_TIMESTAMP()
              WHERE discord_event_id = ? AND guild_id = ?',
            [$category, $category, 'manual', $eventId, $this->config->discord['guildId']]
        );
        return $updated > 0;
    }

    public function isAdmin(?array $user): bool
    {
        if ($user === null || empty($this->config->adminDiscordIds)) {
            return false;
        }
        $discordId = (string) ($user['discordId'] ?? '');
        return $discordId !== '' && in_array($discordId, $this->config->adminDiscordIds, true);
    }

    public static function localParts(string $iso): array
    {
        $utc = new \DateTimeImmutable($iso);
        $local = $utc->setTimezone(new \DateTimeZone(self::TZ));
        $weekday = (int) $local->format('N');
        $month = (int) $local->format('n');
        return [
            'date' => $local->format('Y-m-d'),
            'time' => $local->format('H:i'),
            'offset' => $local->format('P'),
            'weekday' => self::WEEKDAYS[$weekday],
            'weekdayShort' => self::WEEKDAYS_SHORT[$weekday],
            'month' => self::MONTHS[$month],
            'label' => self::WEEKDAYS_SHORT[$weekday] . ' ' . $local->format('d.m') . ' · ' . $local->format('H:i'),
            'dateLabel' => self::WEEKDAYS[$weekday] . ', ' . $local->format('j') . ' ' . self::MONTHS[$month] . ' ' . $local->format('Y'),
            'isoLocal' => $local->format(DATE_ATOM),
        ];
    }

    public static function hostFromName(string $name): ?string
    {
        $parts = preg_split('/\s+-\s+/u', trim($name)) ?: [];
        if (count($parts) >= 3) {
            $candidate = trim($parts[1]);
            if ($candidate !== '' && mb_strlen($candidate) <= 80) {
                return $candidate;
            }
        }
        if (preg_match('/konsultacj/i', $name) && count($parts) === 2) {
            $candidate = trim($parts[1]);
            if ($candidate !== '' && mb_strlen($candidate) <= 80) {
                return $candidate;
            }
        }
        return null;
    }

    private function normalizeEvent(array $event, string $guildId): array
    {
        $id = (string) ($event['id'] ?? '');
        if ($id === '' || !preg_match('/^[0-9]+$/', $id)) {
            throw new DiscordApiException(200, 'invalid_payload', 'Wydarzenie Discord bez poprawnego id.');
        }
        $name = (string) ($event['name'] ?? '');
        $startIso = (string) ($event['scheduled_start_time'] ?? '');
        try {
            $start = new \DateTimeImmutable($startIso);
        } catch (\Throwable) {
            throw new DiscordApiException(200, 'invalid_payload', 'Wydarzenie ' . $id . ' ma niepoprawny scheduled_start_time.');
        }
        $endIso = isset($event['scheduled_end_time']) && $event['scheduled_end_time'] !== null
            ? (string) $event['scheduled_end_time'] : null;
        $end = null;
        if ($endIso !== null) {
            try {
                $end = new \DateTimeImmutable($endIso);
            } catch (\Throwable) {
                throw new DiscordApiException(200, 'invalid_payload', 'Wydarzenie ' . $id . ' ma niepoprawny scheduled_end_time.');
            }
        }
        $status = (int) ($event['status'] ?? 0);
        if (!array_key_exists($status, self::STATUSES)) {
            throw new DiscordApiException(200, 'invalid_payload', 'Wydarzenie ' . $id . ' ma nieznany status.');
        }
        $entityType = (int) ($event['entity_type'] ?? 0);
        $creator = is_array($event['creator'] ?? null) ? $event['creator'] : null;
        $entityMetadata = is_array($event['entity_metadata'] ?? null) ? $event['entity_metadata'] : null;
        $host = self::hostFromName($name);
        $hostSource = $host !== null ? 'name_rule' : 'none';
        if ($host === null && $creator !== null && empty($creator['bot'])) {
            $host = (string) ($creator['global_name'] ?? $creator['username'] ?? '');
            $hostSource = $host !== '' ? 'creator' : 'none';
            $host = $host !== '' ? $host : null;
        }
        $duration = null;
        if ($end !== null) {
            $duration = max(0, (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60));
        }
        $location = null;
        if ($entityType === 3 && is_array($entityMetadata) && isset($entityMetadata['location'])) {
            $location = (string) $entityMetadata['location'];
        }

        return [
            'discord_event_id' => $id,
            'guild_id' => (string) ($event['guild_id'] ?? $guildId),
            'name' => $name,
            'description' => isset($event['description']) ? (string) $event['description'] : null,
            'event_type' => $entityType,
            'status' => $status,
            'channel_id' => isset($event['channel_id']) ? (string) $event['channel_id'] : null,
            'scheduled_start_time' => $start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'scheduled_start_iso' => $start->format(DATE_ATOM),
            'scheduled_end_time' => $end?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'scheduled_end_iso' => $end?->format(DATE_ATOM),
            'creator_id' => $creator !== null && isset($creator['id']) ? (string) $creator['id'] : null,
            'creator_json' => $creator !== null ? json_encode($creator, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'user_count' => isset($event['user_count']) && is_numeric($event['user_count']) ? (int) $event['user_count'] : null,
            'location' => $location,
            'entity_id' => isset($event['entity_id']) ? (string) $event['entity_id'] : null,
            'entity_metadata' => $entityMetadata !== null ? json_encode($entityMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'image' => isset($event['image']) ? (string) $event['image'] : null,
            'recurrence_rule' => isset($event['recurrence_rule']) && is_array($event['recurrence_rule'])
                ? json_encode($event['recurrence_rule'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'raw_payload' => json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'host_name' => $host,
            'host_source' => $hostSource,
            'duration_minutes' => $duration,
            'entity_type_valid' => in_array($entityType, [1, 2, 3], true),
        ];
    }

    private function classify(array $event, ?string $manual): array
    {
        if ($manual !== null && in_array($manual, ['live', 'consultation', 'unassigned'], true)) {
            return ['category' => $manual, 'source' => 'manual'];
        }

        $rules = $this->config->discordEventRules;
        $eventId = $event['discord_event_id'];
        if (isset($rules['event_categories'][$eventId])
            && in_array($rules['event_categories'][$eventId], ['live', 'consultation', 'unassigned'], true)) {
            return ['category' => $rules['event_categories'][$eventId], 'source' => 'rule'];
        }

        $text = trim($event['name'] . ' ' . ($event['description'] ?? ''));
        foreach (($rules['name_patterns'] ?? []) as $rule) {
            if (!is_array($rule) || empty($rule['pattern']) || empty($rule['category'])) {
                continue;
            }
            if (@preg_match((string) $rule['pattern'], $text) === 1
                && in_array($rule['category'], ['live', 'consultation', 'unassigned'], true)) {
                return ['category' => $rule['category'], 'source' => 'rule'];
            }
        }

        if (preg_match('/\bkonsultacj/i', $text) || preg_match('/\b1\s*:\s*1\b/', $text)) {
            return ['category' => 'consultation', 'source' => 'rule'];
        }
        if (preg_match('/\blive\b/i', $text) || preg_match('/\bwebinar\b/i', $text)
            || preg_match('/\bwarsztat/i', $text) || preg_match('/\bakademi/i', $text)
            || preg_match('/\banaliza\b/i', $text) || preg_match('/\brynek\b/i', $text)) {
            return ['category' => 'live', 'source' => 'rule'];
        }

        if ($event['name'] === '' || !$event['entity_type_valid']) {
            return ['category' => 'unassigned', 'source' => 'none'];
        }
        return ['category' => 'live', 'source' => 'default'];
    }

    private function toApiEvent(array $row): array
    {
        $start = self::localParts((string) $row['scheduled_start_iso']);
        $end = $row['scheduled_end_iso'] !== null ? self::localParts((string) $row['scheduled_end_iso']) : null;
        return [
            'id' => (string) $row['discord_event_id'],
            'name' => (string) $row['name'],
            'description' => $row['description'],
            'entityType' => self::TYPES[(int) $row['event_type']] ?? 'UNKNOWN',
            'entityTypeCode' => (int) $row['event_type'],
            'status' => self::STATUSES[(int) $row['status']] ?? 'UNKNOWN',
            'statusCode' => (int) $row['status'],
            'startAt' => (string) $row['scheduled_start_iso'],
            'startLocal' => $start['isoLocal'],
            'startLabel' => $start['label'],
            'startDateLabel' => $start['dateLabel'],
            'localDate' => $start['date'],
            'localTime' => $start['time'],
            'weekday' => $start['weekday'],
            'endAt' => $row['scheduled_end_iso'],
            'endLocal' => $end['isoLocal'] ?? null,
            'durationMinutes' => $row['duration_minutes'] !== null ? (int) $row['duration_minutes'] : null,
            'channelId' => $row['channel_id'],
            'creator' => $row['creator_json'] !== null ? json_decode((string) $row['creator_json'], true) : null,
            'host' => $row['host_name'],
            'hostSource' => $row['host_source'],
            'userCount' => $row['user_count'] !== null ? (int) $row['user_count'] : null,
            'location' => $row['location'],
            'image' => $row['image'],
            'recurrenceRule' => $row['recurrence_rule'] !== null ? json_decode((string) $row['recurrence_rule'], true) : null,
            'category' => (string) $row['category'],
            'categorySource' => (string) $row['category_source'],
            'isDeleted' => (bool) $row['is_deleted'],
            'updatedAt' => $row['updated_at'],
        ];
    }

    private function acquireLock(): ?string
    {
        $token = bin2hex(random_bytes(16));
        $updated = $this->db->execute(
            'UPDATE discord_sync_state
                SET lock_token = ?, locked_until = UTC_TIMESTAMP() + INTERVAL 10 MINUTE
              WHERE id = 1 AND (locked_until IS NULL OR locked_until < UTC_TIMESTAMP())',
            [$token]
        );
        return $updated > 0 ? $token : null;
    }

    private function releaseLock(string $token): void
    {
        try {
            $this->db->execute(
                'UPDATE discord_sync_state SET lock_token = NULL, locked_until = NULL WHERE id = 1 AND lock_token = ?',
                [$token]
            );
        } catch (\Throwable) {
            // Blokada wygaśnie sama.
        }
    }

    private function recordRun(string $status, int $httpStatus, array $result, ?string $error): void
    {
        try {
            $this->db->execute(
                'INSERT INTO discord_sync_runs (started_at, finished_at, status, http_status, events_count, updated_count, duration_ms, error_message)
                 VALUES (UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?, ?, ?, ?, ?, ?)',
                [
                    $status,
                    $httpStatus ?: null,
                    (int) ($result['events_count'] ?? 0),
                    (int) ($result['updated_count'] ?? 0),
                    (int) ($result['duration_ms'] ?? 0),
                    $error !== null ? substr($this->redact($error), 0, 500) : null,
                ]
            );
            $this->db->execute('DELETE FROM discord_sync_runs WHERE started_at < UTC_TIMESTAMP() - INTERVAL 30 DAY');
        } catch (\Throwable $err) {
            $this->log('cannot write sync history: ' . $err->getMessage());
        }
    }

    private function log(string $message): void
    {
        $message = $this->redact($message);
        $line = gmdate('c') . ' ' . $message . "\n";
        error_log('[discord-sync] ' . $message);
        try {
            $dir = $this->config->root . '/logs';
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            file_put_contents($dir . '/discord-sync.log', $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Logowanie nie może przerwać synchronizacji.
        }
    }

    private function redact(string $message): string
    {
        $token = $this->config->discord['botToken'];
        if ($token !== '') {
            $message = str_replace($token, '[redacted]', $message);
        }
        return $message;
    }
}
