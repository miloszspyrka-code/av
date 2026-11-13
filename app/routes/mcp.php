<?php
declare(strict_types=1);

use AlphaVault\Auth;
use AlphaVault\Config;
use AlphaVault\Db;
use AlphaVault\Http;
use AlphaVault\Mcp\Proposals\ProposalException;
use AlphaVault\Mcp\Proposals\ProposalService;

require_once dirname(__DIR__) . '/Mcp/Support/Dates.php';
require_once dirname(__DIR__) . '/Mcp/Proposals/ProposalException.php';
require_once dirname(__DIR__) . '/Mcp/Proposals/ProposalService.php';

/**
 * Panelowa część MCP. Zatwierdzanie i odrzucanie propozycji odbywa się
 * wyłącznie w sesji panelu AlphaVault — nigdy przez token MCP.
 */
function av_handle_mcp_api(Config $config, Db $db, Auth $auth, ?array $user, string $method, string $path): void
{
    $current = $auth->requireAuth($user);
    $userId = (int) $current['id'];
    $service = new ProposalService($db, $config);

    try {
        if ($path === '/api/mcp' && $method === 'GET') {
            $pending = $service->list($userId, 'pending');
            Http::json([
                'endpoint' => 'https://mcp.alphavault.pl/mcp',
                'enabled' => true,
                'writesEnabled' => (bool) $config->mcp['writesEnabled'],
                'scopes' => (bool) $config->mcp['writesEnabled']
                    ? ['alphavault.read', 'alphavault.write']
                    : ['alphavault.read'],
                'proposals' => $pending,
                'proposalCount' => count($pending),
                'audit' => $service->auditLog($userId, 20),
            ]);
        }

        if (preg_match('#^/api/mcp/proposals/([a-f0-9-]{36})/decide$#', $path, $matches) && $method === 'POST') {
            $auth->requireSameOrigin();
            $auth->rateLimit('mcp-proposal-decide', 60, 60000);
            $body = Http::readJsonBody(8192);
            $action = (string) ($body['action'] ?? '');
            if (!in_array($action, ['approve', 'reject'], true)) {
                Http::json(['error' => 'bad_request', 'message' => 'action musi być approve albo reject.'], 400);
            }
            $result = $action === 'approve'
                ? $service->approve($userId, $matches[1], $userId)
                : $service->reject($userId, $matches[1], $userId);
            Http::json($result);
        }

        if ($path === '/api/mcp/proposals/decide' && $method === 'POST') {
            $auth->requireSameOrigin();
            $auth->rateLimit('mcp-proposal-decide', 60, 60000);
            $body = Http::readJsonBody(8192);
            $id = trim((string) ($body['id'] ?? ''));
            $action = (string) ($body['action'] ?? '');
            if ($id === '' || !in_array($action, ['approve', 'reject'], true)) {
                Http::json(['error' => 'bad_request', 'message' => 'Pola id i action (approve|reject) są wymagane.'], 400);
            }
            $result = $action === 'approve'
                ? $service->approve($userId, $id, $userId)
                : $service->reject($userId, $id, $userId);
            Http::json($result);
        }

        Http::json(['error' => 'not_found', 'message' => 'Nieznany endpoint MCP.'], 404);
    } catch (ProposalException $err) {
        Http::json(['error' => $err->errorCode, 'message' => $err->getMessage()], $err->status);
    }
}
