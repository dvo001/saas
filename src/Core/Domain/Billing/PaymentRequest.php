<?php

declare(strict_types=1);

namespace App\Core\Domain\Billing;

final readonly class PaymentRequest
{
    public function __construct(
        public string $invoicePublicId,
        public Money $amount,
        public string $returnUrl,
        public ?string $recurringReference = null,
    ) {}
}
