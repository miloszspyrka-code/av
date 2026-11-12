<?php
declare(strict_types=1);

namespace AlphaVault\Mcp;

use AlphaVault\Db;
use DateTimeImmutable;
use DateTimeZone;

final class McpException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400
    ) {
        parent::__construct($message);
    }
}

/**
 * Silnik MCP AlphaVault.
 *
 * Zasady:
 * - narzędzia odczytu nie mają efektów ubocznych,
 * - narzędzia propozycji zapisują wyłącznie rekord oczekujący na decyzję użytkownika,
 * - zatwierdzenie i odrzucenie odbywa się wyłącznie przez sesję panelu AlphaVault,
 * - dane pochodzące z giełd są niezmienialne dla MCP.
 */
final class Mcp
{
    public const SCOPE_READ = 'panel:read';
    public const SCOPE_PROPOSE = 'panel:propose';
    public const PROPOSAL_TTL_MINUTES = 30;

    private const EXCHANGE_FIELDS = [
        'entry', 'exit', 'exitPrice', 'price', 'fill', 'fillPrice', 'result', 'pnl',
        'size', 'qty', 'quantity', 'sl', 'tp', 'leverage', 'fee', 'fees',
        'balance', 'equity', 'source', 'exchangeId', 'externalId',
    ];

    public function __construct(private readonly Db $db)
    {
    }

    // ------------------------------------------------------------------
    // Flagi bezpieczeństwa
    // ------------------------------------------------------------------

    private function flag(string $name, bool $default): bool
    {
        $value = getenv($name);
        if ($value === false || trim($value) === '') {
            return $default;
        }
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    public function enabled(): bool
    {
        return $this->flag('MCP_ENABLED', true);
    }

    public function writesEnabled(): bool
    {
        return $this->flag('MCP_WRITES_ENABLED', false);
    }

    public function pilotAllowed(array $user): bool
    {
        $raw = trim((string) getenv('MCP_PILOT_DISCORD_IDS'));
        if ($raw === '') {
            return true;
        }
        $ids = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($v) => $v !== ''));
        return in_array((string) ($user['discord_id'] ?? ''), $ids, true);
    }

    // ------------------------------------------------------------------
    // Tokeny
    // ------------------------------------------------------------------

    public function createToken(int $userId, string $name, array $scopes, ?string $expiresAt = null): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new McpException('bad_name', 'Nazwa tokenu musi mieć 1–100 znaków.', 400);
        }
        $allowed = [self::SCOPE_READ, self::SCOPE_PROPOSE];
        $scopes = array_values(array_intersect($allowed, array_map('strval', $scopes)));
        if (!in_array(self::SCOPE_READ, $scopes, true)) {
            throw new McpException('scope_required', 'Token musi mieć zakres panel:read.', 400);
        }
        $token = 'avm_' . bin2hex(random_bytes(24));
        $this->db->execute(
            'INSERT INTO mcp_tokens (user_id, name, token_hash, scopes, expires_at) VALUES (?, ?, ?, ?, ?)',
            [$userId, $name, hash('sha256', $token), implode(' ', $scopes), $expiresAt]
        );
        $id = (int) $this->db->lastInsertId();
        $this->audit($userId, null, 'user', 'mcp.token.create', ['tokenId' => $id, 'scopes' => $scopes]);
        return ['id' => $id, 'token' => $token, 'name' => $name, 'scopes' => $scopes, 'expiresAt' => $expiresAt];
    }

    public function revokeToken(int $userId, int $tokenId): void
    {
        $affected = $this->db->execute(
            'UPDATE mcp_tokens SET revoked_at = UTC_TIMESTAMP() WHERE id = ? AND user_id = ? AND revoked_at IS NULL',
            [$tokenId, $userId]
        );
        if ($affected === 0) {
            throw new McpException('not_found', 'Token nie istnieje lub jest już unieważniony.', 404);
        }
        $this->audit($userId, null, 'user', 'mcp.token.revoke', ['tokenId' => $tokenId]);
    }

    public function tokens(int $userId): array
    {
        $rows = $this->db->query(
            'SELECT id, name, scopes, created_at, last_used_at, expires_at, revoked_at FROM mcp_tokens WHERE user_id = ? ORDER BY id DESC',
            [$userId]
        );
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'scopes' => array_values(array_filter(explode(' ', (string) $row['scopes']))),
            'createdAt' => $row['created_at'],
            'lastUsedAt' => $row['last_used_at'],
            'expiresAt' => $row['expires_at'],
            'revokedAt' => $row['revoked_at'],
        ], $rows);
    }

    public function authenticate(string $token): array
    {
        if (!$this->enabled()) {
            throw new McpException('mcp_disabled', 'MCP jest wyłączony.', 503);
        }
        if ($token === '') {
            throw new McpException('unauthorized', 'Brak tokenu.', 401);
        }
        $row = $this->db->queryOne(
            'SELECT t.id AS token_id, t.name AS token_name, t.scopes, t.expires_at, t.revoked_at,
                    u.id AS user_id, u.discord_id, u.username, u.global_name, u.plan, u.access_until, u.is_demo, u.role_verified
             FROM mcp_tokens t JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ?',
            [hash('sha256', $token)]
        );
        if ($row === null || $row['revoked_at'] !== null) {
            throw new McpException('unauthorized', 'Token jest nieprawidłowy lub unieważniony.', 401);
        }
        if ($row['expires_at'] !== null && strtotime((string) $row['expires_at'] . ' UTC') < time()) {
            throw new McpException('unauthorized', 'Token wygasł.', 401);
        }
        $user = [
            'id' => (int) $row['user_id'],
            'discord_id' => $row['discord_id'],
            'username' => (string) $row['username'],
            'global_name' => $row['global_name'],
            'plan' => (string) $row['plan'],
            'access_until' => $row['access_until'],
            'is_demo' => (int) $row['is_demo'],
            'role_verified' => (int) $row['role_verified'],
        ];
        if (!$this->pilotAllowed($user)) {
            throw new McpException('forbidden', 'Konto nie jest w pilocie MCP.', 403);
        }
        $this->db->execute('UPDATE mcp_tokens SET last_used_at = UTC_TIMESTAMP() WHERE id = ?', [(int) $row['token_id']]);
        return [
            'user' => $user,
            'tokenId' => (int) $row['token_id'],
            'clientName' => (string) $row['token_name'],
            'scopes' => array_values(array_filter(explode(' ', (string) $row['scopes']))),
        ];
    }

    // ------------------------------------------------------------------
    // Narzędzia
    // ------------------------------------------------------------------

    public function tools(array $scopes): array
    {
        $tools = $this->readToolDefinitions();
        if ($this->writesEnabled() && in_array(self::SCOPE_PROPOSE, $scopes, true)) {
            $tools = array_merge($tools, $this->proposeToolDefinitions());
        }
        return $tools;
    }

    private function readToolDefinitions(): array
    {
        $read = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
        $schema = static fn (array $properties = [], array $required = []): array => [
            'type' => 'object', 'properties' => (object) $properties, 'required' => $required, 'additionalProperties' => false,
        ];
        return [
            ['name' => 'get_profile', 'description' => 'Profil AlphaVault: plan, subskrypcja, rola. Tylko odczyt.', 'inputSchema' => $schema(), 'annotations' => $read],
            ['name' => 'get_dashboard', 'description' => 'Pulpit: dzisiejszy wynik, gotowość, plan, limity, najbliższe wydarzenie. Tylko odczyt.', 'inputSchema' => $schema(), 'annotations' => $read],
            ['name' => 'list_trades', 'description' => 'Lista transakcji użytkownika z filtrami i paginacją. Tylko odczyt.', 'inputSchema' => $schema([
                'status' => ['type' => 'string', 'enum' => ['all', 'open', 'closed', 'win', 'loss']],
                'pair' => ['type' => 'string'], 'setup' => ['type' => 'string'], 'grade' => ['type' => 'string', 'enum' => ['A', 'B', 'C']],
                'from' => ['type' => 'string'], 'to' => ['type' => 'string'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                'offset' => ['type' => 'integer', 'minimum' => 0],
            ]), 'annotations' => $read],
            ['name' => 'get_trade', 'description' => 'Szczegóły transakcji. Bez screenów i danych wrażliwych. Tylko odczyt.', 'inputSchema' => $schema([
                'id' => ['type' => 'string'],
            ], ['id']), 'annotations' => $read],
            ['name' => 'get_stats', 'description' => 'Statystyki za okres 7d/30d/90d/all. Tylko odczyt.', 'inputSchema' => $schema([
                'period' => ['type' => 'string', 'enum' => ['7d', '30d', '90d', 'all']],
            ]), 'annotations' => $read],
            ['name' => 'get_events', 'description' => 'Harmonogram live i konsultacji z bazy. Tylko odczyt.', 'inputSchema' => $schema([
                'category' => ['type' => 'string', 'enum' => ['all', 'live', 'consultation', 'unassigned']],
                'from' => ['type' => 'string'], 'to' => ['type' => 'string'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            ]), 'annotations' => $read],
            ['name' => 'get_day_plan', 'description' => 'Plan dnia dla daty. Brak planu = null. Tylko odczyt.', 'inputSchema' => $schema([
                'date' => ['type' => 'string'],
            ]), 'annotations' => $read],
            ['name' => 'list_setups', 'description' => 'Playbook: setupy i statystyki per setup. Tylko odczyt.', 'inputSchema' => $schema(), 'annotations' => $read],
        ];
    }

    private function proposeToolDefinitions(): array
    {
        $propose = ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
        $schema = static fn (array $properties = [], array $required = []): array => [
            'type' => 'object', 'properties' => (object) $properties, 'required' => $required, 'additionalProperties' => false,
        ];
        $idem = ['idempotencyKey' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 120]];
        return [
            ['name' => 'propose_panel_trade', 'description' => 'Tworzy propozycję ręcznego wpisu transakcji w panelu. Nie zapisuje transakcji i nie dotyka giełdy.', 'inputSchema' => $schema(array_merge($idem, [
                'pair' => ['type' => 'string'], 'side' => ['type' => 'string', 'enum' => ['long', 'short']],
                'entry' => ['type' => 'number'], 'size' => ['type' => 'number'],
                'sl' => ['type' => 'number'], 'tp' => ['type' => 'number'],
                'setup' => ['type' => 'string'], 'emotion' => ['type' => 'string'], 'grade' => ['type' => 'string', 'enum' => ['A', 'B', 'C']],
                'note' => ['type' => 'string', 'maxLength' => 2000], 'date' => ['type' => 'string'],
            ]), ['idempotencyKey', 'pair', 'side', 'entry', 'size']), 'annotations' => $propose],
            ['name' => 'propose_trade_review', 'description' => 'Propozycja notatki i metadanych panelowych do transakcji. Nie zmienia danych giełdowych.', 'inputSchema' => $schema(array_merge($idem, [
                'tradeId' => ['type' => 'string'], 'note' => ['type' => 'string', 'maxLength' => 2000],
                'grade' => ['type' => 'string', 'enum' => ['A', 'B', 'C']], 'setup' => ['type' => 'string'],
                'emotion' => ['type' => 'string'], 'checklist' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 20],
            ]), ['idempotencyKey', 'tradeId']), 'annotations' => $propose],
            ['name' => 'propose_day_plan_change', 'description' => 'Propozycja zmiany planu dnia. Nie zapisuje planu przed zatwierdzeniem w panelu.', 'inputSchema' => $schema(array_merge($idem, [
                'date' => ['type' => 'string'], 'market' => ['type' => 'string'], 'bias' => ['type' => 'string', 'enum' => ['long', 'short', 'neutral']],
                'why' => ['type' => 'string', 'maxLength' => 2000],
                'levels' => ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'object']],
                'ifMain' => ['type' => 'string'], 'thenMain' => ['type' => 'string'], 'ifAlt' => ['type' => 'string'], 'thenAlt' => ['type' => 'string'],
                'riskPercent' => ['type' => 'number'], 'maxTrades' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                'until' => ['type' => 'string'], 'notDo' => ['type' => 'string'], 'approve' => ['type' => 'boolean'],
            ]), ['idempotencyKey', 'date']), 'annotations' => $propose],
            ['name' => 'propose_checkin', 'description' => 'Propozycja check-inu na dziś. Nie zapisuje check-inu przed zatwierdzeniem w panelu.', 'inputSchema' => $schema(array_merge($idem, [
                'mood' => ['type' => 'string'], 'energy' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                'note' => ['type' => 'string', 'maxLength' => 500],
            ]), ['idempotencyKey', 'mood', 'energy']), 'annotations' => $propose],
        ];
    }

    public function callTool(string $name, array $args, array $auth): array
    {
        $scopes = $auth['scopes'];
        $userId = (int) $auth['user']['id'];

        $readTools = ['get_profile', 'get_dashboard', 'list_trades', 'get_trade', 'get_stats', 'get_events', 'get_day_plan', 'list_setups'];
        $proposeTools = ['propose_panel_trade', 'propose_trade_review', 'propose_day_plan_change', 'propose_checkin'];

        try {
            if (in_array($name, $readTools, true)) {
                if (!in_array(self::SCOPE_READ, $scopes, true)) {
                    throw new McpException('insufficient_scope', 'Brak zakresu panel:read.', 403);
                }
                $data = $this->runReadTool($name, $args, $userId, $auth['user']);
            } elseif (in_array($name, $proposeTools, true)) {
                if (!$this->writesEnabled()) {
                    throw new McpException('writes_disabled', 'Propozycje zmian są wyłączone (MCP_WRITES_ENABLED=false).', 403);
                }
                if (!in_array(self::SCOPE_PROPOSE, $scopes, true)) {
                    throw new McpException('insufficient_scope', 'Brak zakresu panel:propose.', 403);
                }
                $data = $this->runProposeTool($name, $args, $userId, (string) $auth['clientName']);
            } else {
                throw new McpException('unknown_tool', 'Nieznane narzędzie: ' . $name, 404);
            }
        } catch (McpException $err) {
            return [
                'isError' => true,
                'content' => [['type' => 'text', 'text' => $err->getMessage()]],
                'structuredContent' => ['error' => $err->errorCode, 'message' => $err->getMessage()],
            ];
        }

        $text = is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $data;
        return [
            'isError' => false,
            'content' => [['type' => 'text', 'text' => (string) $text]],
            'structuredContent' => is_array($data) ? $data : ['result' => $data],
        ];
    }

    private function runReadTool(string $name, array $args, int $userId, array $user): mixed
    {
        return match ($name) {
            'get_profile' => $this->getProfile($user),
            'get_dashboard' => $this->getDashboard($userId, $user),
            'list_trades' => $this->listTrades($userId, $args),
            'get_trade' => $this->getTrade($userId, $args),
            'get_stats' => $this->getStats($userId, $args),
            'get_events' => $this->getEvents($args),
            'get_day_plan' => $this->getDayPlan($userId, $args),
            'list_setups' => $this->listSetups($userId),
            default => throw new McpException('unknown_tool', 'Nieznane narzędzie.', 404),
        };
    }

    // ------------------------------------------------------------------
    // Odczyt
    // ------------------------------------------------------------------

    private function getProfile(array $user): array
    {
        $admins = array_filter(array_map('trim', explode(',', (string) getenv('ADMIN_DISCORD_IDS'))));
        return [
            'id' => (string) $user['id'],
            'name' => $user['global_name'] ?: $user['username'],
            'username' => (string) $user['username'],
            'plan' => $user['plan'] === 'full' ? 'Akademia Alpha + Vault' : 'Alpha Vault',
            'accessUntil' => $user['access_until'],
            'isAdmin' => in_array((string) $user['discord_id'], $admins, true),
        ];
    }

    private function getDashboard(int $userId, array $user): array
    {
        $state = $this->state($userId);
        $today = $this->todayKey();
        $trades = $this->includedTrades($state);
        $todays = array_values(array_filter($trades, fn (array $t): bool => $this->dateKey((string) ($t['date'] ?? '')) === $today));
        $closed = array_values(array_filter($todays, static fn (array $t): bool => ($t['result'] ?? null) !== null));
        $net = array_sum(array_map(static fn (array $t): float => (float) $t['result'], $closed));
        $losses = count(array_filter($closed, static fn (array $t): bool => (float) $t['result'] < 0));
        $checkin = $this->todayCheckin($state);
        $settings = is_array($state['settings'] ?? null) ? $state['settings'] : [];
        $readiness = $checkin !== null ? $this->readinessFromCheckin($checkin) : null;
        return [
            'today' => ['date' => $today, 'trades' => count($todays), 'closed' => count($closed), 'net' => round($net, 2), 'losses' => $losses],
            'plan' => $state['plans'][$today] ?? null,
            'readiness' => $readiness,
            'protection' => [
                'lossLimit' => (int) ($settings['maxLosses'] ?? 0),
                'dailyLossPercent' => (float) ($settings['maxDailyLoss'] ?? 0),
                'active' => (bool) ($settings['safetyEnabled'] ?? false) && $losses >= max(1, (int) ($settings['maxLosses'] ?? 2)),
            ],
            'capital' => round($this->capital($state), 2),
            'nextEvent' => $this->nextEvent(),
            'sync' => $this->syncState(),
        ];
    }

    private function listTrades(int $userId, array $args): array
    {
        $state = $this->state($userId);
        $trades = $this->includedTrades($state);
        $status = (string) ($args['status'] ?? 'all');
        $pair = strtoupper(trim((string) ($args['pair'] ?? '')));
        $setup = trim((string) ($args['setup'] ?? ''));
        $grade = strtoupper(trim((string) ($args['grade'] ?? '')));
        $from = (string) ($args['from'] ?? '');
        $to = (string) ($args['to'] ?? '');
        $limit = max(1, min(100, (int) ($args['limit'] ?? 50)));
        $offset = max(0, (int) ($args['offset'] ?? 0));

        $items = array_values(array_filter($trades, function (array $t) use ($status, $pair, $setup, $grade, $from, $to): bool {
            $result = $t['result'] ?? null;
            if ($status === 'open' && $result !== null) return false;
            if ($status === 'closed' && $result === null) return false;
            if ($status === 'win' && (float) $result <= 0) return false;
            if ($status === 'loss' && (float) $result >= 0) return false;
            if ($pair !== '' && !str_contains(strtoupper((string) ($t['pair'] ?? '')), $pair)) return false;
            if ($setup !== '' && (string) ($t['setup'] ?? '') !== $setup) return false;
            if ($grade !== '' && (string) ($t['grade'] ?? '') !== $grade) return false;
            $date = (string) ($t['date'] ?? '');
            if ($from !== '' && $date !== '' && strtotime($date) < strtotime($from)) return false;
            if ($to !== '' && $date !== '' && strtotime($date) > strtotime($to . ' 23:59:59')) return false;
            return true;
        }));
        usort($items, static fn (array $a, array $b): int => strtotime((string) ($b['date'] ?? '')) <=> strtotime((string) ($a['date'] ?? '')));
        $page = array_slice($items, $offset, $limit);
        return [
            'items' => array_map(fn (array $t): array => $this->publicTrade($t), $page),
            'total' => count($items),
            'nextOffset' => $offset + $limit < count($items) ? $offset + $limit : null,
        ];
    }

    private function getTrade(int $userId, array $args): array
    {
        $id = trim((string) ($args['id'] ?? ''));
        if ($id === '') {
            throw new McpException('bad_request', 'Pole id jest wymagane.', 400);
        }
        $state = $this->state($userId);
        foreach ($this->includedTrades($state) as $trade) {
            if ((string) ($trade['id'] ?? '') === $id) {
                $out = $this->publicTrade($trade);
                $out['screensCount'] = is_array($trade['screens'] ?? null) ? count($trade['screens']) : 0;
                $out['review'] = $state['tradeReviews'][$id] ?? null;
                return $out;
            }
        }
        throw new McpException('not_found', 'Transakcja nie istnieje.', 404);
    }

    private function getStats(int $userId, array $args): array
    {
        $period = (string) ($args['period'] ?? '90d');
        $state = $this->state($userId);
        $trades = array_values(array_filter($this->closedTrades($state), function (array $t) use ($period): bool {
            if ($period === 'all') return true;
            $days = (int) rtrim($period, 'd');
            $cutoff = (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))->modify('-' . $days . ' days')->setTime(0, 0);
            return strtotime((string) ($t['date'] ?? '')) >= $cutoff->getTimestamp();
        }));
        $n = count($trades);
        $wins = array_values(array_filter($trades, static fn (array $t): bool => (float) $t['result'] > 0));
        $losses = array_values(array_filter($trades, static fn (array $t): bool => (float) $t['result'] < 0));
        $net = array_sum(array_map(static fn (array $t): float => (float) $t['result'], $trades));
        $gp = array_sum(array_map(static fn (array $t): float => (float) $t['result'], $wins));
        $gl = abs(array_sum(array_map(static fn (array $t): float => (float) $t['result'], $losses)));
        $rs = array_values(array_filter($trades, static fn (array $t): bool => ($t['r'] ?? null) !== null));
        $expectancy = $rs !== [] ? array_sum(array_map(static fn (array $t): float => (float) $t['r'], $rs)) / count($rs) : 0;
        $sl = count(array_filter($trades, static fn (array $t): bool => ($t['sl'] ?? '') !== '' && ($t['sl'] ?? '') !== 'brak'));
        $plan = count(array_filter($trades, static fn (array $t): bool => (bool) ($t['plan'] ?? false)));
        $grades = [];
        foreach (['A', 'B', 'C'] as $grade) {
            $arr = array_values(array_filter($trades, static fn (array $t): bool => ($t['grade'] ?? '') === $grade));
            $gw = count(array_filter($arr, static fn (array $t): bool => (float) $t['result'] > 0));
            $grades[] = ['grade' => $grade, 'n' => count($arr), 'wr' => $arr !== [] ? round($gw / count($arr) * 100, 1) : 0, 'net' => round(array_sum(array_map(static fn (array $t): float => (float) $t['result'], $arr)), 2)];
        }
        return [
            'period' => $period,
            'n' => $n,
            'wins' => count($wins),
            'losses' => count($losses),
            'winRate' => $n > 0 ? round(count($wins) / $n * 100, 1) : 0,
            'net' => round($net, 2),
            'profitFactor' => $gl > 0 ? round($gp / $gl, 2) : ($gp > 0 ? 99 : 0),
            'expectancyR' => round($expectancy, 2),
            'planRate' => $n > 0 ? round($plan / $n * 100, 1) : 0,
            'withSL' => $n > 0 ? round($sl / $n * 100, 1) : 0,
            'fees' => round(array_sum(array_map(static fn (array $t): float => (float) ($t['fee'] ?? 0), $trades)), 2),
            'byGrade' => $grades,
        ];
    }

    private function getEvents(array $args): array
    {
        $category = (string) ($args['category'] ?? 'all');
        $limit = max(1, min(100, (int) ($args['limit'] ?? 50)));
        $from = (string) ($args['from'] ?? gmdate('Y-m-d\TH:i:s\Z'));
        $to = (string) ($args['to'] ?? '');
        $sql = 'SELECT name, category, scheduled_start_time, scheduled_start_iso, duration_minutes, host_name, user_count, location
                FROM discord_scheduled_events WHERE is_deleted = 0 AND scheduled_start_time >= ?';
        $params = [gmdate('Y-m-d H:i:s', strtotime($from))];
        if ($category !== 'all') {
            $sql .= ' AND category = ?';
            $params[] = $category;
        }
        if ($to !== '') {
            $sql .= ' AND scheduled_start_time <= ?';
            $params[] = gmdate('Y-m-d H:i:s', strtotime($to));
        }
        $sql .= ' ORDER BY scheduled_start_time ASC LIMIT ' . $limit;
        $rows = $this->db->query($sql, $params);
        $warsaw = new DateTimeZone('Europe/Warsaw');
        $events = array_map(static function (array $row) use ($warsaw): array {
            $start = new DateTimeImmutable((string) $row['scheduled_start_iso'], new DateTimeZone('UTC'));
            return [
                'name' => (string) $row['name'],
                'category' => (string) $row['category'],
                'startAt' => $start->format('Y-m-d\TH:i:s\Z'),
                'startLocal' => $start->setTimezone($warsaw)->format('Y-m-d\TH:i:sP'),
                'durationMinutes' => $row['duration_minutes'] !== null ? (int) $row['duration_minutes'] : null,
                'host' => $row['host_name'],
                'userCount' => $row['user_count'] !== null ? (int) $row['user_count'] : null,
                'location' => $row['location'],
            ];
        }, $rows);
        return ['events' => $events, 'sync' => $this->syncState()];
    }

    private function getDayPlan(int $userId, array $args): array
    {
        $date = (string) ($args['date'] ?? $this->todayKey());
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new McpException('bad_request', 'Data musi być w formacie YYYY-MM-DD.', 400);
        }
        $state = $this->state($userId);
        return ['date' => $date, 'plan' => $state['plans'][$date] ?? null];
    }

    private function listSetups(int $userId): array
    {
        $state = $this->state($userId);
        $setups = is_array($state['setups'] ?? null) ? $state['setups'] : [];
        $trades = $this->closedTrades($state);
        $items = [];
        foreach ($setups as $setup) {
            $name = is_array($setup) ? (string) ($setup['name'] ?? '') : (string) $setup;
            if ($name === '') continue;
            $arr = array_values(array_filter($trades, static fn (array $t): bool => (string) ($t['setup'] ?? '') === $name));
            $wins = count(array_filter($arr, static fn (array $t): bool => (float) $t['result'] > 0));
            $items[] = [
                'name' => $name,
                'n' => count($arr),
                'wr' => $arr !== [] ? round($wins / count($arr) * 100, 1) : 0,
                'net' => round(array_sum(array_map(static fn (array $t): float => (float) $t['result'], $arr)), 2),
            ];
        }
        return ['setups' => $items];
    }

    // ------------------------------------------------------------------
    // Propozycje
    // ------------------------------------------------------------------

    private function runProposeTool(string $name, array $args, int $userId, string $clientName): array
    {
        $key = trim((string) ($args['idempotencyKey'] ?? ''));
        if (mb_strlen($key) < 8 || mb_strlen($key) > 120) {
            throw new McpException('bad_request', 'idempotencyKey musi mieć 8–120 znaków.', 400);
        }
        $this->assertNoExchangeFields($args, $name === 'propose_trade_review');
        return match ($name) {
            'propose_panel_trade' => $this->proposePanelTrade($userId, $args, $key, $clientName),
            'propose_trade_review' => $this->proposeTradeReview($userId, $args, $key, $clientName),
            'propose_day_plan_change' => $this->proposeDayPlan($userId, $args, $key, $clientName),
            'propose_checkin' => $this->proposeCheckin($userId, $args, $key, $clientName),
            default => throw new McpException('unknown_tool', 'Nieznane narzędzie.', 404),
        };
    }

    private function assertNoExchangeFields(array $args, bool $reviewOnly = false): void
    {
        $forbidden = ['source', 'exchangeId', 'externalId', 'leverage', 'balance', 'equity', 'fee', 'fees', 'fill', 'fillPrice', 'price', 'result', 'exit', 'exitPrice', 'pnl'];
        if ($reviewOnly) {
            $forbidden = array_merge($forbidden, ['entry', 'size', 'sl', 'tp']);
        }
        foreach ($forbidden as $field) {
            if (array_key_exists($field, $args)) {
                throw new McpException('exchange_data_readonly', 'Pole ' . $field . ' należy do danych giełdowych i jest tylko do odczytu.', 403);
            }
        }
    }

    private function proposePanelTrade(int $userId, array $args, string $key, string $clientName): array
    {
        $pair = strtoupper(trim((string) ($args['pair'] ?? '')));
        if (!preg_match('/^[A-Z0-9._\/-]{2,20}$/', $pair)) {
            throw new McpException('bad_request', 'Para jest nieprawidłowa.', 400);
        }
        $side = (string) ($args['side'] ?? '');
        if (!in_array($side, ['long', 'short'], true)) {
            throw new McpException('bad_request', 'Kierunek musi być long albo short.', 400);
        }
        $entry = (float) ($args['entry'] ?? 0);
        $size = (float) ($args['size'] ?? 0);
        if ($entry <= 0 || $size <= 0) {
            throw new McpException('bad_request', 'entry i size muszą być większe od 0.', 400);
        }
        $trade = [
            'id' => $this->uuid(),
            'pair' => $pair,
            'side' => $side,
            'entry' => $entry,
            'size' => $size,
            'sl' => isset($args['sl']) ? (float) $args['sl'] : null,
            'tp' => isset($args['tp']) ? (float) $args['tp'] : null,
            'setup' => trim((string) ($args['setup'] ?? 'Bez setupu')),
            'emotion' => trim((string) ($args['emotion'] ?? '')),
            'grade' => trim((string) ($args['grade'] ?? '')),
            'note' => mb_substr(trim((string) ($args['note'] ?? '')), 0, 2000),
            'date' => $this->normalizeDate((string) ($args['date'] ?? '')),
            'result' => null,
            'source' => 'manual',
            'origin' => 'panel',
        ];
        return $this->storeProposal($userId, $clientName, 'panel_trade', 'trade:manual', null, $trade, $key);
    }

    private function proposeTradeReview(int $userId, array $args, string $key, string $clientName): array
    {
        $tradeId = trim((string) ($args['tradeId'] ?? ''));
        if ($tradeId === '') {
            throw new McpException('bad_request', 'Pole tradeId jest wymagane.', 400);
        }
        $state = $this->state($userId);
        $trade = null;
        foreach ($this->includedTrades($state) as $candidate) {
            if ((string) ($candidate['id'] ?? '') === $tradeId) {
                $trade = $candidate;
                break;
            }
        }
        if ($trade === null) {
            throw new McpException('not_found', 'Transakcja nie istnieje.', 404);
        }
        $review = [];
        foreach (['note', 'grade', 'setup', 'emotion', 'checklist'] as $field) {
            if (array_key_exists($field, $args)) {
                $review[$field] = $args[$field];
            }
        }
        if ($review === []) {
            throw new McpException('bad_request', 'Brak pól recenzji do zapisania.', 400);
        }
        $review['tradeId'] = $tradeId;
        $review['exchangeOwned'] = $this->isExchangeTrade($userId, $trade);
        $before = $state['tradeReviews'][$tradeId] ?? null;
        return $this->storeProposal($userId, $clientName, 'trade_review', 'trade:' . $tradeId, $before, $review, $key);
    }

    private function proposeDayPlan(int $userId, array $args, string $key, string $clientName): array
    {
        $date = (string) ($args['date'] ?? $this->todayKey());
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new McpException('bad_request', 'Data musi być w formacie YYYY-MM-DD.', 400);
        }
        $today = $this->todayKey();
        $yesterday = (new DateTimeImmutable('yesterday', new DateTimeZone('Europe/Warsaw')))->format('Y-m-d');
        if (!in_array($date, [$today, $yesterday], true)) {
            throw new McpException('bad_request', 'Plan można zaproponować tylko na dziś lub wczoraj.', 400);
        }
        $state = $this->state($userId);
        $before = $state['plans'][$date] ?? null;
        $allowed = ['market', 'bias', 'why', 'levels', 'ifMain', 'thenMain', 'ifAlt', 'thenAlt', 'riskPercent', 'maxTrades', 'until', 'notDo', 'approve'];
        $after = is_array($before) ? $before : [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $args)) {
                $after[$field] = $args[$field];
            }
        }
        if (isset($after['levels']) && (!is_array($after['levels']) || count($after['levels']) > 10)) {
            throw new McpException('bad_request', 'Maksymalnie 10 poziomów.', 400);
        }
        if (($after['approve'] ?? false) === true && (trim((string) ($after['market'] ?? '')) === '' || trim((string) ($after['why'] ?? '')) === '')) {
            throw new McpException('bad_request', 'Zatwierdzenie planu wymaga rynku i uzasadnienia.', 400);
        }
        return $this->storeProposal($userId, $clientName, 'day_plan', 'plan:' . $date, $before, $after, $key);
    }

    private function proposeCheckin(int $userId, array $args, string $key, string $clientName): array
    {
        $moods = ['Spokój', 'Pewność', 'Skupienie', 'Nuda', 'Zmęczenie', 'Strach', 'FOMO', 'Chciwość', 'Złość'];
        $mood = trim((string) ($args['mood'] ?? ''));
        if (!in_array($mood, $moods, true)) {
            throw new McpException('bad_request', 'Nieprawidłowy nastrój.', 400);
        }
        $energy = (int) ($args['energy'] ?? 0);
        if ($energy < 1 || $energy > 5) {
            throw new McpException('bad_request', 'Energia musi być w zakresie 1–5.', 400);
        }
        $date = $this->todayKey();
        $state = $this->state($userId);
        $before = $this->todayCheckin($state);
        $after = ['date' => $date, 'mood' => $mood, 'energy' => $energy, 'note' => mb_substr(trim((string) ($args['note'] ?? '')), 0, 500), 'score' => $this->readinessFromCheckin(['mood' => $mood, 'energy' => $energy])['score']];
        return $this->storeProposal($userId, $clientName, 'checkin', 'checkin:' . $date, $before, $after, $key);
    }

    private function storeProposal(int $userId, string $clientName, string $kind, string $target, ?array $before, array $after, string $idempotencyKey): array
    {
        $existing = $this->db->queryOne('SELECT id, status, expires_at FROM mcp_proposals WHERE user_id = ? AND idempotency_key = ?', [$userId, $idempotencyKey]);
        if ($existing !== null) {
            return ['proposalId' => $existing['id'], 'status' => $existing['status'], 'expiresAt' => $existing['expires_at'], 'duplicate' => true];
        }
        $revisionRow = $this->db->queryOne('SELECT revision FROM user_states WHERE user_id = ?', [$userId]);
        $revision = (int) ($revisionRow['revision'] ?? 0);
        $id = $this->uuid();
        $expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . self::PROPOSAL_TTL_MINUTES . ' minutes');
        $diff = $this->diff($before, $after);
        try {
            $this->db->execute(
                'INSERT INTO mcp_proposals (id, user_id, client_name, kind, target_ref, base_revision, before_json, after_json, diff_json, idempotency_key, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $id, $userId, $clientName, $kind, $target, $revision,
                    $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
                    json_encode($after, JSON_UNESCAPED_UNICODE),
                    json_encode($diff, JSON_UNESCAPED_UNICODE),
                    $idempotencyKey, $expires->format('Y-m-d H:i:s'),
                ]
            );
        } catch (\PDOException $err) {
            if ((string) $err->getCode() === '23000') {
                $row = $this->db->queryOne('SELECT id, status, expires_at FROM mcp_proposals WHERE user_id = ? AND idempotency_key = ?', [$userId, $idempotencyKey]);
                if ($row !== null) {
                    return ['proposalId' => $row['id'], 'status' => $row['status'], 'expiresAt' => $row['expires_at'], 'duplicate' => true];
                }
            }
            throw $err;
        }
        $this->audit($userId, $id, 'agent', 'mcp.proposal.create', ['kind' => $kind, 'target' => $target, 'diff' => $diff]);
        return [
            'proposalId' => $id,
            'status' => 'pending',
            'kind' => $kind,
            'target' => $target,
            'expiresAt' => $expires->format('Y-m-d\TH:i:s\Z'),
            'note' => 'Propozycja czeka na zatwierdzenie w panelu AlphaVault. Nic nie zostało zmienione.',
        ];
    }

    // ------------------------------------------------------------------
    // Panel: propozycje
    // ------------------------------------------------------------------

    public function proposals(int $userId, string $status = 'pending'): array
    {
        $rows = $this->db->query(
            'SELECT id, kind, target_ref, before_json, after_json, diff_json, status, created_at, expires_at, decided_at, client_name
             FROM mcp_proposals WHERE user_id = ? AND status = ? ORDER BY created_at DESC LIMIT 50',
            [$userId, $status]
        );
        return array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'kind' => (string) $row['kind'],
            'target' => $row['target_ref'],
            'before' => $row['before_json'] !== null ? json_decode((string) $row['before_json'], true) : null,
            'after' => json_decode((string) $row['after_json'], true),
            'diff' => json_decode((string) $row['diff_json'], true),
            'status' => (string) $row['status'],
            'createdAt' => $row['created_at'],
            'expiresAt' => $row['expires_at'],
            'decidedAt' => $row['decided_at'],
            'clientName' => $row['client_name'],
        ], $rows);
    }

    public function approve(int $userId, string $proposalId, ?int $baseRevision, int $decidedBy): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $proposal = $this->db->queryOne(
                'SELECT * FROM mcp_proposals WHERE id = ? AND user_id = ? FOR UPDATE',
                [$proposalId, $userId]
            );
            if ($proposal === null) {
                throw new McpException('not_found', 'Propozycja nie istnieje.', 404);
            }
            if ($proposal['status'] !== 'pending') {
                throw new McpException('proposal_closed', 'Propozycja nie jest już oczekująca.', 409);
            }
            if (strtotime((string) $proposal['expires_at'] . ' UTC') < time()) {
                $this->db->execute('UPDATE mcp_proposals SET status = ? WHERE id = ?', ['expired', $proposalId]);
                throw new McpException('proposal_expired', 'Propozycja wygasła. Poproś agenta o nową.', 409);
            }
            $row = $this->db->queryOne('SELECT state, revision FROM user_states WHERE user_id = ? FOR UPDATE', [$userId]);
            $state = $row !== null ? json_decode((string) $row['state'], true) : [];
            if (!is_array($state)) $state = [];
            $revision = (int) ($row['revision'] ?? 0);
            $expected = (int) ($proposal['base_revision'] ?? 0);
            if ($expected !== $revision) {
                throw new McpException('revision_conflict', 'Dane zmieniły się od chwili przygotowania propozycji. Poproś agenta o nową.', 409);
            }
            $after = json_decode((string) $proposal['after_json'], true);
            if (!is_array($after)) {
                throw new McpException('bad_proposal', 'Propozycja jest uszkodzona.', 400);
            }
            switch ((string) $proposal['kind']) {
                case 'day_plan':
                    $date = str_replace('plan:', '', (string) $proposal['target_ref']);
                    $state['plans'][$date] = $after;
                    break;
                case 'checkin':
                    $checkins = is_array($state['checkins'] ?? null) ? $state['checkins'] : [];
                    $date = (string) ($after['date'] ?? $this->todayKey());
                    $checkins = array_values(array_filter($checkins, static fn (array $c): bool => (string) ($c['date'] ?? '') !== $date));
                    $checkins[] = $after;
                    $state['checkins'] = $checkins;
                    break;
                case 'panel_trade':
                    $state['trades'] = is_array($state['trades'] ?? null) ? $state['trades'] : [];
                    $state['trades'][] = $after;
                    break;
                case 'trade_review':
                    $tradeId = str_replace('trade:', '', (string) $proposal['target_ref']);
                    $state['tradeReviews'][$tradeId] = $after;
                    break;
                default:
                    throw new McpException('bad_proposal', 'Nieznany typ propozycji.', 400);
            }
            $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new McpException('bad_state', 'Nie można zapisać stanu.', 400);
            }
            if ($row === null) {
                $this->db->execute('INSERT INTO user_states (user_id, state, revision, bytes) VALUES (?, ?, 1, ?)', [$userId, $json, strlen($json)]);
                $revision = 1;
            } else {
                $this->db->execute('UPDATE user_states SET state = ?, revision = ?, bytes = ? WHERE user_id = ?', [$json, $revision + 1, strlen($json), $userId]);
                $revision++;
            }
            $this->db->execute('UPDATE mcp_proposals SET status = ?, decided_at = UTC_TIMESTAMP(), decided_by = ? WHERE id = ?', ['approved', $decidedBy, $proposalId]);
            $this->audit($userId, $proposalId, 'user', 'mcp.proposal.approve', ['kind' => $proposal['kind'], 'revision' => $revision, 'decidedBy' => $decidedBy]);
            $pdo->commit();
            return ['ok' => true, 'status' => 'approved', 'revision' => $revision, 'kind' => $proposal['kind']];
        } catch (\Throwable $err) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $err;
        }
    }

    public function reject(int $userId, string $proposalId, int $decidedBy): array
    {
        $affected = $this->db->execute(
            'UPDATE mcp_proposals SET status = ?, decided_at = UTC_TIMESTAMP(), decided_by = ? WHERE id = ? AND user_id = ? AND status = ?',
            ['rejected', $decidedBy, $proposalId, $userId, 'pending']
        );
        if ($affected === 0) {
            throw new McpException('not_found', 'Propozycja nie istnieje lub została już rozstrzygnięta.', 404);
        }
        $this->audit($userId, $proposalId, 'user', 'mcp.proposal.reject', ['decidedBy' => $decidedBy]);
        return ['ok' => true, 'status' => 'rejected'];
    }

    public function auditLog(int $userId, int $limit = 50): array
    {
        return $this->db->query(
            'SELECT proposal_id, actor, action, detail_json, created_at FROM mcp_audit WHERE user_id = ? ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)),
            [$userId]
        );
    }

    // ------------------------------------------------------------------
    // Pomocnicze
    // ------------------------------------------------------------------

    private function audit(int $userId, ?string $proposalId, string $actor, string $action, array $detail = []): void
    {
        $this->db->execute(
            'INSERT INTO mcp_audit (proposal_id, user_id, actor, action, detail_json) VALUES (?, ?, ?, ?, ?)',
            [$proposalId, $userId, $actor, $action, json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
        );
    }

    private function state(int $userId): array
    {
        $row = $this->db->queryOne('SELECT state, revision FROM user_states WHERE user_id = ?', [$userId]);
        if ($row === null) {
            return [];
        }
        $state = json_decode((string) $row['state'], true);
        return is_array($state) ? $state : [];
    }

    private function includedTrades(array $state): array
    {
        $trades = is_array($state['trades'] ?? null) ? $state['trades'] : [];
        $accounts = is_array($state['accounts'] ?? null) ? $state['accounts'] : [];
        $included = [];
        foreach ($accounts as $account) {
            if (is_array($account) && ($account['include'] ?? true) !== false) {
                $included[] = (string) ($account['name'] ?? '');
            }
        }
        return array_values(array_filter($trades, static function (array $trade) use ($included): bool {
            $account = (string) ($trade['account'] ?? '');
            return $account === '' || in_array($account, $included, true);
        }));
    }

    private function closedTrades(array $state): array
    {
        return array_values(array_filter($this->includedTrades($state), static fn (array $t): bool => ($t['result'] ?? null) !== null));
    }

    private function isExchangeTrade(int $userId, array $trade): bool
    {
        $id = (string) ($trade['id'] ?? '');
        if ($id !== '') {
            try {
                $row = $this->db->queryOne('SELECT 1 AS ok FROM exchange_imports WHERE user_id = ? AND trade_id = ? LIMIT 1', [$userId, $id]);
                if ($row !== null) return true;
            } catch (\Throwable $err) {
                // Brak tabeli importów nie może blokować odczytu.
            }
        }
        $source = strtolower(trim((string) ($trade['source'] ?? '')));
        return $source !== '' && $source !== 'manual' && $source !== 'panel';
    }

    private function publicTrade(array $trade): array
    {
        $keys = ['id', 'pair', 'side', 'entry', 'size', 'sl', 'tp', 'result', 'r', 'mfe', 'mae', 'date', 'setup', 'emotion', 'grade', 'checklist', 'checkTotal', 'errors', 'note', 'account', 'plan', 'source', 'durationMin'];
        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $trade)) {
                $out[$key] = $trade[$key];
            }
        }
        return $out;
    }

    private function todayKey(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))->format('Y-m-d');
    }

    private function dateKey(string $iso): string
    {
        if ($iso === '') return '';
        try {
            return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('Y-m-d');
        } catch (\Throwable $err) {
            return '';
        }
    }

    private function todayCheckin(array $state): ?array
    {
        $today = $this->todayKey();
        foreach ((array) ($state['checkins'] ?? []) as $checkin) {
            if (is_array($checkin) && (string) ($checkin['date'] ?? '') === $today) return $checkin;
        }
        return null;
    }

    private function readinessFromCheckin(array $checkin): array
    {
        $moodMod = ['Spokój' => 10, 'Skupienie' => 7, 'Pewność' => 5, 'Nuda' => -3, 'Zmęczenie' => -8, 'Strach' => -10, 'FOMO' => -12, 'Złość' => -14, 'Chciwość' => -6][(string) ($checkin['mood'] ?? '')] ?? 0;
        $score = (int) max(0, min(100, round(50 + ((int) ($checkin['energy'] ?? 3) - 3) * 10 + $moodMod)));
        $title = 'Świetna forma';
        if ($score < 80) $title = 'Dobra forma';
        if ($score < 65) $title = 'Ostrożnie dziś';
        if ($score < 50) $title = 'Lepiej odpuścić';
        return ['score' => $score, 'title' => $title];
    }

    private function capital(array $state): float
    {
        $sum = 0.0;
        foreach ((array) ($state['accounts'] ?? []) as $account) {
            if (is_array($account) && ($account['include'] ?? true) !== false) {
                $sum += (float) ($account['balance'] ?? 0);
            }
        }
        return $sum;
    }

    private function nextEvent(): ?array
    {
        $row = $this->db->queryOne(
            'SELECT name, category, scheduled_start_iso FROM discord_scheduled_events
             WHERE is_deleted = 0 AND scheduled_start_time >= UTC_TIMESTAMP() ORDER BY scheduled_start_time ASC LIMIT 1'
        );
        if ($row === null) return null;
        return ['name' => (string) $row['name'], 'category' => (string) $row['category'], 'startAt' => (string) $row['scheduled_start_iso']];
    }

    private function syncState(): array
    {
        try {
            $row = $this->db->queryOne('SELECT last_status, last_success_at, events_count FROM discord_sync_state WHERE id = 1');
        } catch (\Throwable $err) {
            return ['status' => 'unknown', 'lastSuccessAt' => null, 'eventsCount' => 0];
        }
        if ($row === null) {
            return ['status' => 'never', 'lastSuccessAt' => null, 'eventsCount' => 0];
        }
        $stale = $row['last_success_at'] === null || strtotime((string) $row['last_success_at'] . ' UTC') < time() - 900;
        return [
            'status' => (string) $row['last_status'],
            'lastSuccessAt' => $row['last_success_at'],
            'eventsCount' => (int) $row['events_count'],
            'stale' => $stale,
        ];
    }

    private function normalizeDate(string $value): string
    {
        if ($value === '') {
            return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        }
        $ts = strtotime($value);
        if ($ts === false) {
            throw new McpException('bad_request', 'Data jest nieprawidłowa.', 400);
        }
        if ($ts > time() + 300) {
            throw new McpException('bad_request', 'Data nie może być z przyszłości.', 400);
        }
        return gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    private function diff(?array $before, array $after): array
    {
        $diff = [];
        $keys = array_unique(array_merge(array_keys($before ?? []), array_keys($after)));
        foreach ($keys as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            if ($old !== $new) {
                $diff[] = ['field' => (string) $key, 'before' => $old, 'after' => $new];
            }
        }
        return $diff;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
