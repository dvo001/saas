<?php

declare(strict_types=1);

namespace App\Tests\Core\Domain\Billing;

use App\Core\Domain\Billing\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    #[DataProvider('decimalAmounts')]
    public function testParsesDecimalAmountsWithoutFloatingPoint(string $input, int $minor): void
    {
        self::assertSame($minor, Money::fromDecimal($input)->minor);
    }

    /** @return iterable<string, array{string, int}> */
    public static function decimalAmounts(): iterable
    {
        yield 'whole francs' => ['149', 14900];
        yield 'one decimal' => ['149.5', 14950];
        yield 'comma decimal' => ['149,05', 14905];
    }

    public function testCalculatesBasisPointPercentageWithCommercialRounding(): void
    {
        self::assertSame(811, (new Money(10010))->percentage(810)->minor);
    }

    public function testRejectsMoreThanTwoDecimalPlaces(): void
    {
        $this->expectException(\DomainException::class);
        Money::fromDecimal('10.001');
    }
}
