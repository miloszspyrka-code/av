<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\OAuth\Entities;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;

final class ClientEntity implements ClientEntityInterface
{
    use ClientTrait;

    public function __construct(
        private readonly string $identifier,
        string $name,
        array $redirectUris,
        bool $confidential,
    ) {
        $this->name = $name;
        $this->redirectUri = array_values($redirectUris);
        $this->isConfidential = $confidential;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }
}
