<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Security;

use Doctrine\DBAL\Connection;

final readonly class PlatformAdminTokenStore
{
    public function __construct(private Connection $connection) {}
    public function issue(int $adminId, string $type, \DateTimeImmutable $expiresAt): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->connection->insert('platform_admin_tokens', ['platform_admin_id' => $adminId, 'token_hash' => hash('sha256', $token), 'token_type' => $type, 'expires_at' => $expiresAt->format('Y-m-d H:i:s'), 'consumed_at' => null, 'created_at' => gmdate('Y-m-d H:i:s')]);

        return $token;
    }

    /** @return array<string, mixed>|null */
    public function consume(string $token, string $type): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM platform_admin_tokens WHERE token_hash = :hash AND token_type = :type AND consumed_at IS NULL AND expires_at > :now FOR UPDATE', ['hash' => hash('sha256', $token), 'type' => $type, 'now' => gmdate('Y-m-d H:i:s')]);
        if ($row === false) { return null; }
        $changed = $this->connection->executeStatement('UPDATE platform_admin_tokens SET consumed_at = :now WHERE id = :id AND consumed_at IS NULL', ['now' => gmdate('Y-m-d H:i:s'), 'id' => $row['id']]);

        return $changed === 1 ? $row : null;
    }
}
