<?php

declare(strict_types=1);

use Echo\Config\Config;
use Echo\Config\Database;
use Echo\Controllers\EchoController;
use Echo\Http\Request;
use Echo\Http\RequestException;
use Echo\Http\Response;
use Echo\Models\EchoRepository;
use Echo\Observability\Logger;
use Echo\Security\OriginPolicy;
use Echo\Security\RateLimiter;
use Echo\Security\Validator;

require dirname(__DIR__) . '/src/bootstrap.php';

$config = Config::fromEnvironment();
$logger = new Logger($config->logLevel);

try {
    $request = Request::fromGlobals($config);

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    if ($config->enableHsts && $request->scheme === 'https') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    if ($request->path === '/' && $request->method === 'GET' && is_file(__DIR__ . '/index.html')) {
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
        header('Cache-Control: no-store, max-age=0');
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/index.html');
        exit;
    }

    if (PHP_SAPI === 'cli-server' && !str_starts_with($request->path, '/api/')) {
        $candidate = __DIR__ . $request->path;
        if (is_file($candidate)) {
            return false;
        }
    }

    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");

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
        Response::json(200, ['status' => 'ok', 'service' => 'ECHO', 'version' => '1.1.2'], $corsHeaders);
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
        new RateLimiter($pdo, $config->rateLimitSecret),
        new Validator($config->maxCiphertextChars),
        $logger,
    );

    if ($isCreate) {
        $controller->create($request, $corsHeaders);
    }

    $controller->reveal($request, (string) $revealMatches[1], $corsHeaders);
} catch (RequestException $exception) {
    $logger->info('request_rejected', ['status' => $exception->status, 'code' => $exception->errorCode]);
    Response::error($exception->status, $exception->errorCode, $exception->getMessage(), $corsHeaders ?? []);
} catch (Throwable $exception) {
    $logger->error('internal_error', ['exception' => $exception::class]);
    Response::error(500, 'internal_error', 'The server could not complete the request.', $corsHeaders ?? []);
}
