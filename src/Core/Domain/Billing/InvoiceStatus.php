<?php

declare(strict_types=1);

namespace App\Core\Domain\Billing;

enum InvoiceStatus: string
{
    case Open = 'open';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Dunning = 'dunning';
    case Cancelled = 'cancelled';
    case Credited = 'credited';
}
