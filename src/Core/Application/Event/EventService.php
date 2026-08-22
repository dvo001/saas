<?php

declare(strict_types=1);

namespace App\Core\Application\Event;

use App\Core\Application\Billing\LicenseService;
use App\Core\Domain\Event\EventStatus;
use App\Core\Domain\Event\EventStatusMachine;
use App\Core\Domain\Tenant\TenantRole;
use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Security\AuditLogger;
use App\Core\Infrastructure\Tenancy\EventAccess;
use App\Running\Application\RunningTransitionGuard;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class EventService
{
    public function __construct(private Connection $connection, private EventStatusMachine $statuses, private EventAccess $access, private LicenseService $licenses, private AuditLogger $audit, private RunningTransitionGuard $runningGuard) {}

    /** @return list<array<string, mixed>> */
    public function listFor(TenantUser $user): array
    {
        $tenant = $user->getTenant(); $tenantId = $tenant->getId() ?? throw new \LogicException('Missing tenant id.');
        $admin = in_array($user->getTenantRole(), [TenantRole::Owner, TenantRole::Administrator], true);
        return $this->connection->fetchAllAssociative('SELECT e.public_id, e.name, e.status, e.starts_on, e.ends_on, e.location, sm.name AS module_name, sm.code AS module_code FROM events e JOIN sport_modules sm ON sm.id = e.module_id LEFT JOIN event_user_assignments a ON a.event_id = e.id AND a.tenant_id = e.tenant_id AND a.user_id = :user WHERE e.tenant_id = :tenant AND (:admin = 1 OR a.id IS NOT NULL) ORDER BY e.starts_on DESC, e.name', ['user' => $user->getId(), 'tenant' => $tenantId, 'admin' => $admin ? 1 : 0]);
    }

    /** @return array<string, mixed> */
    public function get(TenantUser $user, string $publicId): array
    {
        if (!$this->access->canRead($user, $publicId)) { throw new \DomainException('Die Veranstaltung wurde nicht gefunden.'); }
        $row = $this->connection->fetchAssociative('SELECT e.*, sm.name AS module_name, sm.code AS module_code, u.display_name AS manager_name FROM events e JOIN sport_modules sm ON sm.id = e.module_id JOIN tenant_users u ON u.id = e.primary_event_manager_id AND u.tenant_id = e.tenant_id WHERE e.tenant_id = :tenant AND e.public_id = :public_id', ['tenant' => $user->getTenant()->getId(), 'public_id' => $publicId]);
        return $row === false ? throw new \DomainException('Die Veranstaltung wurde nicht gefunden.') : $row;
    }

    /** @param array<string, mixed> $input */
    public function create(TenantUser $actor, array $input, string $ip): string
    {
        $this->requireAdministrator($actor); $tenant = $actor->getTenant(); $tenantId = $tenant->getId() ?? throw new \LogicException('Missing tenant id.');
        $module = $this->connection->fetchAssociative('SELECT id, code FROM sport_modules WHERE code = :code AND active = 1', ['code' => trim((string) ($input['module'] ?? ''))]);
        if ($module === false) { throw new \DomainException('Bitte ein gültiges Sportmodul wählen.'); }
        $this->licenses->denyUnlessLicensed($tenant, (string) $module['code']);
        [$starts, $ends] = $this->dates($input); $name = trim((string) ($input['name'] ?? '')); if ($name === '') { throw new \DomainException('Bitte einen Veranstaltungsnamen angeben.'); }
        $configuration = $this->defaults((int) $module['id']); $templateVersionId = null;
        $templateId = trim((string) ($input['template'] ?? ''));
        if ($templateId !== '') {
            $version = $this->connection->fetchAssociative("SELECT tv.id, tv.configuration FROM event_template_versions tv JOIN event_templates t ON t.id = tv.template_id WHERE t.public_id = :template AND t.module_id = :module AND t.active = 1 AND (t.scope = 'global' OR t.tenant_id = :tenant) ORDER BY tv.version_number DESC LIMIT 1", ['template' => $templateId, 'module' => $module['id'], 'tenant' => $tenantId]);
            if ($version === false) { throw new \DomainException('Die Vorlage ist nicht verfügbar.'); }
            $templateVersionId = $version['id']; $templateConfig = json_decode((string) $version['configuration'], true, 512, JSON_THROW_ON_ERROR); if (is_array($templateConfig)) { $configuration = array_replace_recursive($configuration, $templateConfig); }
        }
        $publicId = Uuid::v7()->toRfc4122(); $now = gmdate('Y-m-d H:i:s');
        $this->connection->transactional(function (Connection $db) use ($tenantId, $actor, $publicId, $module, $templateVersionId, $name, $starts, $ends, $input, $configuration, $now): void {
            $db->insert('events', ['tenant_id' => $tenantId, 'public_id' => $publicId, 'primary_event_manager_id' => $actor->getId(), 'module_id' => $module['id'], 'template_version_id' => $templateVersionId, 'name' => mb_substr($name, 0, 180), 'status' => 'draft', 'starts_on' => $starts, 'ends_on' => $ends, 'location' => mb_substr(trim((string) ($input['location'] ?? '')), 0, 255), 'internal_notes' => trim((string) ($input['notes'] ?? '')), 'configuration' => json_encode($configuration, JSON_THROW_ON_ERROR), 'cancellation_reason' => null, 'completed_at' => null, 'archived_at' => null, 'created_at' => $now, 'updated_at' => $now, 'lock_version' => 1]);
            $eventId = (int) $db->lastInsertId();
            $db->insert('event_user_assignments', ['tenant_id' => $tenantId, 'event_id' => $eventId, 'user_id' => $actor->getId(), 'event_role' => 'event_manager', 'created_at' => $now]);
        });
        $this->audit->log('event.created', 'event', $publicId, $tenant, $actor, ['module' => $module['code'], 'template' => $templateId ?: null], $ip); return $publicId;
    }

    public function transition(TenantUser $actor, string $publicId, EventStatus $target, ?string $reason, bool $confirmed, string $ip): void
    {
        if (!$this->access->canManage($actor, $publicId)) { throw new \DomainException('Keine Berechtigung für diesen Statuswechsel.'); }
        $event = $this->get($actor, $publicId); $from = EventStatus::from((string) $event['status']); $this->statuses->assertTransition($from, $target, $reason, $confirmed);
        $this->runningGuard->assertAllowed($event, $target);
        $changes = ['status' => $target->value, 'updated_at' => gmdate('Y-m-d H:i:s'), 'lock_version' => (int) $event['lock_version'] + 1];
        if ($target === EventStatus::Cancelled) { $changes['cancellation_reason'] = mb_substr(trim((string) $reason), 0, 1000); }
        if ($target === EventStatus::Completed) { $changes['completed_at'] = gmdate('Y-m-d H:i:s'); }
        if ($target === EventStatus::Archived) { $changes['archived_at'] = gmdate('Y-m-d H:i:s'); }
        $affected = $this->connection->update('events', $changes, ['tenant_id' => $actor->getTenant()->getId(), 'public_id' => $publicId, 'lock_version' => $event['lock_version']]);
        if ($affected !== 1) { throw new \DomainException('Die Veranstaltung wurde zwischenzeitlich geändert. Bitte laden Sie die Seite neu.'); }
        $this->audit->log('event.status_changed', 'event', $publicId, $actor->getTenant(), $actor, ['old' => $from->value, 'new' => $target->value, 'reason' => $reason], $ip);
    }

    public function duplicate(TenantUser $actor, string $publicId, string $name, string $ip): string
    {
        if (!$this->access->canManage($actor, $publicId)) { throw new \DomainException('Keine Berechtigung.'); } $source = $this->get($actor, $publicId); $newId = Uuid::v7()->toRfc4122(); $now = gmdate('Y-m-d H:i:s');
        $this->connection->transactional(function (Connection $db) use ($actor, $source, $newId, $name, $now): void { $db->insert('events', ['tenant_id' => $source['tenant_id'], 'public_id' => $newId, 'primary_event_manager_id' => $actor->getId(), 'module_id' => $source['module_id'], 'template_version_id' => $source['template_version_id'], 'name' => mb_substr(trim($name), 0, 180), 'status' => 'draft', 'starts_on' => $source['starts_on'], 'ends_on' => $source['ends_on'], 'location' => $source['location'], 'internal_notes' => $source['internal_notes'], 'configuration' => $source['configuration'], 'cancellation_reason' => null, 'completed_at' => null, 'archived_at' => null, 'created_at' => $now, 'updated_at' => $now, 'lock_version' => 1]); $db->insert('event_user_assignments', ['tenant_id' => $source['tenant_id'], 'event_id' => (int) $db->lastInsertId(), 'user_id' => $actor->getId(), 'event_role' => 'event_manager', 'created_at' => $now]); });
        $this->audit->log('event.duplicated', 'event', $newId, $actor->getTenant(), $actor, ['source' => $publicId], $ip); return $newId;
    }

    public function deleteArchived(TenantUser $actor, string $publicId, bool $confirmed, string $ip): void
    {
        $this->requireAdministrator($actor); if (!$confirmed) { throw new \DomainException('Die endgültige Löschung muss bestätigt werden.'); } $event = $this->get($actor, $publicId); if ($event['status'] !== 'archived') { throw new \DomainException('Nur archivierte Veranstaltungen können gelöscht werden.'); }
        $this->audit->log('event.deleted', 'event', $publicId, $actor->getTenant(), $actor, ['name' => $event['name']], $ip); $this->connection->delete('events', ['tenant_id' => $actor->getTenant()->getId(), 'public_id' => $publicId]);
    }

    /** @return list<array<string, mixed>> */
    public function creationOptions(TenantUser $actor): array { return $this->connection->fetchAllAssociative("SELECT t.public_id, t.name, sm.code AS module_code FROM event_templates t JOIN sport_modules sm ON sm.id = t.module_id WHERE t.active = 1 AND (t.scope = 'global' OR t.tenant_id = :tenant) ORDER BY sm.name, t.scope, t.name", ['tenant' => $actor->getTenant()->getId()]); }
    /**
     * @param array<string, mixed> $input
     * @return array{0: string, 1: string}
     */
    private function dates(array $input): array { try { $start = new \DateTimeImmutable((string) ($input['starts_on'] ?? '')); $end = new \DateTimeImmutable((string) ($input['ends_on'] ?? '')); } catch (\Exception) { throw new \DomainException('Bitte gültige Datumswerte angeben.'); } if ($end < $start) { throw new \DomainException('Das Enddatum darf nicht vor dem Startdatum liegen.'); } return [$start->format('Y-m-d'), $end->format('Y-m-d')]; }
    /** @return array<string, mixed> */
    private function defaults(int $moduleId): array { $json = $this->connection->fetchOne('SELECT configuration FROM module_default_versions WHERE module_id = :module AND valid_from <= :now ORDER BY valid_from DESC, version_number DESC LIMIT 1', ['module' => $moduleId, 'now' => gmdate('Y-m-d H:i:s')]); if (!is_string($json)) { return []; } $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR); return is_array($decoded) ? $decoded : []; }
    private function requireAdministrator(TenantUser $actor): void { if (!in_array($actor->getTenantRole(), [TenantRole::Owner, TenantRole::Administrator], true)) { throw new \DomainException('Nur Owner oder Administratoren dürfen diese Aktion ausführen.'); } }
}
