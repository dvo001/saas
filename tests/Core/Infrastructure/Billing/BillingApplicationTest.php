<?php

declare(strict_types=1);

namespace App\Tests\Core\Infrastructure\Billing;

use PHPUnit\Framework\TestCase;

final class BillingApplicationTest extends TestCase
{
    public function testBookingIsTransactionalAndFreezesInvoiceCommercialData(): void
    {
        $service = $this->source('src/Core/Application/Billing/SubscriptionBillingService.php');
        self::assertStringContainsString('->transactional(', $service);
        self::assertStringContainsString('FOR UPDATE', $service);
        foreach (['billing_snapshot', 'vat_rate_basis_points', 'discount_minor', 'price_version_id', 'reminder_due_at'] as $value) { self::assertStringContainsString($value, $service); }
    }

    public function testLifecycleContainsDunningSuspensionAndRetentionRules(): void
    {
        $service = $this->source('src/Core/Application/Billing/BillingLifecycleService.php');
        self::assertStringContainsString("status = 'overdue'", $service);
        self::assertStringContainsString("status = 'dunning'", $service);
        self::assertStringContainsString("status = 'suspended'", $service);
        self::assertStringContainsString('INTERVAL 90 DAY', $service);
    }

    public function testQrInvoiceUsesDedicatedSwissQrLibraryAndTenantScopedLookup(): void
    {
        $service = $this->source('src/Core/Application/Billing/InvoiceDocumentService.php');
        self::assertStringContainsString('Sprain\\SwissQrBill', $service);
        self::assertStringContainsString('i.tenant_id = :tenant AND i.public_id = :public_id', $service);
        self::assertStringContainsString('TcPdfOutput', $service);
    }

    private function source(string $relative): string
    {
        $source = file_get_contents(dirname(__DIR__, 4).'/'.$relative);
        self::assertIsString($source);
        return $source;
    }
}
