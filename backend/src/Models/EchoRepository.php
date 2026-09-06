<?php

declare(strict_types=1);

namespace Echo\Models;

use PDO;
use PDOException;
use Throwable;

final class EchoRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function create(
        string $id,
        string $ciphertext,
        string $iv,
        string $accessTokenHash,
        bool $burnAfterReading,
        int $createdAt,
        int $expiresAt,
    ): bool {
        $this->deleteExpired($createdAt);

        $sql = <<<'SQL'
            INSERT INTO echoes (
                id, ciphertext, iv, access_token_hash, burn_after_reading,
                created_at, expires_at, read_count, last_read_at
            ) VALUES (
                :id, :ciphertext, :iv, :access_token_hash, :burn_after_reading,
                :created_at, :expires_at, 0, NULL
            )
        SQL;

        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute([
                ':id' => $id,
                ':ciphertext' => $ciphertext,
                ':iv' => $iv,
                ':access_token_hash' => $accessTokenHash,
                ':burn_after_reading' => $burnAfterReading ? 1 : 0,
                ':created_at' => $createdAt,
                ':expires_at' => $expiresAt,
            ]);
            return true;
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                return false;
            }
            throw $exception;
        }
    }

    public function reveal(string $id, string $presentedTokenHash, int $now): ?EchoPayload
    {
        $this->pdo->exec('BEGIN IMMEDIATE');

        try {
            $cleanup = $this->pdo->prepare('DELETE FROM echoes WHERE expires_at <= :now');
            $cleanup->execute([':now' => $now]);

            $statement = $this->pdo->prepare(
                'SELECT ciphertext, iv, access_token_hash, burn_after_reading, created_at, expires_at '
                . 'FROM echoes WHERE id = :id LIMIT 1',
            );
            $statement->execute([':id' => $id]);
            $row = $statement->fetch();

            if (!is_array($row)) {
                $this->pdo->exec('COMMIT');
                return null;
            }

            if (!hash_equals((string) $row['access_token_hash'], $presentedTokenHash)) {
                $this->pdo->exec('COMMIT');
                return null;
            }

            $payload = new EchoPayload(
                (string) $row['ciphertext'],
                (string) $row['iv'],
                ((int) $row['burn_after_reading']) === 1,
                (int) $row['created_at'],
                (int) $row['expires_at'],
            );

            if ($payload->burnAfterReading) {
                $this->deleteById($id);
            } else {
                $update = $this->pdo->prepare(
                    'UPDATE echoes SET read_count = read_count + 1, last_read_at = :now WHERE id = :id',
                );
                $update->execute([':now' => $now, ':id' => $id]);
            }

            $this->pdo->exec('COMMIT');
            return $payload;
        } catch (Throwable $exception) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (Throwable) {
                // Preserve the original exception.
            }
            throw $exception;
        }
    }

    public function deleteExpired(int $now): int
    {
        $statement = $this->pdo->prepare('DELETE FROM echoes WHERE expires_at <= :now');
        $statement->execute([':now' => $now]);
        return $statement->rowCount();
    }

    private function deleteById(string $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM echoes WHERE id = :id');
        $statement->execute([':id' => $id]);
    }
}
