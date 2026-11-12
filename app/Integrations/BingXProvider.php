<?php
declare(strict_types=1);

namespace AlphaVault\Integrations;

/**
 * BingX. Uprawnienia klucza są weryfikowane przez /openApi/v1/account/apiPermissions.
 * Historia: income (symbole) + positionHistory (zamknięte pozycje swap).
 */
final class BingXProvider extends AbstractProvider
{
    public function key(): string
    {
        return 'bingx';
    }

    public function label(): string
    {
        return 'BingX';
    }

    public function connect(array $input, int $userId): array
    {
        $this->requireFields($input, ['apiKey', 'secretKey']);
        $credentials = [
            'apiKey' => trim((string) $input['apiKey']),
            'secretKey' => trim((string) $input['secretKey']),
        ];
        $res = $this->signedGet('/openApi/v1/account/apiPermissions', $credentials, []);
        $this->assertOk($res, 'Nie można zweryfikować klucza BingX.');
        $data = $res['data'] ?? [];
        if (isset($data[0]) && is_array($data[0])) {
            $data = $data[0];
        }
        if (!is_array($data)) {
            $this->providerError('BingX nie zwrócił uprawnień klucza.');
        }
        $blocked = [
            'enableSpotAndMarginTrading' => 'spot',
            'enableFutures' => 'futures',
            'enableWithdrawals' => 'wypłaty',
            'enableInternalTransfer' => 'przelewy wewnętrzne',
            'permitsUniversalTransfer' => 'przelewy uniwersalne',
            'enableVanillaOptions' => 'opcje',
        ];
        foreach ($blocked as $field => $name) {
            if (!empty($data[$field])) {
                throw new IntegrationException(
                    'read_only_required',
                    'Ten klucz BingX ma uprawnienie: ' . $name . '. Utwórz klucz tylko z uprawnieniem Odczyt.',
                    422
                );
            }
        }
        if (array_key_exists('enableReading', $data) && empty($data['enableReading'])) {
            throw new IntegrationException('read_only_required', 'Klucz BingX nie ma uprawnienia Odczyt.', 422);
        }

        return [
            'credentials' => $credentials,
            'accountRef' => Normalizer::mask($credentials['apiKey']),
            'keyMask' => Normalizer::mask($credentials['apiKey']),
            'permissions' => 'read-only',
            'label' => 'BingX',
        ];
    }

    public function fetchTrades(array $credentials, int $fromMs, int $toMs): array
    {
        $symbols = $this->symbolsWithRealizedPnl($credentials, $fromMs, $toMs);
        $out = [];
        foreach (array_slice($symbols, 0, 25) as $symbol) {
            foreach ($this->positionHistory($credentials, $symbol, $fromMs, $toMs) as $trade) {
                $out[] = $trade;
            }
        }
        return $out;
    }

    public function disconnect(array $credentials): void
    {
        // BingX nie wymaga usuwania klucza po stronie API.
    }

    /** @return array<int,string> */
    private function symbolsWithRealizedPnl(array $credentials, int $fromMs, int $toMs): array
    {
        $res = $this->signedGet('/openApi/swap/v2/user/income', $credentials, [
            'incomeType' => 'REALIZED_PNL',
            'startTime' => (string) $fromMs,
            'endTime' => (string) $toMs,
            'limit' => '1000',
        ]);
        $this->assertOk($res, 'BingX odrzucił historię rozliczeń.');
        $rows = $res['data'] ?? [];
        if (!is_array($rows)) {
            return [];
        }
        $symbols = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
            if ($symbol !== '') {
                $symbols[$symbol] = $symbol;
            }
        }
        return array_values($symbols);
    }

    /** @return array<int,array<string,mixed>> */
    private function positionHistory(array $credentials, string $symbol, int $fromMs, int $toMs): array
    {
        $res = $this->signedGet('/openApi/swap/v1/trade/positionHistory', $credentials, [
            'symbol' => $symbol,
            'startTs' => (string) $fromMs,
            'endTs' => (string) $toMs,
            'pageSize' => '100',
        ]);
        $this->assertOk($res, 'BingX odrzucił historię pozycji dla ' . $symbol . '.');
        $rows = $res['data']['positionHistory'] ?? [];
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $closeAt = Normalizer::toMs($row['updateTime'] ?? null);
            $openAt = Normalizer::toMs($row['openTime'] ?? null);
            $pair = Normalizer::pair((string) ($row['symbol'] ?? $symbol));
            $qty = Normalizer::num($row['closePositionAmt'] ?? $row['positionAmt'] ?? null) ?? 0.0;
            if ($closeAt === null || $pair === '' || $qty <= 0) {
                continue;
            }
            $realised = Normalizer::num($row['realisedProfit'] ?? null) ?? 0.0;
            $netProfit = Normalizer::num($row['netProfit'] ?? null);
            $commission = Normalizer::num($row['positionCommission'] ?? null) ?? 0.0;
            $funding = Normalizer::num($row['totalFunding'] ?? null) ?? 0.0;
            $result = $netProfit ?? ($realised + $commission + $funding);
            $posSide = (string) ($row['positionSide'] ?? 'LONG');
            $side = strtoupper($posSide) === 'SHORT' ? 'sell' : 'buy';

            $out[] = [
                'extId' => 'bingx:pos:' . (string) ($row['positionId'] ?? $closeAt),
                'date' => Normalizer::iso($closeAt),
                'pair' => $pair,
                'dir' => Normalizer::direction($side, $posSide),
                'entry' => Normalizer::price(Normalizer::num($row['avgPrice'] ?? null)),
                'exit' => Normalizer::price(Normalizer::num($row['avgClosePrice'] ?? null)),
                'size' => Normalizer::size($qty),
                'result' => round($result, 8),
                'fee' => round(abs($commission + $funding), 8),
                'durationMin' => $openAt !== null ? max(0, (int) round(($closeAt - $openAt) / 60000)) : 0,
                'source' => $this->label(),
                'account' => $this->label(),
            ];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $credentials
     * @param array<string,string> $query
     * @return array<string,mixed>
     */
    private function signedGet(string $path, array $credentials, array $query): array
    {
        $params = $query;
        $params['timestamp'] = (string) (int) round(microtime(true) * 1000);
        ksort($params);
        $queryString = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $signature = hash_hmac('sha256', $queryString, (string) $credentials['secretKey']);
        $url = $this->base('bingx', 'https://open-api.bingx.com') . $path . '?' . $queryString . '&signature=' . $signature;
        $res = $this->requestRaw('GET', $url, [
            'X-BX-APIKEY' => (string) $credentials['apiKey'],
            'X-SOURCE-KEY' => 'AlphaVault',
        ]);
        if ($res['json'] === null) {
            $this->providerError('BingX zwrócił niepoprawną odpowiedź (HTTP ' . $res['status'] . ').');
        }
        return $res['json'];
    }

    /** @param array<string,mixed> $res */
    private function assertOk(array $res, string $message): void
    {
        $code = (string) ($res['code'] ?? '');
        if ($code !== '0' && $code !== '') {
            $msg = (string) ($res['msg'] ?? '');
            $this->providerError($message . ($msg !== '' ? ' ' . substr($msg, 0, 160) : ''));
        }
    }
}
