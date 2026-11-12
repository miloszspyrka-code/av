<?php
declare(strict_types=1);

$appPathFile = __DIR__ . '/app-path.php';
$appDir = is_file($appPathFile) ? require $appPathFile : dirname(__DIR__) . '/app';
if (!is_string($appDir) || !is_dir($appDir)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Alpha Vault: nieprawidłowa ścieżka aplikacji.';
    exit;
}

try {
    [$config, $db, $auth, $discord] = require $appDir . '/bootstrap.php';
} catch (\Throwable $err) {
    error_log('[bootstrap] ' . $err->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Alpha Vault: błąd konfiguracji. Sprawdź logi serwera.';
    exit;
}

\AlphaVault\Http::securityHeaders($config->secureCookies);

$method = \AlphaVault\Http::method();
$path = \AlphaVault\Http::path();

if ($path === '/favicon.ico') {
    http_response_code(204);
    exit;
}
if ($path === '/index.html') {
    \AlphaVault\Http::redirect('/');
}
if ($path === '/') {
    header('Cache-Control: no-store');
    readfile(__DIR__ . '/index.html');
    exit;
}
if ($path === '/docs') {
    \AlphaVault\Http::redirect('/docs/');
}
if ($path === '/docs/') {
    \AlphaVault\Http::html('<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dokumentacja — Alpha Vault</title><style>body{background:#080707;color:#f5f1eb;font-family:system-ui,Arial,sans-serif;max-width:820px;margin:0 auto;padding:40px 22px;line-height:1.6}h1{font-size:34px;letter-spacing:-1px}a{color:#f1d48e}li{margin:8px 0}code{background:rgba(217,178,95,.12);padding:2px 6px;border-radius:5px;color:#f1d48e}small{color:#7f786f}</style></head><body><h1>Alpha Vault — dokumentacja</h1><p>Dokumentacja techniczna i produktowa.</p><ul><li><a href="/docs/README.md">Przegląd projektu</a></li><li><a href="/docs/API.md">API i logowanie Discord</a></li><li><a href="/docs/ARCHITEKTURA.md">Architektura</a></li><li><a href="/docs/BAZA-DANYCH.md">Baza danych MySQL</a></li><li><a href="/docs/FUNKCJE.md">Funkcje aplikacji</a></li><li><a href="/docs/TESTOWANIE.md">Testy e2e</a></li></ul><p><a href="/">← Wróć do aplikacji</a></p></body></html>');
}

if (str_starts_with($path, '/api/')) {
    $session = null;
    try {
        $session = $auth->readSession();
    } catch (\Throwable $err) {
        // Baza może być chwilowo niedostępna; /api/health zwróci 503, pozostałe API 401/500.
        error_log('[session] ' . $err->getMessage());
    }
    require_once $appDir . '/routes/api.php';
    \AlphaVault\av_handle_api($config, $db, $auth, $session['user'] ?? null, $method, $path);
}

if (str_starts_with($path, '/auth/')) {
    $session = null;
    try {
        $session = $auth->readSession();
    } catch (\Throwable $err) {
        error_log('[session] ' . $err->getMessage());
    }
    require_once $appDir . '/routes/auth.php';
    \AlphaVault\av_handle_auth($config, $db, $auth, $discord, $session['user'] ?? null, $method, $path);
}

// Statyczne pliki normalnie obsługuje Apache. To zabezpieczenie działa też
// przy wbudowanym serwerze PHP, gdy router nie zwrócił false.
if ($path !== '/' && is_file(__DIR__ . $path)) {
    $basename = basename($path);
    if (in_array($basename, ['app-path.php', '.env', 'config.json'], true)) {
        \AlphaVault\Http::notFound(false);
    }
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $types = [
        'html' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'md' => 'text/markdown; charset=utf-8',
        'yaml' => 'text/yaml; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
    ];
    header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
    readfile(__DIR__ . $path);
    exit;
}

\AlphaVault\Http::notFound(false);
