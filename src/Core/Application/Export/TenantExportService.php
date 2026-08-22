<?php

declare(strict_types=1);

namespace App\Core\Application\Export;

use App\Core\Domain\Tenant\TenantRole;
use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Security\AuditLogger;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class TenantExportService
{
    public function __construct(private Connection $connection, private AuditLogger $audit, private string $projectDirectory) {}

    public function request(TenantUser $actor, string $ip): string
    {
        $this->requireOwner($actor);
        $tenantId = $actor->getTenant()->getId() ?? throw new \LogicException('Missing tenant id.');
        if ($this->connection->fetchOne("SELECT 1 FROM export_jobs WHERE tenant_id = :tenant AND status IN ('queued', 'running') LIMIT 1", ['tenant' => $tenantId]) !== false) {
            throw new \DomainException('Ein vollständiger Datenexport wird bereits erstellt.');
        }
        $publicId = Uuid::v7()->toRfc4122();
        $this->connection->insert('export_jobs', [
            'tenant_id' => $tenantId, 'requested_by_user_id' => $actor->getId(), 'public_id' => $publicId,
            'export_type' => 'full_tenant_zip', 'status' => 'queued', 'storage_path' => null,
            'error_reference' => null, 'expires_at' => null, 'started_at' => null, 'finished_at' => null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->audit->log('tenant_export.requested', 'export_job', $publicId, $actor->getTenant(), $actor, [], $ip);
        return $publicId;
    }

    /** @return list<array<string, mixed>> */
    public function listFor(TenantUser $actor): array
    {
        $this->requireOwner($actor);
        return $this->connection->fetchAllAssociative('SELECT public_id, status, error_reference, expires_at, finished_at, created_at FROM export_jobs WHERE tenant_id = :tenant AND export_type = :type ORDER BY created_at DESC LIMIT 50', ['tenant' => $actor->getTenant()->getId(), 'type' => 'full_tenant_zip']);
    }

    public function downloadPath(TenantUser $actor, string $publicId, string $ip): string
    {
        $this->requireOwner($actor);
        $row = $this->connection->fetchAssociative("SELECT storage_path FROM export_jobs WHERE tenant_id = :tenant AND public_id = :id AND status = 'ready' AND expires_at > :now", ['tenant' => $actor->getTenant()->getId(), 'id' => $publicId, 'now' => gmdate('Y-m-d H:i:s')]);
        if ($row === false || !is_string($row['storage_path']) || !is_file($this->projectDirectory.'/'.$row['storage_path'])) { throw new \DomainException('Der Export ist nicht mehr verfügbar.'); }
        $this->audit->log('tenant_export.downloaded', 'export_job', $publicId, $actor->getTenant(), $actor, [], $ip);
        return $this->projectDirectory.'/'.$row['storage_path'];
    }

    private function requireOwner(TenantUser $actor): void
    {
        if ($actor->getTenantRole() !== TenantRole::Owner) { throw new \DomainException('Nur der Owner darf vollständige Datenexporte verwalten.'); }
    }
}
