<?php

declare(strict_types=1);

namespace Echo\Security;

use Echo\Http\RequestException;

final class Validator
{
    public const EXPIRATIONS = [300, 3600, 86400, 604800];

    public function __construct(private readonly int $maxCiphertextChars)
    {
    }

    /**
     * @param array<string, mixed> $body
     * @return array{id:string,ciphertext:string,iv:string,accessTokenHash:string,burnAfterReading:bool,expiresInSeconds:int}
     */
    public function createEcho(array $body): array
    {
        $this->rejectUnexpectedKeys($body, [
            'id', 'ciphertext', 'iv', 'accessTokenHash', 'burnAfterReading', 'expiresInSeconds',
        ]);

        $id = $this->requiredString($body, 'id');
        $ciphertext = $this->requiredString($body, 'ciphertext');
        $iv = $this->requiredString($body, 'iv');
        $accessTokenHash = $this->requiredString($body, 'accessTokenHash');
        $burn = $body['burnAfterReading'] ?? null;
        $expires = $body['expiresInSeconds'] ?? null;

        if (!preg_match('/^[A-Za-z0-9_-]{20,32}$/', $id)) {
            $this->invalid('id');
        }
        if (strlen($ciphertext) < 24 || strlen($ciphertext) > $this->maxCiphertextChars
            || !preg_match('/^[A-Za-z0-9_-]+$/', $ciphertext)) {
            $this->invalid('ciphertext');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{16}$/', $iv)) {
            $this->invalid('iv');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $accessTokenHash)) {
            $this->invalid('accessTokenHash');
        }
        if (!is_bool($burn)) {
            $this->invalid('burnAfterReading');
        }
        if (!is_int($expires) || !in_array($expires, self::EXPIRATIONS, true)) {
            $this->invalid('expiresInSeconds');
        }

        return [
            'id' => $id,
            'ciphertext' => $ciphertext,
            'iv' => $iv,
            'accessTokenHash' => $accessTokenHash,
            'burnAfterReading' => $burn,
            'expiresInSeconds' => $expires,
        ];
    }

    /** @param array<string, mixed> $body */
    public function revealToken(array $body): string
    {
        $this->rejectUnexpectedKeys($body, ['accessToken']);
        $token = $this->requiredString($body, 'accessToken');
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            $this->invalid('accessToken');
        }
        return $token;
    }

    public function echoId(string $id): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{20,32}$/', $id)) {
            throw new RequestException(404, 'not_found', 'Echo not found or no longer available.');
        }
        return $id;
    }

    /** @param array<string, mixed> $body */
    private function requiredString(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value) || $value === '') {
            $this->invalid($key);
        }
        return $value;
    }

    /** @param array<string, mixed> $body @param list<string> $allowed */
    private function rejectUnexpectedKeys(array $body, array $allowed): void
    {
        foreach (array_keys($body) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new RequestException(400, 'invalid_request', 'Request contains an unexpected field.');
            }
        }
    }

    private function invalid(string $field): never
    {
        throw new RequestException(400, 'invalid_field', sprintf('Invalid field: %s.', $field));
    }
}
