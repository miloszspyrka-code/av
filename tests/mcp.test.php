<?php
declare(strict_types=1);

/**
 * Testy integracyjne MCP 2.0: propozycje, idempotencja, konflikt rewizji,
 * ochrona danych giełdowych i izolacja użytkowników.
 *
 * Uruchom: php tests/mcp.test.php (wymaga tests/config.test.json i migracji).
 */

putenv('ALPHA_VAULT_CONFIG=' . __DIR__ . '/config.test.json');
putenv('MCP_ENABLED=true');
putenv('MCP_WRITES_ENABLED=true');

require_once __DIR__ . '/../vendor/autoload.php';

use AlphaVault\Db;
use AlphaVault\Mcp\Proposals\ProposalBuilder;
use AlphaVault\Mcp\Proposals\ProposalException;
use AlphaVault\Mcp\Proposals\ProposalService;
use AlphaVault\Mcp\Support\Dates;
use AlphaVault\Mcp\Support\PropChallengeService;

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

function expectError(callable $fn): string
{
    try {
        $fn();
    } catch (ProposalException $err) {
        return $err->errorCode;
    } catch (\Throwable $err) {
        return 'unexpected:' . $err->getMessage();
    }

    return 'none';
}

$ids = [];
try {
    $db->execute("DELETE u FROM users u WHERE u.discord_id LIKE 'mcp2-test-%'");
    $createUser = static function (string $suffix) use ($db): int {
        $db->execute(
            'INSERT INTO users (discord_id, username, global_name, is_demo, plan, role_verified) VALUES (?, ?, ?, 1, ?, 1)',
            ['mcp2-test-' . $suffix, 'mcp2_' . $suffix, 'MCP2 ' . $suffix, 'full']
        );
        return (int) $db->lastInsertId();
    };
    $userA = $createUser('a-' . bin2hex(random_bytes(3)));
    $userB = $createUser('b-' . bin2hex(random_bytes(3)));
    $ids = [$userA, $userB];
    $today = Dates::todayKey();
    $state = [
        'user' => ['name' => 'MCP2 Test', 'currency' => 'USDT'],
        'settings' => [
            'maxLosses' => 2, 'maxDailyLoss' => 3, 'maxRisk' => 1, 'safetyEnabled' => true,
            'checklist' => ['Trend', 'Poziom', 'Potwierdzenie', 'SL', 'R:R', 'Makro', 'Głowa'],
        ],
        'plans' => [],
        'trades' => [
            ['id' => 'ex1', 'pair' => 'BTC/USDT', 'dir' => 'l', 'entry' => '100', 'size' => '1', 'sl' => '90', 'tp' => '120', 'exit' => '110', 'result' => 10, 'r' => 1, 'date' => gmdate('c'), 'source' => 'OKX', 'grade' => 'A', 'setup' => 'Breakout', 'checklist' => 7, 'checkTotal' => 7, 'account' => 'Main'],
            ['id' => 'man1', 'pair' => 'ETH/USDT', 'dir' => 'l', 'entry' => '2000', 'size' => '0.1', 'sl' => '1900', 'tp' => '2200', 'exit' => 'otwarta', 'result' => null, 'r' => null, 'date' => gmdate('c'), 'source' => 'Ręcznie', 'grade' => 'B', 'setup' => 'Pullback', 'account' => 'Main'],
        ],
        'checkins' => [],
        'accounts' => [['name' => 'Main', 'balance' => 1000, 'start' => 1000, 'include' => true, 'currency' => 'USDT', 'type' => 'Futures', 'exchange' => 'OKX']],
        'flows' => [],
        'setups' => [['id' => 'pb1', 'name' => 'Breakout', 'tf' => 'H1', 'grade' => 'A', 'rules' => ['A', 'B', 'C'], 'bad' => false, 'seed' => 1], ['id' => 'pb2', 'name' => 'Pullback', 'tf' => 'M15', 'grade' => 'B', 'rules' => ['A', 'B'], 'bad' => false, 'seed' => 2]],
        'tradeReviews' => [],
        'prop' => ['challenges' => [], 'selectedId' => null],
    ];
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $db->execute('INSERT INTO user_states (user_id, state, revision, bytes) VALUES (?, ?, 5, ?)', [$userA, $json, strlen($json)]);
    $jsonB = json_encode(['trades' => [], 'accounts' => [], 'settings' => []], JSON_UNESCAPED_UNICODE);
    $db->execute('INSERT INTO user_states (user_id, state, revision, bytes) VALUES (?, ?, 1, ?)', [$userB, $jsonB, strlen($jsonB)]);
    $db->execute(
        'INSERT INTO exchange_imports (user_id, provider, external_id, trade_id) VALUES (?, ?, ?, ?)',
        [$userA, 'okx', 'ext-ex1', 'ex1']
    );

    $service = new ProposalService($db, $config);
    $builder = new ProposalBuilder($db, $service);

    // 1. Propozycja transakcji nie zmienia stanu przed zatwierdzeniem.
    $proposal = $builder->createTrade($userA, [
        'pair' => 'sol/usdt', 'dir' => 'long', 'entry' => 100, 'size' => 2,
        'sl' => 95, 'tp' => 120, 'setup' => 'Breakout', 'emotion' => 'Spokój',
        'note' => 'Test agenta', 'account' => 'Main', 'idempotencyKey' => 'idem-trade-1',
    ], 'test-client');
    $beforeRow = $db->queryOne('SELECT state FROM user_states WHERE user_id = ?', [$userA]);
    $beforeState = json_decode((string) $beforeRow['state'], true);
    check('1. propozycja utworzona, stan bez zmian', ($proposal['status'] ?? '') === 'pending' && count($beforeState['trades']) === 2, $proposal['proposalId'] ?? '');

    // 2. Idempotencja.
    $duplicate = $builder->createTrade($userA, [
        'pair' => 'sol/usdt', 'dir' => 'long', 'entry' => 100, 'size' => 2,
        'sl' => 95, 'tp' => 120, 'setup' => 'Breakout', 'emotion' => 'Spokój',
        'note' => 'Test agenta', 'account' => 'Main', 'idempotencyKey' => 'idem-trade-1',
    ], 'test-client');
    check('2. ten sam payload = ta sama propozycja', ($duplicate['proposalId'] ?? '') === ($proposal['proposalId'] ?? '') && ($duplicate['duplicate'] ?? false) === true);

    // 3. Konflikt idempotencji.
    $conflict = expectError(fn () => $builder->createTrade($userA, [
        'pair' => 'sol/usdt', 'dir' => 'short', 'entry' => 100, 'size' => 2,
        'setup' => 'Bez setupu', 'idempotencyKey' => 'idem-trade-1',
    ], 'test-client'));
    check('3. ten sam klucz i inny payload = konflikt', $conflict === 'idempotency_conflict', $conflict);

    // 4. Zatwierdzenie tworzy transakcję ręczną.
    $service->approve($userA, $proposal['proposalId'], $userA);
    $afterRow = $db->queryOne('SELECT state, revision FROM user_states WHERE user_id = ?', [$userA]);
    $afterState = json_decode((string) $afterRow['state'], true);
    $created = null;
    foreach ($afterState['trades'] as $trade) {
        if ((string) ($trade['id'] ?? '') === (string) ($afterState['trades'][2]['id'] ?? '')) {
            $created = $trade;
        }
    }
    $lastTrade = $afterState['trades'][count($afterState['trades']) - 1] ?? null;
    check('4. zatwierdzenie zapisuje transakcję', $lastTrade !== null
        && (string) ($lastTrade['pair'] ?? '') === 'SOL/USDT'
        && (string) ($lastTrade['dir'] ?? '') === 'l'
        && (string) ($lastTrade['source'] ?? '') === 'Ręcznie'
        && (int) $afterRow['revision'] === 6, json_encode($lastTrade, JSON_UNESCAPED_UNICODE));

    // 5. Konflikt rewizji.
    $stale = $builder->createTrade($userA, [
        'pair' => 'ADA/USDT', 'dir' => 'short', 'entry' => 1, 'size' => 10, 'idempotencyKey' => 'idem-trade-stale',
    ], 'test-client');
    $db->execute('UPDATE user_states SET revision = revision + 1 WHERE user_id = ?', [$userA]);
    $staleError = expectError(fn () => $service->approve($userA, $stale['proposalId'], $userA));
    check('5. nieaktualna rewizja blokuje zapis', $staleError === 'revision_conflict', $staleError);

    // 6. Zamknięcie ręcznej transakcji.
    $close = $builder->closeTrade($userA, ['tradeId' => 'man1', 'exitPrice' => 2100, 'idempotencyKey' => 'idem-close-1'], 'test-client');
    $service->approve($userA, $close['proposalId'], $userA);
    $row = $db->queryOne('SELECT state FROM user_states WHERE user_id = ?', [$userA]);
    $saved = json_decode((string) $row['state'], true);
    $closed = null;
    foreach ($saved['trades'] as $trade) {
        if ((string) ($trade['id'] ?? '') === 'man1') {
            $closed = $trade;
        }
    }
    check('6. zamknięcie liczy wynik i R', $closed !== null && (float) $closed['result'] === 10.0 && (float) $closed['r'] === 1.0 && (string) $closed['exit'] === '2100', json_encode($closed));

    // 7. Zamknięcie transakcji giełdowej odrzucone.
    $exchangeClose = expectError(fn () => $builder->closeTrade($userA, ['tradeId' => 'ex1', 'exitPrice' => 130, 'idempotencyKey' => 'idem-close-ex'], 'test-client'));
    check('7. import giełdowy nie do zamknięcia', $exchangeClose === 'exchange_data_readonly', $exchangeClose);

    // 8. Recenzja transakcji giełdowej nie zmienia pól finansowych.
    $review = $builder->tradeReview($userA, ['tradeId' => 'ex1', 'note' => 'Recenzja agenta', 'grade' => 'B', 'idempotencyKey' => 'idem-review-1'], 'test-client');
    $service->approve($userA, $review['proposalId'], $userA);
    $row = $db->queryOne('SELECT state FROM user_states WHERE user_id = ?', [$userA]);
    $saved = json_decode((string) $row['state'], true);
    $entryAfter = 0.0;
    foreach ($saved['trades'] as $trade) {
        if ((string) ($trade['id'] ?? '') === 'ex1') {
            $entryAfter = (float) $trade['entry'];
        }
    }
    check('8. recenzja w osobnym obszarze', $entryAfter === 100.0 && ($saved['tradeReviews']['ex1']['note'] ?? '') === 'Recenzja agenta', 'entry=' . $entryAfter);

    // 9. Ochrona pól finansowych.
    $forbidden = expectError(fn () => $builder->tradeReview($userA, ['tradeId' => 'ex1', 'entry' => 111, 'idempotencyKey' => 'idem-review-2'], 'test-client'));
    $forbidden2 = expectError(fn () => $builder->createTrade($userA, ['pair' => 'BTC/USDT', 'dir' => 'long', 'entry' => 1, 'size' => 1, 'balance' => 999, 'idempotencyKey' => 'idem-trade-2'], 'test-client'));
    check('9. entry i balance odrzucone', $forbidden === 'exchange_data_readonly' && $forbidden2 === 'exchange_data_readonly', $forbidden . '/' . $forbidden2);

    // 10. Plan dnia bez automatycznego approvedAt.
    $plan = $builder->saveDayPlan($userA, ['date' => $today, 'market' => 'BTC', 'bias' => 'long', 'why' => 'Test planu', 'riskPercent' => 2, 'maxTrades' => 3, 'levels' => [['type' => 'support', 'price' => 60000, 'note' => 'Wsparcie']], 'idempotencyKey' => 'idem-plan-1'], 'test-client');
    $service->approve($userA, $plan['proposalId'], $userA);
    $row = $db->queryOne('SELECT state FROM user_states WHERE user_id = ?', [$userA]);
    $saved = json_decode((string) $row['state'], true);
    check('10. plan zapisany bez approvedAt', ($saved['plans'][$today]['market'] ?? '') === 'BTC' && ($saved['plans'][$today]['approvedAt'] ?? null) === null && ($saved['plans'][$today]['risk'] ?? '') === '2%', json_encode($saved['plans'][$today] ?? null));

    // 11. Check-in i score.
    $checkin = $builder->saveCheckin($userA, ['mood' => 'Spokój', 'energy' => 4, 'note' => 'Dobry sen', 'idempotencyKey' => 'idem-checkin-1'], 'test-client');
    $service->approve($userA, $checkin['proposalId'], $userA);
    $row = $db->queryOne('SELECT state FROM user_states WHERE user_id = ?', [$userA]);
    $saved = json_decode((string) $row['state'], true);
    $todayCheckin = $saved['checkins'][0] ?? null;
    check('11. check-in score zgodny z UI', $todayCheckin !== null && (int) $todayCheckin['score'] === 70, json_encode($todayCheckin));

    // 12. Setup: dodanie i edycja.
    $setup = $builder->saveSetup($userA, ['name' => 'Nowy setup', 'rules' => ['Zasada 1', 'Zasada 2'], 'tf' => 'H4', 'grade' => 'A', 'idempotencyKey' => 'idem-setup-1'], 'test-client');
    $service->approve($userA, $setup['proposalId'], $userA);
    $row = $db->queryOne('SELECT state FROM user_states WHERE user_id = ?', [$userA]);
    $saved = json_decode((string) $row['state'], true);
    $found = array_values(array_filter($saved['setups'], static fn (array $s): bool => ($s['name'] ?? '') === 'Nowy setup'));
    check('12. setup dodany', count($found) === 1 && count($found[0]['rules']) === 2, json_encode($found));

    // 13. Prop challenge: dodanie.
    $prop = $builder->savePropChallenge($userA, ['name' => 'FTMO Test', 'templateId' => 'ftmo-2step', 'account' => 'Main', 'accountSize' => 10000, 'startBalance' => 10000, 'startDate' => $today, 'endDate' => $today, 'idempotencyKey' => 'idem-prop-1'], 'test-client');
    $service->approve($userA, $prop['proposalId'], $userA);
    $row = $db->queryOne('SELECT state FROM user_states WHERE user_id = ?', [$userA]);
    $saved = json_decode((string) $row['state'], true);
    $challenge = $saved['prop']['challenges'][0] ?? null;
    check('13. prop challenge dodany', $challenge !== null && ($challenge['name'] ?? '') === 'FTMO Test' && ($challenge['templateId'] ?? '') === 'ftmo-2step', json_encode($challenge));

    // 14. Izolacja użytkowników.
    $foreign = $builder->saveCheckin($userA, ['mood' => 'Spokój', 'energy' => 3, 'idempotencyKey' => 'idem-foreign-1'], 'test-client');
    $foreignError = expectError(fn () => $service->approve($userB, $foreign['proposalId'], $userB));
    check('14. obca propozycja niedostępna', $foreignError === 'not_found', $foreignError);

    // 15. Wygaśnięcie propozycji.
    $expiring = $builder->saveCheckin($userA, ['mood' => 'Skupienie', 'energy' => 3, 'idempotencyKey' => 'idem-expire-1'], 'test-client');
    $db->execute('UPDATE mcp_proposals SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = ?', [$expiring['proposalId']]);
    $expiredError = expectError(fn () => $service->approve($userA, $expiring['proposalId'], $userA));
    check('15. wygasła propozycja odrzucona', $expiredError === 'proposal_expired', $expiredError);

    // 16. PropChallengeService: pusty challenge i trailing.
    $engine = new PropChallengeService();
    $empty = $engine->compute(['id' => 'c1', 'name' => 'Empty', 'account' => 'Main', 'currency' => 'USD', 'accountSize' => 10000, 'startBalance' => 10000, 'targetPct' => 10, 'dailyLossPct' => 5, 'maxLossPct' => 10, 'drawdownType' => 'static', 'dailyBasis' => 'initial', 'minDays' => 0, 'startDate' => '2026-11-01', 'endDate' => '2026-11-30'], [], new \DateTimeImmutable('2026-11-12 12:00:00', Dates::tz()));
    $trailing = $engine->compute(['id' => 'c2', 'name' => 'Trail', 'account' => 'Main', 'currency' => 'USD', 'accountSize' => 10000, 'startBalance' => 10000, 'targetPct' => 10, 'dailyLossPct' => 5, 'maxLossPct' => 10, 'drawdownType' => 'trailing', 'dailyBasis' => 'initial', 'minDays' => 0, 'startDate' => '2026-11-01', 'endDate' => '2026-11-30'], [
        ['date' => '2026-11-03T10:00:00Z', 'result' => 500, 'account' => 'Main'],
        ['date' => '2026-11-04T10:00:00Z', 'result' => -1200, 'account' => 'Main'],
    ], new \DateTimeImmutable('2026-11-12 12:00:00', Dates::tz()));
    check('16. silnik prop: pusty i trailing', $empty['n'] === 0 && $empty['status'] === 'active' && $trailing['maxDrawdownAmount'] === 1200.0 && $trailing['peak'] === 10500.0, json_encode([$empty['status'], $trailing['maxDrawdownAmount'], $trailing['peak']]));

    // 17. Audyt.
    $actions = array_column($service->auditLog($userA, 100), 'action');
    check('17. audyt zawiera utworzenie i zatwierdzenie', in_array('mcp.proposal.create', $actions, true) && in_array('mcp.proposal.approve', $actions, true), implode(', ', array_unique($actions)));
} catch (\Throwable $err) {
    $fail++;
    echo 'FAIL  wyjątek testu  · ' . $err->getMessage() . "\n";
    echo $err->getTraceAsString() . "\n";
} finally {
    foreach ($ids as $id) {
        try {
            $db->execute('DELETE FROM audit_log WHERE user_id = ?', [$id]);
            $db->execute('DELETE FROM users WHERE id = ?', [$id]);
        } catch (\Throwable) {
            // Sprzątanie nie może maskować wyników.
        }
    }
}

echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
