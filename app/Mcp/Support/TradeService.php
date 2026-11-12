<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Support;

final class TradeService
{
    public function includedAccountNames(array $state): array
    {
        $names = [];
        foreach (($state['accounts'] ?? []) as $account) {
            if (!empty($account['include']) && isset($account['name'])) {
                $names[] = (string) $account['name'];
            }
        }

        return $names;
    }

    public function includedTrades(array $state): array
    {
        $names = $this->includedAccountNames($state);
        $trades = [];
        foreach (($state['trades'] ?? []) as $trade) {
            if (!is_array($trade)) {
                continue;
            }
            $account = isset($trade['account']) ? (string) $trade['account'] : '';
            if ($account === '' || in_array($account, $names, true)) {
                $trades[] = $trade;
            }
        }

        return $trades;
    }

    public function closedTrades(array $state): array
    {
        return array_values(array_filter(
            $this->includedTrades($state),
            static fn (array $trade): bool => array_key_exists('result', $trade) && $trade['result'] !== null
        ));
    }

    public function openTrades(array $state): array
    {
        return array_values(array_filter(
            $this->includedTrades($state),
            static fn (array $trade): bool => !array_key_exists('result', $trade) || $trade['result'] === null
        ));
    }

    public function todayTrades(array $state): array
    {
        $today = Dates::todayKey();

        return array_values(array_filter(
            $this->includedTrades($state),
            static fn (array $trade): bool => Dates::dateKey((string) ($trade['date'] ?? '')) === $today
        ));
    }

    public function todayStats(array $state): array
    {
        $arr = $this->todayTrades($state);
        $closed = array_values(array_filter(
            $arr,
            static fn (array $trade): bool => array_key_exists('result', $trade) && $trade['result'] !== null
        ));
        $pnl = 0.0;
        $losses = 0;
        foreach ($closed as $trade) {
            $result = (float) $trade['result'];
            $pnl += $result;
            if ($result < 0) {
                $losses++;
            }
        }
        $risk = 0.0;
        foreach ($arr as $trade) {
            $result = $trade['result'] ?? null;
            $r = $trade['r'] ?? null;
            if ($result !== null && $r !== null && (float) $r !== 0.0) {
                $risk += abs((float) $result / (float) $r);
                continue;
            }
            $slRaw = (string) ($trade['sl'] ?? 'brak');
            if ($slRaw !== '' && $slRaw !== 'brak' && $slRaw !== '—') {
                $entry = Numbers::pnum($trade['entry'] ?? 0);
                $sl = Numbers::pnum($slRaw);
                $size = Numbers::pnum($trade['size'] ?? 0);
                $risk += abs($entry - $sl) * $size;
            }
        }

        return [
            'trades' => $arr,
            'closed' => $closed,
            'pnl' => Numbers::round($pnl),
            'losses' => $losses,
            'count' => count($arr),
            'risk' => Numbers::round($risk),
        ];
    }

    public function list(array $state, array $filters): array
    {
        $status = (string) ($filters['status'] ?? 'all');
        $pair = strtoupper(trim((string) ($filters['pair'] ?? '')));
        $setup = trim((string) ($filters['setup'] ?? ''));
        $grade = strtoupper(trim((string) ($filters['grade'] ?? '')));
        $from = (string) ($filters['from'] ?? '');
        $to = (string) ($filters['to'] ?? '');
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 20)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $fromTs = null;
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

        $filtered = [];
        foreach ($this->includedTrades($state) as $trade) {
            $result = $trade['result'] ?? null;
            if ($status === 'open' && $result !== null) {
                continue;
            }
            if ($status === 'closed' && $result === null) {
                continue;
            }
            if ($pair !== '' && strtoupper((string) ($trade['pair'] ?? '')) !== $pair) {
                continue;
            }
            if ($setup !== '' && (string) ($trade['setup'] ?? '') !== $setup) {
                continue;
            }
            if ($grade !== '' && strtoupper((string) ($trade['grade'] ?? '')) !== $grade) {
                continue;
            }
            $ts = strtotime((string) ($trade['date'] ?? '')) ?: 0;
            if ($fromTs !== null && $ts < $fromTs) {
                continue;
            }
            if ($toTs !== null && $ts >= $toTs) {
                continue;
            }
            $filtered[] = $trade;
        }

        usort($filtered, static function (array $a, array $b): int {
            return (strtotime((string) ($b['date'] ?? '')) ?: 0) <=> (strtotime((string) ($a['date'] ?? '')) ?: 0);
        });

        $total = count($filtered);
        $items = array_slice($filtered, $offset, $limit);

        return [
            'items' => array_map(fn (array $trade): array => $this->sanitize($trade, false), $items),
            'total' => $total,
            'nextOffset' => ($offset + count($items)) < $total ? $offset + count($items) : null,
        ];
    }

    public function find(array $state, string $id): ?array
    {
        foreach ($this->includedTrades($state) as $trade) {
            if ((string) ($trade['id'] ?? '') === $id) {
                return $this->sanitize($trade, true);
            }
        }

        return null;
    }

    public function setupStats(array $state, string $setupName): array
    {
        $trades = array_values(array_filter(
            $this->closedTrades($state),
            static fn (array $trade): bool => (string) ($trade['setup'] ?? '') === $setupName
        ));
        $wins = array_values(array_filter($trades, static fn (array $trade): bool => (float) $trade['result'] > 0));
        $net = 0.0;
        $rSum = 0.0;
        $rCount = 0;
        foreach ($trades as $trade) {
            $net += (float) $trade['result'];
            if (isset($trade['r']) && $trade['r'] !== null) {
                $rSum += (float) $trade['r'];
                $rCount++;
            }
        }

        return [
            'trades' => count($trades),
            'wins' => count($wins),
            'winRate' => $trades !== [] ? Numbers::round(count($wins) / count($trades) * 100) : 0.0,
            'net' => Numbers::round($net),
            'avgR' => $rCount > 0 ? Numbers::round($rSum / $rCount) : 0.0,
        ];
    }

    public function sanitize(array $trade, bool $detail): array
    {
        $entry = Numbers::pnum($trade['entry'] ?? 0);
        $slRaw = (string) ($trade['sl'] ?? 'brak');
        $tpRaw = (string) ($trade['tp'] ?? '—');
        $screens = $trade['screens'] ?? [];
        $screensCount = is_array($screens) ? count(array_filter($screens)) : 0;
        $date = (string) ($trade['date'] ?? '');
        $local = Dates::localParts($date);

        $payload = [
            'id' => (string) ($trade['id'] ?? ''),
            'date' => $date,
            'localDate' => $local['date'],
            'localTime' => $local['time'],
            'pair' => (string) ($trade['pair'] ?? ''),
            'side' => ($trade['dir'] ?? 'l') === 's' ? 'short' : 'long',
            'entry' => (string) ($trade['entry'] ?? ''),
            'stopLoss' => $slRaw === 'brak' ? null : (string) $slRaw,
            'takeProfit' => $tpRaw === '—' ? null : (string) $tpRaw,
            'size' => (string) ($trade['size'] ?? ''),
            'entryPrice' => Numbers::round($entry, 8),
            'stopLossPrice' => $slRaw === 'brak' ? null : Numbers::round(Numbers::pnum($slRaw), 8),
            'takeProfitPrice' => $tpRaw === '—' ? null : Numbers::round(Numbers::pnum($tpRaw), 8),
            'sizeValue' => Numbers::round(Numbers::pnum($trade['size'] ?? 0), 8),
            'exit' => $trade['exit'] ?? null,
            'result' => $trade['result'] !== null ? Numbers::round((float) $trade['result']) : null,
            'r' => $trade['r'] !== null ? Numbers::round((float) $trade['r']) : null,
            'grade' => $trade['grade'] ?? null,
            'checklist' => isset($trade['checklist']) ? (int) $trade['checklist'] : null,
            'checkTotal' => isset($trade['checkTotal']) ? (int) $trade['checkTotal'] : null,
            'errors' => array_values(array_map('strval', is_array($trade['errors'] ?? null) ? $trade['errors'] : [])),
            'emotion' => $trade['emotion'] ?? null,
            'setup' => $trade['setup'] ?? null,
            'session' => $trade['session'] ?? null,
            'mae' => isset($trade['mae']) ? Numbers::round((float) $trade['mae']) : null,
            'mfe' => isset($trade['mfe']) ? Numbers::round((float) $trade['mfe']) : null,
            'durationMin' => isset($trade['durationMin']) ? (int) $trade['durationMin'] : null,
            'fee' => isset($trade['fee']) ? Numbers::round((float) $trade['fee']) : null,
            'plan' => (bool) ($trade['plan'] ?? false),
            'source' => $trade['source'] ?? null,
            'account' => $trade['account'] ?? null,
            'screensCount' => $screensCount,
        ];

        if ($detail) {
            $payload['note'] = (string) ($trade['note'] ?? '');
            $payload['mentor'] = (string) ($trade['mentor'] ?? '');
        }

        return $payload;
    }
}
