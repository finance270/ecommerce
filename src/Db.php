<?php
declare(strict_types=1);

/** Wrapper PDO tipis untuk MariaDB. */
final class Db
{
    private static ?PDO $pdo = null;

    public static function conn(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            Config::get('db_host'),
            Config::get('db_port'),
            Config::get('db_name')
        );

        self::$pdo = new PDO($dsn, (string) Config::get('db_user'), (string) Config::get('db_pass'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION', time_zone='+07:00'",
        ]);

        return self::$pdo;
    }

    /** Cek apakah koneksi + skema sudah siap. */
    public static function isInstalled(): bool
    {
        try {
            $st = self::conn()->query("SHOW TABLES LIKE 'orders'");
            return $st !== false && $st->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }

    public static function q(string $sql, array $params = []): PDOStatement
    {
        $st = self::conn()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::q($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::q($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function val(string $sql, array $params = [], mixed $default = null): mixed
    {
        $v = self::q($sql, $params)->fetchColumn();
        return $v === false ? $default : $v;
    }
}
