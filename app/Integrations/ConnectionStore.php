<?php
declare(strict_types=1);

namespace AlphaVault\Integrations;

use AlphaVault\Db;

/**
 * Trwały magazyn połączeń. Poświadczenia są zaszyfrowane; API widzi tylko maskę.
 */
final class ConnectionStore
{
    public function __construct(
        private readonly Db $db,
        private readonly Crypto $crypto,
    ) {
    }

    /** @return array<int,array<string,mixed>> */
    public function listForUser(int $userId): array
    {
        $rows = $this->db->query(
            'SELECT id, provider, label, account_ref, key_mask, permissions, status, last_sync_at,
                    last_sync_status, last_error, imported_count, created_at
             FROM exchange_connections WHERE user_id = ? ORDER BY id ASC',
            [$userId]
        );
        return array_map(fn (array $row): array => $this->publicRow($row), $rows);
    }

    /** @return array<string,mixed>|null */
    public function findForUser(int $userId, int $id): ?array
    {
        $row = $this->db->queryOne('SELECT * FROM exchange_connections WHERE user_id = ? AND id = ?', [$userId, $id]);
        return $row === null ? null : $this->privateRow($row);
    }

    /** @return array<string,mixed>|null */
    public function findForUserByProvider(int $userId, string $provider): ?array
    {
        $row = $this->db->queryOne(
            'SELECT * FROM exchange_connections WHERE user_id = ? AND provider = ? ORDER BY id DESC LIMIT 1',
            [$userId, $provider]
        );
        return $row === null ? null : $this->privateRow($row);
    }

    /** @param array<string,mixed> $result */
    public function save(int $userId, string $provider, array $result, array $credentials): array
    {
        $accountRef = substr((string) ($result['accountRef'] ?? ''), 0, 128);
        $label = substr((string) ($result['label'] ?? strtoupper($provider)), 0, 64);
        $keyMask = substr((string) ($result['keyMask'] ?? ''), 0, 64);
        $permissions = substr((string) ($result['permissions'] ?? 'read-only'), 0, 24);
        $statusValue = (string) ($result['status'] ?? 'connected');
        $status = in_array($statusValue, ['connected', 'pending'], true) ? $statusValue : 'connected';
        $sealed = $this->crypto->seal($credentials);

        $this->db->execute(
            'INSERT INTO exchange_connections
                (user_id, provider, label, account_ref, key_mask, credentials, permissions, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                label = VALUES(label), key_mask = VALUES(key_mask), credentials = VALUES(credentials),
                permissions = VALUES(permissions), status = VALUES(status), last_error = NULL, updated_at = NOW()',
            [$userId, $provider, $label, $accountRef, $keyMask, $sealed, $permissions, $status]
        );
        $row = $this->db->queryOne(
            'SELECT id FROM exchange_connections WHERE user_id = ? AND provider = ? AND account_ref = ?',
            [$userId, $provider, $accountRef]
        );
        if ($row === null) {
            throw new IntegrationException('store_failed', 'Nie można zapisać połączenia.', 500);
        }
        $id = (int) $row['id'];
        $fresh = $this->db->queryOne('SELECT * FROM exchange_connections WHERE id = ?', [$id]) ?? [];
        return $this->publicRow($fresh);
    }

    public function delete(int $userId, int $id): bool
    {
        return $this->db->execute('DELETE FROM exchange_connections WHERE user_id = ? AND id = ?', [$userId, $id]) > 0;
    }

    /** @return array<int,array<string,mixed>> */
    public function due(int $limit = 50): array
    {
        $rows = $this->db->query(
            "SELECT * FROM exchange_connections
             WHERE status IN ('connected', 'error', 'pending')
               AND (last_sync_at IS NULL OR last_sync_at < DATE_SUB(NOW(), INTERVAL 25 MINUTE))
             ORDER BY COALESCE(last_sync_at, '1970-01-01') ASC LIMIT " . max(1, min(200, $limit))
        );
        return array_map(fn (array $row): array => $this->privateRow($row), $rows);
    }

    public function markSync(int $id, string $status, ?string $error, int $imported, int $cursorMs, bool $backfillDone): void
    {
        $this->db->execute(
            'UPDATE exchange_connections
             SET last_sync_at = NOW(), last_sync_status = ?, last_error = ?, imported_count = imported_count + ?,
                 cursor_ms = ?, backfill_done = ?, status = ?, updated_at = NOW()
             WHERE id = ?',
            [
                $status,
                $error === null ? null : substr($error, 0, 500),
                $imported,
                $cursorMs,
                $backfillDone ? 1 : 0,
                $status === 'success' ? 'connected' : 'error',
                $id,
            ]
        );
    }

    public function markError(int $id, string $message): void
    {
        $this->db->execute(
            "UPDATE exchange_connections SET last_sync_at = NOW(), last_sync_status = 'error', last_error = ?, status = 'error', updated_at = NOW() WHERE id = ?",
            [substr($message, 0, 500), $id]
        );
    }

    public function recordRun(int $connectionId, string $status, int $fetched, int $imported, ?string $error): void
    {
        $this->db->execute(
            'INSERT INTO exchange_sync_runs (connection_id, started_at, finished_at, status, fetched, imported, error_message)
             VALUES (?, NOW(), NOW(), ?, ?, ?, ?)',
            [$connectionId, $status, $fetched, $imported, $error === null ? null : substr($error, 0, 500)]
        );
    }

    public function syncState(): array
    {
        $row = $this->db->queryOne('SELECT * FROM exchange_sync_state WHERE id = 1');
        return $row ?? ['last_status' => 'never'];
    }

    public function markScheduler(?string $status = null, ?string $error = null, ?int $connections = null, ?int $imported = null): void
    {
        $updated = $this->db->execute(
            "UPDATE exchange_sync_state
             SET last_attempt_at = NOW(),
                 last_success_at = IF(? = 'success', NOW(), last_success_at),
                 last_status = ?, last_error = ?,
                 connections_count = COALESCE(?, connections_count),
                 imported_count = COALESCE(?, imported_count),
                 updated_at = NOW()
             WHERE id = 1",
            [$status, $status, $error === null ? null : substr($error, 0, 500), $connections, $imported]
        );
        if ($updated === 0) {
            $this->db->execute(
                "INSERT INTO exchange_sync_state (id, last_attempt_at, last_success_at, last_status, last_error, connections_count, imported_count, updated_at)
                 VALUES (1, NOW(), ?, ?, ?, ?, ?, NOW())",
                [
                    $status === 'success' ? date('Y-m-d H:i:s') : null,
                    $status ?? 'running',
                    $error === null ? null : substr($error, 0, 500),
                    $connections ?? 0,
                    $imported ?? 0,
                ]
            );
        }
    }

    /** @return array{code:string,id:int} */
    public function createPairing(int $userId, string $platform): array
    {
        $platform = in_array($platform, ['mt4', 'mt5'], true) ? $platform : 'mt5';
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $raw = '';
        for ($i = 0; $i < 8; $i++) {
            $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $code = 'AV-' . substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
        $hash = hash('sha256', $code);
        $this->db->execute(
            "DELETE FROM exchange_connections WHERE user_id = ? AND provider = ? AND pairing_hash IS NOT NULL",
            [$userId, $platform]
        );
        $sealed = $this->crypto->seal(['pairing_hash' => $hash, 'platform' => $platform]);
        $this->db->execute(
            'INSERT INTO exchange_connections
                (user_id, provider, label, account_ref, key_mask, credentials, pairing_hash, permissions, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $userId,
                $platform,
                $platform === 'mt4' ? 'MetaTrader 4' : 'MetaTrader 5',
                'pairing:' . substr($hash, 0, 16),
                'EA ••••',
                $sealed,
                $hash,
                'read-only',
                'pending',
            ]
        );
        return ['code' => $code, 'id' => $this->db->lastInsertId()];
    }

    /** @return array<string,mixed>|null */
    public function findByPairingHash(string $hash): ?array
    {
        $row = $this->db->queryOne(
            "SELECT * FROM exchange_connections WHERE pairing_hash = ? AND status IN ('pending', 'connected') ORDER BY id DESC LIMIT 1",
            [$hash]
        );
        return $row === null ? null : $this->privateRow($row);
    }

    public function markPairingConnected(int $id, string $accountRef, int $imported): void
    {
        $this->db->execute(
            "UPDATE exchange_connections
             SET status = 'connected', account_ref = ?, key_mask = 'EA ••••', permissions = 'read-only',
                 last_sync_at = NOW(), last_sync_status = 'success', last_error = NULL,
                 imported_count = imported_count + ?, updated_at = NOW()
             WHERE id = ?",
            [substr($accountRef, 0, 128), $imported, $id]
        );
    }

    /** @param array<string,mixed> $row */
    private function privateRow(array $row): array
    {
        $credentials = [];
        try {
            $credentials = $this->crypto->open((string) $row['credentials']);
        } catch (\Throwable) {
            $credentials = [];
        }
        $row['credentials'] = $credentials;
        return $row;
    }

    /** @param array<string,mixed> $row */
    private function publicRow(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'provider' => (string) ($row['provider'] ?? ''),
            'label' => (string) ($row['label'] ?? ''),
            'accountRef' => (string) ($row['account_ref'] ?? ''),
            'keyMask' => (string) ($row['key_mask'] ?? ''),
            'permissions' => (string) ($row['permissions'] ?? 'read-only'),
            'status' => (string) ($row['status'] ?? 'connected'),
            'lastSyncAt' => $row['last_sync_at'] ?? null,
            'lastSyncStatus' => $row['last_sync_status'] ?? null,
            'lastError' => $row['last_error'] ?? null,
            'importedCount' => (int) ($row['imported_count'] ?? 0),
            'createdAt' => $row['created_at'] ?? null,
            'platform' => str_starts_with((string) ($row['provider'] ?? ''), 'mt') ? (string) $row['provider'] : null,
            'serverRef' => $this->serverRef($row),
        ];
    }

    /** @param array<string,mixed> $row */
    private function serverRef(array $row): ?string
    {
        if (!in_array((string) ($row['provider'] ?? ''), ['mt4', 'mt5'], true)) {
            return null;
        }
        if (!isset($row['credentials']) || !is_string($row['credentials']) || $row['credentials'] === '') {
            return null;
        }
        try {
            $credentials = $this->crypto->open((string) $row['credentials']);
            return isset($credentials['server']) ? (string) $credentials['server'] : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
