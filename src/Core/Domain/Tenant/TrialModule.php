<?php

declare(strict_types=1);

namespace App\Core\Domain\Tenant;

enum TrialModule: string
{
    case Running = 'running_event';
    case Football = 'football_tournament';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Laufveranstaltung',
            self::Football => 'Fussballturnier',
        };
    }
}
