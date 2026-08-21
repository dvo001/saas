<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Doctrine\Repository;

use App\Core\Infrastructure\Doctrine\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Tenant> */
final class TenantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tenant::class);
    }

    public function findBySlug(string $slug): ?Tenant
    {
        return $this->findOneBy(['slug' => mb_strtolower(trim($slug))]);
    }

    public function findByPublicId(string $publicId): ?Tenant
    {
        return $this->findOneBy(['publicId' => $publicId]);
    }

    /** @return list<Tenant> */
    public function findForPlatformAdministration(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }
}
