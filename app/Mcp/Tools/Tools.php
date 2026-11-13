<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Tools;

use AlphaVault\Auth;
use AlphaVault\Config;
use AlphaVault\Db;
use AlphaVault\Mcp\Proposals\ProposalBuilder;
use AlphaVault\Mcp\Proposals\ProposalException;
use AlphaVault\Mcp\Proposals\ProposalService;
use AlphaVault\Mcp\Support\CapitalService;
use AlphaVault\Mcp\Support\ConnectionsService;
use AlphaVault\Mcp\Support\DashboardService;
use AlphaVault\Mcp\Support\Dates;
use AlphaVault\Mcp\Support\EventsService;
use AlphaVault\Mcp\Support\MacroService;
use AlphaVault\Mcp\Support\ProfileService;
use AlphaVault\Mcp\Support\PropChallengeService;
use AlphaVault\Mcp\Support\StateRepository;
use AlphaVault\Mcp\Support\StatsService;
use AlphaVault\Mcp\Support\TradeContextService;
use AlphaVault\Mcp\Support\TradeService;
use Mcp\Server\Exception\ToolCallException;
use Mcp\Server\RequestContext;

final class Tools
{
    private ProfileService $profiles;
    private StateRepository $states;
    private TradeService $trades;
    private StatsService $stats;
    private EventsService $events;
    private DashboardService $dashboard;
    private MacroService $macro;
    private CapitalService $capital;
    private ConnectionsService $connections;
    private TradeContextService $tradeContext;
    private PropChallengeService $prop;
    private ProposalBuilder $builder;
    private bool $writesEnabled;

    public function __construct(Config $config, Db $db, Auth $auth)
    {
        $this->writesEnabled = (bool) $config->mcp['writesEnabled'];
        $this->profiles = new ProfileService($config, $db, $auth);
        $this->states = new StateRepository($db);
        $this->trades = new TradeService();
        $this->stats = new StatsService($this->trades);
        $this->events = new EventsService($config, $db);
        $this->dashboard = new DashboardService($this->trades, $this->stats, $this->events);
        $this->macro = new MacroService($db);
        $this->capital = new CapitalService();
        $this->connections = new ConnectionsService($db);
        $this->tradeContext = new TradeContextService($this->trades);
        $this->prop = new PropChallengeService();
        $proposals = new ProposalService($db, $config);
        $this->builder = new ProposalBuilder($db, $proposals);
    }

    // ------------------------------------------------------------------
    // Odczyt
    // ------------------------------------------------------------------

    public function getProfile(RequestContext $context): array
    {
        return $this->profiles->profile($this->user($context));
    }

    public function getDashboard(RequestContext $context): array
    {
        return $this->dashboard->dashboard($this->state($context));
    }

    public function listTrades(RequestContext $context, string $status = 'all', ?string $pair = null, ?string $setup = null, ?string $grade = null, ?string $from = null, ?string $to = null, int $limit = 20, int $offset = 0): array
    {
        if (!in_array($status, ['all', 'open', 'closed', 'win', 'loss'], true)) {
            throw new ToolCallException('status musi być all, open, closed, win albo loss.');
        }
        if ($grade !== null && !in_array(strtoupper($grade), ['A', 'B', 'C'], true)) {
            throw new ToolCallException('grade musi być A, B albo C.');
        }
        try {
            return $this->trades->list($this->state($context), [
                'status' => $status,
                'pair' => $pair,
                'setup' => $setup,
                'grade' => $grade,
                'from' => $from,
                'to' => $to,
                'limit' => $limit,
                'offset' => $offset,
            ]);
        } catch (\InvalidArgumentException $err) {
            throw new ToolCallException($err->getMessage());
        }
    }

    public function getTrade(RequestContext $context, string $id): array
    {
        if (trim($id) === '') {
            throw new ToolCallException('id nie może być pusty.');
        }
        $trade = $this->trades->find($this->state($context), $id);

        return ['found' => $trade !== null, 'trade' => $trade];
    }

    public function getStats(RequestContext $context, string $period = '30d'): array
    {
        try {
            return $this->stats->compute($this->state($context), $period);
        } catch (\InvalidArgumentException $err) {
            throw new ToolCallException($err->getMessage());
        }
    }

    public function getEvents(RequestContext $context, string $category = 'all', ?string $from = null, ?string $to = null, int $limit = 20): array
    {
        try {
            return $this->events->list([
                'category' => $category,
                'from' => $from,
                'to' => $to,
                'limit' => $limit,
            ]);
        } catch (\InvalidArgumentException $err) {
            throw new ToolCallException($err->getMessage());
        }
    }

    public function getDayPlan(RequestContext $context, ?string $date = null): array
    {
        $date = $date ?? Dates::todayKey();
        if (!Dates::isValidKey($date)) {
            throw new ToolCallException('date musi mieć format YYYY-MM-DD.');
        }
        $state = $this->state($context);
        $plan = $state['plans'][$date] ?? null;

        return ['date' => $date, 'plan' => is_array($plan) ? $plan : null];
    }

    public function listSetups(RequestContext $context): array
    {
        $state = $this->state($context);
        $items = [];
        foreach (($state['setups'] ?? []) as $setup) {
            if (!is_array($setup)) {
                continue;
            }
            $name = (string) ($setup['name'] ?? '');
            $items[] = [
                'id' => $setup['id'] ?? null,
                'name' => $name,
                'grade' => $setup['grade'] ?? null,
                'timeframe' => $setup['tf'] ?? null,
                'rules' => array_values(array_map('strval', is_array($setup['rules'] ?? null) ? $setup['rules'] : [])),
                'avoid' => (bool) ($setup['bad'] ?? false),
                'stats' => $this->trades->setupStats($state, $name),
            ];
        }

        return ['items' => $items, 'total' => count($items)];
    }

    public function getMacroCalendar(RequestContext $context, ?string $from = null, ?string $to = null, ?string $currency = null, ?int $impact = null, int $limit = 50, int $offset = 0): array
    {
        try {
            return $this->macro->list([
                'from' => $from,
                'to' => $to,
                'currency' => $currency,
                'impact' => $impact,
                'limit' => $limit,
                'offset' => $offset,
            ]);
        } catch (\InvalidArgumentException $err) {
            throw new ToolCallException($err->getMessage());
        }
    }

    public function getPropChallenges(RequestContext $context): array
    {
        $state = $this->state($context);
        $challenges = is_array($state['prop']['challenges'] ?? null) ? $state['prop']['challenges'] : [];
        $accountNames = array_values(array_filter(array_map(
            static fn (array $account): string => (string) ($account['name'] ?? ''),
            array_filter((array) ($state['accounts'] ?? []), 'is_array')
        ), static fn (string $name): bool => $name !== ''));
        $items = [];
        foreach ($challenges as $challenge) {
            if (!is_array($challenge)) {
                continue;
            }
            $normalized = PropChallengeService::normalize($challenge);
            $metrics = $this->prop->compute($challenge, (array) ($state['trades'] ?? []), null, $accountNames);
            $items[] = array_merge($normalized, ['metrics' => $metrics]);
        }

        return [
            'items' => $items,
            'total' => count($items),
            'selectedId' => $state['prop']['selectedId'] ?? null,
        ];
    }

    public function getCheckinHistory(RequestContext $context, ?string $from = null, ?string $to = null, int $limit = 50, int $offset = 0): array
    {
        $fromTs = $this->dayStart($from, 'from');
        $toTs = $this->dayEnd($to, 'to');
        $items = [];
        foreach ((array) ($this->state($context)['checkins'] ?? []) as $checkin) {
            if (!is_array($checkin)) {
                continue;
            }
            $date = (string) ($checkin['date'] ?? '');
            if (!Dates::isValidKey($date)) {
                continue;
            }
            $ts = Dates::startOfLocalDay($date)->getTimestamp();
            if ($fromTs !== null && $ts < $fromTs) {
                continue;
            }
            if ($toTs !== null && $ts >= $toTs) {
                continue;
            }
            $items[] = [
                'date' => $date,
                'mood' => (string) ($checkin['mood'] ?? ''),
                'energy' => (int) ($checkin['energy'] ?? 0),
                'note' => (string) ($checkin['note'] ?? ''),
                'score' => (int) ($checkin['score'] ?? 0),
            ];
        }
        usort($items, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']));
        $total = count($items);
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $page = array_slice($items, $offset, $limit);

        return [
            'items' => $page,
            'total' => $total,
            'nextOffset' => ($offset + count($page)) < $total ? $offset + count($page) : null,
        ];
    }

    public function getAccounts(RequestContext $context): array
    {
        $items = $this->capital->accounts($this->state($context));

        return ['items' => $items, 'total' => count($items)];
    }

    public function getCapitalSummary(RequestContext $context): array
    {
        return $this->capital->summary($this->state($context));
    }

    public function getConnectionStatus(RequestContext $context): array
    {
        return $this->connections->status((int) $this->user($context)['id']);
    }

    public function getTradeContext(RequestContext $context, string $tradeId): array
    {
        $contextData = $this->tradeContext->context($this->state($context), trim($tradeId));
        if ($contextData === null) {
            throw new ToolCallException('Transakcja nie istnieje.');
        }

        return $contextData;
    }

    // ------------------------------------------------------------------
    // Propozycje zmian
    // ------------------------------------------------------------------

    public function proposeCreateTrade(
        RequestContext $context,
        string $pair,
        string $dir,
        float $entry,
        float $size,
        string $idempotencyKey,
        ?float $sl = null,
        ?float $tp = null,
        ?string $setup = null,
        ?string $emotion = null,
        ?string $grade = null,
        ?int $checklist = null,
        ?int $checkTotal = null,
        ?array $errors = null,
        ?string $note = null,
        ?string $date = null,
        ?string $account = null
    ): array {
        $args = [
            'pair' => $pair,
            'dir' => $dir,
            'entry' => $entry,
            'size' => $size,
            'idempotencyKey' => $idempotencyKey,
        ];
        foreach (['sl' => $sl, 'tp' => $tp, 'setup' => $setup, 'emotion' => $emotion, 'grade' => $grade, 'checklist' => $checklist, 'checkTotal' => $checkTotal, 'errors' => $errors, 'note' => $note, 'date' => $date, 'account' => $account] as $key => $value) {
            if ($value !== null) {
                $args[$key] = $value;
            }
        }

        return $this->propose($context, fn (int $userId, string $client): array => $this->builder->createTrade($userId, $args, $client));
    }

    public function proposeCloseTrade(RequestContext $context, string $tradeId, float $exitPrice, string $idempotencyKey): array
    {
        return $this->propose($context, fn (int $userId, string $client): array => $this->builder->closeTrade($userId, [
            'tradeId' => $tradeId,
            'exitPrice' => $exitPrice,
            'idempotencyKey' => $idempotencyKey,
        ], $client));
    }

    public function proposeTradeReview(
        RequestContext $context,
        string $tradeId,
        string $idempotencyKey,
        ?string $note = null,
        ?string $grade = null,
        ?string $setup = null,
        ?string $emotion = null,
        ?int $checklist = null,
        ?array $errors = null
    ): array {
        $args = ['tradeId' => $tradeId, 'idempotencyKey' => $idempotencyKey];
        foreach (['note' => $note, 'grade' => $grade, 'setup' => $setup, 'emotion' => $emotion, 'checklist' => $checklist, 'errors' => $errors] as $key => $value) {
            if ($value !== null) {
                $args[$key] = $value;
            }
        }

        return $this->propose($context, fn (int $userId, string $client): array => $this->builder->tradeReview($userId, $args, $client));
    }

    public function proposeSaveDayPlan(
        RequestContext $context,
        string $idempotencyKey,
        ?string $date = null,
        ?string $market = null,
        ?string $bias = null,
        ?string $why = null,
        ?array $levels = null,
        ?string $ifMain = null,
        ?string $thenMain = null,
        ?string $ifAlt = null,
        ?string $thenAlt = null,
        ?float $riskPercent = null,
        ?int $maxTrades = null,
        ?string $until = null,
        ?string $notDo = null
    ): array {
        $args = ['idempotencyKey' => $idempotencyKey];
        foreach ([
            'date' => $date, 'market' => $market, 'bias' => $bias, 'why' => $why, 'levels' => $levels,
            'ifMain' => $ifMain, 'thenMain' => $thenMain, 'ifAlt' => $ifAlt, 'thenAlt' => $thenAlt,
            'riskPercent' => $riskPercent, 'maxTrades' => $maxTrades, 'until' => $until, 'notDo' => $notDo,
        ] as $key => $value) {
            if ($value !== null) {
                $args[$key] = $value;
            }
        }

        return $this->propose($context, fn (int $userId, string $client): array => $this->builder->saveDayPlan($userId, $args, $client));
    }

    public function proposeSaveCheckin(RequestContext $context, string $mood, int $energy, string $idempotencyKey, ?string $note = null): array
    {
        $args = ['mood' => $mood, 'energy' => $energy, 'idempotencyKey' => $idempotencyKey];
        if ($note !== null) {
            $args['note'] = $note;
        }

        return $this->propose($context, fn (int $userId, string $client): array => $this->builder->saveCheckin($userId, $args, $client));
    }

    public function proposeSaveSetup(
        RequestContext $context,
        string $name,
        array $rules,
        string $idempotencyKey,
        ?string $id = null,
        ?string $tf = null,
        ?string $grade = null,
        ?bool $bad = null,
        ?int $seed = null
    ): array {
        $args = ['name' => $name, 'rules' => $rules, 'idempotencyKey' => $idempotencyKey];
        foreach (['id' => $id, 'tf' => $tf, 'grade' => $grade, 'bad' => $bad, 'seed' => $seed] as $key => $value) {
            if ($value !== null) {
                $args[$key] = $value;
            }
        }

        return $this->propose($context, fn (int $userId, string $client): array => $this->builder->saveSetup($userId, $args, $client));
    }

    public function proposeSavePropChallenge(
        RequestContext $context,
        string $idempotencyKey,
        ?string $id = null,
        ?string $name = null,
        ?string $firm = null,
        ?string $templateId = null,
        ?string $phase = null,
        ?string $account = null,
        ?string $currency = null,
        ?float $accountSize = null,
        ?float $startBalance = null,
        ?float $targetPct = null,
        ?float $dailyLossPct = null,
        ?float $maxLossPct = null,
        ?string $drawdownType = null,
        ?string $dailyBasis = null,
        ?int $minDays = null,
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $notes = null
    ): array {
        $args = ['idempotencyKey' => $idempotencyKey];
        foreach ([
            'id' => $id, 'name' => $name, 'firm' => $firm, 'templateId' => $templateId, 'phase' => $phase,
            'account' => $account, 'currency' => $currency, 'accountSize' => $accountSize, 'startBalance' => $startBalance,
            'targetPct' => $targetPct, 'dailyLossPct' => $dailyLossPct, 'maxLossPct' => $maxLossPct,
            'drawdownType' => $drawdownType, 'dailyBasis' => $dailyBasis, 'minDays' => $minDays,
            'startDate' => $startDate, 'endDate' => $endDate, 'notes' => $notes,
        ] as $key => $value) {
            if ($value !== null) {
                $args[$key] = $value;
            }
        }

        return $this->propose($context, fn (int $userId, string $client): array => $this->builder->savePropChallenge($userId, $args, $client));
    }

    private function propose(RequestContext $context, callable $build): array
    {
        if (!$this->writesEnabled) {
            throw new ToolCallException('Propozycje zmian są wyłączone (MCP_WRITES_ENABLED=false).');
        }
        $token = $context->getAccessToken();
        if ($token === null || !$token->hasScope('alphavault.write')) {
            throw new ToolCallException('Brak zakresu alphavault.write.');
        }
        $user = $this->user($context);
        $client = $token->getClientId() ?? 'Agent MCP';
        try {
            return $build((int) $user['id'], $client);
        } catch (ProposalException $err) {
            throw new ToolCallException($err->getMessage());
        }
    }

    private function user(RequestContext $context): array
    {
        $token = $context->getAccessToken();
        $subject = $token?->getSubject();
        if ($subject === null || !ctype_digit($subject)) {
            throw new ToolCallException('Brak zalogowanego użytkownika AlphaVault.');
        }
        $user = $this->profiles->findUser((int) $subject);
        if ($user === null) {
            throw new ToolCallException('Konto AlphaVault nie istnieje.');
        }

        return $user;
    }

    private function state(RequestContext $context): array
    {
        $user = $this->user($context);
        $loaded = $this->states->load((int) $user['id']);

        return $loaded['state'] ?? StateRepository::emptyState();
    }

    private function dayStart(?string $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!Dates::isValidKey($value)) {
            throw new ToolCallException($field . ' musi mieć format YYYY-MM-DD.');
        }

        return Dates::startOfLocalDay($value)->getTimestamp();
    }

    private function dayEnd(?string $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!Dates::isValidKey($value)) {
            throw new ToolCallException($field . ' musi mieć format YYYY-MM-DD.');
        }

        return Dates::startOfLocalDay($value)->modify('+1 day')->getTimestamp();
    }
}
