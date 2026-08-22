<?php

declare(strict_types=1);

namespace App\Tests\Core\Infrastructure\System;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class DocumentsExportSchemaTest extends TestCase
{
    public function testMigrationProvidesFinalDocumentsAndLegalRetention(): void
    {
        $migration = $this->source('migrations/Version20260821090000.php');
        foreach (['event_documents', 'logo_storage_path', 'retention_until', 'sha256', 'version_number', 'is_current'] as $required) { self::assertStringContainsString($required, $migration); }
        self::assertStringContainsString('INTERVAL 10 YEAR', $migration);
    }

    public function testTenantZipExportExcludesAuditAndRequiresReauthentication(): void
    {
        $builder = $this->source('src/Core/Application/Export/TenantExportBuilder.php');
        self::assertStringContainsString('audit_log_included', $builder);
        self::assertStringNotContainsString("'audit_log'", $builder);
        self::assertStringContainsString('event_documents', $builder);
        self::assertStringContainsString('payment_transactions', $builder);

        $controller = $this->source('src/Core/Presentation/Web/Controller/Tenant/TenantExportController.php');
        self::assertStringContainsString('isPasswordValid', $controller);
        self::assertStringContainsString('->verify(', $controller);
    }

    public function testInvoiceDeliveryKeepsFailuresSeparateFromInvoiceValidity(): void
    {
        $delivery = $this->source('src/Core/Application/Billing/InvoiceDeliveryService.php');
        self::assertStringContainsString('attach', $this->source('src/Core/Infrastructure/Mail/SystemMailer.php'));
        self::assertStringContainsString('invoice.delivery_failed', $delivery);
        self::assertStringContainsString('catch (\\Throwable)', $delivery);
    }

    public function testRetentionDeletesOperationalDataAndKeepsPermanentDeletionLog(): void
    {
        $runner = $this->source('src/Core/Application/Cron/CronJobRunner.php');
        foreach (['module_retention_expired', 'subscription_retention_expired', 'legal_retention_expired', 'deletion_log'] as $required) { self::assertStringContainsString($required, $runner); }
        self::assertStringContainsString('actor_platform_admin_id IS NULL', $runner);
    }

    private function source(string $relative): string
    {
        $source = file_get_contents(dirname(__DIR__, 4).'/'.$relative);
        self::assertIsString($source);
        return $source;
    }
}
