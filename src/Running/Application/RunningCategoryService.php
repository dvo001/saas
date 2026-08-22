<?php

declare(strict_types=1);

namespace App\Running\Application;

use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Security\AuditLogger;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class RunningCategoryService
{
    public function __construct(private Connection $connection, private RunningEventGateway $events, private AuditLogger $audit) {}

    /** @return list<array<string, mixed>> */
    public function list(TenantUser $actor, string $eventPublicId): array
    {
        $event = $this->events->event($actor, $eventPublicId);
        return $this->connection->fetchAllAssociative('SELECT * FROM running_categories WHERE tenant_id = :tenant AND event_id = :event ORDER BY sort_order, name', ['tenant' => $event['tenant_id'], 'event' => $event['id']]);
    }

    public function create(TenantUser $actor, string $eventPublicId, string $name, int $yearFrom, int $yearTo, string $gender, string $ip): string
    {
        $event = $this->events->event($actor, $eventPublicId, true);
        $this->assertFinalistsUnlocked($event);
        if ($yearFrom > $yearTo || trim($name) === '' || !in_array($gender, ['female', 'male', 'other'], true)) { throw new \DomainException('Die Kategoriedaten sind ungültig.'); }
        if ($this->connection->fetchOne(<<<'SQL'
            SELECT 1 FROM running_categories
            WHERE tenant_id = :tenant AND event_id = :event AND gender = :gender AND active = 1
              AND NOT (birth_year_to < :year_from OR birth_year_from > :year_to)
            SQL, ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'gender' => $gender, 'year_from' => $yearFrom, 'year_to' => $yearTo]) !== false) {
            throw new \DomainException('Jahrgangsbereiche für dasselbe Geschlecht dürfen sich nicht überschneiden.');
        }
        $publicId = Uuid::v7()->toRfc4122(); $now = gmdate('Y-m-d H:i:s');
        $this->connection->insert('running_categories', ['tenant_id' => $event['tenant_id'], 'event_id' => $event['id'], 'public_id' => $publicId, 'name' => mb_substr(trim($name), 0, 120), 'birth_year_from' => $yearFrom, 'birth_year_to' => $yearTo, 'gender' => $gender, 'sort_order' => 0, 'active' => 1, 'created_at' => $now, 'updated_at' => $now, 'lock_version' => 1]);
        $this->synchronizeParticipants($event);
        $this->audit->log('running.category_created', 'running_category', $publicId, $actor->getTenant(), $actor, ['event' => $eventPublicId], $ip);
        return $publicId;
    }

    public function update(TenantUser $actor, string $eventPublicId, string $categoryPublicId, string $name, int $yearFrom, int $yearTo, string $gender, int $lockVersion, string $ip): void
    {
        $event = $this->events->event($actor, $eventPublicId, true);
        $this->assertFinalistsUnlocked($event);
        if ($yearFrom > $yearTo || trim($name) === '' || !in_array($gender, ['female', 'male', 'other'], true)) { throw new \DomainException('Die Kategoriedaten sind ungültig.'); }
        $category = $this->connection->fetchAssociative('SELECT id FROM running_categories WHERE tenant_id = :tenant AND event_id = :event AND public_id = :id', ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'id' => $categoryPublicId]);
        if ($category === false) { throw new \DomainException('Kategorie nicht gefunden.'); }
        if ($this->connection->fetchOne('SELECT 1 FROM running_categories WHERE tenant_id = :tenant AND event_id = :event AND id <> :id AND gender = :gender AND active = 1 AND NOT (birth_year_to < :year_from OR birth_year_from > :year_to)', ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'id' => $category['id'], 'gender' => $gender, 'year_from' => $yearFrom, 'year_to' => $yearTo]) !== false) { throw new \DomainException('Jahrgangsbereiche für dasselbe Geschlecht dürfen sich nicht überschneiden.'); }
        $affected = $this->connection->update('running_categories', ['name' => mb_substr(trim($name), 0, 120), 'birth_year_from' => $yearFrom, 'birth_year_to' => $yearTo, 'gender' => $gender, 'updated_at' => gmdate('Y-m-d H:i:s'), 'lock_version' => $lockVersion + 1], ['id' => $category['id'], 'tenant_id' => $event['tenant_id'], 'lock_version' => $lockVersion]);
        if ($affected !== 1) { throw new \DomainException('Die Kategorie wurde gleichzeitig geändert. Bitte neu laden.'); }
        $this->synchronizeParticipants($event); $this->audit->log('running.category_updated', 'running_category', $categoryPublicId, $actor->getTenant(), $actor, ['event' => $eventPublicId], $ip);
    }

    /** @param array<string, mixed> $event */
    public function synchronizeParticipants(array $event): int
    {
        $participants = $this->connection->fetchAllAssociative('SELECT id, birth_year, gender FROM event_participants WHERE tenant_id = :tenant AND event_id = :event', ['tenant' => $event['tenant_id'], 'event' => $event['id']]);
        $nextNumber = (int) $this->connection->fetchOne('SELECT COALESCE(MAX(start_number), 0) + 1 FROM running_participant_data WHERE tenant_id = :tenant AND event_id = :event', ['tenant' => $event['tenant_id'], 'event' => $event['id']]);
        $changed = 0; $now = gmdate('Y-m-d H:i:s');
        foreach ($participants as $participant) {
            $gender = match (mb_strtolower((string) $participant['gender'])) { 'w', 'weiblich', 'female', 'f' => 'female', 'm', 'männlich', 'maennlich', 'male' => 'male', default => 'other' };
            $category = $this->connection->fetchOne('SELECT id FROM running_categories WHERE tenant_id = :tenant AND event_id = :event AND active = 1 AND gender = :gender AND :year BETWEEN birth_year_from AND birth_year_to ORDER BY sort_order, id LIMIT 1', ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'gender' => $gender, 'year' => $participant['birth_year']]);
            $existing = $this->connection->fetchAssociative('SELECT category_id, lock_version FROM running_participant_data WHERE participant_id = :participant', ['participant' => $participant['id']]);
            if ($existing === false) {
                $this->connection->insert('running_participant_data', ['participant_id' => $participant['id'], 'tenant_id' => $event['tenant_id'], 'event_id' => $event['id'], 'category_id' => $category === false ? null : $category, 'start_number' => $nextNumber++, 'finalist_confirmed' => 0, 'final_start_order' => null, 'created_at' => $now, 'updated_at' => $now, 'lock_version' => 1]); ++$changed;
            } elseif ((string) ($existing['category_id'] ?? '') !== (string) ($category === false ? '' : $category)) {
                $affected = $this->connection->update('running_participant_data', ['category_id' => $category === false ? null : $category, 'updated_at' => $now, 'lock_version' => (int) $existing['lock_version'] + 1], ['participant_id' => $participant['id'], 'lock_version' => $existing['lock_version']]); if ($affected !== 1) { throw new \DomainException('Eine Teilnehmerzuordnung wurde gleichzeitig geändert. Bitte neu laden.'); } ++$changed;
            }
        }
        return $changed;
    }

    /** @param array<string, mixed> $event */
    private function assertFinalistsUnlocked(array $event): void
    {
        if ($this->connection->fetchOne('SELECT 1 FROM running_event_settings WHERE tenant_id = :tenant AND event_id = :event AND finalists_confirmed_at IS NOT NULL', ['tenant' => $event['tenant_id'], 'event' => $event['id']]) !== false) {
            throw new \DomainException('Kategorien sind nach Bestätigung der Finalisten gesperrt. Setzen Sie zuerst die Bestätigung zurück.');
        }
    }
}
