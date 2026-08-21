<?php

declare(strict_types=1);

namespace App\Core\Domain\Tenant;

enum TenantStatus: string
{
    case PendingConfirmation = 'pending_confirmation';
    case Trial = 'trial';
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';
}
