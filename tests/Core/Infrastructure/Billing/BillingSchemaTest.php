<?php

declare(strict_types=1);

namespace App\Tests\Core\Infrastructure\Billing;

use PHPUnit\Framework\TestCase;

final class BillingSchemaTest extends TestCase
{
    public function testTenantBillingTablesRequireTenantIdAndCompositeReferences(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 4).'/migrations/Version20260821030000.php');
        self::assertIsString($migration);

        foreach (['billing_profiles', 'subscriptions', 'subscription_modules', 'invoices', 'invoice_lines', 'payment_transactions'] as $table) {
            self::assertMatchesRegularExpression('/CREATE TABLE '.preg_quote($table, '/').' \(.*?tenant_id INT NOT NULL/s', $migration, $table.' must require tenant_id');
        }
        self::assertStringContainsString('FOREIGN KEY (tenant_id, subscription_id) REFERENCES subscriptions (tenant_id, id)', $migration);
        self::assertStringContainsString('FOREIGN KEY (tenant_id, invoice_id) REFERENCES invoices (tenant_id, id)', $migration);
    }

    public function testIssuedInvoicesContainFrozenCommercialValues(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 4).'/migrations/Version20260821030000.php');
        self::assertIsString($migration);

        foreach (['subtotal_minor', 'discount_minor', 'vat_rate_basis_points', 'vat_minor', 'total_minor', 'billing_snapshot', 'issued_at', 'due_at'] as $column) {
            self::assertStringContainsString($column, $migration);
        }
        self::assertStringContainsString('price_version_id BIGINT', $migration);
    }
}
