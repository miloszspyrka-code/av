<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\OAuth\Repositories;

use AlphaVault\Db;
use AlphaVault\Mcp\OAuth\Entities\AuthCodeEntity;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;

final class AuthCodeRepository implements AuthCodeRepositoryInterface
{
    public function __construct(private readonly Db $db)
    {
    }

    public function getNewAuthCode(): AuthCodeEntityInterface
    {
        return new AuthCodeEntity();
    }

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        try {
            $this->db->execute(
                'INSERT INTO mcp_auth_codes (id, client_id, user_id, expires_at) VALUES (?, ?, ?, ?)',
                [
                    $authCodeEntity->getIdentifier(),
                    $authCodeEntity->getClient()->getIdentifier(),
                    (int) $authCodeEntity->getUserIdentifier(),
                    $authCodeEntity->getExpiryDateTime()->format('Y-m-d H:i:s'),
                ]
            );
        } catch (\PDOException $err) {
            if ((string) $err->getCode() === '23000') {
                throw UniqueTokenIdentifierConstraintViolationException::create();
            }
            throw $err;
        }
    }

    public function revokeAuthCode(string $codeId): void
    {
        $this->db->execute('UPDATE mcp_auth_codes SET revoked = 1 WHERE id = ?', [$codeId]);
    }

    public function isAuthCodeRevoked(string $codeId): bool
    {
        $row = $this->db->queryOne('SELECT revoked FROM mcp_auth_codes WHERE id = ? LIMIT 1', [$codeId]);
        if ($row === null) {
            return true;
        }

        return (bool) $row['revoked'];
    }
}
