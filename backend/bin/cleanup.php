<?php

declare(strict_types=1);

use Echo\Config\Config;
use Echo\Config\Database;
use Echo\Models\EchoRepository;
use Echo\Security\RateLimiter;

require dirname(__DIR__) . '/src/bootstrap.php';

try {
    $config = Config::fromEnvironment();
    $pdo = Database::connect($config);
    $now = time();

    $echoes = (new EchoRepository($pdo))->deleteExpired($now);
    $rateLimits = (new RateLimiter($pdo, $config->rateLimitSecret))->cleanupExpired($now - 86400);
    if (str_starts_with($config->dsn, 'sqlite:')) {
        $pdo->exec('PRAGMA wal_checkpoint(PASSIVE)');
    }

    fwrite(STDOUT, sprintf(
        "ECHO cleanup complete: %d expired echo(es), %d stale rate-limit bucket(s) removed.\n",
        $echoes,
        $rateLimits,
    ));
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "Cleanup failed: {$exception->getMessage()}\n");
    exit(1);
}
