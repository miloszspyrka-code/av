<?php
declare(strict_types=1);

use AlphaVault\Auth;
use AlphaVault\Config;
use AlphaVault\Db;
use AlphaVault\Http;
use AlphaVault\Mcp\Mcp;
use AlphaVault\Mcp\McpException;

require_once dirname(__DIR__) . '/mcp/Mcp.php';

function av_handle_mcp_api(Config $config, Db $db, Auth $auth, ?array $user, string $method, string $path): void
{
    $mcp = new Mcp($db);
    $current = $auth->requireAuth($user);
    $userId = (int) $current['id'];

    try {
        if ($path === '/api/mcp' && $method === 'GET') {
            Http::json([
                'enabled' => $mcp->enabled(),
                'writesEnabled' => $mcp->writesEnabled(),
                'scopes' => [Mcp::SCOPE_READ, Mcp::SCOPE_PROPOSE],
                'tokens' => $mcp->tokens($userId),
                'proposals' => $mcp->proposals($userId, 'pending'),
                'audit' => $mcp->auditLog($userId, 20),
            ]);
        }

        if ($path === '/api/mcp/tokens' && $method === 'POST') {
            $auth->requireSameOrigin();
            $auth->rateLimit('mcp-token-create', 10, 60000);
            $active = count(array_filter($mcp->tokens($userId), static fn (array $t): bool => $t['revokedAt'] === null));
            if ($active >= 10) {
                Http::json(['error' => 'token_limit', 'message' => 'Limit aktywnych tokenów: 10. Unieważnij stary token.'], 409);
            }
            $body = Http::readJsonBody(8192);
            $scopes = is_array($body['scopes'] ?? null) ? $body['scopes'] : [Mcp::SCOPE_READ];
            $created = $mcp->createToken($userId, (string) ($body['name'] ?? 'Agent MCP'), $scopes, null);
            Http::json([
                'ok' => true,
                'id' => $created['id'],
                'token' => $created['token'],
                'scopes' => $created['scopes'],
                'message' => 'Token widoczny tylko teraz. Zapisz go w kliencie MCP.',
            ], 201);
        }

        if ($path === '/api/mcp/tokens/revoke' && $method === 'POST') {
            $auth->requireSameOrigin();
            $body = Http::readJsonBody(4096);
            $mcp->revokeToken($userId, (int) ($body['id'] ?? 0));
            Http::json(['ok' => true]);
        }

        if ($path === '/api/mcp/proposals/decide' && $method === 'POST') {
            $auth->requireSameOrigin();
            $auth->rateLimit('mcp-proposal-decide', 60, 60000);
            $body = Http::readJsonBody(8192);
            $id = trim((string) ($body['id'] ?? ''));
            $action = (string) ($body['action'] ?? '');
            $baseRevision = isset($body['baseRevision']) && is_numeric($body['baseRevision']) ? (int) $body['baseRevision'] : null;
            if ($id === '' || !in_array($action, ['approve', 'reject'], true)) {
                Http::json(['error' => 'bad_request', 'message' => 'Pole id i action (approve|reject) są wymagane.'], 400);
            }
            $result = $action === 'approve'
                ? $mcp->approve($userId, $id, $baseRevision, $userId)
                : $mcp->reject($userId, $id, $userId);
            Http::json($result);
        }

        Http::json(['error' => 'not_found', 'message' => 'Nieznany endpoint MCP.'], 404);
    } catch (McpException $err) {
        Http::json(['error' => $err->errorCode, 'message' => $err->getMessage()], $err->status);
    }
}
