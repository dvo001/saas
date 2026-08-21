<?php

declare(strict_types=1);

namespace App\Core\Application\Platform;

use App\Core\Infrastructure\Doctrine\Entity\PlatformAdmin;
use App\Core\Infrastructure\Doctrine\Repository\PlatformAdminRepository;
use App\Core\Infrastructure\Security\AuditLogger;
use App\Core\Infrastructure\Security\PasswordPolicy;
use App\Core\Infrastructure\Security\PlatformAdminTokenStore;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

final readonly class PlatformAdminService
{
    public function __construct(
        private PlatformAdminRepository $admins,
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private UserPasswordHasherInterface $passwordHasher,
        private PasswordPolicy $passwordPolicy,
        private PlatformAdminTokenStore $tokens,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urls,
        private AuditLogger $audit,
    ) {}

    public function invite(PlatformAdmin $actor, string $email, string $ip): void
    {
        if ($this->admins->findByEmail($email) !== null) { throw new \DomainException('Diese E-Mail-Adresse ist bereits vorhanden.'); }
        $admin = new PlatformAdmin(Uuid::v7()->toRfc4122(), $email, '', '', ['ROLE_PLATFORM_ADMIN'], true, false);
        $admin->changePassword($this->passwordHasher->hashPassword($admin, bin2hex(random_bytes(32))));
        $this->entityManager->persist($admin);
        $this->entityManager->flush();
        $token = $this->tokens->issue($admin->getId() ?? throw new \LogicException('Missing admin id.'), 'platform_invitation', new \DateTimeImmutable('+7 days', new \DateTimeZone('UTC')));
        $url = $this->urls->generate('platform_invitation_accept', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->mailer->send((new Email())->to($admin->getEmail())->subject('Einladung zur Plattformadministration')->text("Die Einladung ist 7 Tage gültig. 2FA ist obligatorisch:\n\n".$url));
        $this->audit->logPlatform('platform.admin.invited', 'platform_admin', $admin->getPublicId(), $actor, [], null, $ip);
    }

    public function accept(string $token, string $name, string $password, string $ip): PlatformAdmin
    {
        $violations = $this->passwordPolicy->violations($password);
        if (trim($name) === '') { $violations[] = 'Bitte einen Namen eingeben.'; }
        if ($violations !== []) { throw new \DomainException(implode(' ', $violations)); }

        return $this->connection->transactional(function () use ($token, $name, $password, $ip): PlatformAdmin {
            $row = $this->tokens->consume($token, 'platform_invitation');
            if ($row === null) { throw new \DomainException('Die Einladung ist ungültig, abgelaufen oder bereits verwendet.'); }
            $admin = $this->entityManager->find(PlatformAdmin::class, (int) $row['platform_admin_id']);
            if (!$admin instanceof PlatformAdmin) { throw new \DomainException('Das Plattformkonto wurde nicht gefunden.'); }
            $admin->confirmInvitation($name, $this->passwordHasher->hashPassword($admin, $password));
            $this->entityManager->flush();
            $this->audit->logPlatform('platform.admin.invitation_accepted', 'platform_admin', $admin->getPublicId(), $admin, [], null, $ip);

            return $admin;
        });
    }

    public function setActive(PlatformAdmin $actor, PlatformAdmin $target, bool $active, string $ip): void
    {
        if (!$active && $target->isActive() && $this->admins->countActiveConfirmed() <= 1) { throw new \DomainException('Der letzte aktive Plattformadmin kann nicht deaktiviert werden.'); }
        $active ? $target->reactivate() : $target->deactivate();
        $this->entityManager->flush();
        $this->audit->logPlatform($active ? 'platform.admin.reactivated' : 'platform.admin.deactivated', 'platform_admin', $target->getPublicId(), $actor, [], null, $ip);
    }

    public function unlock(PlatformAdmin $actor, PlatformAdmin $target, string $ip): void
    {
        if ($actor->getPublicId() === $target->getPublicId()) { throw new \DomainException('Ein gesperrter Plattformadmin darf sich nicht selbst entsperren.'); }
        $target->unlock();
        $this->entityManager->flush();
        $this->audit->logPlatform('platform.admin.unlocked', 'platform_admin', $target->getPublicId(), $actor, [], null, $ip);
    }

    public function delete(PlatformAdmin $actor, PlatformAdmin $target, string $password, string $ip): void
    {
        if ($target->isEmailConfirmed() && $this->admins->countConfirmed() <= 1) { throw new \DomainException('Der letzte verbleibende Plattformadmin kann nicht gelöscht werden.'); }
        if (!$this->passwordHasher->isPasswordValid($actor, $password)) { throw new \DomainException('Das aktuelle Passwort ist nicht korrekt.'); }
        $target->anonymize($this->passwordHasher->hashPassword($target, bin2hex(random_bytes(32))), new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->entityManager->flush();
        $this->audit->logPlatform('platform.admin.deleted', 'platform_admin', $target->getPublicId(), $actor, [], null, $ip);
    }
}
