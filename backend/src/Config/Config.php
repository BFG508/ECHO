<?php

declare(strict_types=1);

namespace Echo\Config;

final class Config
{
    /** @var list<string> */
    public readonly array $allowedOrigins;

    public function __construct(
        public readonly string $environment,
        public readonly string $dsn,
        array $allowedOrigins,
        public readonly int $createLimit,
        public readonly int $revealLimit,
        public readonly int $rateWindowSeconds,
        public readonly int $maxCiphertextChars,
        public readonly bool $enableHsts,
    ) {
        $this->allowedOrigins = array_values(array_unique(array_filter(
            array_map(static fn (string $origin): string => rtrim(trim($origin), '/'), $allowedOrigins),
            static fn (string $origin): bool => $origin !== ''
        )));
    }

    public static function fromEnvironment(): self
    {
        $defaultDb = dirname(__DIR__, 2) . '/var/echo.sqlite';

        return new self(
            self::env('ECHO_APP_ENV', 'production'),
            self::env('ECHO_DSN', 'sqlite:' . $defaultDb),
            explode(',', self::env('ECHO_ALLOWED_ORIGINS', '')),
            self::boundedInt('ECHO_CREATE_LIMIT', 20, 1, 1000),
            self::boundedInt('ECHO_REVEAL_LIMIT', 60, 1, 5000),
            self::boundedInt('ECHO_RATE_WINDOW_SECONDS', 60, 10, 3600),
            self::boundedInt('ECHO_MAX_CIPHERTEXT_CHARS', 100000, 4096, 1000000),
            self::boolEnv('ECHO_ENABLE_HSTS', false),
        );
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);
        return $value === false || trim($value) === '' ? $default : trim($value);
    }

    private static function boundedInt(string $name, int $default, int $min, int $max): int
    {
        $raw = getenv($name);
        if ($raw === false || !preg_match('/^-?\d+$/', trim($raw))) {
            return $default;
        }

        $value = (int) $raw;
        return max($min, min($max, $value));
    }

    private static function boolEnv(string $name, bool $default): bool
    {
        $raw = getenv($name);
        if ($raw === false) {
            return $default;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
