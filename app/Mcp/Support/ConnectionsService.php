<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Support;

use AlphaVault\Db;

final class ConnectionsService
{
    public function __construct(private readonly Db $db)
    {
    }

    public function status(int $userId): array
    {
        $connections = [];
        try {
            $rows = $this->db->query(
                'SELECT provider, label, account_ref, permissions, status, last_sync_at, last_sync_status, imported_count, last_error
                 FROM exchange_connections WHERE user_id = ? ORDER BY provider ASC, id ASC',
                [$userId]
            );
            foreach ($rows as $row) {
                $connections[] = [
                    'provider' => (string) $row['provider'],
                    'label' => (string) $row['label'],
                    'accountRef' => $this->maskReference((string) ($row['account_ref'] ?? '')),
                    'permissions' => (string) $row['permissions'],
                    'status' => (string) $row['status'],
                    'lastSyncAt' => $row['last_sync_at'],
                    'lastSyncStatus' => $row['last_sync_status'],
                    'importedCount' => (int) $row['imported_count'],
                    'lastError' => $this->sanitizeError((string) ($row['last_error'] ?? '')),
                ];
            }
        } catch (\Throwable) {
            // Brak tabel integracji nie może blokować reszty MCP.
        }

        $sync = null;
        try {
            $row = $this->db->queryOne(
                'SELECT last_attempt_at, last_success_at, last_status, last_error, connections_count, imported_count FROM exchange_sync_state WHERE id = 1'
            );
            if ($row !== null) {
                $sync = [
                    'status' => (string) $row['last_status'],
                    'lastAttemptAt' => $row['last_attempt_at'],
                    'lastSuccessAt' => $row['last_success_at'],
                    'connectionsCount' => (int) $row['connections_count'],
                    'importedCount' => (int) $row['imported_count'],
                    'lastError' => $this->sanitizeError((string) ($row['last_error'] ?? '')),
                ];
            }
        } catch (\Throwable) {
            $sync = null;
        }

        return [
            'connections' => $connections,
            'total' => count($connections),
            'sync' => $sync,
            'note' => 'Poświadczenia, klucze API i tokeny nie są zwracane przez MCP.',
        ];
    }

    private function maskReference(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (mb_strlen($value) <= 4) {
            return str_repeat('•', mb_strlen($value));
        }

        return mb_substr($value, 0, 2) . str_repeat('•', max(4, mb_strlen($value) - 4)) . mb_substr($value, -2);
    }

    private function sanitizeError(string $message): ?string
    {
        $message = trim($message);
        if ($message === '') {
            return null;
        }
        $message = mb_substr($message, 0, 300);
        $message = preg_replace('/\b(sk|pk|api|key|token)[_-][A-Za-z0-9_-]{8,}\b/i', '[redacted]', $message) ?? $message;
        $message = preg_replace('/\b[A-Fa-f0-9]{32,}\b/', '[redacted]', $message) ?? $message;

        return $message;
    }
}
