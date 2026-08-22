<?php

declare(strict_types=1);

namespace App\Core\Application\Notification;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class NotificationService
{
    public function __construct(private Connection $connection) {}

    public function notifyAdministrativeUsers(int $tenantId, string $type, string $title, string $message, string $deduplicationKey, string $severity = 'info', ?string $actionUrl = null): int
    {
        $userIds = $this->connection->fetchFirstColumn("SELECT id FROM tenant_users WHERE tenant_id = :tenant AND tenant_role IN ('owner', 'administrator') AND active = 1 AND deleted_at IS NULL", ['tenant' => $tenantId]);
        $created = 0;
        foreach ($userIds as $userId) {
            $created += $this->connection->executeStatement(<<<'SQL'
                INSERT IGNORE INTO notifications (tenant_id, recipient_user_id, public_id, notification_type, severity, title, message, action_url, deduplication_key, read_at, created_at)
                VALUES (:tenant, :user, :public_id, :type, :severity, :title, :message, :action_url, :deduplication_key, NULL, :created_at)
                SQL, [
                    'tenant' => $tenantId, 'user' => $userId, 'public_id' => Uuid::v7()->toRfc4122(), 'type' => $type,
                    'severity' => $severity, 'title' => mb_substr($title, 0, 180), 'message' => mb_substr($message, 0, 1000),
                    'action_url' => $actionUrl, 'deduplication_key' => mb_substr($deduplicationKey, 0, 190), 'created_at' => gmdate('Y-m-d H:i:s'),
                ]);
        }
        return $created;
    }

    /** @return list<array<string, mixed>> */
    public function forUser(int $tenantId, int $userId, int $limit = 100): array
    {
        return $this->connection->fetchAllAssociative('SELECT public_id, notification_type, severity, title, message, action_url, read_at, created_at FROM notifications WHERE tenant_id = :tenant AND recipient_user_id = :user ORDER BY created_at DESC LIMIT :limit', ['tenant' => $tenantId, 'user' => $userId, 'limit' => min(200, max(1, $limit))], ['limit' => \Doctrine\DBAL\ParameterType::INTEGER]);
    }

    public function markRead(int $tenantId, int $userId, string $publicId): bool
    {
        return $this->connection->executeStatement('UPDATE notifications SET read_at = COALESCE(read_at, :now) WHERE tenant_id = :tenant AND recipient_user_id = :user AND public_id = :public_id', ['now' => gmdate('Y-m-d H:i:s'), 'tenant' => $tenantId, 'user' => $userId, 'public_id' => $publicId]) > 0;
    }
}
