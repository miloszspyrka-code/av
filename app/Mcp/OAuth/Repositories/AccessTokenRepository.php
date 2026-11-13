<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\OAuth\Repositories;

use AlphaVault\Config;
use AlphaVault\Db;
use AlphaVault\Mcp\Keys;
use AlphaVault\Mcp\OAuth\Entities\AccessTokenEntity;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;

final class AccessTokenRepository implements AccessTokenRepositoryInterface
{
    public function __construct(
        private readonly Db $db,
        private readonly Config $config,
    ) {
    }

    public function getNewToken(
        ClientEntityInterface $clientEntity,
        array $scopes,
        ?string $userIdentifier = null
    ): AccessTokenEntityInterface {
        $token = new AccessTokenEntity();
        $token->setClient($clientEntity);
        if ($userIdentifier !== null) {
            $token->setUserIdentifier($userIdentifier);
        }
        foreach ($scopes as $scope) {
            $token->addScope($scope);
        }
        $token->setAudience((string) $this->config->mcp['resource']);
        $token->setIssuer((string) $this->config->mcp['issuer']);
        $token->setKeyId(Keys::keyId($this->config));

        return $token;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $scopes = array_map(
            static fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(),
            $accessTokenEntity->getScopes()
        );

        try {
            $this->db->execute(
                'INSERT INTO mcp_access_tokens (id, client_id, user_id, scopes, audience, expires_at) VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $accessTokenEntity->getIdentifier(),
                    $accessTokenEntity->getClient()->getIdentifier(),
                    (int) $accessTokenEntity->getUserIdentifier(),
                    json_encode(array_values($scopes), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    (string) $this->config->mcp['resource'],
                    $accessTokenEntity->getExpiryDateTime()->format('Y-m-d H:i:s'),
                ]
            );
        } catch (\PDOException $err) {
            if ((string) $err->getCode() === '23000') {
                throw UniqueTokenIdentifierConstraintViolationException::create();
            }
            throw $err;
        }
    }

    public function revokeAccessToken(string $tokenId): void
    {
        $this->db->execute('UPDATE mcp_access_tokens SET revoked = 1 WHERE id = ?', [$tokenId]);
    }

    public function isAccessTokenRevoked(string $tokenId): bool
    {
        $row = $this->db->queryOne('SELECT revoked FROM mcp_access_tokens WHERE id = ? LIMIT 1', [$tokenId]);
        if ($row === null) {
            return true;
        }

        return (bool) $row['revoked'];
    }

    public function find(string $tokenId): ?array
    {
        return $this->db->queryOne('SELECT * FROM mcp_access_tokens WHERE id = ? LIMIT 1', [$tokenId]);
    }

    public function findForUser(int $userId, string $tokenId): ?array
    {
        return $this->db->queryOne(
            'SELECT * FROM mcp_access_tokens WHERE id = ? AND user_id = ? LIMIT 1',
            [$tokenId, $userId]
        );
    }
}
