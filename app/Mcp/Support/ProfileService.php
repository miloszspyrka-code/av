<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Support;

use AlphaVault\Auth;
use AlphaVault\Config;
use AlphaVault\Db;
use AlphaVault\DiscordEvents;

final class ProfileService
{
    private DiscordEvents $events;

    public function __construct(
        private readonly Config $config,
        private readonly Db $db,
        private readonly Auth $auth,
    ) {
        $this->events = new DiscordEvents($config, $db);
    }

    public function findUser(int $userId): ?array
    {
        $row = $this->db->queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]);
        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'discordId' => $row['discord_id'],
            'username' => (string) $row['username'],
            'globalName' => $row['global_name'],
            'avatarUrl' => $row['avatar_url'],
            'email' => $row['email'],
            'isDemo' => (bool) $row['is_demo'],
            'plan' => (string) $row['plan'],
            'accessUntil' => $row['access_until'],
            'roleVerified' => (bool) $row['role_verified'],
            'createdAt' => $row['created_at'],
            'lastLoginAt' => $row['last_login_at'],
        ];
    }

    public function profile(array $user): array
    {
        $subscription = $this->auth->subscription($user);
        $name = (string) ($user['globalName'] ?? $user['username'] ?? 'Trader');

        return [
            'id' => 'av_' . (int) $user['id'],
            'name' => $name,
            'nickname' => 'AlphaVault · ' . strtoupper((string) ($subscription['plan'] ?? 'full')),
            'plan' => $subscription['plan'] ?? 'full',
            'accessUntil' => $subscription['accessUntil'] ?? null,
            'expired' => (bool) ($subscription['expired'] ?? false),
            'roleVerified' => (bool) ($subscription['roleVerified'] ?? false),
            'isDemo' => (bool) ($user['isDemo'] ?? false),
            'isAdmin' => $this->events->isAdmin($user),
        ];
    }
}
