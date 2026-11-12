<?php
declare(strict_types=1);

/**
 * AlphaVault MCP — front controller (Streamable HTTP, JSON responses).
 *
 * Endpoint: POST /mcp
 * Autoryzacja: Authorization: Bearer <token panelu>
 */

use AlphaVault\Mcp\Mcp;
use AlphaVault\Mcp\McpException;

require_once dirname(__DIR__, 2) . '/app/mcp/Mcp.php';

[$config, $db] = require dirname(__DIR__, 2) . '/app/bootstrap.php';

const MCP_PROTOCOL_DEFAULT = '2025-06-18';
const MCP_BODY_LIMIT = 65536;

function mcp_json(int $status, array $payload, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mcp_rpc_error(int $status, int $code, string $message, mixed $id = null): never
{
    mcp_json($status, ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cross-Origin-Resource-Policy: same-origin');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

if ($path !== '/mcp') {
    mcp_json(404, ['error' => 'not_found']);
}
if ($method !== 'POST') {
    header('Allow: POST');
    mcp_json(405, ['error' => 'method_not_allowed']);
}

$allowedHosts = array_filter(array_map('trim', explode(',', (string) getenv('MCP_ALLOWED_HOSTS'))));
if ($allowedHosts !== []) {
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if (!in_array($host, $allowedHosts, true)) {
        mcp_json(400, ['error' => 'bad_host']);
    }
}

$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
    $allowedOrigins = array_filter(array_map('trim', explode(',', (string) getenv('MCP_ALLOWED_ORIGINS'))));
    if ($allowedOrigins === []) {
        $allowedOrigins = ['https://chatgpt.com', 'https://chat.openai.com', 'https://platform.openai.com', 'https://grok.com', 'https://x.ai'];
    }
    if (!in_array($origin, $allowedOrigins, true)) {
        mcp_json(403, ['error' => 'bad_origin']);
    }
}

$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
if (!str_starts_with($contentType, 'application/json')) {
    mcp_json(415, ['error' => 'unsupported_media_type']);
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > MCP_BODY_LIMIT) {
    mcp_json(413, ['error' => 'body_too_large']);
}
$request = json_decode($raw, true);
if (!is_array($request) || ($request['jsonrpc'] ?? '') !== '2.0' || !isset($request['method']) || !is_string($request['method'])) {
    mcp_rpc_error(400, -32600, 'Invalid Request');
}

$id = $request['id'] ?? null;
$rpcMethod = $request['method'];
$params = is_array($request['params'] ?? null) ? $request['params'] : [];

$token = '';
$authHeader = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches) === 1) {
    $token = trim($matches[1]);
}

$mcp = new Mcp($db);
try {
    $auth = $mcp->authenticate($token);
} catch (McpException $err) {
    $status = $err->status === 503 ? 503 : 401;
    mcp_json($status, ['error' => $err->errorCode, 'message' => $err->getMessage()], [
        'WWW-Authenticate' => 'Bearer resource_metadata="https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/.well-known/oauth-protected-resource"',
    ]);
}

if (!in_array($rpcMethod, ['initialize', 'notifications/initialized', 'ping', 'tools/list', 'tools/call', 'resources/list', 'prompts/list'], true)) {
    mcp_rpc_error(200, -32601, 'Method not found', $id);
}

if ($rpcMethod === 'notifications/initialized') {
    http_response_code(202);
    exit;
}

if ($rpcMethod === 'initialize') {
    $protocol = (string) ($params['protocolVersion'] ?? ($_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? MCP_PROTOCOL_DEFAULT));
    mcp_json(200, [
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => [
            'protocolVersion' => $protocol,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'alphavault-mcp', 'version' => '1.0.0'],
            'instructions' => 'AlphaVault MCP. Dane giełdowe i historia finansowa są tylko do odczytu. Zmiany danych panelu wymagają propozycji i zatwierdzenia przez użytkownika w panelu AlphaVault. Nie istnieje narzędzie zatwierdzające propozycję.',
        ],
    ]);
}

if ($rpcMethod === 'ping') {
    mcp_json(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => (object) []]);
}

if ($rpcMethod === 'tools/list') {
    $tools = $mcp->tools($auth['scopes']);
    mcp_json(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => $tools]]);
}

if ($rpcMethod === 'resources/list') {
    mcp_json(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['resources' => []]]);
}

if ($rpcMethod === 'prompts/list') {
    mcp_json(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['prompts' => []]]);
}

$name = (string) ($params['name'] ?? '');
$args = $params['arguments'] ?? [];
if (!is_array($args)) {
    $args = [];
}
if ($name === '') {
    mcp_rpc_error(200, -32602, 'Invalid params', $id);
}

try {
    $result = $mcp->callTool($name, $args, $auth);
} catch (McpException $err) {
    $result = [
        'isError' => true,
        'content' => [['type' => 'text', 'text' => $err->getMessage()]],
        'structuredContent' => ['error' => $err->errorCode, 'message' => $err->getMessage()],
    ];
} catch (\Throwable $err) {
    error_log('[mcp] ' . $err->getMessage());
    $result = [
        'isError' => true,
        'content' => [['type' => 'text', 'text' => 'Błąd wewnętrzny MCP.']],
        'structuredContent' => ['error' => 'internal_error'],
    ];
}

mcp_json(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
