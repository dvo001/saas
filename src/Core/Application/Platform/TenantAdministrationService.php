<?php

declare(strict_types=1);

namespace App\Core\Application\Platform;

use App\Core\Domain\Tenant\TenantStatus;
use App\Core\Infrastructure\Doctrine\Entity\PlatformAdmin;
use App\Core\Infrastructure\Doctrine\Entity\Tenant;
use App\Core\Infrastructure\Security\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

final readonly class TenantAdministrationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditLogger $audit,
    ) {}

    public function activatePendingRegistration(PlatformAdmin $actor, Tenant $tenant, string $reason, string $ip): void
    {
        $reason = trim($reason);
        if ($tenant->getStatus() !== TenantStatus::PendingConfirmation) {
            throw new \DomainException('Nur eine noch unbestätigte Registrierung kann freigeschaltet werden.');
        }
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw new \DomainException('Die Begründung muss zwischen 10 und 500 Zeichen lang sein.');
        }

        $tenant->confirm(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->entityManager->flush();
        $this->audit->logPlatform('tenant.registration_activated', 'tenant', $tenant->getPublicId(), $actor, [
            'reason' => $reason,
            'new_status' => TenantStatus::Trial->value,
            'trial_days' => 14,
        ], $tenant, $ip);
    }
}
