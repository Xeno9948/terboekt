<?php
declare(strict_types=1);

namespace Terboekt;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private PDO $pdo;

    public function __construct(private readonly Config $config)
    {
        $this->pdo = self::connect($config->databaseUrl);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $this->isMysql());
        if ($this->isSqlite()) {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
        }
        if ($this->isMysql()) {
            $this->pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            $this->pdo->exec("SET time_zone = '+00:00'");
        }
    }

    public static function connect(string $url): PDO
    {
        if ($url === 'sqlite::memory:' || str_starts_with($url, 'sqlite::memory:')) {
            return new PDO('sqlite::memory:');
        }
        if (str_starts_with($url, 'sqlite:')) {
            $path = substr($url, strlen('sqlite:'));
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
                throw new RuntimeException('Cannot create SQLite directory: ' . $dir);
            }
            return new PDO('sqlite:' . $path);
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'])) {
            throw new RuntimeException('Invalid DATABASE_URL');
        }
        if (!in_array($parts['scheme'], ['mysql', 'mysqli'], true)) {
            throw new RuntimeException('Unsupported database scheme: ' . $parts['scheme']);
        }
        $host = $parts['host'] ?? '127.0.0.1';
        $port = (int) ($parts['port'] ?? 3306);
        $name = ltrim($parts['path'] ?? '', '/');
        $user = isset($parts['user']) ? rawurldecode($parts['user']) : '';
        $pass = isset($parts['pass']) ? rawurldecode($parts['pass']) : '';
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        try {
            return new PDO($dsn, $user, $pass);
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function isSqlite(): bool
    {
        return $this->driver() === 'sqlite';
    }

    public function isMysql(): bool
    {
        return $this->driver() === 'mysql';
    }

    public function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        $own = !$this->pdo->inTransaction();
        if ($own) {
            if ($this->isSqlite()) {
                $this->pdo->exec('BEGIN IMMEDIATE');
            } else {
                $this->pdo->beginTransaction();
            }
        }
        try {
            $result = $fn();
            if ($own) {
                $this->pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Serialize booking/calendar writers. Call inside an open transaction.
     * SQLite already uses BEGIN IMMEDIATE; MySQL takes a row lock on the mutex.
     */
    public function lockScheduling(): void
    {
        if ($this->isSqlite()) {
            return;
        }
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('lockScheduling requires an open transaction');
        }
        $this->pdo->query('SELECT id FROM availability_locks WHERE id = 1 FOR UPDATE');
    }

    public function forUpdateSuffix(): string
    {
        return $this->isMysql() ? ' FOR UPDATE' : '';
    }

    public function isUniqueConstraintViolation(\Throwable $e): bool
    {
        if (!$e instanceof PDOException) {
            return false;
        }
        $code = (string) $e->getCode();
        if ($code === '23000' || $code === '23505') {
            return true;
        }
        $message = $e->getMessage();
        return str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry');
    }

    /** @param array<string, mixed> $params */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with((string) $key, ':') ? $key : ':' . $key);
            $type = PDO::PARAM_STR;
            if (is_int($value)) {
                $type = PDO::PARAM_INT;
            } elseif (is_bool($value)) {
                $type = PDO::PARAM_INT;
                $value = $value ? 1 : 0;
            } elseif ($value === null) {
                $type = PDO::PARAM_NULL;
            }
            $stmt->bindValue($name, $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    /** @param array<string, mixed> $params */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $params @return list<array<string, mixed>> */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }
}
