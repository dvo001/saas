<?php

declare(strict_types=1);

namespace App\Core\Domain\Billing;

enum SubscriptionStatus: string
{
    case Active = 'active';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Suspended = 'suspended';
    case Temporary = 'temporary';
}
