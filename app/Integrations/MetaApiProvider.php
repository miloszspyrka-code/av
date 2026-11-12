<?php
declare(strict_types=1);

namespace AlphaVault\Integrations;

/**
 * MetaTrader 4/5 przez MetaApi (cloud REST). Hasło inwestora jest przekazywane
 * wyłącznie do MetaApi; Alpha Vault go nie przechowuje. Połączenie jest akceptowane
 * tylko wtedy, gdy MetaApi potwierdzi `investorMode: true`.
 */
final class MetaApiProvider extends AbstractProvider
{
    public function __construct(
        HttpClient $http,
        array $bases,
        private readonly string $token,
    ) {
        parent::__construct($http, $bases);
    }

    public function key(): string
    {
        return 'metaapi';
    }

    public function label(): string
    {
        return 'MetaTrader';
    }

    public function connect(array $input, int $userId): array
    {
        if ($this->token === '') {
            throw new IntegrationException(
                'meta_not_configured',
                'Integracja MetaTrader nie jest skonfigurowana na serwerze (METAAPI_TOKEN).',
                503
            );
        }
        $this->requireFields($input, ['platform', 'server', 'login', 'password']);
        $platform = strtolower((string) $input['platform']);
        if (!in_array($platform, ['mt4', 'mt5'], true)) {
            throw new IntegrationException('bad_platform', 'Wybierz MT4 albo MT5.', 422);
        }
        $login = trim((string) $input['login']);
        if (!preg_match('/^\d{4,20}$/', $login)) {
            throw new IntegrationException('bad_login', 'Numer konta MetaTrader zawiera tylko cyfry.', 422);
        }
        $password = (string) $input['password'];
        if (strlen($password) < 4) {
            throw new IntegrationException('bad_password', 'Hasło inwestora jest za krótkie.', 422);
        }

        $payload = [
            'login' => $login,
            'password' => $password,
            'name' => 'Alpha Vault ' . $userId,
            'server' => trim((string) $input['server']),
            'platform' => $platform,
            'magic' => 0,
            'type' => 'cloud-g2',
            'reliability' => 'regular',
            'manualTrades' => false,
            'keywords' => [(string) $input['server']],
        ];
        $transactionId = bin2hex(random_bytes(16));
        $created = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $res = $this->requestRaw(
                'POST',
                $this->base('metaapi', 'https://mt-provisioning-api-v1.agiliumtrade.agiliumtrade.ai') . '/users/current/accounts',
                [
                    'auth-token' => $this->token,
                    'transaction-id' => $transactionId,
                    'Content-Type' => 'application/json',
                ],
                (string) json_encode($payload, JSON_UNESCAPED_SLASHES),
                40
            );
            if ($res['status'] === 202) {
                sleep(2);
                continue;
            }
            if ($res['status'] === 201 && is_array($res['json'])) {
                $created = $res['json'];
                break;
            }
            $this->throwMetaError($res, 'Nie można dodać konta MetaTrader.');
        }
        if ($created === null || empty($created['id'])) {
            throw new IntegrationException(
                'meta_provisioning',
                'MetaApi nadal konfiguruje konto. Spróbuj ponownie za minutę.',
                409
            );
        }

        $accountId = (string) $created['id'];
        $region = (string) ($created['region'] ?? 'us-east-1');
        $account = $this->waitForAccount($accountId, $region);
        if ($account === null) {
            $this->deleteAccount($accountId);
            throw new IntegrationException(
                'meta_not_ready',
                'Konto MetaTrader nie połączyło się z brokerem. Sprawdź serwer, login i hasło inwestora.',
                422
            );
        }
        if (($account['investorMode'] ?? null) !== true) {
            $this->deleteAccount($accountId);
            throw new IntegrationException(
                'investor_password_required',
                'To nie jest hasło inwestora. MetaTrader przyjął hasło z prawem handlu — Alpha Vault go nie zapisze. Użyj hasła inwestora.',
                422
            );
        }

        return [
            'credentials' => [
                'platform' => $platform,
                'accountId' => $accountId,
                'region' => $region,
                'server' => (string) $payload['server'],
                'login' => $login,
            ],
            'accountRef' => $accountId,
            'keyMask' => 'login ••' . substr($login, -3),
            'permissions' => 'read-only',
            'label' => $platform === 'mt4' ? 'MetaTrader 4' : 'MetaTrader 5',
            'status' => 'connected',
        ];
    }

    public function fetchTrades(array $credentials, int $fromMs, int $toMs): array
    {
        $accountId = (string) ($credentials['accountId'] ?? '');
        $region = (string) ($credentials['region'] ?? 'us-east-1');
        if ($accountId === '') {
            throw new IntegrationException('invalid_credentials', 'Brak identyfikatora konta MetaTrader.', 422);
        }
        $base = str_replace('{region}', $region, $this->base(
            'metaapi_client',
            'https://mt-client-api-v1.{region}.agiliumtrade.agiliumtrade.ai'
        ));
        $out = [];
        $cursor = $fromMs;
        $guard = 0;
        $seenDeals = [];
        while ($cursor < $toMs && $guard++ < 8) {
            $end = min($cursor + 30 * 86400 * 1000, $toMs);
            $from = Normalizer::iso($cursor);
            $to = Normalizer::iso($end);
            if ($from === null || $to === null) {
                break;
            }
            $url = $base . '/users/current/accounts/' . rawurlencode($accountId)
                . '/history-deals/time/' . rawurlencode($from) . '/' . rawurlencode($to);
            $res = $this->requestRaw('GET', $url, ['auth-token' => $this->token], null, 40);
            if ($res['status'] === 202) {
                sleep(2);
                $res = $this->requestRaw('GET', $url, ['auth-token' => $this->token], null, 40);
            }
            if ($res['status'] >= 400) {
                $this->throwMetaError($res, 'MetaApi odrzucił historię transakcji.');
            }
            $deals = is_array($res['json']) && array_is_list($res['json'])
                ? $res['json']
                : (is_array($res['json']['deals'] ?? null) ? $res['json']['deals'] : []);
            foreach ($deals as $deal) {
                if (!is_array($deal)) {
                    continue;
                }
                $id = (string) ($deal['id'] ?? '');
                if ($id === '') {
                    $id = (string) ($deal['positionId'] ?? '') . '|' . (string) ($deal['time'] ?? '') . '|' . (string) ($deal['volume'] ?? '');
                }
                $seenDeals[$id] = $deal;
            }
            $cursor = $end;
        }
        return $this->aggregateDeals(array_values($seenDeals));
    }

    public function disconnect(array $credentials): void
    {
        $accountId = (string) ($credentials['accountId'] ?? '');
        if ($accountId === '') {
            return;
        }
        $this->deleteAccount($accountId);
    }

    /** @param array<int,array<string,mixed>> $deals
     *  @return array<int,array<string,mixed>>
     */
    private function aggregateDeals(array $deals): array
    {
        /** @var array<string,array<string,mixed>> $positions */
        $positions = [];
        foreach ($deals as $deal) {
            if (!is_array($deal)) {
                continue;
            }
            $positionId = (string) ($deal['positionId'] ?? '');
            if ($positionId === '') {
                continue;
            }
            $time = Normalizer::toMs($deal['time'] ?? null);
            $volume = Normalizer::num($deal['volume'] ?? null) ?? 0.0;
            $price = Normalizer::num($deal['price'] ?? null) ?? 0.0;
            if ($time === null || $volume <= 0 || $price <= 0) {
                continue;
            }
            $type = strtoupper((string) ($deal['type'] ?? ''));
            $entryType = strtoupper((string) ($deal['entryType'] ?? ''));
            if (!isset($positions[$positionId])) {
                $positions[$positionId] = [
                    'positionId' => $positionId,
                    'symbol' => (string) ($deal['symbol'] ?? ''),
                    'dir' => $type === 'DEAL_TYPE_SELL' ? 's' : 'l',
                    'entryQty' => 0.0,
                    'entryNotional' => 0.0,
                    'exitQty' => 0.0,
                    'exitNotional' => 0.0,
                    'result' => 0.0,
                    'fees' => 0.0,
                    'openTime' => $time,
                    'closeTime' => $time,
                ];
            }
            $pos = &$positions[$positionId];
            $isExit = str_contains($entryType, 'OUT') || ($pos['entryQty'] > 0 && str_contains($entryType, 'INOUT'));
            if ($isExit) {
                $pos['exitQty'] += $volume;
                $pos['exitNotional'] += $volume * $price;
                $pos['closeTime'] = $time;
            } else {
                $pos['entryQty'] += $volume;
                $pos['entryNotional'] += $volume * $price;
                $pos['openTime'] = min((int) $pos['openTime'], $time);
            }
            $pos['result'] += (Normalizer::num($deal['profit'] ?? null) ?? 0.0)
                + (Normalizer::num($deal['commission'] ?? null) ?? 0.0)
                + (Normalizer::num($deal['swap'] ?? null) ?? 0.0);
            $pos['fees'] += abs((Normalizer::num($deal['commission'] ?? null) ?? 0.0)
                + (Normalizer::num($deal['swap'] ?? null) ?? 0.0));
            unset($pos);
        }

        $out = [];
        foreach ($positions as $pos) {
            if ($pos['exitQty'] <= 0 || $pos['entryQty'] <= 0) {
                continue;
            }
            $pair = Normalizer::pair((string) $pos['symbol']);
            if ($pair === '') {
                continue;
            }
            $out[] = [
                'extId' => 'mt:' . $pos['positionId'],
                'date' => Normalizer::iso((int) $pos['closeTime']),
                'pair' => $pair,
                'dir' => $pos['dir'],
                'entry' => Normalizer::price((float) $pos['entryNotional'] / $pos['entryQty']),
                'exit' => Normalizer::price((float) $pos['exitNotional'] / $pos['exitQty']),
                'size' => Normalizer::size((float) $pos['exitQty']),
                'result' => round((float) $pos['result'], 8),
                'fee' => round((float) $pos['fees'], 8),
                'durationMin' => max(0, (int) round(((int) $pos['closeTime'] - (int) $pos['openTime']) / 60000)),
                'source' => $this->label(),
                'account' => $this->label(),
            ];
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    private function waitForAccount(string $accountId, string $region): ?array
    {
        $provisioning = $this->base('metaapi', 'https://mt-provisioning-api-v1.agiliumtrade.agiliumtrade.ai');
        $client = str_replace('{region}', $region, $this->base(
            'metaapi_client',
            'https://mt-client-api-v1.{region}.agiliumtrade.agiliumtrade.ai'
        ));
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $res = $this->requestRaw(
                'GET',
                $provisioning . '/users/current/accounts/' . rawurlencode($accountId),
                ['auth-token' => $this->token],
                null,
                20
            );
            if ($res['status'] >= 400) {
                return null;
            }
            $account = is_array($res['json']) ? $res['json'] : [];
            $state = strtoupper((string) ($account['state'] ?? ''));
            $connection = strtoupper((string) ($account['connectionStatus'] ?? ''));
            if ($state === 'DEPLOYED' || $connection === 'CONNECTED') {
                $info = $this->requestRaw(
                    'GET',
                    $client . '/users/current/accounts/' . rawurlencode($accountId) . '/account-information?refreshTerminalState=true',
                    ['auth-token' => $this->token],
                    null,
                    30
                );
                if ($info['status'] < 400 && is_array($info['json'])) {
                    return $info['json'];
                }
            }
            sleep(2);
        }
        return null;
    }

    private function deleteAccount(string $accountId): void
    {
        try {
            $this->requestRaw(
                'DELETE',
                $this->base('metaapi', 'https://mt-provisioning-api-v1.agiliumtrade.agiliumtrade.ai')
                . '/users/current/accounts/' . rawurlencode($accountId) . '?executeForAllReplicas=true',
                ['auth-token' => $this->token],
                null,
                20
            );
        } catch (\Throwable) {
            // Lokalny wpis i tak zostanie usunięty; nie blokujemy UI.
        }
    }

    /** @param array{status:int,headers:array<string,string>,body:string,json:?array} $res */
    private function throwMetaError(array $res, string $message): never
    {
        $json = $res['json'];
        $detail = '';
        if (is_array($json)) {
            $detail = (string) ($json['message'] ?? $json['error'] ?? '');
        }
        $code = '';
        if (is_array($json) && is_array($json['details'] ?? null)) {
            $code = (string) ($json['details']['code'] ?? '');
        } elseif (is_array($json) && is_string($json['details'] ?? null)) {
            $code = (string) $json['details'];
        }
        $suffix = trim($code . ' ' . $detail);
        throw new IntegrationException(
            'meta_error',
            $message . ($suffix !== '' ? ' ' . substr($suffix, 0, 220) : ''),
            $res['status'] >= 400 ? 422 : 502
        );
    }
}
