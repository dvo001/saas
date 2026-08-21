<?php

declare(strict_types=1);

namespace App\Tests\Core\Infrastructure\Tenancy;

use App\Core\Domain\Tenant\TenantRole;
use App\Core\Domain\Tenant\TenantStatus;
use App\Core\Domain\Tenant\TrialModule;
use App\Core\Infrastructure\Doctrine\Entity\Tenant;
use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Tenancy\TenantContext;
use PHPUnit\Framework\TestCase;

final class TenantIsolationTest extends TestCase
{
    public function testSameEmailMayBelongToDifferentTenantsButContextCannotSwitch(): void
    {
        $tenantA = $this->tenant('a', 'Verein A');
        $tenantB = $this->tenant('b', 'Verein B');
        $userA = new TenantUser($tenantA, '10000000-0000-7000-8000-000000000001', 'person@example.ch', 'Person A', TenantRole::ReadOnly, 'hash');
        $userB = new TenantUser($tenantB, '10000000-0000-7000-8000-000000000002', 'person@example.ch', 'Person B', TenantRole::ReadOnly, 'hash');

        self::assertSame($userA->getEmail(), $userB->getEmail());
        self::assertNotSame($userA->getUserIdentifier(), $userB->getUserIdentifier());
        self::assertNotSame($userA->getTenant()->getPublicId(), $userB->getTenant()->getPublicId());

        $context = new TenantContext();
        $context->set($tenantA);
        $this->expectException(\LogicException::class);
        $context->set($tenantB);
    }

    public function testMigrationScopesEveryOperationalTableToTenant(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 4).'/migrations/Version20260821010000.php');
        self::assertIsString($migration);

        foreach (['tenant_users', 'tenant_auth_tokens', 'events', 'event_user_assignments', 'owner_transfers'] as $table) {
            self::assertMatchesRegularExpression('/CREATE TABLE '.preg_quote($table, '/').' \(.*?tenant_id INT NOT NULL/s', $migration, $table.' must require tenant_id');
        }
        self::assertStringContainsString('UNIQUE INDEX uniq_tenant_user_email (tenant_id, email)', $migration);
    }

    private function tenant(string $slug, string $name): Tenant
    {
        return new Tenant(
            '10000000-0000-7000-8000-00000000000'.$slug,
            $name,
            $slug,
            TenantStatus::Trial,
            TrialModule::Running,
            'v1',
            new \DateTimeImmutable(),
        );
    }
}
