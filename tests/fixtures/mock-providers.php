<?php
declare(strict_types=1);

/**
 * Mock dostawców dla testów E2E integracji.
 * Uruchom: php -S 127.0.0.1:4191 tests/fixtures/mock-providers.php
 *
 * Serwer weryfikuje podpisy HMAC, żeby test wykrywał błędy w kliencie,
 * a nie tylko szczęśliwe odpowiedzi.
 */

$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = parse_url($uri, PHP_URL_PATH) ?: '/';
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$now = (int) round(microtime(true) * 1000);

function out(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function headerValue(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return (string) ($_SERVER[$key] ?? '');
}

function okxSignValid(string $secret): bool
{
    $timestamp = headerValue('OK-ACCESS-TIMESTAMP');
    $sign = headerValue('OK-ACCESS-SIGN');
    if ($timestamp === '' || $sign === '') {
        return false;
    }
    $requestPath = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $requestPath = preg_replace('#^/okx#', '', $requestPath) ?? $requestPath;
    $prehash = $timestamp . 'GET' . $requestPath;
    return hash_equals(base64_encode(hash_hmac('sha256', $prehash, $secret, true)), $sign);
}

function weexSignValid(string $secret): bool
{
    $timestamp = headerValue('ACCESS-TIMESTAMP');
    $sign = headerValue('ACCESS-SIGN');
    if ($timestamp === '' || $sign === '') {
        return false;
    }
    $requestPath = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $requestPath = preg_replace('#^/weex#', '', $requestPath) ?? $requestPath;
    $body = (string) file_get_contents('php://input');
    $prehash = $timestamp . strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) . $requestPath . $body;
    return hash_equals(base64_encode(hash_hmac('sha256', $prehash, $secret, true)), $sign);
}

function bingxSignValid(string $secret): bool
{
    $params = $_GET;
    $signature = (string) ($params['signature'] ?? '');
    unset($params['signature']);
    ksort($params);
    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    return hash_equals(hash_hmac('sha256', $query, $secret), $signature);
}

// ---------- OKX ----------
if (str_starts_with($path, '/okx/')) {
    if (!okxSignValid('okx-secret')) {
        out(['code' => '50113', 'msg' => 'Invalid Sign', 'data' => []], 401);
    }
    $apiKey = headerValue('OK-ACCESS-KEY');
    if ($path === '/okx/api/v5/account/config') {
        $perm = $apiKey === 'okx-trade' ? 'read_only,trade' : 'read_only';
        out(['code' => '0', 'msg' => '', 'data' => [[
            'uid' => 'okx-uid-1', 'perm' => $perm, 'acctLv' => '2',
        ]]]);
    }
    if ($path === '/okx/api/v5/account/positions-history') {
        out(['code' => '0', 'msg' => '', 'data' => [
            [
                'posId' => '111', 'instId' => 'BTC-USDT-SWAP', 'posSide' => 'long',
                'openAvgPx' => '60000', 'closeAvgPx' => '61000', 'closeTotalPos' => '0.1',
                'realizedPnl' => '100', 'fee' => '-0.5', 'fundingFee' => '-0.1', 'liqPenalty' => '0',
                'openTime' => (string) ($now - 7200000), 'closeTime' => (string) ($now - 3600000),
            ],
            [
                'posId' => '222', 'instId' => 'ETH-USDT-SWAP', 'posSide' => 'short',
                'openAvgPx' => '3000', 'closeAvgPx' => '2950', 'closeTotalPos' => '1',
                'realizedPnl' => '50', 'fee' => '-0.2', 'fundingFee' => '0', 'liqPenalty' => '0',
                'openTime' => (string) ($now - 10800000), 'closeTime' => (string) ($now - 7200000),
            ],
        ]]);
    }
    if ($path === '/okx/api/v5/trade/fills-history') {
        out(['code' => '0', 'msg' => '', 'data' => []]);
    }
    out(['code' => '404', 'msg' => 'Not found', 'data' => []], 404);
}

// ---------- BingX ----------
if (str_starts_with($path, '/bingx/')) {
    if (!bingxSignValid('bingx-secret')) {
        out(['code' => 100001, 'msg' => 'Signature error', 'data' => []], 401);
    }
    $apiKey = headerValue('X-BX-APIKEY');
    if ($path === '/bingx/openApi/v1/account/apiPermissions') {
        $trade = $apiKey === 'bingx-trade';
        out(['code' => 0, 'msg' => '', 'data' => [
            'enableReading' => true,
            'enableSpotAndMarginTrading' => $trade,
            'enableFutures' => $trade,
            'enableWithdrawals' => false,
            'enableInternalTransfer' => false,
            'permitsUniversalTransfer' => false,
            'enableVanillaOptions' => false,
        ]]);
    }
    if ($path === '/bingx/openApi/swap/v2/user/income') {
        out(['code' => 0, 'msg' => '', 'data' => [[
            'symbol' => 'BTC-USDT', 'incomeType' => 'REALIZED_PNL', 'income' => '50',
            'asset' => 'USDT', 'time' => $now - 3600000, 'tranId' => 'inc-1', 'tradeId' => 'inc-1',
        ]]]);
    }
    if ($path === '/bingx/openApi/swap/v1/trade/positionHistory') {
        out(['code' => 0, 'msg' => '', 'data' => ['positionHistory' => [[
            'positionId' => '333', 'symbol' => 'BTC-USDT', 'positionSide' => 'LONG',
            'openTime' => $now - 7200000, 'updateTime' => $now - 3600000,
            'avgPrice' => '60000', 'avgClosePrice' => '60500',
            'realisedProfit' => '50', 'netProfit' => '49.5',
            'positionAmt' => '0.1', 'closePositionAmt' => '0.1',
            'positionCommission' => '-0.4', 'totalFunding' => '-0.1',
        ]]]]);
    }
    out(['code' => 404, 'msg' => 'Not found', 'data' => []], 404);
}

// ---------- WEEX ----------
if (str_starts_with($path, '/weex/')) {
    if (!weexSignValid('weex-secret')) {
        out(['code' => -1001, 'msg' => 'Invalid signature'], 401);
    }
    $apiKey = headerValue('ACCESS-KEY');
    if ($path === '/weex/api/v3/account') {
        out(['uid' => 999, 'permissions' => ['SPOT'], 'canTrade' => $apiKey === 'weex-trade']);
    }
    if ($path === '/weex/capi/v3/account/balance') {
        out([['asset' => 'USDT', 'balance' => '1000', 'availableBalance' => '1000', 'frozen' => '0']]);
    }
    if ($path === '/weex/api/v3/order' || $path === '/weex/capi/v3/order') {
        if ($apiKey === 'weex-trade') {
            out(['code' => -1100, 'msg' => 'Invalid symbol']);
        }
        out(['code' => -1052, 'msg' => 'INSUFFICIENT_PERMISSIONS']);
    }
    if ($path === '/weex/capi/v3/userTrades') {
        out([
            [
                'id' => 501, 'orderId' => 1, 'symbol' => 'BTCUSDT', 'buyer' => true,
                'commission' => '-0.1', 'commissionAsset' => 'USDT', 'maker' => false,
                'price' => '100', 'qty' => '1', 'quoteQty' => '100', 'realizedPnl' => '0',
                'side' => 'BUY', 'positionSide' => 'LONG', 'time' => $now - 7200000,
            ],
            [
                'id' => 502, 'orderId' => 2, 'symbol' => 'BTCUSDT', 'buyer' => false,
                'commission' => '-0.1', 'commissionAsset' => 'USDT', 'maker' => false,
                'price' => '110', 'qty' => '1', 'quoteQty' => '110', 'realizedPnl' => '10',
                'side' => 'SELL', 'positionSide' => 'LONG', 'time' => $now - 3600000,
            ],
        ]);
    }
    out(['code' => 404, 'msg' => 'Not found'], 404);
}

// ---------- MetaApi ----------
if ($path === '/metaapi/users/current/accounts' && $method === 'POST') {
    if (headerValue('auth-token') !== 'test-metaapi-token') {
        out(['error' => 'Unauthorized', 'message' => 'bad token'], 401);
    }
    $body = json_decode((string) file_get_contents('php://input'), true);
    $password = (string) ($body['password'] ?? '');
    $id = $password === 'master' ? 'meta-master' : 'meta-investor';
    out(['id' => $id, 'state' => 'DEPLOYED', 'region' => 'us-east-1'], 201);
}
if (preg_match('#^/metaapi/users/current/accounts/([^/]+)$#', $path, $m)) {
    if ($method === 'DELETE') {
        http_response_code(204);
        exit;
    }
    out(['id' => $m[1], 'state' => 'DEPLOYED', 'connectionStatus' => 'CONNECTED', 'region' => 'us-east-1']);
}
if (preg_match('#^/metaapi-client/[^/]+/users/current/accounts/([^/]+)/account-information$#', $path, $m)) {
    out([
        'login' => 81234567,
        'platform' => 'mt5',
        'investorMode' => $m[1] !== 'meta-master',
        'balance' => 10000,
        'equity' => 10000,
        'currency' => 'USD',
    ]);
}
if (preg_match('#^/metaapi-client/[^/]+/users/current/accounts/([^/]+)/history-deals/time/#', $path, $m)) {
    out([
        [
            'id' => 'd1', 'positionId' => 'mt-pos-1', 'symbol' => 'XAUUSD',
            'type' => 'DEAL_TYPE_BUY', 'entryType' => 'DEAL_ENTRY_IN',
            'volume' => 0.1, 'price' => 2000, 'profit' => 0, 'commission' => -0.2, 'swap' => 0,
            'time' => gmdate('Y-m-d\TH:i:s.000\Z', time() - 7200),
        ],
        [
            'id' => 'd2', 'positionId' => 'mt-pos-1', 'symbol' => 'XAUUSD',
            'type' => 'DEAL_TYPE_SELL', 'entryType' => 'DEAL_ENTRY_OUT',
            'volume' => 0.1, 'price' => 2010, 'profit' => 100, 'commission' => -0.2, 'swap' => -0.1,
            'time' => gmdate('Y-m-d\TH:i:s.000\Z', time() - 3600),
        ],
    ]);
}

out(['error' => 'mock_not_found', 'path' => $path], 404);
