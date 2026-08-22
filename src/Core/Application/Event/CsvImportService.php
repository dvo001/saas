<?php
declare(strict_types=1);
namespace App\Core\Application\Event;
final readonly class CsvImportService
{
    public const RUNNING_HEADERS = ['Vorname', 'Nachname', 'Geburtsjahr', 'Geschlecht', 'Ort', 'Schulklasse', 'Externe ID'];
    public function template(): string { $stream = fopen('php://temp', 'r+'); if ($stream === false) { throw new \RuntimeException('CSV stream unavailable.'); } fputcsv($stream, self::RUNNING_HEADERS, ';', '"', ''); rewind($stream); $csv = stream_get_contents($stream); fclose($stream); return "\xEF\xBB\xBF".$csv; }
    /** @return array{valid:list<array<string,string>>,errors:list<array{line:int,message:string}>,duplicates:list<array{line:int,key:string}>,categories:list<string>} */
    public function preview(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents; $stream = fopen('php://temp', 'r+'); if ($stream === false) { throw new \RuntimeException('CSV stream unavailable.'); } fwrite($stream, $contents); rewind($stream);
        $headers = fgetcsv($stream, 0, ';', '"', ''); if ($headers !== self::RUNNING_HEADERS) { fclose($stream); throw new \DomainException('Die CSV-Spalten entsprechen nicht der festen Vorlage.'); }
        $valid = $errors = $duplicates = []; $categories = []; $seen = []; $line = 1;
        while (($values = fgetcsv($stream, 0, ';', '"', '')) !== false) { ++$line; if ($values === [null]) { continue; } $values = array_pad($values, count(self::RUNNING_HEADERS), ''); $row = array_combine(self::RUNNING_HEADERS, array_map(static fn ($v): string => trim((string) $v), array_slice($values, 0, count(self::RUNNING_HEADERS)))); if ($row['Vorname'] === '' || $row['Nachname'] === '' || preg_match('/^(19|20)\d{2}$/', $row['Geburtsjahr']) !== 1) { $errors[] = ['line' => $line, 'message' => 'Vorname, Nachname und gültiges Geburtsjahr sind Pflicht.']; continue; } $key = mb_strtolower($row['Vorname'].'|'.$row['Nachname'].'|'.$row['Geburtsjahr']); if (isset($seen[$key])) { $duplicates[] = ['line' => $line, 'key' => $key]; } $seen[$key] = true; if ($row['Schulklasse'] !== '') { $categories[$row['Schulklasse']] = true; } $valid[] = $row; }
        fclose($stream); return ['valid' => $valid, 'errors' => $errors, 'duplicates' => $duplicates, 'categories' => array_keys($categories)];
    }
    /** @param array<mixed> $errors */
    public function errorReport(array $errors): string { $stream = fopen('php://temp', 'r+'); if ($stream === false) { throw new \RuntimeException('CSV stream unavailable.'); } fputcsv($stream, ['Zeile', 'Fehler'], ';', '"', ''); foreach ($errors as $error) { if (!is_array($error) || !isset($error['line'], $error['message']) || !is_numeric($error['line']) || !is_string($error['message'])) { continue; } fputcsv($stream, [(int) $error['line'], $error['message']], ';', '"', ''); } rewind($stream); $csv = stream_get_contents($stream); fclose($stream); return $csv; }
}
