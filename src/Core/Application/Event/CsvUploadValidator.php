<?php

declare(strict_types=1);

namespace App\Core\Application\Event;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class CsvUploadValidator
{
    private const MAX_BYTES = 2_000_000;
    private const MAX_ROWS = 10_000;
    private const MAX_LINE_BYTES = 32_768;

    public function content(UploadedFile $file): string
    {
        $size = $file->getSize();
        if (!$file->isValid() || $size === false || $size < 1 || $size > self::MAX_BYTES) { throw new \DomainException('Bitte eine gültige CSV-Datei bis 2 MB hochladen.'); }
        $content = $file->getContent();
        if (str_contains($content, "\0") || !mb_check_encoding($content, 'UTF-8')) { throw new \DomainException('Die CSV-Datei muss gültiger UTF-8-Text ohne Binärdaten sein.'); }
        $rows = preg_split('/\r\n|\n|\r/', $content);
        if (!is_array($rows) || count($rows) > self::MAX_ROWS + 1) { throw new \DomainException('Die CSV-Datei darf höchstens 10’000 Datenzeilen enthalten.'); }
        foreach ($rows as $row) { if (strlen($row) > self::MAX_LINE_BYTES) { throw new \DomainException('Eine CSV-Zeile überschreitet die zulässige Länge.'); } }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);
        if (!in_array($mime, ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'], true)) { throw new \DomainException('Der Dateiinhalt wurde nicht als CSV-Text erkannt.'); }
        return $content;
    }
}
