<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Security;

use App\Core\Infrastructure\Doctrine\Entity\PlatformAdmin;
use App\Core\Infrastructure\Doctrine\Repository\PlatformAdminRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/** @implements UserProviderInterface<PlatformAdmin> */
final readonly class PlatformAdminProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(private PlatformAdminRepository $admins, private EntityManagerInterface $entityManager) {}

    public function loadUserByIdentifier(string $identifier): PlatformAdmin
    {
        return $this->admins->findByEmail($identifier) ?? throw new UserNotFoundException();
    }

    public function refreshUser(UserInterface $user): PlatformAdmin
    {
        if (!$user instanceof PlatformAdmin) { throw new UnsupportedUserException(); }
        $fresh = $this->admins->findByPublicId($user->getPublicId());
        if ($fresh === null || $fresh->getAuthVersion() !== $user->getAuthVersion()) { throw new UserNotFoundException('The platform session is no longer valid.'); }

        return $fresh;
    }

    public function supportsClass(string $class): bool { return is_a($class, PlatformAdmin::class, true); }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof PlatformAdmin) { throw new UnsupportedUserException(); }
        $user->changePassword($newHashedPassword);
        $this->entityManager->flush();
    }
}
