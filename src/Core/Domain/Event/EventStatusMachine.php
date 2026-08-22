<?php

declare(strict_types=1);

namespace App\Core\Domain\Event;

final readonly class EventStatusMachine
{
    /** @return list<EventStatus> */
    public function allowedTargets(EventStatus $from): array
    {
        return match ($from) {
            EventStatus::Draft => [EventStatus::Preparation, EventStatus::Cancelled],
            EventStatus::Preparation => [EventStatus::Running, EventStatus::Cancelled],
            EventStatus::Running => [EventStatus::Completed, EventStatus::Cancelled],
            EventStatus::Completed, EventStatus::Cancelled => [EventStatus::Archived],
            EventStatus::Archived => [],
        };
    }

    public function assertTransition(EventStatus $from, EventStatus $to, ?string $reason = null, bool $confirmed = false): void
    {
        if (!in_array($to, $this->allowedTargets($from), true)) { throw new \DomainException(sprintf('Statuswechsel von %s zu %s ist nicht erlaubt.', $from->label(), $to->label())); }
        if (in_array($to, [EventStatus::Completed, EventStatus::Cancelled, EventStatus::Archived], true) && !$confirmed) { throw new \DomainException('Dieser endgültige Statuswechsel muss ausdrücklich bestätigt werden.'); }
        if ($to === EventStatus::Cancelled && trim((string) $reason) === '') { throw new \DomainException('Für den Abbruch ist eine Begründung erforderlich.'); }
    }
}
