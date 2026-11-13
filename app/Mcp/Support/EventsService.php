<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Support;

use AlphaVault\Config;
use AlphaVault\Db;
use AlphaVault\DiscordEvents;

final class EventsService
{
    private DiscordEvents $events;

    public function __construct(Config $config, Db $db)
    {
        $this->events = new DiscordEvents($config, $db);
    }

    public function list(array $filters = []): array
    {
        $category = (string) ($filters['category'] ?? 'all');
        if (!in_array($category, ['all', 'live', 'consultation', 'unassigned'], true)) {
            throw new \InvalidArgumentException('Pole category musi mieć wartość all, live, consultation albo unassigned.');
        }
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 20)));
        $from = (string) ($filters['from'] ?? '');
        $to = (string) ($filters['to'] ?? '');
        $fromTs = time() - 3600;
        $toTs = null;
        if ($from !== '') {
            if (!Dates::isValidKey($from)) {
                throw new \InvalidArgumentException('Pole from musi mieć format YYYY-MM-DD.');
            }
            $fromTs = Dates::startOfLocalDay($from)->getTimestamp();
        }
        if ($to !== '') {
            if (!Dates::isValidKey($to)) {
                throw new \InvalidArgumentException('Pole to musi mieć format YYYY-MM-DD.');
            }
            $toTs = Dates::startOfLocalDay($to)->modify('+1 day')->getTimestamp();
        }

        $raw = $this->events->listForApi(false);
        $items = [];
        foreach (($raw['events'] ?? []) as $event) {
            if (!is_array($event)) {
                continue;
            }
            if ($category !== 'all' && (string) ($event['category'] ?? '') !== $category) {
                continue;
            }
            $ts = strtotime((string) ($event['startAt'] ?? '')) ?: 0;
            if ($ts < $fromTs) {
                continue;
            }
            if ($toTs !== null && $ts >= $toTs) {
                continue;
            }
            $items[] = $this->map($event);
        }
        usort($items, static fn (array $a, array $b): int => strcmp((string) $a['startAt'], (string) $b['startAt']));

        return [
            'events' => array_slice($items, 0, $limit),
            'total' => count($items),
            'sync' => $this->sync($raw['sync'] ?? null),
        ];
    }

    public function nextUpcoming(): ?array
    {
        try {
            $raw = $this->events->listForApi(false);
        } catch (\Throwable) {
            return null;
        }
        $now = time() - 3600;
        foreach (($raw['events'] ?? []) as $event) {
            if (!is_array($event)) {
                continue;
            }
            if (!in_array((string) ($event['category'] ?? ''), ['live', 'consultation'], true)) {
                continue;
            }
            if (in_array((int) ($event['statusCode'] ?? 0), [3, 4], true)) {
                continue;
            }
            if ((strtotime((string) ($event['startAt'] ?? '')) ?: 0) < $now) {
                continue;
            }

            return $this->map($event);
        }

        return null;
    }

    public function sync(?array $sync): ?array
    {
        if ($sync === null) {
            return null;
        }

        return [
            'status' => $sync['status'] ?? 'never',
            'lastSuccessAt' => $sync['lastSuccessAt'] ?? null,
            'eventsCount' => (int) ($sync['eventsCount'] ?? 0),
            'stale' => (bool) ($sync['stale'] ?? true),
            'lastError' => $sync['lastError'] ?? null,
        ];
    }

    private function map(array $event): array
    {
        $description = $event['description'] ?? null;
        if (is_string($description)) {
            $description = mb_substr($description, 0, 2000);
        } else {
            $description = null;
        }

        return [
            'id' => (string) ($event['id'] ?? ''),
            'name' => (string) ($event['name'] ?? ''),
            'description' => $description,
            'category' => (string) ($event['category'] ?? ''),
            'status' => (string) ($event['status'] ?? ''),
            'entityType' => (string) ($event['entityType'] ?? ''),
            'startAt' => (string) ($event['startAt'] ?? ''),
            'startLocal' => $event['startLocal'] ?? null,
            'localDate' => $event['localDate'] ?? null,
            'localTime' => $event['localTime'] ?? null,
            'weekday' => $event['weekday'] ?? null,
            'endAt' => $event['endAt'] ?? null,
            'durationMinutes' => $event['durationMinutes'] ?? null,
            'host' => $event['host'] ?? null,
            'userCount' => $event['userCount'] ?? null,
            'location' => $event['location'] ?? null,
        ];
    }
}
