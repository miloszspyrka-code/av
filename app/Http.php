<?php
declare(strict_types=1);

namespace AlphaVault;

final class Http
{
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function html(string $html, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    public static function redirect(string $url, int $status = 302): never
    {
        http_response_code($status);
        header('Location: ' . $url, true, $status);
        exit;
    }

    public static function securityHeaders(bool $secure): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header(
            "Content-Security-Policy: default-src 'self'; "
            . "script-src 'self'; "
            . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
            . "font-src 'self' https://fonts.gstatic.com data:; "
            . "img-src 'self' data: blob: https://cdn.discordapp.com; "
            . "connect-src 'self'; "
            . "frame-ancestors 'none'; "
            . "base-uri 'self'; "
            . "form-action 'self'"
        );
        if ($secure) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    public static function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function path(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        return is_string($path) && $path !== '' ? $path : '/';
    }

    public static function readJsonBody(int $maxBytes): array
    {
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > $maxBytes) {
            throw new HttpException(413, 'payload_too_large', 'Żądanie jest za duże.');
        }
        $raw = file_get_contents('php://input');
        if ($raw === false) {
            throw new HttpException(400, 'bad_request', 'Nie można odczytać treści żądania.');
        }
        if (strlen($raw) > $maxBytes) {
            throw new HttpException(413, 'payload_too_large', 'Żądanie jest za duże.');
        }
        if (trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new HttpException(400, 'bad_json', 'Treść żądania musi być obiektem JSON.');
        }
        return $decoded;
    }

    public static function errorPage(int $status, string $title, string $text, string $detail = ''): never
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeText = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeDetail = htmlspecialchars($detail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $detailHtml = $safeDetail !== '' ? '<p><code>' . $safeDetail . '</code></p>' : '';
        self::html('<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
            . $safeTitle . ' — Alpha Vault</title><style>body{margin:0;background:#080707;color:#f5f1eb;font-family:system-ui,Arial,sans-serif;display:grid;place-items:center;min-height:100vh;padding:24px}.box{max-width:520px;width:100%;background:#121110;border:1px solid rgba(255,255,255,.11);border-radius:24px;padding:34px;text-align:center}.badge{font-family:monospace;font-size:11px;letter-spacing:.2em;color:#d9b25f;text-transform:uppercase}h1{font-size:28px;margin:12px 0}p{color:#b7b0a6;line-height:1.6;font-size:14px}code{color:#f1d48e;background:rgba(217,178,95,.12);padding:2px 6px;border-radius:5px}.btn{display:inline-block;margin-top:20px;background:#f5f1eb;color:#080707;text-decoration:none;font-weight:700;padding:12px 20px;border-radius:99px}small{display:block;margin-top:18px;color:#7f786f;font-size:11px;line-height:1.5}</style></head><body><div class="box"><div class="badge">Alpha Vault</div><h1>'
            . $safeTitle . '</h1><p>' . $safeText . '</p>' . $detailHtml
            . '<a class="btn" href="/">Wróć do logowania</a><small>Jeśli problem się powtarza, skontaktuj się z zespołem Alpha Moves.</small></div></body></html>', $status);
    }

    public static function notFound(bool $json): never
    {
        if ($json) {
            self::json(['error' => 'not_found', 'message' => 'Nie ma takiego endpointu.'], 404);
        }
        self::html('<!doctype html><html lang="pl"><meta charset="utf-8"><body style="background:#080707;color:#f5f1eb;font-family:Arial;padding:40px"><h1>404</h1><p>Nie ma takiej strony. <a style="color:#f1d48e" href="/">Wróć do Alpha Vault</a>.</p></body></html>', 404);
    }
}
