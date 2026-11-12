<?php
declare(strict_types=1);

namespace AlphaVault;

final class Discord
{
    public function __construct(private readonly Config $config)
    {
    }

    public function authorizeUrl(string $state, bool $remember): string
    {
        $params = [
            'client_id' => $this->config->discord['clientId'],
            'redirect_uri' => $this->config->discord['redirectUri'],
            'response_type' => 'code',
            'scope' => 'identify guilds.members.read',
            'state' => $state,
            'prompt' => 'consent',
        ];
        return 'https://discord.com/api/oauth2/authorize?' . http_build_query($params);
    }

    public function exchangeCode(string $code): array
    {
        return $this->requestWithRetry('POST', 'https://discord.com/api/oauth2/token', [
            'Content-Type: application/x-www-form-urlencoded',
        ], http_build_query([
            'client_id' => $this->config->discord['clientId'],
            'client_secret' => $this->config->discord['clientSecret'],
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->config->discord['redirectUri'],
        ]));
    }

    public function fetchProfile(string $accessToken): array
    {
        return $this->requestWithRetry('GET', 'https://discord.com/api/users/@me', [
            'Authorization: Bearer ' . $accessToken,
        ]);
    }

    public function fetchMember(string $accessToken): array
    {
        $guildId = rawurlencode($this->config->discord['guildId']);
        return $this->requestWithRetry('GET', 'https://discord.com/api/v10/users/@me/guilds/' . $guildId . '/member', [
            'Authorization: Bearer ' . $accessToken,
        ]);
    }

    /**
     * Odczyt pojedynczego członka serwera przez bota. Endpoint nie wymaga
     * uprzywilejowanego intencji GUILD_MEMBERS (w przeciwieństwie do listy).
     */
    public function fetchMemberByBot(string $discordId): array
    {
        $guildId = rawurlencode($this->config->discord['guildId']);
        $userId = rawurlencode($discordId);
        return $this->requestWithRetry(
            'GET',
            $this->config->discord['apiBase'] . '/guilds/' . $guildId . '/members/' . $userId,
            [
                'Authorization: Bot ' . $this->config->discord['botToken'],
                'User-Agent: AlphaVault/' . $this->config->version . ' (+https://alphavault.pl)',
            ]
        );
    }

    public function checkRole(string $accessToken): array
    {
        if (!$this->config->roleCheckConfigured) {
            return [
                'checked' => false,
                'ok' => false,
                'status' => 'not_configured',
                'reason' => 'Weryfikacja roli nie jest skonfigurowana. Dostęp został zablokowany.',
            ];
        }
        return $this->interpretMember($this->fetchMember($accessToken));
    }

    public function checkRoleByBot(string $discordId): array
    {
        if (!$this->config->roleCheckConfigured) {
            return [
                'checked' => false,
                'ok' => false,
                'status' => 'not_configured',
                'reason' => 'Weryfikacja roli nie jest skonfigurowana. Dostęp został zablokowany.',
            ];
        }
        if ($this->config->discord['botToken'] === '') {
            return [
                'checked' => false,
                'ok' => false,
                'status' => 'unavailable',
                'reason' => 'Cykliczna weryfikacja roli wymaga DISCORD_BOT_TOKEN.',
            ];
        }
        return $this->interpretMember($this->fetchMemberByBot($discordId));
    }

    public function interpretMember(array $member): array
    {
        $status = (int) ($member['status'] ?? 0);
        if ($status === 404) {
            return [
                'checked' => true,
                'ok' => false,
                'status' => 'not_member',
                'reason' => 'Nie jesteś członkiem serwera Alpha Moves.',
            ];
        }
        if ($status === 429 || $status >= 500 || $status === 0) {
            return [
                'checked' => true,
                'ok' => false,
                'status' => 'unavailable',
                'reason' => 'Discord chwilowo nie odpowiada. Dostęp dla zweryfikowanych kont pozostaje aktywny.',
                'detail' => (string) ($member['body']['message'] ?? ''),
            ];
        }
        if ($status < 200 || $status >= 300) {
            return [
                'checked' => true,
                'ok' => false,
                'status' => 'unavailable',
                'reason' => 'Nie udało się sprawdzić roli na Discordzie.',
                'detail' => (string) ($member['body']['message'] ?? ''),
            ];
        }
        $roles = $member['body']['roles'] ?? [];
        if (!is_array($roles) || !in_array($this->config->discord['requiredRoleId'], $roles, true)) {
            return [
                'checked' => true,
                'ok' => false,
                'status' => 'missing',
                'reason' => 'Brak aktywnej roli Alpha na serwerze Alpha Moves.',
            ];
        }
        return ['checked' => true, 'ok' => true, 'status' => 'ok'];
    }

    public function avatarUrl(array $profile): string
    {
        if (!empty($profile['avatar'])) {
            return 'https://cdn.discordapp.com/avatars/' . rawurlencode((string) $profile['id'])
                . '/' . rawurlencode((string) $profile['avatar']) . '.png?size=128';
        }
        $index = ((int) ($profile['discriminator'] ?? 0)) % 5;
        return 'https://cdn.discordapp.com/embed/avatars/' . $index . '.png';
    }

    private function requestWithRetry(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $attempts = 3;
        $result = [];
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $result = $this->request($method, $url, $headers, $body);
            if ((int) ($result['status'] ?? 0) !== 429 || $attempt === $attempts) {
                return $result;
            }
            $retryAfter = (int) ($result['headers']['retry-after'] ?? 1);
            usleep(min(max($retryAfter, 1), 3) * 1_000_000);
        }
        return $result;
    }

    private function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Nie można zainicjować cURL.');
        }
        $responseHeaders = [];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $responseHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }
                return strlen($line);
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException('Błąd połączenia z Discordem: ' . $error);
        }
        $decoded = json_decode((string) $raw, true);
        return [
            'status' => $status,
            'body' => is_array($decoded) ? $decoded : ['raw' => (string) $raw],
            'raw' => (string) $raw,
            'headers' => $responseHeaders,
        ];
    }
}
