<?php

declare(strict_types=1);

use Echo\Config\Config;
use Echo\Config\Database;
use Echo\Models\EchoRepository;

require dirname(__DIR__) . '/src/bootstrap.php';

if ($argc !== 5) {
    fwrite(STDERR, "usage: reveal-worker.php <dsn> <id> <tokenHash> <now>\n");
    exit(2);
}

$config = new Config(
    'test',
    $argv[1],
    [],
    [],
    'race-test-secret-at-least-32-bytes',
    1000,
    1000,
    60,
    100000,
    false,
    'off',
);
$payload = (new EchoRepository(Database::connect($config)))->reveal($argv[2], $argv[3], (int) $argv[4]);
fwrite(STDOUT, $payload === null ? "0\n" : "1\n");
