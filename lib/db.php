<?php
/**
 * SeederLinux Lite - Database Connection Library
 * PostgreSQL PDO Connection Handler
 */

declare(strict_types=1);

class Database {
    private static ?PDO $instance = null;
    private static array $config = [];

    /**
     * Load .env file from project root into $_ENV if present.
     */
    private static function loadEnvFile(): void {
        $candidates = [
            __DIR__ . '/../.env',
            __DIR__ . '/../../.env',
        ];
        foreach ($candidates as $path) {
            if (!is_file($path)) continue;
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') continue;
                if (!str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim($v);
                if (strlen($v) >= 2 && $v[0] === '"' && $v[-1] === '"') {
                    $v = substr($v, 1, -1);
                }
                $_ENV[$k] = $v;
                putenv("$k=$v");
            }
            break;
        }
    }

    /**
     * Load configuration from environment or defaults
     */
    private static function loadConfig(): void {
        self::loadEnvFile();
        self::$config = [
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'port' => $_ENV['DB_PORT'] ?? '5432',
            'dbname' => $_ENV['DB_NAME'] ?? 'seederlinux',
            'user' => $_ENV['DB_USER'] ?? 'seeder',
            'password' => $_ENV['DB_PASS'] ?? 'seeder123',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
            ]
        ];
    }

    /**
     * Get singleton database connection instance
     */
    public static function getInstance(): PDO {
        if (self::$instance === null) {
            self::loadConfig();

            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                self::$config['host'],
                self::$config['port'],
                self::$config['dbname']
            );

            try {
                self::$instance = new PDO(
                    $dsn,
                    self::$config['user'],
                    self::$config['password'],
                    self::$config['options']
                );
            } catch (PDOException $e) {
                error_log('Database connection failed: ' . $e->getMessage());
                throw new RuntimeException('Database connection failed');
            }
        }

        return self::$instance;
    }

    /**
     * Execute a prepared statement and return result
     */
    public static function query(string $sql, array $params = []): PDOStatement {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch single row
     */
    public static function fetchOne(string $sql, array $params = []): ?array {
        $result = self::query($sql, $params)->fetch();
        return $result ?: null;
    }

    /**
     * Fetch all rows
     */
    public static function fetchAll(string $sql, array $params = []): array {
        return self::query($sql, $params)->fetchAll();
    }

    /**
     * Execute INSERT/UPDATE/DELETE
     */
    public static function execute(string $sql, array $params = []): bool {
        return self::query($sql, $params)->rowCount() >= 0;
    }

    /**
     * Get last insert ID
     */
    public static function lastInsertId(): string {
        return self::getInstance()->lastInsertId();
    }

    /**
     * Begin transaction
     */
    public static function beginTransaction(): bool {
        return self::getInstance()->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public static function commit(): bool {
        return self::getInstance()->commit();
    }

    /**
     * Rollback transaction
     */
    public static function rollback(): bool {
        return self::getInstance()->rollBack();
    }

    /**
     * Quote value for safe SQL
     */
    public static function quote($value): string {
        return self::getInstance()->quote($value);
    }
}
