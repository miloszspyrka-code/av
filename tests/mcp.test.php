<?php
declare(strict_types=1);

/**
 * Testy bezpieczeństwa MCP (17 scenariuszy).
 * Uruchom: php tests/mcp.test.php
 * Wymaga tests/config.test.json i tabel mcp_* (php app/db/migrate.php).
 */

putenv('ALPHA_VAULT_CONFIG=' . __DIR__ . '/config.test.json');
putenv('MCP_ENABLED=true');
putenv('MCP_WRITES_ENABLED=true');

require_once __DIR__ . '/../app/mcp/Mcp.php';

use AlphaVault\Db;
use AlphaVault\Mcp\Mcp;
use AlphaVault\Mcp\McpException;

[$config, $db] = require __DIR__ . '/../app/bootstrap.php';

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $info = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "PASS  {$name}" . ($info !== '' ? "  · {$info}" : '') . "\n";
    } else {
        $fail++;
        echo "FAIL  {$name}" . ($info !== '' ? "  · {$info}" : '') . "\n";
    }
}

$mcpFront = (string) file_get_contents(__DIR__ . '/../mcp/public/index.php');
check(
    '0. allowlista originów Claude w MCP',
    str_contains($mcpFront, "'https://claude.ai'") && str_contains($mcpFront, "'https://claude.com'"),
    'claude.ai + claude.com'
);

function tool(Mcp $mcp, array $auth, string $name, array $args): array
{
    return $mcp->callTool($name, $args, $auth);
}

function errorCode(array $result): ?string
{
    return $result['isError'] ? (string) ($result['structuredContent']['error'] ?? '') : null;
}

$ids = [];
$mcp = new Mcp($db);
try {
    $db->execute("DELETE u FROM users u WHERE u.discord_id LIKE 'mcp-test-%'");

    $createUser = static function (string $suffix) use ($db): int {
        $db->execute(
            'INSERT INTO users (discord_id, username, global_name, is_demo, plan, role_verified) VALUES (?, ?, ?, 1, ?, 1)',
            ['mcp-test-' . $suffix, 'mcp_' . $suffix, 'MCP ' . $suffix, 'full']
        );
        return (int) $db->lastInsertId();
    };
    $userA = $createUser('a-' . bin2hex(random_bytes(3)));
    $userB = $createUser('b-' . bin2hex(random_bytes(3)));
    $ids = [$userA, $userB];

    $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))->format('Y-m-d');
    $state = [
        'user' => ['name' => 'MCP Test'],
        'settings' => ['maxLosses' => 2, 'maxDailyLoss' => 3, 'maxRisk' => 1, 'safetyEnabled' => true],
        'plans' => [],
        'trades' => [
            ['id' => 'ex1', 'pair' => 'BTC/USDT', 'side' => 'long', 'entry' => 100, 'size' => 1, 'sl' => 90, 'tp' => 120, 'result' => 10, 'r' => 1, 'date' => gmdate('c'), 'source' => 'OKX', 'grade' => 'A', 'setup' => 'Breakout', 'emotion' => 'Spokój', 'checklist' => 7, 'checkTotal' => 7, 'fee' => 0.5],
            ['id' => 'man1', 'pair' => 'ETH/USDT', 'side' => 'long', 'entry' => 2000, 'size' => 0.1, 'result' => null, 'date' => gmdate('c'), 'source' => 'manual', 'setup' => 'Pullback'],
        ],
        'checkins' => [],
        'accounts' => [['name' => 'Main', 'balance' => 1000, 'include' => true]],
        'setups' => [['name' => 'Breakout'], ['name' => 'Pullback']],
        'tradeReviews' => [],
    ];
    $json = json_encode($state, JSON_UNESCAPED_UNICODE);
    $db->execute('INSERT INTO user_states (user_id, state, revision, bytes) VALUES (?, ?, 5, ?)', [$userA, $json, strlen($json)]);

    $tokenA = $mcp->createToken($userA, 'Test agent', [Mcp::SCOPE_READ, Mcp::SCOPE_PROPOSE]);
    $tokenB = $mcp->createToken($userB, 'Test read only', [Mcp::SCOPE_READ]);
    $authA = $mcp->authenticate($tokenA['token']);
    $authB = $mcp->authenticate($tokenB['token']);

    $read = tool($mcp, $authA, 'get_trade', ['id' => 'ex1']);
    check('1. odczyt transakcji giełdowej', !$read['isError'] && (float) $read['structuredContent']['entry'] === 100.0, 'entry=' . ($read['structuredContent']['entry'] ?? '?'));

    check('2. zmiana entry odrzucona', errorCode(tool($mcp, $authA, 'propose_trade_review', ['idempotencyKey' => 'idem-review-2', 'tradeId' => 'ex1', 'entry' => 111])) === 'exchange_data_readonly');
    check('3. zmiana fill odrzucona', errorCode(tool($mcp, $authA, 'propose_trade_review', ['idempotencyKey' => 'idem-review-3', 'tradeId' => 'ex1', 'exitPrice' => 130])) === 'exchange_data_readonly');
    check('4. zmiana SL/TP odrzucona', errorCode(tool($mcp, $authA, 'propose_trade_review', ['idempotencyKey' => 'idem-review-4', 'tradeId' => 'ex1', 'sl' => 50, 'tp' => 200])) === 'exchange_data_readonly');

    check('5. modyfikacja salda odrzucona', errorCode(tool($mcp, $authA, 'propose_panel_trade', ['idempotencyKey' => 'idem-trade-5', 'pair' => 'BTC/USDT', 'side' => 'long', 'entry' => 100, 'size' => 1, 'balance' => 999999])) === 'exchange_data_readonly');

    $review = tool($mcp, $authA, 'propose_trade_review', ['idempotencyKey' => 'idem-review-1', 'tradeId' => 'ex1', 'note' => 'Notatka agenta']);
    $reviewId = $review['structuredContent']['proposalId'] ?? '';
    if ($reviewId !== '') {
        $mcp->approve($userA, $reviewId, null, $userA);
    }
    $stateRow = $db->queryOne('SELECT state FROM user_states WHERE user_id = ?', [$userA]);
    $saved = json_decode((string) $stateRow['state'], true);
    $entryAfter = 0.0;
    foreach ($saved['trades'] as $trade) {
        if ($trade['id'] === 'ex1') $entryAfter = (float) $trade['entry'];
    }
    check('6. notatka w osobnym rekordzie', $entryAfter === 100.0 && ($saved['tradeReviews']['ex1']['note'] ?? '') === 'Notatka agenta', 'entry=' . $entryAfter);

    $plan = tool($mcp, $authA, 'propose_day_plan_change', ['idempotencyKey' => 'idem-plan-1', 'date' => $today, 'market' => 'BTC', 'why' => 'Test']);
    $planId = $plan['structuredContent']['proposalId'] ?? '';
    $stateRow = $db->queryOne('SELECT state FROM user_states WHERE user_id = ?', [$userA]);
    $saved = json_decode((string) $stateRow['state'], true);
    check('7. propozycja planu utworzona', $planId !== '' && ($plan['structuredContent']['status'] ?? '') === 'pending', $planId);
    check('8. plan bez zmian przed zatwierdzeniem', !isset($saved['plans'][$today]), 'plan=' . json_encode($saved['plans'][$today] ?? null));

    $mcp->reject($userA, $planId, $userA);
    $stateRow = $db->queryOne('SELECT state FROM user_states WHERE user_id = ?', [$userA]);
    $saved = json_decode((string) $stateRow['state'], true);
    check('9. odrzucenie propozycji', !isset($saved['plans'][$today]));

    $plan2 = tool($mcp, $authA, 'propose_day_plan_change', ['idempotencyKey' => 'idem-plan-2', 'date' => $today, 'market' => 'BTC', 'why' => 'Plan zatwierdzony']);
    $mcp->approve($userA, $plan2['structuredContent']['proposalId'], null, $userA);
    $stateRow = $db->queryOne('SELECT state FROM user_states WHERE user_id = ?', [$userA]);
    $saved = json_decode((string) $stateRow['state'], true);
    check('10. zatwierdzenie zmienia plan', ($saved['plans'][$today]['market'] ?? '') === 'BTC');

    $plan3 = tool($mcp, $authA, 'propose_day_plan_change', ['idempotencyKey' => 'idem-plan-3', 'date' => $today, 'market' => 'ETH', 'why' => 'Cudza']);
    $otherError = null;
    try {
        $mcp->approve($userB, $plan3['structuredContent']['proposalId'], null, $userB);
    } catch (McpException $err) {
        $otherError = $err->errorCode;
    }
    check('11. obca propozycja odrzucona', $otherError === 'not_found', (string) $otherError);

    $names = array_column($mcp->tools([Mcp::SCOPE_READ, Mcp::SCOPE_PROPOSE]), 'name');
    check('12. brak narzędzia approve', !in_array('approve_change', $names, true) && !in_array('create_trade', $names, true) && !in_array('close_trade', $names, true), count($names) . ' narzędzi');

    $dup1 = tool($mcp, $authA, 'propose_checkin', ['idempotencyKey' => 'idem-checkin-1', 'mood' => 'Spokój', 'energy' => 4]);
    $dup2 = tool($mcp, $authA, 'propose_checkin', ['idempotencyKey' => 'idem-checkin-1', 'mood' => 'Spokój', 'energy' => 4]);
    $dupCount = $db->queryOne('SELECT COUNT(*) AS n FROM mcp_proposals WHERE user_id = ? AND idempotency_key = ?', [$userA, 'idem-checkin-1']);
    check('13. brak duplikatu propozycji', ($dup1['structuredContent']['proposalId'] ?? '') === ($dup2['structuredContent']['proposalId'] ?? '') && (int) $dupCount['n'] === 1);

    $conflict = tool($mcp, $authA, 'propose_day_plan_change', ['idempotencyKey' => 'idem-plan-4', 'date' => $today, 'market' => 'SOL', 'why' => 'Konflikt']);
    $db->execute('UPDATE user_states SET revision = revision + 1 WHERE user_id = ?', [$userA]);
    $conflictError = null;
    try {
        $mcp->approve($userA, $conflict['structuredContent']['proposalId'], null, $userA);
    } catch (McpException $err) {
        $conflictError = $err->errorCode;
    }
    check('14. konflikt rewizji', $conflictError === 'revision_conflict', (string) $conflictError);

    $listB = tool($mcp, $authB, 'list_trades', []);
    $tradeB = tool($mcp, $authB, 'get_trade', ['id' => 'ex1']);
    check('15. dane innego użytkownika niedostępne', !$listB['isError'] && (int) ($listB['structuredContent']['total'] ?? -1) === 0 && errorCode($tradeB) === 'not_found');

    $noScope = tool($mcp, $authB, 'propose_checkin', ['idempotencyKey' => 'idem-scope-1', 'mood' => 'Spokój', 'energy' => 3]);
    check('16. brak zakresu blokuje propozycję', errorCode($noScope) === 'insufficient_scope', (string) errorCode($noScope));

    $audit = $mcp->auditLog($userA, 100);
    $actions = array_column($audit, 'action');
    check('17. wpisy audytu', in_array('mcp.proposal.create', $actions, true) && in_array('mcp.proposal.approve', $actions, true), implode(', ', array_unique($actions)));
} catch (\Throwable $err) {
    $fail++;
    echo 'FAIL  wyjątek testu  · ' . $err->getMessage() . "\n";
} finally {
    foreach ($ids as $id) {
        try {
            $db->execute('DELETE FROM audit_log WHERE user_id = ?', [$id]);
            $db->execute('DELETE FROM users WHERE id = ?', [$id]);
        } catch (\Throwable $err) {
            // Sprzątanie nie może maskować wyników testów.
        }
    }
}

echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
