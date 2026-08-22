<?php

declare(strict_types=1);

namespace App\Tests\Core\Infrastructure\Tenancy;

use App\Core\Application\Export\TenantExportService;
use App\Core\Domain\Tenant\TenantRole;
use App\Core\Domain\Tenant\TenantStatus;
use App\Core\Domain\Tenant\TrialModule;
use App\Core\Infrastructure\Doctrine\Entity\Tenant;
use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

#[Group('database')]
final class TenantFileIsolationTest extends KernelTestCase
{
    public function testTenantExportCannotListOrDownloadAnotherTenantsJob(): void
    {
        if (getenv('RUN_DATABASE_TESTS') !== '1') {
            self::markTestSkipped('Set RUN_DATABASE_TESTS=1 with an isolated migrated MariaDB to run the file isolation tests.');
        }

        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $connection = self::getContainer()->get(Connection::class);
        $exports = self::getContainer()->get(TenantExportService::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(TenantExportService::class, $exports);
        $suffix = substr(str_replace('-', '', Uuid::v7()->toRfc4122()), -10);
        $tenantA = $this->tenant('file-a-'.$suffix, 'File A '.$suffix);
        $tenantB = $this->tenant('file-b-'.$suffix, 'File B '.$suffix);
        $ownerA = $this->owner($tenantA, 'owner-a-'.$suffix.'@example.ch');
        $ownerB = $this->owner($tenantB, 'owner-b-'.$suffix.'@example.ch');
        foreach ([$tenantA, $tenantB, $ownerA, $ownerB] as $entity) { $entityManager->persist($entity); }
        $entityManager->flush();
        $foreignJob = Uuid::v7()->toRfc4122();
        $connection->insert('export_jobs', [
            'tenant_id' => $tenantB->getId(), 'requested_by_user_id' => $ownerB->getId(), 'public_id' => $foreignJob,
            'export_type' => 'full_tenant_zip', 'status' => 'ready', 'storage_path' => 'storage/exports/foreign.zip',
            'error_reference' => null, 'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600), 'started_at' => null,
            'finished_at' => gmdate('Y-m-d H:i:s'), 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        try {
            self::assertSame([], $exports->listFor($ownerA));
            try {
                $exports->downloadPath($ownerA, $foreignJob, '127.0.0.1');
                self::fail('A foreign tenant export must never be downloadable.');
            } catch (\DomainException) {
                self::addToAssertionCount(1);
            }
        } finally {
            $connection->executeStatement('DELETE FROM tenants WHERE slug IN (:a, :b)', ['a' => $tenantA->getSlug(), 'b' => $tenantB->getSlug()]);
        }
    }

    private function tenant(string $slug, string $name): Tenant
    {
        return new Tenant(Uuid::v7()->toRfc4122(), $name, $slug, TenantStatus::Trial, TrialModule::Running, 'v1', new \DateTimeImmutable());
    }

    private function owner(Tenant $tenant, string $email): TenantUser
    {
        return new TenantUser($tenant, Uuid::v7()->toRfc4122(), $email, 'Owner', TenantRole::Owner, 'hash', true, true);
    }
}
