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
use Symfony\Component\Uid\Uuid;

final readonly class UserAdministrationService
{
    public function __construct(
        private TenantUserRepository $users,
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private UserPasswordHasherInterface $passwordHasher,
        private PasswordPolicy $passwordPolicy,
        private OneTimeTokenStore $tokens,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urls,
        private AuditLogger $audit,
    ) {}

    public function invite(TenantUser $actor, string $email, TenantRole $role, string $ip): void
    {
        $this->requireAdministrator($actor);
        if ($role === TenantRole::Owner) {
            throw new \DomainException('Owner-Rechte werden ausschliesslich über den Ownerwechsel übertragen.');
        }
        $tenant = $actor->getTenant();
        if ($this->users->findByTenantAndEmail($tenant, $email) !== null) {
            throw new \DomainException('Diese E-Mail-Adresse ist im Verein bereits vorhanden.');
        }

        $user = new TenantUser($tenant, Uuid::v7()->toRfc4122(), $email, $email, $role, '');
        $user->changePassword($this->passwordHasher->hashPassword($user, bin2hex(random_bytes(32))));
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $token = $this->tokens->issue($tenant->getId() ?? throw new \LogicException('Missing tenant id.'), $user->getId(), 'user_invitation', new \DateTimeImmutable('+7 days', new \DateTimeZone('UTC')));
        $url = $this->urls->generate('invitation_accept', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->mailer->send((new Email())->to($user->getEmail())->subject('Einladung zu '.$tenant->getName())->text("Die Einladung ist 7 Tage gültig:\n\n".$url));
        $this->audit->log('user.invited', 'tenant_user', $user->getPublicId(), $tenant, $actor, ['role' => $role->value], $ip);
    }

    public function acceptInvitation(string $rawToken, string $displayName, string $password, string $ip): TenantUser
    {
        $violations = $this->passwordPolicy->violations($password);
        if (trim($displayName) === '') {
            $violations[] = 'Bitte Vor- und Nachname eingeben.';
        }
        if ($violations !== []) {
            throw new \DomainException(implode(' ', $violations));
        }

        return $this->connection->transactional(function () use ($rawToken, $displayName, $password, $ip): TenantUser {
            $row = $this->tokens->consume($rawToken, 'user_invitation', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            if ($row === null) {
                throw new \DomainException('Die Einladung ist ungültig, abgelaufen oder wurde bereits verwendet.');
            }
            $user = $this->entityManager->find(TenantUser::class, (int) $row['user_id']);
            if (!$user instanceof TenantUser) {
                throw new \DomainException('Das eingeladene Konto wurde nicht gefunden.');
            }
            $user->rename($displayName);
            $user->changePassword($this->passwordHasher->hashPassword($user, $password));
            $user->confirmEmail();
            $this->entityManager->flush();
            $this->audit->log('user.invitation_accepted', 'tenant_user', $user->getPublicId(), $user->getTenant(), $user, [], $ip);

            return $user;
        });
    }

    public function setActive(TenantUser $actor, TenantUser $target, bool $active, string $ip): void
    {
        $this->requireSameTenantAdministrator($actor, $target);
        if ($target->getTenantRole() === TenantRole::Owner) {
            throw new \DomainException('Der Owner muss zuerst übertragen werden.');
        }
        if (!$active && $target->getTenantRole() === TenantRole::Administrator && $this->users->countActiveRole($actor->getTenant(), TenantRole::Administrator) <= 1) {
            throw new \DomainException('Der letzte aktive Administrator kann nicht deaktiviert werden.');
        }
        $active ? $target->reactivate() : $target->deactivate();
        $this->entityManager->flush();
        $this->audit->log($active ? 'user.reactivated' : 'user.deactivated', 'tenant_user', $target->getPublicId(), $actor->getTenant(), $actor, [], $ip);
    }

    public function unlock(TenantUser $actor, TenantUser $target, string $ip): void
    {
        $this->requireSameTenantAdministrator($actor, $target);
        if ($target->getTenantRole() === TenantRole::Owner) {
            throw new \DomainException('Owner entsperren sich über den sicheren E-Mail-Link.');
        }
        $target->unlock();
        $this->entityManager->flush();
        $this->audit->log('user.unlocked', 'tenant_user', $target->getPublicId(), $actor->getTenant(), $actor, [], $ip);
    }

    public function delete(TenantUser $actor, TenantUser $target, string $currentPassword, string $ip): void
    {
        $this->requireSameTenantAdministrator($actor, $target);
        if (!$this->passwordHasher->isPasswordValid($actor, $currentPassword)) {
            throw new \DomainException('Das aktuelle Passwort ist nicht korrekt.');
        }
        if ($target->getTenantRole() === TenantRole::Owner) {
            throw new \DomainException('Der Owner muss zuerst übertragen werden.');
        }
        if ($target->getTenantRole() === TenantRole::Administrator && $target->isActive() && $this->users->countActiveRole($actor->getTenant(), TenantRole::Administrator) <= 1) {
            throw new \DomainException('Der letzte aktive Administrator kann nicht gelöscht werden.');
        }
        $publicId = $target->getPublicId();
        $target->anonymize($this->passwordHasher->hashPassword($target, bin2hex(random_bytes(32))), new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->entityManager->flush();
        $this->audit->log('user.deleted_and_anonymised', 'tenant_user', $publicId, $actor->getTenant(), $actor, [], $ip);
    }

    private function requireAdministrator(TenantUser $actor): void
    {
        if (!in_array($actor->getTenantRole(), [TenantRole::Owner, TenantRole::Administrator], true)) {
            throw new \DomainException('Für diese Aktion fehlen die Berechtigungen.');
        }
    }

    private function requireSameTenantAdministrator(TenantUser $actor, TenantUser $target): void
    {
        $this->requireAdministrator($actor);
        if ($actor->getTenant()->getPublicId() !== $target->getTenant()->getPublicId()) {
            throw new \DomainException('Mandantenübergreifende Änderungen sind nicht erlaubt.');
        }
    }
}
