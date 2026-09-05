<?php

declare(strict_types=1);

namespace Echo\Config;

final class Config
{
    /** @var list<string> */
    public readonly array $allowedOrigins;

    /** @var list<string> */
    public readonly array $trustedProxies;

    public function __construct(
        public readonly string $environment,
        public readonly string $dsn,
        array $allowedOrigins,
        array $trustedProxies,
        public readonly string $rateLimitSecret,
        public readonly int $createLimit,
        public readonly int $revealLimit,
        public readonly int $rateWindowSeconds,
        public readonly int $maxCiphertextChars,
        public readonly bool $enableHsts,
        public readonly string $logLevel,
    ) {
        $this->allowedOrigins = self::normalizeList($allowedOrigins, true);
        $this->trustedProxies = self::normalizeList($trustedProxies, false);
    }

    public static function fromEnvironment(): self
    {
        $defaultDb = dirname(__DIR__, 2) . '/var/echo.sqlite';
        $environment = self::env('ECHO_APP_ENV', 'production');
        $rateLimitSecret = self::env(
            'ECHO_RATE_LIMIT_SECRET',
            'echo-prototype-rate-limit-secret-change-me-before-public-deployment'
        );

        return new self(
            $environment,
            self::env('ECHO_DSN', 'sqlite:' . $defaultDb),
            explode(',', self::env('ECHO_ALLOWED_ORIGINS', '')),
            explode(',', self::env('ECHO_TRUSTED_PROXIES', '')),
            $rateLimitSecret,
            self::boundedInt('ECHO_CREATE_LIMIT', 20, 1, 1000),
            self::boundedInt('ECHO_REVEAL_LIMIT', 60, 1, 5000),
            self::boundedInt('ECHO_RATE_WINDOW_SECONDS', 60, 10, 3600),
            self::boundedInt('ECHO_MAX_CIPHERTEXT_CHARS', 100000, 4096, 1000000),
            self::boolEnv('ECHO_ENABLE_HSTS', false),
            self::logLevel(),
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

    /** @param array<int, string> $values @return list<string> */
    private static function normalizeList(array $values, bool $trimTrailingSlash): array
    {
        $normalized = array_map(
            static function (string $value) use ($trimTrailingSlash): string {
                $value = trim($value);
                return $trimTrailingSlash ? rtrim($value, '/') : $value;
            },
            $values
        );

        return array_values(array_unique(array_filter(
            $normalized,
            static fn (string $value): bool => $value !== ''
        )));
    }

    private static function logLevel(): string
    {
        $value = strtolower(self::env('ECHO_LOG_LEVEL', 'info'));
        return in_array($value, ['off', 'error', 'info'], true) ? $value : 'info';
    }
}
