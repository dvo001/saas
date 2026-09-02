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

    public function testModuleActivationRequiresCurrentPricingAndCoverageIsVisible(): void
    {
        $service = $this->source('src/Core/Application/Billing/BillingCatalogService.php');
        self::assertStringContainsString('priced_product_count', $service);
        self::assertStringContainsString("bp.product_type = 'sport_module' AND bp.active = 1", $service);
        self::assertStringContainsString('pv.valid_from <= UTC_TIMESTAMP()', $service);
        self::assertStringContainsString('kann erst aktiviert werden', $service);
        self::assertStringContainsString('Das letzte aktuell bepreiste Produkt', $service);

        $template = $this->source('templates/platform/billing/products.html.twig');
        foreach (['Produkt fehlt', 'Produkt deaktiviert', 'Preis fehlt', 'Preis erst zukünftig gültig', 'Buchbar'] as $status) {
            self::assertStringContainsString($status, $template);
        }
    }

    public function testRunningSubscriptionCanReceiveFullPriceAddOnUntilCommonEnd(): void
    {
        $service = $this->source('src/Core/Application/Billing/SubscriptionBillingService.php');
        self::assertStringContainsString('public function addOn(', $service);
        self::assertStringContainsString("'module_role' => 'addon'", $service);
        self::assertStringContainsString('policy->addOnEnd(', $service);
        self::assertStringContainsString("'full_price' => true", $service);
        self::assertStringContainsString('Dieses Sportmodul ist im laufenden Abo bereits enthalten.', $service);
        self::assertStringContainsString("status IN ('active', 'cancelled')", $service);

        $controller = $this->source('src/Core/Presentation/Web/Controller/Tenant/TenantBillingController.php');
        self::assertStringContainsString("name: 'tenant_billing_addon'", $controller);
    }

    public function testOnlyUnusedProductsCanBeDeletedByPlatformAdmin(): void
    {
        $service = $this->source('src/Core/Application/Billing/BillingCatalogService.php');
        self::assertStringContainsString('public function deleteProduct(', $service);
        self::assertStringContainsString('subscription_modules smod', $service);
        self::assertStringContainsString('invoice_lines il', $service);
        self::assertStringContainsString('billing.product.deleted', $service);
        self::assertStringContainsString('Deaktivieren Sie es stattdessen.', $service);

        $controller = $this->source('src/Core/Presentation/Web/Controller/Platform/PlatformBillingCatalogController.php');
        self::assertStringContainsString("name: 'platform_billing_product_delete'", $controller);
    }

    public function testInactiveProductsAreHiddenUnlessExplicitlyRequested(): void
    {
        $service = $this->source('src/Core/Application/Billing/BillingCatalogService.php');
        self::assertStringContainsString('products(bool $includeInactive = false)', $service);
        self::assertStringContainsString(':include_inactive = 1 OR bp.active = 1', $service);

        $template = $this->source('templates/platform/billing/products.html.twig');
        self::assertStringContainsString('Mit deaktivierten', $template);
        self::assertStringContainsString('name="mit_deaktivierten"', $template);
    }

    private function source(string $relative): string
    {
        $source = file_get_contents(dirname(__DIR__, 4).'/'.$relative);
        self::assertIsString($source);
        return $source;
    }
}
