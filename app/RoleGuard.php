<?php
declare(strict_types=1);

namespace AlphaVault;

/**
 * Cykliczna weryfikacja roli Alpha przez bota.
 *
 * Logowanie sprawdza rolę tokenem użytkownika (OAuth). Cron co 5 minut
 * sprawdza aktywne sesje botem i usuwa sesje osób bez roli. Chwilowy błąd
 * Discorda nie odbiera dostępu osobom już zweryfikowanym (fail open), ale
 * brak roli lub brak członkostwa zawsze kończy się odebraniem dostępu.
 */
final class RoleGuard
{
    public function __construct(
        private readonly Config $config,
        private readonly Db $db,
        private readonly Discord $discord,
    ) {
    }

    /**
     * Decyzja przy logowaniu. Zwraca allow=true również wtedy, gdy Discord
     * chwilowo nie odpowiada, ale konto było już wcześniej zweryfikowane.
     *
     * @param array<string,mixed>|null $existingUser
     * @param array<string,mixed> $role
     * @return array{allow:bool,grace:bool,status:string,http:int,reason:string}
     */
    public function loginDecision(?array $existingUser, array $role): array
    {
        $status = (string) ($role['status'] ?? (($role['ok'] ?? false) ? 'ok' : 'unavailable'));
        if ($status === 'ok' || ($role['ok'] ?? false)) {
            return ['allow' => true, 'grace' => false, 'status' => 'ok', 'http' => 200, 'reason' => ''];
        }
        if ($status === 'missing' || $status === 'not_member') {
            return [
                'allow' => false,
                'grace' => false,
                'status' => $status,
                'http' => 403,
                'reason' => (string) ($role['reason'] ?? 'Brak wymaganej roli Alpha.'),
            ];
        }
        $verified = $existingUser !== null && (int) ($existingUser['role_verified'] ?? 0) === 1;
        if ($status === 'unavailable' && $verified) {
            return [
                'allow' => true,
                'grace' => true,
                'status' => 'unavailable',
                'http' => 200,
                'reason' => (string) ($role['reason'] ?? 'Discord chwilowo nie odpowiada.'),
            ];
        }
        return [
            'allow' => false,
            'grace' => false,
            'status' => $status,
            'http' => 503,
            'reason' => $status === 'unavailable'
                ? 'Nie udało się teraz sprawdzić roli na Discordzie. Spróbuj ponownie za chwilę.'
                : (string) ($role['reason'] ?? 'Weryfikacja roli nie jest skonfigurowana.'),
        ];
    }

    public function markVerified(int $userId): void
    {
        $this->db->execute(
            "UPDATE users SET role_verified = 1, role_checked_at = UTC_TIMESTAMP(), role_check_status = 'ok', role_check_error = NULL WHERE id = ?",
            [$userId]
        );
    }

    public function markTransient(int $userId, string $error): void
    {
        $this->db->execute(
            "UPDATE users SET role_checked_at = UTC_TIMESTAMP(), role_check_status = 'error', role_check_error = ? WHERE id = ?",
            [substr($error, 0, 500), $userId]
        );
    }

    /**
     * Sprawdza role dla aktywnych sesji, których ostatnia weryfikacja jest
     * starsza niż próg. Uruchamiane z crona co 5 minut.
     *
     * @return array{skipped:bool,reason?:string,checked:int,revoked:int,errors:int,status:string}
     */
    public function reapDue(int $limit = 120): array
    {
        if (!$this->config->roleRecheckConfigured) {
            return ['skipped' => true, 'reason' => 'not_configured', 'checked' => 0, 'revoked' => 0, 'errors' => 0, 'status' => 'disabled'];
        }

        $lockToken = bin2hex(random_bytes(16));
        $locked = $this->db->execute(
            'UPDATE role_check_state
                SET lock_token = ?, locked_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 4 MINUTE), last_attempt_at = UTC_TIMESTAMP()
              WHERE id = 1
                AND (lock_token IS NULL OR locked_until IS NULL OR locked_until < UTC_TIMESTAMP())',
            [$lockToken]
        );
        if ($locked === 0) {
            return ['skipped' => true, 'reason' => 'locked', 'checked' => 0, 'revoked' => 0, 'errors' => 0, 'status' => 'skipped'];
        }

        $limit = max(1, min(500, $limit));
        $dueSeconds = max(60, (int) $this->config->discord['roleRecheckSeconds'] - 60);
        $checked = 0;
        $revoked = 0;
        $errors = 0;
        $firstError = null;
        $started = microtime(true);

        try {
            $rows = $this->db->query(
                "SELECT u.id, u.discord_id, u.role_verified
                   FROM users u
                  WHERE u.is_demo = 0
                    AND u.discord_id IS NOT NULL
                    AND u.discord_id <> ''
                    AND EXISTS (
                        SELECT 1 FROM sessions s
                         WHERE s.user_id = u.id AND s.expires_at > UTC_TIMESTAMP()
                    )
                    AND (u.role_checked_at IS NULL OR u.role_checked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$dueSeconds} SECOND))
                  ORDER BY u.role_checked_at IS NULL DESC, u.role_checked_at ASC
                  LIMIT {$limit}"
            );

            foreach ($rows as $row) {
                if (microtime(true) - $started > 45) {
                    break;
                }
                $userId = (int) $row['id'];
                $discordId = (string) $row['discord_id'];
                $role = $this->discord->checkRoleByBot($discordId);
                $checked++;

                if (($role['status'] ?? '') === 'ok') {
                    $this->markVerified($userId);
                } elseif (in_array(($role['status'] ?? ''), ['missing', 'not_member'], true)) {
                    $this->revoke($userId, $discordId, (string) ($role['reason'] ?? 'Brak wymaganej roli.'));
                    $revoked++;
                } else {
                    $message = (string) ($role['reason'] ?? 'Nieznany błąd weryfikacji roli.');
                    $detail = (string) ($role['detail'] ?? '');
                    $this->markTransient($userId, $detail !== '' ? $message . ' ' . $detail : $message);
                    $errors++;
                    $firstError ??= $message;
                }

                usleep(250_000);
            }

            $status = $errors > 0 ? ($checked > $errors ? 'partial' : 'error') : 'success';
            $this->db->execute(
                'UPDATE role_check_state
                    SET last_success_at = IF(? = 0, UTC_TIMESTAMP(), last_success_at),
                        last_status = ?, last_error = ?,
                        checked_count = ?, revoked_count = ?, error_count = ?,
                        lock_token = NULL, locked_until = NULL
                  WHERE id = 1',
                [$errors, $status, $firstError !== null ? substr($firstError, 0, 500) : null, $checked, $revoked, $errors]
            );

            return ['skipped' => false, 'checked' => $checked, 'revoked' => $revoked, 'errors' => $errors, 'status' => $status];
        } catch (\Throwable $err) {
            try {
                $this->db->execute(
                    "UPDATE role_check_state
                        SET last_status = 'error', last_error = ?, lock_token = NULL, locked_until = NULL
                      WHERE id = 1",
                    [substr($err->getMessage(), 0, 500)]
                );
            } catch (\Throwable) {
                // Diagnostyka nie może przykryć właściwego błędu.
            }
            throw $err;
        } finally {
            try {
                $this->db->execute(
                    'UPDATE role_check_state SET lock_token = NULL, locked_until = NULL WHERE id = 1 AND lock_token = ?',
                    [$lockToken]
                );
            } catch (\Throwable) {
                // Lock wygaśnie sam po 4 minutach.
            }
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    public function state(): ?array
    {
        try {
            $row = $this->db->queryOne(
                'SELECT last_attempt_at, last_success_at, last_status, last_error, checked_count, revoked_count, error_count
                   FROM role_check_state WHERE id = 1'
            );
            if ($row === null) {
                return null;
            }
            $lastSuccess = $row['last_success_at'] ?? null;
            $stale = $lastSuccess === null
                || (time() - strtotime((string) $lastSuccess . ' UTC')) > max(900, (int) $this->config->discord['roleRecheckSeconds'] * 3);
            return [
                'configured' => $this->config->roleRecheckConfigured,
                'intervalSeconds' => (int) $this->config->discord['roleRecheckSeconds'],
                'status' => (string) ($row['last_status'] ?? 'never'),
                'lastAttemptAt' => $row['last_attempt_at'] ?? null,
                'lastSuccessAt' => $lastSuccess,
                'lastError' => $row['last_error'] ?? null,
                'checked' => (int) ($row['checked_count'] ?? 0),
                'revoked' => (int) ($row['revoked_count'] ?? 0),
                'errors' => (int) ($row['error_count'] ?? 0),
                'stale' => $stale,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function revoke(int $userId, string $discordId, string $reason): void
    {
        $this->db->withTransaction(function (Db $db) use ($userId, $discordId, $reason): void {
            $db->execute(
                "UPDATE users SET role_verified = 0, role_checked_at = UTC_TIMESTAMP(), role_check_status = 'missing', role_check_error = NULL WHERE id = ?",
                [$userId]
            );
            $db->execute('DELETE FROM sessions WHERE user_id = ?', [$userId]);
            try {
                $db->execute(
                    'INSERT INTO audit_log (user_id, action, detail, ip, user_agent) VALUES (?, ?, ?, ?, ?)',
                    [
                        $userId,
                        'access.role_revoked',
                        json_encode(['discordId' => $discordId, 'reason' => $reason], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        null,
                        null,
                    ]
                );
            } catch (\Throwable $err) {
                error_log('[role] audit: ' . $err->getMessage());
            }
        });
    }
}
