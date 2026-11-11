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
            $c = self::config();
            self::$pdo = new PDO(
                sprintf('pgsql:host=%s;port=%d;dbname=%s', $c['host'], $c['port'], $c['db']),
                $c['user'],
                $c['pass'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        }
        return self::$pdo;
    }

    /**
     * Connection settings from DATABASE_URL, or from Railway's PGHOST / PGPORT / PGUSER / PGPASSWORD / PGDATABASE.
     */
    private static function config(): array
    {
        $url = App::env('DATABASE_URL');
        if ($url !== null) {
            $parts = parse_url($url);
            if (is_array($parts) && !empty($parts['host'])) {
                return [
                    'host' => $parts['host'],
                    'port' => (int) ($parts['port'] ?? 5432),
                    'db' => ltrim($parts['path'] ?? '/railway', '/'),
                    'user' => isset($parts['user']) ? urldecode($parts['user']) : null,
                    'pass' => isset($parts['pass']) ? urldecode($parts['pass']) : null,
                ];
            }
            App::log('DATABASE_URL has no host (value starts with "' . substr($url, 0, 12) . '"). Is the ${{...}} reference correct?');
        }
        if (App::env('PGHOST') !== null) {
            return [
                'host' => App::env('PGHOST'),
                'port' => (int) App::env('PGPORT', '5432'),
                'db' => App::env('PGDATABASE', 'railway'),
                'user' => App::env('PGUSER'),
                'pass' => App::env('PGPASSWORD'),
            ];
        }
        throw new RuntimeException('No database settings: set DATABASE_URL to ${{Postgres.DATABASE_URL}} (use your Postgres service name)');
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
