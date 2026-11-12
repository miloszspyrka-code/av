<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\OAuth\Repositories;

use AlphaVault\Config;
use AlphaVault\Mcp\OAuth\Entities\ScopeEntity;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;

final class ScopeRepository implements ScopeRepositoryInterface
{
    public function __construct(private readonly Config $config)
    {
    }

    public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface
    {
        return in_array($identifier, $this->allowedScopes(), true) ? new ScopeEntity($identifier) : null;
    }

    public function finalizeScopes(
        array $scopes,
        string $grantType,
        ClientEntityInterface $clientEntity,
        ?string $userIdentifier = null,
        ?string $authCodeId = null
    ): array {
        $allowed = $this->allowedScopes();
        $result = [];
        foreach ($scopes as $scope) {
            $identifier = $scope->getIdentifier();
            if (in_array($identifier, $allowed, true)) {
                $result[$identifier] = $scope;
            }
        }

        if ($result === []) {
            $result['alphavault.read'] = new ScopeEntity('alphavault.read');
        }

        return array_values($result);
    }

    public function allowedScopes(): array
    {
        $scopes = ['alphavault.read'];
        if ((bool) $this->config->mcp['writesEnabled']) {
            $scopes[] = 'alphavault.write';
        }

        return $scopes;
    }
}
