<?php

declare(strict_types=1);

namespace App\Core\Domain\Billing;

interface PaymentProviderInterface
{
    public function key(): string;
    public function start(PaymentRequest $request): PaymentInitiation;
    public function status(string $providerReference): PaymentResult;
    /** @param array<string, string> $headers */
    public function handleWebhook(string $payload, array $headers): PaymentResult;
    public function supportsRecurringPayments(): bool;
}
