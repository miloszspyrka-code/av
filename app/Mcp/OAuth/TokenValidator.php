<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\OAuth;

use AlphaVault\Config;
use AlphaVault\Db;
use AlphaVault\Mcp\Keys;
use DateInterval;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\HasClaim;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\RequiredConstraintsViolated;
use League\OAuth2\Server\Exception\OAuthServerException;
use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;
use Psr\Clock\ClockInterface;

final class TokenValidator implements AuthorizationTokenValidatorInterface
{
    private Configuration $jwt;
    private string $expectedKeyId;

    public function __construct(
        private readonly Config $config,
        private readonly Db $db,
    ) {
        $this->expectedKeyId = Keys::keyId($config);
        $this->jwt = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText('empty'),
            InMemory::file((string) $config->mcp['publicKeyPath'])
        );
    }

    public function validate(string $accessToken): AuthorizationResult
    {
        try {
            $token = $this->jwt->parser()->parse($accessToken);
        } catch (\Throwable) {
            return AuthorizationResult::unauthorized('invalid_token', 'Token jest nieprawidłowy.');
        }

        if (!$token instanceof Plain) {
            return AuthorizationResult::unauthorized('invalid_token', 'Token nie jest tokenem JWT.');
        }

        $headers = $token->headers();
        if ($headers->get('alg') !== 'RS256' || $headers->get('typ') !== 'at+jwt') {
            return AuthorizationResult::unauthorized('invalid_token', 'Nieobsługiwany typ tokenu.');
        }
        if ($headers->get('kid') !== $this->expectedKeyId) {
            return AuthorizationResult::unauthorized('invalid_token', 'Nieznany klucz tokenu.');
        }

        try {
            $this->jwt->validator()->assert(
                $token,
                new SignedWith(new Sha256(), InMemory::file((string) $this->config->mcp['publicKeyPath'])),
                new LooseValidAt($this->clock(), new DateInterval('PT30S')),
                new IssuedBy((string) $this->config->mcp['issuer']),
                new PermittedFor((string) $this->config->mcp['resource']),
                new HasClaim('client_id'),
                new HasClaim('scope')
            );
        } catch (RequiredConstraintsViolated) {
            return AuthorizationResult::unauthorized('invalid_token', 'Token nie przeszedł walidacji.');
        } catch (\Throwable) {
            return AuthorizationResult::unauthorized('invalid_token', 'Token nie przeszedł walidacji.');
        }

        $claims = $token->claims();
        $jti = (string) $claims->get('jti');
        $sub = (string) $claims->get('sub');
        $clientId = (string) $claims->get('client_id');
        if ($jti === '' || $sub === '' || $clientId === '' || !ctype_digit($sub)) {
            return AuthorizationResult::unauthorized('invalid_token', 'Token nie zawiera pełnej tożsamości.');
        }

        try {
            $row = $this->db->queryOne(
                'SELECT id, user_id, client_id, revoked, expires_at FROM mcp_access_tokens WHERE id = ? LIMIT 1',
                [$jti]
            );
        } catch (\Throwable) {
            return AuthorizationResult::unavailable(30);
        }
        if ($row === null || (bool) $row['revoked'] || (int) $row['user_id'] !== (int) $sub) {
            return AuthorizationResult::unauthorized('invalid_token', 'Token został unieważniony.');
        }
        if (strtotime((string) $row['expires_at'] . ' UTC') < time()) {
            return AuthorizationResult::unauthorized('invalid_token', 'Token wygasł.');
        }

        $scopeClaim = (string) $claims->get('scope');
        $scopes = array_values(array_filter(
            preg_split('/\s+/', trim($scopeClaim)) ?: [],
            static fn (string $scope): bool => $scope !== ''
        ));
        if ($scopes === []) {
            return AuthorizationResult::unauthorized('invalid_token', 'Token nie ma uprawnień.');
        }

        $claimsArray = $claims->all();
        $claimsArray['scopes'] = $scopes;

        return AuthorizationResult::allow(new AccessToken($scopes, $claimsArray));
    }

    private function clock(): ClockInterface
    {
        return new class () implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            }
        };
    }
}
