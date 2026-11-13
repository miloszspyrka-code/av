<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\OAuth\Repositories;

use AlphaVault\Db;
use AlphaVault\Mcp\OAuth\Entities\RefreshTokenEntity;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;

final class RefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    public function __construct(private readonly Db $db)
    {
    }

    public function getNewRefreshToken(): ?RefreshTokenEntityInterface
    {
        return new RefreshTokenEntity();
    }

    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        try {
            $this->db->execute(
                'INSERT INTO mcp_refresh_tokens (id, client_id, user_id, expires_at) VALUES (?, ?, ?, ?)',
                [
                    $refreshTokenEntity->getIdentifier(),
                    $refreshTokenEntity->getAccessToken()->getClient()->getIdentifier(),
                    (int) $refreshTokenEntity->getAccessToken()->getUserIdentifier(),
                    $refreshTokenEntity->getExpiryDateTime()->format('Y-m-d H:i:s'),
                ]
            );
        } catch (\PDOException $err) {
            if ((string) $err->getCode() === '23000') {
                throw UniqueTokenIdentifierConstraintViolationException::create();
            }
            throw $err;
        }
    }

    public function revokeRefreshToken(string $tokenId): void
    {
        $this->db->execute('UPDATE mcp_refresh_tokens SET revoked = 1 WHERE id = ?', [$tokenId]);
    }

    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        $row = $this->db->queryOne('SELECT revoked FROM mcp_refresh_tokens WHERE id = ? LIMIT 1', [$tokenId]);
        if ($row === null) {
            return true;
        }

        return (bool) $row['revoked'];
    }
}
