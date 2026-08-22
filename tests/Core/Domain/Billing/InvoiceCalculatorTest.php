<?php

declare(strict_types=1);

namespace App\Tests\Core\Domain\Billing;

use App\Core\Domain\Billing\InvoiceCalculator;
use App\Core\Domain\Billing\Money;
use PHPUnit\Framework\TestCase;

final class InvoiceCalculatorTest extends TestCase
{
    public function testDiscountIsAppliedBeforeVatAndAmountsAreRoundedToRappen(): void
    {
        $totals = (new InvoiceCalculator())->calculate([new Money(10_00), new Money(20_00)], 1_500, 810);

        self::assertSame(3_000, $totals->subtotalMinor);
        self::assertSame(450, $totals->discountMinor);
        self::assertSame(207, $totals->vatMinor);
        self::assertSame(2_757, $totals->totalMinor);
        self::assertSame('CHF', $totals->currency);
    }

    public function testMixedCurrenciesAreRejected(): void
    {
        $this->expectException(\DomainException::class);
        (new InvoiceCalculator())->calculate([new Money(100, 'CHF'), new Money(100, 'EUR')], 0, 0);
    }

    public function testEmptyInvoiceIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        (new InvoiceCalculator())->calculate([], 0, 0);
    }
}
