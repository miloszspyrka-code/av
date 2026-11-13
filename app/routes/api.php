<?php
declare(strict_types=1);

namespace AlphaVault;

use AlphaVault\Integrations\IntegrationException;
use AlphaVault\Integrations\Integrations;

function av_integration_sync_state(Config $config, Db $db): ?array
{
    if (!$config->integrationsConfigured) {
        return null;
    }
    try {
        $row = $db->queryOne('SELECT last_attempt_at, last_success_at, last_status, last_error, connections_count, imported_count FROM exchange_sync_state WHERE id = 1');
        if ($row === null) {
            return null;
        }
        return [
            'status' => (string) ($row['last_status'] ?? 'never'),
            'lastAttemptAt' => $row['last_attempt_at'] ?? null,
            'lastSuccessAt' => $row['last_success_at'] ?? null,
            'connections' => (int) ($row['connections_count'] ?? 0),
            'imported' => (int) ($row['imported_count'] ?? 0),
            'lastError' => $row['last_error'] ?? null,
        ];
    } catch (\Throwable) {
        return null;
    }
}

function av_handle_api(Config $config, Db $db, Auth $auth, ?array $user, string $method, string $path): void
{
    if (str_starts_with($path, '/api/mcp')) {
        require_once __DIR__ . '/mcp.php';
        av_handle_mcp_api($config, $db, $auth, $user, $method, $path);
        return;
    }

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
            $sync = null;
            try {
                $events = new DiscordEvents($config, $db);
                $syncState = $events->syncState();
                $sync = [
                    'status' => $syncState['status'],
                    'lastSuccessAt' => $syncState['lastSuccessAt'],
                    'eventsCount' => $syncState['eventsCount'],
                    'stale' => $syncState['stale'],
                ];
            } catch (\Throwable $err) {
                // Brak tabeli stanu nie może wywalić publicznego health checku.
                $sync = null;
            }
            $roleRecheck = null;
            try {
                $guard = new RoleGuard($config, $db, new Discord($config));
                $roleRecheck = $guard->state();
            } catch (\Throwable) {
                // Brak tabeli stanu nie może wywalić publicznego health checku.
                $roleRecheck = null;
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
                'roleRecheckConfigured' => $config->roleRecheckConfigured,
                'roleRecheck' => $roleRecheck,
                'eventsConfigured' => $config->eventsConfigured,
                'demoAllowed' => $config->allowDemoLogin,
                'sync' => $sync,
                'integrations' => [
                    'configured' => $config->integrationsConfigured,
                    'metaApiConfigured' => $config->metaApiToken !== '',
                    'sync' => av_integration_sync_state($config, $db),
                ],
            ]);
        }

        if ($path === '/api/me' && $method === 'GET') {
            $events = new DiscordEvents($config, $db);
            $base = [
                'authenticated' => $user !== null,
                'discordConfigured' => $config->discordConfigured,
                'roleCheckConfigured' => $config->roleCheckConfigured,
                'roleRecheckConfigured' => $config->roleRecheckConfigured,
                'roleRecheckSeconds' => (int) $config->discord['roleRecheckSeconds'],
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

        if ($path === '/api/quotes' && $method === 'GET') {
            $requested = [];
            foreach (explode(',', (string) ($_GET['symbols'] ?? '')) as $label) {
                $label = trim($label);
                if ($label !== '') {
                    $requested[] = $label;
                }
            }
            $quotes = new Quotes($config->quotesCacheFile, $config->quotesBase, $config->quotesTtl);
            Http::json($quotes->get($requested));
        }

        if ($path === '/api/state' && $method === 'GET') {
            $current = $auth->requireAuth($user);
            $row = $db->queryOne('SELECT state, revision, bytes, updated_at FROM user_states WHERE user_id = ?', [$current['id']]);
            if ($row === null && $current['isDemo'] && is_file($config->demoStateFile)) {
                $demo = file_get_contents($config->demoStateFile);
                if ($demo !== false) {
                    $demo = \AlphaVault\DemoState::shiftToToday($demo) ?? $demo;
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
            if ($state !== null) {
                $repaired = Encoding::repairValue($state);
                if ($repaired !== $state) {
                    $json = json_encode($repaired, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($json !== false) {
                        $affected = $db->execute(
                            'UPDATE user_states SET state = ?, bytes = ? WHERE user_id = ? AND revision = ?',
                            [$json, strlen($json), $current['id'], (int) $row['revision']]
                        );
                        if ($affected > 0) {
                            $auth->audit((int) $current['id'], 'state.repair_encoding', ['bytes' => strlen($json)]);
                            $row['bytes'] = strlen($json);
                            $state = $repaired;
                        } else {
                            $latest = $db->queryOne(
                                'SELECT state, revision, bytes, updated_at FROM user_states WHERE user_id = ?',
                                [$current['id']]
                            );
                            if ($latest !== null) {
                                $latestState = json_decode((string) $latest['state'], true);
                                if (is_array($latestState)) {
                                    $state = Encoding::repairValue($latestState);
                                    $row = $latest;
                                }
                            }
                        }
                    }
                }
            }
            Http::json([
                'state' => $state,
                'revision' => (int) $row['revision'],
                'bytes' => (int) $row['bytes'],
                'updatedAt' => $row['updated_at'],
                'userId' => (int) $current['id'],
            ]);
        }

        if ($path === '/api/state' && $method === 'PUT') {
            $current = $auth->requireAuth($user);
            $auth->requireSameOrigin();
            $auth->rateLimit('state-save', 180, 60000);
            $body = Http::readJsonBody($config->maxStateBytes);
            $ownerId = isset($body['ownerId']) && is_numeric($body['ownerId']) ? (int) $body['ownerId'] : null;
            if ($ownerId !== null && $ownerId !== (int) $current['id']) {
                Http::json([
                    'error' => 'session_changed',
                    'message' => 'Sesja w tej karcie należy do innego konta. Odśwież stronę.',
                    'userId' => (int) $current['id'],
                ], 409);
            }
            $state = $body['state'] ?? null;
            if (!is_array($state)) {
                Http::json(['error' => 'bad_state', 'message' => 'Pole state musi być obiektem JSON.'], 400);
            }
            $stateOwner = $state['user']['discordId'] ?? null;
            if ($stateOwner !== null && $stateOwner !== '' && is_numeric($stateOwner)
                && (int) $stateOwner !== (int) $current['id']) {
                Http::json([
                    'error' => 'session_changed',
                    'message' => 'Stan należy do innego konta. Odśwież stronę.',
                    'userId' => (int) $current['id'],
                ], 409);
            }
            $state = Encoding::repairValue($state);
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
                Http::json(['ok' => true, 'revision' => 1, 'bytes' => $bytes, 'updatedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'userId' => (int) $current['id']]);
            }
            $baseRevision = isset($body['baseRevision']) && is_numeric($body['baseRevision'])
                ? (int) $body['baseRevision']
                : null;
            if ($baseRevision !== null && $baseRevision !== (int) $existing['revision']) {
                Http::json([
                    'error' => 'revision_conflict',
                    'message' => 'Dane zmienione w innej karcie.',
                    'revision' => (int) $existing['revision'],
                    'userId' => (int) $current['id'],
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
                    'userId' => (int) $current['id'],
                ], 409);
            }
            $auth->audit((int) $current['id'], 'state.save', ['bytes' => $bytes, 'revision' => $nextRevision]);
            Http::json(['ok' => true, 'revision' => $nextRevision, 'bytes' => $bytes, 'updatedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'userId' => (int) $current['id']]);
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
            if (!empty($current['isDemo'])) {
                $auth->clearCookie($config->demoCookie);
            }
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

        if ($path === '/api/mt/ea' && $method === 'POST') {
            $auth->rateLimit('mt-ea', 60, 60000);
            $body = Http::readJsonBody(1024 * 1024);
            $bundle = Integrations::make($config, $db);
            if ($bundle === null) {
                Http::json(['error' => 'integrations_not_configured', 'message' => 'Integracje nie są skonfigurowane na serwerze.'], 503);
            }
            $code = trim((string) ($body['code'] ?? ''));
            $trades = is_array($body['trades'] ?? null) ? $body['trades'] : [];
            try {
                $result = $bundle['mt']->ingest($code, $trades);
                Http::json(['ok' => true, ...$result]);
            } catch (IntegrationException $err) {
                Http::json(['error' => $err->errorCode, 'message' => $err->getMessage()], $err->httpStatus);
            }
        }

        if ($path === '/api/connections' && $method === 'GET') {
            $current = $auth->requireAuth($user);
            $bundle = Integrations::make($config, $db);
            if ($bundle === null) {
                Http::json([
                    'configured' => false,
                    'metaApiConfigured' => $config->metaApiToken !== '',
                    'connections' => [],
                ]);
            }
            Http::json([
                'configured' => true,
                'metaApiConfigured' => $config->metaApiToken !== '',
                'connections' => $bundle['store']->listForUser((int) $current['id']),
            ]);
        }

        if ($path === '/api/connections' && $method === 'POST') {
            $current = $auth->requireAuth($user);
            $auth->requireSameOrigin();
            $auth->rateLimit('connections-connect', 12, 60000);
            $body = Http::readJsonBody(65536);
            $provider = strtolower(trim((string) ($body['provider'] ?? '')));
            $fields = is_array($body['fields'] ?? null) ? $body['fields'] : [];
            if ($provider === 'mt') {
                $provider = strtolower(trim((string) ($fields['platform'] ?? 'mt5')));
            }
            if (!in_array($provider, ['weex', 'okx', 'bingx', 'mt4', 'mt5'], true)) {
                Http::json(['error' => 'bad_provider', 'message' => 'Nieznana giełda lub broker.'], 422);
            }
            $bundle = Integrations::make($config, $db);
            if ($bundle === null) {
                Http::json(['error' => 'integrations_not_configured', 'message' => 'Integracje nie są skonfigurowane na serwerze.'], 503);
            }
            try {
                $adapterResult = match ($provider) {
                    'okx' => (new \AlphaVault\Integrations\OKXProvider(new \AlphaVault\Integrations\HttpClient(), $config->integrationBases))->connect($fields, (int) $current['id']),
                    'bingx' => (new \AlphaVault\Integrations\BingXProvider(new \AlphaVault\Integrations\HttpClient(), $config->integrationBases))->connect($fields, (int) $current['id']),
                    'weex' => (new \AlphaVault\Integrations\WEEXProvider(new \AlphaVault\Integrations\HttpClient(), $config->integrationBases))->connect($fields, (int) $current['id']),
                    default => (new \AlphaVault\Integrations\MetaApiProvider(new \AlphaVault\Integrations\HttpClient(), $config->integrationBases, $config->metaApiToken))->connect($fields, (int) $current['id']),
                };
                $saved = $bundle['store']->save(
                    (int) $current['id'],
                    $provider,
                    $adapterResult,
                    is_array($adapterResult['credentials'] ?? null) ? $adapterResult['credentials'] : []
                );
                Http::json(['ok' => true, 'connection' => $saved], 201);
            } catch (IntegrationException $err) {
                Http::json(['error' => $err->errorCode, 'message' => $err->getMessage()], $err->httpStatus);
            }
        }

        if ($path === '/api/connections/sync' && $method === 'POST') {
            $current = $auth->requireAuth($user);
            $auth->requireSameOrigin();
            $auth->rateLimit('connections-sync', 20, 60000);
            $bundle = Integrations::make($config, $db);
            if ($bundle === null) {
                Http::json(['error' => 'integrations_not_configured', 'message' => 'Integracje nie są skonfigurowane na serwerze.'], 503);
            }
            $body = Http::readJsonBody(8192);
            $only = isset($body['provider']) ? strtolower(trim((string) $body['provider'])) : null;
            if ($only === 'mt') {
                $only = null;
            }
            Http::json($bundle['sync']->syncUser((int) $current['id'], $only));
        }

        if ($path === '/api/connections/mt/pair' && $method === 'POST') {
            $current = $auth->requireAuth($user);
            $auth->requireSameOrigin();
            $auth->rateLimit('connections-pair', 10, 60000);
            $bundle = Integrations::make($config, $db);
            if ($bundle === null) {
                Http::json(['error' => 'integrations_not_configured', 'message' => 'Integracje nie są skonfigurowane na serwerze.'], 503);
            }
            $body = Http::readJsonBody(8192);
            $platform = strtolower(trim((string) ($body['platform'] ?? 'mt5')));
            $pair = $bundle['mt']->pair((int) $current['id'], $platform);
            Http::json(['ok' => true, 'code' => $pair['code'], 'connectionId' => $pair['id']], 201);
        }

        if (preg_match('#^/api/connections/(\d+)/sync$#', $path, $m) && $method === 'POST') {
            $current = $auth->requireAuth($user);
            $auth->requireSameOrigin();
            $auth->rateLimit('connection-sync', 30, 60000);
            $bundle = Integrations::make($config, $db);
            if ($bundle === null) {
                Http::json(['error' => 'integrations_not_configured', 'message' => 'Integracje nie są skonfigurowane na serwerze.'], 503);
            }
            try {
                Http::json(['ok' => true, 'result' => $bundle['sync']->syncUserConnection((int) $current['id'], (int) $m[1])]);
            } catch (IntegrationException $err) {
                Http::json(['error' => $err->errorCode, 'message' => $err->getMessage()], $err->httpStatus);
            }
        }

        if (preg_match('#^/api/connections/(\d+)$#', $path, $m) && $method === 'DELETE') {
            $current = $auth->requireAuth($user);
            $auth->requireSameOrigin();
            $auth->rateLimit('connections-delete', 20, 60000);
            $bundle = Integrations::make($config, $db);
            if ($bundle === null) {
                Http::json(['error' => 'integrations_not_configured', 'message' => 'Integracje nie są skonfigurowane na serwerze.'], 503);
            }
            $row = $bundle['store']->findForUser((int) $current['id'], (int) $m[1]);
            if ($row === null) {
                Http::json(['error' => 'not_found', 'message' => 'Nie znaleziono połączenia.'], 404);
            }
            $bundle['sync']->disconnect($row);
            $bundle['store']->delete((int) $current['id'], (int) $m[1]);
            Http::json(['ok' => true]);
        }

        if ($path === '/api/events' && $method === 'GET') {
            $auth->requireAuth($user);
            $events = new DiscordEvents($config, $db);
            Http::json($events->listForApi(false));
        }

        if ($path === '/api/macro-events' && $method === 'GET') {
            $auth->requireAuth($user);
            $macro = new MacroEvents($config, $db);
            Http::json($macro->listForApi());
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

        if ($path === '/api/wearables' && $method === 'GET') {
            $current = $auth->requireAuth($user);
            $wearables = new Wearables($config, $db, $auth);
            Http::json($wearables->listForUser((int) $current['id']));
        }

        if (preg_match('#^/api/wearables/(apple|oura)/sync$#', $path, $matches) && $method === 'POST') {
            $current = $auth->requireAuth($user);
            $auth->requireSameOrigin();
            $auth->rateLimit('wearables-sync', 20, 60000);
            $wearables = new Wearables($config, $db, $auth);
            $result = $wearables->sync((int) $current['id'], $matches[1]);
            Http::json(['ok' => true, ...$result]);
        }

        if (preg_match('#^/api/wearables/(apple|oura)$#', $path, $matches) && $method === 'DELETE') {
            $current = $auth->requireAuth($user);
            $auth->requireSameOrigin();
            $wearables = new Wearables($config, $db, $auth);
            $wearables->disconnect((int) $current['id'], $matches[1]);
            Http::json(['ok' => true, 'provider' => $matches[1]]);
        }

        if ($path === '/api/wearables/apple/pair' && $method === 'POST') {
            $current = $auth->requireAuth($user);
            $auth->requireSameOrigin();
            $auth->rateLimit('wearables-apple-pair', 10, 60000);
            $wearables = new Wearables($config, $db, $auth);
            Http::json(['ok' => true, ...$wearables->applePairCode((int) $current['id'])]);
        }

        if ($path === '/api/wearables/apple/ingest' && $method === 'POST') {
            // Ingest z iPhone nie ma sesji: autoryzacją jest kod parowania
            // (jednorazowo) albo token urządzenia (nagłówek Bearer).
            $auth->rateLimit('wearables-apple-ingest', 120, 60000);
            $body = Http::readJsonBody(262144);
            $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
            if ($authorization === '' && function_exists('getallheaders')) {
                $headers = getallheaders();
                $authorization = (string) ($headers['Authorization'] ?? $headers['authorization'] ?? '');
            }
            $bearer = null;
            if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $m) === 1) {
                $bearer = trim($m[1]);
            }
            $wearables = new Wearables($config, $db, $auth);
            Http::json($wearables->appleIngest($body, $bearer));
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
