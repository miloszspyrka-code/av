<?php
declare(strict_types=1);

/**
 * Helper testowy MCP. Nie jest częścią produkcyjnego API.
 *
 * Użycie:
 *   php tests/mcp-helper.php state <userId>
 *   php tests/mcp-helper.php decide <proposalId> <userId> approve|reject
 */

require_once __DIR__ . '/../vendor/autoload.php';

use AlphaVault\Mcp\Proposals\ProposalException;
use AlphaVault\Mcp\Proposals\ProposalService;

[$config, $db] = require __DIR__ . '/../app/bootstrap.php';
$command = (string) ($argv[1] ?? '');
$service = new ProposalService($db, $config);

try {
    if ($command === 'state') {
        $userId = (int) ($argv[2] ?? 0);
        $row = $db->queryOne('SELECT state, revision FROM user_states WHERE user_id = ?', [$userId]);
        echo json_encode([
            'state' => $row !== null ? json_decode((string) $row['state'], true) : null,
            'revision' => (int) ($row['revision'] ?? 0),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit(0);
    }
    if ($command === 'decide') {
        $proposalId = (string) ($argv[2] ?? '');
        $userId = (int) ($argv[3] ?? 0);
        $action = (string) ($argv[4] ?? '');
        $result = $action === 'approve'
            ? $service->approve($userId, $proposalId, $userId)
            : $service->reject($userId, $proposalId, $userId);
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit(0);
    }
    fwrite(STDERR, "Nieznana komenda\n");
    exit(2);
} catch (ProposalException $err) {
    echo json_encode(['error' => $err->errorCode, 'message' => $err->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(1);
}
