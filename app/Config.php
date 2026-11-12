<?php
declare(strict_types=1);

namespace AlphaVault;

final class Config
{
    public function __construct(
        public readonly string $root,
        public readonly string $appUrl,
        public readonly string $origin,
        public readonly bool $isProd,
        public readonly bool $secureCookies,
        public readonly string $sessionSecret,
        public readonly string $sessionCookie,
        public readonly string $oauthCookie,
        public readonly int $sessionDays,
        public readonly int $maxStateBytes,
        public readonly bool $allowDemoLogin,
        public readonly string $demoStateFile,
        public readonly array $db,
        public readonly array $discord,
        public readonly bool $discordConfigured,
        public readonly bool $roleCheckConfigured,
        public readonly string $version,
    ) {
    }

    public static function load(string $root): self
    {
        $configFile = getenv('ALPHA_VAULT_CONFIG') ?: $root . '/config.json';
        $file = [];
        if (is_file($configFile)) {
            $raw = file_get_contents($configFile);
            if ($raw === false) {
                throw new \RuntimeException('Nie można odczytać pliku konfiguracji.');
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('Plik konfiguracji nie jest poprawnym JSON.');
            }
            $file = $decoded;
        }

        $env = static function (string $key, ?string $default = null) use ($file): ?string {
            $value = getenv($key);
            if ($value !== false && $value !== '') {
                return $value;
            }
            if (array_key_exists($key, $file) && $file[$key] !== null && $file[$key] !== '') {
                return (string) $file[$key];
            }
            return $default;
        };

        $appUrl = rtrim((string) $env('APP_URL', 'http://127.0.0.1:4173'), '/');
        $parts = parse_url($appUrl);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \RuntimeException('APP_URL musi być poprawnym adresem URL.');
        }
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        $isProd = str_starts_with($appUrl, 'https://') || $env('APP_ENV') === 'production';
        $sessionSecret = (string) $env('SESSION_SECRET', '');
        if ($isProd && ($sessionSecret === '' || $sessionSecret === 'dev-only-change-me')) {
            throw new \RuntimeException('SESSION_SECRET musi być ustawiony w produkcji.');
        }

        $parseBool = static function (?string $value, bool $default): bool {
            if ($value === null || $value === '') {
                return $default;
            }
            return (bool) preg_match('/^(1|true|yes|on)$/i', $value);
        };
        $parseInt = static function (?string $value, int $default): int {
            if ($value === null || $value === '' || !is_numeric($value)) {
                return $default;
            }
            return (int) $value;
        };

        $db = [
            'host' => (string) $env('DB_HOST', '127.0.0.1'),
            'port' => $parseInt($env('DB_PORT'), 3306),
            'user' => (string) $env('DB_USER', 'alpha_vault'),
            'password' => (string) $env('DB_PASSWORD', ''),
            'database' => (string) $env('DB_NAME', 'alpha_vault'),
        ];

        $discord = [
            'clientId' => (string) $env('DISCORD_CLIENT_ID', ''),
            'clientSecret' => (string) $env('DISCORD_CLIENT_SECRET', ''),
            'redirectUri' => (string) $env('DISCORD_REDIRECT_URI', $appUrl . '/auth/discord/callback'),
            'guildId' => (string) $env('DISCORD_GUILD_ID', ''),
            'requiredRoleId' => (string) $env('DISCORD_REQUIRED_ROLE_ID', ''),
            'defaultPlan' => $env('DISCORD_DEFAULT_PLAN') === 'solo' ? 'solo' : 'full',
            'trialDays' => $parseInt($env('DISCORD_TRIAL_DAYS'), 0),
        ];

        $discordConfigured = $discord['clientId'] !== ''
            && $discord['clientSecret'] !== ''
            && $discord['redirectUri'] !== '';
        $roleCheckConfigured = $discord['guildId'] !== '' && $discord['requiredRoleId'] !== '';

        return new self(
            root: $root,
            appUrl: $appUrl,
            origin: $origin,
            isProd: $isProd,
            secureCookies: str_starts_with($appUrl, 'https://'),
            sessionSecret: $sessionSecret,
            sessionCookie: 'av_session',
            oauthCookie: 'av_oauth',
            sessionDays: 30,
            maxStateBytes: $parseInt($env('MAX_STATE_BYTES'), 12 * 1024 * 1024),
            allowDemoLogin: $parseBool($env('ALLOW_DEMO_LOGIN'), !$isProd),
            demoStateFile: $root . '/db/demo-state.json',
            db: $db,
            discord: $discord,
            discordConfigured: $discordConfigured,
            roleCheckConfigured: $roleCheckConfigured,
            version: '3.0.0',
        );
    }
}
