<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Security;

use App\Core\Domain\Tenant\TenantRole;
use App\Core\Infrastructure\Doctrine\Entity\PlatformAdmin;
use App\Core\Infrastructure\Doctrine\Entity\Tenant;
use App\Core\Infrastructure\Doctrine\Entity\TenantUser;

/**
 * Transient tenant identity used only while an audited support session is active.
 * It is deliberately never persisted as a tenant user.
 */
final class SupportTenantUser extends TenantUser
{
    public function __construct(Tenant $tenant, private readonly PlatformAdmin $platformAdmin)
    {
        parent::__construct(
            $tenant,
            'support-'.$platformAdmin->getPublicId(),
            $platformAdmin->getEmail(),
            $platformAdmin->getDisplayName().' (Support)',
            TenantRole::Administrator,
            '',
            true,
            true,
        );
    }

    public function getPlatformAdmin(): PlatformAdmin
    {
        return $this->platformAdmin;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return [...parent::getRoles(), 'ROLE_SUPPORT_IMPERSONATION'];
    }

    public function requiresTwoFactor(): bool
    {
        return false;
    }

    public function hasTwoFactor(): bool
    {
        return false;
    }
}
