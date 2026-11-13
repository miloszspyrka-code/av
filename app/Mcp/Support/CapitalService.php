<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Support;

final class CapitalService
{
    public function accounts(array $state): array
    {
        $items = [];
        foreach ((array) ($state['accounts'] ?? []) as $account) {
            if (!is_array($account)) {
                continue;
            }
            $name = trim((string) ($account['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $currency = strtoupper((string) ($account['currency'] ?? 'USDT'));
            $start = Numbers::round((float) ($account['start'] ?? 0));
            $balance = Numbers::round((float) ($account['balance'] ?? 0));
            $items[] = [
                'id' => (string) ($account['id'] ?? ''),
                'name' => $name,
                'type' => (string) ($account['type'] ?? ''),
                'exchange' => (string) ($account['exchange'] ?? ''),
                'currency' => $currency,
                'start' => $start,
                'balance' => $balance,
                'startUsdt' => Numbers::round($start * Numbers::fxToUsdt($currency)),
                'balanceUsdt' => Numbers::round($balance * Numbers::fxToUsdt($currency)),
                'include' => ($account['include'] ?? true) !== false,
                'primary' => (bool) ($account['primary'] ?? false),
                'leverage' => (string) ($account['lev'] ?? ''),
                'goal' => (string) ($account['goal'] ?? ''),
                'date' => (string) ($account['date'] ?? ''),
            ];
        }

        return $items;
    }

    public function summary(array $state): array
    {
        $accounts = $this->accounts($state);
        $included = array_values(array_filter($accounts, static fn (array $a): bool => $a['include']));
        $byCurrency = [];
        $startUsdt = 0.0;
        $balanceUsdt = 0.0;
        foreach ($included as $account) {
            $currency = $account['currency'];
            if (!isset($byCurrency[$currency])) {
                $byCurrency[$currency] = ['currency' => $currency, 'accounts' => 0, 'start' => 0.0, 'balance' => 0.0];
            }
            $byCurrency[$currency]['accounts']++;
            $byCurrency[$currency]['start'] += $account['start'];
            $byCurrency[$currency]['balance'] += $account['balance'];
            $startUsdt += $account['startUsdt'];
            $balanceUsdt += $account['balanceUsdt'];
        }
        foreach ($byCurrency as &$row) {
            $row['start'] = Numbers::round($row['start']);
            $row['balance'] = Numbers::round($row['balance']);
            $row['startUsdt'] = Numbers::round($row['start'] * Numbers::fxToUsdt($row['currency']));
            $row['balanceUsdt'] = Numbers::round($row['balance'] * Numbers::fxToUsdt($row['currency']));
        }
        unset($row);

        $flows = [];
        $deposits = 0.0;
        $withdrawals = 0.0;
        foreach ((array) ($state['flows'] ?? []) as $flow) {
            if (!is_array($flow)) {
                continue;
            }
            $amount = (float) ($flow['amount'] ?? 0);
            $isDeposit = (string) ($flow['type'] ?? '') === 'Wpłata';
            if ($isDeposit) {
                $deposits += $amount;
            } else {
                $withdrawals += $amount;
            }
            $flows[] = [
                'id' => (string) ($flow['id'] ?? ''),
                'date' => (string) ($flow['date'] ?? ''),
                'account' => (string) ($flow['account'] ?? ''),
                'type' => (string) ($flow['type'] ?? ''),
                'amount' => Numbers::round($amount),
                'currency' => (string) ($flow['currency'] ?? ''),
                'fiat' => Numbers::round((float) ($flow['fiat'] ?? 0)),
                'fx' => (float) ($flow['fx'] ?? 1),
                'description' => (string) ($flow['op'] ?? ''),
            ];
        }
        usort($flows, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']));

        return [
            'currency' => 'USDT',
            'fxApproximate' => true,
            'fxNote' => 'Przeliczenia USDT są orientacyjne (stałe kursy w aplikacji); nie są kursem rynkowym.',
            'accounts' => $accounts,
            'includedCount' => count($included),
            'startCapital' => Numbers::round($startUsdt),
            'capital' => Numbers::round($balanceUsdt),
            'byCurrency' => array_values($byCurrency),
            'flows' => $flows,
            'flowTotals' => [
                'deposits' => Numbers::round($deposits),
                'withdrawals' => Numbers::round($withdrawals),
                'net' => Numbers::round($deposits - $withdrawals),
            ],
        ];
    }
}
