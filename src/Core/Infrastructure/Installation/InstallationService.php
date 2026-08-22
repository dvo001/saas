<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Installation;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Symfony\Component\Uid\Uuid;

final readonly class InstallationService
{
    public function __construct(
        private string $projectDirectory,
        private EnvironmentWriter $environmentWriter,
        private InstallationState $state,
    ) {
    }

    public function install(InstallationInput $input): void
    {
        if (!$this->state->isInstallerAvailable()) {
            throw new \RuntimeException('Der Installer ist gesperrt.');
        }

        $this->assertDatabaseConnection($input->database);
        $this->environmentWriter->write($input, true);

        $connectionParameters = (new DsnParser(['mysql' => 'pdo_mysql']))->parse($input->database->databaseUrl());
        $connection = DriverManager::getConnection($connectionParameters);
        try {
            $this->runMigrations($connection);
            $this->writeInitialData($connection, $input);
            $this->environmentWriter->write($input, false);
            $this->writeLock();
        } finally {
            $connection->close();
        }
    }

    private function assertDatabaseConnection(DatabaseConfiguration $database): void
    {
        try {
            $pdo = new \PDO($database->pdoDsn(), $database->username, $database->password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 5,
            ]);
            $pdo->query('SELECT 1');
        } catch (\PDOException $exception) {
            throw new \RuntimeException('Die Datenbankverbindung konnte nicht hergestellt werden.', 0, $exception);
        }
    }

    private function runMigrations(Connection $connection): void
    {
        $configuration = new Configuration();
        $configuration->addMigrationsDirectory('DoctrineMigrations', $this->projectDirectory . '/migrations');
        // MariaDB implicitly commits DDL, so a global migration transaction would
        // leave DBAL's transaction state out of sync with the server.
        $configuration->setAllOrNothing(false);

        $factory = DependencyFactory::fromConnection(
            new ExistingConfiguration($configuration),
            new ExistingConnection($connection),
        );
        $factory->getMetadataStorage()->ensureInitialized();
        $version = $factory->getVersionAliasResolver()->resolveVersionAlias('latest');
        $plan = $factory->getMigrationPlanCalculator()->getPlanUntilVersion($version);

        if (count($plan) > 0) {
            $factory->getMigrator()->migrate(
                $plan,
                (new MigratorConfiguration())->setAllOrNothing(false),
            );
        }
    }

    private function writeInitialData(Connection $connection, InstallationInput $input): void
    {
        if ((int) $connection->fetchOne('SELECT COUNT(*) FROM platform_admins') > 0) {
            throw new \RuntimeException('Es existiert bereits ein Plattformadmin. Die Installation wurde nicht überschrieben.');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $connection->beginTransaction();
        try {
            $connection->insert('platform_admins', [
                'public_id' => Uuid::v7()->toRfc4122(),
                'email' => $input->adminEmail,
                'password' => password_hash($input->adminPassword, PASSWORD_DEFAULT),
                'roles' => json_encode(['ROLE_PLATFORM_ADMIN'], JSON_THROW_ON_ERROR),
                'active' => 1,
                'created_at' => $now->format('Y-m-d H:i:s'),
            ]);

            foreach ($this->defaultSettings($input) as $key => $value) {
                $connection->insert('platform_settings', [
                    'setting_key' => $key,
                    'value' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'valid_from' => $now->format('Y-m-d H:i:s'),
                    'created_at' => $now->format('Y-m-d H:i:s'),
                ]);
            }
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function defaultSettings(InstallationInput $input): array
    {
        return [
            'platform.name' => $input->platformName,
            'platform.base_domain' => rtrim($input->baseDomain, '/'),
            'platform.operator' => ['name' => '', 'address' => '', 'email' => ''],
            'mail.system_sender' => $input->mailFrom,
            'billing.vat' => ['enabled' => false, 'number' => null, 'standard_rate' => null],
            'billing.invoice_prefix' => 'RE',
            'billing.qr' => ['iban' => null, 'creditor' => null],
            'subscription.trial_days' => 14,
            'subscription.retention_days' => 90,
            'billing.payment_term_days' => 30,
            'billing.reminder_grace_days' => 10,
            'cron.intervals' => ['dispatcher_minutes' => 5, 'mail_minutes' => 5, 'cleanup_hours' => 24],
            'uploads.logo_max_pixels' => 2400,
            'modules.defaults' => [],
        ];
    }

    private function writeLock(): void
    {
        $payload = json_encode([
            'installed_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
            'version' => 1,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        if (file_put_contents($this->state->lockPath(), $payload . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('Die Installationssperre konnte nicht geschrieben werden.');
        }
        @chmod($this->state->lockPath(), 0600);
    }
}
