<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\OAuth\Entities;

use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\ScopeTrait;

final class ScopeEntity implements ScopeEntityInterface
{
    use ScopeTrait;

    public function __construct(private readonly string $identifier)
    {
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }
}
