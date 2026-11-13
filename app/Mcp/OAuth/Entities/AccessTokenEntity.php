<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\OAuth\Entities;

use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use League\OAuth2\Server\CryptKeyInterface;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

final class AccessTokenEntity implements AccessTokenEntityInterface
{
    use EntityTrait;
    use TokenEntityTrait;

    private CryptKeyInterface $privateKey;
    private Configuration $jwtConfiguration;
    private string $audience = '';
    private string $issuer = '';
    private string $keyId = '';

    public function setPrivateKey(CryptKeyInterface $privateKey): void
    {
        $this->privateKey = $privateKey;
    }

    public function setAudience(string $audience): void
    {
        $this->audience = $audience;
    }

    public function setIssuer(string $issuer): void
    {
        $this->issuer = $issuer;
    }

    public function setKeyId(string $keyId): void
    {
        $this->keyId = $keyId;
    }

    public function toString(): string
    {
        if ($this->audience === '' || $this->issuer === '' || $this->keyId === '') {
            throw new \LogicException('Access token nie ma ustawionego audience, issuer lub kid.');
        }
        if (!isset($this->privateKey)) {
            throw new \LogicException('Access token nie ma ustawionego klucza prywatnego.');
        }

        $this->jwtConfiguration = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText($this->privateKey->getKeyContents(), $this->privateKey->getPassPhrase() ?? ''),
            InMemory::plainText('empty', 'empty')
        );

        $scopes = array_map(
            static fn ($scope): string => $scope->getIdentifier(),
            $this->getScopes()
        );

        $token = $this->jwtConfiguration->builder()
            ->permittedFor($this->audience)
            ->issuedBy($this->issuer)
            ->identifiedBy($this->getIdentifier())
            ->issuedAt(new DateTimeImmutable())
            ->canOnlyBeUsedAfter(new DateTimeImmutable())
            ->expiresAt($this->getExpiryDateTime())
            ->relatedTo((string) $this->getUserIdentifier())
            ->withClaim('client_id', $this->getClient()->getIdentifier())
            ->withClaim('scope', implode(' ', $scopes))
            ->withClaim('scopes', $scopes)
            ->withHeader('kid', $this->keyId)
            ->withHeader('typ', 'at+jwt')
            ->getToken($this->jwtConfiguration->signer(), $this->jwtConfiguration->signingKey());

        return $token->toString();
    }
}
