<?php

declare(strict_types=1);

namespace Echo\Http;

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
    ) {
    }

    public static function fromGlobals(int $maxBodyBytes = 150000): self
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

        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        $scheme = ($https !== '' && $https !== 'off' && $https !== '0') ? 'https' : 'http';

        return new self(
            $method,
            $path,
            isset($_SERVER['HTTP_ORIGIN']) ? trim((string) $_SERVER['HTTP_ORIGIN']) : null,
            strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'))),
            $scheme,
            (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
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
}
