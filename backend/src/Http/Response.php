<?php

declare(strict_types=1);

namespace Echo\Http;

final class Response
{
    /** @param array<string, mixed> $payload */
    public static function json(int $status, array $payload, array $headers = []): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    public static function noContent(array $headers = []): never
    {
        http_response_code(204);
        header('Cache-Control: no-store, max-age=0');
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        exit;
    }

    /** @param array<string, string> $headers */
    public static function error(int $status, string $code, string $message, array $headers = []): never
    {
        self::json($status, [
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $headers);
    }
}
