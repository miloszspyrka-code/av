<?php
declare(strict_types=1);

namespace AlphaVault;

function av_handle_api(Config $config, Db $db, Auth $auth, ?array $user, string $method, string $path): void
{
    try {
        if ($path === '/api/health' && $method === 'GET') {
            try {
                $health = $db->health();
            } catch (\Throwable $err) {
                Http::json([
                    'ok' => false,
                    'service' => 'alpha-vault',
                    'error' => 'db_unavailable',
                    'message' => $config->isProd ? 'Baza danych jest niedostępna.' : $err->getMessage(),
                ], 503);
            }
            Http::json([
                'ok' => $health['ok'],
                'service' => 'alpha-vault',
                'version' => $config->version,
                'db' => $health,
                'uptime' => (int) round(microtime(true) - AV_START),
                'now' => gmdate('Y-m-d\TH:i:s\Z'),
                'discordConfigured' => $config->discordConfigured,
                'roleCheckConfigured' => $config->roleCheckConfigured,
                'eventsConfigured' => $config->eventsConfigured,
                'demoAllowed' => $config->allowDemoLogin,
            ]);
        }

        if ($path === '/api/me' && $method === 'GET') {
            $events = new DiscordEvents($config, $db);
            $base = [
                'authenticated' => $user !== null,
                'discordConfigured' => $config->discordConfigured,
                'roleCheckConfigured' => $config->roleCheckConfigured,
                'eventsConfigured' => $config->eventsConfigured,
                'demoAllowed' => $config->allowDemoLogin,
                'isAdmin' => $events->isAdmin($user),
                'db' => $config->db['database'],
            ];
            if ($user === null) {
                Http::json($base);
            }
            Http::json([...$base, 'user' => $auth->publicUser($user)]);
        }

        if ($path === '/api/state' && $method === 'GET') {
            $current = $auth->requireAuth($user);
            $row = $db->queryOne('SELECT state, revision, bytes, updated_at FROM user_states WHERE user_id = ?', [$current['id']]);
            if ($row === null && $current['isDemo'] && is_file($config->demoStateFile)) {
                $demo = file_get_contents($config->demoStateFile);
                if ($demo !== false) {
                    $db->execute(
                        'INSERT INTO user_states (user_id, state, revision, bytes) VALUES (?, ?, 1, ?) ON DUPLICATE KEY UPDATE state = VALUES(state)',
                        [$current['id'], $demo, strlen($demo)]
                    );
                    $row = $db->queryOne('SELECT state, revision, bytes, updated_at FROM user_states WHERE user_id = ?', [$current['id']]);
                    $auth->audit((int) $current['id'], 'state.seeded_demo', ['bytes' => strlen($demo)]);
                }
            }
            if ($row === null) {
                Http::json(['state' => null, 'revision' => 0, 'updatedAt' => null]);
            }
            $state = json_decode((string) $row['state'], true);
            if (!is_array($state)) {
                $state = null;
            }
            Http::json([
                'state' => $state,
                'revision' => (int) $row['revision'],
                'bytes' => (int) $row['bytes'],
                'updatedAt' => $row['updated_at'],
            ]);
        }

        if ($path === '/api/state' && $method === 'PUT') {
            $current = $auth->requireAuth($user);
            $auth->requireSameOrigin();
            $auth->rateLimit('state-save', 180, 60000);
            $body = Http::readJsonBody($config->maxStateBytes);
            $state = $body['state'] ?? null;
            if (!is_array($state)) {
                Http::json(['error' => 'bad_state', 'message' => 'Pole state musi być obiektem JSON.'], 400);
            }
            $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                Http::json(['error' => 'bad_state', 'message' => 'Nie można zakodować stanu.'], 400);
            }
            $bytes = strlen($json);
            if ($bytes > $config->maxStateBytes) {
                Http::json([
                    'error' => 'state_too_large',
                    'message' => 'Stan jest za duży. Usuń część screenów.',
                    'bytes' => $bytes,
                    'limit' => $config->maxStateBytes,
                ], 413);
            }
            $existing = $db->queryOne('SELECT revision FROM user_states WHERE user_id = ?', [$current['id']]);
            if ($existing === null) {
                $db->execute(
                    'INSERT INTO user_states (user_id, state, revision, bytes) VALUES (?, ?, 1, ?)',
                    [$current['id'], $json, $bytes]
                );
                $auth->audit((int) $current['id'], 'state.create', ['bytes' => $bytes, 'revision' => 1]);
                Http::json(['ok' => true, 'revision' => 1, 'bytes' => $bytes, 'updatedAt' => gmdate('Y-m-d\TH:i:s\Z')]);
            }
            $baseRevision = isset($body['baseRevision']) && is_numeric($body['baseRevision'])
                ? (int) $body['baseRevision']
                : null;
            if ($baseRevision !== null && $baseRevision !== (int) $existing['revision']) {
                Http::json([
                    'error' => 'revision_conflict',
                    'message' => 'Dane zmienione w innej karcie.',
                    'revision' => (int) $existing['revision'],
                ], 409);
            }
            $nextRevision = (int) $existing['revision'] + 1;
            $affected = $db->execute(
                'UPDATE user_states SET state = ?, revision = ?, bytes = ? WHERE user_id = ? AND revision = ?',
                [$json, $nextRevision, $bytes, $current['id'], (int) $existing['revision']]
            );
            if ($affected === 0) {
                $latest = $db->queryOne('SELECT revision FROM user_states WHERE user_id = ?', [$current['id']]);
                Http::json([
                    'error' => 'revision_conflict',
                    'message' => 'Dane zmienione w innej karcie.',
                    'revision' => (int) ($latest['revision'] ?? 0),
                ], 409);
            }
            $auth->audit((int) $current['id'], 'state.save', ['bytes' => $bytes, 'revision' => $nextRevision]);
            Http::json(['ok' => true, 'revision' => $nextRevision, 'bytes' => $bytes, 'updatedAt' => gmdate('Y-m-d\TH:i:s\Z')]);
        }

        if ($path === '/api/state' && $method === 'DELETE') {
            $current = $auth->requireAuth($user);
            $auth->requireSameOrigin();
            $db->execute('DELETE FROM user_states WHERE user_id = ?', [$current['id']]);
            $auth->audit((int) $current['id'], 'state.delete');
            Http::json(['ok' => true]);
        }

        if ($path === '/api/account' && $method === 'DELETE') {
            $current = $auth->requireAuth($user);
            $auth->requireSameOrigin();
            $auth->audit((int) $current['id'], 'account.delete', ['demo' => (bool) $current['isDemo']]);
            $auth->destroySession();
            $db->execute('DELETE FROM users WHERE id = ?', [$current['id']]);
            Http::json(['ok' => true]);
        }

        if ($path === '/api/me/export' && $method === 'GET') {
            $current = $auth->requireAuth($user);
            $row = $db->queryOne('SELECT state, revision, updated_at FROM user_states WHERE user_id = ?', [$current['id']]);
            $state = $row !== null ? json_decode((string) $row['state'], true) : null;
            $payload = [
                'user' => $auth->publicUser($current),
                'revision' => (int) ($row['revision'] ?? 0),
                'updatedAt' => $row['updated_at'] ?? null,
                'state' => is_array($state) ? $state : null,
            ];
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="alpha-vault-' . gmdate('Y-m-d') . '.json"');
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            exit;
        }

        if ($path === '/api/events' && $method === 'GET') {
            $auth->requireAuth($user);
            $events = new DiscordEvents($config, $db);
            Http::json($events->listForApi(false));
        }

        if ($path === '/api/admin/events' && $method === 'GET') {
            $current = $auth->requireAuth($user);
            $events = new DiscordEvents($config, $db);
            if (!$events->isAdmin($current)) {
                Http::json(['error' => 'forbidden', 'message' => 'Brak uprawnień administratora wydarzeń.'], 403);
            }
            Http::json([
                'isAdmin' => true,
                'events' => $events->listForApi(true)['events'],
                'sync' => $events->syncState(),
            ]);
        }

        if (preg_match('#^/api/admin/events/([0-9]+)/category$#', $path, $matches) && $method === 'PUT') {
            $current = $auth->requireAuth($user);
            $events = new DiscordEvents($config, $db);
            if (!$events->isAdmin($current)) {
                Http::json(['error' => 'forbidden', 'message' => 'Brak uprawnień administratora wydarzeń.'], 403);
            }
            $auth->requireSameOrigin();
            $body = Http::readJsonBody(65536);
            $category = (string) ($body['category'] ?? '');
            $updated = $events->adminSetCategory($matches[1], $category);
            if (!$updated) {
                Http::json(['error' => 'not_found', 'message' => 'Nie ma takiego wydarzenia.'], 404);
            }
            Http::json(['ok' => true, 'id' => $matches[1], 'category' => $category]);
        }

        if (str_starts_with($path, '/api/')) {
            Http::json(['error' => 'not_found', 'message' => 'Nie ma takiego endpointu.'], 404);
        }
    } catch (HttpException $err) {
        Http::json(['error' => $err->errorCode, 'message' => $err->getMessage(), ...$err->extra], $err->status);
    } catch (\Throwable $err) {
        error_log('[api] ' . $err->getMessage());
        Http::json([
            'error' => 'server_error',
            'message' => $config->isProd ? 'Błąd serwera.' : $err->getMessage(),
        ], 500);
    }
}
