<?php
declare(strict_types=1);

namespace AlphaVault\Integrations;

/**
 * WEEX. Klucz czytany jest przez API spot/futures. WEEX nie udostępnia
 * endpointu uprawnień klucza, więc akceptujemy każdy klucz, który przechodzi
 * odczyt konta — także z prawem handlu lub wypłat. Import wymaga tylko odczytu.
 */
final class WEEXProvider extends AbstractProvider
{
    private const PERMISSION_CODES = [-1051, -1052, -1053, -3236];

    public function key(): string
    {
        return 'weex';
    }

    public function label(): string
    {
        return 'WEEX';
    }

    public function connect(array $input, int $userId): array
    {
        $this->requireFields($input, ['apiKey', 'secretKey', 'passphrase']);
        $credentials = [
            'apiKey' => trim((string) $input['apiKey']),
            'secretKey' => trim((string) $input['secretKey']),
            'passphrase' => trim((string) $input['passphrase']),
        ];

        $account = $this->probeAccount($credentials);

        return [
            'credentials' => $credentials,
            'accountRef' => $account,
            'keyMask' => Normalizer::mask($credentials['apiKey']),
            'permissions' => 'any',
            'label' => 'WEEX',
        ];
    }

    public function fetchTrades(array $credentials, int $fromMs, int $toMs): array
    {
        $fills = [];
        $cursor = $fromMs;
        $guard = 0;
        while ($cursor < $toMs && $guard++ < 30) {
            $end = min($cursor + 7 * 86400 * 1000, $toMs);
            foreach ($this->fetchFuturesFills($credentials, $cursor, $end) as $fill) {
                $fills[] = $fill;
            }
            $cursor = $end;
        }
        $unique = [];
        foreach ($fills as $fill) {
            $key = $fill['id'] !== '' ? $fill['id'] : $fill['symbol'] . '|' . $fill['time'] . '|' . $fill['qty'] . '|' . $fill['price'];
            $unique[$key] = $fill;
        }
        return PositionReconstructor::build(array_values($unique), 'weex', $this->label());
    }

    public function disconnect(array $credentials): void
    {
        // WEEX nie wymaga usuwania klucza po stronie API.
    }

    /** @return array<int,array<string,mixed>> */
    private function fetchFuturesFills(array $credentials, int $fromMs, int $toMs): array
    {
        $res = $this->signedRequest('GET', '/capi/v3/userTrades', $credentials, [
            'startTime' => (string) $fromMs,
            'endTime' => (string) $toMs,
            'limit' => '100',
        ], null, 'futures');
        $rows = $this->rows($res);
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $qty = Normalizer::num($row['qty'] ?? null);
            $price = Normalizer::num($row['price'] ?? null);
            $time = Normalizer::toMs($row['time'] ?? null);
            if ($qty === null || $price === null || $time === null) {
                continue;
            }
            $commission = Normalizer::num($row['commission'] ?? null) ?? 0.0;
            $commissionAsset = strtoupper((string) ($row['commissionAsset'] ?? ''));
            if (!in_array($commissionAsset, ['USDT', 'USDC', 'USD'], true)) {
                $commission = 0.0;
            }
            $pnl = Normalizer::num($row['realizedPnl'] ?? null);
            $buyer = !empty($row['buyer']);
            $out[] = [
                'id' => (string) ($row['id'] ?? ''),
                'symbol' => (string) ($row['symbol'] ?? ''),
                'side' => $buyer ? 'buy' : 'sell',
                'positionSide' => isset($row['positionSide']) ? (string) $row['positionSide'] : null,
                'qty' => $qty,
                'price' => $price,
                'fee' => $commission,
                'pnl' => $pnl !== null && abs($pnl) > 0 ? $pnl : null,
                'time' => $time,
            ];
        }
        // Jeśli strona była pełna, zegar może gubić resztę. Dzielimy okno i dociągamy.
        if (count($rows) >= 100 && ($toMs - $fromMs) > 3600 * 1000) {
            $mid = $fromMs + (int) (($toMs - $fromMs) / 2);
            $out = array_merge(
                $out,
                $this->fetchFuturesFills($credentials, $fromMs, $mid),
                $this->fetchFuturesFills($credentials, $mid + 1, $toMs)
            );
        }
        return $out;
    }

    /** @return array<string,mixed>|array<int,mixed> */
    private function probeAccount(array $credentials): string
    {
        foreach (['spot', 'futures'] as $market) {
            try {
                $path = $market === 'spot' ? '/api/v3/account' : '/capi/v3/account/balance';
                $res = $this->signedRequest('GET', $path, $credentials, [], null, $market);
                if (!isset($res['code'])) {
                    if ($market === 'spot' && isset($res['uid'])) {
                        return (string) $res['uid'];
                    }
                    return $market;
                }
                $code = (int) ($res['code'] ?? 0);
                if (in_array($code, self::PERMISSION_CODES, true)) {
                    continue;
                }
            } catch (IntegrationException $err) {
                if ($err->errorCode === 'network_error' || $err->httpStatus >= 500) {
                    throw $err;
                }
            }
        }
        throw new IntegrationException('invalid_credentials', 'WEEX odrzucił klucz lub passphrase.', 422);
    }

    /**
     * @param array<string,mixed> $credentials
     * @param array<string,string> $query
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>|array<int,mixed>
     */
    private function signedRequest(
        string $method,
        string $path,
        array $credentials,
        array $query,
        ?array $body,
        string $market
    ): array {
        $queryString = $query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $requestPath = $path . $queryString;
        $timestamp = (string) (int) round(microtime(true) * 1000);
        $bodyJson = $body === null ? '' : (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $prehash = $timestamp . strtoupper($method) . $requestPath . $bodyJson;
        $sign = base64_encode(hash_hmac('sha256', $prehash, (string) $credentials['secretKey'], true));
        $headers = [
            'ACCESS-KEY' => (string) $credentials['apiKey'],
            'ACCESS-SIGN' => $sign,
            'ACCESS-TIMESTAMP' => $timestamp,
            'ACCESS-PASSPHRASE' => (string) $credentials['passphrase'],
            'Content-Type' => 'application/json',
        ];
        $base = $market === 'spot' ? $this->base('weex_spot', 'https://api-spot.weex.com') : $this->base('weex_futures', 'https://api-contract.weex.com');
        $res = $this->requestRaw($method, $base . $requestPath, $headers, $body === null ? null : $bodyJson);
        if ($res['json'] === null) {
            $this->providerError('WEEX zwrócił niepoprawną odpowiedź (HTTP ' . $res['status'] . ').');
        }
        return $res['json'];
    }

    /** @param array<string,mixed>|array<int,mixed> $res
     *  @return array<int,mixed>
     */
    private function rows(array $res): array
    {
        if (isset($res['code']) && (int) $res['code'] !== 0) {
            $msg = (string) ($res['msg'] ?? '');
            $this->providerError('WEEX odrzucił historię transakcji.' . ($msg !== '' ? ' ' . substr($msg, 0, 160) : ''));
        }
        if (array_is_list($res)) {
            return $res;
        }
        if (isset($res['data']) && is_array($res['data'])) {
            return $res['data'];
        }
        return [];
    }
}
