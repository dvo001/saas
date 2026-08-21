<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Security;

use Doctrine\DBAL\Connection;

final readonly class OneTimeTokenStore
{
    public function __construct(private Connection $connection) {}

    /** @param array<string, mixed> $payload */
    public function issue(int $tenantId, ?int $userId, string $type, \DateTimeImmutable $expiresAt, array $payload = []): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->connection->insert('tenant_auth_tokens', [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
            'token_type' => $type,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'consumed_at' => null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return $token;
    }

    /** @return array<string, mixed>|null */
    public function consume(string $token, string $type, \DateTimeImmutable $now): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM tenant_auth_tokens WHERE token_hash = :hash AND token_type = :type AND consumed_at IS NULL AND expires_at > :now FOR UPDATE',
            ['hash' => hash('sha256', $token), 'type' => $type, 'now' => $now->format('Y-m-d H:i:s')],
        );
        if ($row === false) {
            return null;
        }

        $changed = $this->connection->executeStatement(
            'UPDATE tenant_auth_tokens SET consumed_at = :now WHERE id = :id AND consumed_at IS NULL',
            ['now' => $now->format('Y-m-d H:i:s'), 'id' => $row['id']],
        );

        return $changed === 1 ? $row : null;
    }
}
