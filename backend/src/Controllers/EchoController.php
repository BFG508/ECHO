<?php

declare(strict_types=1);

namespace Echo\Controllers;

use Echo\Config\Config;
use Echo\Http\Request;
use Echo\Http\RequestException;
use Echo\Http\Response;
use Echo\Models\EchoRepository;
use Echo\Observability\Logger;
use Echo\Security\RateLimiter;
use Echo\Security\Validator;

final class EchoController
{
    public function __construct(
        private readonly Config $config,
        private readonly EchoRepository $repository,
        private readonly RateLimiter $rateLimiter,
        private readonly Validator $validator,
        private readonly Logger $logger,
    ) {
    }

    /** @param array<string, string> $corsHeaders */
    public function create(Request $request, array $corsHeaders): never
    {
        $startedAt = hrtime(true);
        $this->enforceRateLimit('create', $request, $this->config->createLimit);
        $input = $this->validator->createEcho($request->json());

        $now = time();
        $created = $this->repository->create(
            $input['id'],
            $input['ciphertext'],
            $input['iv'],
            $input['accessTokenHash'],
            $input['burnAfterReading'],
            $now,
            $now + $input['expiresInSeconds'],
        );

        if (!$created) {
            throw new RequestException(409, 'id_conflict', 'Please retry with a newly generated echo.');
        }

        $this->logger->info('echo_created', [
            'burn_after_reveal' => $input['burnAfterReading'],
            'ttl_seconds' => $input['expiresInSeconds'],
            'duration_ms' => self::elapsedMilliseconds($startedAt),
        ]);

        Response::json(201, [
            'id' => $input['id'],
            'createdAt' => $now,
            'expiresAt' => $now + $input['expiresInSeconds'],
        ], $corsHeaders);
    }

    /** @param array<string, string> $corsHeaders */
    public function reveal(Request $request, string $id, array $corsHeaders): never
    {
        $startedAt = hrtime(true);
        $this->enforceRateLimit('reveal', $request, $this->config->revealLimit);
        $id = $this->validator->echoId($id);
        $accessToken = $this->validator->revealToken($request->json());
        $tokenBytes = self::base64UrlDecode($accessToken);
        if ($tokenBytes === null || strlen($tokenBytes) !== 32) {
            throw new RequestException(404, 'not_found', 'Echo not found or no longer available.');
        }

        $presentedHash = self::base64UrlEncode(hash('sha256', $tokenBytes, true));
        $payload = $this->repository->reveal($id, $presentedHash, time());

        if ($payload === null) {
            throw new RequestException(404, 'not_found', 'Echo not found or no longer available.');
        }

        $this->logger->info('echo_revealed', [
            'burn_after_reveal' => $payload->burnAfterReading,
            'duration_ms' => self::elapsedMilliseconds($startedAt),
        ]);

        Response::json(200, $payload->toArray(), $corsHeaders);
    }

    private function enforceRateLimit(string $bucket, Request $request, int $limit): void
    {
        $result = $this->rateLimiter->consume(
            $bucket,
            $request->clientIp,
            $limit,
            $this->config->rateWindowSeconds,
            time(),
        );

        if (!$result->allowed) {
            $this->logger->info('rate_limited', ['bucket' => $bucket]);
            header('Retry-After: ' . $result->retryAfterSeconds);
            throw new RequestException(429, 'rate_limited', 'Too many requests. Please try again shortly.');
        }
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $padding = (4 - (strlen($value) % 4)) % 4;
        $base64 = strtr($value, '-_', '+/') . str_repeat('=', $padding);
        $decoded = base64_decode($base64, true);
        return $decoded === false ? null : $decoded;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function elapsedMilliseconds(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 2);
    }
}
