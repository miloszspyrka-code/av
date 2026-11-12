<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Tools;

final class Schemas
{
    public static function object(array $properties = [], array $required = []): array
    {
        $schema = [
            'type' => 'object',
            'properties' => $properties === [] ? new \stdClass() : $properties,
            'additionalProperties' => true,
        ];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    public static function nullable(array $schema): array
    {
        return ['anyOf' => [$schema, ['type' => 'null']]];
    }

    public static function inputProfile(): array
    {
        return self::object();
    }

    public static function inputDashboard(): array
    {
        return self::object();
    }

    public static function inputListTrades(): array
    {
        return self::object([
            'status' => ['type' => 'string', 'enum' => ['all', 'open', 'closed'], 'default' => 'all'],
            'pair' => ['type' => 'string', 'maxLength' => 32],
            'setup' => ['type' => 'string', 'maxLength' => 100],
            'grade' => ['type' => 'string', 'enum' => ['A', 'B', 'C']],
            'from' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
            'to' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'default' => 0],
        ]);
    }

    public static function inputGetTrade(): array
    {
        return self::object([
            'id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
        ], ['id']);
    }

    public static function inputStats(): array
    {
        return self::object([
            'period' => ['type' => 'string', 'enum' => ['7d', '30d', '90d', 'all'], 'default' => '30d'],
        ]);
    }

    public static function inputEvents(): array
    {
        return self::object([
            'category' => ['type' => 'string', 'enum' => ['all', 'live', 'consultation', 'unassigned'], 'default' => 'all'],
            'from' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
            'to' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
        ]);
    }

    public static function inputDayPlan(): array
    {
        return self::object([
            'date' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
        ]);
    }

    public static function inputSetups(): array
    {
        return self::object();
    }

    public static function outputProfile(): array
    {
        return self::object([
            'id' => ['type' => 'string'],
            'name' => ['type' => 'string'],
            'nickname' => ['type' => 'string'],
            'plan' => ['type' => 'string'],
            'accessUntil' => self::nullable(['type' => 'string']),
            'expired' => ['type' => 'boolean'],
            'roleVerified' => ['type' => 'boolean'],
            'isDemo' => ['type' => 'boolean'],
            'isAdmin' => ['type' => 'boolean'],
        ], ['id', 'name', 'plan', 'expired', 'roleVerified', 'isAdmin']);
    }

    public static function outputDashboard(): array
    {
        return self::object([
            'asOf' => ['type' => 'string'],
            'date' => ['type' => 'string'],
            'today' => self::object([
                'pnl' => ['type' => 'number'],
                'trades' => ['type' => 'integer'],
                'closedTrades' => ['type' => 'integer'],
                'openPositions' => ['type' => 'integer'],
                'losses' => ['type' => 'integer'],
                'riskUsed' => ['type' => 'number'],
            ]),
            'plan' => self::nullable(['type' => 'object']),
            'readiness' => ['type' => 'object'],
            'protection' => ['type' => 'object'],
            'capital' => ['type' => 'object'],
            'nextEvent' => self::nullable(['type' => 'object']),
            'sync' => self::nullable(['type' => 'object']),
        ], ['asOf', 'date', 'today', 'plan', 'readiness', 'protection', 'capital', 'nextEvent', 'sync']);
    }

    public static function outputTradeList(): array
    {
        return self::object([
            'items' => ['type' => 'array', 'items' => ['type' => 'object']],
            'total' => ['type' => 'integer'],
            'nextOffset' => self::nullable(['type' => 'integer']),
        ], ['items', 'total', 'nextOffset']);
    }

    public static function outputTrade(): array
    {
        return self::object([
            'found' => ['type' => 'boolean'],
            'trade' => self::nullable(['type' => 'object']),
        ], ['found', 'trade']);
    }

    public static function outputStats(): array
    {
        return self::object([
            'period' => ['type' => 'string'],
            'from' => self::nullable(['type' => 'string']),
            'to' => ['type' => 'string'],
            'trades' => ['type' => 'integer'],
            'wins' => ['type' => 'integer'],
            'losses' => ['type' => 'integer'],
            'winRate' => ['type' => 'number'],
            'net' => ['type' => 'number'],
            'grossProfit' => ['type' => 'number'],
            'grossLoss' => ['type' => 'number'],
            'profitFactor' => ['type' => 'number'],
            'expectancyR' => ['type' => 'number'],
            'avgWin' => ['type' => 'number'],
            'avgLoss' => ['type' => 'number'],
            'maxDrawdown' => ['type' => 'number'],
            'maxDrawdownPct' => ['type' => 'number'],
            'fees' => ['type' => 'number'],
            'withStopLoss' => ['type' => 'number'],
            'planAdherence' => ['type' => 'number'],
            'byGrade' => ['type' => 'array', 'items' => ['type' => 'object']],
            'bySetup' => ['type' => 'array', 'items' => ['type' => 'object']],
            'fullChecklist' => ['type' => 'object'],
            'partialChecklist' => ['type' => 'object'],
            'currency' => ['type' => 'string'],
        ], ['period', 'trades', 'net', 'winRate', 'currency']);
    }

    public static function outputEvents(): array
    {
        return self::object([
            'events' => ['type' => 'array', 'items' => ['type' => 'object']],
            'total' => ['type' => 'integer'],
            'sync' => self::nullable(['type' => 'object']),
        ], ['events', 'total', 'sync']);
    }

    public static function outputDayPlan(): array
    {
        return self::object([
            'date' => ['type' => 'string'],
            'plan' => self::nullable(['type' => 'object']),
        ], ['date', 'plan']);
    }

    public static function outputSetups(): array
    {
        return self::object([
            'items' => ['type' => 'array', 'items' => ['type' => 'object']],
            'total' => ['type' => 'integer'],
        ], ['items', 'total']);
    }
}
