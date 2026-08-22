<?php

declare(strict_types=1);

namespace App\Core\Domain\Event;

enum EventStatus: string
{
    case Draft = 'draft';
    case Preparation = 'preparation';
    case Running = 'running';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) { self::Draft => 'Entwurf', self::Preparation => 'Vorbereitung', self::Running => 'Laufend', self::Completed => 'Abgeschlossen', self::Cancelled => 'Abgebrochen', self::Archived => 'Archiviert' };
    }

    public function isImmutable(): bool { return in_array($this, [self::Completed, self::Cancelled, self::Archived], true); }
}
