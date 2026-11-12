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

    public function migrate(string $schemaFile): void
    {
        $schema = file_get_contents($schemaFile);
        if ($schema === false) {
            throw new \RuntimeException('Nie można odczytać schematu bazy.');
        }

        $lines = preg_split('/\R/', $schema) ?: [];
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

        $this->execute(
            'INSERT INTO schema_migrations (version) VALUES (?) ON DUPLICATE KEY UPDATE version = VALUES(version)',
            ['003_php']
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
