<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Security;

use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Doctrine\Repository\TenantUserRepository;
use App\Core\Infrastructure\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/** @implements UserProviderInterface<TenantUser> */
final readonly class TenantUserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(
        private TenantContext $context,
        private TenantUserRepository $users,
        private EntityManagerInterface $entityManager,
    ) {}

    public function loadUserByIdentifier(string $identifier): TenantUser
    {
        $tenant = $this->context->get();
        if (str_contains($identifier, '|')) {
            [$tenantPublicId, $email] = explode('|', $identifier, 2);
            if (!hash_equals($tenant->getPublicId(), $tenantPublicId)) {
                throw new UserNotFoundException();
            }
            $identifier = $email;
        }
        $user = $this->users->findByTenantAndEmail($tenant, $identifier);

        return $user ?? throw new UserNotFoundException();
    }

    public function refreshUser(UserInterface $user): TenantUser
    {
        if (!$user instanceof TenantUser) {
            throw new UnsupportedUserException(sprintf('Unsupported user class "%s".', $user::class));
        }

        $fresh = $this->users->findByTenantAndPublicId($this->context->get(), $user->getPublicId());
        if ($fresh === null || $fresh->getAuthVersion() !== $user->getAuthVersion()) {
            throw new UserNotFoundException('The session is no longer valid.');
        }

        return $fresh;
    }

    public function supportsClass(string $class): bool
    {
        return is_a($class, TenantUser::class, true);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof TenantUser) {
            throw new UnsupportedUserException();
        }

        $user->changePassword($newHashedPassword);
        $this->entityManager->flush();
    }
}
