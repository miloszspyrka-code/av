<?php
declare(strict_types=1);

namespace AlphaVault;

/**
 * Integracje urządzeń zdrowotnych.
 *
 * Oura: OAuth2 + pobieranie danych po stronie serwera.
 * Apple Watch: most Alpha Vault na iPhone — jednorazowy kod parowania,
 * a następnie ingest danych z tokenem urządzenia (bez OAuth).
 *
 * Garmin i Whoop są wyłączone na razie: implementacja i konfiguracja
 * pozostają w kodzie, ale nie są wystawiane w API ani UI.
 *
 * Tokeny dostawców są szyfrowane (AES-256-GCM) i nigdy nie trafiają do API.
 * Dane o zdrowiu są usuwane po odłączeniu urządzenia.
 */
final class Wearables
{
    public const PROVIDERS = ['apple', 'oura'];

    private const OAUTH_PROVIDERS = ['oura'];

    private const PKCE_PROVIDERS = ['oura'];

    private const MAX_DAYS = 90;

    private const TOKEN_REFRESH_MARGIN = 120;

    private const METRIC_RANGE = [
        'sleepMinutes' => [0, 1440],
        'hrvMs' => [0, 400],
        'restingHr' => [20, 220],
        'bodyBattery' => [0, 100],
        'providerScore' => [0, 100],
    ];

    public function __construct(
        private readonly Config $config,
        private readonly Db $db,
        private readonly Auth $auth,
    ) {
    }

    public function providerConfigured(string $provider): bool
    {
        if ($provider === 'apple') {
            return true;
        }
        if (!$this->encryptionConfigured()) {
            return false;
        }
        $cfg = $this->providerConfig($provider);
        if ($cfg === null) {
            return false;
        }
        return (string) ($cfg['clientId'] ?? '') !== ''
            && (string) ($cfg['clientSecret'] ?? '') !== ''
            && (string) ($cfg['authorizeUrl'] ?? '') !== ''
            && (string) ($cfg['tokenUrl'] ?? '') !== ''
            && (string) ($cfg['apiBase'] ?? '') !== '';
    }

    /**
     * Garmin wymaga zatwierdzonego planu Business. Personal API nie jest
     * przeznaczone dla aplikacji obsługujących wielu użytkowników.
     */
    public function garminAccessReady(): bool
    {
        $plan = (string) ($this->config->wearables['garmin']['accessPlan'] ?? '');
        return in_array($plan, ['starter', 'enterprise'], true);
    }

    /**
     * Adapter dostarczania danych Garmina nie jest jeszcze zaimplementowany.
     * Szczegółowe schematy push są dostępne dopiero w Garmin Developer Portal.
     */
    public function garminDataDeliveryReady(): bool
    {
        $cfg = $this->config->wearables['garmin'] ?? [];
        return (string) ($cfg['dataDelivery'] ?? 'none') === 'push'
            && (bool) ($cfg['pushReady'] ?? false);
    }

    public function providerCanConnect(string $provider): bool
    {
        if (!$this->providerConfigured($provider)) {
            return false;
        }
        if ($provider === 'garmin' && !$this->garminAccessReady()) {
            return false;
        }
        return true;
    }

    private function deviceStatus(string $provider, ?array $row, bool $configured, bool $pendingAuth): string
    {
        if ($provider === 'garmin') {
            if (!$configured) {
                return 'not_configured';
            }
            if (!$this->garminAccessReady()) {
                return 'awaiting_provider_access';
            }
        } elseif (!$configured) {
            return 'not_configured';
        }

        if ($row === null || (string) $row['status'] !== 'connected') {
            if ($row !== null && (string) $row['status'] === 'revoked') {
                return 'revoked';
            }
            return $pendingAuth ? 'pending_authorization' : 'ready_to_connect';
        }

        $sync = (string) ($row['last_sync_status'] ?? '');
        if ($sync === 'syncing') {
            return 'syncing';
        }
        if ($sync === 'token_expired') {
            return 'token_expired';
        }
        if ($sync === 'error') {
            return 'sync_error';
        }
        if ($provider === 'garmin' && !$this->garminDataDeliveryReady()) {
            return 'awaiting_provider_spec';
        }
        return 'connected';
    }

    public function providerLabel(string $provider): string
    {
        return match ($provider) {
            'garmin' => 'Garmin Connect',
            'apple' => 'Apple Watch · Zdrowie',
            'oura' => 'Oura Ring',
            'whoop' => 'Whoop',
            default => $provider,
        };
    }

    public function listForUser(int $userId): array
    {
        $rows = [];
        try {
            $rows = $this->db->query(
                'SELECT * FROM wearable_connections WHERE user_id = ? ORDER BY provider',
                [$userId]
            );
        } catch (\Throwable $err) {
            // Brak migracji nie może wywalić całego panelu.
            error_log('[wearables] list: ' . $err->getMessage());
        }
        $byProvider = [];
        foreach ($rows as $row) {
            $byProvider[(string) $row['provider']] = $row;
        }
        $pending = [];
        try {
            $states = $this->db->query(
                'SELECT DISTINCT provider FROM wearable_oauth_states WHERE user_id = ? AND expires_at > UTC_TIMESTAMP()',
                [$userId]
            );
            foreach ($states as $state) {
                $pending[(string) $state['provider']] = true;
            }
        } catch (\Throwable $err) {
            error_log('[wearables] pending states: ' . $err->getMessage());
        }

        $devices = [];
        foreach (self::PROVIDERS as $provider) {
            $row = $byProvider[$provider] ?? null;
            $connected = $row !== null && (string) $row['status'] === 'connected';
            $configured = $provider === 'apple' ? true : $this->providerConfigured($provider);
            $status = $this->deviceStatus(
                $provider,
                $row,
                $configured,
                isset($pending[$provider])
            );
            $devices[$provider] = [
                'provider' => $provider,
                'label' => $this->providerLabel($provider),
                'configured' => $configured,
                'canConnect' => $provider === 'apple' ? true : $this->providerCanConnect($provider),
                'connected' => $connected,
                'oauthConnected' => $connected,
                'dataReady' => $provider === 'garmin' ? $this->garminDataDeliveryReady() : $connected,
                'status' => $status,
                'deviceName' => $row['device_name'] ?? null,
                'lastSyncAt' => $this->utcIso($row['last_sync_at'] ?? null),
                'lastSyncStatus' => $row['last_sync_status'] ?? null,
                'lastError' => $connected ? ($row['last_error'] ?? null) : null,
                'consentAt' => $this->utcIso($row['consent_at'] ?? null),
            ];
        }

        $series = $this->series($userId, self::MAX_DAYS);
        $todayKey = $this->todayKey();
        $todayRaw = null;
        foreach ($series as $day) {
            if ($day['date'] === $todayKey) {
                $todayRaw = $day;
                break;
            }
        }
        $baseline = $this->baseline($series, $todayKey);
        $checkin = $this->todayCheckin($userId, $todayKey);
        $readiness = $this->readiness($todayRaw, $baseline, $checkin);

        $today = $todayRaw !== null ? $this->publicDay($todayRaw) : null;
        if ($today !== null) {
            $today['readiness'] = $readiness['score'];
            $today['readinessTitle'] = $readiness['title'];
            $today['readinessClass'] = $readiness['class'];
            $today['components'] = $readiness['components'];
        }

        return [
            'devices' => $devices,
            'today' => $today,
            'baseline' => $baseline,
            'series' => array_map(fn (array $day): array => $this->publicDay($day), $series),
            'readiness' => [
                'score' => $readiness['score'],
                'title' => $readiness['title'],
                'class' => $readiness['class'],
                'source' => $readiness['source'],
                'components' => $readiness['components'],
                'checkinScore' => $checkin['score'] ?? null,
            ],
            'updatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    public function startUrl(int $userId, string $provider): string
    {
        $this->assertOauthProvider($provider);
        if (!$this->providerCanConnect($provider)) {
            throw new HttpException(503, 'provider_not_configured', 'Ta integracja nie jest jeszcze skonfigurowana na serwerze.');
        }
        $cfg = $this->providerConfig($provider);
        $state = $this->auth->randomToken(24);
        $verifier = in_array($provider, self::PKCE_PROVIDERS, true) ? $this->codeVerifier() : null;
        $this->db->execute('DELETE FROM wearable_oauth_states WHERE expires_at < UTC_TIMESTAMP()');
        $this->db->execute(
            'INSERT INTO wearable_oauth_states (state, user_id, provider, code_verifier, expires_at) VALUES (?, ?, ?, ?, ?)',
            [$state, $userId, $provider, $verifier, gmdate('Y-m-d H:i:s', time() + 600)]
        );
        $params = [
            'response_type' => 'code',
            'client_id' => (string) $cfg['clientId'],
            'redirect_uri' => (string) $cfg['redirectUri'],
            'state' => $state,
        ];
        if ((string) ($cfg['scopes'] ?? '') !== '') {
            $params['scope'] = (string) $cfg['scopes'];
        }
        if ($verifier !== null) {
            $params['code_challenge'] = $this->codeChallenge($verifier);
            $params['code_challenge_method'] = 'S256';
        }
        $joiner = str_contains((string) $cfg['authorizeUrl'], '?') ? '&' : '?';
        return (string) $cfg['authorizeUrl'] . $joiner . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public function handleCallback(int $userId, string $provider, string $code, string $state): array
    {
        $this->assertOauthProvider($provider);
        if ($state === '' || $code === '') {
            throw new HttpException(400, 'bad_callback', 'Dostawca nie przekazał kodu autoryzacji.');
        }
        $row = $this->db->queryOne(
            'SELECT * FROM wearable_oauth_states WHERE state = ? AND user_id = ? AND provider = ? AND expires_at > UTC_TIMESTAMP() LIMIT 1',
            [$state, $userId, $provider]
        );
        if ($row === null) {
            throw new HttpException(400, 'bad_state', 'Sesja łączenia wygasła albo została przerwana. Spróbuj ponownie.');
        }
        $this->db->execute('DELETE FROM wearable_oauth_states WHERE state = ?', [$state]);

        $token = $this->exchangeToken($provider, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => (string) $this->providerConfig($provider)['redirectUri'],
        ], (string) ($row['code_verifier'] ?? ''));
        $this->storeConnection($userId, $provider, $token);

        if ($provider === 'garmin') {
            // OAuth jest zakończony, ale dostarczanie danych wymaga adaptera
            // push i specyfikacji udostępnianej w Garmin Developer Portal.
            try {
                $this->hydrateGarmin($userId, (string) $token['accessToken']);
            } catch (\Throwable $err) {
                error_log('[wearables] garmin profile: ' . $this->safeError($err));
            }
            $this->touchSync($userId, $provider, 'provider_spec');
            $this->auth->audit($userId, 'wearables.connect', ['provider' => $provider, 'dataDelivery' => 'pending']);
            return ['provider' => $provider, 'syncError' => null, 'dataDelivery' => 'pending'];
        }

        $syncError = null;
        try {
            $this->sync($userId, $provider);
        } catch (\Throwable $err) {
            $syncError = $this->safeError($err);
            $this->markError($userId, $provider, $syncError);
        }
        $this->auth->audit($userId, 'wearables.connect', ['provider' => $provider, 'syncError' => $syncError]);
        return ['provider' => $provider, 'syncError' => $syncError];
    }

    public function sync(int $userId, string $provider): array
    {
        $this->assertProvider($provider);
        $row = $this->db->queryOne(
            'SELECT * FROM wearable_connections WHERE user_id = ? AND provider = ? AND status = \'connected\' LIMIT 1',
            [$userId, $provider]
        );
        if ($row === null) {
            throw new HttpException(404, 'not_connected', 'To urządzenie nie jest połączone.');
        }
        if ($provider === 'apple') {
            $this->touchSync($userId, $provider, 'ok');
            return ['provider' => $provider, 'days' => 0];
        }
        if ($provider === 'garmin' && !$this->garminDataDeliveryReady()) {
            $this->touchSync($userId, $provider, 'provider_spec');
            return ['provider' => $provider, 'days' => 0, 'pending' => 'provider_spec'];
        }
        try {
            $this->touchSync($userId, $provider, 'syncing');
            $token = $this->validToken($userId, $provider, $row);
            $days = $this->fetchAndStore($userId, $provider, $token);
            $this->touchSync($userId, $provider, 'ok');
            $this->auth->audit($userId, 'wearables.sync', ['provider' => $provider, 'days' => $days]);
            return ['provider' => $provider, 'days' => $days];
        } catch (HttpException $err) {
            $status = in_array($err->status, [401, 403], true) ? 'token_expired' : 'error';
            $this->markError($userId, $provider, $err->getMessage(), $status);
            $this->auth->audit($userId, 'wearables.sync_failed', ['provider' => $provider, 'error' => $err->errorCode]);
            throw $err;
        } catch (\Throwable $err) {
            $message = $this->safeError($err);
            $this->markError($userId, $provider, $message, 'error');
            $this->auth->audit($userId, 'wearables.sync_failed', ['provider' => $provider, 'error' => $message]);
            throw new HttpException(502, 'sync_failed', 'Nie udało się pobrać danych z urządzenia.', ['detail' => $message]);
        }
    }

    public function disconnect(int $userId, string $provider): void
    {
        $this->assertProvider($provider);
        $row = $this->db->queryOne(
            'SELECT * FROM wearable_connections WHERE user_id = ? AND provider = ? LIMIT 1',
            [$userId, $provider]
        );
        if ($row === null) {
            throw new HttpException(404, 'not_connected', 'To urządzenie nie jest połączone.');
        }
        $revoked = false;
        if ($provider !== 'apple' && (string) $row['status'] === 'connected') {
            $revoked = $this->revokeProviderAccess($provider, $row);
        }
        $this->db->withTransaction(function (Db $db) use ($userId, $provider): void {
            $db->execute('DELETE FROM wearable_daily_metrics WHERE user_id = ? AND provider = ?', [$userId, $provider]);
            $db->execute('DELETE FROM wearable_connections WHERE user_id = ? AND provider = ?', [$userId, $provider]);
            $db->execute('DELETE FROM wearable_pair_codes WHERE user_id = ?', [$userId]);
            $db->execute('DELETE FROM wearable_oauth_states WHERE user_id = ? AND provider = ?', [$userId, $provider]);
        });
        $this->auth->audit($userId, 'wearables.disconnect', [
            'provider' => $provider,
            'hadConnection' => true,
            'providerRevoked' => $revoked,
        ]);
    }

    public function applePairCode(int $userId): array
    {
        $this->db->execute('DELETE FROM wearable_pair_codes WHERE expires_at < UTC_TIMESTAMP()');
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $raw = '';
        for ($i = 0; $i < 8; $i++) {
            $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $code = 'AV-' . substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
        $expires = gmdate('Y-m-d H:i:s', time() + 900);
        $this->db->execute(
            'INSERT INTO wearable_pair_codes (code_hash, user_id, expires_at) VALUES (?, ?, ?)',
            [$this->auth->hashToken($code), $userId, $expires]
        );
        $this->auth->audit($userId, 'wearables.apple_pair', []);
        return ['code' => $code, 'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', strtotime($expires . ' UTC'))];
    }

    public function appleIngest(array $payload, ?string $bearerToken): array
    {
        $userId = null;
        $deviceToken = null;
        $isPairing = false;
        if ($bearerToken !== null && $bearerToken !== '') {
            $conn = $this->db->queryOne(
                'SELECT * FROM wearable_connections WHERE provider = \'apple\' AND device_token_hash = ? AND status = \'connected\' LIMIT 1',
                [$this->auth->hashToken($bearerToken)]
            );
            if ($conn === null) {
                throw new HttpException(401, 'bad_device_token', 'Token urządzenia jest nieprawidłowy.');
            }
            $userId = (int) $conn['user_id'];
        } else {
            $code = strtoupper(trim((string) ($payload['code'] ?? '')));
            if ($code === '') {
                throw new HttpException(400, 'missing_code', 'Podaj kod parowania albo token urządzenia.');
            }
            $row = $this->db->queryOne(
                'SELECT * FROM wearable_pair_codes WHERE code_hash = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP() LIMIT 1',
                [$this->auth->hashToken($code)]
            );
            if ($row === null) {
                throw new HttpException(401, 'bad_pair_code', 'Kod parowania jest nieprawidłowy albo wygasł.');
            }
            $userId = (int) $row['user_id'];
            $isPairing = true;
            $deviceToken = 'avw_' . $this->auth->randomToken(32);
            $deviceName = substr(trim((string) ($payload['deviceName'] ?? 'iPhone')), 0, 100) ?: 'iPhone';
            $this->db->execute(
                'INSERT INTO wearable_connections (user_id, provider, status, device_token_hash, device_name, last_sync_status, consent_at)
                 VALUES (?, \'apple\', \'connected\', ?, ?, \'ok\', UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE device_token_hash = VALUES(device_token_hash), device_name = VALUES(device_name),
                   status = \'connected\', revoked_at = NULL, last_error = NULL, consent_at = UTC_TIMESTAMP()',
                [$userId, $this->auth->hashToken($deviceToken), $deviceName]
            );
            $this->db->execute('UPDATE wearable_pair_codes SET used_at = UTC_TIMESTAMP() WHERE code_hash = ?', [$this->auth->hashToken($code)]);
            $this->auth->audit($userId, 'wearables.apple_connect', ['device' => $deviceName]);
        }

        $days = $payload['days'] ?? null;
        if (!is_array($days) || $days === []) {
            throw new HttpException(400, 'missing_days', 'Brak danych do zapisania.');
        }
        $days = array_slice($days, 0, self::MAX_DAYS);
        $stored = 0;
        foreach ($days as $day) {
            if (!is_array($day)) {
                continue;
            }
            $normalized = $this->normalizeDay($day, 'apple');
            if ($normalized === null) {
                continue;
            }
            $this->storeMetric($userId, 'apple', $normalized, $day);
            $stored++;
        }
        if ($stored === 0) {
            throw new HttpException(400, 'bad_days', 'Żaden dzień nie zawiera poprawnych danych.');
        }
        $this->touchSync($userId, 'apple', 'ok');
        $result = ['ok' => true, 'days' => $stored];
        if ($deviceToken !== null) {
            $result['deviceToken'] = $deviceToken;
            $result['deviceName'] = $payload['deviceName'] ?? 'iPhone';
        }
        $this->auth->audit($userId, 'wearables.apple_ingest', ['days' => $stored, 'paired' => $isPairing]);
        return $result;
    }

    public function syncAll(): array
    {
        $providers = array_values(array_diff(self::PROVIDERS, ['apple']));
        $placeholders = implode(', ', array_fill(0, count($providers), '?'));
        $rows = $this->db->query(
            'SELECT user_id, provider FROM wearable_connections WHERE status = \'connected\' AND provider IN (' . $placeholders . ') ORDER BY user_id, provider',
            $providers
        );
        $ok = 0;
        $failed = 0;
        foreach ($rows as $row) {
            try {
                $this->sync((int) $row['user_id'], (string) $row['provider']);
                $ok++;
            } catch (\Throwable $err) {
                $failed++;
                error_log('[wearables] syncAll ' . $row['provider'] . ': ' . $this->safeError($err));
            }
        }
        return ['ok' => $ok, 'failed' => $failed, 'total' => count($rows)];
    }

    /* ------------------------------------------------------------------ */
    /* Dane i gotowość                                                     */
    /* ------------------------------------------------------------------ */

    private function series(int $userId, int $days): array
    {
        $from = gmdate('Y-m-d', strtotime($this->todayKey() . ' -' . ($days - 1) . ' days'));
        try {
            $rows = $this->db->query(
                'SELECT provider, metric_date, sleep_minutes, hrv_ms, resting_hr, body_battery, provider_score, synced_at
                   FROM wearable_daily_metrics
                  WHERE user_id = ? AND metric_date >= ?
                  ORDER BY metric_date ASC, synced_at DESC',
                [$userId, $from]
            );
        } catch (\Throwable $err) {
            error_log('[wearables] series: ' . $err->getMessage());
            return [];
        }
        $byDate = [];
        foreach ($rows as $row) {
            $date = (string) $row['metric_date'];
            if (!isset($byDate[$date])) {
                $byDate[$date] = [
                    'date' => $date,
                    'sleepMinutes' => null,
                    'hrvMs' => null,
                    'restingHr' => null,
                    'bodyBattery' => null,
                    'providerScore' => null,
                    'providers' => [],
                ];
            }
            $day = &$byDate[$date];
            foreach (['sleepMinutes' => 'sleep_minutes', 'hrvMs' => 'hrv_ms', 'restingHr' => 'resting_hr', 'bodyBattery' => 'body_battery', 'providerScore' => 'provider_score'] as $key => $column) {
                if ($day[$key] === null && $row[$column] !== null && $row[$column] !== '') {
                    $day[$key] = $key === 'hrvMs' ? (float) $row[$column] : (int) $row[$column];
                }
            }
            if (!in_array((string) $row['provider'], $day['providers'], true)) {
                $day['providers'][] = (string) $row['provider'];
            }
            unset($day);
        }
        return array_values($byDate);
    }

    private function baseline(array $series, string $todayKey): array
    {
        $fields = ['sleepMinutes', 'hrvMs', 'restingHr'];
        $sums = array_fill_keys($fields, 0.0);
        $counts = array_fill_keys($fields, 0);
        foreach ($series as $day) {
            if ($day['date'] >= $todayKey) {
                continue;
            }
            foreach ($fields as $field) {
                if ($day[$field] !== null) {
                    $sums[$field] += (float) $day[$field];
                    $counts[$field]++;
                }
            }
        }
        $out = ['days' => 0];
        foreach ($fields as $field) {
            $out[$field] = $counts[$field] > 0 ? round($sums[$field] / $counts[$field], $field === 'hrvMs' ? 1 : 0) : null;
            $out['days'] = max($out['days'], $counts[$field]);
        }
        return $out;
    }

    private function readiness(?array $today, array $baseline, ?array $checkin): array
    {
        $components = [];
        if ($today !== null) {
            if ($today['sleepMinutes'] !== null) {
                $components['sleep'] = self::clamp((int) round($today['sleepMinutes'] / 480 * 100), 0, 100);
            }
            if ($today['hrvMs'] !== null && $baseline['hrvMs'] !== null && (float) $baseline['hrvMs'] > 0) {
                $components['hrv'] = self::clamp((int) round($today['hrvMs'] / (float) $baseline['hrvMs'] * 100), 0, 100);
            }
            if ($today['restingHr'] !== null && $baseline['restingHr'] !== null) {
                $components['restingHr'] = self::clamp((int) round(100 - ((int) $today['restingHr'] - (int) $baseline['restingHr']) * 5), 0, 100);
            }
            if ($today['bodyBattery'] !== null) {
                $components['bodyBattery'] = self::clamp((int) $today['bodyBattery'], 0, 100);
            }
            if ($today['providerScore'] !== null) {
                $components['recovery'] = self::clamp((int) $today['providerScore'], 0, 100);
            }
        }
        $weights = ['sleep' => 0.40, 'hrv' => 0.25, 'restingHr' => 0.10, 'recovery' => 0.15, 'bodyBattery' => 0.10];
        $weighted = 0.0;
        $weightSum = 0.0;
        foreach ($components as $key => $value) {
            $w = $weights[$key] ?? 0.0;
            $weighted += $value * $w;
            $weightSum += $w;
        }
        $physiology = $weightSum > 0 ? (int) round($weighted / $weightSum) : null;
        $checkScore = $checkin['score'] ?? null;

        if ($physiology !== null && $checkScore !== null) {
            $score = (int) round($physiology * 0.7 + $checkScore * 0.3);
            $source = 'devices+checkin';
        } elseif ($physiology !== null) {
            $score = $physiology;
            $source = 'devices';
        } elseif ($checkScore !== null) {
            $score = $checkScore;
            $source = 'checkin';
        } else {
            $score = null;
            $source = 'none';
        }

        [$title, $class] = $this->readinessLabel($score);
        return [
            'score' => $score,
            'title' => $title,
            'class' => $class,
            'source' => $source,
            'components' => $components + ['physiology' => $physiology, 'checkin' => $checkScore],
        ];
    }

    private function readinessLabel(?int $score): array
    {
        if ($score === null) {
            return ['', ''];
        }
        if ($score >= 80) {
            return ['Świetna forma', 'win'];
        }
        if ($score >= 65) {
            return ['Dobra forma', 'win'];
        }
        if ($score >= 50) {
            return ['Ostrożnie dziś', 'warnc'];
        }
        return ['Lepiej odpuścić', 'loss'];
    }

    private function todayCheckin(int $userId, string $todayKey): ?array
    {
        try {
            $row = $this->db->queryOne('SELECT state FROM user_states WHERE user_id = ?', [$userId]);
        } catch (\Throwable) {
            return null;
        }
        if ($row === null) {
            return null;
        }
        $state = json_decode((string) $row['state'], true);
        if (!is_array($state) || !is_array($state['checkins'] ?? null)) {
            return null;
        }
        $today = null;
        foreach ($state['checkins'] as $checkin) {
            if (!is_array($checkin) || (string) ($checkin['date'] ?? '') !== $todayKey) {
                continue;
            }
            if ($today === null || (int) ($checkin['score'] ?? 0) > (int) ($today['score'] ?? 0)) {
                $today = $checkin;
            }
        }
        if ($today === null) {
            return null;
        }
        return ['score' => self::clamp((int) ($today['score'] ?? 55), 0, 100)];
    }

    private function publicDay(array $day): array
    {
        return [
            'date' => $day['date'],
            'sleepMinutes' => $day['sleepMinutes'],
            'hrvMs' => $day['hrvMs'],
            'restingHr' => $day['restingHr'],
            'bodyBattery' => $day['bodyBattery'],
            'providerScore' => $day['providerScore'],
            'providers' => $day['providers'],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* OAuth i pobieranie danych                                           */
    /* ------------------------------------------------------------------ */

    private function codeVerifier(): string
    {
        // 48 losowych bajtów daje 64 znaki Base64URL: mieszczą się w zakresie
        // 43–128 znaków wymaganym przez Oura i Garmin.
        return $this->auth->randomToken(48);
    }

    private function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function exchangeToken(string $provider, array $form, string $codeVerifier = ''): array
    {
        if (!$this->providerConfigured($provider)) {
            throw new HttpException(503, 'provider_not_configured', 'Ta integracja nie jest jeszcze skonfigurowana na serwerze.');
        }
        $cfg = $this->providerConfig($provider);
        $isRefresh = (string) ($form['grant_type'] ?? '') === 'refresh_token';
        $form['client_id'] = (string) $cfg['clientId'];
        $form['client_secret'] = (string) $cfg['clientSecret'];
        if ($codeVerifier !== '') {
            $form['code_verifier'] = $codeVerifier;
        }
        $res = $this->http('POST', (string) $cfg['tokenUrl'], [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ], http_build_query($form, '', '&', PHP_QUERY_RFC3986));
        if ($res['status'] < 200 || $res['status'] >= 300) {
            $error = strtolower((string) ($res['json']['error'] ?? ''));
            if ($res['status'] === 429) {
                throw new HttpException(503, 'provider_rate_limited', 'Dostawca ograniczył liczbę żądań. Spróbuj później.');
            }
            if ($isRefresh && in_array($error, ['invalid_grant', 'invalid_token', 'expired_token'], true)) {
                throw new HttpException(401, 'reauth_required', 'Token wygasł. Połącz urządzenie ponownie.');
            }
            if (in_array($res['status'], [401, 403], true)) {
                throw new HttpException(401, 'reauth_required', 'Dostawca odrzucił token. Połącz urządzenie ponownie.');
            }
            throw new HttpException(502, 'token_exchange_failed', 'Dostawca odrzucił autoryzację.', ['status' => $res['status']]);
        }
        if (!is_array($res['json'])) {
            throw new HttpException(502, 'token_exchange_failed', 'Dostawca nie zwrócił poprawnej odpowiedzi tokenu.');
        }
        $access = (string) ($res['json']['access_token'] ?? '');
        if ($access === '') {
            throw new HttpException(502, 'token_exchange_failed', 'Dostawca nie zwrócił tokenu dostępu.');
        }
        return [
            'accessToken' => $access,
            'refreshToken' => (string) ($res['json']['refresh_token'] ?? ''),
            'expiresIn' => (int) ($res['json']['expires_in'] ?? 0),
            'refreshExpiresIn' => (int) ($res['json']['refresh_token_expires_in'] ?? 0),
            'scopes' => (string) ($res['json']['scope'] ?? ''),
            'externalUserId' => (string) ($res['json']['user_id'] ?? ''),
        ];
    }

    private function storeConnection(int $userId, string $provider, array $token): void
    {
        $expiresAt = (int) $token['expiresIn'] > 0
            ? gmdate('Y-m-d H:i:s', time() + (int) $token['expiresIn'])
            : null;
        $refreshExpiresAt = (int) ($token['refreshExpiresIn'] ?? 0) > 0
            ? gmdate('Y-m-d H:i:s', time() + (int) $token['refreshExpiresIn'])
            : null;
        $this->db->execute(
            'INSERT INTO wearable_connections
                (user_id, provider, status, external_user_id, scopes, access_token_enc, refresh_token_enc, token_expires_at, refresh_token_expires_at, last_sync_status, consent_at)
             VALUES (?, ?, \'connected\', ?, ?, ?, ?, ?, ?, \'pending\', UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
               status = \'connected\',
               external_user_id = COALESCE(VALUES(external_user_id), external_user_id),
               scopes = COALESCE(VALUES(scopes), scopes),
               access_token_enc = VALUES(access_token_enc),
               refresh_token_enc = COALESCE(VALUES(refresh_token_enc), refresh_token_enc),
               token_expires_at = VALUES(token_expires_at),
               refresh_token_expires_at = COALESCE(VALUES(refresh_token_expires_at), refresh_token_expires_at),
               revoked_at = NULL, last_error = NULL, last_sync_status = \'pending\', consent_at = UTC_TIMESTAMP()',
            [
                $userId,
                $provider,
                $token['externalUserId'] !== '' ? $token['externalUserId'] : null,
                $token['scopes'] !== '' ? $token['scopes'] : null,
                $this->encrypt((string) $token['accessToken']),
                $token['refreshToken'] !== '' ? $this->encrypt((string) $token['refreshToken']) : null,
                $expiresAt,
                $refreshExpiresAt,
            ]
        );
    }

    private function storeRefreshedTokens(int $userId, string $provider, array $token): void
    {
        $expiresAt = (int) $token['expiresIn'] > 0
            ? gmdate('Y-m-d H:i:s', time() + (int) $token['expiresIn'])
            : null;
        $refreshExpiresAt = (int) ($token['refreshExpiresIn'] ?? 0) > 0
            ? gmdate('Y-m-d H:i:s', time() + (int) $token['refreshExpiresIn'])
            : null;
        $accessEnc = $this->encrypt((string) $token['accessToken']);
        $refreshEnc = $token['refreshToken'] !== '' ? $this->encrypt((string) $token['refreshToken']) : null;
        $scopes = $token['scopes'] !== '' ? (string) $token['scopes'] : null;

        $this->db->withTransaction(function (Db $db) use (
            $userId,
            $provider,
            $accessEnc,
            $refreshEnc,
            $expiresAt,
            $refreshExpiresAt,
            $scopes
        ): void {
            $current = $db->queryOne(
                'SELECT refresh_token_enc FROM wearable_connections WHERE user_id = ? AND provider = ? AND status = \'connected\' FOR UPDATE',
                [$userId, $provider]
            );
            if ($current === null) {
                throw new HttpException(401, 'reauth_required', 'Połączenie wygasło. Połącz urządzenie ponownie.');
            }
            $refreshToStore = $refreshEnc ?? (string) ($current['refresh_token_enc'] ?? '');
            if ($refreshToStore === '') {
                throw new HttpException(401, 'reauth_required', 'Brak tokenu odświeżania. Połącz urządzenie ponownie.');
            }
            $db->execute(
                'UPDATE wearable_connections
                    SET access_token_enc = ?, refresh_token_enc = ?, token_expires_at = ?, refresh_token_expires_at = ?,
                        scopes = COALESCE(?, scopes), last_error = NULL
                  WHERE user_id = ? AND provider = ? AND status = \'connected\'',
                [$accessEnc, $refreshToStore, $expiresAt, $refreshExpiresAt, $scopes, $userId, $provider]
            );
        });
    }

    private function tokenUsable(array $row): bool
    {
        if ((string) ($row['access_token_enc'] ?? '') === '') {
            return false;
        }
        $expires = $row['token_expires_at'] !== null
            ? strtotime((string) $row['token_expires_at'] . ' UTC')
            : null;
        return $expires === null || $expires > time() + self::TOKEN_REFRESH_MARGIN;
    }

    private function validToken(int $userId, string $provider, array $row): string
    {
        if ($this->tokenUsable($row)) {
            $token = $this->decrypt((string) ($row['access_token_enc'] ?? ''));
            if ($token !== '') {
                return $token;
            }
        }

        $lock = $this->acquireRefreshLock($userId, $provider);
        try {
            // Inny proces mógł odświeżyć token, zanim zdobyliśmy blokadę.
            $fresh = $this->db->queryOne(
                'SELECT * FROM wearable_connections WHERE user_id = ? AND provider = ? AND status = \'connected\' LIMIT 1',
                [$userId, $provider]
            );
            if ($fresh === null) {
                throw new HttpException(401, 'reauth_required', 'Połączenie wygasło. Połącz urządzenie ponownie.');
            }
            if ($this->tokenUsable($fresh)) {
                $token = $this->decrypt((string) ($fresh['access_token_enc'] ?? ''));
                if ($token !== '') {
                    return $token;
                }
            }

            $refreshExpires = $fresh['refresh_token_expires_at'] !== null
                ? strtotime((string) $fresh['refresh_token_expires_at'] . ' UTC')
                : null;
            if ($refreshExpires !== null && $refreshExpires <= time()) {
                $this->markError($userId, $provider, 'Refresh token wygasł.', 'token_expired');
                throw new HttpException(401, 'reauth_required', 'Token odświeżania wygasł. Połącz urządzenie ponownie.');
            }
            $refresh = $this->decrypt((string) ($fresh['refresh_token_enc'] ?? ''));
            if ($refresh === '') {
                $this->markError($userId, $provider, 'Brak refresh tokenu.', 'token_expired');
                throw new HttpException(401, 'reauth_required', 'Brak tokenu odświeżania. Połącz urządzenie ponownie.');
            }
            $token = $this->exchangeToken($provider, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refresh,
            ]);
            $this->storeRefreshedTokens($userId, $provider, $token);
            return (string) $token['accessToken'];
        } finally {
            $this->releaseRefreshLock($lock);
        }
    }

    private function acquireRefreshLock(int $userId, string $provider): string
    {
        $name = 'av:wear:' . $userId . ':' . $provider;
        $row = $this->db->queryOne('SELECT GET_LOCK(?, 10) AS ok', [$name]);
        if ((int) ($row['ok'] ?? 0) !== 1) {
            throw new HttpException(503, 'refresh_busy', 'Trwa odświeżanie tokenu. Spróbuj ponownie za chwilę.');
        }
        return $name;
    }

    private function releaseRefreshLock(string $name): void
    {
        try {
            $this->db->queryOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
        } catch (\Throwable $err) {
            error_log('[wearables] release lock: ' . $err->getMessage());
        }
    }

    private function fetchAndStore(int $userId, string $provider, string $accessToken): int
    {
        $days = match ($provider) {
            'oura' => $this->fetchOura($userId, $accessToken),
            'whoop' => $this->fetchWhoop($userId, $accessToken),
            default => [],
        };
        $stored = 0;
        foreach ($days as $day) {
            $normalized = $this->normalizeDay($day, $provider);
            if ($normalized === null) {
                continue;
            }
            $this->storeMetric($userId, $provider, $normalized, $day);
            $stored++;
        }
        return $stored;
    }

    private function fetchOura(int $userId, string $accessToken): array
    {
        $end = $this->todayKey();
        $start = gmdate('Y-m-d', strtotime($end . ' -29 days'));
        $query = '?start_date=' . rawurlencode($start) . '&end_date=' . rawurlencode($end);
        $readiness = $this->pagedOura('/v2/usercollection/daily_readiness', $query, $accessToken);
        $sleeps = $this->pagedOura('/v2/usercollection/sleep', $query, $accessToken);
        $days = [];
        foreach ($readiness as $row) {
            $date = $this->dateOnly($row['day'] ?? '');
            if ($date === null) {
                continue;
            }
            $days[$date]['date'] = $date;
            $days[$date]['providerScore'] = isset($row['score']) ? (int) $row['score'] : null;
        }
        foreach ($sleeps as $row) {
            $date = $this->dateOnly($row['day'] ?? ($row['bedtime_start'] ?? ''));
            if ($date === null) {
                continue;
            }
            $seconds = (int) ($row['total_sleep_duration'] ?? 0);
            $candidate = [
                'date' => $date,
                'sleepMinutes' => $seconds > 0 ? (int) round($seconds / 60) : null,
                'hrvMs' => isset($row['average_hrv']) ? (float) $row['average_hrv'] : null,
                'restingHr' => isset($row['lowest_heart_rate']) ? (int) $row['lowest_heart_rate'] : null,
            ];
            if (!isset($days[$date]) || ($candidate['sleepMinutes'] ?? 0) > ($days[$date]['sleepMinutes'] ?? 0)) {
                $days[$date] = array_merge($days[$date] ?? ['date' => $date], $candidate);
            }
        }
        return array_values($days);
    }

    private function fetchWhoop(int $userId, string $accessToken): array
    {
        $end = gmdate('Y-m-d\TH:i:s\Z');
        $start = gmdate('Y-m-d\TH:i:s\Z', strtotime('-29 days'));
        $query = '?start=' . rawurlencode($start) . '&end=' . rawurlencode($end) . '&limit=25';
        $days = [];
        foreach ($this->pagedApi('whoop', '/developer/v2/recovery' . $query, $accessToken) as $row) {
            $date = $this->dateOnly($row['created_at'] ?? '');
            if ($date === null) {
                continue;
            }
            $score = is_array($row['score'] ?? null) ? $row['score'] : [];
            $days[$date]['date'] = $date;
            $days[$date]['providerScore'] = isset($score['recovery_score']) ? (int) round((float) $score['recovery_score']) : null;
            $days[$date]['hrvMs'] = isset($score['hrv_rmssd_milli']) ? (float) $score['hrv_rmssd_milli'] : null;
            $days[$date]['restingHr'] = isset($score['resting_heart_rate']) ? (int) round((float) $score['resting_heart_rate']) : null;
        }
        foreach ($this->pagedApi('whoop', '/developer/v2/activity/sleep' . $query, $accessToken) as $row) {
            $date = $this->dateOnly($row['end'] ?? ($row['start'] ?? ''));
            if ($date === null) {
                continue;
            }
            $score = is_array($row['score'] ?? null) ? $row['score'] : [];
            $stages = is_array($score['stage_summary'] ?? null) ? $score['stage_summary'] : [];
            $inBed = (int) ($stages['total_in_bed_time_milli'] ?? 0);
            $awake = (int) ($stages['total_awake_time_milli'] ?? 0);
            $minutes = $inBed > 0 ? (int) round(max(0, $inBed - $awake) / 60000) : null;
            if (!isset($days[$date])) {
                $days[$date] = ['date' => $date];
            }
            if ($minutes !== null && ($days[$date]['sleepMinutes'] ?? 0) < $minutes) {
                $days[$date]['sleepMinutes'] = $minutes;
            }
        }
        return array_values($days);
    }

    private function hydrateGarmin(int $userId, string $accessToken): void
    {
        $identity = $this->apiGet('garmin', '/wellness-api/rest/user/id', $accessToken);
        $externalId = trim((string) ($identity['userId'] ?? ''));
        $permissions = $this->apiGet('garmin', '/wellness-api/rest/user/permissions', $accessToken);
        $granted = [];
        foreach ($permissions as $permission) {
            if (is_string($permission) && $permission !== '') {
                $granted[] = $permission;
            }
        }
        $this->db->execute(
            'UPDATE wearable_connections
                SET external_user_id = COALESCE(?, external_user_id), scopes = ?
              WHERE user_id = ? AND provider = \'garmin\'',
            [$externalId !== '' ? $externalId : null, $granted !== [] ? implode(' ', $granted) : null, $userId]
        );
        $this->auth->audit($userId, 'wearables.garmin_permissions', ['permissions' => $granted]);
    }

    private function revokeProviderAccess(string $provider, array $row): bool
    {
        $access = $this->decrypt((string) ($row['access_token_enc'] ?? ''));
        if ($access === '') {
            return false;
        }
        $cfg = $this->providerConfig($provider);
        try {
            if ($provider === 'oura') {
                $url = (string) ($cfg['revokeUrl'] ?? '');
                if ($url === '') {
                    return false;
                }
                $separator = str_contains($url, '?') ? '&' : '?';
                $res = $this->http('GET', $url . $separator . 'access_token=' . rawurlencode($access), [
                    'Accept: application/json',
                ]);
            } elseif ($provider === 'whoop') {
                $res = $this->http('DELETE', (string) ($cfg['revokeUrl'] ?? ''), [
                    'Accept: application/json',
                    'Authorization: Bearer ' . $access,
                ]);
            } elseif ($provider === 'garmin') {
                $res = $this->http('DELETE', (string) ($cfg['revokeUrl'] ?? ''), [
                    'Accept: application/json',
                    'Authorization: Bearer ' . $access,
                ]);
            } else {
                return false;
            }
            return $res['status'] >= 200 && $res['status'] < 300;
        } catch (\Throwable $err) {
            error_log('[wearables] revoke ' . $provider . ': ' . $this->safeError($err));
            return false;
        }
    }

    private function apiGet(string $provider, string $path, string $accessToken): array
    {
        $cfg = $this->providerConfig($provider);
        $res = $this->http('GET', (string) $cfg['apiBase'] . $path, [
            'Accept: application/json',
            'Authorization: Bearer ' . $accessToken,
        ]);
        if ($res['status'] === 204) {
            return [];
        }
        if (in_array($res['status'], [401, 403], true)) {
            throw new HttpException(401, 'provider_unauthorized', 'Dostawca odrzucił token. Połącz urządzenie ponownie.');
        }
        if ($res['status'] === 429) {
            throw new HttpException(503, 'provider_rate_limited', 'Dostawca ograniczył liczbę żądań. Spróbuj później.');
        }
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new HttpException(502, 'provider_error', 'Dostawca zwrócił HTTP ' . $res['status'] . '.', ['status' => $res['status']]);
        }
        if (!is_array($res['json'])) {
            throw new HttpException(502, 'provider_bad_response', 'Dostawca zwrócił niepoprawną odpowiedź.');
        }
        return $res['json'];
    }

    private function pagedOura(string $path, string $query, string $accessToken): array
    {
        $rows = [];
        $url = $path . $query;
        for ($page = 0; $page < 8; $page++) {
            $json = $this->apiGet('oura', $url, $accessToken);
            foreach (($json['data'] ?? []) as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
            $next = $json['next_token'] ?? null;
            if (!is_string($next) || $next === '') {
                break;
            }
            $url = $path . $query . '&next_token=' . rawurlencode($next);
        }
        return $rows;
    }

    private function pagedApi(string $provider, string $path, string $accessToken): array
    {
        $rows = [];
        $url = $path;
        for ($page = 0; $page < 8; $page++) {
            $json = $this->apiGet($provider, $url, $accessToken);
            foreach (($json['records'] ?? []) as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
            $next = $json['next_token'] ?? null;
            if (!is_string($next) || $next === '') {
                break;
            }
            $url = $path . '&nextToken=' . rawurlencode($next);
        }
        return $rows;
    }

    private function normalizeDay(array $day, string $provider): ?array
    {
        $date = $this->dateOnly($day['date'] ?? '');
        if ($date === null) {
            return null;
        }
        $timestamp = strtotime($date . ' 12:00:00 UTC');
        $today = strtotime($this->todayKey() . ' 12:00:00 UTC');
        if ($timestamp === false || $today === false || $timestamp < $today - (400 * 86400) || $timestamp > $today + 86400) {
            return null;
        }
        $out = ['date' => $date];
        $has = false;
        foreach (self::METRIC_RANGE as $key => [$min, $max]) {
            $value = $day[$key] ?? null;
            if ($value === null || $value === '' || !is_numeric($value)) {
                $out[$key] = null;
                continue;
            }
            $number = $key === 'hrvMs' ? (float) $value : (int) round((float) $value);
            if ($number < $min || $number > $max) {
                $out[$key] = null;
                continue;
            }
            $out[$key] = $number;
            $has = true;
        }
        return $has ? $out : null;
    }

    private function storeMetric(int $userId, string $provider, array $normalized, array $raw): void
    {
        $safeRaw = json_encode($this->redactRaw($raw), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($safeRaw === false || strlen($safeRaw) > 60000) {
            $safeRaw = null;
        }
        $this->db->execute(
            'INSERT INTO wearable_daily_metrics
                (user_id, provider, metric_date, sleep_minutes, hrv_ms, resting_hr, body_battery, provider_score, raw_payload, synced_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE sleep_minutes = VALUES(sleep_minutes), hrv_ms = VALUES(hrv_ms),
               resting_hr = VALUES(resting_hr), body_battery = VALUES(body_battery), provider_score = VALUES(provider_score),
               raw_payload = VALUES(raw_payload), synced_at = UTC_TIMESTAMP()',
            [
                $userId,
                $provider,
                $normalized['date'],
                $normalized['sleepMinutes'],
                $normalized['hrvMs'],
                $normalized['restingHr'],
                $normalized['bodyBattery'],
                $normalized['providerScore'],
                $safeRaw,
            ]
        );
    }

    private function touchSync(int $userId, string $provider, string $status): void
    {
        $this->db->execute(
            'UPDATE wearable_connections SET last_sync_at = UTC_TIMESTAMP(), last_sync_status = ?, last_error = NULL WHERE user_id = ? AND provider = ?',
            [$status, $userId, $provider]
        );
    }

    private function markError(int $userId, string $provider, string $message, string $status = 'error'): void
    {
        $this->db->execute(
            'UPDATE wearable_connections SET last_sync_at = UTC_TIMESTAMP(), last_sync_status = ?, last_error = ? WHERE user_id = ? AND provider = ?',
            [$status, substr($message, 0, 300), $userId, $provider]
        );
    }

    /* ------------------------------------------------------------------ */
    /* Szyfrowanie, HTTP, pomocnicze                                       */
    /* ------------------------------------------------------------------ */

    private function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $key = $this->encryptionKey();
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false) {
            throw new \RuntimeException('Nie można zaszyfrować tokenu.');
        }
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    private function decrypt(string $payload): string
    {
        if ($payload === '' || !str_starts_with($payload, 'v1:')) {
            return '';
        }
        $plain = $this->decryptWithKey($payload, $this->encryptionKey());
        if ($plain !== '') {
            return $plain;
        }
        $previous = $this->encryptionKey(true);
        return $previous !== '' ? $this->decryptWithKey($payload, $previous) : '';
    }

    private function decryptWithKey(string $payload, string $key): string
    {
        $binary = base64_decode(substr($payload, 3), true);
        if ($binary === false || strlen($binary) < 29) {
            return '';
        }
        $iv = substr($binary, 0, 12);
        $tag = substr($binary, 12, 16);
        $cipher = substr($binary, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    }

    private function encryptionConfigured(): bool
    {
        return (string) ($this->config->wearables['encryptionKey'] ?? '') !== '';
    }

    private function encryptionKey(bool $previous = false): string
    {
        $secret = (string) ($this->config->wearables[$previous ? 'encryptionKeyPrevious' : 'encryptionKey'] ?? '');
        if ($secret === '') {
            if ($previous) {
                return '';
            }
            throw new HttpException(
                503,
                'wearables_encryption_missing',
                'Integracje urządzeń nie mają ustawionego klucza szyfrowania.'
            );
        }
        return hash('sha256', $secret . '|alpha-vault-wearables', true);
    }

    private function http(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Nie można zainicjować cURL.');
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => array_merge($headers, ['User-Agent: AlphaVault/' . $this->config->version . ' (wearables)']),
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_ENCODING => '',
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new \RuntimeException($error !== '' ? $error : 'Błąd połączenia z dostawcą.');
        }
        $json = json_decode((string) $raw, true);
        return ['status' => $status, 'body' => (string) $raw, 'json' => is_array($json) ? $json : null];
    }

    private function redactRaw(array $raw): array
    {
        $out = $raw;
        foreach (['access_token', 'refresh_token', 'token', 'code', 'client_secret', 'authorization'] as $key) {
            if (array_key_exists($key, $out)) {
                $out[$key] = '[redacted]';
            }
        }
        return $out;
    }

    private function safeError(\Throwable $err): string
    {
        $message = trim($err->getMessage());
        $message = preg_replace('/(access_token|refresh_token|token|code|client_secret)=([^&\s]+)/i', '$1=[redacted]', $message) ?? $message;
        return substr($message !== '' ? $message : 'Nieznany błąd integracji.', 0, 300);
    }

    private function providerConfig(string $provider): ?array
    {
        $cfg = $this->config->wearables[$provider] ?? null;
        return is_array($cfg) ? $cfg : null;
    }

    private function assertProvider(string $provider): void
    {
        if (!in_array($provider, self::PROVIDERS, true)) {
            throw new HttpException(404, 'unknown_provider', 'Nieznany dostawca danych.');
        }
    }

    private function assertOauthProvider(string $provider): void
    {
        $this->assertProvider($provider);
        if (!in_array($provider, self::OAUTH_PROVIDERS, true)) {
            throw new HttpException(400, 'unsupported_provider', 'To urządzenie nie używa OAuth2.');
        }
    }

    private function todayKey(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Warsaw')))->format('Y-m-d');
    }

    private function utcIso(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $ts = strtotime($value . ' UTC');
        return $ts === false ? null : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    private function dateOnly(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $m) === 1) {
            return $m[1];
        }
        $ts = strtotime($value);
        return $ts === false ? null : gmdate('Y-m-d', $ts);
    }

    private static function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
