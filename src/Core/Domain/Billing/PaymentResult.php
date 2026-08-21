<?php

declare(strict_types=1);

namespace App\Core\Domain\Billing;

final readonly class PaymentResult
{
    /** @param array<string, scalar|null> $providerData */
    public function __construct(
        public string $providerReference,
        public string $status,
        public Money $amount,
        public array $providerData = [],
    ) {}
}
