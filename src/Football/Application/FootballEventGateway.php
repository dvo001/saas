<?php

declare(strict_types=1);

namespace App\Football\Application;

use App\Core\Application\Billing\LicenseService;
use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Tenancy\EventAccess;
use Doctrine\DBAL\Connection;

final readonly class FootballEventGateway
{
    public function __construct(private Connection $connection, private EventAccess $access, private LicenseService $licenses) {}

    /** @return array<string, mixed> */
    public function read(TenantUser $actor, string $publicId): array
    {
        if (!$this->access->canRead($actor, $publicId)) { throw new \DomainException('Keine Berechtigung für diese Veranstaltung.'); }
        return $this->load($actor, $publicId, false);
    }

    /** @return array<string, mixed> */
    public function manage(TenantUser $actor, string $publicId): array
    {
        if (!$this->access->canManage($actor, $publicId)) { throw new \DomainException('Nur die Veranstaltungsleitung darf diese Fussballkonfiguration ändern.'); }
        return $this->load($actor, $publicId, true);
    }

    /** @return array<string, mixed> */
    public function enterData(TenantUser $actor, string $publicId): array
    {
        if (!$this->access->canEnterData($actor, $publicId)) { throw new \DomainException('Keine Berechtigung zur Datenerfassung.'); }
        return $this->load($actor, $publicId, true);
    }

    /** @return array{manage:bool,data_entry:bool} */
    public function capabilities(TenantUser $actor, string $publicId): array
    {
        return ['manage' => $this->access->canManage($actor, $publicId), 'data_entry' => $this->access->canEnterData($actor, $publicId)];
    }

    /** @param array<string, mixed> $event */
    public function initialize(array $event): void
    {
        $configuration = json_decode((string) ($event['configuration'] ?? '{}'), true);
        $defaults = is_array($configuration) ? $configuration : [];
        $now = gmdate('Y-m-d H:i:s');
        $strategy = in_array($defaults['scheduling_strategy'] ?? null, ['field_utilization', 'compact', 'balanced'], true) ? $defaults['scheduling_strategy'] : 'field_utilization';
        $this->connection->executeStatement(<<<'SQL'
            INSERT IGNORE INTO football_event_settings
            (event_id, tenant_id, points_win, points_draw, points_loss, forfait_goals_winner, forfait_goals_loser, scheduling_strategy, schedule_state, ranking_state, created_at, updated_at, lock_version)
            VALUES (:event, :tenant, :win, :draw, :loss, :forfait_win, :forfait_loss, :strategy, 'draft', 'draft', :now, :now, 1)
            SQL, [
                'event' => $event['id'], 'tenant' => $event['tenant_id'],
                'win' => max(0, (int) ($defaults['points_win'] ?? 3)), 'draw' => max(0, (int) ($defaults['points_draw'] ?? 1)), 'loss' => max(0, (int) ($defaults['points_loss'] ?? 0)),
                'forfait_win' => max(0, (int) ($defaults['forfait_goals_winner'] ?? 3)), 'forfait_loss' => max(0, (int) ($defaults['forfait_goals_loser'] ?? 0)),
                'strategy' => $strategy, 'now' => $now,
            ]);
    }

    /** @return array<string, mixed> */
    private function load(TenantUser $actor, string $publicId, bool $write): array
    {
        $this->licenses->denyUnlessLicensed($actor->getTenant(), 'football_tournament');
        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT e.id, e.tenant_id, e.public_id, e.name, e.status, e.starts_on, e.ends_on, e.configuration
            FROM events e JOIN sport_modules sm ON sm.id = e.module_id
            WHERE e.tenant_id = :tenant AND e.public_id = :id AND sm.code = 'football_tournament'
            SQL, ['tenant' => $actor->getTenant()->getId(), 'id' => $publicId]);
        if ($row === false) { throw new \DomainException('Fussballturnier nicht gefunden.'); }
        if ($write && in_array($row['status'], ['completed', 'cancelled', 'archived'], true)) { throw new \DomainException('Das Fussballturnier ist nicht mehr bearbeitbar.'); }
        return $row;
    }
}
