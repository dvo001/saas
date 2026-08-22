<?php

declare(strict_types=1);

namespace App\Tests\Core\Infrastructure\Tenancy;

use App\Core\Application\Registration\RegistrationService;
use App\Core\Domain\Tenant\EventRole;
use App\Core\Domain\Tenant\TenantRole;
use App\Core\Domain\Tenant\TenantStatus;
use App\Core\Domain\Tenant\TrialModule;
use App\Core\Infrastructure\Doctrine\Entity\Tenant;
use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Doctrine\Repository\TenantUserRepository;
use App\Core\Infrastructure\Tenancy\EventAccess;
use App\Core\Infrastructure\Security\OneTimeTokenStore;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

#[Group('database')]
final class TenantDatabaseIsolationTest extends KernelTestCase
{
    public function testOrmFilterPreventsCrossTenantReadsWithSameEmail(): void
    {
        if (getenv('RUN_DATABASE_TESTS') !== '1') {
            self::markTestSkipped('Set RUN_DATABASE_TESTS=1 with an isolated migrated MariaDB to run this merge-blocking test.');
        }

        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $connection = $container->get(Connection::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(Connection::class, $connection);

        $suffix = substr(str_replace('-', '', Uuid::v7()->toRfc4122()), -10);
        $tenantA = $this->tenant('isolation-a-'.$suffix, 'Isolation A '.$suffix);
        $tenantB = $this->tenant('isolation-b-'.$suffix, 'Isolation B '.$suffix);
        $email = 'same-'.$suffix.'@example.ch';
        $userA = new TenantUser($tenantA, Uuid::v7()->toRfc4122(), $email, 'Tenant A', TenantRole::ReadOnly, 'hash', true, true);
        $userB = new TenantUser($tenantB, Uuid::v7()->toRfc4122(), $email, 'Tenant B', TenantRole::ReadOnly, 'hash', true, true);

        $entityManager->persist($tenantA);
        $entityManager->persist($tenantB);
        $entityManager->persist($userA);
        $entityManager->persist($userB);
        $entityManager->flush();
        $entityManager->clear();

        $entityManager->getFilters()->enable('tenant')->setParameter('tenant_id', $tenantA->getId());
        $repository = $entityManager->getRepository(TenantUser::class);
        self::assertInstanceOf(TenantUserRepository::class, $repository);
        $visibleUsers = $repository->findBy(['email' => $email]);
        self::assertCount(1, $visibleUsers);
        self::assertSame('Tenant A', $visibleUsers[0]->getDisplayName());

        $entityManager->getFilters()->disable('tenant');
        $connection->executeStatement('DELETE FROM tenants WHERE slug IN (:a, :b)', ['a' => 'isolation-a-'.$suffix, 'b' => 'isolation-b-'.$suffix]);
    }

    public function testRegistrationCreatesPendingTenantUserAndSevenDayToken(): void
    {
        if (getenv('RUN_DATABASE_TESTS') !== '1') {
            self::markTestSkipped('Set RUN_DATABASE_TESTS=1 with an isolated migrated MariaDB to run this merge-blocking test.');
        }

        self::bootKernel();
        $container = self::getContainer();
        $registrations = $container->get(RegistrationService::class);
        $connection = $container->get(Connection::class);
        self::assertInstanceOf(RegistrationService::class, $registrations);
        self::assertInstanceOf(Connection::class, $connection);
        $suffix = substr(str_replace('-', '', Uuid::v7()->toRfc4122()), -10);
        $slug = 'registration-'.$suffix;
        $tenant = $registrations->register([
            'club_name' => 'Registration '.$suffix,
            'slug' => $slug,
            'module' => TrialModule::Football->value,
            'display_name' => 'Test Owner',
            'email' => 'owner-'.$suffix.'@example.ch',
            'password' => 'Sicheres-Testpasswort!2026',
        ], '127.0.0.1');

        self::assertSame(TenantStatus::PendingConfirmation, $tenant->getStatus());
        self::assertSame(0, (int) $connection->fetchOne('SELECT email_confirmed FROM tenant_users WHERE tenant_id = :tenant', ['tenant' => $tenant->getId()]));
        $hours = (int) $connection->fetchOne('SELECT TIMESTAMPDIFF(HOUR, created_at, expires_at) FROM tenant_auth_tokens WHERE tenant_id = :tenant AND token_type = :type', ['tenant' => $tenant->getId(), 'type' => 'registration_confirmation']);
        self::assertGreaterThanOrEqual(167, $hours);
        self::assertLessThanOrEqual(168, $hours);

        $ownerId = (int) $connection->fetchOne('SELECT id FROM tenant_users WHERE tenant_id = :tenant', ['tenant' => $tenant->getId()]);
        $confirmationToken = (new OneTimeTokenStore($connection))->issue(
            $tenant->getId() ?? throw new \LogicException('Missing tenant id.'),
            $ownerId,
            'registration_confirmation',
            new \DateTimeImmutable('+10 minutes', new \DateTimeZone('UTC')),
        );
        $confirmedTenant = $registrations->confirm($confirmationToken, '127.0.0.1');
        self::assertSame(TenantStatus::Trial, $confirmedTenant->getStatus());
        self::assertSame(1209600, (int) $connection->fetchOne('SELECT TIMESTAMPDIFF(SECOND, trial_starts_at, trial_ends_at) FROM tenants WHERE id = :tenant', ['tenant' => $tenant->getId()]));
        try {
            $registrations->confirm($confirmationToken, '127.0.0.1');
            self::fail('A confirmation token must be single-use.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }

        $connection->delete('tenants', ['id' => $tenant->getId()]);
    }

    public function testDatabaseRejectsCrossTenantEventAssignment(): void
    {
        if (getenv('RUN_DATABASE_TESTS') !== '1') {
            self::markTestSkipped('Set RUN_DATABASE_TESTS=1 with an isolated migrated MariaDB to run this merge-blocking test.');
        }

        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $connection = $container->get(Connection::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(Connection::class, $connection);
        $suffix = substr(str_replace('-', '', Uuid::v7()->toRfc4122()), -10);
        $tenantA = $this->tenant('fk-a-'.$suffix, 'FK A '.$suffix);
        $tenantB = $this->tenant('fk-b-'.$suffix, 'FK B '.$suffix);
        $userA = new TenantUser($tenantA, Uuid::v7()->toRfc4122(), 'manager-'.$suffix.'@example.ch', 'Event Manager', TenantRole::EventManager, 'hash', true, true);
        $userB = new TenantUser($tenantB, Uuid::v7()->toRfc4122(), 'foreign-'.$suffix.'@example.ch', 'Foreign User', TenantRole::ReadOnly, 'hash', true, true);
        $entityManager->persist($tenantA);
        $entityManager->persist($tenantB);
        $entityManager->persist($userA);
        $entityManager->persist($userB);
        $entityManager->flush();
        $eventPublicId = Uuid::v7()->toRfc4122();
        $moduleId = (int) $connection->fetchOne("SELECT id FROM sport_modules WHERE code = 'running_event'");
        $connection->insert('events', [
            'tenant_id' => $tenantA->getId(),
            'public_id' => $eventPublicId,
            'primary_event_manager_id' => $userA->getId(),
            'module_id' => $moduleId,
            'name' => 'Isolation Event',
            'status' => 'draft',
            'starts_on' => gmdate('Y-m-d'),
            'ends_on' => gmdate('Y-m-d'),
            'configuration' => '{}',
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $eventId = (int) $connection->lastInsertId();

        try {
            $connection->insert('event_user_assignments', [
                'tenant_id' => $tenantA->getId(),
                'event_id' => $eventId,
                'user_id' => $userB->getId(),
                'event_role' => 'read_only',
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            self::fail('The composite tenant foreign key must reject a user from another tenant.');
        } catch (ForeignKeyConstraintViolationException) {
            self::addToAssertionCount(1);
        } finally {
            $connection->executeStatement('DELETE FROM tenants WHERE slug IN (:a, :b)', ['a' => 'fk-a-'.$suffix, 'b' => 'fk-b-'.$suffix]);
        }
    }

    public function testEventRolesAreScopedPerEventAndTenant(): void
    {
        if (getenv('RUN_DATABASE_TESTS') !== '1') {
            self::markTestSkipped('Set RUN_DATABASE_TESTS=1 with an isolated migrated MariaDB to run this merge-blocking test.');
        }

        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $connection = $container->get(Connection::class);
        $access = $container->get(EventAccess::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(EventAccess::class, $access);
        $suffix = substr(str_replace('-', '', Uuid::v7()->toRfc4122()), -10);
        $tenantA = $this->tenant('role-a-'.$suffix, 'Role A '.$suffix);
        $tenantB = $this->tenant('role-b-'.$suffix, 'Role B '.$suffix);
        $owner = new TenantUser($tenantA, Uuid::v7()->toRfc4122(), 'owner-'.$suffix.'@example.ch', 'Owner', TenantRole::Owner, 'hash', true, true);
        $reader = new TenantUser($tenantA, Uuid::v7()->toRfc4122(), 'reader-'.$suffix.'@example.ch', 'Reader', TenantRole::ReadOnly, 'hash', true, true);
        $foreignReader = new TenantUser($tenantB, Uuid::v7()->toRfc4122(), 'foreign-reader-'.$suffix.'@example.ch', 'Foreign Reader', TenantRole::ReadOnly, 'hash', true, true);
        foreach ([$tenantA, $tenantB, $owner, $reader, $foreignReader] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();
        $eventPublicId = Uuid::v7()->toRfc4122();
        $moduleId = (int) $connection->fetchOne("SELECT id FROM sport_modules WHERE code = 'running_event'");
        $connection->insert('events', ['tenant_id' => $tenantA->getId(), 'public_id' => $eventPublicId, 'primary_event_manager_id' => $owner->getId(), 'module_id' => $moduleId, 'name' => 'Role Event', 'status' => 'draft', 'starts_on' => gmdate('Y-m-d'), 'ends_on' => gmdate('Y-m-d'), 'configuration' => '{}', 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);

        $access->assign($owner, $eventPublicId, $reader->getPublicId(), EventRole::ReadOnly, '127.0.0.1');
        self::assertTrue($access->canRead($reader, $eventPublicId));
        self::assertFalse($access->canManage($reader, $eventPublicId));
        self::assertFalse($access->canRead($foreignReader, $eventPublicId));
        try {
            $access->assign($owner, $eventPublicId, $foreignReader->getPublicId(), EventRole::ReadOnly, '127.0.0.1');
            self::fail('A foreign tenant user must never be assignable.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        } finally {
            $connection->executeStatement('DELETE FROM tenants WHERE slug IN (:a, :b)', ['a' => 'role-a-'.$suffix, 'b' => 'role-b-'.$suffix]);
        }
    }

    private function tenant(string $slug, string $name): Tenant
    {
        return new Tenant(Uuid::v7()->toRfc4122(), $name, $slug, TenantStatus::Trial, TrialModule::Running, 'v1', new \DateTimeImmutable());
    }
}
