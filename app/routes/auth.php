<?php
declare(strict_types=1);

namespace AlphaVault;

function av_auth_error(int $status, string $title, string $text, string $detail = ''): never
{
    Http::errorPage($status, $title, $text, $detail);
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
            'UPDATE users SET username = ?, global_name = ?, avatar_url = ?, email = ?, role_verified = 1, last_login_at = CURRENT_TIMESTAMP WHERE id = ?',
            [
                (string) ($profile['username'] ?? $existing['username']),
                $profile['global_name'] ?? null,
                $profile['avatar_url'] ?? $existing['avatar_url'],
                $profile['email'] ?? $existing['email'],
                (int) $existing['id'],
            ]
        );
        return $db->queryOne('SELECT * FROM users WHERE id = ?', [(int) $existing['id']]) ?? $existing;
    }
    $db->execute(
        'INSERT INTO users (discord_id, username, global_name, avatar_url, email, is_demo, plan, access_until, role_verified, last_login_at)
         VALUES (?, ?, ?, ?, ?, 0, ?, ?, 1, CURRENT_TIMESTAMP)',
        [
            (string) $profile['id'],
            (string) ($profile['username'] ?? 'Trader'),
            $profile['global_name'] ?? null,
            $profile['avatar_url'] ?? null,
            $profile['email'] ?? null,
            $config->discord['defaultPlan'],
            $trialUntil,
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
            if (!$role['ok']) {
                $auth->audit(null, 'login.denied', [
                    'reason' => $role['reason'] ?? 'role_check_failed',
                    'discordId' => (string) $profile['id'],
                ]);
                av_auth_error(403, 'Brak dostępu do Alpha Vault', (string) ($role['reason'] ?? 'Brak wymaganej roli.'), (string) ($role['detail'] ?? ''));
            }
            $dbUser = av_upsert_discord_user($config, $db, $profile, (bool) $role['checked']);
            if (empty($dbUser['id'])) {
                av_auth_error(500, 'Błąd konta', 'Nie udało się utworzyć konta użytkownika.');
            }
            $session = $auth->createSession((int) $dbUser['id'], (bool) $row['remember']);
            $auth->setSessionCookie($session);
            $auth->audit((int) $dbUser['id'], 'login.discord', ['roleVerified' => (bool) $role['checked']]);
            Http::redirect('/#/pulpit');
        }

        if ($path === '/auth/demo' && $method === 'POST') {
            if (!$config->allowDemoLogin) {
                Http::json(['error' => 'demo_disabled', 'message' => 'Logowanie demo jest wyłączone.'], 403);
            }
            $auth->requireSameOrigin();
            $auth->rateLimit('demo-login', 20, 60000);
            $body = Http::readJsonBody(65536);
            $demo = $db->queryOne("SELECT * FROM users WHERE discord_id = 'demo-local' LIMIT 1");
            if ($demo === null) {
                $db->execute(
                    "INSERT INTO users (discord_id, username, global_name, avatar_url, email, is_demo, plan, access_until, role_verified, last_login_at)
                     VALUES ('demo-local', 'Kasia (demo)', 'Kasia', NULL, 'kasia@example.com', 1, 'full', '2099-12-31', 1, CURRENT_TIMESTAMP)"
                );
                $demo = $db->queryOne('SELECT * FROM users WHERE id = ?', [$db->lastInsertId()]);
            } else {
                $db->execute('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?', [(int) $demo['id']]);
            }
            $remember = ($body['remember'] ?? true) !== false;
            $session = $auth->createSession((int) $demo['id'], $remember);
            $auth->setSessionCookie($session);
            $auth->audit((int) $demo['id'], 'login.demo', ['remember' => $remember]);
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
