<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Doctrine\Repository;

use App\Core\Infrastructure\Doctrine\Entity\PlatformAdmin;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PlatformAdmin> */
final class PlatformAdminRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, PlatformAdmin::class); }

    public function findByEmail(string $email): ?PlatformAdmin { return $this->findOneBy(['email' => mb_strtolower(trim($email)), 'deletedAt' => null]); }
    public function findByPublicId(string $publicId): ?PlatformAdmin { return $this->findOneBy(['publicId' => $publicId, 'deletedAt' => null]); }
    /** @return list<PlatformAdmin> */
    public function findCurrent(): array { return $this->findBy(['deletedAt' => null], ['displayName' => 'ASC']); }
    public function countActiveConfirmed(): int { return $this->count(['active' => true, 'emailConfirmed' => true, 'deletedAt' => null]); }
    public function countConfirmed(): int { return $this->count(['emailConfirmed' => true, 'deletedAt' => null]); }
}
