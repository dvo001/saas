<?php

declare(strict_types=1);

namespace App\Tests\Core\Presentation\Web;

use App\Core\Domain\Tenant\TenantRole;
use App\Core\Domain\Tenant\TenantStatus;
use App\Core\Domain\Tenant\TrialModule;
use App\Core\Infrastructure\Doctrine\Entity\Tenant;
use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

#[Group('database')]
final class VersionOneHttpAcceptanceTest extends WebTestCase
{
    public function testRegistrationWizardCreatesAnIsolatedPendingTenant(): void
    {
        $this->requireDatabaseTests();
        $client = self::createClient();
        $lockPath = $this->markInstalled();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $suffix = substr(str_replace('-', '', Uuid::v7()->toRfc4122()), -10);
        $slug = 'http-registration-'.$suffix;

        try {
            $crawler = $client->request('GET', '/registrieren');
            self::assertResponseIsSuccessful();
            $client->submit($crawler->selectButton('Weiter')->form([
                'club_name' => 'HTTP Registration '.$suffix,
                'slug' => $slug,
                'module' => TrialModule::Running->value,
            ]));
            self::assertResponseIsSuccessful();

            $crawler = $client->getCrawler();
            $client->submit($crawler->selectButton('Weiter')->form([
                'display_name' => 'HTTP Owner',
                'email' => 'http-owner-'.$suffix.'@example.ch',
            ]));
            self::assertResponseIsSuccessful();

            $crawler = $client->getCrawler();
            $client->submit($crawler->selectButton('Registrierung absenden')->form([
                'password' => 'Sicheres-HTTP-Passwort!2026',
                'legal_accepted' => '1',
            ]));
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'E-Mail');
            self::assertSame('pending_confirmation', $connection->fetchOne('SELECT status FROM tenants WHERE slug = :slug', ['slug' => $slug]));
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM tenant_users u INNER JOIN tenants t ON t.id = u.tenant_id WHERE t.slug = :slug', ['slug' => $slug]));
        } finally {
            $connection->executeStatement('DELETE FROM tenants WHERE slug = :slug', ['slug' => $slug]);
            (new Filesystem())->remove($lockPath);
        }
    }

    public function testAuthenticatedUserCannotOpenAnotherTenantDashboard(): void
    {
        $this->requireDatabaseTests();
        $client = self::createClient();
        $lockPath = $this->markInstalled();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        self::assertInstanceOf(Connection::class, $connection);
        $suffix = substr(str_replace('-', '', Uuid::v7()->toRfc4122()), -10);
        $slugA = 'http-isolation-a-'.$suffix;
        $slugB = 'http-isolation-b-'.$suffix;
        $password = 'Sicheres-HTTP-Passwort!2026';
        $tenantA = $this->tenant($slugA, 'HTTP Isolation A '.$suffix);
        $tenantB = $this->tenant($slugB, 'HTTP Isolation B '.$suffix);
        $userA = new TenantUser($tenantA, Uuid::v7()->toRfc4122(), 'reader-'.$suffix.'@example.ch', 'Tenant A Reader', TenantRole::ReadOnly, '', true, true);
        $userA->changePassword($hasher->hashPassword($userA, $password));
        foreach ([$tenantA, $tenantB, $userA] as $entity) { $entityManager->persist($entity); }
        $entityManager->flush();

        try {
            $crawler = $client->request('GET', '/v/'.$slugA.'/login');
            $client->submit($crawler->selectButton('Anmelden')->form(['email' => $userA->getEmail(), 'password' => $password]));
            self::assertResponseRedirects('/v/'.$slugA);
            $client->followRedirect();
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'HTTP Isolation A');

            $client->request('GET', '/v/'.$slugB);
            self::assertNotSame(200, $client->getResponse()->getStatusCode());
            self::assertStringNotContainsString('HTTP Isolation B', (string) $client->getResponse()->getContent());
        } finally {
            $connection->executeStatement('DELETE FROM tenants WHERE slug IN (:a, :b)', ['a' => $slugA, 'b' => $slugB]);
            (new Filesystem())->remove($lockPath);
        }
    }

    private function markInstalled(): string
    {
        $lockPath = self::getContainer()->getParameter('app.install_lock_path');
        self::assertIsString($lockPath);
        (new Filesystem())->dumpFile($lockPath, "test\n");

        return $lockPath;
    }

    private function requireDatabaseTests(): void
    {
        if (getenv('RUN_DATABASE_TESTS') !== '1') {
            self::markTestSkipped('Set RUN_DATABASE_TESTS=1 with an isolated migrated MariaDB to run the V1 HTTP acceptance tests.');
        }
    }

    private function tenant(string $slug, string $name): Tenant
    {
        return new Tenant(Uuid::v7()->toRfc4122(), $name, $slug, TenantStatus::Trial, TrialModule::Running, 'v1', new \DateTimeImmutable());
    }
}
