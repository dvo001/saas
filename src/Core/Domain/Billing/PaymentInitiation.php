<?php

declare(strict_types=1);

namespace App\Core\Domain\Billing;

final readonly class PaymentInitiation
{
    public function __construct(public string $providerReference, public string $redirectUrl) {}
}
