<?php

declare(strict_types=1);

use Echo\Config\Config;
use Echo\Config\Database;
use Echo\Controllers\EchoController;
use Echo\Http\Request;
use Echo\Http\RequestException;
use Echo\Http\Response;
use Echo\Models\EchoRepository;
use Echo\Security\OriginPolicy;
use Echo\Security\RateLimiter;
use Echo\Security\Validator;

require dirname(__DIR__) . '/src/bootstrap.php';

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($path) && $path !== '' ? $path : '/';

$rawMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($path === '/' && $rawMethod === 'GET' && is_file(__DIR__ . '/index.html')) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
    header('Cache-Control: no-store, max-age=0');
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
    exit;
}

if (PHP_SAPI === 'cli-server' && !str_starts_with($path, '/api/')) {
    $candidate = __DIR__ . $path;
    if (is_file($candidate)) {
        return false;
    }
}

$config = Config::fromEnvironment();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');
if ($config->enableHsts && (($_SERVER['HTTPS'] ?? '') === 'on')) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

$corsHeaders = [];

try {
    $request = Request::fromGlobals();
    $originPolicy = new OriginPolicy($config);
    $corsHeaders = $originPolicy->enforce($request);

    if ($request->method === 'OPTIONS') {
        $headers = $corsHeaders + [
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type',
            'Access-Control-Max-Age' => '600',
        ];
        Response::noContent($headers);
    }

    if ($request->method === 'GET' && $request->path === '/api/health') {
        Response::json(200, ['status' => 'ok', 'service' => 'ECHO', 'version' => '1.0.0'], $corsHeaders);
    }

    if (!str_starts_with($request->path, '/api/')) {
        Response::error(404, 'not_found', 'Resource not found.');
    }

    $isCreate = $request->method === 'POST' && $request->path === '/api/echoes';
    $revealMatches = [];
    $isReveal = $request->method === 'POST'
        && preg_match('#^/api/echoes/([A-Za-z0-9_-]{1,64})/reveal$#', $request->path, $revealMatches) === 1;

    if (!$isCreate && !$isReveal) {
        Response::error(404, 'not_found', 'Resource not found.', $corsHeaders);
    }

    $pdo = Database::connect($config);
    $controller = new EchoController(
        $config,
        new EchoRepository($pdo),
        new RateLimiter($pdo),
        new Validator($config->maxCiphertextChars),
    );

    if ($isCreate) {
        $controller->create($request, $corsHeaders);
    }

    $controller->reveal($request, (string) $revealMatches[1], $corsHeaders);
} catch (RequestException $exception) {
    Response::error($exception->status, $exception->errorCode, $exception->getMessage(), $corsHeaders);
} catch (Throwable $exception) {
    error_log(sprintf('[ECHO] %s: %s', $exception::class, $exception->getMessage()));
    Response::error(500, 'internal_error', 'The server could not complete the request.', $corsHeaders);
}
