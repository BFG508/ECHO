<?php

declare(strict_types=1);

namespace Echo\Observability;

use JsonException;

final class Logger
{
    public function __construct(private readonly string $level)
    {
    }

    /** @param array<string, bool|int|float|string|null> $context */
    public function info(string $event, array $context = []): void
    {
        if ($this->level !== 'info') {
            return;
        }
        $this->write('info', $event, $context);
    }

    /** @param array<string, bool|int|float|string|null> $context */
    public function error(string $event, array $context = []): void
    {
        if ($this->level === 'off') {
            return;
        }
        $this->write('error', $event, $context);
    }

    /** @param array<string, bool|int|float|string|null> $context */
    private function write(string $level, string $event, array $context): void
    {
        $payload = ['ts' => gmdate('c'), 'level' => $level, 'event' => $event] + $context;
        try {
            error_log('[ECHO] ' . json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } catch (JsonException) {
            error_log(sprintf('[ECHO] {"level":"error","event":"logging_failure","source":"%s"}', $event));
        }
    }
}
