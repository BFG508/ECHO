<?php

declare(strict_types=1);

namespace Echo\Models;

final class EchoPayload
{
    public function __construct(
        public readonly string $ciphertext,
        public readonly string $iv,
        public readonly bool $burnAfterReading,
        public readonly int $createdAt,
        public readonly int $expiresAt,
    ) {}

    /** @return array<string, int|string|bool> */
    public function toArray(): array
    {
        return [
            'ciphertext' => $this->ciphertext,
            'iv' => $this->iv,
            'burnAfterReading' => $this->burnAfterReading,
            'createdAt' => $this->createdAt,
            'expiresAt' => $this->expiresAt,
        ];
    }
}
