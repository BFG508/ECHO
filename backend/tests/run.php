<?php

declare(strict_types=1);

use Echo\Config\Config;
use Echo\Config\Database;
use Echo\Http\Request;
use Echo\Http\RequestException;
use Echo\Models\EchoRepository;
use Echo\Security\OriginPolicy;
use Echo\Security\RateLimiter;
use Echo\Security\TrustedProxy;
use Echo\Security\Validator;

require dirname(__DIR__) . '/src/bootstrap.php';

$tests = 0;
$failures = 0;

function check(bool $condition, string $message): void
{
    global $tests, $failures;
    $tests++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function expectRequestException(callable $fn, int $status, string $message): void
{
    try {
        $fn();
        check(false, $message . ' (no exception)');
    } catch (RequestException $exception) {
        check($exception->status === $status, $message);
    }
}

function configForTests(string $dsn = 'sqlite::memory:'): Config
{
    return new Config(
        'test',
        $dsn,
        ['https://example.org'],
        ['127.0.0.1', '10.0.0.0/8', '2001:db8::/32'],
        'unit-test-rate-limit-secret-at-least-32-bytes',
        20,
        60,
        60,
        100000,
        false,
        'off',
    );
}

$validator = new Validator(100000);
$valid = [
    'id' => str_repeat('A', 22),
    'ciphertext' => str_repeat('B', 24),
    'iv' => str_repeat('C', 16),
    'accessTokenHash' => str_repeat('D', 43),
    'burnAfterReading' => true,
    'expiresInSeconds' => 3600,
];
check($validator->createEcho($valid)['expiresInSeconds'] === 3600, 'valid create payload is accepted');
expectRequestException(fn () => $validator->createEcho($valid + ['extra' => 1]), 400, 'unexpected create field is rejected');
$badExpiry = $valid;
$badExpiry['expiresInSeconds'] = 42;
expectRequestException(fn () => $validator->createEcho($badExpiry), 400, 'unsupported expiry is rejected');
$badBurn = $valid;
$badBurn['burnAfterReading'] = 1;
expectRequestException(fn () => $validator->createEcho($badBurn), 400, 'non-boolean burn flag is rejected');
expectRequestException(fn () => $validator->echoId('../etc/passwd'), 404, 'invalid echo id is hidden as not found');

$fuzzValues = [null, true, false, 0, 1, -1, 1.5, [], ['nested'], new stdClass()];
foreach ($fuzzValues as $index => $value) {
    $payload = $valid;
    $payload['expiresInSeconds'] = $value;
    expectRequestException(fn () => $validator->createEcho($payload), 400, "fuzz expiry value {$index} is rejected");
}
foreach (['', '../etc/passwd', '<script>', str_repeat('A', 1000), 'AA AA', '🔥'] as $index => $id) {
    expectRequestException(fn () => $validator->echoId($id), 404, "fuzz id {$index} is rejected");
}

$config = configForTests();
check($config->allowedOrigins === ['https://example.org'], 'allowed origin configuration is normalized');

$originPolicy = new OriginPolicy($config);
$sameOriginRequest = new Request('POST', '/api/echoes', 'https://echo.test', 'echo.test', 'https', '127.0.0.1', '{}', 'application/json');
check(($originPolicy->enforce($sameOriginRequest)['Access-Control-Allow-Origin'] ?? null) === 'https://echo.test', 'exact same origin is allowed');
$configuredOriginRequest = new Request('POST', '/api/echoes', 'https://example.org', 'echo.test', 'https', '127.0.0.1', '{}', 'application/json');
check(($originPolicy->enforce($configuredOriginRequest)['Access-Control-Allow-Origin'] ?? null) === 'https://example.org', 'configured extra origin is allowed');
$wrongPortRequest = new Request('POST', '/api/echoes', 'https://echo.test:444', 'echo.test', 'https', '127.0.0.1', '{}', 'application/json');
expectRequestException(fn () => $originPolicy->enforce($wrongPortRequest), 403, 'same host on a different port is rejected');

$trustedProxy = new TrustedProxy($config->trustedProxies);
check($trustedProxy->isTrusted('127.0.0.1'), 'exact trusted proxy IP matches');
check($trustedProxy->isTrusted('10.42.0.7'), 'IPv4 CIDR trusted proxy matches');
check(!$trustedProxy->isTrusted('11.42.0.7'), 'IPv4 outside trusted CIDR is rejected');
check($trustedProxy->isTrusted('2001:db8::1234'), 'IPv6 CIDR trusted proxy matches');
check(!$trustedProxy->isTrusted('2001:db9::1'), 'IPv6 outside trusted CIDR is rejected');

$originalServer = $_SERVER;
$_SERVER = [
    'REQUEST_METHOD' => 'GET',
    'REQUEST_URI' => '/api/health',
    'REMOTE_ADDR' => '127.0.0.1',
    'HTTP_HOST' => 'internal:8080',
    'HTTP_X_FORWARDED_FOR' => '198.51.100.250, 203.0.113.9',
    'HTTP_X_FORWARDED_PROTO' => 'https',
    'HTTP_X_FORWARDED_HOST' => 'echo.example.org',
];
$proxiedRequest = Request::fromGlobals($config);
check($proxiedRequest->clientIp === '203.0.113.9', 'trusted proxy forwards client IP');
check($proxiedRequest->scheme === 'https', 'trusted proxy forwards HTTPS scheme');
check($proxiedRequest->host === 'echo.example.org', 'trusted proxy forwards public host');
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
$untrustedRequest = Request::fromGlobals($config);
check($untrustedRequest->clientIp === '198.51.100.7', 'untrusted peer cannot spoof forwarded client IP');
check($untrustedRequest->scheme === 'http', 'untrusted peer cannot spoof forwarded scheme');
$_SERVER = $originalServer;

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $pdo = Database::connect($config);
    $repository = new EchoRepository($pdo);
    $now = 1_800_000_000;
    $token = random_bytes(32);
    $tokenHash = rtrim(strtr(base64_encode(hash('sha256', $token, true)), '+/', '-_'), '=');
    $wrongHash = rtrim(strtr(base64_encode(hash('sha256', random_bytes(32), true)), '+/', '-_'), '=');

    check($repository->create(str_repeat('E', 22), 'ciphertext-value-long-enough', str_repeat('F', 16), $tokenHash, true, $now, $now + 300), 'repository creates echo');
    check($repository->reveal(str_repeat('E', 22), $wrongHash, $now + 1) === null, 'wrong reveal token does not reveal echo');
    check($repository->reveal(str_repeat('E', 22), $tokenHash, $now + 2) !== null, 'correct reveal token reveals echo');
    check($repository->reveal(str_repeat('E', 22), $tokenHash, $now + 3) === null, 'burn-after-reveal echo is deleted atomically');

    check($repository->create(str_repeat('G', 22), 'ciphertext-value-long-enough', str_repeat('H', 16), $tokenHash, false, $now, $now + 300), 'repository creates persistent echo');
    check($repository->reveal(str_repeat('G', 22), $tokenHash, $now + 1) !== null, 'persistent echo first read works');
    check($repository->reveal(str_repeat('G', 22), $tokenHash, $now + 2) !== null, 'persistent echo can be reread');

    check($repository->create(str_repeat('I', 22), 'ciphertext-value-long-enough', str_repeat('J', 16), $tokenHash, false, $now, $now + 1), 'repository creates expiring echo');
    check($repository->deleteExpired($now + 2) >= 1, 'explicit cleanup removes expired echoes');

    $limiter = new RateLimiter($pdo, $config->rateLimitSecret);
    check($limiter->consume('test', '127.0.0.1', 2, 60, $now)->allowed, 'rate limiter allows first request');
    $storedHash = $pdo->query("SELECT identity_hash FROM rate_limits WHERE bucket = 'test'")->fetchColumn();
    check(is_string($storedHash) && $storedHash === hash_hmac('sha256', '127.0.0.1', $config->rateLimitSecret), 'rate limiter stores HMAC identity');
    check($storedHash !== hash('sha256', '127.0.0.1'), 'rate limiter does not store a plain SHA-256 of the IP');
    check($limiter->consume('test', '127.0.0.1', 2, 60, $now + 1)->allowed, 'rate limiter allows second request');
    $limited = $limiter->consume('test', '127.0.0.1', 2, 60, $now + 2);
    check(!$limited->allowed && $limited->retryAfterSeconds === 58, 'rate limiter blocks excess request with retry time');
} else {
    fwrite(STDOUT, "SKIP: PDO SQLite is not installed; repository integration tests were not run.\n");
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} of {$tests} checks failed.\n");
    exit(1);
}

fwrite(STDOUT, "All {$tests} executed checks passed.\n");
