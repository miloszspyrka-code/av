<?php
declare(strict_types=1);

namespace AlphaVault;

final class Auth
{
    public function __construct(
        private readonly Config $config,
        private readonly Db $db,
    ) {
    }

    public function parseCookies(string $header = ''): array
    {
        $out = [];
        foreach (explode(';', $header) as $part) {
            $i = strpos($part, '=');
            if ($i === false) {
                continue;
            }
            $key = trim(substr($part, 0, $i));
            $value = trim(substr($part, $i + 1));
            if ($key === '') {
                continue;
            }
            $out[$key] = rawurldecode($value);
        }
        return $out;
    }

    public function serializeCookie(string $name, string $value, array $opts = []): string
    {
        $parts = [
            $name . '=' . rawurlencode($value),
            'Path=/',
            'HttpOnly',
            'SameSite=Lax',
        ];
        if (isset($opts['maxAge'])) {
            $parts[] = 'Max-Age=' . (int) floor((float) $opts['maxAge']);
        }
        if (isset($opts['expires'])) {
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s T', (int) $opts['expires']);
        }
        if ($this->config->secureCookies) {
            $parts[] = 'Secure';
        }
        return implode('; ', $parts);
    }

    public function randomToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function safeEqual(?string $a, ?string $b): bool
    {
        if ($a === null || $b === null || $a === '' || $b === '') {
            return false;
        }
        return hash_equals($a, $b);
    }

    public function clientIp(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $ip = $remote;
        if ($this->config->trustProxy) {
            $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            if ($forwarded !== '') {
                $candidate = trim(explode(',', $forwarded)[0]);
                if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                    $ip = $candidate;
                }
            }
        }
        return substr(trim($ip), 0, 64);
    }

    public function userAgent(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);
    }

    public function audit(?int $userId, string $action, ?array $detail = null): void
    {
        try {
            $this->db->execute(
                'INSERT INTO audit_log (user_id, action, detail, ip, user_agent) VALUES (?, ?, ?, ?, ?)',
                [
                    $userId,
                    $action,
                    $detail !== null ? json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                    $this->clientIp(),
                    $this->userAgent(),
                ]
            );
        } catch (\Throwable $err) {
            error_log('[audit] ' . $err->getMessage());
        }
    }

    public function createSession(int $userId, bool $remember): array
    {
        $token = $this->randomToken(32);
        $id = $this->hashToken($token);
        $expires = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $expires = $expires->modify('+' . $this->config->sessionDays . ' days');
        $this->db->execute(
            'INSERT INTO sessions (id, user_id, remember, user_agent, ip, expires_at) VALUES (?, ?, ?, ?, ?, ?)',
            [
                $id,
                $userId,
                $remember ? 1 : 0,
                $this->userAgent(),
                $this->clientIp(),
                $expires->format('Y-m-d H:i:s'),
            ]
        );
        return ['token' => $token, 'expires' => $expires->getTimestamp(), 'days' => $this->config->sessionDays];
    }

    public function setSessionCookie(array $session): void
    {
        $cookie = $this->serializeCookie($this->config->sessionCookie, $session['token'], [
            'maxAge' => $session['days'] * 86400,
            'expires' => $session['expires'],
        ]);
        header('Set-Cookie: ' . $cookie, false);
    }

    public function setCookie(string $name, string $value, array $opts = []): void
    {
        header('Set-Cookie: ' . $this->serializeCookie($name, $value, $opts), false);
    }

    public function clearCookie(string $name): void
    {
        $this->setCookie($name, '', ['maxAge' => 0, 'expires' => time() - 3600]);
    }

    public function clearSessionCookie(): void
    {
        $cookie = $this->serializeCookie($this->config->sessionCookie, '', [
            'maxAge' => 0,
            'expires' => time() - 3600,
        ]);
        header('Set-Cookie: ' . $cookie, false);
    }

    public function readSession(): ?array
    {
        $cookies = $this->parseCookies((string) ($_SERVER['HTTP_COOKIE'] ?? ''));
        $token = $cookies[$this->config->sessionCookie] ?? '';
        if ($token === '') {
            return null;
        }
        $id = $this->hashToken($token);
        $row = $this->db->queryOne(
            'SELECT s.id AS session_id, s.remember, s.expires_at, u.*
               FROM sessions s JOIN users u ON u.id = s.user_id
              WHERE s.id = ? AND s.expires_at > UTC_TIMESTAMP()
              LIMIT 1',
            [$id]
        );
        if ($row === null) {
            return null;
        }
        $lastSeen = $row['last_seen_at'] ?? null;
        if ($lastSeen === null || (time() - strtotime((string) $lastSeen . ' UTC')) > 300) {
            try {
                $this->db->execute('UPDATE sessions SET last_seen_at = CURRENT_TIMESTAMP WHERE id = ?', [$id]);
            } catch (\Throwable) {
                // Nie blokuj żądania z powodu odświeżenia last_seen.
            }
        }
        return [
            'sessionId' => $id,
            'remember' => (bool) $row['remember'],
            'expiresAt' => $row['expires_at'],
            'user' => [
                'id' => (int) $row['id'],
                'discordId' => $row['discord_id'],
                'username' => $row['username'],
                'globalName' => $row['global_name'],
                'avatarUrl' => $row['avatar_url'],
                'email' => $row['email'],
                'isDemo' => (bool) $row['is_demo'],
                'plan' => $row['plan'],
                'accessUntil' => $row['access_until'],
                'roleVerified' => (bool) $row['role_verified'],
                'createdAt' => $row['created_at'],
                'lastLoginAt' => $row['last_login_at'],
            ],
        ];
    }

    public function requireAuth(?array $user): array
    {
        if ($user === null) {
            Http::json(['error' => 'unauthorized', 'message' => 'Zaloguj się przez Discord.'], 401);
        }
        return $user;
    }

    public function destroySession(): void
    {
        $cookies = $this->parseCookies((string) ($_SERVER['HTTP_COOKIE'] ?? ''));
        $token = $cookies[$this->config->sessionCookie] ?? '';
        if ($token !== '') {
            $this->db->execute('DELETE FROM sessions WHERE id = ?', [$this->hashToken($token)]);
        }
        $this->clearSessionCookie();
    }

    public function subscription(array $user): array
    {
        $until = $user['accessUntil'] ?? null;
        $expired = false;
        if ($until !== null && $until !== '') {
            $expiry = new \DateTimeImmutable((string) $until . ' 23:59:59', new \DateTimeZone('UTC'));
            $expired = $expiry->getTimestamp() < time();
        }
        return [
            'plan' => $user['plan'] ?? 'full',
            'accessUntil' => $until,
            'expired' => $expired,
            'roleVerified' => (bool) ($user['roleVerified'] ?? false),
        ];
    }

    public function publicUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'globalName' => $user['globalName'] ?? null,
            'avatarUrl' => $user['avatarUrl'] ?? null,
            'email' => $user['email'] ?? null,
            'isDemo' => (bool) ($user['isDemo'] ?? false),
            'subscription' => $this->subscription($user),
        ];
    }

    public function requireSameOrigin(): void
    {
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin === '') {
            return;
        }
        $allowed = [$this->config->origin];
        $parts = parse_url($this->config->origin);
        if (is_array($parts) && !empty($parts['host'])) {
            $host = strtolower((string) $parts['host']);
            $altHost = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.' . $host;
            $alt = (string) ($parts['scheme'] ?? 'https') . '://' . $altHost;
            if (isset($parts['port'])) {
                $alt .= ':' . $parts['port'];
            }
            $allowed[] = $alt;
        }
        if (!in_array($origin, array_values(array_unique($allowed)), true)) {
            Http::json(['error' => 'bad_origin', 'message' => 'Nieprawidłowe źródło żądania.'], 403);
        }
    }

    public function rateLimit(string $name, int $limit, int $windowMs): void
    {
        $windowSeconds = max(1, (int) floor($windowMs / 1000));
        $windowStart = time() - (time() % $windowSeconds);
        $ip = $this->clientIp();
        $this->db->execute(
            'INSERT INTO rate_limits (bucket, ip, window_start, count) VALUES (?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE
               count = IF(window_start = VALUES(window_start), count + 1, 1),
               window_start = VALUES(window_start)',
            [$name, $ip, $windowStart]
        );
        $row = $this->db->queryOne(
            'SELECT count FROM rate_limits WHERE bucket = ? AND ip = ?',
            [$name, $ip]
        );
        $count = (int) ($row['count'] ?? 0);
        if ($count > $limit) {
            Http::json(['error' => 'rate_limited', 'message' => 'Zbyt wiele prób. Spróbuj za chwilę.'], 429);
        }
        if (random_int(1, 100) === 1) {
            try {
                $this->db->execute('DELETE FROM rate_limits WHERE window_start < ?', [time() - 86400]);
            } catch (\Throwable) {
                // sprzątanie jest opcjonalne
            }
        }
    }
}
