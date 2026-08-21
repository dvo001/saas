<?php

declare(strict_types=1);

namespace App\Core\Domain\Tenant;

use App\Core\Infrastructure\Doctrine\Entity\Tenant;

interface TenantOwnedEntity
{
    public function getTenant(): Tenant;
}
