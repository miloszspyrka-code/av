<?php
declare(strict_types=1);

namespace AlphaVault;

final class UserService
{
    /**
     * Idempotentne dopasowanie konta wyłącznie po Discord User ID.
     * Nazwa użytkownika i e-mail nigdy nie są kluczem dopasowania.
     */
    public static function upsertDiscord(Config $config, Db $db, array $profile, bool $roleVerified): array
    {
        $discordId = (string) ($profile['id'] ?? '');
        if ($discordId === '') {
            throw new \InvalidArgumentException('Profil Discord nie zawiera identyfikatora użytkownika.');
        }

        $existing = $db->queryOne('SELECT * FROM users WHERE discord_id = ? LIMIT 1', [$discordId]);
        if ($existing !== null) {
            $db->execute(
                'UPDATE users SET username = ?, global_name = ?, avatar_url = ?, email = ?, role_verified = ?, last_login_at = CURRENT_TIMESTAMP WHERE id = ?',
                [
                    (string) ($profile['username'] ?? $existing['username']),
                    $profile['global_name'] ?? $existing['global_name'],
                    $profile['avatar_url'] ?? $existing['avatar_url'],
                    $profile['email'] ?? $existing['email'],
                    $roleVerified ? 1 : (int) $existing['role_verified'],
                    (int) $existing['id'],
                ]
            );

            return $db->queryOne('SELECT * FROM users WHERE id = ?', [(int) $existing['id']]) ?? $existing;
        }

        $trialUntil = null;
        if ((int) $config->discord['trialDays'] > 0) {
            $trialUntil = gmdate('Y-m-d', time() + ((int) $config->discord['trialDays'] * 86400));
        }

        try {
            $db->execute(
                'INSERT INTO users (discord_id, username, global_name, avatar_url, email, is_demo, plan, access_until, role_verified, last_login_at)
                 VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, CURRENT_TIMESTAMP)',
                [
                    $discordId,
                    (string) ($profile['username'] ?? 'Trader'),
                    $profile['global_name'] ?? null,
                    $profile['avatar_url'] ?? null,
                    $profile['email'] ?? null,
                    $config->discord['defaultPlan'],
                    $trialUntil,
                    $roleVerified ? 1 : 0,
                ]
            );
        } catch (\PDOException $err) {
            // Wyścig dwóch pierwszych logowań: unikalny indeks discord_id rozstrzyga,
            // a my wracamy po istniejący rekord zamiast tworzyć duplikat.
            if ((string) $err->getCode() !== '23000') {
                throw $err;
            }
            $race = $db->queryOne('SELECT * FROM users WHERE discord_id = ? LIMIT 1', [$discordId]);
            if ($race === null) {
                throw $err;
            }

            return $race;
        }

        return $db->queryOne('SELECT * FROM users WHERE id = ?', [$db->lastInsertId()]) ?? [];
    }
}
