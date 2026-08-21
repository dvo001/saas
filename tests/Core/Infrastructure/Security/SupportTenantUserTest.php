<?php

declare(strict_types=1);

namespace App\Tests\Core\Infrastructure\Security;

use App\Core\Domain\Tenant\TenantRole;
use App\Core\Domain\Tenant\TenantStatus;
use App\Core\Domain\Tenant\TrialModule;
use App\Core\Infrastructure\Doctrine\Entity\PlatformAdmin;
use App\Core\Infrastructure\Doctrine\Entity\Tenant;
use App\Core\Infrastructure\Security\SupportTenantUser;
use PHPUnit\Framework\TestCase;

final class SupportTenantUserTest extends TestCase
{
    public function testSupportIdentityIsTransientAdministratorWithExplicitRole(): void
    {
        $tenant = new Tenant(
            '10000000-0000-7000-8000-000000000001',
            'Turnverein Muster',
            'turnverein-muster',
            TenantStatus::Trial,
            TrialModule::Running,
            'v1',
            new \DateTimeImmutable(),
        );
        $admin = new PlatformAdmin(
            '20000000-0000-7000-8000-000000000001',
            'support@example.ch',
            'hash',
            'Support Person',
        );

        $support = new SupportTenantUser($tenant, $admin);

        self::assertSame(TenantRole::Administrator, $support->getTenantRole());
        self::assertContains('ROLE_SUPPORT_IMPERSONATION', $support->getRoles());
        self::assertSame($admin, $support->getPlatformAdmin());
        self::assertNull($support->getId(), 'The support identity must never be persisted as a tenant user.');
        self::assertFalse($support->requiresTwoFactor(), 'Platform 2FA has already been completed before support mode starts.');
    }

    public function testTenantOwnerCanRevokeSupportPermission(): void
    {
        $tenant = new Tenant(
            '10000000-0000-7000-8000-000000000002',
            'Sportclub Muster',
            'sportclub-muster',
            TenantStatus::Trial,
            TrialModule::Football,
            'v1',
            new \DateTimeImmutable(),
        );

        self::assertTrue($tenant->isSupportImpersonationEnabled());
        $tenant->setSupportImpersonationEnabled(false);
        self::assertFalse($tenant->isSupportImpersonationEnabled());
    }
}
