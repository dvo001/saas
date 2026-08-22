<?php

declare(strict_types=1);

namespace App\Football\Application;

use Doctrine\DBAL\Connection;

final readonly class FootballRosterGuard
{
    public function __construct(private Connection $connection) {}

    public function assertAssignmentAllowed(int $tenantId, int $eventId, int $teamId, int $participantId): void
    {
        $category = $this->connection->fetchAssociative(<<<'SQL'
            SELECT c.max_roster_size, d.withdrawn_at
            FROM events e JOIN sport_modules sm ON sm.id=e.module_id
            JOIN football_team_data d ON d.event_id=e.id AND d.tenant_id=e.tenant_id AND d.team_id=:team
            JOIN football_categories c ON c.id=d.category_id
            WHERE e.tenant_id=:tenant AND e.id=:event AND sm.code='football_tournament'
            SQL, ['tenant' => $tenantId, 'event' => $eventId, 'team' => $teamId]);
        if ($category === false) { return; }
        if ($category['withdrawn_at'] !== null) { throw new \DomainException('Einem zurückgezogenen Team können keine Spieler zugewiesen werden.'); }
        $alreadyAssigned = $this->connection->fetchOne('SELECT 1 FROM event_team_memberships WHERE tenant_id=:tenant AND event_id=:event AND team_id=:team AND participant_id=:participant', ['tenant' => $tenantId, 'event' => $eventId, 'team' => $teamId, 'participant' => $participantId]) !== false;
        $size = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM event_team_memberships WHERE tenant_id=:tenant AND event_id=:event AND team_id=:team', ['tenant' => $tenantId, 'event' => $eventId, 'team' => $teamId]);
        if (!$alreadyAssigned && $size >= (int) $category['max_roster_size']) { throw new \DomainException('Die maximale Kadergrösse dieses Teams ist erreicht.'); }
    }
}
