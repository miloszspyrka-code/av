<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Tools;

use AlphaVault\Config;
use AlphaVault\Db;
use AlphaVault\Auth;
use AlphaVault\Mcp\Support\DashboardService;
use AlphaVault\Mcp\Support\Dates;
use AlphaVault\Mcp\Support\EventsService;
use AlphaVault\Mcp\Support\ProfileService;
use AlphaVault\Mcp\Support\StateRepository;
use AlphaVault\Mcp\Support\StatsService;
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

    public function __construct(Config $config, Db $db, Auth $auth)
    {
        $this->profiles = new ProfileService($config, $db, $auth);
        $this->states = new StateRepository($db);
        $this->trades = new TradeService();
        $this->stats = new StatsService($this->trades);
        $this->events = new EventsService($config, $db);
        $this->dashboard = new DashboardService($this->trades, $this->stats, $this->events);
    }

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
        if (!in_array($status, ['all', 'open', 'closed'], true)) {
            throw new ToolCallException('status musi być all, open albo closed.');
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
}
