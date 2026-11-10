<?php
/**
 * Tiny PDO wrapper for PostgreSQL.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $url = App::env('DATABASE_URL');
            if ($url === null) {
                throw new RuntimeException('DATABASE_URL is not set');
            }
            $parts = parse_url($url);
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $parts['host'] ?? 'localhost',
                $parts['port'] ?? 5432,
                ltrim($parts['path'] ?? '/postgres', '/')
            );
            self::$pdo = new PDO(
                $dsn,
                isset($parts['user']) ? urldecode($parts['user']) : null,
                isset($parts['pass']) ? urldecode($parts['pass']) : null,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        }
        return self::$pdo;
    }

    /** Drop the connection (the worker calls this after a DB error). */
    public static function reset(): void
    {
        self::$pdo = null;
    }

    public static function q(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        foreach (array_values($params) as $i => $value) {
            $type = PDO::PARAM_STR;
            if (is_int($value)) {
                $type = PDO::PARAM_INT;
            } elseif (is_bool($value)) {
                $type = PDO::PARAM_BOOL;
            } elseif ($value === null) {
                $type = PDO::PARAM_NULL;
            }
            $stmt->bindValue($i + 1, $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::q($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::q($sql, $params)->fetchAll();
    }

    public static function val(string $sql, array $params = []): mixed
    {
        $value = self::q($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** Run every migrations/*.sql file that has not been applied yet. */
    public static function migrate(): void
    {
        $pdo = self::pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name TEXT PRIMARY KEY, applied_at TIMESTAMPTZ NOT NULL DEFAULT now())');
        $files = glob(App::root() . '/migrations/*.sql');
        sort($files);
        foreach ($files as $file) {
            $name = basename($file);
            if (self::val('SELECT 1 FROM schema_migrations WHERE name = ?', [$name])) {
                continue;
            }
            $pdo->beginTransaction();
            $pdo->exec((string) file_get_contents($file));
            self::q('INSERT INTO schema_migrations (name) VALUES (?)', [$name]);
            $pdo->commit();
            App::log("migrated $name");
        }
    }
}
