<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Support;

final class TradeContextService
{
    public function __construct(private readonly TradeService $trades)
    {
    }

    public function context(array $state, string $tradeId): ?array
    {
        $trade = null;
        foreach ((array) ($state['trades'] ?? []) as $candidate) {
            if (is_array($candidate) && (string) ($candidate['id'] ?? '') === $tradeId) {
                $trade = $candidate;
                break;
            }
        }
        if ($trade === null) {
            return null;
        }

        $setupName = (string) ($trade['setup'] ?? '');
        $setup = null;
        foreach ((array) ($state['setups'] ?? []) as $candidate) {
            if (is_array($candidate) && (string) ($candidate['name'] ?? '') === $setupName) {
                $setup = [
                    'id' => (string) ($candidate['id'] ?? ''),
                    'name' => $setupName,
                    'timeframe' => $candidate['tf'] ?? null,
                    'grade' => $candidate['grade'] ?? null,
                    'rules' => array_values(array_map('strval', is_array($candidate['rules'] ?? null) ? $candidate['rules'] : [])),
                    'avoid' => (bool) ($candidate['bad'] ?? false),
                ];
                break;
            }
        }

        $dateKey = Dates::dateKey((string) ($trade['date'] ?? ''));
        $plan = $dateKey !== null ? ($state['plans'][$dateKey] ?? null) : null;
        $plan = is_array($plan) ? $plan : null;
        $planApproved = $plan !== null && !empty($plan['approvedAt']);

        return [
            'trade' => $this->trades->sanitize($trade, true),
            'review' => $state['tradeReviews'][$tradeId] ?? null,
            'setup' => $setup,
            'setupStats' => $setupName !== '' ? $this->trades->setupStats($state, $setupName) : null,
            'plan' => $plan,
            'planMatch' => [
                'approved' => $planApproved,
                'setupInPlaybook' => $setup !== null,
                'tradeMarkedPlan' => (bool) ($trade['plan'] ?? false),
                'aligned' => $planApproved && $setup !== null && (bool) ($trade['plan'] ?? false),
            ],
            'date' => $dateKey,
        ];
    }
}
