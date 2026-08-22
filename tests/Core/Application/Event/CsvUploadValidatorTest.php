<?php

declare(strict_types=1);

namespace App\Tests\Core\Application\Event;

use App\Core\Application\Event\CsvUploadValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class CsvUploadValidatorTest extends TestCase
{
    public function testItAcceptsUtf8CsvContentWithoutTrustingTheClientExtension(): void
    {
        $file = $this->upload("name;ort\nMüller;Zürich\n", 'payload.bin');

        self::assertSame("name;ort\nMüller;Zürich\n", (new CsvUploadValidator())->content($file));
    }

    public function testItRejectsBinaryContent(): void
    {
        $file = $this->upload("name;ort\0hidden", 'entries.csv');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('UTF-8-Text');
        (new CsvUploadValidator())->content($file);
    }

    public function testItRejectsOversizedLines(): void
    {
        $file = $this->upload(str_repeat('a', 32_769), 'entries.csv');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('CSV-Zeile');
        (new CsvUploadValidator())->content($file);
    }

    private function upload(string $content, string $clientName): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'csv-test-');
        self::assertIsString($path);
        file_put_contents($path, $content);

        return new UploadedFile($path, $clientName, null, null, true);
    }
}
