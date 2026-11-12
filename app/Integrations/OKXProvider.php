<?php
declare(strict_types=1);

namespace AlphaVault\Integrations;

/**
 * OKX v5. Klucz tylko-do-odczytu jest weryfikowany przez pole `perm`
 * z /api/v5/account/config (odrzucamy trade/withdraw).
 */
final class OKXProvider extends AbstractProvider
{
    public function key(): string
    {
        return 'okx';
    }

    public function label(): string
    {
        return 'OKX';
    }

    public function connect(array $input, int $userId): array
    {
        $this->requireFields($input, ['apiKey', 'secretKey', 'passphrase']);
        $credentials = [
            'apiKey' => trim((string) $input['apiKey']),
            'secretKey' => trim((string) $input['secretKey']),
            'passphrase' => trim((string) $input['passphrase']),
        ];

        $res = $this->signedGet('/api/v5/account/config', $credentials, []);
        $this->assertOk($res, 'Nie można zweryfikować klucza OKX.');
        $data = $res['data'][0] ?? [];
        $perm = strtolower((string) ($data['perm'] ?? ''));
        if (str_contains($perm, 'trade') || str_contains($perm, 'withdraw')) {
            throw new IntegrationException(
                'read_only_required',
                'Ten klucz OKX ma prawo do handlu lub wypłat. Utwórz klucz tylko z uprawnieniem Odczyt.',
                422
            );
        }

        $credentials['uid'] = (string) ($data['uid'] ?? '');
        return [
            'credentials' => $credentials,
            'accountRef' => $credentials['uid'] !== '' ? $credentials['uid'] : Normalizer::mask($credentials['apiKey']),
            'keyMask' => Normalizer::mask($credentials['apiKey']),
            'permissions' => 'read-only',
            'label' => 'OKX',
        ];
    }

    public function fetchTrades(array $credentials, int $fromMs, int $toMs): array
    {
        if ($toMs <= $fromMs) {
            return [];
        }
        $trades = [];
        $errors = [];

        try {
            foreach ($this->fetchPositions($credentials, $fromMs, $toMs) as $trade) {
                $trades[] = $trade;
            }
        } catch (IntegrationException $err) {
            $errors[] = $err;
        }

        try {
            foreach ($this->fetchSpotFills($credentials, $fromMs, $toMs) as $trade) {
                $trades[] = $trade;
            }
        } catch (IntegrationException $err) {
            $errors[] = $err;
        }

        if ($errors !== [] && count($errors) === 2) {
            throw $errors[0];
        }
        return $trades;
    }

    public function disconnect(array $credentials): void
    {
        // OKX nie wymaga usuwania klucza po stronie API.
    }

    /** @return array<int,array<string,mixed>> */
    private function fetchPositions(array $credentials, int $fromMs, int $toMs): array
    {
        $query = [
            'instType' => 'SWAP',
            'begin' => (string) $fromMs,
            'end' => (string) $toMs,
            'limit' => '100',
        ];
        $after = '';
        $out = [];
        for ($page = 0; $page < 10; $page++) {
            if ($after !== '') {
                $query['after'] = $after;
            }
            $res = $this->signedGet('/api/v5/account/positions-history', $credentials, $query);
            $this->assertOk($res, 'OKX odrzucił historię pozycji.');
            $rows = is_array($res['data'] ?? null) ? $res['data'] : [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $trade = $this->mapPosition($row);
                if ($trade !== null) {
                    $out[] = $trade;
                }
            }
            if (count($rows) < 100) {
                break;
            }
            $last = $rows[count($rows) - 1];
            $after = (string) ($last['posId'] ?? '');
            if ($after === '') {
                break;
            }
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function fetchSpotFills(array $credentials, int $fromMs, int $toMs): array
    {
        $query = [
            'instType' => 'SPOT',
            'begin' => (string) $fromMs,
            'end' => (string) $toMs,
            'limit' => '100',
        ];
        $after = '';
        $fills = [];
        for ($page = 0; $page < 10; $page++) {
            if ($after !== '') {
                $query['after'] = $after;
            }
            $res = $this->signedGet('/api/v5/trade/fills-history', $credentials, $query);
            $this->assertOk($res, 'OKX odrzucił historię transakcji spot.');
            $rows = is_array($res['data'] ?? null) ? $res['data'] : [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $qty = Normalizer::num($row['fillSz'] ?? null);
                $price = Normalizer::num($row['fillPx'] ?? null);
                $time = Normalizer::toMs($row['ts'] ?? null);
                if ($qty === null || $price === null || $time === null) {
                    continue;
                }
                $fee = Normalizer::num($row['fee'] ?? null) ?? 0.0;
                $feeCcy = strtoupper((string) ($row['feeCcy'] ?? ''));
                if (!in_array($feeCcy, ['USDT', 'USDC', 'USD'], true)) {
                    $fee = 0.0;
                }
                $fills[] = [
                    'id' => (string) ($row['billId'] ?? $row['tradeId'] ?? ''),
                    'symbol' => (string) ($row['instId'] ?? ''),
                    'side' => strtolower((string) ($row['side'] ?? 'buy')),
                    'positionSide' => null,
                    'qty' => $qty,
                    'price' => $price,
                    'fee' => $fee,
                    'pnl' => null,
                    'time' => $time,
                ];
            }
            if (count($rows) < 100) {
                break;
            }
            $last = $rows[count($rows) - 1];
            $after = (string) ($last['billId'] ?? '');
            if ($after === '') {
                break;
            }
        }
        return PositionReconstructor::build(array_values($this->uniqueFills($fills)), 'okx', $this->label());
    }

    /** @param array<int,array<string,mixed>> $fills
     *  @return array<string,array<string,mixed>>
     */
    private function uniqueFills(array $fills): array
    {
        $unique = [];
        foreach ($fills as $fill) {
            $key = (string) ($fill['id'] ?? '');
            if ($key === '') {
                $key = (string) ($fill['symbol'] ?? '') . '|' . (string) ($fill['time'] ?? '') . '|' . (string) ($fill['qty'] ?? '');
            }
            $unique[$key] = $fill;
        }
        return $unique;
    }

    /** @param array<string,mixed> $row */
    private function mapPosition(array $row): ?array
    {
        $closeAt = Normalizer::toMs($row['closeTime'] ?? null);
        $openAt = Normalizer::toMs($row['openTime'] ?? null);
        $pair = Normalizer::pair((string) ($row['instId'] ?? ''));
        if ($closeAt === null || $pair === '') {
            return null;
        }
        $realized = Normalizer::num($row['realizedPnl'] ?? null) ?? 0.0;
        $fee = Normalizer::num($row['fee'] ?? null) ?? 0.0;
        $funding = Normalizer::num($row['fundingFee'] ?? null) ?? 0.0;
        $penalty = Normalizer::num($row['liqPenalty'] ?? null) ?? 0.0;
        $qty = Normalizer::num($row['closeTotalPos'] ?? $row['openMaxPos'] ?? null) ?? 0.0;
        if ($qty <= 0) {
            return null;
        }
        $posSide = (string) ($row['posSide'] ?? 'long');
        $openAvg = Normalizer::num($row['openAvgPx'] ?? null);
        $closeAvg = Normalizer::num($row['closeAvgPx'] ?? null);
        if (strtolower($posSide) === 'net' && $openAvg !== null && $closeAvg !== null) {
            $side = (($closeAvg >= $openAvg) === ($realized >= 0)) ? 'buy' : 'sell';
        } else {
            $side = strtolower($posSide) === 'short' ? 'sell' : 'buy';
        }

        return [
            'extId' => 'okx:pos:' . (string) ($row['posId'] ?? $row['uTime'] ?? $closeAt),
            'date' => Normalizer::iso($closeAt),
            'pair' => $pair,
            'dir' => Normalizer::direction($side, $posSide),
            'entry' => Normalizer::price(Normalizer::num($row['openAvgPx'] ?? null)),
            'exit' => Normalizer::price(Normalizer::num($row['closeAvgPx'] ?? null)),
            'size' => Normalizer::size($qty),
            'result' => round($realized + $fee + $funding + $penalty, 8),
            'fee' => round(abs($fee + $funding + $penalty), 8),
            'durationMin' => $openAt !== null ? max(0, (int) round(($closeAt - $openAt) / 60000)) : 0,
            'source' => $this->label(),
            'account' => $this->label(),
        ];
    }

    /**
     * @param array<string,mixed> $credentials
     * @param array<string,string> $query
     * @return array<string,mixed>
     */
    private function signedGet(string $path, array $credentials, array $query): array
    {
        $queryString = $query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $requestPath = $path . $queryString;
        $timestamp = gmdate('Y-m-d\TH:i:s.000\Z');
        $prehash = $timestamp . 'GET' . $requestPath;
        $sign = base64_encode(hash_hmac('sha256', $prehash, (string) $credentials['secretKey'], true));
        $headers = [
            'OK-ACCESS-KEY' => (string) $credentials['apiKey'],
            'OK-ACCESS-SIGN' => $sign,
            'OK-ACCESS-TIMESTAMP' => $timestamp,
            'OK-ACCESS-PASSPHRASE' => (string) $credentials['passphrase'],
            'Content-Type' => 'application/json',
        ];
        $res = $this->requestRaw('GET', $this->base('okx', 'https://www.okx.com') . $requestPath, $headers);
        if ($res['json'] === null) {
            $this->providerError('OKX zwrócił niepoprawną odpowiedź (HTTP ' . $res['status'] . ').');
        }
        return $res['json'];
    }

    /** @param array<string,mixed> $res */
    private function assertOk(array $res, string $message): void
    {
        $code = (string) ($res['code'] ?? '');
        if ($code !== '0') {
            $msg = (string) ($res['msg'] ?? '');
            $this->providerError($message . ($msg !== '' ? ' ' . substr($msg, 0, 160) : ''));
        }
    }
}
