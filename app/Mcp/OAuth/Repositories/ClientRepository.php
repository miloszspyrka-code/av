<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\OAuth\Repositories;

use AlphaVault\Db;
use AlphaVault\Mcp\OAuth\Entities\ClientEntity;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;

final class ClientRepository implements ClientRepositoryInterface
{
    public function __construct(private readonly Db $db)
    {
    }

    public function getClientEntity(string $clientIdentifier): ?ClientEntityInterface
    {
        $row = $this->db->queryOne('SELECT * FROM mcp_clients WHERE client_id = ? LIMIT 1', [$clientIdentifier]);
        if ($row === null) {
            return null;
        }
        $uris = json_decode((string) $row['redirect_uris'], true);

        return new ClientEntity(
            (string) $row['client_id'],
            (string) $row['client_name'],
            is_array($uris) ? array_values(array_filter($uris, 'is_string')) : [],
            (bool) $row['is_confidential'],
        );
    }

    public function validateClient(string $clientIdentifier, ?string $clientSecret, ?string $grantType): bool
    {
        $row = $this->db->queryOne(
            'SELECT client_secret_hash, is_confidential FROM mcp_clients WHERE client_id = ? LIMIT 1',
            [$clientIdentifier]
        );
        if ($row === null) {
            return false;
        }
        if (!(bool) $row['is_confidential']) {
            return true;
        }
        if ($clientSecret === null || $clientSecret === '' || empty($row['client_secret_hash'])) {
            return false;
        }

        return password_verify($clientSecret, (string) $row['client_secret_hash']);
    }

    public function register(string $clientName, array $redirectUris, bool $confidential): array
    {
        $clientId = 'mcp_' . bin2hex(random_bytes(16));
        $secret = null;
        $secretHash = null;
        if ($confidential) {
            $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $secretHash = password_hash($secret, PASSWORD_DEFAULT);
        }

        $this->db->execute(
            'INSERT INTO mcp_clients (client_id, client_name, redirect_uris, is_confidential, client_secret_hash) VALUES (?, ?, ?, ?, ?)',
            [
                $clientId,
                $clientName,
                json_encode(array_values($redirectUris), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $confidential ? 1 : 0,
                $secretHash,
            ]
        );

        return [
            'client_id' => $clientId,
            'client_name' => $clientName,
            'redirect_uris' => array_values($redirectUris),
            'is_confidential' => $confidential,
            'client_secret' => $secret,
        ];
    }

    public function touch(string $clientId): void
    {
        $this->db->execute('UPDATE mcp_clients SET last_used_at = UTC_TIMESTAMP() WHERE client_id = ?', [$clientId]);
    }

    public function listForUser(int $userId): array
    {
        return $this->db->query(
            'SELECT c.client_id, c.client_name, c.created_at,
                    MAX(t.created_at) AS last_token_at,
                    SUM(CASE WHEN t.revoked = 0 AND t.expires_at > UTC_TIMESTAMP() THEN 1 ELSE 0 END) AS active_tokens
               FROM mcp_access_tokens t
               JOIN mcp_clients c ON c.client_id = t.client_id
              WHERE t.user_id = ?
              GROUP BY c.client_id, c.client_name, c.created_at
              ORDER BY last_token_at DESC',
            [$userId]
        );
    }

    public function revokeAllForUser(int $userId, string $clientId): void
    {
        $this->db->execute(
            'UPDATE mcp_access_tokens SET revoked = 1 WHERE user_id = ? AND client_id = ? AND revoked = 0',
            [$userId, $clientId]
        );
        $this->db->execute(
            'UPDATE mcp_refresh_tokens SET revoked = 1 WHERE user_id = ? AND client_id = ? AND revoked = 0',
            [$userId, $clientId]
        );
    }
}
