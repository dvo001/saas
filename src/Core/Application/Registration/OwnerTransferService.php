<?php

declare(strict_types=1);

namespace App\Core\Application\Registration;

use App\Core\Domain\Tenant\TenantRole;
use App\Core\Infrastructure\Doctrine\Entity\Tenant;
use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Security\AuditLogger;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class OwnerTransferService
{
    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urls,
        private AuditLogger $audit,
    ) {}

    public function initiate(TenantUser $owner, TenantUser $target, string $password, string $ip): void
    {
        if ($owner->getTenantRole() !== TenantRole::Owner) {
            throw new \DomainException('Nur der Owner kann den Ownerwechsel starten.');
        }
        if ($owner->getTenant()->getPublicId() !== $target->getTenant()->getPublicId() || !$target->isActive() || !$target->isEmailConfirmed()) {
            throw new \DomainException('Das Zielkonto ist nicht für den Ownerwechsel verfügbar.');
        }
        if (!$this->passwordHasher->isPasswordValid($owner, $password)) {
            throw new \DomainException('Das aktuelle Passwort ist nicht korrekt.');
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->connection->transactional(function () use ($owner, $target, $token, $now): void {
            $this->connection->executeStatement('UPDATE owner_transfers SET cancelled_at = :now WHERE tenant_id = :tenant AND confirmed_at IS NULL AND cancelled_at IS NULL', [
                'now' => $now->format('Y-m-d H:i:s'),
                'tenant' => $owner->getTenant()->getId(),
            ]);
            $this->connection->insert('owner_transfers', [
                'tenant_id' => $owner->getTenant()->getId(),
                'initiated_by_user_id' => $owner->getId(),
                'target_user_id' => $target->getId(),
                'confirmation_token_hash' => hash('sha256', $token),
                'expires_at' => $now->add(new \DateInterval('P1D'))->format('Y-m-d H:i:s'),
                'confirmed_at' => null,
                'cancelled_at' => null,
                'created_at' => $now->format('Y-m-d H:i:s'),
            ]);
        });
        $url = $this->urls->generate('owner_transfer_confirm', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->mailer->send((new Email())->to($target->getEmail())->subject('Ownerwechsel bestätigen')->text("Bestätigen Sie den Ownerwechsel innert 24 Stunden:\n\n".$url));
        $this->audit->log('owner_transfer.initiated', 'tenant_user', $target->getPublicId(), $owner->getTenant(), $owner, [], $ip);
    }

    public function confirm(string $rawToken, string $ip): Tenant
    {
        return $this->connection->transactional(function () use ($rawToken, $ip): Tenant {
            $row = $this->connection->fetchAssociative(
                'SELECT * FROM owner_transfers WHERE confirmation_token_hash = :hash AND confirmed_at IS NULL AND cancelled_at IS NULL AND expires_at > :now FOR UPDATE',
                ['hash' => hash('sha256', $rawToken), 'now' => gmdate('Y-m-d H:i:s')],
            );
            if ($row === false) {
                throw new \DomainException('Der Ownerwechsel ist ungültig, abgelaufen oder bereits abgeschlossen.');
            }
            $tenant = $this->entityManager->find(Tenant::class, (int) $row['tenant_id']);
            $owner = $this->entityManager->find(TenantUser::class, (int) $row['initiated_by_user_id']);
            $target = $this->entityManager->find(TenantUser::class, (int) $row['target_user_id']);
            if (!$tenant instanceof Tenant || !$owner instanceof TenantUser || !$target instanceof TenantUser || !$target->isActive() || !$target->isEmailConfirmed()) {
                throw new \DomainException('Die beteiligten Konten sind nicht mehr für den Ownerwechsel verfügbar.');
            }
            if ($owner->getTenantRole() !== TenantRole::Owner) {
                throw new \DomainException('Der bisherige Owner hat sich inzwischen geändert.');
            }

            $owner->changeRole(TenantRole::Administrator);
            $target->changeRole(TenantRole::Owner);
            $this->entityManager->flush();
            $this->connection->update('owner_transfers', ['confirmed_at' => gmdate('Y-m-d H:i:s')], ['id' => $row['id']]);
            $this->audit->log('owner_transfer.confirmed', 'tenant_user', $target->getPublicId(), $tenant, $owner, ['previous_owner_public_id' => $owner->getPublicId()], $ip);

            return $tenant;
        });
    }
}
