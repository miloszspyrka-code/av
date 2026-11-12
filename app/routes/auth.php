<?php
declare(strict_types=1);

namespace AlphaVault;

function av_auth_error(int $status, string $title, string $text, string $detail = ''): never
{
    Http::errorPage($status, $title, $text, $detail);
}

/**
 * Zwraca stabilny identyfikator demo dla tej przeglądarki.
 * Dzięki temu dwie osoby nie trafiają do jednego wspólnego konta demo.
 *
 * @return array{0:string,1:bool} [klucz, czy nowy]
 */
function av_demo_identity(Config $config, Auth $auth): array
{
    $cookies = $auth->parseCookies((string) ($_SERVER['HTTP_COOKIE'] ?? ''));
    $key = strtolower(trim((string) ($cookies[$config->demoCookie] ?? '')));
    $fresh = preg_match('/^[a-f0-9]{24}$/', $key) !== 1;
    if ($fresh) {
        $key = bin2hex(random_bytes(12));
        $auth->setCookie($config->demoCookie, $key, [
            'maxAge' => 30 * 86400,
            'expires' => time() + 30 * 86400,
        ]);
    }
    return [$key, $fresh];
}

function av_cleanup_demo_users(Db $db, int $keepId): void
{
    try {
        $db->execute(
            "DELETE u FROM users u
             LEFT JOIN sessions s ON s.user_id = u.id AND s.expires_at > UTC_TIMESTAMP()
             WHERE u.is_demo = 1 AND u.id <> ?
               AND u.discord_id LIKE 'demo-%'
               AND s.id IS NULL
               AND (u.last_login_at IS NULL OR u.last_login_at < UTC_TIMESTAMP() - INTERVAL 7 DAY)",
            [$keepId]
        );
    } catch (\Throwable $err) {
        // Sprzątanie nie może blokować logowania demo.
        error_log('[auth] demo cleanup: ' . $err->getMessage());
    }
}

function av_upsert_discord_user(Config $config, Db $db, array $profile, bool $roleVerified): array
{
    $existing = $db->queryOne('SELECT * FROM users WHERE discord_id = ? LIMIT 1', [(string) $profile['id']]);
    $trialUntil = null;
    if ((int) $config->discord['trialDays'] > 0) {
        $trialUntil = gmdate('Y-m-d', time() + ((int) $config->discord['trialDays'] * 86400));
    }
    if ($existing !== null) {
        $db->execute(
            'UPDATE users SET username = ?, global_name = ?, avatar_url = ?, email = ?, role_verified = ?, last_login_at = CURRENT_TIMESTAMP WHERE id = ?',
            [
                (string) ($profile['username'] ?? $existing['username']),
                $profile['global_name'] ?? null,
                $profile['avatar_url'] ?? $existing['avatar_url'],
                $profile['email'] ?? $existing['email'],
                $roleVerified ? 1 : (int) ($existing['role_verified'] ?? 0),
                (int) $existing['id'],
            ]
        );
        return $db->queryOne('SELECT * FROM users WHERE id = ?', [(int) $existing['id']]) ?? $existing;
    }
    $db->execute(
        'INSERT INTO users (discord_id, username, global_name, avatar_url, email, is_demo, plan, access_until, role_verified, last_login_at)
         VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, CURRENT_TIMESTAMP)',
        [
            (string) $profile['id'],
            (string) ($profile['username'] ?? 'Trader'),
            $profile['global_name'] ?? null,
            $profile['avatar_url'] ?? null,
            $profile['email'] ?? null,
            $config->discord['defaultPlan'],
            $trialUntil,
            $roleVerified ? 1 : 0,
        ]
    );
    return $db->queryOne('SELECT * FROM users WHERE id = ?', [$db->lastInsertId()]) ?? [];
}

function av_handle_auth(Config $config, Db $db, Auth $auth, Discord $discord, ?array $user, string $method, string $path): void
{
    try {
        if ($path === '/auth/discord' && $method === 'GET') {
            $auth->rateLimit('discord-start', 30, 60000);
            if (!$config->discordConfigured) {
                av_auth_error(
                    503,
                    'Discord nie jest podłączony',
                    'Uzupełnij DISCORD_CLIENT_ID, DISCORD_CLIENT_SECRET i DISCORD_REDIRECT_URI w konfiguracji serwera.'
                );
            }
            $remember = (string) ($_GET['remember'] ?? '1') !== '0';
            $state = $auth->randomToken(24);
            $expires = time() + 600;
            $db->execute('DELETE FROM oauth_states WHERE expires_at < UTC_TIMESTAMP()');
            $db->execute(
                'INSERT INTO oauth_states (state, remember, redirect_to, expires_at) VALUES (?, ?, ?, ?)',
                [$state, $remember ? 1 : 0, '/', gmdate('Y-m-d H:i:s', $expires)]
            );
            header('Set-Cookie: ' . $auth->serializeCookie($config->oauthCookie, $state, [
                'maxAge' => 600,
                'expires' => $expires,
            ]), false);
            Http::redirect($discord->authorizeUrl($state, $remember));
        }

        if ($path === '/auth/discord/callback' && $method === 'GET') {
            $auth->rateLimit('discord-callback', 30, 60000);
            if (isset($_GET['error'])) {
                $auth->audit(null, 'login.cancelled', ['error' => (string) $_GET['error']]);
                av_auth_error(400, 'Logowanie anulowane', 'Discord zwrócił informację, że logowanie nie zostało ukończone.');
            }
            $state = (string) ($_GET['state'] ?? '');
            $code = (string) ($_GET['code'] ?? '');
            if ($state === '' || $code === '') {
                av_auth_error(400, 'Brak kodu logowania', 'Discord nie przekazał kodu autoryzacji.');
            }
            $cookies = $auth->parseCookies((string) ($_SERVER['HTTP_COOKIE'] ?? ''));
            if (!$auth->safeEqual($cookies[$config->oauthCookie] ?? null, $state)) {
                av_auth_error(400, 'Nieprawidłowy stan logowania', 'Sesja logowania wygasła albo została przerwana. Spróbuj ponownie.');
            }
            $row = $db->queryOne(
                'SELECT * FROM oauth_states WHERE state = ? AND expires_at > UTC_TIMESTAMP() LIMIT 1',
                [$state]
            );
            if ($row === null) {
                av_auth_error(400, 'Sesja logowania wygasła', 'Odśwież stronę i zaloguj się ponownie.');
            }
            $db->execute('DELETE FROM oauth_states WHERE state = ?', [$state]);
            header('Set-Cookie: ' . $auth->serializeCookie($config->oauthCookie, '', [
                'maxAge' => 0,
                'expires' => time() - 3600,
            ]), false);

            $token = $discord->exchangeCode($code);
            $accessToken = (string) ($token['body']['access_token'] ?? '');
            if ($token['status'] < 200 || $token['status'] >= 300 || $accessToken === '') {
                $auth->audit(null, 'login.failed', ['step' => 'token', 'status' => $token['status']]);
                av_auth_error(502, 'Discord odrzucił logowanie', 'Nie udało się wymienić kodu na token. Sprawdź konfigurację OAuth2.', (string) ($token['body']['error'] ?? ''));
            }
            $me = $discord->fetchProfile($accessToken);
            if ($me['status'] < 200 || $me['status'] >= 300 || empty($me['body']['id'])) {
                av_auth_error(502, 'Nie udało się pobrać profilu', 'Discord nie zwrócił danych użytkownika.');
            }
            $profile = $me['body'];
            $profile['avatar_url'] = $discord->avatarUrl($profile);
            $role = $discord->checkRole($accessToken);
            $existing = $db->queryOne('SELECT * FROM users WHERE discord_id = ? LIMIT 1', [(string) $profile['id']]);
            $guard = new RoleGuard($config, $db, $discord);
            $decision = $guard->loginDecision($existing, $role);
            if (!$decision['allow']) {
                $auth->audit(null, 'login.denied', [
                    'reason' => $decision['reason'],
                    'status' => $decision['status'],
                    'discordId' => (string) $profile['id'],
                ]);
                if ($decision['http'] === 403) {
                    av_auth_error(403, 'Brak dostępu do Alpha Vault', $decision['reason'], (string) ($role['detail'] ?? ''));
                }
                av_auth_error(503, 'Nie udało się sprawdzić roli', $decision['reason']);
            }
            $dbUser = av_upsert_discord_user($config, $db, $profile, (bool) $role['ok']);
            if (empty($dbUser['id'])) {
                av_auth_error(500, 'Błąd konta', 'Nie udało się utworzyć konta użytkownika.');
            }
            try {
                if ($role['ok']) {
                    $guard->markVerified((int) $dbUser['id']);
                } else {
                    $guard->markTransient((int) $dbUser['id'], $decision['reason']);
                }
            } catch (\Throwable $err) {
                // Starsza baza bez kolumn role_* nie może zablokować logowania.
                error_log('[role] zapis stanu weryfikacji: ' . $err->getMessage());
            }
            $session = $auth->createSession((int) $dbUser['id'], (bool) $row['remember']);
            $auth->setSessionCookie($session);
            $auth->audit((int) $dbUser['id'], 'login.discord', [
                'roleVerified' => (bool) $role['ok'],
                'roleStatus' => (string) ($role['status'] ?? ''),
                'grace' => $decision['grace'],
            ]);
            Http::redirect('/#/pulpit');
        }

        if ($path === '/auth/demo' && $method === 'POST') {
            if (!$config->allowDemoLogin) {
                Http::json(['error' => 'demo_disabled', 'message' => 'Logowanie demo jest wyłączone.'], 403);
            }
            $auth->requireSameOrigin();
            $auth->rateLimit('demo-login', 20, 60000);
            $body = Http::readJsonBody(65536);
            [$demoKey] = av_demo_identity($config, $auth);
            $discordId = 'demo-' . $demoKey;
            $demo = $db->queryOne('SELECT * FROM users WHERE discord_id = ? LIMIT 1', [$discordId]);
            if ($demo === null) {
                $db->execute(
                    'INSERT INTO users (discord_id, username, global_name, avatar_url, email, is_demo, plan, access_until, role_verified, last_login_at)
                     VALUES (?, ?, ?, NULL, NULL, 1, ?, ?, 1, CURRENT_TIMESTAMP)',
                    [$discordId, 'Kasia (demo)', 'Kasia', 'full', '2099-12-31']
                );
                $demo = $db->queryOne('SELECT * FROM users WHERE id = ?', [$db->lastInsertId()]);
            } else {
                $db->execute('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?', [(int) $demo['id']]);
            }
            if ($demo === null || empty($demo['id'])) {
                Http::json(['error' => 'demo_failed', 'message' => 'Nie udało się utworzyć konta demo.'], 500);
            }
            // Jedna przeglądarka = jedno konto demo; poprzednia sesja nie może
            // pozostać aktywna po ponownym logowaniu.
            $auth->destroySession();
            $remember = ($body['remember'] ?? true) !== false;
            $session = $auth->createSession((int) $demo['id'], $remember);
            $auth->setSessionCookie($session);
            $auth->audit((int) $demo['id'], 'login.demo', ['remember' => $remember]);
            av_cleanup_demo_users($db, (int) $demo['id']);
            Http::json([
                'ok' => true,
                'user' => [
                    'id' => (int) $demo['id'],
                    'username' => $demo['username'],
                    'globalName' => $demo['global_name'],
                    'avatarUrl' => $demo['avatar_url'],
                    'email' => $demo['email'],
                    'isDemo' => true,
                    'plan' => $demo['plan'],
                    'accessUntil' => $demo['access_until'],
                    'roleVerified' => true,
                ],
                'redirect' => '/',
            ]);
        }

        if (preg_match('#^/auth/wearables/(oura)/start$#', $path, $matches) && $method === 'GET') {
            $current = $user;
            if ($current === null) {
                av_auth_error(401, 'Zaloguj się', 'Połączenie urządzenia wymaga zalogowania do Alpha Vault.');
            }
            $auth->rateLimit('wearables-start', 30, 60000);
            try {
                $wearables = new Wearables($config, $db, $auth);
                $url = $wearables->startUrl((int) $current['id'], $matches[1]);
            } catch (HttpException $err) {
                av_auth_error($err->status, 'Nie można połączyć urządzenia', $err->getMessage());
            } catch (\Throwable $err) {
                error_log('[wearables] start: ' . $err->getMessage());
                av_auth_error(500, 'Błąd integracji', $config->isProd ? 'Spróbuj ponownie za chwilę.' : $err->getMessage());
            }
            Http::redirect($url);
        }

        if (preg_match('#^/auth/wearables/(oura)/callback$#', $path, $matches) && $method === 'GET') {
            $current = $user;
            if ($current === null) {
                av_auth_error(401, 'Sesja wygasła', 'Zaloguj się ponownie i połącz urządzenie.');
            }
            $provider = $matches[1];
            if (isset($_GET['error'])) {
                $auth->audit((int) $current['id'], 'wearables.cancelled', ['provider' => $provider, 'error' => (string) $_GET['error']]);
                av_auth_error(400, 'Połączenie anulowane', 'Dostawca nie ukończył autoryzacji urządzenia.');
            }
            $state = (string) ($_GET['state'] ?? '');
            $code = (string) ($_GET['code'] ?? '');
            try {
                $wearables = new Wearables($config, $db, $auth);
                $result = $wearables->handleCallback((int) $current['id'], $provider, $code, $state);
            } catch (HttpException $err) {
                $auth->audit((int) $current['id'], 'wearables.connect_failed', ['provider' => $provider, 'error' => $err->errorCode]);
                av_auth_error($err->status, 'Nie udało się połączyć urządzenia', $err->getMessage());
            } catch (\Throwable $err) {
                error_log('[wearables] callback: ' . $err->getMessage());
                av_auth_error(500, 'Błąd integracji', $config->isProd ? 'Spróbuj ponownie za chwilę.' : $err->getMessage());
            }
            $query = 'connected=' . rawurlencode($provider);
            if (!empty($result['syncError'])) {
                $query .= '&sync=error';
            }
            Http::redirect('/?' . $query . '#cialo');
        }

        if ($path === '/auth/logout' && ($method === 'GET' || $method === 'POST')) {
            $auth->requireSameOrigin();
            $auth->audit($user !== null ? (int) $user['id'] : null, 'logout');
            $auth->destroySession();
            if ($method === 'GET') {
                Http::redirect('/');
            }
            Http::json(['ok' => true]);
        }

        if (str_starts_with($path, '/auth/')) {
            Http::json(['error' => 'not_found', 'message' => 'Nie ma takiego endpointu.'], 404);
        }
    } catch (HttpException $err) {
        Http::json(['error' => $err->errorCode, 'message' => $err->getMessage(), ...$err->extra], $err->status);
    } catch (\Throwable $err) {
        error_log('[auth] ' . $err->getMessage());
        av_auth_error(500, 'Błąd serwera', $config->isProd ? 'Spróbuj ponownie za chwilę.' : $err->getMessage());
    }
}
