<?php

declare(strict_types=1);

namespace App\Football\Domain;

enum SchedulingStrategy: string
{
    case FieldUtilization = 'field_utilization';
    case Compact = 'compact';
    case Balanced = 'balanced';
}
