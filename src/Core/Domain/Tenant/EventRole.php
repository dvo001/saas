<?php

declare(strict_types=1);

namespace App\Core\Domain\Tenant;

enum EventRole: string
{
    case EventManager = 'event_manager';
    case DataEntry = 'data_entry';
    case ReadOnly = 'read_only';
}
