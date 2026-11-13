<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Support;

final class StatsService
{
    public function __construct(private readonly TradeService $trades)
    {
    }

    public function compute(array $state, string $period): array
    {
        if (!in_array($period, ['7d', '30d', '90d', 'all'], true)) {
            throw new \InvalidArgumentException('Pole period musi mieć wartość 7d, 30d, 90d albo all.');
        }

        $closed = $this->trades->closedTrades($state);
        $fromKey = null;
        if ($period !== 'all') {
            $days = (int) substr($period, 0, -1);
            $fromKey = (new \DateTimeImmutable('now', Dates::tz()))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
            $fromTs = Dates::startOfLocalDay($fromKey)->getTimestamp();
            $closed = array_values(array_filter(
                $closed,
                static fn (array $trade): bool => (strtotime((string) ($trade['date'] ?? '')) ?: 0) >= $fromTs
            ));
        }

        $n = count($closed);
        $wins = [];
        $losses = [];
        $net = 0.0;
        $grossProfit = 0.0;
        $grossLoss = 0.0;
        $rSum = 0.0;
        $rCount = 0;
        $fees = 0.0;
        $withSl = 0;
        $planned = 0;
        $full = [];
        $part = [];
        foreach ($closed as $trade) {
            $result = (float) ($trade['result'] ?? 0);
            $net += $result;
            if ($result > 0) {
                $wins[] = $trade;
                $grossProfit += $result;
            } elseif ($result < 0) {
                $losses[] = $trade;
                $grossLoss += abs($result);
            }
            if (array_key_exists('r', $trade) && $trade['r'] !== null) {
                $rSum += (float) $trade['r'];
                $rCount++;
            }
            $fees += (float) ($trade['fee'] ?? 0);
            $sl = (string) ($trade['sl'] ?? 'brak');
            if ($sl !== '' && $sl !== 'brak' && $sl !== '—') {
                $withSl++;
            }
            if (!empty($trade['plan'])) {
                $planned++;
            }
            $checklist = (int) ($trade['checklist'] ?? 0);
            $checkTotal = (int) ($trade['checkTotal'] ?? 7);
            if ($checklist >= $checkTotal) {
                $full[] = $trade;
            } else {
                $part[] = $trade;
            }
        }

        $start = $this->startCapital($state);
        if ($start <= 0) {
            $start = 2000.0;
        }
        $sorted = $closed;
        usort($sorted, static fn (array $a, array $b): int => (strtotime((string) ($a['date'] ?? '')) ?: 0) <=> (strtotime((string) ($b['date'] ?? '')) ?: 0));
        $eq = $start;
        $peak = $start;
        $maxDd = 0.0;
        $maxDdPct = 0.0;
        foreach ($sorted as $trade) {
            $eq += (float) ($trade['result'] ?? 0);
            $peak = max($peak, $eq);
            $dd = $eq - $peak;
            if ($dd < $maxDd) {
                $maxDd = $dd;
            }
            if ($peak > 0) {
                $maxDdPct = min($maxDdPct, $dd / $peak * 100);
            }
        }

        $grades = [];
        foreach (['A', 'B', 'C'] as $grade) {
            $items = array_values(array_filter($closed, static fn (array $trade): bool => (string) ($trade['grade'] ?? '') === $grade));
            $gradeWins = array_values(array_filter($items, static fn (array $trade): bool => (float) ($trade['result'] ?? 0) > 0));
            $gradeNet = 0.0;
            foreach ($items as $item) {
                $gradeNet += (float) ($item['result'] ?? 0);
            }
            $grades[] = [
                'grade' => $grade,
                'trades' => count($items),
                'winRate' => $items !== [] ? Numbers::round(count($gradeWins) / count($items) * 100) : 0.0,
                'net' => Numbers::round($gradeNet),
            ];
        }

        $setups = [];
        foreach ($closed as $trade) {
            $key = (string) ($trade['setup'] ?? '—');
            if (!isset($setups[$key])) {
                $setups[$key] = ['setup' => $key, 'trades' => 0, 'wins' => 0, 'net' => 0.0];
            }
            $setups[$key]['trades']++;
            $setups[$key]['net'] += (float) ($trade['result'] ?? 0);
            if ((float) ($trade['result'] ?? 0) > 0) {
                $setups[$key]['wins']++;
            }
        }
        $bySetup = array_values(array_map(static function (array $row): array {
            return [
                'setup' => $row['setup'],
                'trades' => $row['trades'],
                'winRate' => $row['trades'] > 0 ? Numbers::round($row['wins'] / $row['trades'] * 100) : 0.0,
                'net' => Numbers::round($row['net']),
            ];
        }, $setups));
        usort($bySetup, static fn (array $a, array $b): int => $b['net'] <=> $a['net']);

        $avgWin = $wins !== [] ? $grossProfit / count($wins) : 0.0;
        $avgLoss = $losses !== [] ? -$grossLoss / count($losses) : 0.0;
        $checklistStats = static function (array $items): array {
            $count = count($items);
            $winsCount = count(array_filter($items, static fn (array $trade): bool => (float) ($trade['result'] ?? 0) > 0));
            $sum = 0.0;
            foreach ($items as $item) {
                $sum += (float) ($item['result'] ?? 0);
            }

            return [
                'trades' => $count,
                'winRate' => $count > 0 ? Numbers::round($winsCount / $count * 100) : 0.0,
                'net' => Numbers::round($sum),
            ];
        };

        return [
            'period' => $period,
            'from' => $fromKey ?? ($sorted !== [] ? Dates::dateKey((string) ($sorted[0]['date'] ?? '')) : null),
            'to' => Dates::todayKey(),
            'trades' => $n,
            'wins' => count($wins),
            'losses' => count($losses),
            'winRate' => $n > 0 ? Numbers::round(count($wins) / $n * 100) : 0.0,
            'net' => Numbers::round($net),
            'grossProfit' => Numbers::round($grossProfit),
            'grossLoss' => Numbers::round($grossLoss),
            'profitFactor' => $grossLoss > 0 ? Numbers::round($grossProfit / $grossLoss) : ($grossProfit > 0 ? 99.0 : 0.0),
            'expectancyR' => $rCount > 0 ? Numbers::round($rSum / $rCount) : 0.0,
            'avgWin' => Numbers::round($avgWin),
            'avgLoss' => Numbers::round($avgLoss),
            'maxDrawdown' => Numbers::round($maxDd),
            'maxDrawdownPct' => Numbers::round($maxDdPct),
            'fees' => Numbers::round($fees),
            'withStopLoss' => $n > 0 ? Numbers::round($withSl / $n * 100) : 0.0,
            'planAdherence' => $n > 0 ? Numbers::round($planned / $n * 100) : 0.0,
            'byGrade' => $grades,
            'bySetup' => array_slice($bySetup, 0, 5),
            'fullChecklist' => $checklistStats($full),
            'partialChecklist' => $checklistStats($part),
            'currency' => 'USDT',
        ];
    }

    public function startCapital(array $state): float
    {
        $sum = 0.0;
        foreach (($state['accounts'] ?? []) as $account) {
            if (empty($account['include'])) {
                continue;
            }
            $sum += (float) ($account['start'] ?? 0) * Numbers::fxToUsdt((string) ($account['currency'] ?? 'USDT'));
        }

        return $sum;
    }

    public function totalCapital(array $state): float
    {
        $sum = 0.0;
        foreach (($state['accounts'] ?? []) as $account) {
            if (empty($account['include'])) {
                continue;
            }
            $sum += (float) ($account['balance'] ?? 0) * Numbers::fxToUsdt((string) ($account['currency'] ?? 'USDT'));
        }

        return $sum;
    }
}
