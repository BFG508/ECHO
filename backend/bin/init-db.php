<?php

declare(strict_types=1);

use Echo\Config\Config;
use Echo\Config\Database;

require dirname(__DIR__) . '/src/bootstrap.php';

try {
    Database::connect(Config::fromEnvironment());
    fwrite(STDOUT, "ECHO database initialized successfully.\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "Database initialization failed: {$exception->getMessage()}\n");
    exit(1);
}
