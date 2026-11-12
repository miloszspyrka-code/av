<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Http;

use AlphaVault\Auth;
use AlphaVault\Config;
use AlphaVault\Db;
use AlphaVault\Discord;
use AlphaVault\Http;
use AlphaVault\HttpException;
use AlphaVault\Mcp\Keys;
use AlphaVault\Mcp\OAuth\Entities\UserEntity;
use AlphaVault\Mcp\OAuth\OAuthServer;
use AlphaVault\UserService;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use League\OAuth2\Server\CryptTrait;
use League\OAuth2\Server\Exception\OAuthServerException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class OAuthController
{
    private Psr17Factory $psr17;
    private OAuthServer $oauth;

    public function __construct(
        private readonly Config $config,
        private readonly Db $db,
        private readonly Auth $auth,
        private readonly Discord $discord,
    ) {
        $this->psr17 = new Psr17Factory();
        $this->oauth = new OAuthServer($config, $db);
    }

    public function metadata(): never
    {
        Http::json([
            'resource' => (string) $this->config->mcp['resource'],
            'authorization_servers' => [(string) $this->config->mcp['issuer']],
            'scopes_supported' => $this->oauth->allowedScopes(),
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'AlphaVault',
            'resource_documentation' => 'https://github.com/miloszspyrka-code/av/blob/main/docs/MCP.md',
        ]);
    }

    public function authorizationServerMetadata(): never
    {
        $issuer = (string) $this->config->mcp['issuer'];
        Http::json([
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer . '/oauth/authorize',
            'token_endpoint' => $issuer . '/oauth/token',
            'registration_endpoint' => $issuer . '/oauth/register',
            'revocation_endpoint' => $issuer . '/oauth/revoke',
            'jwks_uri' => $issuer . '/oauth/jwks.json',
            'scopes_supported' => $this->oauth->allowedScopes(),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post'],
            'authorization_response_iss_parameter_supported' => true,
            'service_documentation' => 'https://github.com/miloszspyrka-code/av/blob/main/docs/MCP.md',
        ]);
    }

    public function jwks(): never
    {
        Http::json(Keys::jwks($this->config));
    }

    public function register(): never
    {
        try {
            $this->auth->rateLimit('mcp-register', 10, 3600000);
        } catch (HttpException $err) {
            Http::json(['error' => $err->errorCode, 'message' => $err->getMessage()], $err->status);
        }

        try {
            $body = Http::readJsonBody(16384);
        } catch (HttpException $err) {
            Http::json(['error' => $err->errorCode, 'message' => $err->getMessage()], $err->status);
        }

        $name = trim((string) ($body['client_name'] ?? 'Klient MCP'));
        if ($name === '' || mb_strlen($name) > 200) {
            Http::json(['error' => 'invalid_client_metadata', 'message' => 'client_name musi mieć 1–200 znaków.'], 400);
        }

        $redirectUris = $body['redirect_uris'] ?? null;
        if (!is_array($redirectUris) || $redirectUris === [] || count($redirectUris) > 5) {
            Http::json(['error' => 'invalid_redirect_uri', 'message' => 'redirect_uris musi zawierać 1–5 adresów.'], 400);
        }

        $cleanUris = [];
        foreach ($redirectUris as $uri) {
            if (!is_string($uri) || !$this->isAllowedRedirectUri($uri)) {
                Http::json(['error' => 'invalid_redirect_uri', 'message' => 'Niedozwolony redirect_uri: ' . (is_string($uri) ? $uri : 'nieprawidłowa wartość')], 400);
            }
            $cleanUris[] = $uri;
        }

        $authMethod = (string) ($body['token_endpoint_auth_method'] ?? 'none');
        if (!in_array($authMethod, ['none', 'client_secret_post'], true)) {
            Http::json(['error' => 'invalid_client_metadata', 'message' => 'Obsługiwane token_endpoint_auth_method: none, client_secret_post.'], 400);
        }

        $registered = $this->oauth->clients->register($name, array_values(array_unique($cleanUris)), $authMethod === 'client_secret_post');
        $payload = [
            'client_id' => $registered['client_id'],
            'client_name' => $registered['client_name'],
            'redirect_uris' => $registered['redirect_uris'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $registered['is_confidential'] ? 'client_secret_post' : 'none',
            'client_id_issued_at' => time(),
        ];
        if ($registered['client_secret'] !== null) {
            $payload['client_secret'] = $registered['client_secret'];
            $payload['client_secret_expires_at'] = 0;
        }

        Http::json($payload, 201);
    }

    public function authorize(): never
    {
        if (Http::method() === 'GET') {
            $request = $this->request();
            try {
                $authRequest = $this->oauth->server->validateAuthorizationRequest($request);
            } catch (OAuthServerException $err) {
                $this->emitOAuthException($err);
            }

            $resource = (string) ($request->getQueryParams()['resource'] ?? $this->config->mcp['resource']);
            if (!$this->isAllowedResource($resource)) {
                $this->denyAuthorization($authRequest->getRedirectUri(), $authRequest->getState(), 'invalid_target', 'Nieprawidłowy resource.');
            }
            if (($authRequest->getCodeChallengeMethod() ?? '') !== 'S256' || $authRequest->getCodeChallenge() === null) {
                $this->denyAuthorization($authRequest->getRedirectUri(), $authRequest->getState(), 'invalid_request', 'Wymagane jest PKCE S256.');
            }

            $scopes = array_map(static fn ($scope): string => $scope->getIdentifier(), $authRequest->getScopes());
            $session = $this->auth->readSession();
            if ($session === null) {
                $this->beginLogin('/oauth/authorize?' . http_build_query($request->getQueryParams()));
            }

            $consentId = $this->auth->randomToken(24);
            $csrf = $this->auth->randomToken(24);
            $this->db->execute('DELETE FROM mcp_consents WHERE expires_at < UTC_TIMESTAMP()');
            $this->db->execute(
                'INSERT INTO mcp_consents (id, user_id, client_id, redirect_uri, scopes, state, code_challenge, code_challenge_method, resource, csrf, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP() + INTERVAL 10 MINUTE)',
                [
                    $consentId,
                    (int) $session['user']['id'],
                    $authRequest->getClient()->getIdentifier(),
                    (string) $authRequest->getRedirectUri(),
                    implode(' ', $scopes),
                    $authRequest->getState(),
                    (string) $authRequest->getCodeChallenge(),
                    'S256',
                    $resource,
                    $csrf,
                ]
            );

            $this->renderConsent($consentId, $csrf, $authRequest->getClient()->getName(), $scopes, $session['user']);
        }

        $this->auth->requireSameOrigin();
        $session = $this->auth->readSession();
        if ($session === null) {
            Http::html('<h1>401</h1><p>Sesja wygasła. <a href="/oauth/authorize">Spróbuj ponownie</a>.</p>', 401);
        }

        $consentId = (string) ($_POST['consent_id'] ?? '');
        $csrf = (string) ($_POST['csrf'] ?? '');
        $decision = (string) ($_POST['decision'] ?? 'deny');
        $row = $this->db->queryOne(
            'SELECT * FROM mcp_consents WHERE id = ? AND user_id = ? AND expires_at > UTC_TIMESTAMP() LIMIT 1',
            [$consentId, (int) $session['user']['id']]
        );
        if ($row === null || !hash_equals((string) $row['csrf'], $csrf)) {
            Http::html('<h1>400</h1><p>Nieprawidłowa lub wygasła zgoda. <a href="/oauth/authorize">Spróbuj ponownie</a>.</p>', 400);
        }

        $this->db->execute('DELETE FROM mcp_consents WHERE id = ?', [$consentId]);

        $query = [
            'response_type' => 'code',
            'client_id' => (string) $row['client_id'],
            'redirect_uri' => (string) $row['redirect_uri'],
            'scope' => (string) $row['scopes'],
            'code_challenge' => (string) $row['code_challenge'],
            'code_challenge_method' => (string) $row['code_challenge_method'],
            'resource' => (string) $row['resource'],
        ];
        if ($row['state'] !== null && $row['state'] !== '') {
            $query['state'] = (string) $row['state'];
        }

        $request = $this->request()->withQueryParams($query);
        try {
            $authRequest = $this->oauth->server->validateAuthorizationRequest($request);
            $authRequest->setUser(new UserEntity((string) $session['user']['id']));
            $authRequest->setAuthorizationApproved($decision === 'allow');
            $response = $this->oauth->server->completeAuthorizationRequest($authRequest, new Response());
        } catch (OAuthServerException $err) {
            $this->emitOAuthException($err);
        } catch (\Throwable $err) {
            Http::html('<h1>500</h1><p>Nie udało się zakończyć autoryzacji.</p>', 500);
        }

        $this->auth->audit((int) $session['user']['id'], 'mcp.oauth.authorize', [
            'clientId' => (string) $row['client_id'],
            'decision' => $decision === 'allow' ? 'allow' : 'deny',
            'scopes' => (string) $row['scopes'],
        ]);
        $this->emit($this->withIssuer($response));
    }

    public function token(): never
    {
        $request = $this->request();
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            parse_str((string) $request->getBody(), $body);
        }
        $resource = (string) ($body['resource'] ?? $this->config->mcp['resource']);
        if (!$this->isAllowedResource($resource)) {
            Http::json(['error' => 'invalid_target', 'message' => 'Nieprawidłowy resource.'], 400);
        }

        try {
            $response = $this->oauth->server->respondToAccessTokenRequest($request, new Response());
        } catch (OAuthServerException $err) {
            $this->emitOAuthException($err);
        }

        $clientId = (string) ($body['client_id'] ?? '');
        if ($clientId !== '') {
            $this->oauth->clients->touch($clientId);
        }

        $this->emit($response
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Pragma', 'no-cache'));
    }

    public function revoke(): never
    {
        $request = $this->request();
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            parse_str((string) $request->getBody(), $body);
        }
        $token = (string) ($body['token'] ?? '');
        $hint = (string) ($body['token_type_hint'] ?? '');
        if ($token !== '') {
            $this->revokeRawToken($token, $hint);
        }

        Http::json(['ok' => true]);
    }

    public function login(): never
    {
        $requestId = (string) ($_GET['req'] ?? '');
        $returnTo = (string) ($_GET['return'] ?? '');
        if ($requestId === '' && $returnTo !== '') {
            $requestId = $this->createLoginRequest($returnTo);
        }
        $row = $this->loginRequest($requestId);
        if ($row === null) {
            Http::html('<h1>400</h1><p>Nieprawidłowa sesja logowania. <a href="/">Wróć</a>.</p>', 400);
        }
        if ($this->auth->readSession() !== null) {
            $this->finishLoginRequest($row);
        }

        Http::redirect($this->discord->authorizeUrl((string) $row['discord_state'], true));
    }

    public function discordCallback(): never
    {
        $state = (string) ($_GET['state'] ?? '');
        $code = (string) ($_GET['code'] ?? '');
        if (isset($_GET['error'])) {
            Http::html('<h1>Logowanie anulowane</h1><p>Discord nie ukończył logowania. <a href="/">Wróć</a>.</p>', 400);
        }
        if ($state === '' || $code === '') {
            Http::html('<h1>400</h1><p>Brak kodu logowania. <a href="/">Wróć</a>.</p>', 400);
        }

        $row = $this->db->queryOne(
            'SELECT * FROM mcp_login_requests WHERE discord_state = ? AND expires_at > UTC_TIMESTAMP() LIMIT 1',
            [$state]
        );
        if ($row === null) {
            Http::html('<h1>400</h1><p>Sesja logowania wygasła. <a href="/">Wróć</a>.</p>', 400);
        }
        if (!hash_equals((string) ($_COOKIE['mcp_login'] ?? ''), (string) $row['id'])) {
            Http::html('<h1>400</h1><p>Nieprawidłowa sesja logowania. <a href="/">Wróć</a>.</p>', 400);
        }

        $token = $this->discord->exchangeCode($code);
        $accessToken = (string) ($token['body']['access_token'] ?? '');
        if ($token['status'] < 200 || $token['status'] >= 300 || $accessToken === '') {
            $this->auth->audit(null, 'mcp.login.failed', ['step' => 'token', 'status' => $token['status']]);
            Http::html('<h1>502</h1><p>Discord odrzucił logowanie.</p>', 502);
        }

        $me = $this->discord->fetchProfile($accessToken);
        if ($me['status'] < 200 || $me['status'] >= 300 || empty($me['body']['id'])) {
            Http::html('<h1>502</h1><p>Nie udało się pobrać profilu Discord.</p>', 502);
        }
        $profile = $me['body'];
        $profile['avatar_url'] = $this->discord->avatarUrl($profile);

        $role = $this->discord->checkRole($accessToken);
        if (!$role['ok']) {
            $this->auth->audit(null, 'mcp.login.denied', [
                'reason' => $role['reason'] ?? 'role_check_failed',
                'discordId' => (string) $profile['id'],
            ]);
            $reason = htmlspecialchars((string) ($role['reason'] ?? 'Brak wymaganej roli.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            Http::html('<!doctype html><html lang="pl"><head><meta charset="utf-8"><title>Brak dostępu — AlphaVault</title></head><body style="background:#080707;color:#f5f1eb;font-family:system-ui;padding:40px;line-height:1.6"><h1>Brak dostępu do AlphaVault</h1><p>' . $reason . '</p><p><a style="color:#f1d48e" href="https://alphavault.pl">Wróć do AlphaVault</a></p></body></html>', 403);
        }

        $dbUser = UserService::upsertDiscord($this->config, $this->db, $profile, (bool) $role['checked']);
        if (empty($dbUser['id'])) {
            Http::html('<h1>500</h1><p>Nie udało się utworzyć konta AlphaVault.</p>', 500);
        }

        $session = $this->auth->createSession((int) $dbUser['id'], true);
        $this->auth->setSessionCookie($session);
        $this->db->execute('UPDATE mcp_login_requests SET user_id = ? WHERE id = ?', [(int) $dbUser['id'], (string) $row['id']]);
        $this->auth->audit((int) $dbUser['id'], 'mcp.login.discord', ['roleVerified' => (bool) $role['checked']]);

        $this->finishLoginRequest($row);
    }

    public function connected(): never
    {
        $session = $this->auth->readSession();
        if ($session === null) {
            $this->beginLogin('/oauth/connected');
        }

        $clients = $this->oauth->clients->listForUser((int) $session['user']['id']);
        $csrf = hash_hmac('sha256', 'mcp-connected:' . (string) $session['sessionId'], $this->config->sessionSecret);
        $items = '';
        foreach ($clients as $client) {
            $name = htmlspecialchars((string) $client['client_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $active = (int) $client['active_tokens'];
            $last = htmlspecialchars((string) ($client['last_token_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $items .= '<li><strong>' . $name . '</strong><br><small>Aktywne tokeny: ' . $active . ' · Ostatnio: ' . $last . '</small>'
                . '<form method="post" action="/oauth/connected/revoke" style="margin-top:8px">'
                . '<input type="hidden" name="client_id" value="' . htmlspecialchars((string) $client['client_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
                . '<input type="hidden" name="csrf" value="' . htmlspecialchars($csrf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
                . '<button type="submit">Unieważnij dostęp</button></form></li>';
        }
        if ($items === '') {
            $items = '<li>Brak połączonych klientów MCP.</li>';
        }

        Http::html('<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Połączone aplikacje — AlphaVault</title><style>body{background:#080707;color:#f5f1eb;font-family:system-ui,Arial,sans-serif;max-width:760px;margin:0 auto;padding:40px 22px;line-height:1.6}h1{font-size:32px;letter-spacing:-1px}a,button{color:#f1d48e}button{background:#f5f1eb;color:#080707;border:0;border-radius:99px;padding:8px 14px;font-weight:700;cursor:pointer}li{margin:18px 0;padding:14px;background:#121110;border:1px solid rgba(255,255,255,.1);border-radius:14px;list-style:none}small{color:#9f978c}</style></head><body><h1>Połączone aplikacje MCP</h1><p>Te klienty mają dostęp do Twoich danych AlphaVault. Unieważnienie odbiera wszystkie aktywne tokeny danego klienta.</p><ul>' . $items . '</ul><p><a href="https://alphavault.pl">← Wróć do AlphaVault</a></p></body></html>');
    }

    public function connectedRevoke(): never
    {
        $this->auth->requireSameOrigin();
        $session = $this->auth->readSession();
        if ($session === null) {
            Http::html('<h1>401</h1><p>Sesja wygasła.</p>', 401);
        }
        $expected = hash_hmac('sha256', 'mcp-connected:' . (string) $session['sessionId'], $this->config->sessionSecret);
        if (!hash_equals($expected, (string) ($_POST['csrf'] ?? ''))) {
            Http::html('<h1>403</h1><p>Nieprawidłowy token CSRF.</p>', 403);
        }
        $clientId = (string) ($_POST['client_id'] ?? '');
        if ($clientId !== '') {
            $this->oauth->clients->revokeAllForUser((int) $session['user']['id'], $clientId);
            $this->auth->audit((int) $session['user']['id'], 'mcp.token.revoke', ['clientId' => $clientId]);
        }
        Http::redirect('/oauth/connected');
    }

    public function health(): never
    {
        try {
            $db = $this->db->health();
        } catch (\Throwable $err) {
            Http::json(['ok' => false, 'service' => 'alphavault-mcp', 'error' => 'db_unavailable'], 503);
        }
        Http::json([
            'ok' => true,
            'service' => 'alphavault-mcp',
            'version' => $this->config->version,
            'db' => $db,
            'now' => gmdate('Y-m-d\TH:i:s\Z'),
            'mcp' => [
                'enabled' => (bool) $this->config->mcp['enabled'],
                'writesEnabled' => (bool) $this->config->mcp['writesEnabled'],
                'issuer' => (string) $this->config->mcp['issuer'],
                'resource' => (string) $this->config->mcp['resource'],
                'tools' => 8,
            ],
        ]);
    }

    public function home(): never
    {
        $resource = htmlspecialchars((string) $this->config->mcp['resource'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        Http::html('<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AlphaVault MCP</title><style>body{background:#080707;color:#f5f1eb;font-family:system-ui,Arial,sans-serif;max-width:720px;margin:0 auto;padding:48px 22px;line-height:1.7}h1{font-size:38px;letter-spacing:-1.5px}a{color:#f1d48e}code{background:rgba(217,178,95,.12);padding:2px 6px;border-radius:5px;color:#f1d48e}</style></head><body><h1>AlphaVault MCP</h1><p>Prywatny serwer MCP dla użytkowników AlphaVault. Endpoint: <code>' . $resource . '</code></p><p><a href="' . $resource . '">Przejdź do endpointu MCP</a></p><p><a href="/oauth/connected">Połączone aplikacje</a> · <a href="https://alphavault.pl">AlphaVault</a></p></body></html>');
    }

    private function request(): ServerRequestInterface
    {
        $creator = new ServerRequestCreator($this->psr17, $this->psr17, $this->psr17, $this->psr17);

        return $creator->fromGlobals();
    }

    private function emit(ResponseInterface $response): never
    {
        (new SapiEmitter())->emit($response);
        exit;
    }

    private function emitOAuthException(OAuthServerException $err): never
    {
        $response = $err->generateHttpResponse(new Response());
        if ($err->getRedirectUri() !== null) {
            $response = $this->withIssuer($response);
        }
        $this->emit($response);
    }

    private function withIssuer(ResponseInterface $response): ResponseInterface
    {
        $location = $response->getHeaderLine('Location');
        if ($location === '') {
            return $response;
        }
        $query = (string) parse_url($location, PHP_URL_QUERY);
        if (preg_match('/(?:^|&)iss=/', $query) === 1) {
            return $response;
        }
        $sep = str_contains($location, '?') ? '&' : '?';

        return $response->withHeader('Location', $location . $sep . 'iss=' . rawurlencode((string) $this->config->mcp['issuer']));
    }

    private function denyAuthorization(?string $redirectUri, ?string $state, string $error, string $message): never
    {
        if ($redirectUri === null) {
            Http::html('<h1>400</h1><p>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>', 400);
        }
        $query = ['error' => $error, 'error_description' => $message, 'iss' => (string) $this->config->mcp['issuer']];
        if ($state !== null && $state !== '') {
            $query['state'] = $state;
        }
        $sep = str_contains($redirectUri, '?') ? '&' : '?';
        Http::redirect($redirectUri . $sep . http_build_query($query));
    }

    private function isAllowedResource(string $resource): bool
    {
        $allowed = [(string) $this->config->mcp['resource'], (string) $this->config->mcp['issuer']];

        return in_array(rtrim($resource, '/'), array_map(static fn (string $item): string => rtrim($item, '/'), $allowed), true);
    }

    private function isAllowedRedirectUri(string $uri): bool
    {
        $parts = parse_url($uri);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $loopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if ($scheme !== 'https' && !($scheme === 'http' && $loopback && !$this->config->isProd)) {
            return false;
        }
        if ($loopback && !$this->config->isProd) {
            return true;
        }

        return in_array($host, $this->config->mcp['allowedRedirectHosts'], true);
    }

    private function beginLogin(string $returnTo): never
    {
        $id = $this->createLoginRequest($returnTo);
        Http::redirect('/oauth/login?req=' . rawurlencode($id));
    }

    private function createLoginRequest(string $returnTo): string
    {
        if (!str_starts_with($returnTo, '/') || str_starts_with($returnTo, '//')) {
            throw new \InvalidArgumentException('Nieprawidłowy adres powrotu.');
        }
        $id = $this->auth->randomToken(24);
        $state = $this->auth->randomToken(24);
        $this->db->execute('DELETE FROM mcp_login_requests WHERE expires_at < UTC_TIMESTAMP()');
        $this->db->execute(
            'INSERT INTO mcp_login_requests (id, return_to, discord_state, expires_at) VALUES (?, ?, ?, UTC_TIMESTAMP() + INTERVAL 10 MINUTE)',
            [$id, $returnTo, $state]
        );
        setcookie('mcp_login', $id, [
            'expires' => time() + 600,
            'path' => '/',
            'secure' => $this->config->secureCookies,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        return $id;
    }

    private function loginRequest(string $id): ?array
    {
        if ($id === '' || !hash_equals((string) ($_COOKIE['mcp_login'] ?? ''), $id)) {
            return null;
        }

        return $this->db->queryOne(
            'SELECT * FROM mcp_login_requests WHERE id = ? AND expires_at > UTC_TIMESTAMP() LIMIT 1',
            [$id]
        );
    }

    private function finishLoginRequest(array $row): never
    {
        $returnTo = (string) $row['return_to'];
        if (!str_starts_with($returnTo, '/') || str_starts_with($returnTo, '//')) {
            Http::html('<h1>400</h1><p>Nieprawidłowy adres powrotu.</p>', 400);
        }
        $this->db->execute('DELETE FROM mcp_login_requests WHERE id = ?', [(string) $row['id']]);
        setcookie('mcp_login', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $this->config->secureCookies,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        Http::redirect($returnTo);
    }

    private function renderConsent(string $consentId, string $csrf, string $clientName, array $scopes, array $user): never
    {
        $name = htmlspecialchars($clientName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $userName = htmlspecialchars((string) ($user['globalName'] ?? $user['username'] ?? 'Użytkownik'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $scopeHtml = '';
        foreach ($scopes as $scope) {
            $label = match ($scope) {
                'alphavault.read' => 'Odczyt danych: profil, transakcje, statystyki, plan dnia, wydarzenia, playbook.',
                'alphavault.write' => 'Zapis danych: nowe transakcje, zamykanie pozycji, plan dnia, check-in.',
                default => $scope,
            };
            $scopeHtml .= '<li><code>' . htmlspecialchars($scope, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code> — ' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
        }
        $writeNotice = in_array('alphavault.write', $scopes, true)
            ? '<p style="border-left:3px solid #d9b25f;padding-left:12px"><strong>Uwaga:</strong> ten klient może wykonywać operacje zapisujące. Każdą operację zatwierdzasz w rozmowie z klientem AI.</p>'
            : '';

        Http::html('<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Zgoda MCP — AlphaVault</title><style>body{background:#080707;color:#f5f1eb;font-family:system-ui,Arial,sans-serif;max-width:680px;margin:0 auto;padding:40px 22px;line-height:1.65}h1{font-size:32px;letter-spacing:-1px}h2{font-size:18px;margin-top:28px}code{background:rgba(217,178,95,.12);padding:2px 6px;border-radius:5px;color:#f1d48e}ul{padding-left:20px}li{margin:8px 0}.card{background:#121110;border:1px solid rgba(255,255,255,.1);border-radius:18px;padding:22px;margin-top:20px}button{border:0;border-radius:99px;padding:11px 18px;font-weight:700;cursor:pointer}.allow{background:#f5f1eb;color:#080707}.deny{background:transparent;color:#f5f1eb;border:1px solid rgba(255,255,255,.25);margin-left:8px}small{color:#9f978c}</style></head><body><h1>Połącz AlphaVault z klientem AI</h1><p>Zalogowano jako <strong>' . $userName . '</strong>.</p><div class="card"><p><strong>' . $name . '</strong> prosi o dostęp do Twojego konta AlphaVault.</p><h2>Zakres dostępu</h2><ul>' . $scopeHtml . '</ul><h2>Co to oznacza</h2><p>Klient otrzyma dostęp do danych tradingowych zapisanych na Twoim koncie. Nie otrzyma hasła, tokenu Discord ani dostępu do bazy. Możesz w każdej chwili unieważnić dostęp na stronie <a style="color:#f1d48e" href="/oauth/connected">Połączone aplikacje</a>.</p>' . $writeNotice . '<form method="post" action="/oauth/authorize"><input type="hidden" name="consent_id" value="' . htmlspecialchars($consentId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"><input type="hidden" name="csrf" value="' . htmlspecialchars($csrf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"><button class="allow" type="submit" name="decision" value="allow">Zatwierdzam dostęp</button><button class="deny" type="submit" name="decision" value="deny">Odrzucam</button></form></div><p><small>AlphaVault nie przekazuje Twojego tokenu Discord klientowi AI. Token MCP jest osobny, ograniczony czasem i scope.</small></p></body></html>');
    }

    private function revokeRawToken(string $token, string $hint): void
    {
        if ($hint !== 'refresh_token' && substr_count($token, '.') === 2) {
            $parts = explode('.', $token);
            $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
            $jti = is_array($payload) ? (string) ($payload['jti'] ?? '') : '';
            if ($jti !== '') {
                $this->oauth->accessTokens->revokeAccessToken($jti);
            }
        }

        try {
            $decryptor = new class ($this->config) {
                use CryptTrait;

                public function __construct(Config $config)
                {
                    $this->setEncryptionKey(\AlphaVault\Mcp\Keys::encryptionKey($config));
                }

                public function decryptToken(string $token): string
                {
                    return $this->decrypt($token);
                }
            };
            $payload = json_decode($decryptor->decryptToken($token), true);
            $refreshId = is_array($payload) ? (string) ($payload['refresh_token_id'] ?? '') : '';
            if ($refreshId !== '') {
                $this->oauth->refreshTokens->revokeRefreshToken($refreshId);
            }
        } catch (\Throwable) {
            // Token mógł być access tokenem albo śmieciem; revocation endpoint
            // zawsze odpowiada 200, żeby nie ujawniać stanu tokenu.
        }
    }
}
