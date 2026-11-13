<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Support;

use AlphaVault\Db;

final class MacroService
{
    public function __construct(private readonly Db $db)
    {
    }

    public function list(array $filters = []): array
    {
        $from = trim((string) ($filters['from'] ?? ''));
        $to = trim((string) ($filters['to'] ?? ''));
        $currency = strtoupper(trim((string) ($filters['currency'] ?? '')));
        $impact = isset($filters['impact']) && $filters['impact'] !== '' ? (int) $filters['impact'] : null;
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $fromTs = $this->timestamp($from, 'from');
        $toTs = $this->timestamp($to, 'to');
        if ($fromTs !== null && $toTs !== null && $fromTs > $toTs) {
            throw new \InvalidArgumentException('Pole from nie może być późniejsze niż to.');
        }
        if ($impact !== null && ($impact < 1 || $impact > 3)) {
            throw new \InvalidArgumentException('impact musi być 1, 2 albo 3.');
        }
        if ($currency !== '' && preg_match('/^[A-Z]{2,8}$/', $currency) !== 1) {
            throw new \InvalidArgumentException('currency musi być kodem waluty, np. USD.');
        }

        $where = ['is_deleted = 0'];
        $params = [];
        if ($fromTs !== null) {
            $where[] = 'starts_at_utc >= ?';
            $params[] = gmdate('Y-m-d H:i:s', $fromTs);
        }
        if ($toTs !== null) {
            $where[] = 'starts_at_utc <= ?';
            $params[] = gmdate('Y-m-d H:i:s', $toTs);
        }
        if ($currency !== '') {
            $where[] = 'currency = ?';
            $params[] = $currency;
        }
        if ($impact !== null) {
            $where[] = 'impact = ?';
            $params[] = $impact;
        }
        $whereSql = implode(' AND ', $where);

        $totalRow = $this->db->queryOne('SELECT COUNT(*) AS n FROM macro_events WHERE ' . $whereSql, $params);
        $rows = $this->db->query(
            'SELECT id, source, name, description, currency, impact, starts_at_iso, forecast, previous
             FROM macro_events WHERE ' . $whereSql . ' ORDER BY starts_at_utc ASC LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );

        $items = array_map(static function (array $row): array {
            $start = (string) $row['starts_at_iso'];
            $local = Dates::localParts($start);

            return [
                'id' => (string) $row['id'],
                'source' => (string) $row['source'],
                'name' => (string) $row['name'],
                'description' => $row['description'] !== null ? mb_substr((string) $row['description'], 0, 500) : null,
                'currency' => (string) $row['currency'],
                'impact' => (int) $row['impact'],
                'startAt' => $start,
                'startLocal' => $local['date'] !== null ? $local['date'] . 'T' . $local['time'] . ':00' . $local['offset'] : null,
                'forecast' => $row['forecast'],
                'previous' => $row['previous'],
            ];
        }, $rows);

        $total = (int) ($totalRow['n'] ?? 0);

        return [
            'items' => $items,
            'total' => $total,
            'nextOffset' => ($offset + count($items)) < $total ? $offset + count($items) : null,
            'sync' => $this->sync(),
        ];
    }

    public function sync(): ?array
    {
        try {
            $row = $this->db->queryOne(
                'SELECT last_attempt_at, last_success_at, last_status, last_error, events_count FROM macro_sync_state WHERE id = 1'
            );
        } catch (\Throwable) {
            return null;
        }
        if ($row === null) {
            return null;
        }
        $stale = $row['last_success_at'] === null
            || strtotime((string) $row['last_success_at'] . ' UTC') < time() - 1800;

        return [
            'status' => (string) $row['last_status'],
            'lastSuccessAt' => $row['last_success_at'],
            'eventsCount' => (int) $row['events_count'],
            'stale' => $stale,
        ];
    }

    private function timestamp(string $value, string $field): ?int
    {
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        if ($ts === false) {
            throw new \InvalidArgumentException('Pole ' . $field . ' musi być poprawną datą ISO 8601 albo YYYY-MM-DD.');
        }

        return $ts;
    }
}
