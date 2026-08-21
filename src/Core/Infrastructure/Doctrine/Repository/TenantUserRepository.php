<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Doctrine\Repository;

use App\Core\Infrastructure\Doctrine\Entity\Tenant;
use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<TenantUser> */
final class TenantUserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TenantUser::class);
    }

    public function findByTenantAndEmail(Tenant $tenant, string $email): ?TenantUser
    {
        return $this->findOneBy([
            'tenant' => $tenant,
            'email' => mb_strtolower(trim($email)),
        ]);
    }

    public function findByTenantAndPublicId(Tenant $tenant, string $publicId): ?TenantUser
    {
        return $this->findOneBy(['tenant' => $tenant, 'publicId' => $publicId]);
    }

    /** @return list<TenantUser> */
    public function findActiveAndInvitedByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.tenant = :tenant')
            ->andWhere('u.deletedAt IS NULL')
            ->setParameter('tenant', $tenant)
            ->orderBy('u.displayName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countActiveRole(Tenant $tenant, \App\Core\Domain\Tenant\TenantRole $role): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.tenant = :tenant')
            ->andWhere('u.tenantRole = :role')
            ->andWhere('u.active = true')
            ->andWhere('u.emailConfirmed = true')
            ->andWhere('u.deletedAt IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('role', $role)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
