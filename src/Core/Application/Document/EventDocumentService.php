<?php

declare(strict_types=1);

namespace App\Core\Application\Document;

use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Security\AuditLogger;
use App\Core\Infrastructure\Tenancy\EventAccess;
use App\Football\Application\FootballCompetitionService;
use App\Football\Application\FootballPdfService;
use App\Running\Application\RunningCompetitionService;
use App\Running\Application\RunningPdfService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class EventDocumentService
{
    private const DOCUMENTS = [
        'running_event' => ['qualification', 'finalists', 'final'],
        'football_tournament' => ['schedule', 'schedule_category', 'schedule_field', 'schedule_time', 'standings', 'finals', 'final_rankings'],
    ];

    public function __construct(
        private Connection $connection,
        private EventAccess $access,
        private RunningCompetitionService $running,
        private RunningPdfService $runningPdf,
        private FootballCompetitionService $football,
        private FootballPdfService $footballPdf,
        private AuditLogger $audit,
        private string $projectDirectory,
    ) {}

    /** @param array<string, mixed> $event */
    public function releaseFinalDocuments(TenantUser $actor, array $event, string $ip): void
    {
        $module = (string) $event['module_code'];
        $types = self::DOCUMENTS[$module] ?? throw new \DomainException('Für dieses Sportmodul sind keine Abschlussdokumente definiert.');
        $workspace = $module === 'running_event'
            ? $this->running->workspace($actor, (string) $event['public_id'])
            : $this->football->workspace($actor, (string) $event['public_id']);
        $createdAt = gmdate('Y-m-d H:i:s');
        $directory = 'storage/documents/'.$actor->getTenant()->getPublicId().'/'.$event['public_id'];
        $absoluteDirectory = $this->projectDirectory.'/'.$directory;
        if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0700, true) && !is_dir($absoluteDirectory)) {
            throw new \RuntimeException('Das Verzeichnis für Abschlussdokumente konnte nicht erstellt werden.');
        }

        $writtenFiles = [];
        try {
            $this->connection->transactional(function (Connection $db) use ($actor, $event, $module, $types, $workspace, $createdAt, $directory, &$writtenFiles): void {
                foreach ($types as $type) {
                    $version = (int) $db->fetchOne(
                        'SELECT COALESCE(MAX(version_number), 0) + 1 FROM event_documents WHERE tenant_id = :tenant AND event_id = :event AND document_type = :type FOR UPDATE',
                        ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'type' => $type],
                    );
                    $pdf = $module === 'running_event'
                        ? $this->runningPdf->create($workspace, $type, ['version' => $version, 'created_at' => $createdAt])
                        : $this->footballPdf->create($workspace, $type, ['version' => $version, 'created_at' => $createdAt]);
                    $relative = $directory.'/'.$type.'-v'.$version.'.pdf';
                    $absolute = $this->projectDirectory.'/'.$relative;
                    if (file_put_contents($absolute, $pdf, LOCK_EX) === false) { throw new \RuntimeException('Ein Abschlussdokument konnte nicht gespeichert werden.'); }
                    chmod($absolute, 0600); $writtenFiles[] = $absolute;
                $db->update('event_documents', ['is_current' => 0], ['tenant_id' => $event['tenant_id'], 'event_id' => $event['id'], 'document_type' => $type, 'is_current' => 1]);
                $db->insert('event_documents', [
                    'tenant_id' => $event['tenant_id'], 'event_id' => $event['id'], 'public_id' => Uuid::v7()->toRfc4122(),
                    'module_code' => $module, 'document_type' => $type, 'version_number' => $version,
                    'snapshot' => json_encode($this->normalize($workspace), JSON_THROW_ON_ERROR),
                    'storage_path' => $relative, 'sha256' => hash('sha256', $pdf),
                    'released_by_user_public_id' => $actor->getPublicId(), 'released_by_name' => $actor->getDisplayName(),
                    'is_current' => 1, 'created_at' => $createdAt,
                ]);
                }
            });
        } catch (\Throwable $exception) {
            foreach ($writtenFiles as $path) { if (is_file($path)) { @unlink($path); } }
            throw $exception;
        }

        $this->audit->log('event.final_documents_released', 'event', (string) $event['public_id'], $actor->getTenant(), $actor, ['module' => $module, 'documents' => $types], $ip);
    }

    /** @return list<array<string, mixed>> */
    public function listFor(TenantUser $actor, string $eventPublicId): array
    {
        if (!$this->access->canRead($actor, $eventPublicId)) { throw new \DomainException('Keine Berechtigung für diese Veranstaltung.'); }
        return $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT d.public_id, d.document_type, d.version_number, d.sha256, d.released_by_name, d.is_current, d.created_at
            FROM event_documents d JOIN events e ON e.id = d.event_id AND e.tenant_id = d.tenant_id
            WHERE d.tenant_id = :tenant AND e.public_id = :event
            ORDER BY d.document_type, d.version_number DESC
            SQL, ['tenant' => $actor->getTenant()->getId(), 'event' => $eventPublicId]);
    }

    public function downloadPath(TenantUser $actor, string $eventPublicId, string $documentPublicId): string
    {
        if (!$this->access->canRead($actor, $eventPublicId)) { throw new \DomainException('Keine Berechtigung für diese Veranstaltung.'); }
        $path = $this->connection->fetchOne(<<<'SQL'
            SELECT d.storage_path FROM event_documents d JOIN events e ON e.id = d.event_id AND e.tenant_id = d.tenant_id
            WHERE d.tenant_id = :tenant AND e.public_id = :event AND d.public_id = :document
            SQL, ['tenant' => $actor->getTenant()->getId(), 'event' => $eventPublicId, 'document' => $documentPublicId]);
        if (!is_string($path) || $path === '' || !is_file($this->projectDirectory.'/'.$path)) { throw new \DomainException('Abschlussdokument nicht gefunden.'); }
        return $this->projectDirectory.'/'.$path;
    }

    /** @param array<string, mixed> $event */
    public function pruneOnArchive(array $event): void
    {
        $obsolete = $this->connection->fetchAllAssociative('SELECT id, storage_path FROM event_documents WHERE tenant_id = :tenant AND event_id = :event AND is_current = 0', ['tenant' => $event['tenant_id'], 'event' => $event['id']]);
        foreach ($obsolete as $document) {
            $path = $this->projectDirectory.'/'.(string) $document['storage_path'];
            if (is_file($path)) { @unlink($path); }
            $this->connection->delete('event_documents', ['id' => $document['id'], 'tenant_id' => $event['tenant_id'], 'event_id' => $event['id']]);
        }
        $publications = $this->connection->fetchAllAssociative('SELECT id, document_type, version_number FROM football_publications WHERE tenant_id = :tenant AND event_id = :event ORDER BY document_type, version_number DESC', ['tenant' => $event['tenant_id'], 'event' => $event['id']]);
        $kept = [];
        foreach ($publications as $publication) {
            $type = (string) $publication['document_type'];
            if (!isset($kept[$type])) { $kept[$type] = true; continue; }
            $this->connection->delete('football_publications', ['id' => $publication['id'], 'tenant_id' => $event['tenant_id'], 'event_id' => $event['id']]);
        }
    }

    /** @param array<string, mixed> $event */
    public function removeAllFiles(array $event): void
    {
        $paths = $this->connection->fetchFirstColumn('SELECT storage_path FROM event_documents WHERE tenant_id = :tenant AND event_id = :event', ['tenant' => $event['tenant_id'], 'event' => $event['id']]);
        foreach ($paths as $path) { if (is_string($path) && is_file($this->projectDirectory.'/'.$path)) { @unlink($this->projectDirectory.'/'.$path); } }
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) { return $value->value; }
        if ($value instanceof \DateTimeInterface) { return $value->format(DATE_ATOM); }
        if (!is_array($value)) { return $value; }
        $normalized = [];
        foreach ($value as $key => $item) { $normalized[$key] = $this->normalize($item); }
        return $normalized;
    }
}
