<?php

declare(strict_types=1);

namespace App\Running\Application;

use App\Core\Domain\Event\EventStatus;
use Doctrine\DBAL\Connection;

final readonly class RunningTransitionGuard
{
    public function __construct(private Connection $connection) {}

    /** @param array<string, mixed> $event */
    public function assertAllowed(array $event, EventStatus $target): void
    {
        if (($event['module_code'] ?? null) !== 'running_event' || !in_array($target, [EventStatus::Running, EventStatus::Completed], true)) { return; }
        $tenant = $event['tenant_id']; $eventId = $event['id'];
        if ($this->connection->fetchOne('SELECT 1 FROM running_event_settings WHERE event_id = :event', ['event' => $eventId]) === false) { throw new \DomainException('Die Laufkonfiguration ist noch nicht angelegt.'); }
        if ($this->connection->fetchOne('SELECT 1 FROM running_categories WHERE tenant_id = :tenant AND event_id = :event AND active = 1', ['tenant' => $tenant, 'event' => $eventId]) === false) { throw new \DomainException('Mindestens eine Laufkategorie ist erforderlich.'); }
        $unassigned = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM event_participants p LEFT JOIN running_participant_data d ON d.participant_id = p.id WHERE p.tenant_id = :tenant AND p.event_id = :event AND (d.participant_id IS NULL OR d.category_id IS NULL)', ['tenant' => $tenant, 'event' => $eventId]);
        if ($unassigned > 0) { throw new \DomainException($unassigned.' Teilnehmer sind keiner Kategorie zugeordnet.'); }
        if ($target !== EventStatus::Completed) { return; }
        $withoutResult = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM event_participants p WHERE p.tenant_id = :tenant AND p.event_id = :event AND NOT EXISTS (SELECT 1 FROM running_qualification_results q WHERE q.tenant_id = p.tenant_id AND q.event_id = p.event_id AND q.participant_id = p.id)', ['tenant' => $tenant, 'event' => $eventId]);
        if ($withoutResult > 0) { throw new \DomainException('Für alle Teilnehmer muss mindestens ein Qualifikationsstatus erfasst sein.'); }
        $settings = $this->connection->fetchAssociative('SELECT final_enabled, finalists_confirmed_at FROM running_event_settings WHERE event_id = :event', ['event' => $eventId]);
        if ($settings !== false && (bool) $settings['final_enabled']) {
            if ($settings['finalists_confirmed_at'] === null) { throw new \DomainException('Die Finalisten sind noch nicht bestätigt.'); }
            $missingFinals = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM running_participant_data d WHERE d.tenant_id = :tenant AND d.event_id = :event AND d.finalist_confirmed = 1 AND NOT EXISTS (SELECT 1 FROM running_final_results f WHERE f.participant_id = d.participant_id)', ['tenant' => $tenant, 'event' => $eventId]);
            if ($missingFinals > 0) { throw new \DomainException('Für alle Finalisten muss ein Finalstatus erfasst sein.'); }
        }
    }
}
