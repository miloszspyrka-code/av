<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\OAuth;

use AlphaVault\Config;
use AlphaVault\Db;
use AlphaVault\Mcp\Keys;
use AlphaVault\Mcp\OAuth\Repositories\AccessTokenRepository;
use AlphaVault\Mcp\OAuth\Repositories\AuthCodeRepository;
use AlphaVault\Mcp\OAuth\Repositories\ClientRepository;
use AlphaVault\Mcp\OAuth\Repositories\RefreshTokenRepository;
use AlphaVault\Mcp\OAuth\Repositories\ScopeRepository;
use DateInterval;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;

final class OAuthServer
{
    public readonly AuthorizationServer $server;
    public readonly ClientRepository $clients;
    public readonly AccessTokenRepository $accessTokens;
    public readonly AuthCodeRepository $authCodes;
    public readonly RefreshTokenRepository $refreshTokens;
    public readonly ScopeRepository $scopes;

    public function __construct(
        private readonly Config $config,
        private readonly Db $db,
    ) {
        Keys::ensure($config);

        $this->clients = new ClientRepository($db);
        $this->accessTokens = new AccessTokenRepository($db, $config);
        $this->authCodes = new AuthCodeRepository($db);
        $this->refreshTokens = new RefreshTokenRepository($db);
        $this->scopes = new ScopeRepository($config);

        $privateKey = new CryptKey((string) $config->mcp['privateKeyPath'], null, true);
        $encryptionKey = Keys::encryptionKey($config);

        $this->server = new AuthorizationServer(
            $this->clients,
            $this->accessTokens,
            $this->scopes,
            $privateKey,
            $encryptionKey,
            new McpBearerTokenResponse()
        );
        $this->server->setDefaultScope('alphavault.read');

        $authCodeGrant = new AuthCodeGrant(
            $this->authCodes,
            $this->refreshTokens,
            new DateInterval('PT' . max(60, (int) $config->mcp['authCodeTtl']) . 'S')
        );
        $authCodeGrant->setRefreshTokenTTL(new DateInterval('PT' . max(3600, (int) $config->mcp['refreshTokenTtl']) . 'S'));

        $this->server->enableGrantType(
            $authCodeGrant,
            new DateInterval('PT' . max(300, (int) $config->mcp['accessTokenTtl']) . 'S')
        );
        $this->server->enableGrantType(
            new RefreshTokenGrant($this->refreshTokens),
            new DateInterval('PT' . max(300, (int) $config->mcp['accessTokenTtl']) . 'S')
        );
    }

    public function issuer(): string
    {
        return (string) $this->config->mcp['issuer'];
    }

    public function resource(): string
    {
        return (string) $this->config->mcp['resource'];
    }

    public function allowedScopes(): array
    {
        return $this->scopes->allowedScopes();
    }
}
