<?php

declare(strict_types=1);

namespace App\Core\Application\Billing;

use App\Core\Domain\Tenant\TrialModule;
use App\Core\Infrastructure\Doctrine\Entity\Tenant;
use Doctrine\DBAL\Connection;

final readonly class LicenseService
{
    public function __construct(private Connection $connection) {}

    /** @return list<array{code: string, name: string}> */
    public function licensedModules(Tenant $tenant): array
    {
        $modules = $this->connection->fetchAllAssociative('SELECT code, name FROM sport_modules WHERE active = 1 ORDER BY name');

        return array_values(array_filter($modules, fn (array $module): bool => $this->isLicensed($tenant, (string) $module['code'])));
    }

    public function isLicensed(Tenant $tenant, string $moduleCode, ?\DateTimeImmutable $now = null): bool
    {
        $tenantId = $tenant->getId() ?? throw new \LogicException('Missing tenant id.');
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($this->connection->fetchOne('SELECT 1 FROM sport_modules WHERE code = :code AND active = 1', ['code' => $moduleCode]) === false) { return false; }
        $trialCode = match ($tenant->getTrialModule()) { TrialModule::Running => 'running_event', TrialModule::Football => 'football_tournament' };
        if ($tenant->getStatus()->value === 'trial' && $trialCode === $moduleCode) {
            return $this->connection->fetchOne('SELECT 1 FROM tenants WHERE id = :tenant AND trial_ends_at > :now', ['tenant' => $tenantId, 'now' => $now->format('Y-m-d H:i:s')]) !== false;
        }

        return $this->connection->fetchOne(<<<'SQL'
            SELECT 1 FROM subscription_modules smod
            INNER JOIN sport_modules sm ON sm.id = smod.module_id
            INNER JOIN subscriptions s ON s.id = smod.subscription_id AND s.tenant_id = smod.tenant_id
            WHERE smod.tenant_id = :tenant AND sm.code = :code AND smod.status = 'active'
              AND smod.starts_at <= :now AND smod.ends_at > :now
              AND s.status IN ('active', 'cancelled', 'temporary') AND s.starts_at <= :now AND s.ends_at > :now
            SQL, ['tenant' => $tenantId, 'code' => $moduleCode, 'now' => $now->format('Y-m-d H:i:s')]) !== false;
    }

    public function denyUnlessLicensed(Tenant $tenant, string $moduleCode): void
    {
        if (!$this->isLicensed($tenant, $moduleCode)) { throw new \DomainException('Zugriff verweigert'); }
    }
}
