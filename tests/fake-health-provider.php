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

if (preg_match('#^/oauth/(garmin|oura|whoop)/authorize$#', $path, $m) === 1) {
    $redirect = (string) ($_GET['redirect_uri'] ?? '');
    $state = (string) ($_GET['state'] ?? '');
    if ($redirect === '' || $state === '') {
        out(['error' => 'invalid_request'], 400);
    }
    $sep = str_contains($redirect, '?') ? '&' : '?';
    header('Location: ' . $redirect . $sep . http_build_query([
        'code' => 'fake-code-' . $m[1],
        'state' => $state,
    ]), true, 302);
    exit;
}

if (preg_match('#^/oauth/(garmin|oura|whoop)/token$#', $path, $m) === 1) {
    if ($method !== 'POST') {
        out(['error' => 'method_not_allowed'], 405);
    }
    $grant = (string) ($_POST['grant_type'] ?? '');
    if (!in_array($grant, ['authorization_code', 'refresh_token'], true)) {
        out(['error' => 'unsupported_grant_type'], 400);
    }
    out([
        'access_token' => 'fake-access-' . $m[1] . '-' . ($grant === 'refresh_token' ? 'refreshed' : 'initial'),
        'refresh_token' => 'fake-refresh-' . $m[1],
        'token_type' => 'Bearer',
        'expires_in' => 3600,
        'scope' => 'fake',
    ]);
}

if (preg_match('#^/oauth/(garmin|oura|whoop)/revoke$#', $path, $m) === 1) {
    out(['ok' => true]);
}

if ($path === '/oura/v2/usercollection/daily_readiness') {
    $rows = [];
    for ($i = 13; $i >= 0; $i--) {
        $rows[] = ['day' => day($i), 'score' => $i === 0 ? 70 : 75];
    }
    out(['data' => $rows]);
}

if ($path === '/oura/v2/usercollection/sleep') {
    $rows = [];
    for ($i = 13; $i >= 0; $i--) {
        $rows[] = [
            'day' => day($i),
            'type' => 'long_sleep',
            'total_sleep_duration' => $i === 0 ? 312 * 60 : 450 * 60,
            'average_hrv' => $i === 0 ? 38 : 52,
            'lowest_heart_rate' => $i === 0 ? 61 : 56,
        ];
    }
    out(['data' => $rows]);
}

if ($path === '/whoop/developer/v1/recovery') {
    $rows = [];
    for ($i = 13; $i >= 0; $i--) {
        $rows[] = [
            'created_at' => dayIso($i, '07:30'),
            'score' => [
                'recovery_score' => $i === 0 ? 70 : 75,
                'hrv_rmssd_milli' => $i === 0 ? 38 : 52,
                'resting_heart_rate' => $i === 0 ? 61 : 56,
            ],
        ];
    }
    out(['records' => $rows, 'next_token' => null]);
}

if ($path === '/whoop/developer/v1/activity/sleep') {
    $rows = [];
    for ($i = 13; $i >= 0; $i--) {
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
    out(['records' => $rows, 'next_token' => null]);
}

if ($path === '/garmin/wellness-api/rest/dailies') {
    $rows = [];
    for ($i = 13; $i >= 0; $i--) {
        $rows[] = [
            'calendarDate' => day($i),
            'restingHeartRateInBeatsPerMinute' => $i === 0 ? 61 : 56,
            'bodyBatteryMostRecentValue' => $i === 0 ? 34 : 70,
        ];
    }
    out(['dailies' => $rows]);
}

if ($path === '/garmin/wellness-api/rest/sleeps') {
    $rows = [];
    for ($i = 13; $i >= 0; $i--) {
        $rows[] = [
            'calendarDate' => day($i),
            'sleepTimeInSeconds' => ($i === 0 ? 312 : 450) * 60,
            'awakeDurationInSeconds' => 0,
        ];
    }
    out(['sleeps' => $rows]);
}

if ($path === '/garmin/wellness-api/rest/hrv') {
    $rows = [];
    for ($i = 13; $i >= 0; $i--) {
        $rows[] = [
            'calendarDate' => day($i),
            'lastNightAvg' => $i === 0 ? 38 : 52,
        ];
    }
    out(['hrv' => $rows]);
}

out(['error' => 'not_found', 'path' => $path], 404);
