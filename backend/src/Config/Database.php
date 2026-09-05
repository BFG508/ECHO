<?php

declare(strict_types=1);

namespace Echo\Config;

use PDO;
use RuntimeException;

final class Database
{
    public static function connect(Config $config): PDO
    {
        self::prepareSqliteDirectory($config->dsn);

        $pdo = new PDO($config->dsn, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        if (str_starts_with($config->dsn, 'sqlite:')) {
            $pdo->exec('PRAGMA busy_timeout = 5000');
            self::initializeSchema($pdo);
        }

        return $pdo;
    }

    private static function prepareSqliteDirectory(string $dsn): void
    {
        if (!str_starts_with($dsn, 'sqlite:')) {
            return;
        }

        $path = substr($dsn, strlen('sqlite:'));
        if ($path === '' || $path === ':memory:') {
            return;
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the database directory.');
        }
    }

    private static function initializeSchema(PDO $pdo): void
    {
        $schemaPath = dirname(__DIR__, 3) . '/database/init_schema.sql';
        $schema = @file_get_contents($schemaPath);
        if ($schema === false) {
            throw new RuntimeException('Database schema is missing.');
        }

        $pdo->exec($schema);
    }
}
