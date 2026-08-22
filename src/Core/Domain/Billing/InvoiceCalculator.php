<?php

declare(strict_types=1);

namespace App\Core\Domain\Billing;

final class InvoiceCalculator
{
    /** @param list<Money> $lineTotals */
    public function calculate(array $lineTotals, int $discountBasisPoints, int $vatRateBasisPoints): InvoiceTotals
    {
        if ($lineTotals === []) {
            throw new \DomainException('An invoice requires at least one line.');
        }
        if ($discountBasisPoints < 0 || $discountBasisPoints > 10_000) {
            throw new \DomainException('Discount must be between 0 and 100 percent.');
        }
        if ($vatRateBasisPoints < 0 || $vatRateBasisPoints > 10_000) {
            throw new \DomainException('VAT rate must be between 0 and 100 percent.');
        }

        $currency = $lineTotals[0]->currency;
        $subtotal = 0;
        foreach ($lineTotals as $lineTotal) {
            if ($lineTotal->currency !== $currency) {
                throw new \DomainException('Invoice lines must use the same currency.');
            }
            $subtotal += $lineTotal->minor;
        }

        $discount = $this->roundFraction($subtotal * $discountBasisPoints, 10_000);
        $net = $subtotal - $discount;
        $vat = $this->roundFraction($net * $vatRateBasisPoints, 10_000);

        return new InvoiceTotals($subtotal, $discount, $vatRateBasisPoints, $vat, $net + $vat, $currency);
    }

    private function roundFraction(int $numerator, int $denominator): int
    {
        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }
}
