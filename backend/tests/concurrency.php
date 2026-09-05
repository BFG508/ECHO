<?php

declare(strict_types=1);

use Echo\Config\Config;
use Echo\Config\Database;
use Echo\Models\EchoRepository;

require dirname(__DIR__) . '/src/bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDOUT, "SKIP: PDO SQLite is not installed; concurrency test was not run.\n");
    exit(0);
}
if (!function_exists('proc_open')) {
    fwrite(STDOUT, "SKIP: proc_open is unavailable; concurrency test was not run.\n");
    exit(0);
}

$database = tempnam(sys_get_temp_dir(), 'echo-race-');
if ($database === false) {
    fwrite(STDERR, "Unable to create race-test database.\n");
    exit(1);
}
@unlink($database);
$dsn = 'sqlite:' . $database;
$config = new Config(
    'test', $dsn, [], [], 'race-test-secret-at-least-32-bytes',
    1000, 1000, 60, 100000, false, 'off'
);
$pdo = Database::connect($config);
$repo = new EchoRepository($pdo);
$now = time();
$id = str_repeat('R', 22);
$token = random_bytes(32);
$tokenHash = rtrim(strtr(base64_encode(hash('sha256', $token, true)), '+/', '-_'), '=');
if (!$repo->create($id, 'ciphertext-value-long-enough', str_repeat('V', 16), $tokenHash, true, $now, $now + 300)) {
    fwrite(STDERR, "Unable to create race-test echo.\n");
    exit(1);
}
unset($repo, $pdo);

$workers = [];
$workerPath = __DIR__ . '/reveal-worker.php';
for ($i = 0; $i < 50; $i++) {
    $command = [PHP_BINARY, $workerPath, $dsn, $id, $tokenHash, (string) ($now + 1)];
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        fwrite(STDERR, "Unable to start concurrency worker {$i}.\n");
        exit(1);
    }
    $workers[] = [$process, $pipes];
}

$successes = 0;
$errors = [];
foreach ($workers as [$process, $pipes]) {
    $stdout = trim(stream_get_contents($pipes[1]));
    $stderr = trim(stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) {
        $errors[] = $stderr === '' ? "worker exited {$status}" : $stderr;
        continue;
    }
    if ($stdout === '1') {
        $successes++;
    } elseif ($stdout !== '0') {
        $errors[] = "unexpected worker output: {$stdout}";
    }
}

@unlink($database);
@unlink($database . '-wal');
@unlink($database . '-shm');

if ($errors !== []) {
    fwrite(STDERR, "Concurrency workers failed:\n" . implode("\n", array_slice($errors, 0, 5)) . "\n");
    exit(1);
}
if ($successes !== 1) {
    fwrite(STDERR, "FAIL: expected exactly 1 successful reveal, got {$successes}.\n");
    exit(1);
}

fwrite(STDOUT, "Concurrency test passed: exactly 1 of 50 simultaneous reveals succeeded.\n");
