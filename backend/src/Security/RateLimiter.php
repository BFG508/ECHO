<?php

declare(strict_types=1);

namespace Echo\Security;

use PDO;
use Throwable;

final class RateLimiter
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function consume(string $bucket, string $identity, int $limit, int $windowSeconds, int $now): RateLimitResult
    {
        $identityHash = hash('sha256', $identity);
        $this->pdo->exec('BEGIN IMMEDIATE');

        try {
            $select = $this->pdo->prepare(
                'SELECT window_start, request_count FROM rate_limits '
                . 'WHERE bucket = :bucket AND identity_hash = :identity_hash'
            );
            $select->execute([':bucket' => $bucket, ':identity_hash' => $identityHash]);
            $row = $select->fetch();

            if (!is_array($row) || ((int) $row['window_start'] + $windowSeconds) <= $now) {
                $upsert = $this->pdo->prepare(
                    'INSERT INTO rate_limits (bucket, identity_hash, window_start, request_count) '
                    . 'VALUES (:bucket, :identity_hash, :window_start, 1) '
                    . 'ON CONFLICT(bucket, identity_hash) DO UPDATE SET '
                    . 'window_start = excluded.window_start, request_count = 1'
                );
                $upsert->execute([
                    ':bucket' => $bucket,
                    ':identity_hash' => $identityHash,
                    ':window_start' => $now,
                ]);
                $this->cleanup($now - 86400);
                $this->pdo->exec('COMMIT');
                return new RateLimitResult(true, 0);
            }

            $windowStart = (int) $row['window_start'];
            $count = (int) $row['request_count'];
            if ($count >= $limit) {
                $retryAfter = max(1, $windowStart + $windowSeconds - $now);
                $this->pdo->exec('COMMIT');
                return new RateLimitResult(false, $retryAfter);
            }

            $update = $this->pdo->prepare(
                'UPDATE rate_limits SET request_count = request_count + 1 '
                . 'WHERE bucket = :bucket AND identity_hash = :identity_hash'
            );
            $update->execute([':bucket' => $bucket, ':identity_hash' => $identityHash]);
            $this->pdo->exec('COMMIT');
            return new RateLimitResult(true, 0);
        } catch (Throwable $exception) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (Throwable) {
                // Preserve the original exception.
            }
            throw $exception;
        }
    }

    private function cleanup(int $before): void
    {
        $statement = $this->pdo->prepare('DELETE FROM rate_limits WHERE window_start < :before');
        $statement->execute([':before' => $before]);
    }
}
