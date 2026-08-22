<?php

declare(strict_types=1);

namespace App\Core\Application\Billing;

use App\Core\Application\Export\CsvCellSanitizer;
use App\Core\Infrastructure\Doctrine\Entity\PlatformAdmin;
use App\Core\Infrastructure\Security\AuditLogger;
use Doctrine\DBAL\Connection;

final readonly class AccountingExportService
{
    public function __construct(private Connection $connection, private AuditLogger $audit, private CsvCellSanitizer $csvCells) {}

    /**
     * @param array<string, mixed> $input
     * @return array{content: string, filename: string}
     */
    public function create(PlatformAdmin $actor, array $input, string $ip): array
    {
        [$from, $to] = $this->period($input);
        $until = $to->add(new \DateInterval('P1D'));
        $invoices = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT i.issued_at AS booked_at, i.document_type AS record_type, i.invoice_number AS document_number,
                   t.public_id AS tenant_public_id, t.name AS tenant_name, i.status, i.currency,
                   i.subtotal_minor, i.discount_minor, i.vat_minor, i.total_minor,
                   NULL AS payment_method, NULL AS payment_reference
            FROM invoices i JOIN tenants t ON t.id = i.tenant_id
            WHERE i.issued_at >= :from AND i.issued_at < :until
            ORDER BY i.issued_at, i.id
            SQL, ['from' => $from->format('Y-m-d H:i:s'), 'until' => $until->format('Y-m-d H:i:s')]);
        $payments = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT COALESCE(p.received_at, p.created_at) AS booked_at, 'payment' AS record_type, i.invoice_number AS document_number,
                   t.public_id AS tenant_public_id, t.name AS tenant_name, p.status, p.currency,
                   NULL AS subtotal_minor, NULL AS discount_minor, NULL AS vat_minor, p.amount_minor AS total_minor,
                   p.payment_method, COALESCE(p.provider_reference, p.public_id) AS payment_reference
            FROM payment_transactions p JOIN invoices i ON i.id = p.invoice_id AND i.tenant_id = p.tenant_id
            JOIN tenants t ON t.id = p.tenant_id
            WHERE COALESCE(p.received_at, p.created_at) >= :from AND COALESCE(p.received_at, p.created_at) < :until
            ORDER BY booked_at, p.id
            SQL, ['from' => $from->format('Y-m-d H:i:s'), 'until' => $until->format('Y-m-d H:i:s')]);
        $rows = [...$invoices, ...$payments];
        usort($rows, static fn (array $left, array $right): int => [(string) $left['booked_at'], (string) $left['record_type']] <=> [(string) $right['booked_at'], (string) $right['record_type']]);
        $content = $this->csv($rows);
        $this->audit->logPlatform('accounting.exported', 'accounting_export', null, $actor, ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'rows' => count($rows)], null, $ip);
        return ['content' => $content, 'filename' => 'buchhaltung-'.$from->format('Ymd').'-'.$to->format('Ymd').'.csv'];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function period(array $input): array
    {
        $type = (string) ($input['period'] ?? 'month');
        try {
            if ($type === 'month') { $from = new \DateTimeImmutable((string) ($input['month'] ?? '').'-01', new \DateTimeZone('UTC')); $to = $from->modify('last day of this month'); }
            elseif ($type === 'quarter') { $year = $this->year($input['year'] ?? null); $quarter = (int) ($input['quarter'] ?? 0); if ($quarter < 1 || $quarter > 4) { throw new \DomainException('Ungültiges Quartal.'); } $from = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, (($quarter - 1) * 3) + 1), new \DateTimeZone('UTC')); $to = $from->modify('+2 months')->modify('last day of this month'); }
            elseif ($type === 'year') { $year = $this->year($input['year'] ?? null); $from = new \DateTimeImmutable($year.'-01-01', new \DateTimeZone('UTC')); $to = new \DateTimeImmutable($year.'-12-31', new \DateTimeZone('UTC')); }
            elseif ($type === 'custom') { $from = new \DateTimeImmutable((string) ($input['date_from'] ?? ''), new \DateTimeZone('UTC')); $to = new \DateTimeImmutable((string) ($input['date_to'] ?? ''), new \DateTimeZone('UTC')); }
            else { throw new \DomainException('Unbekannter Zeitraumfilter.'); }
        } catch (\DomainException $exception) { throw $exception; } catch (\Throwable) { throw new \DomainException('Bitte einen gültigen Zeitraum wählen.'); }
        if ($to < $from || $to > $from->add(new \DateInterval('P10Y'))) { throw new \DomainException('Der Zeitraum muss chronologisch sein und darf höchstens zehn Jahre umfassen.'); }
        return [$from->setTime(0, 0), $to->setTime(0, 0)];
    }

    private function year(mixed $value): int
    {
        $year = (int) $value;
        if ($year < 2000 || $year > 2200) { throw new \DomainException('Ungültiges Buchhaltungsjahr.'); }
        return $year;
    }

    /** @param list<array<string, mixed>> $rows */
    private function csv(array $rows): string
    {
        $headers = ['booked_at', 'record_type', 'document_number', 'tenant_public_id', 'tenant_name', 'status', 'currency', 'subtotal_minor', 'discount_minor', 'vat_minor', 'total_minor', 'payment_method', 'payment_reference'];
        $stream = fopen('php://temp', 'w+b'); if ($stream === false) { throw new \RuntimeException('CSV konnte nicht erstellt werden.'); }
        fwrite($stream, "\xEF\xBB\xBF"); fputcsv($stream, $headers, ';', '"', '');
        foreach ($rows as $row) { fputcsv($stream, array_map(fn (string $header): string => $this->csvCells->escape($row[$header] ?? ''), $headers), ';', '"', ''); }
        rewind($stream); $content = stream_get_contents($stream); fclose($stream);
        return is_string($content) ? $content : '';
    }
}
