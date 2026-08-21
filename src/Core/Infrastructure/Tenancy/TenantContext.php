<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Tenancy;

use App\Core\Infrastructure\Doctrine\Entity\Tenant;

final class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        if ($this->tenant !== null && $this->tenant->getPublicId() !== $tenant->getPublicId()) {
            throw new \LogicException('A request cannot switch its tenant context.');
        }

        $this->tenant = $tenant;
    }

    public function get(): Tenant
    {
        return $this->tenant ?? throw new \LogicException('No tenant is active for this request.');
    }

    public function getOrNull(): ?Tenant
    {
        return $this->tenant;
    }
}
