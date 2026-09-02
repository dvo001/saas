<?php

declare(strict_types=1);

namespace App\Tests\Core\Application\Platform;

use App\Core\Application\Platform\TenantAdministrationService;
use App\Core\Domain\Tenant\TenantStatus;
use App\Core\Domain\Tenant\TrialModule;
use App\Core\Infrastructure\Doctrine\Entity\PlatformAdmin;
use App\Core\Infrastructure\Doctrine\Entity\Tenant;
use App\Core\Infrastructure\Security\AuditLogger;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class TenantAdministrationServiceTest extends TestCase
{
    public function testRejectsActivationWithoutAUsefulReason(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $audit = new AuditLogger($this->createMock(Connection::class), 'test-secret');
        $service = new TenantAdministrationService($entityManager, $audit);
        $tenant = new Tenant('01900000-0000-7000-8000-000000000001', 'Testverein', 'testverein', TenantStatus::PendingConfirmation, TrialModule::Running, 'v1', new \DateTimeImmutable());
        $admin = new PlatformAdmin('01900000-0000-7000-8000-000000000002', 'admin@example.ch', 'hash', 'Admin');

        $this->expectException(\DomainException::class);
        $service->activatePendingRegistration($admin, $tenant, 'zu kurz', '127.0.0.1');
    }

    public function testRejectsActivationOfAnAlreadyActivatedTenant(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $audit = new AuditLogger($this->createMock(Connection::class), 'test-secret');
        $service = new TenantAdministrationService($entityManager, $audit);
        $tenant = new Tenant('01900000-0000-7000-8000-000000000003', 'Testverein', 'testverein', TenantStatus::Trial, TrialModule::Running, 'v1', new \DateTimeImmutable());
        $admin = new PlatformAdmin('01900000-0000-7000-8000-000000000004', 'admin@example.ch', 'hash', 'Admin');

        $this->expectException(\DomainException::class);
        $service->activatePendingRegistration($admin, $tenant, 'Manuelle Freigabe durch Support.', '127.0.0.1');
    }
}
