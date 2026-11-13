<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Tools;

final class Schemas
{
    public static function object(array $properties = [], array $required = [], bool $additionalProperties = true): array
    {
        $schema = [
            'type' => 'object',
            'properties' => $properties === [] ? new \stdClass() : $properties,
            'additionalProperties' => $additionalProperties,
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

    private static function idem(): array
    {
        return ['type' => 'string', 'minLength' => 8, 'maxLength' => 120];
    }

    private static function input(array $properties = [], array $required = []): array
    {
        return self::object($properties, $required, false);
    }

    // ------------------------------------------------------------------
    // Odczyt
    // ------------------------------------------------------------------

    public static function inputProfile(): array { return self::input(); }
    public static function inputDashboard(): array { return self::input(); }

    public static function inputListTrades(): array
    {
        return self::input([
            'status' => ['type' => 'string', 'enum' => ['all', 'open', 'closed', 'win', 'loss'], 'default' => 'all'],
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
        return self::input(['id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128]], ['id']);
    }

    public static function inputStats(): array
    {
        return self::input(['period' => ['type' => 'string', 'enum' => ['7d', '30d', '90d', 'all'], 'default' => '30d']]);
    }

    public static function inputEvents(): array
    {
        return self::input([
            'category' => ['type' => 'string', 'enum' => ['all', 'live', 'consultation', 'unassigned'], 'default' => 'all'],
            'from' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
            'to' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
        ]);
    }

    public static function inputDayPlan(): array
    {
        return self::input(['date' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$']]);
    }

    public static function inputSetups(): array { return self::input(); }

    public static function inputMacroCalendar(): array
    {
        return self::input([
            'from' => ['type' => 'string', 'maxLength' => 40],
            'to' => ['type' => 'string', 'maxLength' => 40],
            'currency' => ['type' => 'string', 'pattern' => '^[A-Za-z]{2,8}$'],
            'impact' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'default' => 0],
        ]);
    }

    public static function inputPropChallenges(): array { return self::input(); }

    public static function inputCheckinHistory(): array
    {
        return self::input([
            'from' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
            'to' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'default' => 0],
        ]);
    }

    public static function inputAccounts(): array { return self::input(); }
    public static function inputCapitalSummary(): array { return self::input(); }
    public static function inputConnectionStatus(): array { return self::input(); }

    public static function inputTradeContext(): array
    {
        return self::input(['tradeId' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128]], ['tradeId']);
    }

    // ------------------------------------------------------------------
    // Propozycje
    // ------------------------------------------------------------------

    public static function inputProposeCreateTrade(): array
    {
        return self::input([
            'pair' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9._/-]{2,20}$'],
            'dir' => ['type' => 'string', 'enum' => ['long', 'short', 'l', 's']],
            'entry' => ['type' => 'number', 'exclusiveMinimum' => 0],
            'size' => ['type' => 'number', 'exclusiveMinimum' => 0],
            'sl' => ['type' => 'number', 'exclusiveMinimum' => 0],
            'tp' => ['type' => 'number', 'exclusiveMinimum' => 0],
            'setup' => ['type' => 'string', 'maxLength' => 100],
            'emotion' => ['type' => 'string', 'maxLength' => 30],
            'grade' => ['type' => 'string', 'enum' => ['A', 'B', 'C']],
            'checklist' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 20],
            'checkTotal' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
            'errors' => ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'string', 'maxLength' => 60]],
            'note' => ['type' => 'string', 'maxLength' => 2000],
            'date' => ['type' => 'string', 'maxLength' => 40],
            'account' => ['type' => 'string', 'maxLength' => 100],
            'idempotencyKey' => self::idem(),
        ], ['pair', 'dir', 'entry', 'size', 'idempotencyKey']);
    }

    public static function inputProposeCloseTrade(): array
    {
        return self::input([
            'tradeId' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
            'exitPrice' => ['type' => 'number', 'exclusiveMinimum' => 0],
            'idempotencyKey' => self::idem(),
        ], ['tradeId', 'exitPrice', 'idempotencyKey']);
    }

    public static function inputProposeTradeReview(): array
    {
        return self::input([
            'tradeId' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
            'note' => ['type' => 'string', 'maxLength' => 2000],
            'grade' => ['type' => 'string', 'enum' => ['A', 'B', 'C']],
            'setup' => ['type' => 'string', 'maxLength' => 100],
            'emotion' => ['type' => 'string', 'maxLength' => 30],
            'checklist' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 20],
            'errors' => ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'string', 'maxLength' => 60]],
            'idempotencyKey' => self::idem(),
        ], ['tradeId', 'idempotencyKey']);
    }

    public static function inputProposeDayPlan(): array
    {
        return self::input([
            'date' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
            'market' => ['type' => 'string', 'maxLength' => 20],
            'bias' => ['type' => 'string', 'enum' => ['long', 'short', 'neutral', 'none']],
            'why' => ['type' => 'string', 'maxLength' => 2000],
            'levels' => [
                'type' => 'array', 'maxItems' => 10,
                'items' => self::input([
                    'type' => ['type' => 'string', 'maxLength' => 20],
                    'price' => ['type' => 'string', 'maxLength' => 30],
                    'note' => ['type' => 'string', 'maxLength' => 200],
                ]),
            ],
            'ifMain' => ['type' => 'string', 'maxLength' => 2000],
            'thenMain' => ['type' => 'string', 'maxLength' => 2000],
            'ifAlt' => ['type' => 'string', 'maxLength' => 2000],
            'thenAlt' => ['type' => 'string', 'maxLength' => 2000],
            'riskPercent' => ['type' => 'number', 'minimum' => 0.1, 'maximum' => 100],
            'maxTrades' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
            'until' => ['type' => 'string', 'pattern' => '^([01]\\d|2[0-3]):[0-5]\\d$'],
            'notDo' => ['type' => 'string', 'maxLength' => 2000],
            'idempotencyKey' => self::idem(),
        ], ['idempotencyKey']);
    }

    public static function inputProposeCheckin(): array
    {
        return self::input([
            'mood' => ['type' => 'string', 'maxLength' => 30],
            'energy' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
            'note' => ['type' => 'string', 'maxLength' => 500],
            'idempotencyKey' => self::idem(),
        ], ['mood', 'energy', 'idempotencyKey']);
    }

    public static function inputProposeSetup(): array
    {
        return self::input([
            'id' => ['type' => 'string', 'maxLength' => 64],
            'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 80],
            'tf' => ['type' => 'string', 'maxLength' => 40],
            'grade' => ['type' => 'string', 'enum' => ['A', 'B', 'C']],
            'rules' => ['type' => 'array', 'minItems' => 2, 'maxItems' => 5, 'items' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 200]],
            'bad' => ['type' => 'boolean'],
            'seed' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 9999],
            'idempotencyKey' => self::idem(),
        ], ['name', 'rules', 'idempotencyKey']);
    }

    public static function inputProposePropChallenge(): array
    {
        return self::input([
            'id' => ['type' => 'string', 'maxLength' => 64],
            'name' => ['type' => 'string', 'maxLength' => 100],
            'firm' => ['type' => 'string', 'maxLength' => 100],
            'templateId' => ['type' => 'string', 'maxLength' => 40],
            'phase' => ['type' => 'string', 'maxLength' => 50],
            'account' => ['type' => 'string', 'maxLength' => 100],
            'currency' => ['type' => 'string', 'enum' => ['USD', 'USDT', 'EUR', 'PLN']],
            'accountSize' => ['type' => 'number', 'exclusiveMinimum' => 0],
            'startBalance' => ['type' => 'number', 'exclusiveMinimum' => 0],
            'targetPct' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1000],
            'dailyLossPct' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
            'maxLossPct' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
            'drawdownType' => ['type' => 'string', 'enum' => ['static', 'trailing']],
            'dailyBasis' => ['type' => 'string', 'enum' => ['initial', 'dayStart']],
            'minDays' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 60],
            'startDate' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
            'endDate' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
            'notes' => ['type' => 'string', 'maxLength' => 2000],
            'idempotencyKey' => self::idem(),
        ], ['idempotencyKey']);
    }

    // ------------------------------------------------------------------
    // Wyjście — schematy permissive, żeby SDK nie odrzucało nowych pól.
    // ------------------------------------------------------------------

    public static function outputProfile(): array
    {
        return self::object([
            'id' => ['type' => 'string'], 'name' => ['type' => 'string'], 'nickname' => ['type' => 'string'],
            'plan' => ['type' => 'string'], 'accessUntil' => self::nullable(['type' => 'string']),
            'expired' => ['type' => 'boolean'], 'roleVerified' => ['type' => 'boolean'], 'isDemo' => ['type' => 'boolean'], 'isAdmin' => ['type' => 'boolean'],
        ]);
    }

    public static function outputDashboard(): array { return self::object(); }
    public static function outputTradeList(): array { return self::object(); }
    public static function outputTrade(): array { return self::object(); }
    public static function outputStats(): array { return self::object(); }
    public static function outputEvents(): array { return self::object(); }
    public static function outputDayPlan(): array { return self::object(); }
    public static function outputSetups(): array { return self::object(); }
    public static function outputMacro(): array { return self::object(); }
    public static function outputPropChallenges(): array { return self::object(); }
    public static function outputCheckins(): array { return self::object(); }
    public static function outputAccounts(): array { return self::object(); }
    public static function outputCapital(): array { return self::object(); }
    public static function outputConnections(): array { return self::object(); }
    public static function outputTradeContext(): array { return self::object(); }

    public static function outputProposal(): array
    {
        return self::object([
            'proposalId' => ['type' => 'string'],
            'status' => ['type' => 'string'],
            'kind' => ['type' => 'string'],
            'target' => ['type' => 'string'],
            'expiresAt' => ['type' => 'string'],
            'duplicate' => ['type' => 'boolean'],
            'note' => ['type' => 'string'],
        ]);
    }
}
