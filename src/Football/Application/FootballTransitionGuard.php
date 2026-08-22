<?php

declare(strict_types=1);

namespace App\Football\Application;

use App\Core\Domain\Event\EventStatus;
use Doctrine\DBAL\Connection;

final readonly class FootballTransitionGuard
{
    public function __construct(private Connection $connection) {}

    /** @param array<string, mixed> $event */
    public function assertAllowed(array $event, EventStatus $target): void
    {
        if (($event['module_code'] ?? null) !== 'football_tournament' || !in_array($target, [EventStatus::Running, EventStatus::Completed], true)) { return; }
        $scope = ['tenant' => $event['tenant_id'], 'event' => $event['id']];
        $settings = $this->connection->fetchAssociative('SELECT schedule_state,ranking_state FROM football_event_settings WHERE tenant_id=:tenant AND event_id=:event', $scope);
        if ($settings === false) { throw new \DomainException('Die Fussballkonfiguration ist noch nicht angelegt.'); }
        if ((int) $this->connection->fetchOne('SELECT COUNT(*) FROM football_categories WHERE tenant_id=:tenant AND event_id=:event AND active=1', $scope) < 1) { throw new \DomainException('Mindestens eine Fussballkategorie ist erforderlich.'); }
        if ((int) $this->connection->fetchOne('SELECT COUNT(*) FROM event_teams WHERE tenant_id=:tenant AND event_id=:event', $scope) < 2) { throw new \DomainException('Mindestens zwei Teams sind erforderlich.'); }
        $unassigned = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM event_teams t LEFT JOIN football_team_data d ON d.team_id=t.id WHERE t.tenant_id=:tenant AND t.event_id=:event AND (d.category_id IS NULL OR d.group_id IS NULL)', $scope);
        if ($unassigned > 0) { throw new \DomainException($unassigned.' Teams sind noch keiner Kategorie und Gruppe zugeordnet.'); }
        if ((int) $this->connection->fetchOne("SELECT COUNT(*) FROM football_field_periods WHERE tenant_id=:tenant AND event_id=:event AND period_type='available'", $scope) < 1) { throw new \DomainException('Mindestens ein Spielfeld mit Verfügbarkeit ist erforderlich.'); }
        if ((int) $this->connection->fetchOne('SELECT COUNT(*) FROM football_matches WHERE tenant_id=:tenant AND event_id=:event', $scope) < 1) { throw new \DomainException('Der Spielplan wurde noch nicht erzeugt.'); }
        $missingSchedules = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM football_categories c WHERE c.tenant_id=:tenant AND c.event_id=:event AND c.active=1 AND NOT EXISTS (SELECT 1 FROM football_matches m WHERE m.tenant_id=c.tenant_id AND m.event_id=c.event_id AND m.category_id=c.id AND m.stage='group')", $scope);
        if ($missingSchedules > 0) { throw new \DomainException('Für jede Kategorie muss ein Gruppenspielplan erzeugt werden.'); }
        if ($target === EventStatus::Running && $settings['schedule_state'] !== 'published') { throw new \DomainException('Der Spielplan muss vor Turnierstart freigegeben werden.'); }
        if ($target !== EventStatus::Completed) { return; }
        $open = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM football_matches WHERE tenant_id=:tenant AND event_id=:event AND status='scheduled'", $scope);
        if ($open > 0) { throw new \DomainException('Alle angesetzten Spiele müssen abgeschlossen, abgesagt oder gestrichen sein.'); }
        $missingFinals = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM football_categories c WHERE c.tenant_id=:tenant AND c.event_id=:event AND c.active=1 AND NOT EXISTS (SELECT 1 FROM football_matches m WHERE m.tenant_id=c.tenant_id AND m.event_id=c.event_id AND m.category_id=c.id AND m.stage='final')", $scope);
        if ($missingFinals > 0) { throw new \DomainException('Für jede Kategorie muss eine Finalrunde erstellt und abgeschlossen sein.'); }
        if ($settings['ranking_state'] !== 'published') { throw new \DomainException('Die Ranglisten müssen vor Abschluss freigegeben werden.'); }
    }
}
