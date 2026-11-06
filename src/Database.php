<?php
declare(strict_types=1);

final class Database
{
    public static function connect(array $config): PDO
    {
        if ($config['db_user'] === '') {
            // Fail closed: do not silently fall back to root with an empty password.
            throw new RuntimeException('Database user is not configured (set LAB_DB_USER).');
        }

        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_name']);

        return new PDO($dsn, $config['db_user'], $config['db_pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,  // real server-side prepared statements (issue 1)
            PDO::MYSQL_ATTR_FOUND_ROWS   => true,   // rowCount() = rows matched, so "no change" != "not found"
        ]);
    }
}
