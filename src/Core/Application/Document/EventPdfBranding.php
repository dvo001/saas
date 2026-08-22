<?php

declare(strict_types=1);

namespace App\Core\Application\Document;

use App\Core\Infrastructure\Settings\PlatformSettings;
use Doctrine\DBAL\Connection;

final readonly class EventPdfBranding
{
    public function __construct(private Connection $connection, private PlatformSettings $settings, private string $projectDirectory) {}

    /**
     * @param array<string,mixed> $event
     * @param array{version?:int,created_at?:string} $metadata
     */
    public function apply(\TCPDF $pdf, array $event, array $metadata = []): void
    {
        $tenant = isset($event['tenant_id']) ? $this->connection->fetchAssociative('SELECT name,logo_storage_path FROM tenants WHERE id=:tenant', ['tenant' => $event['tenant_id']]) : false;
        $platform = (string) $this->settings->get('platform.name', 'Vereinssport Schweiz');
        $createdAt = $metadata['created_at'] ?? gmdate(DATE_ATOM);
        try { $created = (new \DateTimeImmutable($createdAt))->setTimezone(new \DateTimeZone('Europe/Zurich'))->format('d.m.Y H:i'); } catch (\Exception) { $created = $createdAt; }
        $eventDate = '';
        if (is_string($event['starts_on'] ?? null) && $event['starts_on'] !== '') { $eventDate = (new \DateTimeImmutable($event['starts_on']))->format('d.m.Y'); if (($event['ends_on'] ?? null) !== $event['starts_on']) { $eventDate .= '–'.(new \DateTimeImmutable((string) $event['ends_on']))->format('d.m.Y'); } }
        $details = implode(' · ', array_filter([$tenant === false ? null : (string) $tenant['name'], $eventDate, isset($metadata['version']) ? 'Version '.$metadata['version'] : null, 'Erstellt '.$created, $platform]));
        $logo = '';
        if ($tenant !== false && is_string($tenant['logo_storage_path']) && $tenant['logo_storage_path'] !== '') { $candidate = $this->projectDirectory.'/'.$tenant['logo_storage_path']; if (is_file($candidate)) { $logo = $candidate; } }
        $pdf->SetCreator($platform); $pdf->SetAuthor($tenant === false ? $platform : (string) $tenant['name']); $pdf->SetTitle((string) ($event['name'] ?? 'Veranstaltungsdokument'));
        $pdf->setPrintHeader(true); $pdf->setPrintFooter(true); $pdf->SetHeaderData($logo, $logo === '' ? 0 : 18, (string) ($event['name'] ?? 'Veranstaltung'), $details); $pdf->setHeaderMargin(7); $pdf->setFooterMargin(8); $pdf->SetMargins(12, 28, 12); $pdf->SetAutoPageBreak(true, 15);
        $pdf->setFooterData([90, 90, 90], [230, 230, 230]);
    }
}
