<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Tenancy;

use App\Core\Domain\Tenant\EventRole;
use App\Core\Domain\Tenant\TenantRole;
use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Doctrine\Repository\TenantUserRepository;
use App\Core\Infrastructure\Security\AuditLogger;
use Doctrine\DBAL\Connection;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final readonly class EventAccess
{
    public function __construct(
        private Connection $connection,
        private TenantUserRepository $users,
        private AuditLogger $audit,
        private MailerInterface $mailer,
    ) {}

    public function canRead(TenantUser $user, string $eventPublicId): bool
    {
        if ($this->isGlobalAdministrator($user)) {
            return $this->eventId($user, $eventPublicId) !== null;
        }

        return $this->hasAssignment($user, $eventPublicId, EventRole::cases());
    }

    public function canManage(TenantUser $user, string $eventPublicId): bool
    {
        if ($this->isGlobalAdministrator($user)) {
            return $this->eventId($user, $eventPublicId) !== null;
        }

        return $this->hasAssignment($user, $eventPublicId, [EventRole::EventManager]);
    }

    public function canEnterData(TenantUser $user, string $eventPublicId): bool
    {
        if ($this->isGlobalAdministrator($user)) {
            return $this->eventId($user, $eventPublicId) !== null;
        }

        return $this->hasAssignment($user, $eventPublicId, [EventRole::EventManager, EventRole::DataEntry]);
    }

    public function assign(TenantUser $actor, string $eventPublicId, string $targetPublicId, EventRole $role, string $ip): void
    {
        $this->requireGlobalAdministrator($actor);
        $eventId = $this->eventId($actor, $eventPublicId) ?? throw new \DomainException('Die Veranstaltung wurde nicht gefunden.');
        $target = $this->users->findByTenantAndPublicId($actor->getTenant(), $targetPublicId) ?? throw new \DomainException('Der Benutzer wurde nicht gefunden.');
        if (!$target->isActive() || !$target->isEmailConfirmed()) {
            throw new \DomainException('Nur aktive Benutzer können zugewiesen werden.');
        }

        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO event_user_assignments (tenant_id, event_id, user_id, event_role, created_at)
            VALUES (:tenant, :event, :user, :role, :created)
            ON DUPLICATE KEY UPDATE event_role = VALUES(event_role)
            SQL, [
                'tenant' => $actor->getTenant()->getId(),
                'event' => $eventId,
                'user' => $target->getId(),
                'role' => $role->value,
                'created' => gmdate('Y-m-d H:i:s'),
            ]);
        $this->audit->log('event.user_assigned', 'event', $eventPublicId, $actor->getTenant(), $actor, ['user_public_id' => $targetPublicId, 'role' => $role->value], $ip);
    }

    public function changePrimaryManager(TenantUser $actor, string $eventPublicId, string $targetPublicId, string $ip): void
    {
        $this->requireGlobalAdministrator($actor);
        $tenantId = $actor->getTenant()->getId() ?? throw new \LogicException('Missing tenant id.');
        $eventId = $this->eventId($actor, $eventPublicId) ?? throw new \DomainException('Die Veranstaltung wurde nicht gefunden.');
        $target = $this->users->findByTenantAndPublicId($actor->getTenant(), $targetPublicId) ?? throw new \DomainException('Der Benutzer wurde nicht gefunden.');
        if (!$target->isActive() || !$target->isEmailConfirmed()) {
            throw new \DomainException('Nur aktive Benutzer können Event-Manager sein.');
        }

        $previousEmail = $this->connection->fetchOne('SELECT u.email FROM events e LEFT JOIN tenant_users u ON u.id = e.primary_event_manager_id AND u.tenant_id = e.tenant_id WHERE e.tenant_id = :tenant AND e.id = :event', ['tenant' => $tenantId, 'event' => $eventId]);
        $this->connection->transactional(function () use ($tenantId, $eventId, $target): void {
            $this->connection->executeStatement(<<<'SQL'
                INSERT INTO event_user_assignments (tenant_id, event_id, user_id, event_role, created_at)
                VALUES (:tenant, :event, :user, 'event_manager', :created)
                ON DUPLICATE KEY UPDATE event_role = 'event_manager'
                SQL, ['tenant' => $tenantId, 'event' => $eventId, 'user' => $target->getId(), 'created' => gmdate('Y-m-d H:i:s')]);
            $this->connection->update('events', ['primary_event_manager_id' => $target->getId()], ['tenant_id' => $tenantId, 'id' => $eventId]);
        });

        if (is_string($previousEmail) && $previousEmail !== $target->getEmail()) {
            $this->mailer->send((new Email())->to($previousEmail)->subject('Primäre Event-Leitung geändert')->text('Die primäre Event-Leitung wurde geändert.'));
        }
        $this->mailer->send((new Email())->to($target->getEmail())->subject('Sie sind primäre Event-Leitung')->text('Sie wurden als primäre Event-Leitung zugewiesen.'));
        $this->audit->log('event.primary_manager_changed', 'event', $eventPublicId, $actor->getTenant(), $actor, ['new_manager_public_id' => $targetPublicId], $ip);
    }

    /** @param list<EventRole> $roles */
    private function hasAssignment(TenantUser $user, string $eventPublicId, array $roles): bool
    {
        $values = array_map(static fn (EventRole $role): string => $role->value, $roles);
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $params = [$user->getTenant()->getId(), $eventPublicId, $user->getId(), ...$values];

        return $this->connection->fetchOne(<<<SQL
            SELECT 1 FROM event_user_assignments a
            JOIN events e ON e.id = a.event_id AND e.tenant_id = a.tenant_id
            WHERE a.tenant_id = ? AND e.public_id = ? AND a.user_id = ? AND a.event_role IN ($placeholders)
            LIMIT 1
            SQL, $params) !== false;
    }

    private function eventId(TenantUser $user, string $eventPublicId): ?int
    {
        $id = $this->connection->fetchOne('SELECT id FROM events WHERE tenant_id = :tenant AND public_id = :public_id', [
            'tenant' => $user->getTenant()->getId(),
            'public_id' => $eventPublicId,
        ]);

        return $id === false ? null : (int) $id;
    }

    private function isGlobalAdministrator(TenantUser $user): bool
    {
        return in_array($user->getTenantRole(), [TenantRole::Owner, TenantRole::Administrator], true);
    }

    private function requireGlobalAdministrator(TenantUser $user): void
    {
        if (!$this->isGlobalAdministrator($user)) {
            throw new \DomainException('Nur Owner oder Administratoren dürfen Event-Zuweisungen ändern.');
        }
    }
}
