<?php
declare(strict_types=1);

/**
 * Helper testowy panelu MCP: tworzy dwie propozycje dla użytkownika demo.
 * Użycie: php tests/seed-mcp-ui.php <userId>
 */

require_once __DIR__ . '/../vendor/autoload.php';

use AlphaVault\Mcp\Proposals\ProposalService;
use AlphaVault\Mcp\Support\Dates;

[$config, $db] = require __DIR__ . '/../app/bootstrap.php';
$userId = (int) ($argv[1] ?? 0);
if ($userId <= 0) {
    fwrite(STDERR, "Brak userId\n");
    exit(2);
}

$service = new ProposalService($db, $config);
$db->execute("DELETE FROM mcp_proposals WHERE user_id = ? AND client_name = 'UI E2E'", [$userId]);
$suffix = bin2hex(random_bytes(4));
$today = Dates::todayKey();

$checkin = $service->create($userId, 'UI E2E', 'checkin', 'checkin:' . $today, null, [
    'date' => $today,
    'mood' => 'Spokój',
    'energy' => 4,
    'note' => 'Propozycja z testu UI',
    'score' => 70,
], 'ui-e2e-checkin-' . $suffix);

$setupId = 'pb_ui_' . $suffix;
$setup = $service->create($userId, 'UI E2E', 'setup', 'setup:' . $setupId, null, [
    'id' => $setupId,
    'name' => 'UI E2E Setup ' . $suffix,
    'tf' => 'H1',
    'grade' => 'A',
    'rules' => ['Zasada UI 1', 'Zasada UI 2'],
    'bad' => false,
    'seed' => 42,
], 'ui-e2e-setup-' . $suffix);

echo json_encode([
    'checkin' => $checkin['proposalId'],
    'setup' => $setup['proposalId'],
    'setupId' => $setupId,
    'today' => $today,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
