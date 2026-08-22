<?php

declare(strict_types=1);

namespace App\Core\Domain\Billing;

final readonly class InvoiceTotals
{
    public function __construct(
        public int $subtotalMinor,
        public int $discountMinor,
        public int $vatRateBasisPoints,
        public int $vatMinor,
        public int $totalMinor,
        public string $currency = 'CHF',
    ) {
        if ($subtotalMinor < 0 || $discountMinor < 0 || $discountMinor > $subtotalMinor || $vatRateBasisPoints < 0 || $vatMinor < 0 || $totalMinor < 0) {
            throw new \InvalidArgumentException('Invalid invoice totals.');
        }
    }
}
