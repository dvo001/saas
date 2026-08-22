<?php
declare(strict_types=1);
namespace App\Tests\Core\Application\Event;
use App\Core\Application\Event\CsvImportService; use PHPUnit\Framework\TestCase;
final class CsvImportServiceTest extends TestCase
{
    public function testPreviewSeparatesErrorsDuplicatesAndCategories(): void { $csv = implode("\n", ['Vorname;Nachname;Geburtsjahr;Geschlecht;Ort;Schulklasse;Externe ID','Ada;Lovelace;2010;w;Bern;7a;X1','Ada;Lovelace;2010;w;Bern;7a;X2',';Fehlt;xx;;;7b;']); $result = (new CsvImportService())->preview($csv); self::assertCount(2, $result['valid']); self::assertCount(1, $result['duplicates']); self::assertCount(1, $result['errors']); self::assertSame(['7a'], $result['categories']); }
    public function testTemplateHasBomAndFixedHeaders(): void { $csv = (new CsvImportService())->template(); self::assertStringStartsWith("\xEF\xBB\xBF", $csv); self::assertStringContainsString('Vorname;Nachname;Geburtsjahr', $csv); }
}
