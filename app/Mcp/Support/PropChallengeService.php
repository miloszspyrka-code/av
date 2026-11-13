<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Support;

/**
 * Silnik prop challenge w PHP.
 *
 * Musi być zgodny z public/js/prop.js (normalize + compute). Zmiana reguły
 * wymaga zmiany obu implementacji i testu parytetu (tests/prop-parity.mjs).
 */
final class PropChallengeService
{
    public const DRAWDOWN_TYPES = ['static', 'trailing'];
    public const DAILY_BASES = ['initial', 'dayStart'];
    public const CURRENCIES = ['USD', 'USDT', 'EUR', 'PLN'];

    public const TEMPLATES = [
        ['id' => 'ftmo-2step', 'firm' => 'FTMO', 'label' => 'FTMO · 2-Step', 'phase' => 'Phase 1', 'currency' => 'USD', 'accountSize' => 10000, 'targetPct' => 10, 'dailyLossPct' => 5, 'maxLossPct' => 10, 'drawdownType' => 'static', 'dailyBasis' => 'initial', 'minDays' => 4, 'durationDays' => null],
        ['id' => 'ftmo-1step', 'firm' => 'FTMO', 'label' => 'FTMO · 1-Step', 'phase' => 'Challenge', 'currency' => 'USD', 'accountSize' => 10000, 'targetPct' => 10, 'dailyLossPct' => 3, 'maxLossPct' => 6, 'drawdownType' => 'static', 'dailyBasis' => 'initial', 'minDays' => 4, 'durationDays' => null],
        ['id' => 'ftmo-swing', 'firm' => 'FTMO', 'label' => 'FTMO · Swing', 'phase' => 'Phase 1', 'currency' => 'USD', 'accountSize' => 10000, 'targetPct' => 10, 'dailyLossPct' => 5, 'maxLossPct' => 10, 'drawdownType' => 'static', 'dailyBasis' => 'initial', 'minDays' => 4, 'durationDays' => null],
        ['id' => 'fundednext-stellar', 'firm' => 'FundedNext', 'label' => 'FundedNext · Stellar 2-Step', 'phase' => 'Phase 1', 'currency' => 'USD', 'accountSize' => 10000, 'targetPct' => 8, 'dailyLossPct' => 5, 'maxLossPct' => 10, 'drawdownType' => 'static', 'dailyBasis' => 'initial', 'minDays' => 5, 'durationDays' => 30],
        ['id' => 'fundingpips-2step', 'firm' => 'FundingPips', 'label' => 'FundingPips · 2-Step', 'phase' => 'Phase 1', 'currency' => 'USD', 'accountSize' => 10000, 'targetPct' => 8, 'dailyLossPct' => 5, 'maxLossPct' => 10, 'drawdownType' => 'static', 'dailyBasis' => 'initial', 'minDays' => 5, 'durationDays' => 30],
        ['id' => 'topstep-50k', 'firm' => 'Topstep', 'label' => 'Topstep · 50K Combine', 'phase' => 'Combine', 'currency' => 'USD', 'accountSize' => 50000, 'targetPct' => 6, 'dailyLossPct' => 2, 'maxLossPct' => 4, 'drawdownType' => 'trailing', 'dailyBasis' => 'initial', 'minDays' => 5, 'durationDays' => 30],
        ['id' => 'apex-50k', 'firm' => 'Apex', 'label' => 'Apex · 50K Evaluation', 'phase' => 'Evaluation', 'currency' => 'USD', 'accountSize' => 50000, 'targetPct' => 6, 'dailyLossPct' => 0, 'maxLossPct' => 5, 'drawdownType' => 'trailing', 'dailyBasis' => 'initial', 'minDays' => 7, 'durationDays' => null],
        ['id' => 'breakout-10k', 'firm' => 'Breakout', 'label' => 'Breakout · 10K Challenge', 'phase' => 'Challenge', 'currency' => 'USD', 'accountSize' => 10000, 'targetPct' => 10, 'dailyLossPct' => 5, 'maxLossPct' => 10, 'drawdownType' => 'static', 'dailyBasis' => 'initial', 'minDays' => 5, 'durationDays' => 30],
        ['id' => 'custom', 'firm' => '', 'label' => 'Własne zasady', 'phase' => 'Phase 1', 'currency' => 'USD', 'accountSize' => 10000, 'targetPct' => 10, 'dailyLossPct' => 5, 'maxLossPct' => 10, 'drawdownType' => 'static', 'dailyBasis' => 'initial', 'minDays' => 0, 'durationDays' => 30],
    ];

    public static function templates(): array
    {
        return self::TEMPLATES;
    }

    public static function templateById(string $id): ?array
    {
        foreach (self::TEMPLATES as $template) {
            if ($template['id'] === $id) {
                return $template;
            }
        }

        return null;
    }

    public static function toNum(mixed $value, float $fallback = 0.0): float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : $fallback;
        }
        $raw = str_replace(["\u{00a0}", ' ', '%'], '', (string) ($value ?? ''));
        $raw = str_replace(',', '.', $raw);
        return is_numeric($raw) ? (float) $raw : $fallback;
    }

    private static function clamp(float $value, float $min, float $max): float
    {
        return min($max, max($min, $value));
    }

    public static function parseDay(?string $value, ?\DateTimeImmutable $fallback = null): ?\DateTimeImmutable
    {
        $value = trim((string) $value);
        if ($value !== '' && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) === 1) {
            $date = \DateTimeImmutable::createFromFormat('!Y-n-j', $m[1] . '-' . $m[2] . '-' . $m[3], Dates::tz());
            if ($date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $m[1] . '-' . sprintf('%02d', (int) $m[2]) . '-' . sprintf('%02d', (int) $m[3])) {
                return $date;
            }
            return $fallback;
        }
        if ($value !== '') {
            try {
                return (new \DateTimeImmutable($value))->setTimezone(Dates::tz())->setTime(0, 0);
            } catch (\Throwable) {
                return $fallback;
            }
        }

        return $fallback;
    }

    public static function dateKey(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(Dates::tz())->format('Y-m-d');
    }

    public static function normalize(array $input): array
    {
        $template = self::templateById((string) ($input['templateId'] ?? '')) ?? [];
        $accountSize = max(0.0, self::toNum($input['accountSize'] ?? null, (float) ($template['accountSize'] ?? 10000)));
        $startBalanceRaw = array_key_exists('startBalance', $input) ? self::toNum($input['startBalance'], $accountSize) : $accountSize;
        $startBalance = max(0.0, $startBalanceRaw);

        $startDate = self::parseDay((string) ($input['startDate'] ?? ''), Dates::startOfLocalDay(Dates::todayKey())) ?? Dates::startOfLocalDay(Dates::todayKey());
        $durationDays = array_key_exists('durationDays', $input)
            ? self::toNum($input['durationDays'], 0.0)
            : self::toNum($template['durationDays'] ?? null, 0.0);
        $fallbackEnd = $durationDays > 0 ? $startDate->modify('+' . (int) round($durationDays) . ' days') : null;
        $endDate = array_key_exists('endDate', $input)
            ? self::parseDay((string) $input['endDate'], null)
            : $fallbackEnd;

        $currency = strtoupper((string) ($input['currency'] ?? ''));
        if (!in_array($currency, self::CURRENCIES, true)) {
            $currency = (string) ($template['currency'] ?? 'USD');
        }

        $templateId = self::templateById((string) ($input['templateId'] ?? '')) ? (string) $input['templateId'] : 'custom';

        return [
            'id' => trim((string) ($input['id'] ?? '')) !== '' ? trim((string) $input['id']) : self::uid(),
            'name' => trim((string) ($input['name'] ?? ($template['label'] ?? 'Challenge'))) !== '' ? trim((string) ($input['name'] ?? ($template['label'] ?? 'Challenge'))) : 'Challenge',
            'firm' => trim((string) ($input['firm'] ?? ($template['firm'] ?? ''))),
            'templateId' => $templateId,
            'phase' => trim((string) ($input['phase'] ?? ($template['phase'] ?? 'Phase 1'))) !== '' ? trim((string) ($input['phase'] ?? ($template['phase'] ?? 'Phase 1'))) : 'Phase 1',
            'account' => trim((string) ($input['account'] ?? '')),
            'currency' => $currency,
            'accountSize' => Numbers::round($accountSize, 2),
            'startBalance' => Numbers::round($startBalance > 0 ? $startBalance : $accountSize, 2),
            'targetPct' => Numbers::round(self::clamp(self::toNum($input['targetPct'] ?? null, (float) ($template['targetPct'] ?? 10)), 0, 1000), 2),
            'dailyLossPct' => Numbers::round(self::clamp(self::toNum($input['dailyLossPct'] ?? null, (float) ($template['dailyLossPct'] ?? 5)), 0, 100), 2),
            'maxLossPct' => Numbers::round(self::clamp(self::toNum($input['maxLossPct'] ?? null, (float) ($template['maxLossPct'] ?? 10)), 0, 100), 2),
            'drawdownType' => in_array((string) ($input['drawdownType'] ?? ''), self::DRAWDOWN_TYPES, true) ? (string) $input['drawdownType'] : (string) ($template['drawdownType'] ?? 'static'),
            'dailyBasis' => in_array((string) ($input['dailyBasis'] ?? ''), self::DAILY_BASES, true) ? (string) $input['dailyBasis'] : (string) ($template['dailyBasis'] ?? 'initial'),
            'minDays' => (int) round(self::clamp(self::toNum($input['minDays'] ?? null, (float) ($template['minDays'] ?? 0)), 0, 60)),
            'startDate' => self::dateKey($startDate),
            'endDate' => $endDate instanceof \DateTimeImmutable ? self::dateKey($endDate) : '',
            'notes' => trim((string) ($input['notes'] ?? '')),
            'archived' => (bool) ($input['archived'] ?? false),
            'createdAt' => (string) ($input['createdAt'] ?? gmdate('c')),
        ];
    }

    /**
     * @param list<array> $trades
     * @param list<string>|null $accountNames
     */
    public function compute(array $challenge, array $trades, ?\DateTimeImmutable $now = null, ?array $accountNames = null): array
    {
        $c = self::normalize($challenge);
        $today = ($now ?? new \DateTimeImmutable('now', Dates::tz()))->setTimezone(Dates::tz())->setTime(0, 0);
        $start = self::parseDay($c['startDate'], $today) ?? $today;
        $end = $c['endDate'] !== '' ? self::parseDay($c['endDate'], null) : null;
        $startBalance = $c['startBalance'] > 0 ? $c['startBalance'] : $c['accountSize'];
        $targetAmount = $startBalance * $c['targetPct'] / 100;
        $maxLossAmount = $startBalance * $c['maxLossPct'] / 100;
        $accountKey = strtolower(trim($c['account']));

        $list = [];
        foreach ($trades as $trade) {
            if (!is_array($trade) || ($trade['result'] ?? null) === null) {
                continue;
            }
            $value = (float) $trade['result'];
            if (!is_finite($value)) {
                continue;
            }
            if ($accountKey !== '' && strtolower(trim((string) ($trade['account'] ?? ''))) !== $accountKey) {
                continue;
            }
            try {
                $raw = new \DateTimeImmutable((string) ($trade['date'] ?? ''));
            } catch (\Throwable) {
                continue;
            }
            $day = $raw->setTimezone(Dates::tz())->setTime(0, 0);
            if ($day < $start) {
                continue;
            }
            if ($end instanceof \DateTimeImmutable && $day > $end) {
                continue;
            }
            $trade['_day'] = $day;
            $trade['_value'] = $value;
            $list[] = $trade;
        }
        usort($list, static fn (array $a, array $b): int => $a['_day'] <=> $b['_day']);

        $equity = $startBalance;
        $peak = $startBalance;
        $minEquity = $startBalance;
        $maxDrawdownAmount = 0.0;
        $curve = [['x' => 0, 'date' => $start->format(\DateTimeInterface::ATOM), 'eq' => $startBalance]];
        foreach ($list as $index => $trade) {
            $equity += $trade['_value'];
            $peak = max($peak, $equity);
            $minEquity = min($minEquity, $equity);
            $used = $c['drawdownType'] === 'trailing' ? $peak - $equity : $startBalance - $equity;
            if ($used > $maxDrawdownAmount) {
                $maxDrawdownAmount = $used;
            }
            $curve[] = [
                'x' => $index + 1,
                'date' => $trade['_day']->format(\DateTimeInterface::ATOM),
                'eq' => Numbers::round($equity),
                'result' => Numbers::round($trade['_value']),
                'tradeId' => (string) ($trade['id'] ?? ''),
            ];
        }

        $profitAmount = $equity - $startBalance;
        $targetReached = $targetAmount > 0 && $profitAmount >= $targetAmount;
        $targetProgressPct = $targetAmount > 0 ? $profitAmount / $targetAmount * 100 : 0.0;

        $todayKey = self::dateKey($today);
        $pnlBeforeToday = 0.0;
        $todayPnl = 0.0;
        foreach ($list as $trade) {
            if ($trade['_day'] < $today) {
                $pnlBeforeToday += $trade['_value'];
            }
            if (self::dateKey($trade['_day']) === $todayKey) {
                $todayPnl += $trade['_value'];
            }
        }
        $dayStartBalance = $startBalance + $pnlBeforeToday;
        $basisAmount = $c['dailyBasis'] === 'dayStart' ? max(0.0, $dayStartBalance) : $startBalance;
        $dailyLimitAmount = $basisAmount * $c['dailyLossPct'] / 100;
        $dailyLossAmount = max(0.0, -$todayPnl);
        $dailyUsedPct = $dailyLimitAmount > 0 ? $dailyLossAmount / $dailyLimitAmount * 100 : 0.0;

        $drawdownUsedPct = $maxLossAmount > 0 ? $maxDrawdownAmount / $maxLossAmount * 100 : 0.0;
        $currentDrawdown = max(0.0, $c['drawdownType'] === 'trailing' ? $peak - $equity : $startBalance - $equity);
        $drawdownBuffer = max(0.0, $maxLossAmount - $currentDrawdown);
        $drawdownFloor = ($c['drawdownType'] === 'trailing' ? $peak : $startBalance) - $maxLossAmount;

        $hasDeadline = $end instanceof \DateTimeImmutable;
        $daysLeft = $hasDeadline ? ($today > $end ? 0 : (int) $today->diff($end)->days + 1) : null;
        $daysTotal = $hasDeadline ? max(1, (int) $start->diff($end)->days + 1) : null;
        $expired = $hasDeadline && $today > $end;

        $tradingDays = [];
        foreach ($list as $trade) {
            $tradingDays[self::dateKey($trade['_day'])] = true;
        }
        $tradingDaysCount = count($tradingDays);
        $minDaysMet = $tradingDaysCount >= $c['minDays'];

        $dailyBreached = $dailyLimitAmount > 0 && $dailyLossAmount >= $dailyLimitAmount;
        $drawdownBreached = $maxLossAmount > 0 && $maxDrawdownAmount >= $maxLossAmount;
        $status = 'active';
        if ($drawdownBreached || $dailyBreached) {
            $status = 'failed';
        } elseif ($targetReached) {
            $status = $minDaysMet ? 'passed' : 'target';
        } elseif ($expired) {
            $status = 'expired';
        }

        $riskPct = max($dailyUsedPct, $drawdownUsedPct);
        $riskLevel = 'low';
        if ($status === 'failed' || $dailyBreached || $drawdownBreached) {
            $riskLevel = 'breached';
        } elseif ($riskPct >= 90) {
            $riskLevel = 'critical';
        } elseif ($riskPct >= 75) {
            $riskLevel = 'high';
        } elseif ($riskPct >= 50) {
            $riskLevel = 'medium';
        }

        $wins = array_values(array_filter($list, static fn (array $t): bool => $t['_value'] > 0));
        $losses = array_values(array_filter($list, static fn (array $t): bool => $t['_value'] < 0));
        $winsSum = array_sum(array_map(static fn (array $t): float => $t['_value'], $wins));
        $lossesSum = abs(array_sum(array_map(static fn (array $t): float => $t['_value'], $losses)));
        $rValues = array_values(array_filter(array_map(static fn (array $t): float => (float) ($t['r'] ?? 0), $list), static fn (float $r): bool => is_finite($r)));

        $maxConsecutiveLosses = 0;
        $streak = 0;
        foreach ($list as $trade) {
            if ($trade['_value'] < 0) {
                $streak++;
                $maxConsecutiveLosses = max($maxConsecutiveLosses, $streak);
            } elseif ($trade['_value'] > 0) {
                $streak = 0;
            }
        }

        $dayMap = [];
        foreach ($list as $trade) {
            $key = self::dateKey($trade['_day']);
            $row = $dayMap[$key] ?? ['date' => $key, 'pnl' => 0.0, 'n' => 0, 'wins' => 0];
            $row['pnl'] += $trade['_value'];
            $row['n']++;
            if ($trade['_value'] > 0) {
                $row['wins']++;
            }
            $dayMap[$key] = $row;
        }
        ksort($dayMap);
        $dailyRows = [];
        $running = $startBalance;
        foreach ($dayMap as $row) {
            $running += $row['pnl'];
            $dailyRows[] = [
                'date' => $row['date'],
                'pnl' => Numbers::round($row['pnl']),
                'n' => $row['n'],
                'wins' => $row['wins'],
                'equity' => Numbers::round($running),
            ];
        }
        $sortedDays = $dailyRows;
        usort($sortedDays, static fn (array $a, array $b): int => $a['pnl'] <=> $b['pnl']);
        $bestDay = $sortedDays !== [] ? $sortedDays[count($sortedDays) - 1] : null;
        $worstDay = $sortedDays !== [] ? $sortedDays[0] : null;

        $accountMissing = $accountKey !== '' && is_array($accountNames) && $accountNames !== []
            && !in_array($accountKey, array_map(static fn (string $name): string => strtolower(trim($name)), $accountNames), true);

        $alerts = $this->alerts($c, $dailyLimitAmount, $dailyLossAmount, $dailyUsedPct, $maxLossAmount, $maxDrawdownAmount, $drawdownUsedPct, $drawdownBuffer, $targetReached, $status, $profitAmount, $targetAmount, $tradingDaysCount, $expired, $daysLeft);

        return [
            'challenge' => $c,
            'currency' => $c['currency'],
            'startBalance' => Numbers::round($startBalance),
            'balance' => Numbers::round($equity),
            'profitAmount' => Numbers::round($profitAmount),
            'targetPct' => $c['targetPct'],
            'targetAmount' => Numbers::round($targetAmount),
            'targetProgressPct' => Numbers::round($targetProgressPct),
            'targetRemaining' => Numbers::round(max(0.0, $targetAmount - $profitAmount)),
            'targetReached' => $targetReached,
            'dailyLossPct' => $c['dailyLossPct'],
            'dailyLimitAmount' => Numbers::round($dailyLimitAmount),
            'dailyLossAmount' => Numbers::round($dailyLossAmount),
            'dailyUsedPct' => Numbers::round($dailyUsedPct),
            'dailyBuffer' => Numbers::round(max(0.0, $dailyLimitAmount - $dailyLossAmount)),
            'todayPnl' => Numbers::round($todayPnl),
            'dayStartBalance' => Numbers::round($dayStartBalance),
            'dailyBasisAmount' => Numbers::round($basisAmount),
            'maxLossPct' => $c['maxLossPct'],
            'maxLossAmount' => Numbers::round($maxLossAmount),
            'maxDrawdownAmount' => Numbers::round($maxDrawdownAmount),
            'drawdownUsedPct' => Numbers::round($drawdownUsedPct),
            'drawdownBuffer' => Numbers::round($drawdownBuffer),
            'drawdownFloor' => Numbers::round($drawdownFloor),
            'peak' => Numbers::round($peak),
            'minEquity' => Numbers::round($minEquity),
            'hasDeadline' => $hasDeadline,
            'daysLeft' => $daysLeft,
            'daysTotal' => $daysTotal,
            'expired' => $expired,
            'tradingDays' => $tradingDaysCount,
            'minDays' => $c['minDays'],
            'minDaysMet' => $minDaysMet,
            'status' => $status,
            'riskLevel' => $riskLevel,
            'riskPct' => Numbers::round($riskPct),
            'alerts' => $alerts,
            'n' => count($list),
            'wins' => count($wins),
            'losses' => count($losses),
            'winRate' => $list !== [] ? Numbers::round(count($wins) / count($list) * 100) : 0.0,
            'pf' => $lossesSum > 0 ? Numbers::round($winsSum / $lossesSum) : ($winsSum > 0 ? 99.0 : 0.0),
            'net' => Numbers::round($profitAmount),
            'avgR' => $rValues !== [] ? Numbers::round(array_sum($rValues) / count($rValues)) : 0.0,
            'maxConsecutiveLosses' => $maxConsecutiveLosses,
            'bestDay' => $bestDay,
            'worstDay' => $worstDay,
            'dailyRows' => $dailyRows,
            'curve' => $curve,
            'accountMissing' => $accountMissing,
            'startDate' => $c['startDate'],
            'endDate' => $c['endDate'],
            'todayKey' => $todayKey,
        ];
    }

    private function alerts(
        array $c,
        float $dailyLimitAmount,
        float $dailyLossAmount,
        float $dailyUsedPct,
        float $maxLossAmount,
        float $maxDrawdownAmount,
        float $drawdownUsedPct,
        float $drawdownBuffer,
        bool $targetReached,
        string $status,
        float $profitAmount,
        float $targetAmount,
        int $tradingDays,
        bool $expired,
        ?int $daysLeft
    ): array {
        $alerts = [];
        $currency = $c['currency'];
        if ($dailyLimitAmount > 0) {
            if ($dailyUsedPct >= 100) {
                $alerts[] = ['level' => 'danger', 'code' => 'daily-breach', 'title' => 'Dzienny limit straty przekroczony', 'text' => 'Dziś wykorzystano ' . Numbers::round($dailyUsedPct, 1) . '% dziennego limitu.'];
            } elseif ($dailyUsedPct >= 90) {
                $alerts[] = ['level' => 'danger', 'code' => 'daily-90', 'title' => 'Prawie dzienny limit', 'text' => 'Zostało ' . Numbers::round(max(0.0, $dailyLimitAmount - $dailyLossAmount)) . ' ' . $currency . '.'];
            } elseif ($dailyUsedPct >= 75) {
                $alerts[] = ['level' => 'warn', 'code' => 'daily-75', 'title' => 'Dzienny limit blisko', 'text' => 'Wykorzystano ' . Numbers::round($dailyUsedPct, 1) . '% dziennego limitu.'];
            }
        }
        if ($maxLossAmount > 0) {
            if ($drawdownUsedPct >= 100) {
                $alerts[] = ['level' => 'danger', 'code' => 'dd-breach', 'title' => 'Maksymalne obsunięcie przekroczone', 'text' => 'Limit ' . Numbers::round($maxLossAmount) . ' ' . $currency . '.'];
            } elseif ($drawdownUsedPct >= 90) {
                $alerts[] = ['level' => 'danger', 'code' => 'dd-90', 'title' => 'Prawie maksymalne obsunięcie', 'text' => 'Bufor: ' . Numbers::round($drawdownBuffer) . ' ' . $currency . '.'];
            } elseif ($drawdownUsedPct >= 75) {
                $alerts[] = ['level' => 'warn', 'code' => 'dd-75', 'title' => 'Obsunięcie blisko limitu', 'text' => 'Wykorzystano ' . Numbers::round($drawdownUsedPct, 1) . '% limitu obsunięcia.'];
            }
        }
        if ($targetReached && $status === 'passed') {
            $alerts[] = ['level' => 'info', 'code' => 'target', 'title' => 'Cel zysku osiągnięty', 'text' => 'Wynik ' . Numbers::round($profitAmount) . ' ' . $currency . ' przy celu ' . Numbers::round($targetAmount) . ' ' . $currency . '.'];
        } elseif ($targetReached && $status === 'target') {
            $alerts[] = ['level' => 'warn', 'code' => 'mindays', 'title' => 'Cel osiągnięty, brakuje dni handlowych', 'text' => 'Masz ' . $tradingDays . ' z ' . $c['minDays'] . ' wymaganych dni.'];
        }
        if ($expired && $status === 'expired') {
            $alerts[] = ['level' => 'warn', 'code' => 'expired', 'title' => 'Czas challenge\'u minął', 'text' => 'Termin upłynął, a cel nie został osiągnięty.'];
        }
        if (!$expired && $daysLeft !== null && $daysLeft <= 3 && $status === 'active') {
            $alerts[] = ['level' => 'warn', 'code' => 'days', 'title' => 'Kończy się czas', 'text' => 'Zostało ' . $daysLeft . ' dni do końca challenge\'u.'];
        }
        if (!$expired && $daysLeft !== null && $status === 'active' && $c['minDays'] > $tradingDays && ($c['minDays'] - $tradingDays) > $daysLeft) {
            $alerts[] = ['level' => 'warn', 'code' => 'mindays-deadline', 'title' => 'Nie zdążysz z min. dniami', 'text' => 'Brakuje ' . ($c['minDays'] - $tradingDays) . ' dni handlowych.'];
        }
        if ($alerts === []) {
            $alerts[] = ['level' => 'ok', 'code' => 'ok', 'title' => 'Limity w normie', 'text' => 'Challenge nie zbliża się do żadnego limitu.'];
        }
        $order = ['danger' => 0, 'warn' => 1, 'info' => 2, 'ok' => 3];
        usort($alerts, static fn (array $a, array $b): int => ($order[$a['level']] ?? 9) <=> ($order[$b['level']] ?? 9));

        return $alerts;
    }

    private static function uid(): string
    {
        return 'prop_' . bin2hex(random_bytes(6));
    }
}
