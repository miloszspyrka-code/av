<?php
declare(strict_types=1);

namespace AlphaVault;

use PDO;
use PDOException;

final class Db
{
    private PDO $pdo;

    public function __construct(array $config)
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'],
            (int) $config['port'],
            $config['database']
        );
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
        $this->pdo = new PDO($dsn, $config['user'], $config['password'], $options);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        $rows = $this->query($sql, $params);
        return $rows[0] ?? null;
    }

    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    public function withTransaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $err) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $err;
        }
    }

    /**
     * Aplikuje schemat bazowy, a następnie wszystkie nieaplikowane migracje.
     *
     * Migracje są plikami db/migrations/*.sql. Wersją jest nazwa pliku, więc
     * ponowne uruchomienie jest bezpieczne i nie wykonuje zmian drugi raz.
     *
     * @return list<string> wersje migracji zastosowane w tym wywołaniu
     */
    public function migrate(string $schemaFile, ?string $migrationsDir = null): array
    {
        $schema = file_get_contents($schemaFile);
        if ($schema === false) {
            throw new \RuntimeException('Nie można odczytać schematu bazy.');
        }
        $this->applySql($schema);

        $this->applyAdditiveMigrations();

        $this->execute(
            'INSERT INTO schema_migrations (version) VALUES (?) ON DUPLICATE KEY UPDATE version = VALUES(version)',
            ['003_php']
        );
        $this->execute(
            'INSERT INTO schema_migrations (version) VALUES (?) ON DUPLICATE KEY UPDATE version = VALUES(version)',
            ['004_role_recheck']
        );

        $applied = [];
        $dir = $migrationsDir ?? dirname($schemaFile) . '/migrations';
        if (!is_dir($dir)) {
            return $applied;
        }
        $files = glob($dir . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $version = basename($file);
            $done = $this->queryOne('SELECT version FROM schema_migrations WHERE version = ?', [$version]);
            if ($done !== null) {
                continue;
            }
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new \RuntimeException('Nie można odczytać migracji: ' . $version);
            }
            $this->applySql($sql);
            $this->execute(
                'INSERT INTO schema_migrations (version) VALUES (?) ON DUPLICATE KEY UPDATE version = VALUES(version)',
                [$version]
            );
            $applied[] = $version;
        }
        return $applied;
    }

    private function applySql(string $sql): void
    {
        // Tylko rzeczywiste końce linii. Bez trybu /u \R traktuje bajt 0x85
        // (część UTF-8 liter ą/ń) jako separator i rozcina komentarze SQL.
        $lines = preg_split('/\r\n|\n|\r/', $sql) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            if (str_starts_with(ltrim($line), '--')) {
                continue;
            }
            $clean[] = $line;
        }
        $statements = preg_split('/;\s*(?:\R|$)/', implode("\n", $clean)) ?: [];
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            $this->pdo->exec($statement);
        }
    }

    /**
     * Kolumny dodane po pierwszym wdrożeniu. CREATE TABLE IF NOT EXISTS nie
     * zmienia istniejących tabel, a MySQL 8 nie obsługuje ADD COLUMN IF NOT
     * EXISTS. Sprawdzamy information_schema i wykonujemy ALTER tylko raz.
     */
    private function applyAdditiveMigrations(): void
    {
        $this->ensureColumn(
            'wearable_oauth_states',
            'code_verifier',
            'VARCHAR(128) NULL AFTER `provider`'
        );
        $this->ensureColumn(
            'wearable_connections',
            'refresh_token_expires_at',
            'DATETIME NULL AFTER `token_expires_at`'
        );
        $this->ensureColumn('users', 'role_checked_at', 'DATETIME NULL');
        $this->ensureColumn('users', 'role_check_status', "VARCHAR(16) NOT NULL DEFAULT 'never'");
        $this->ensureColumn('users', 'role_check_error', 'VARCHAR(500) NULL');
        $this->ensureIndex('users', 'idx_users_role_check', 'role_checked_at');
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        $row = $this->queryOne(
            'SELECT COUNT(*) AS c
               FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        );
        if ((int) ($row['c'] ?? 0) > 0) {
            return;
        }
        $this->pdo->exec(
            'ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition
        );
    }

    private function ensureIndex(string $table, string $index, string $column): void
    {
        $row = $this->queryOne(
            'SELECT COUNT(*) AS c
               FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $index]
        );
        if ((int) ($row['c'] ?? 0) > 0) {
            return;
        }
        $this->pdo->exec(
            'ALTER TABLE `' . $table . '` ADD INDEX `' . $index . '` (`' . $column . '`)'
        );
    }

    public function health(): array
    {
        $start = microtime(true);
        $row = $this->queryOne('SELECT 1 AS ok, VERSION() AS version');
        return [
            'ok' => (int) ($row['ok'] ?? 0) === 1,
            'version' => (string) ($row['version'] ?? ''),
            'latencyMs' => (int) round((microtime(true) - $start) * 1000),
        ];
    }
}
