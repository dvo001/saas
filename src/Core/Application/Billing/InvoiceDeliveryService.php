<?php

declare(strict_types=1);

namespace App\Core\Application\Billing;

use App\Core\Application\Notification\NotificationService;
use App\Core\Infrastructure\Mail\SystemMailer;
use App\Core\Infrastructure\Security\AuditLogger;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class InvoiceDeliveryService
{
    public function __construct(private Connection $connection, private InvoiceDocumentService $documents, private SystemMailer $mailer, private NotificationService $notifications, private AuditLogger $audit) {}

    public function deliver(int $tenantId, string $invoicePublicId): void
    {
        $invoice = $this->connection->fetchAssociative('SELECT invoice_number, document_type, billing_snapshot FROM invoices WHERE tenant_id = :tenant AND public_id = :invoice', ['tenant' => $tenantId, 'invoice' => $invoicePublicId]);
        if ($invoice === false) { return; }
        try {
            $snapshot = json_decode((string) $invoice['billing_snapshot'], true, 512, JSON_THROW_ON_ERROR);
            $recipient = is_array($snapshot) ? (string) ($snapshot['invoice_email'] ?? '') : '';
            if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) { throw new \RuntimeException('Missing invoice recipient.'); }
            $path = $this->documents->pdfPathForTenant($tenantId, $invoicePublicId);
            $label = $invoice['document_type'] === 'credit_note' ? 'Gutschrift' : 'Rechnung';
            $this->mailer->send($recipient, $label.' '.$invoice['invoice_number'], 'Im Anhang finden Sie '.$label.' '.$invoice['invoice_number'].'.', 'invoice_document', $tenantId, null, $path, $invoice['invoice_number'].'.pdf');
            $this->audit->logSystem('invoice.delivery.succeeded', 'invoice', $invoicePublicId, ['tenant_id' => $tenantId]);
        } catch (\Throwable) {
            $reference = Uuid::v7()->toRfc4122();
            $this->notifications->notifyAdministrativeUsers($tenantId, 'invoice.delivery_failed', 'Rechnungsversand fehlgeschlagen', 'Die Rechnung bleibt gültig. Bitte Versand und PDF-Konfiguration prüfen. Referenz: '.$reference, 'invoice.delivery_failed:'.$invoicePublicId, 'danger');
            $this->audit->logSystem('invoice.delivery.failed', 'invoice', $invoicePublicId, ['tenant_id' => $tenantId, 'reference' => $reference]);
        }
    }
}
