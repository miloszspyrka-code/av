<?php
declare(strict_types=1);

/**
 * Helper testowy do operacji na bazie, których nie da się wykonać przez API.
 * Używa tej samej konfiguracji i klucza szyfrowania co test E2E.
 *
 * Uruchom: php tests/wearables-db.php <command> [args]
 */

[$config, $db] = require __DIR__ . '/../app/bootstrap.php';

function out(array $data): never
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
}

function enc(string $plain, \AlphaVault\Config $config): string
{
    $secret = (string) ($config->wearables['encryptionKey'] ?? '');
    if ($secret === '') {
        throw new RuntimeException('Brak WEARABLES_ENCRYPTION_KEY.');
    }
    $key = hash('sha256', $secret . '|alpha-vault-wearables', true);
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($cipher === false) {
        throw new RuntimeException('encrypt failed');
    }
    return 'v1:' . base64_encode($iv . $tag . $cipher);
}

$command = (string) ($argv[1] ?? '');

if ($command === 'expire-token') {
    $provider = (string) ($argv[2] ?? '');
    $row = $db->queryOne(
        'SELECT refresh_token_enc FROM wearable_connections WHERE provider = ? ORDER BY id DESC LIMIT 1',
        [$provider]
    );
    $db->execute(
        'UPDATE wearable_connections SET token_expires_at = UTC_TIMESTAMP() - INTERVAL 1 HOUR WHERE provider = ?',
        [$provider]
    );
    out(['refreshHash' => hash('sha256', (string) ($row['refresh_token_enc'] ?? ''))]);
}

if ($command === 'refresh-hash') {
    $provider = (string) ($argv[2] ?? '');
    $row = $db->queryOne(
        'SELECT refresh_token_enc FROM wearable_connections WHERE provider = ? ORDER BY id DESC LIMIT 1',
        [$provider]
    );
    out(['refreshHash' => hash('sha256', (string) ($row['refresh_token_enc'] ?? ''))]);
}

if ($command === 'set-access') {
    $provider = (string) ($argv[2] ?? '');
    $suffix = (string) ($argv[3] ?? '');
    $db->execute(
        'UPDATE wearable_connections
            SET access_token_enc = ?, token_expires_at = UTC_TIMESTAMP() + INTERVAL 1 HOUR,
                last_sync_status = NULL, last_error = NULL
          WHERE provider = ?',
        [enc('fake-access-' . $provider . '-' . $suffix, $config), $provider]
    );
    out(['ok' => true]);
}

if ($command === 'expire-refresh') {
    $provider = (string) ($argv[2] ?? '');
    $db->execute(
        'UPDATE wearable_connections SET refresh_token_expires_at = UTC_TIMESTAMP() - INTERVAL 1 HOUR WHERE provider = ?',
        [$provider]
    );
    out(['ok' => true]);
}

if ($command === 'expire-state') {
    $state = (string) ($argv[2] ?? '');
    $db->execute('UPDATE wearable_oauth_states SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE state = ?', [$state]);
    out(['ok' => true]);
}

if ($command === 'create-other-user') {
    $username = 'E2E other ' . bin2hex(random_bytes(4));
    $db->execute('INSERT INTO users (username, is_demo, plan) VALUES (?, 0, \'full\')', [$username]);
    $userId = $db->lastInsertId();
    $db->execute(
        'INSERT INTO wearable_connections
            (user_id, provider, status, external_user_id, scopes, access_token_enc, refresh_token_enc, token_expires_at, refresh_token_expires_at, last_sync_status, consent_at)
         VALUES (?, \'oura\', \'connected\', \'other-user\', \'daily\', ?, ?, UTC_TIMESTAMP() + INTERVAL 1 HOUR, NULL, \'ok\', UTC_TIMESTAMP())',
        [$userId, enc('fake-access-oura-other', $config), enc('fake-refresh-oura-other', $config)]
    );
    $db->execute(
        'INSERT INTO wearable_daily_metrics (user_id, provider, metric_date, sleep_minutes, synced_at)
         VALUES (?, \'oura\', UTC_DATE(), 480, UTC_TIMESTAMP())',
        [$userId]
    );
    out(['userId' => $userId]);
}

if ($command === 'delete-user') {
    $userId = (int) ($argv[2] ?? 0);
    if ($userId > 0) {
        $db->execute('DELETE FROM users WHERE id = ?', [$userId]);
    }
    out(['ok' => true]);
}

if ($command === 'count-connections') {
    $provider = (string) ($argv[2] ?? '');
    $row = $db->queryOne('SELECT COUNT(*) AS c FROM wearable_connections WHERE provider = ?', [$provider]);
    out(['count' => (int) ($row['c'] ?? 0)]);
}

if ($command === 'connection') {
    $provider = (string) ($argv[2] ?? '');
    $row = $db->queryOne(
        'SELECT provider, status, external_user_id, scopes, last_sync_status, last_error, token_expires_at, refresh_token_expires_at
           FROM wearable_connections WHERE provider = ? ORDER BY id DESC LIMIT 1',
        [$provider]
    );
    out(['connection' => $row]);
}

out(['error' => 'unknown_command', 'command' => $command]);
