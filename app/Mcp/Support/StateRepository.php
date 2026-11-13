<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Support;

use AlphaVault\Db;

final class StateRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function load(int $userId): array
    {
        $row = $this->db->queryOne('SELECT state, revision, updated_at FROM user_states WHERE user_id = ?', [$userId]);
        if ($row === null) {
            return ['state' => null, 'revision' => 0, 'updatedAt' => null];
        }
        $state = json_decode((string) $row['state'], true);

        return [
            'state' => is_array($state) ? $state : null,
            'revision' => (int) $row['revision'],
            'updatedAt' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
        ];
    }

    public static function emptyState(): array
    {
        return [
            'version' => 2,
            'user' => [],
            'subscription' => [],
            'settings' => [
                'remember' => true,
                'apiCode' => true,
                'safetyEnabled' => true,
                'requireReason' => true,
                'pushLimit' => true,
                'macroBefore' => true,
                'macroChecklist' => true,
                'macroTwo' => true,
                'remindDay' => true,
                'remindHour' => true,
                'greeting' => true,
                'maxLosses' => 2,
                'maxDailyLoss' => 3,
                'block' => 'midnight',
                'maxRisk' => 1,
                'rules' => [],
                'checklist' => [],
                'devices' => [],
                'connections' => [],
            ],
            'health' => [
                'sleepH' => 0,
                'sleepBase' => 0,
                'hrv' => 0,
                'hrvBase' => 52,
                'hr' => 56,
                'hrBase' => 56,
                'battery' => 0,
            ],
            'plans' => [],
            'trades' => [],
            'setups' => [],
            'accounts' => [],
            'flows' => [],
            'tax' => ['carryLoss' => 0, 'year' => (int) gmdate('Y')],
            'checkins' => [],
            'chat' => [],
            'overrides' => [],
            'meta' => [],
        ];
    }
}
