<?php

declare(strict_types=1);

namespace App\Running\Application;

use App\Core\Application\Billing\LicenseService;
use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Tenancy\EventAccess;
use Doctrine\DBAL\Connection;

final readonly class RunningEventGateway
{
    public function __construct(private Connection $connection, private EventAccess $access, private LicenseService $licenses) {}

    /** @return array<string, mixed> */
    public function event(TenantUser $actor, string $publicId, bool $write = false): array
    {
        $allowed = $write ? $this->access->canManage($actor, $publicId) : $this->access->canRead($actor, $publicId);
        if (!$allowed) { throw new \DomainException('Keine Berechtigung für diese Veranstaltung.'); }
        return $this->load($actor, $publicId, $write);
    }

    /** @return array<string, mixed> */
    public function eventForDataEntry(TenantUser $actor, string $publicId): array
    {
        if (!$this->access->canEnterData($actor, $publicId)) { throw new \DomainException('Keine Berechtigung zur Datenerfassung.'); }
        return $this->load($actor, $publicId, true);
    }

    /** @return array<string, mixed> */
    private function load(TenantUser $actor, string $publicId, bool $write): array
    {
        $this->licenses->denyUnlessLicensed($actor->getTenant(), 'running_event');
        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT e.id, e.tenant_id, e.public_id, e.name, e.status, e.starts_on, e.ends_on
            FROM events e JOIN sport_modules sm ON sm.id = e.module_id
            WHERE e.tenant_id = :tenant AND e.public_id = :id AND sm.code = 'running_event'
            SQL, ['tenant' => $actor->getTenant()->getId(), 'id' => $publicId]);
        if ($row === false) { throw new \DomainException('Laufveranstaltung nicht gefunden.'); }
        if ($write && in_array($row['status'], ['completed', 'cancelled', 'archived'], true)) { throw new \DomainException('Die Laufveranstaltung ist nicht mehr bearbeitbar.'); }
        return $row;
    }

    /** @param array<string, mixed> $event */
    public function initialize(array $event): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $json = $this->connection->fetchOne("SELECT d.configuration FROM module_default_versions d JOIN sport_modules sm ON sm.id = d.module_id WHERE sm.code = 'running_event' AND d.valid_from <= :now ORDER BY d.valid_from DESC, d.version_number DESC LIMIT 1", ['now' => $now]);
        $defaults = is_string($json) ? json_decode($json, true) : null; $defaults = is_array($defaults) ? $defaults : [];
        $runs = max(1, min(20, (int) ($defaults['qualification_runs'] ?? 2))); $finalists = max(1, min(50, (int) ($defaults['finalists_per_category'] ?? 3))); $precision = in_array($defaults['time_precision'] ?? null, ['tenths', 'hundredths'], true) ? $defaults['time_precision'] : 'tenths'; $finalEnabled = array_key_exists('final_enabled', $defaults) ? (bool) $defaults['final_enabled'] : true;
        $this->connection->executeStatement(<<<'SQL'
            INSERT IGNORE INTO running_event_settings
            (event_id, tenant_id, qualification_runs, finalists_per_category, time_precision, final_enabled, finalists_confirmed_at, finalists_confirmed_by_user_id, created_at, updated_at, lock_version)
            VALUES (:event, :tenant, :runs, :finalists, :precision, :final_enabled, NULL, NULL, :now, :now, 1)
            SQL, ['event' => $event['id'], 'tenant' => $event['tenant_id'], 'runs' => $runs, 'finalists' => $finalists, 'precision' => $precision, 'final_enabled' => $finalEnabled, 'now' => $now]);
    }
}
