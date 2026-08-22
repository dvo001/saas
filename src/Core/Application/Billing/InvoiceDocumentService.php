<?php

declare(strict_types=1);

namespace App\Core\Application\Billing;

use App\Core\Infrastructure\Doctrine\Entity\Tenant;
use App\Core\Infrastructure\Settings\PlatformSettings;
use Doctrine\DBAL\Connection;
use Sprain\SwissQrBill\DataGroup\Element\AdditionalInformation;
use Sprain\SwissQrBill\DataGroup\Element\CreditorInformation;
use Sprain\SwissQrBill\DataGroup\Element\PaymentAmountInformation;
use Sprain\SwissQrBill\DataGroup\Element\PaymentReference;
use Sprain\SwissQrBill\DataGroup\Element\StructuredAddress;
use Sprain\SwissQrBill\PaymentPart\Output\TcPdfOutput\TcPdfOutput;
use Sprain\SwissQrBill\QrBill;
use Sprain\SwissQrBill\Reference\RfCreditorReferenceGenerator;
use Symfony\Component\Filesystem\Filesystem;

final readonly class InvoiceDocumentService
{
    public function __construct(private Connection $connection, private PlatformSettings $settings, private string $projectDirectory) {}

    public function pdfPath(Tenant $tenant, string $invoicePublicId): string
    {
        $tenantId = $tenant->getId() ?? throw new \LogicException('Missing tenant id.');
        $invoice = $this->connection->fetchAssociative('SELECT * FROM invoices WHERE tenant_id = :tenant AND public_id = :public_id', ['tenant' => $tenantId, 'public_id' => $invoicePublicId]);
        if ($invoice === false) { throw new \DomainException('Rechnung nicht gefunden.'); }
        if (is_string($invoice['pdf_storage_path']) && $invoice['pdf_storage_path'] !== '') {
            $existing = $this->projectDirectory.'/'.$invoice['pdf_storage_path'];
            if (is_file($existing)) { return $existing; }
        }
        $snapshot = json_decode((string) $invoice['billing_snapshot'], true, 512, JSON_THROW_ON_ERROR);
        $creditor = $this->settings->get('billing.creditor', []);
        if (!is_array($snapshot) || !is_array($creditor) || !$this->validCreditor($creditor)) { throw new \DomainException('Die QR-Rechnungsdaten sind noch nicht vollständig konfiguriert.'); }

        $qrBill = QrBill::create();
        $qrBill->setCreditor(StructuredAddress::createWithStreet((string) $creditor['name'], (string) $creditor['street'], null, (string) $creditor['postal_code'], (string) $creditor['city'], (string) $creditor['country_code']));
        $qrBill->setCreditorInformation(CreditorInformation::create((string) $creditor['iban']));
        $qrBill->setUltimateDebtor(StructuredAddress::createWithStreet((string) $snapshot['club_name'], (string) $snapshot['address_line'], null, (string) $snapshot['postal_code'], (string) $snapshot['city'], (string) $snapshot['country_code']));
        $qrBill->setPaymentAmountInformation(PaymentAmountInformation::create((string) $invoice['currency'], (float) ((int) $invoice['total_minor'] / 100)));
        $reference = RfCreditorReferenceGenerator::generate(str_replace(['GS-', '-'], ['C', ''], (string) $invoice['invoice_number']));
        $qrBill->setPaymentReference(PaymentReference::create(PaymentReference::TYPE_SCOR, $reference));
        $qrBill->setAdditionalInformation(AdditionalInformation::create('Rechnung '.$invoice['invoice_number']));
        if (count($qrBill->getViolations()) > 0) { throw new \DomainException('Die konfigurierten QR-Rechnungsdaten sind ungültig.'); }

        $lines = $this->connection->fetchAllAssociative('SELECT * FROM invoice_lines WHERE tenant_id = :tenant AND invoice_id = :invoice ORDER BY position', ['tenant' => $tenantId, 'invoice' => $invoice['id']]);
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->setPrintHeader(false); $pdf->setPrintFooter(false); $pdf->SetMargins(15, 15, 15); $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 18); $pdf->Cell(0, 10, $invoice['document_type'] === 'credit_note' ? 'Gutschrift' : 'Rechnung', 0, 1);
        $pdf->SetFont('helvetica', '', 10); $pdf->MultiCell(95, 5, $snapshot['club_name']."\n".$snapshot['address_line']."\n".$snapshot['postal_code'].' '.$snapshot['city'], 0, 'L');
        $pdf->SetXY(120, 25); $pdf->MultiCell(75, 5, "Nr.: {$invoice['invoice_number']}\nDatum: ".(new \DateTimeImmutable((string) $invoice['issued_at']))->format('d.m.Y')."\nFällig: ".(new \DateTimeImmutable((string) $invoice['due_at']))->format('d.m.Y'), 0, 'L');
        $pdf->SetY(65); $pdf->SetFont('helvetica', 'B', 10); $pdf->Cell(120, 7, 'Leistung'); $pdf->Cell(55, 7, 'Betrag', 0, 1, 'R'); $pdf->SetFont('helvetica', '', 10);
        foreach ($lines as $line) { $pdf->Cell(120, 7, (string) $line['description']); $pdf->Cell(55, 7, $this->money((int) $line['line_total_minor'], (string) $invoice['currency']), 0, 1, 'R'); }
        $pdf->Ln(3); $this->totalLine($pdf, 'Zwischensumme', (int) $invoice['subtotal_minor'], (string) $invoice['currency']);
        if ((int) $invoice['discount_minor'] > 0) { $this->totalLine($pdf, 'Rabatt', -(int) $invoice['discount_minor'], (string) $invoice['currency']); }
        $this->totalLine($pdf, 'MwSt. '.number_format((int) $invoice['vat_rate_basis_points'] / 100, 2).' %', (int) $invoice['vat_minor'], (string) $invoice['currency']);
        $pdf->SetFont('helvetica', 'B', 10); $this->totalLine($pdf, 'Total', (int) $invoice['total_minor'], (string) $invoice['currency']);
        (new TcPdfOutput($qrBill, 'de', $pdf))->getPaymentPart();

        $relative = 'storage/invoices/'.$tenant->getPublicId().'/'.$invoice['invoice_number'].'.pdf';
        $absolute = $this->projectDirectory.'/'.$relative;
        (new Filesystem())->mkdir(dirname($absolute), 0700); $pdf->Output($absolute, 'F');
        $this->connection->update('invoices', ['pdf_storage_path' => $relative, 'qr_payload' => $qrBill->getQrCode()->getText()], ['id' => $invoice['id'], 'tenant_id' => $tenantId]);

        return $absolute;
    }

    /** @param array<mixed> $value */
    private function validCreditor(array $value): bool { foreach (['name', 'street', 'postal_code', 'city', 'country_code', 'iban'] as $key) { if (!isset($value[$key]) || trim((string) $value[$key]) === '') { return false; } } return true; }
    private function money(int $minor, string $currency): string { return $currency.' '.number_format($minor / 100, 2, '.', "'"); }
    private function totalLine(\TCPDF $pdf, string $label, int $minor, string $currency): void { $pdf->Cell(120, 6, $label); $pdf->Cell(55, 6, $this->money($minor, $currency), 0, 1, 'R'); }
}
