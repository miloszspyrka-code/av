<?php
declare(strict_types=1);

namespace AlphaVault\Integrations;

/**
 * Minimalny klient HTTP dla integracji. Nie loguje nagłówków ani body.
 * Adresy bazowe pochodzą z konfiguracji serwera, nie od użytkownika (brak SSRF).
 */
final class HttpClient
{
    private const MAX_BODY = 4 * 1024 * 1024;

    /**
     * @param array<string,string> $headers
     * @return array{status:int,headers:array<string,string>,body:string,json:?array}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): array
    {
        $method = strtoupper($method);
        if (!function_exists('curl_init')) {
            return $this->streamRequest($method, $url, $headers, $body, $timeout);
        }

        $headerLines = ['User-Agent: AlphaVault/3.1'];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new IntegrationException('network_error', 'Nie można uruchomić klienta HTTP.', 502);
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXFILESIZE => self::MAX_BODY,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            throw new IntegrationException('network_error', 'Brak odpowiedzi dostawcy: ' . $this->shorten($error), 502);
        }
        if (strlen($responseBody) > self::MAX_BODY) {
            throw new IntegrationException('response_too_large', 'Odpowiedź dostawcy jest zbyt duża.', 502);
        }

        $decoded = json_decode($responseBody, true);
        return [
            'status' => $status,
            'headers' => [],
            'body' => $responseBody,
            'json' => is_array($decoded) ? $decoded : null,
        ];
    }

    /** @param array<string,string> $headers */
    private function streamRequest(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $headerLines = ['User-Agent: AlphaVault/3.1'];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => $body ?? '',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
        ]);
        $responseBody = @file_get_contents($url, false, $context);
        if ($responseBody === false) {
            throw new IntegrationException('network_error', 'Brak odpowiedzi dostawcy.', 502);
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $m)) {
                $status = (int) $m[1];
            }
        }
        $decoded = json_decode($responseBody, true);
        return [
            'status' => $status,
            'headers' => [],
            'body' => $responseBody,
            'json' => is_array($decoded) ? $decoded : null,
        ];
    }

    private function shorten(?string $value): string
    {
        $value = trim((string) $value);
        return $value === '' ? 'nieznany błąd' : substr($value, 0, 160);
    }
}
