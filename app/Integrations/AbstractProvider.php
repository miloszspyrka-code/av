<?php
declare(strict_types=1);

namespace AlphaVault\Integrations;

abstract class AbstractProvider implements ProviderInterface
{
    public function __construct(
        protected readonly HttpClient $http,
        protected readonly array $bases,
    ) {
    }

    protected function base(string $key, string $default = ''): string
    {
        $value = (string) ($this->bases[$key] ?? $default);
        return rtrim($value, '/');
    }

    /** @param array<string,mixed> $json */
    protected function requireFields(array $input, array $fields): void
    {
        foreach ($fields as $field) {
            if (!isset($input[$field]) || trim((string) $input[$field]) === '') {
                throw new IntegrationException('missing_fields', 'Uzupełnij wszystkie pola: ' . implode(', ', $fields), 422);
            }
        }
    }

    /** @param array<string,string> $headers */
    protected function getJson(string $url, array $headers = [], int $timeout = 20): array
    {
        $res = $this->http->request('GET', $url, $headers, null, $timeout);
        return $this->decode($res);
    }

    /** @param array<string,string> $headers */
    protected function postJson(string $url, array $headers, array $payload, int $timeout = 20): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $res = $this->http->request('POST', $url, $headers + ['Content-Type' => 'application/json'], $body === false ? '{}' : $body, $timeout);
        return $this->decode($res);
    }

    /** @return array{status:int,headers:array<string,string>,body:string,json:?array} */
    protected function requestRaw(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): array
    {
        return $this->http->request($method, $url, $headers, $body, $timeout);
    }

    /** @return array<string,mixed> */
    protected function decode(array $res): array
    {
        if ($res['json'] === null) {
            throw new IntegrationException('invalid_response', 'Dostawca zwrócił niepoprawną odpowiedź (HTTP ' . $res['status'] . ').', 502);
        }
        return $res['json'];
    }

    protected function providerError(string $message, int $status = 502): never
    {
        throw new IntegrationException('provider_error', $message, $status);
    }
}
