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
        return $this->config->discord['apiBase'] . '/api/oauth2/authorize?' . http_build_query($params);
    }

    public function exchangeCode(string $code): array
    {
        return $this->request('POST', $this->config->discord['apiBase'] . '/api/oauth2/token', [
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
        return $this->request('GET', $this->config->discord['apiBase'] . '/api/users/@me', [
            'Authorization: Bearer ' . $accessToken,
        ]);
    }

    public function fetchMember(string $accessToken): array
    {
        $guildId = rawurlencode($this->config->discord['guildId']);
        return $this->request('GET', $this->config->discord['apiBase'] . '/api/v10/users/@me/guilds/' . $guildId . '/member', [
            'Authorization: Bearer ' . $accessToken,
        ]);
    }

    public function checkRole(string $accessToken): array
    {
        if (!$this->config->roleCheckConfigured) {
            return [
                'checked' => false,
                'ok' => false,
                'reason' => 'Weryfikacja roli nie jest skonfigurowana. Dostęp został zablokowany.',
            ];
        }
        $member = $this->fetchMember($accessToken);
        if ($member['status'] === 404) {
            return [
                'checked' => true,
                'ok' => false,
                'reason' => 'Nie jesteś członkiem serwera Alpha Moves.',
            ];
        }
        if ($member['status'] < 200 || $member['status'] >= 300) {
            return [
                'checked' => true,
                'ok' => false,
                'reason' => 'Nie udało się sprawdzić roli na Discordzie.',
                'detail' => (string) ($member['body']['message'] ?? ''),
            ];
        }
        $roles = $member['body']['roles'] ?? [];
        if (!is_array($roles) || !in_array($this->config->discord['requiredRoleId'], $roles, true)) {
            return [
                'checked' => true,
                'ok' => false,
                'reason' => 'Brak aktywnej roli Alpha na serwerze Alpha Moves.',
            ];
        }
        return ['checked' => true, 'ok' => true];
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

    private function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Nie można zainicjować cURL.');
        }
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CUSTOMREQUEST => $method,
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
        ];
    }
}
