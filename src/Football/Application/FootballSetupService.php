<?php

declare(strict_types=1);

namespace App\Football\Application;

use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Security\AuditLogger;
use App\Football\Domain\SchedulingStrategy;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class FootballSetupService
{
    public function __construct(private Connection $connection, private FootballEventGateway $events, private AuditLogger $audit) {}

    /** @return array<string, mixed> */
    public function data(TenantUser $actor, string $publicId): array
    {
        $event = $this->events->read($actor, $publicId); $this->events->initialize($event); $scope = ['tenant' => $event['tenant_id'], 'event' => $event['id']];
        return [
            'event' => $event,
            'settings' => $this->settings((int) $event['id']),
            'categories' => $this->connection->fetchAllAssociative('SELECT * FROM football_categories WHERE tenant_id = :tenant AND event_id = :event AND active = 1 ORDER BY sort_order, name', $scope),
            'groups' => $this->connection->fetchAllAssociative('SELECT g.*, c.name AS category_name FROM football_groups g JOIN football_categories c ON c.id = g.category_id WHERE g.tenant_id = :tenant AND g.event_id = :event ORDER BY c.sort_order, g.sort_order, g.name', $scope),
            'teams' => $this->connection->fetchAllAssociative('SELECT t.id, t.public_id, t.team_number, t.name, t.lock_version AS team_lock_version, d.category_id, d.group_id, d.withdrawn_at, d.lock_version, c.name AS category_name, g.name AS group_name, (SELECT COUNT(*) FROM event_team_memberships m WHERE m.tenant_id=t.tenant_id AND m.event_id=t.event_id AND m.team_id=t.id) AS roster_size FROM event_teams t LEFT JOIN football_team_data d ON d.team_id=t.id LEFT JOIN football_categories c ON c.id=d.category_id LEFT JOIN football_groups g ON g.id=d.group_id WHERE t.tenant_id=:tenant AND t.event_id=:event ORDER BY t.team_number', $scope),
            'fields' => $this->connection->fetchAllAssociative('SELECT * FROM football_fields WHERE tenant_id=:tenant AND event_id=:event AND active=1 ORDER BY sort_order,name', $scope),
            'periods' => $this->connection->fetchAllAssociative('SELECT p.*, f.name AS field_name FROM football_field_periods p JOIN football_fields f ON f.id=p.field_id WHERE p.tenant_id=:tenant AND p.event_id=:event ORDER BY f.sort_order,p.starts_at', $scope),
        ];
    }

    /** @param array<string, mixed> $input */
    public function configure(TenantUser $actor, string $publicId, array $input, string $ip): void
    {
        $event = $this->structureEvent($actor, $publicId); $this->events->initialize($event); $current = $this->settings((int) $event['id']);
        $win = (int) ($input['points_win'] ?? 3); $draw = (int) ($input['points_draw'] ?? 1); $loss = (int) ($input['points_loss'] ?? 0); $forfaitWin = (int) ($input['forfait_win'] ?? 3); $forfaitLoss = (int) ($input['forfait_loss'] ?? 0); $strategy = (string) ($input['strategy'] ?? 'field_utilization'); $expected = (int) ($input['lock_version'] ?? 0);
        if (min($win, $draw, $loss, $forfaitWin, $forfaitLoss) < 0 || max($win, $draw, $loss, $forfaitWin, $forfaitLoss) > 99 || SchedulingStrategy::tryFrom($strategy) === null) { throw new \DomainException('Die Turnierkonfiguration ist ungültig.'); }
        if ($expected !== (int) $current['lock_version']) { throw new \DomainException('Die Turnierkonfiguration wurde gleichzeitig geändert. Bitte neu laden.'); }
        $affected = $this->connection->update('football_event_settings', ['points_win' => $win, 'points_draw' => $draw, 'points_loss' => $loss, 'forfait_goals_winner' => $forfaitWin, 'forfait_goals_loser' => $forfaitLoss, 'scheduling_strategy' => $strategy, 'updated_at' => gmdate('Y-m-d H:i:s'), 'lock_version' => $expected + 1], ['event_id' => $event['id'], 'tenant_id' => $event['tenant_id'], 'lock_version' => $expected]);
        if ($affected !== 1) { throw new \DomainException('Die Turnierkonfiguration wurde gleichzeitig geändert. Bitte neu laden.'); }
        $this->draftPublications($event); $this->audit->log('football.configuration_changed', 'event', $publicId, $actor->getTenant(), $actor, [], $ip);
    }

    public function createSwissDefaults(TenantUser $actor, string $publicId, string $ip): void
    {
        $event = $this->structureEvent($actor, $publicId);
        $defaults = [
            ['Junioren A', 18, 19, 11, 20], ['Junioren B', 16, 17, 11, 20], ['Junioren C', 14, 15, 9, 18],
            ['Junioren D', 12, 13, 7, 15], ['Junioren E', 10, 11, 7, 15], ['Junioren F', 8, 9, 5, 12], ['Junioren G', 6, 7, 5, 10],
        ];
        foreach ($defaults as $sort => [$name, $ageMin, $ageMax, $players, $minutes]) {
            $exists = $this->connection->fetchOne('SELECT 1 FROM football_categories WHERE tenant_id=:tenant AND event_id=:event AND name=:name', ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'name' => $name]);
            if ($exists !== false) { continue; }
            $this->insertCategory($event, ['name' => $name, 'age_min' => $ageMin, 'age_max' => $ageMax, 'gender' => 'mixed', 'max_roster_size' => max(10, $players + 4), 'players_on_field' => $players, 'match_minutes' => $minutes, 'min_break_minutes' => $minutes, 'overtime_minutes' => 5, 'tournament_mode' => 'semifinal_final', 'group_size' => 4, 'third_place' => false, 'knockout_draw_mode' => 'penalties', 'qualify_winners' => 1, 'qualify_runners_up' => 1, 'qualify_best_thirds' => 0, 'exclude_last' => false, 'sort_order' => $sort]);
        }
        $this->draftPublications($event);
        $this->audit->log('football.swiss_categories_created', 'event', $publicId, $actor->getTenant(), $actor, ['source' => 'SFV age categories'], $ip);
    }

    /** @param array<string, mixed> $input */
    public function createCategory(TenantUser $actor, string $publicId, array $input, string $ip): string
    {
        $event = $this->structureEvent($actor, $publicId); $id = $this->insertCategory($event, $input);
        $this->draftPublications($event);
        $this->audit->log('football.category_created', 'football_category', $id, $actor->getTenant(), $actor, ['event' => $publicId], $ip); return $id;
    }

    /** @param array<string, mixed> $input */
    public function updateCategory(TenantUser $actor, string $publicId, string $categoryPublicId, array $input, string $ip): void
    {
        $event = $this->structureEvent($actor, $publicId); $category = $this->category($event, $categoryPublicId); $values = $this->categoryValues($input); $expected = (int) ($input['lock_version'] ?? 0);
        if ($this->categoryHasMatches($event, (int) $category['id'])) { throw new \DomainException('Die Kategorie kann nach der Spielplanerstellung nicht mehr geändert werden.'); }
        $values['updated_at'] = gmdate('Y-m-d H:i:s'); $values['lock_version'] = $expected + 1;
        $affected = $this->connection->update('football_categories', $values, ['id' => $category['id'], 'tenant_id' => $event['tenant_id'], 'event_id' => $event['id'], 'lock_version' => $expected]);
        if ($affected !== 1) { throw new \DomainException('Die Kategorie wurde gleichzeitig geändert. Bitte neu laden.'); }
        $this->draftPublications($event); $this->audit->log('football.category_updated', 'football_category', $categoryPublicId, $actor->getTenant(), $actor, [], $ip);
    }

    public function assignTeamCategory(TenantUser $actor, string $publicId, string $teamPublicId, string $categoryPublicId, string $ip): void
    {
        $event = $this->structureEvent($actor, $publicId); $category = $this->category($event, $categoryPublicId); $teamId = $this->teamId($event, $teamPublicId);
        if ($this->connection->fetchOne('SELECT 1 FROM football_matches WHERE tenant_id=:tenant AND event_id=:event AND (home_team_id=:team OR away_team_id=:team)', ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'team' => $teamId]) !== false) { throw new \DomainException('Die Teamkategorie kann nach der Spielplanerstellung nicht mehr geändert werden.'); }
        $rosterSize = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM event_team_memberships WHERE tenant_id=:tenant AND event_id=:event AND team_id=:team', ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'team' => $teamId]);
        if ($rosterSize > (int) $category['max_roster_size']) { throw new \DomainException('Der bestehende Kader ist grösser als die maximale Kadergrösse der Kategorie.'); }
        $now = gmdate('Y-m-d H:i:s');
        $this->connection->executeStatement('INSERT INTO football_team_data (team_id,tenant_id,event_id,category_id,group_id,withdrawn_at,withdrawal_reason,created_at,updated_at,lock_version) VALUES (:team,:tenant,:event,:category,NULL,NULL,NULL,:now,:now,1) ON DUPLICATE KEY UPDATE category_id=VALUES(category_id),group_id=NULL,updated_at=VALUES(updated_at),lock_version=lock_version+1', ['team' => $teamId, 'tenant' => $event['tenant_id'], 'event' => $event['id'], 'category' => $category['id'], 'now' => $now]);
        $this->draftPublications($event); $this->audit->log('football.team_category_assigned', 'event_team', $teamPublicId, $actor->getTenant(), $actor, ['category' => $categoryPublicId], $ip);
    }

    public function updateTeam(TenantUser $actor, string $publicId, string $teamPublicId, string $name, int $number, int $expectedVersion, string $ip): void
    {
        $event = $this->events->manage($actor, $publicId); $teamId = $this->teamId($event, $teamPublicId); $name = trim($name);
        if ($name === '' || $number < 1) { throw new \DomainException('Teamname und positive Teamnummer sind erforderlich.'); }
        $duplicate = $this->connection->fetchOne('SELECT 1 FROM event_teams WHERE tenant_id=:tenant AND event_id=:event AND team_number=:number AND id<>:team', ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'number' => $number, 'team' => $teamId]);
        if ($duplicate !== false) { throw new \DomainException('Diese Teamnummer ist in der Veranstaltung bereits vergeben.'); }
        $affected = $this->connection->update('event_teams', ['name' => mb_substr($name, 0, 180), 'team_number' => $number, 'updated_at' => gmdate('Y-m-d H:i:s'), 'lock_version' => $expectedVersion + 1], ['id' => $teamId, 'tenant_id' => $event['tenant_id'], 'event_id' => $event['id'], 'lock_version' => $expectedVersion]);
        if ($affected !== 1) { throw new \DomainException('Das Team wurde gleichzeitig geändert. Bitte neu laden.'); }
        $this->draftPublications($event); $this->audit->log('football.team_updated', 'event_team', $teamPublicId, $actor->getTenant(), $actor, ['name' => $name, 'team_number' => $number, 'after_start' => $event['status'] === 'running'], $ip);
    }

    public function generateGroups(TenantUser $actor, string $publicId, string $categoryPublicId, string $ip): void
    {
        $event = $this->structureEvent($actor, $publicId); $category = $this->category($event, $categoryPublicId);
        if ($this->connection->fetchOne('SELECT 1 FROM football_matches WHERE tenant_id=:tenant AND event_id=:event AND category_id=:category', ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'category' => $category['id']]) !== false) { throw new \DomainException('Gruppen können nach der Spielplanerstellung nicht neu erzeugt werden.'); }
        $teams = $this->connection->fetchFirstColumn('SELECT team_id FROM football_team_data WHERE tenant_id=:tenant AND event_id=:event AND category_id=:category AND withdrawn_at IS NULL ORDER BY team_id', ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'category' => $category['id']]);
        if (count($teams) < 2) { throw new \DomainException('Mindestens zwei Teams sind für eine Gruppe erforderlich.'); }
        $groupCount = max(1, (int) ceil(count($teams) / max(2, (int) $category['group_size']))); $now = gmdate('Y-m-d H:i:s');
        $this->connection->transactional(function (Connection $db) use ($event, $category, $teams, $groupCount, $now): void {
            $db->executeStatement('UPDATE football_team_data SET group_id=NULL,lock_version=lock_version+1 WHERE tenant_id=:tenant AND event_id=:event AND category_id=:category', ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'category' => $category['id']]);
            $db->delete('football_groups', ['tenant_id' => $event['tenant_id'], 'event_id' => $event['id'], 'category_id' => $category['id']]);
            $groupIds = [];
            for ($index = 0; $index < $groupCount; ++$index) { $name = $index < 26 ? 'Gruppe '.chr(65 + $index) : 'Gruppe '.($index + 1); $db->insert('football_groups', ['tenant_id' => $event['tenant_id'], 'event_id' => $event['id'], 'category_id' => $category['id'], 'public_id' => Uuid::v7()->toRfc4122(), 'name' => $name, 'sort_order' => $index, 'created_at' => $now, 'updated_at' => $now, 'lock_version' => 1]); $groupIds[] = (int) $db->lastInsertId(); }
            foreach ($teams as $index => $teamId) { $cycle = intdiv($index, $groupCount); $position = $index % $groupCount; $groupIndex = $cycle % 2 === 0 ? $position : $groupCount - 1 - $position; $db->executeStatement('UPDATE football_team_data SET group_id=:group_id,updated_at=:now,lock_version=lock_version+1 WHERE team_id=:team AND tenant_id=:tenant AND event_id=:event', ['group_id' => $groupIds[$groupIndex], 'now' => $now, 'team' => $teamId, 'tenant' => $event['tenant_id'], 'event' => $event['id']]); }
        });
        $this->draftPublications($event); $this->audit->log('football.groups_generated', 'football_category', $categoryPublicId, $actor->getTenant(), $actor, ['groups' => $groupCount], $ip);
    }

    public function moveTeamToGroup(TenantUser $actor, string $publicId, string $teamPublicId, string $groupPublicId, string $ip): void
    {
        $event = $this->structureEvent($actor, $publicId); $teamId = $this->teamId($event, $teamPublicId); $group = $this->connection->fetchAssociative('SELECT id,category_id FROM football_groups WHERE tenant_id=:tenant AND event_id=:event AND public_id=:id', ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'id' => $groupPublicId]);
        if ($group === false) { throw new \DomainException('Gruppe nicht gefunden.'); }
        if ($this->connection->fetchOne('SELECT 1 FROM football_matches WHERE tenant_id=:tenant AND event_id=:event AND (home_team_id=:team OR away_team_id=:team)', ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'team' => $teamId]) !== false) { throw new \DomainException('Die Gruppenzuteilung kann nach der Spielplanerstellung nicht mehr geändert werden.'); }
        $affected = $this->connection->executeStatement('UPDATE football_team_data SET group_id=:group_id,updated_at=:now,lock_version=lock_version+1 WHERE team_id=:team AND tenant_id=:tenant AND event_id=:event AND category_id=:category', ['group_id' => $group['id'], 'now' => gmdate('Y-m-d H:i:s'), 'team' => $teamId, 'tenant' => $event['tenant_id'], 'event' => $event['id'], 'category' => $group['category_id']]);
        if ($affected !== 1) { throw new \DomainException('Team und Gruppe gehören nicht zur selben Kategorie.'); }
        $this->draftPublications($event); $this->audit->log('football.team_group_changed', 'event_team', $teamPublicId, $actor->getTenant(), $actor, ['group' => $groupPublicId], $ip);
    }

    public function createField(TenantUser $actor, string $publicId, string $name, string $ip): string
    {
        $event = $this->structureEvent($actor, $publicId); $this->assertNoMatches($event, 'Spielfelder'); if (trim($name) === '') { throw new \DomainException('Der Feldname fehlt.'); } $id = Uuid::v7()->toRfc4122(); $now = gmdate('Y-m-d H:i:s');
        $this->connection->insert('football_fields', ['tenant_id' => $event['tenant_id'], 'event_id' => $event['id'], 'public_id' => $id, 'name' => mb_substr(trim($name), 0, 120), 'sort_order' => 0, 'active' => 1, 'created_at' => $now, 'updated_at' => $now, 'lock_version' => 1]);
        $this->draftPublications($event);
        $this->audit->log('football.field_created', 'football_field', $id, $actor->getTenant(), $actor, [], $ip); return $id;
    }

    public function addFieldPeriod(TenantUser $actor, string $publicId, string $fieldPublicId, string $type, string $startsAt, string $endsAt, string $reason, string $ip): void
    {
        $event = $this->structureEvent($actor, $publicId); $this->assertNoMatches($event, 'Feldzeiten'); if (!in_array($type, ['available', 'blocked'], true)) { throw new \DomainException('Ungültiger Zeitraumtyp.'); }
        $fieldId = $this->connection->fetchOne('SELECT id FROM football_fields WHERE tenant_id=:tenant AND event_id=:event AND public_id=:id', ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'id' => $fieldPublicId]); if ($fieldId === false) { throw new \DomainException('Spielfeld nicht gefunden.'); }
        try { $start = new \DateTimeImmutable($startsAt, new \DateTimeZone('Europe/Zurich')); $end = new \DateTimeImmutable($endsAt, new \DateTimeZone('Europe/Zurich')); } catch (\Exception) { throw new \DomainException('Der Feldzeitraum ist ungültig.'); }
        if ($end <= $start) { throw new \DomainException('Das Ende des Feldzeitraums muss nach dem Beginn liegen.'); }
        $this->connection->insert('football_field_periods', ['tenant_id' => $event['tenant_id'], 'event_id' => $event['id'], 'field_id' => $fieldId, 'period_type' => $type, 'starts_at' => $start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), 'ends_at' => $end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), 'reason' => mb_substr(trim($reason), 0, 255) ?: null, 'created_at' => gmdate('Y-m-d H:i:s')]);
        $this->draftPublications($event); $this->audit->log('football.field_period_created', 'football_field', $fieldPublicId, $actor->getTenant(), $actor, ['type' => $type], $ip);
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $input
     */
    private function insertCategory(array $event, array $input): string
    {
        $values = $this->categoryValues($input); $id = Uuid::v7()->toRfc4122(); $now = gmdate('Y-m-d H:i:s');
        $this->connection->insert('football_categories', ['tenant_id' => $event['tenant_id'], 'event_id' => $event['id'], 'public_id' => $id, ...$values, 'active' => 1, 'created_at' => $now, 'updated_at' => $now, 'lock_version' => 1]); return $id;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function categoryValues(array $input): array
    {
        $name = trim((string) ($input['name'] ?? '')); $ageMin = ($input['age_min'] ?? '') === '' ? null : (int) $input['age_min']; $ageMax = ($input['age_max'] ?? '') === '' ? null : (int) $input['age_max'];
        $gender = (string) ($input['gender'] ?? 'open'); $mode = (string) ($input['tournament_mode'] ?? 'semifinal_final'); $drawMode = (string) ($input['knockout_draw_mode'] ?? 'penalties');
        $maxRoster = (int) ($input['max_roster_size'] ?? 15); $players = (int) ($input['players_on_field'] ?? 7); $minutes = (int) ($input['match_minutes'] ?? 15); $break = (int) ($input['min_break_minutes'] ?? 15); $overtime = (int) ($input['overtime_minutes'] ?? 5); $groupSize = (int) ($input['group_size'] ?? 4);
        if ($name === '' || ($ageMin !== null && $ageMax !== null && $ageMin > $ageMax) || !in_array($gender, ['male', 'female', 'mixed', 'open'], true) || !in_array($mode, ['final_only', 'semifinal_final', 'quarterfinal_semifinal_final'], true) || !in_array($drawMode, ['penalties', 'overtime_penalties'], true) || $maxRoster < 1 || $players < 1 || $players > $maxRoster || $minutes < 1 || $break < 0 || $overtime < 0 || $groupSize < 2 || $groupSize > 20) { throw new \DomainException('Die Fussballkategorie ist ungültig.'); }
        return ['name' => mb_substr($name, 0, 120), 'age_min' => $ageMin, 'age_max' => $ageMax, 'gender' => $gender, 'max_roster_size' => $maxRoster, 'players_on_field' => $players, 'match_minutes' => $minutes, 'min_break_minutes' => $break, 'overtime_minutes' => $overtime, 'tournament_mode' => $mode, 'group_size' => $groupSize, 'third_place_enabled' => !empty($input['third_place']), 'knockout_draw_mode' => $drawMode, 'qualify_group_winners' => max(0, (int) ($input['qualify_winners'] ?? 1)), 'qualify_group_runners_up' => max(0, (int) ($input['qualify_runners_up'] ?? 1)), 'qualify_best_thirds' => max(0, (int) ($input['qualify_best_thirds'] ?? 0)), 'exclude_last_for_cross_group' => !empty($input['exclude_last']), 'sort_order' => (int) ($input['sort_order'] ?? 0)];
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function category(array $event, string $publicId): array { $row = $this->connection->fetchAssociative('SELECT * FROM football_categories WHERE tenant_id=:tenant AND event_id=:event AND public_id=:id AND active=1', ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'id' => $publicId]); return $row === false ? throw new \DomainException('Kategorie nicht gefunden.') : $row; }
    /** @param array<string, mixed> $event */
    private function teamId(array $event, string $publicId): int { $id = $this->connection->fetchOne('SELECT id FROM event_teams WHERE tenant_id=:tenant AND event_id=:event AND public_id=:id', ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'id' => $publicId]); return $id === false ? throw new \DomainException('Team nicht gefunden.') : (int) $id; }
    /** @return array<string, mixed> */
    private function settings(int $eventId): array { $row = $this->connection->fetchAssociative('SELECT * FROM football_event_settings WHERE event_id=:event', ['event' => $eventId]); return $row === false ? throw new \LogicException('Fussballkonfiguration fehlt.') : $row; }
    /** @return array<string, mixed> */
    private function structureEvent(TenantUser $actor, string $publicId): array { $event = $this->events->manage($actor, $publicId); if ($event['status'] === 'running') { throw new \DomainException('Turnierstruktur und Spielplan dürfen nach Turnierstart nicht mehr neu aufgebaut werden.'); } return $event; }
    /** @param array<string, mixed> $event */
    private function draftPublications(array $event): void { $this->connection->executeStatement("UPDATE football_event_settings SET schedule_state='draft',ranking_state='draft',updated_at=:now,lock_version=lock_version+1 WHERE event_id=:event AND tenant_id=:tenant", ['now' => gmdate('Y-m-d H:i:s'), 'event' => $event['id'], 'tenant' => $event['tenant_id']]); }
    /** @param array<string, mixed> $event */
    private function categoryHasMatches(array $event, int $categoryId): bool { return $this->connection->fetchOne('SELECT 1 FROM football_matches WHERE tenant_id=:tenant AND event_id=:event AND category_id=:category', ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'category' => $categoryId]) !== false; }
    /** @param array<string, mixed> $event */
    private function assertNoMatches(array $event, string $subject): void { if ($this->connection->fetchOne('SELECT 1 FROM football_matches WHERE tenant_id=:tenant AND event_id=:event', ['tenant' => $event['tenant_id'], 'event' => $event['id']]) !== false) { throw new \DomainException($subject.' können nach der Spielplanerstellung nicht mehr geändert werden.'); } }
}
