<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Security;

use App\Core\Infrastructure\Doctrine\Entity\Tenant;
use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Doctrine\Entity\PlatformAdmin;
use Doctrine\DBAL\Connection;

final readonly class AuditLogger
{
    public function __construct(private Connection $connection, private string $appSecret) {}

    /** @param array<string, mixed> $context */
    public function log(string $action, string $subjectType, ?string $subjectPublicId, ?Tenant $tenant = null, ?TenantUser $actor = null, array $context = [], ?string $ip = null): void
    {
        $this->connection->insert('audit_log', [
            'tenant_id' => $tenant?->getId(),
            'actor_user_id' => $actor?->getId(),
            'actor_platform_admin_id' => null,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_public_id' => $subjectPublicId,
            'context' => json_encode($context, JSON_THROW_ON_ERROR),
            'ip_hash' => $ip === null ? null : hash_hmac('sha256', $ip, $this->appSecret),
            'occurred_at' => gmdate('Y-m-d H:i:s'),
            'retention_until' => null,
        ]);
    }

    /** @param array<string, mixed> $context */
    public function logPlatform(string $action, string $subjectType, ?string $subjectPublicId, PlatformAdmin $actor, array $context = [], ?Tenant $tenant = null, ?string $ip = null): void
    {
        $this->connection->insert('audit_log', [
            'tenant_id' => $tenant?->getId(),
            'actor_user_id' => null,
            'actor_platform_admin_id' => $actor->getId(),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_public_id' => $subjectPublicId,
            'context' => json_encode($context, JSON_THROW_ON_ERROR),
            'ip_hash' => $ip === null ? null : hash_hmac('sha256', $ip, $this->appSecret),
            'occurred_at' => gmdate('Y-m-d H:i:s'),
            'retention_until' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->add(new \DateInterval('P10Y'))->format('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<string, mixed> $context */
    public function logSystem(string $action, string $subjectType, ?string $subjectPublicId, array $context = []): void
    {
        $this->connection->insert('audit_log', [
            'tenant_id' => null,
            'actor_user_id' => null,
            'actor_platform_admin_id' => null,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_public_id' => $subjectPublicId,
            'context' => json_encode($context, JSON_THROW_ON_ERROR),
            'ip_hash' => null,
            'occurred_at' => gmdate('Y-m-d H:i:s'),
            'retention_until' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->add(new \DateInterval('P10Y'))->format('Y-m-d H:i:s'),
        ]);
    }
}
