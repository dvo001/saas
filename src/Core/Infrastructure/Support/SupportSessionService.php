<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Support;

use App\Core\Infrastructure\Doctrine\Entity\PlatformAdmin;
use App\Core\Infrastructure\Doctrine\Entity\Tenant;
use App\Core\Infrastructure\Doctrine\Repository\PlatformAdminRepository;
use App\Core\Infrastructure\Security\AuditLogger;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class SupportSessionService
{
    public const SESSION_KEY = 'platform_support_token';

    public function __construct(
        private Connection $connection,
        private PlatformAdminRepository $admins,
        private AuditLogger $audit,
        private string $appSecret,
    ) {}

    public function start(PlatformAdmin $admin, Tenant $tenant, string $reason, string $ip): string
    {
        $reason = trim($reason);
        if (!$tenant->isSupportImpersonationEnabled()) {
            throw new \DomainException('Der Verein hat den Supportzugriff deaktiviert.');
        }
        if (mb_strlen($reason) < 10) {
            throw new \DomainException('Bitte einen nachvollziehbaren Grund mit mindestens 10 Zeichen angeben.');
        }

        $rawToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $publicId = Uuid::v7()->toRfc4122();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $expiresAt = $now->add(new \DateInterval('PT2H'));
        $this->connection->insert('support_sessions', [
            'public_id' => $publicId,
            'tenant_id' => $tenant->getId() ?? throw new \LogicException('Missing tenant id.'),
            'platform_admin_id' => $admin->getId() ?? throw new \LogicException('Missing platform admin id.'),
            'token_hash' => $this->hash($rawToken),
            'reason' => mb_substr($reason, 0, 500),
            'started_at' => $now->format('Y-m-d H:i:s'),
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'ended_at' => null,
        ]);
        $this->audit->logPlatform('support.started', 'support_session', $publicId, $admin, [
            'reason' => $reason,
            'expires_at' => $expiresAt->format(DATE_ATOM),
        ], $tenant, $ip);

        return $rawToken;
    }

    public function resolve(string $rawToken, Tenant $tenant): ?ActiveSupportSession
    {
        if ($rawToken === '' || $tenant->getId() === null || !$tenant->isSupportImpersonationEnabled()) {
            return null;
        }

        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT public_id, platform_admin_id, reason, expires_at
            FROM support_sessions
            WHERE token_hash = :token_hash
              AND tenant_id = :tenant_id
              AND ended_at IS NULL
              AND expires_at > :now
            SQL, [
            'token_hash' => $this->hash($rawToken),
            'tenant_id' => $tenant->getId(),
            'now' => gmdate('Y-m-d H:i:s'),
        ]);
        if ($row === false) {
            return null;
        }

        $admin = $this->admins->find((int) $row['platform_admin_id']);
        if (!$admin instanceof PlatformAdmin || !$admin->isActive() || !$admin->isEmailConfirmed()) {
            return null;
        }

        return new ActiveSupportSession(
            (string) $row['public_id'],
            $admin,
            (string) $row['reason'],
            new \DateTimeImmutable((string) $row['expires_at'], new \DateTimeZone('UTC')),
        );
    }

    public function end(string $rawToken, Tenant $tenant, string $ip): void
    {
        $active = $this->resolve($rawToken, $tenant);
        if ($active === null) {
            return;
        }

        $this->connection->update('support_sessions', ['ended_at' => gmdate('Y-m-d H:i:s')], [
            'token_hash' => $this->hash($rawToken),
            'tenant_id' => $tenant->getId(),
        ]);
        $this->audit->logPlatform('support.ended', 'support_session', $active->publicId, $active->platformAdmin, [
            'reason' => $active->reason,
        ], $tenant, $ip);
    }

    private function hash(string $rawToken): string
    {
        return hash_hmac('sha256', $rawToken, $this->appSecret);
    }
}
