<?php
declare(strict_types=1);

/**
 * Lokalny dostawca danych zdrowotnych do testów E2E.
 * Nie jest częścią produkcji i nie zawiera prawdziwych danych.
 *
 * Uruchomienie:
 *   php -S 127.0.0.1:4180 tests/fake-health-provider.php
 */

date_default_timezone_set('Europe/Warsaw');

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function day(int $offset): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))
        ->modify('-' . $offset . ' days')
        ->format('Y-m-d');
}

function dayIso(int $offset, string $time): string
{
    return day($offset) . 'T' . $time . ':00+01:00';
}

function base64url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function bearer(): string
{
    $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = (string) ($headers['Authorization'] ?? $headers['authorization'] ?? '');
    }
    return preg_match('/^Bearer\s+(.+)$/i', $header, $m) === 1 ? trim($m[1]) : '';
}

function stateFile(): string
{
    $path = (string) getenv('FAKE_HEALTH_STATE');
    return $path !== '' ? $path : sys_get_temp_dir() . '/av-fake-health-state.json';
}

function bump(string $key): void
{
    $file = stateFile();
    $state = [];
    if (is_file($file)) {
        $decoded = json_decode((string) file_get_contents($file), true);
        if (is_array($decoded)) {
            $state = $decoded;
        }
    }
    $state[$key] = (int) ($state[$key] ?? 0) + 1;
    file_put_contents($file, json_encode($state));
}

function bumpRefresh(string $provider): void
{
    bump($provider . '_refresh_count');
}

if (preg_match('#^/oauth/(garmin|oura|whoop)/authorize$#', $path, $m) === 1) {
    $provider = $m[1];
    $redirect = (string) ($_GET['redirect_uri'] ?? '');
    $state = (string) ($_GET['state'] ?? '');
    if ($redirect === '' || $state === '') {
        out(['error' => 'invalid_request'], 400);
    }
    $challenge = (string) ($_GET['code_challenge'] ?? '');
    $challengeMethod = (string) ($_GET['code_challenge_method'] ?? '');
    if (in_array($provider, ['garmin', 'oura'], true) && ($challenge === '' || $challengeMethod !== 'S256')) {
        out(['error' => 'pkce_required'], 400);
    }
    $code = 'fake-code-' . $provider . ($challenge !== '' ? '-' . $challenge : '');
    $sep = str_contains($redirect, '?') ? '&' : '?';
    header('Location: ' . $redirect . $sep . http_build_query([
        'code' => $code,
        'state' => $state,
    ]), true, 302);
    exit;
}

if (preg_match('#^/oauth/(garmin|oura|whoop)/token$#', $path, $m) === 1) {
    if ($method !== 'POST') {
        out(['error' => 'method_not_allowed'], 405);
    }
    $provider = $m[1];
    $grant = (string) ($_POST['grant_type'] ?? '');
    if (!in_array($grant, ['authorization_code', 'refresh_token'], true)) {
        out(['error' => 'unsupported_grant_type'], 400);
    }
    if ($grant === 'authorization_code') {
        $code = (string) ($_POST['code'] ?? '');
        if (preg_match('/^fake-code-(garmin|oura)-([A-Za-z0-9_-]{20,})$/', $code, $cm) === 1) {
            $verifier = (string) ($_POST['code_verifier'] ?? '');
            $expected = base64url(hash('sha256', $verifier, true));
            if ($verifier === '' || !hash_equals($cm[2], $expected)) {
                out(['error' => 'invalid_grant', 'error_description' => 'PKCE verification failed'], 400);
            }
        } elseif (!str_starts_with($code, 'fake-code-' . $provider)) {
            out(['error' => 'invalid_grant'], 400);
        }
    } else {
        bumpRefresh($provider);
    }
    out([
        'access_token' => 'fake-access-' . $provider . '-' . ($grant === 'refresh_token' ? 'refreshed' : 'initial'),
        'refresh_token' => 'fake-refresh-' . $provider . '-' . bin2hex(random_bytes(6)),
        'token_type' => 'Bearer',
        'expires_in' => 3600,
        'refresh_token_expires_in' => 7775998,
        'scope' => 'fake',
    ]);
}

if (preg_match('#^/oauth/(garmin|oura|whoop)/revoke$#', $path, $m) === 1) {
    out(['ok' => true]);
}

$access = bearer();
if ($access !== '' && str_contains($access, 'revoked')) {
    out(['error' => 'unauthorized'], 401);
}
if ($access !== '' && str_contains($access, 'ratelimit')) {
    header('Retry-After: 1');
    out(['error' => 'rate_limited'], 429);
}
if ($access !== '' && str_contains($access, 'badjson')) {
    header('Content-Type: text/plain');
    echo 'not-json';
    exit;
}

if ($path === '/oura/v2/usercollection/daily_readiness') {
    $rows = [];
    $offset = isset($_GET['next_token']) ? 7 : 0;
    for ($i = 13 - $offset; $i >= max(0, 7 - $offset); $i--) {
        $rows[] = ['day' => day($i), 'score' => $i === 0 ? 70 : 75];
    }
    out(['data' => $rows, 'next_token' => $offset === 0 ? 'oura-readiness-page-2' : null]);
}

if ($path === '/oura/v2/usercollection/sleep') {
    $rows = [];
    $offset = isset($_GET['next_token']) ? 7 : 0;
    for ($i = 13 - $offset; $i >= max(0, 7 - $offset); $i--) {
        $rows[] = [
            'day' => day($i),
            'type' => 'long_sleep',
            'total_sleep_duration' => $i === 0 ? 312 * 60 : 450 * 60,
            'average_hrv' => $i === 0 ? 38 : 52,
            'lowest_heart_rate' => $i === 0 ? 61 : 56,
        ];
    }
    out(['data' => $rows, 'next_token' => $offset === 0 ? 'oura-sleep-page-2' : null]);
}

if ($path === '/whoop/developer/v2/recovery') {
    $rows = [];
    $offset = isset($_GET['nextToken']) ? 7 : 0;
    for ($i = 13 - $offset; $i >= max(0, 7 - $offset); $i--) {
        $rows[] = [
            'created_at' => dayIso($i, '07:30'),
            'score' => [
                'recovery_score' => $i === 0 ? 70 : 75,
                'hrv_rmssd_milli' => $i === 0 ? 38 : 52,
                'resting_heart_rate' => $i === 0 ? 61 : 56,
            ],
        ];
    }
    out(['records' => $rows, 'next_token' => $offset === 0 ? 'whoop-recovery-page-2' : null]);
}

if ($path === '/whoop/developer/v2/activity/sleep') {
    $rows = [];
    $offset = isset($_GET['nextToken']) ? 7 : 0;
    for ($i = 13 - $offset; $i >= max(0, 7 - $offset); $i--) {
        $minutes = $i === 0 ? 312 : 450;
        $rows[] = [
            'start' => dayIso($i, '01:00'),
            'end' => dayIso($i, '07:00'),
            'score' => [
                'stage_summary' => [
                    'total_in_bed_time_milli' => $minutes * 60000,
                    'total_awake_time_milli' => 0,
                ],
            ],
        ];
    }
    out(['records' => $rows, 'next_token' => $offset === 0 ? 'whoop-sleep-page-2' : null]);
}

if ($path === '/garmin/wellness-api/rest/user/id') {
    out(['userId' => 'fake-garmin-user']);
}

if ($path === '/garmin/wellness-api/rest/user/permissions') {
    out(['HEALTH_EXPORT']);
}

if ($path === '/garmin/wellness-api/rest/user/registration' && $method === 'DELETE') {
    bump('garmin_revoke_count');
    out(['ok' => true]);
}

if ($path === '/whoop/developer/v2/user/access' && $method === 'DELETE') {
    bump('whoop_revoke_count');
    out(['ok' => true]);
}

if ($path === '/oura/oauth/revoke') {
    bump('oura_revoke_count');
    out(['ok' => true]);
}

out(['error' => 'not_found', 'path' => $path], 404);
