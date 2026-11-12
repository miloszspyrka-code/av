<?php
declare(strict_types=1);

$appPathFile = __DIR__ . '/app-path.php';
$appDir = is_file($appPathFile) ? require $appPathFile : dirname(__DIR__, 2) . '/app';
if (!is_string($appDir) || !is_dir($appDir)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'AlphaVault MCP: nieprawidłowa ścieżka aplikacji.';
    exit;
}

$autoload = dirname($appDir) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'AlphaVault MCP: brak zależności Composer.';
    exit;
}
require_once $autoload;

try {
    [$config, $db, $auth, $discord] = require $appDir . '/bootstrap.php';
} catch (\Throwable $err) {
    error_log('[mcp-bootstrap] ' . $err->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'AlphaVault MCP: błąd konfiguracji.';
    exit;
}

\AlphaVault\Http::securityHeaders($config->secureCookies);

$method = \AlphaVault\Http::method();
$path = \AlphaVault\Http::path();

$oauth = new \AlphaVault\Mcp\Http\OAuthController($config, $db, $auth, $discord);

if ($path === '/health' && $method === 'GET') {
    $oauth->health();
}

if (!$config->mcp['enabled']) {
    if ($path === '/') {
        \AlphaVault\Http::html('<!doctype html><html lang="pl"><meta charset="utf-8"><body style="background:#080707;color:#f5f1eb;font-family:system-ui;padding:40px"><h1>AlphaVault MCP</h1><p>MCP jest wyłączony.</p></body></html>', 503);
    }
    if (str_starts_with($path, '/.well-known/') || str_starts_with($path, '/oauth/')) {
        \AlphaVault\Http::json(['error' => 'mcp_disabled', 'message' => 'MCP jest wyłączony.'], 503);
    }
    \AlphaVault\Http::json([
        'jsonrpc' => '2.0',
        'id' => null,
        'error' => ['code' => -32000, 'message' => 'MCP jest wyłączony.'],
    ], 503);
}

if ($path === '/.well-known/oauth-protected-resource' || $path === '/.well-known/oauth-protected-resource/mcp') {
    if ($method !== 'GET') {
        \AlphaVault\Http::json(['error' => 'method_not_allowed'], 405);
    }
    $oauth->metadata();
}

if ($path === '/.well-known/oauth-authorization-server') {
    if ($method !== 'GET') {
        \AlphaVault\Http::json(['error' => 'method_not_allowed'], 405);
    }
    $oauth->authorizationServerMetadata();
}

if ($path === '/oauth/jwks.json') {
    if ($method !== 'GET') {
        \AlphaVault\Http::json(['error' => 'method_not_allowed'], 405);
    }
    $oauth->jwks();
}

if ($path === '/oauth/register') {
    if ($method !== 'POST') {
        \AlphaVault\Http::json(['error' => 'method_not_allowed'], 405);
    }
    $oauth->register();
}

if ($path === '/oauth/authorize') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        \AlphaVault\Http::json(['error' => 'method_not_allowed'], 405);
    }
    $oauth->authorize();
}

if ($path === '/oauth/token') {
    if ($method !== 'POST') {
        \AlphaVault\Http::json(['error' => 'method_not_allowed'], 405);
    }
    $oauth->token();
}

if ($path === '/oauth/revoke') {
    if ($method !== 'POST') {
        \AlphaVault\Http::json(['error' => 'method_not_allowed'], 405);
    }
    $oauth->revoke();
}

if ($path === '/oauth/login/start') {
    if ($method !== 'GET') {
        \AlphaVault\Http::json(['error' => 'method_not_allowed'], 405);
    }
    $oauth->loginStart();
}

if ($path === '/oauth/login') {
    if ($method !== 'GET') {
        \AlphaVault\Http::json(['error' => 'method_not_allowed'], 405);
    }
    $oauth->login();
}

if ($path === '/oauth/discord/callback') {
    if ($method !== 'GET') {
        \AlphaVault\Http::json(['error' => 'method_not_allowed'], 405);
    }
    $oauth->discordCallback();
}

if ($path === '/oauth/connected') {
    if ($method !== 'GET') {
        \AlphaVault\Http::json(['error' => 'method_not_allowed'], 405);
    }
    $oauth->connected();
}

if ($path === '/oauth/connected/revoke') {
    if ($method !== 'POST') {
        \AlphaVault\Http::json(['error' => 'method_not_allowed'], 405);
    }
    $oauth->connectedRevoke();
}

if ($path === '/mcp') {
    (new \AlphaVault\Mcp\Http\McpController($config, $db, $auth, $discord))->handle();
}

if ($path === '/') {
    $oauth->home();
}

\AlphaVault\Http::json(['error' => 'not_found', 'message' => 'Nie ma takiego endpointu.'], 404);
