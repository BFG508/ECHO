<?php

declare(strict_types=1);

namespace Echo\Http;

use Echo\Config\Config;
use Echo\Security\TrustedProxy;
use JsonException;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly ?string $origin,
        public readonly string $host,
        public readonly string $scheme,
        public readonly string $clientIp,
        private readonly string $rawBody,
        public readonly ?string $contentType,
    ) {}

    public static function fromGlobals(Config $config, int $maxBodyBytes = 150000): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';

        $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        if ($contentLength > $maxBodyBytes) {
            throw new RequestException(413, 'request_too_large', 'Request body is too large.');
        }

        $body = file_get_contents('php://input', false, null, 0, $maxBodyBytes + 1);
        if ($body === false) {
            throw new RequestException(400, 'invalid_request', 'Unable to read request body.');
        }
        if (strlen($body) > $maxBodyBytes) {
            throw new RequestException(413, 'request_too_large', 'Request body is too large.');
        }

        $remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        $scheme = ($https !== '' && $https !== 'off' && $https !== '0') ? 'https' : 'http';
        $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost')));
        $clientIp = $remoteAddress;

        $trustedProxy = new TrustedProxy($config->trustedProxies);
        if ($trustedProxy->isTrusted($remoteAddress)) {
            $forwardedProto = self::firstForwardedValue($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null);
            if ($forwardedProto !== null && in_array(strtolower($forwardedProto), ['http', 'https'], true)) {
                $scheme = strtolower($forwardedProto);
            }

            $forwardedHost = self::firstForwardedValue($_SERVER['HTTP_X_FORWARDED_HOST'] ?? null);
            if ($forwardedHost !== null && self::isValidHost($forwardedHost)) {
                $host = strtolower($forwardedHost);
            }

            $forwardedFor = self::lastForwardedValue($_SERVER['HTTP_X_FORWARDED_FOR'] ?? null);
            if ($forwardedFor !== null && filter_var($forwardedFor, FILTER_VALIDATE_IP) !== false) {
                $clientIp = $forwardedFor;
            }
        }

        return new self(
            $method,
            $path,
            isset($_SERVER['HTTP_ORIGIN']) ? trim((string) $_SERVER['HTTP_ORIGIN']) : null,
            $host,
            $scheme,
            $clientIp,
            $body,
            isset($_SERVER['CONTENT_TYPE']) ? strtolower(trim((string) $_SERVER['CONTENT_TYPE'])) : null,
        );
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if ($this->contentType === null || !str_starts_with($this->contentType, 'application/json')) {
            throw new RequestException(415, 'unsupported_media_type', 'Content-Type must be application/json.');
        }

        try {
            $decoded = json_decode($this->rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RequestException(400, 'invalid_json', 'Request body is not valid JSON.');
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RequestException(400, 'invalid_json', 'JSON body must be an object.');
        }

        return $decoded;
    }

    private static function firstForwardedValue(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $first = trim(explode(',', $value, 2)[0]);
        return $first === '' ? null : $first;
    }

    private static function lastForwardedValue(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $parts = array_map('trim', explode(',', $value));
        $last = end($parts);
        return is_string($last) && $last !== '' ? $last : null;
    }

    private static function isValidHost(string $host): bool
    {
        if ($host === '' || str_contains($host, '/') || str_contains($host, '\\') || str_contains($host, '@')) {
            return false;
        }

        $parts = parse_url('http://' . $host);
        if (!is_array($parts) || !isset($parts['host'])) {
            return false;
        }

        if (isset($parts['port']) && ((int) $parts['port'] < 1 || (int) $parts['port'] > 65535)) {
            return false;
        }

        return true;
    }
}
