<?php

declare(strict_types=1);

namespace App\Tests\Core\Application\Export;

use App\Core\Application\Export\CsvCellSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CsvCellSanitizerTest extends TestCase
{
    #[DataProvider('dangerousCells')]
    public function testItNeutralizesSpreadsheetFormulas(string $input): void
    {
        self::assertSame("'".$input, (new CsvCellSanitizer())->escape($input));
    }

    /** @return iterable<string, array{string}> */
    public static function dangerousCells(): iterable
    {
        yield 'formula' => ['=1+1'];
        yield 'plus' => ['+SUM(A1:A2)'];
        yield 'minus' => ['-2+3'];
        yield 'command' => ['@SUM(A1:A2)'];
        yield 'tab' => ["\t=1+1"];
    }

    public function testItKeepsRegularContentUnchanged(): void
    {
        self::assertSame('Müller + Partner', (new CsvCellSanitizer())->escape('Müller + Partner'));
    }
}
