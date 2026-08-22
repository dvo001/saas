<?php

declare(strict_types=1);

namespace App\Core\Application\Export;

use App\Core\Application\Billing\InvoiceDocumentService;
use Doctrine\DBAL\Connection;

final readonly class TenantExportBuilder
{
    /** @var list<string> */
    private const TENANT_TABLES = [
        'tenant_users', 'billing_profiles', 'subscriptions', 'subscription_modules', 'coupons', 'invoices', 'invoice_lines',
        'payment_transactions', 'external_organizations', 'participant_registry', 'team_registry', 'event_templates',
        'events', 'event_user_assignments', 'event_participants', 'event_teams', 'event_team_memberships', 'event_documents',
        'running_event_settings', 'running_categories', 'running_participant_data', 'running_qualification_results', 'running_final_results',
        'football_event_settings', 'football_categories', 'football_groups', 'football_team_data', 'football_fields',
        'football_field_periods', 'football_matches', 'football_publications', 'football_tiebreak_decisions',
    ];

    public function __construct(private Connection $connection, private InvoiceDocumentService $invoiceDocuments, private CsvCellSanitizer $csvCells, private string $projectDirectory) {}

    public function build(int $tenantId, string $jobPublicId, \DateTimeImmutable $now): string
    {
        $tenant = $this->connection->fetchAssociative('SELECT * FROM tenants WHERE id = :tenant', ['tenant' => $tenantId]);
        if ($tenant === false) { throw new \RuntimeException('Mandant für Export nicht gefunden.'); }
        $directory = $this->projectDirectory.'/storage/exports';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) { throw new \RuntimeException('Exportverzeichnis konnte nicht erstellt werden.'); }
        $relative = 'storage/exports/'.$jobPublicId.'.zip';
        $absolute = $this->projectDirectory.'/'.$relative;
        $zip = new \ZipArchive();
        if ($zip->open($absolute, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) { throw new \RuntimeException('ZIP-Export konnte nicht erstellt werden.'); }
        try {
            $manifest = ['format_version' => 1, 'export_type' => 'full_tenant_zip', 'tenant_public_id' => $tenant['public_id'], 'created_at' => $now->format(DATE_ATOM), 'audit_log_included' => false];
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $zip->addFromString('daten/tenants.csv', $this->csv([$this->scrub($tenant)]));
            foreach (self::TENANT_TABLES as $table) {
                $rows = $this->connection->fetchAllAssociative('SELECT * FROM '.$table.' WHERE tenant_id = :tenant', ['tenant' => $tenantId]);
                $zip->addFromString('daten/'.$table.'.csv', $this->csv(array_map($this->scrub(...), $rows)));
            }
            $templateVersions = $this->connection->fetchAllAssociative('SELECT v.* FROM event_template_versions v JOIN event_templates t ON t.id = v.template_id WHERE t.tenant_id = :tenant ORDER BY v.template_id, v.version_number', ['tenant' => $tenantId]);
            $zip->addFromString('daten/event_template_versions.csv', $this->csv($templateVersions));
            $logo = $this->absolute((string) ($tenant['logo_storage_path'] ?? ''));
            if ($logo !== null) { $zip->addFile($logo, 'dateien/vereinslogo.png'); }
            $this->addStoredFiles($zip, $tenantId);
        } catch (\Throwable $exception) {
            $zip->close();
            if (is_file($absolute)) { @unlink($absolute); }
            throw $exception;
        }
        $zip->close();
        chmod($absolute, 0600);
        return $relative;
    }

    private function addStoredFiles(\ZipArchive $zip, int $tenantId): void
    {
        $documents = $this->connection->fetchAllAssociative('SELECT e.public_id AS event_public_id, d.document_type, d.version_number, d.storage_path FROM event_documents d JOIN events e ON e.id = d.event_id AND e.tenant_id = d.tenant_id WHERE d.tenant_id = :tenant ORDER BY e.public_id, d.document_type, d.version_number', ['tenant' => $tenantId]);
        foreach ($documents as $document) {
            $path = $this->absolute((string) $document['storage_path']);
            if ($path !== null) { $zip->addFile($path, sprintf('pdf/veranstaltungen/%s/%s-v%d.pdf', $document['event_public_id'], $document['document_type'], $document['version_number'])); }
        }
        $invoices = $this->connection->fetchAllAssociative('SELECT public_id, invoice_number, document_type, pdf_storage_path FROM invoices WHERE tenant_id = :tenant ORDER BY issued_at, id', ['tenant' => $tenantId]);
        foreach ($invoices as $invoice) {
            $path = $this->absolute((string) $invoice['pdf_storage_path']);
            if ($path === null) { $path = $this->invoiceDocuments->pdfPathForTenant($tenantId, (string) $invoice['public_id']); }
            $zip->addFile($path, 'pdf/rechnungen/'.preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $invoice['invoice_number']).'-'.$invoice['document_type'].'.pdf');
        }
    }

    private function absolute(string $relative): ?string
    {
        if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/')) { return null; }
        $absolute = $this->projectDirectory.'/'.$relative;
        return is_file($absolute) ? $absolute : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function scrub(array $row): array
    {
        foreach (array_keys($row) as $column) {
            if (preg_match('/(?:password|secret|token)(?:_|$)/i', (string) $column) === 1) { unset($row[$column]); }
        }
        return $row;
    }

    /** @param list<array<string, mixed>> $rows */
    private function csv(array $rows): string
    {
        if ($rows === []) { return "\xEF\xBB\xBF"; }
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) { throw new \RuntimeException('CSV konnte nicht erzeugt werden.'); }
        fwrite($stream, "\xEF\xBB\xBF"); fputcsv($stream, array_keys($rows[0]), ';', '"', '');
        foreach ($rows as $row) { fputcsv($stream, array_map($this->csvCells->escape(...), array_values($row)), ';', '"', ''); }
        rewind($stream); $content = stream_get_contents($stream); fclose($stream);
        return is_string($content) ? $content : '';
    }
}
