<?php

declare(strict_types=1);

namespace App\Core\Application\Registration;

use App\Core\Domain\Tenant\TenantRole;
use App\Core\Infrastructure\Doctrine\Entity\Tenant;
use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Doctrine\Repository\TenantUserRepository;
use App\Core\Infrastructure\Security\AuditLogger;
use App\Core\Infrastructure\Security\OneTimeTokenStore;
use App\Core\Infrastructure\Security\PasswordPolicy;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class AccountRecoveryService
{
    public function __construct(
        private TenantUserRepository $users,
        private OneTimeTokenStore $tokens,
        private Connection $connection,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private PasswordPolicy $passwordPolicy,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urls,
        private AuditLogger $audit,
    ) {}

    public function requestPasswordReset(Tenant $tenant, string $email, string $ip): void
    {
        $user = $this->users->findByTenantAndEmail($tenant, $email);
        if ($user === null || !$user->isActive() || !$user->isEmailConfirmed()) {
            return;
        }

        $token = $this->tokens->issue(
            $tenant->getId() ?? throw new \LogicException('Missing tenant id.'),
            $user->getId(),
            'password_reset',
            new \DateTimeImmutable('+60 minutes', new \DateTimeZone('UTC')),
        );
        $url = $this->urls->generate('password_reset', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->mailer->send((new Email())->to($user->getEmail())->subject('Passwort zurücksetzen')->text("Der einmal verwendbare Link ist 60 Minuten gültig:\n\n".$url));
        $this->audit->log('auth.password_reset_requested', 'tenant_user', $user->getPublicId(), $tenant, $user, [], $ip);
    }

    public function resetPassword(string $rawToken, string $password, string $ip): TenantUser
    {
        $violations = $this->passwordPolicy->violations($password);
        if ($violations !== []) {
            throw new \DomainException(implode(' ', $violations));
        }

        return $this->connection->transactional(function () use ($rawToken, $password, $ip): TenantUser {
            $row = $this->tokens->consume($rawToken, 'password_reset', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            if ($row === null) {
                throw new \DomainException('Der Link ist ungültig, abgelaufen oder wurde bereits verwendet.');
            }
            $user = $this->entityManager->find(TenantUser::class, (int) $row['user_id']);
            if (!$user instanceof TenantUser) {
                throw new \DomainException('Das Benutzerkonto wurde nicht gefunden.');
            }
            $user->changePassword($this->passwordHasher->hashPassword($user, $password));
            $user->unlock();
            $this->entityManager->flush();
            $this->audit->log('auth.password_reset_completed', 'tenant_user', $user->getPublicId(), $user->getTenant(), $user, [], $ip);

            return $user;
        });
    }

    public function requestOwnerUnlock(Tenant $tenant, string $email, string $ip): void
    {
        $user = $this->users->findByTenantAndEmail($tenant, $email);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($user === null || $user->getTenantRole() !== TenantRole::Owner || !$user->isLocked($now)) {
            return;
        }

        $token = $this->tokens->issue($tenant->getId() ?? throw new \LogicException('Missing tenant id.'), $user->getId(), 'owner_unlock', $now->add(new \DateInterval('PT60M')));
        $url = $this->urls->generate('owner_unlock', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->mailer->send((new Email())->to($user->getEmail())->subject('Owner-Konto entsperren')->text("Entsperren Sie Ihr Konto über diesen 60 Minuten gültigen Link:\n\n".$url));
        $this->audit->log('auth.owner_unlock_requested', 'tenant_user', $user->getPublicId(), $tenant, $user, [], $ip);
    }

    public function unlockOwner(string $rawToken, string $ip): TenantUser
    {
        return $this->connection->transactional(function () use ($rawToken, $ip): TenantUser {
            $row = $this->tokens->consume($rawToken, 'owner_unlock', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            if ($row === null) {
                throw new \DomainException('Der Link ist ungültig, abgelaufen oder wurde bereits verwendet.');
            }
            $user = $this->entityManager->find(TenantUser::class, (int) $row['user_id']);
            if (!$user instanceof TenantUser || $user->getTenantRole() !== TenantRole::Owner) {
                throw new \DomainException('Das Owner-Konto wurde nicht gefunden.');
            }
            $user->unlock();
            $this->entityManager->flush();
            $this->audit->log('auth.owner_unlocked', 'tenant_user', $user->getPublicId(), $user->getTenant(), $user, [], $ip);

            return $user;
        });
    }
}
