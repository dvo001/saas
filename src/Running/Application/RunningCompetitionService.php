<?php

declare(strict_types=1);

namespace App\Running\Application;

use App\Core\Domain\Tenant\TenantRole;
use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Security\AuditLogger;
use App\Running\Domain\RunStatus;
use App\Running\Domain\RunningRankingService;
use App\Running\Domain\TimePrecision;
use App\Running\Domain\TimeValue;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final readonly class RunningCompetitionService
{
    public function __construct(private Connection $connection, private RunningEventGateway $events, private RunningCategoryService $categories, private RunningRankingService $ranking, private AuditLogger $audit) {}

    /** @return array<string, mixed> */
    public function workspace(TenantUser $actor, string $publicId): array
    {
        $event = $this->events->event($actor, $publicId); $this->events->initialize($event); $this->categories->synchronizeParticipants($event);
        $settings = $this->settings((int) $event['id']);
        $participants = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT p.id, p.public_id, p.first_name, p.last_name, p.birth_year, p.gender,
                   r.start_number, r.category_id, r.finalist_confirmed, r.final_start_order, r.lock_version, c.name AS category_name
            FROM event_participants p JOIN running_participant_data r ON r.participant_id = p.id
            LEFT JOIN running_categories c ON c.id = r.category_id
            WHERE p.tenant_id = :tenant AND p.event_id = :event
            ORDER BY c.sort_order, c.name, r.start_number
            SQL, ['tenant' => $event['tenant_id'], 'event' => $event['id']]);
        $participantPublicIds = []; foreach ($participants as $participant) { $participantPublicIds[(int) $participant['id']] = (string) $participant['public_id']; }
        $results = $this->connection->fetchAllAssociative('SELECT participant_id, run_number, time_units, status, lock_version FROM running_qualification_results WHERE tenant_id = :tenant AND event_id = :event', ['tenant' => $event['tenant_id'], 'event' => $event['id']]);
        $qualificationInputs = []; $qualificationVersions = [];
        foreach ($results as $result) { $participantPublicId = $participantPublicIds[(int) $result['participant_id']] ?? null; if ($participantPublicId === null) { continue; } $qualificationInputs[(int) $result['participant_id']][(int) $result['run_number']] = $result['status'] === 'valid' && $result['time_units'] !== null ? TimeValue::format((int) $result['time_units'], TimePrecision::from((string) $settings['time_precision'])) : strtoupper((string) $result['status']); $qualificationVersions[$participantPublicId][(int) $result['run_number']] = (int) $result['lock_version']; }
        $participantsByPublicId = []; foreach ($participants as $participant) { $participantsByPublicId[(string) $participant['public_id']] = $participant; }
        $finalRows = $this->connection->fetchAllAssociative('SELECT p.public_id, f.time_units, f.status, f.lock_version FROM event_participants p JOIN running_final_results f ON f.participant_id = p.id WHERE p.tenant_id = :tenant AND p.event_id = :event', ['tenant' => $event['tenant_id'], 'event' => $event['id']]);
        $finalInputs = []; $finalVersions = []; foreach ($finalRows as $row) { $finalInputs[(string) $row['public_id']] = $row['status'] === 'valid' && $row['time_units'] !== null ? TimeValue::format((int) $row['time_units'], TimePrecision::from((string) $settings['time_precision'])) : strtoupper((string) $row['status']); $finalVersions[(string) $row['public_id']] = (int) $row['lock_version']; }
        $qualification = $this->qualificationRankings($event); $proposed = [];
        foreach ($qualification as $rows) { foreach ($this->ranking->finalists($rows, (int) $settings['finalists_per_category']) as $participantPublicId) { $proposed[(string) $participantPublicId] = true; } }
        $finalists = []; foreach ($participants as $participant) { if (!empty($participant['finalist_confirmed'])) { $finalists[(string) $participant['category_name']][] = $participant; } }
        return ['event' => $event, 'settings' => $settings, 'participants' => $participants, 'participants_by_id' => $participantsByPublicId, 'inputs' => $qualificationInputs, 'qualification_versions' => $qualificationVersions, 'final_inputs' => $finalInputs, 'final_versions' => $finalVersions, 'qualification' => $qualification, 'proposed_finalists' => $proposed, 'finalists' => $finalists, 'finals' => $this->finalRankings($event), 'revision' => $this->revisionForEvent($event)];
    }

    public function revision(TenantUser $actor, string $publicId): string
    {
        return $this->revisionForEvent($this->events->event($actor, $publicId));
    }

    /** @param array<string, mixed> $input */
    public function configure(TenantUser $actor, string $publicId, array $input, string $ip): void
    {
        $event = $this->events->event($actor, $publicId, true); $this->events->initialize($event);
        $runs = (int) ($input['qualification_runs'] ?? 2); $finalists = (int) ($input['finalists'] ?? 3); $precision = (string) ($input['precision'] ?? 'tenths');
        if ($runs < 1 || $runs > 20 || $finalists < 1 || $finalists > 50 || TimePrecision::tryFrom($precision) === null) { throw new \DomainException('Die Laufkonfiguration ist ungültig.'); }
        $current = $this->settings((int) $event['id']);
        if ($current['finalists_confirmed_at'] !== null) { throw new \DomainException('Die Konfiguration ist nach Bestätigung der Finalisten gesperrt.'); }
        if ($precision !== $current['time_precision'] && $this->connection->fetchOne('SELECT 1 FROM running_qualification_results WHERE tenant_id = :tenant AND event_id = :event AND time_units IS NOT NULL LIMIT 1', ['tenant' => $event['tenant_id'], 'event' => $event['id']]) !== false) { throw new \DomainException('Die Zeitauflösung kann nach der ersten gültigen Zeit nicht mehr geändert werden.'); }
        if ($this->connection->fetchOne('SELECT 1 FROM running_qualification_results WHERE event_id = :event AND run_number > :runs', ['event' => $event['id'], 'runs' => $runs]) !== false) { throw new \DomainException('Vorhandene Zeiten verhindern das Reduzieren der Laufanzahl.'); }
        $expected = (int) ($input['lock_version'] ?? 0);
        if ($expected !== (int) $current['lock_version']) { throw new \DomainException('Die Laufkonfiguration wurde gleichzeitig geändert. Bitte neu laden.'); }
        $affected = $this->connection->update('running_event_settings', ['qualification_runs' => $runs, 'finalists_per_category' => $finalists, 'time_precision' => $precision, 'final_enabled' => !empty($input['final_enabled']), 'updated_at' => gmdate('Y-m-d H:i:s'), 'lock_version' => $expected + 1], ['event_id' => $event['id'], 'tenant_id' => $event['tenant_id'], 'lock_version' => $expected]);
        if ($affected !== 1) { throw new \DomainException('Die Laufkonfiguration wurde gleichzeitig geändert. Bitte neu laden.'); }
        $this->audit->log('running.configuration_changed', 'event', $publicId, $actor->getTenant(), $actor, ['qualification_runs' => $runs, 'finalists' => $finalists, 'precision' => $precision], $ip);
    }

    /**
     * @param array<string, mixed> $numbers
     * @param array<string, mixed> $versions
     */
    public function saveStartNumbers(TenantUser $actor, string $publicId, array $numbers, array $versions, string $ip): void
    {
        $event = $this->events->eventForDataEntry($actor, $publicId); $used = [];
        $assignments = []; foreach ($numbers as $participantPublicId => $raw) { $number = (int) $raw; if ($number < 1 || $number > 1_000_000_000 || isset($used[$number])) { throw new \DomainException('Startnummern müssen zwischen 1 und 1 Milliarde liegen und eindeutig sein.'); } $used[$number] = true; $assignments[$this->participantId($event, (string) $participantPublicId)] = ['number' => $number, 'version' => (int) ($versions[(string) $participantPublicId] ?? 0)]; }
        $this->connection->transactional(function (Connection $db) use ($assignments, $event): void { $temporary = 4_000_000_000; foreach ($assignments as $participantId => $assignment) { $affected = $db->update('running_participant_data', ['start_number' => $temporary++], ['participant_id' => $participantId, 'tenant_id' => $event['tenant_id'], 'event_id' => $event['id'], 'lock_version' => $assignment['version']]); if ($affected !== 1) { throw new \DomainException('Eine Startnummer wurde gleichzeitig geändert. Bitte neu laden.'); } } foreach ($assignments as $participantId => $assignment) { $db->update('running_participant_data', ['start_number' => $assignment['number'], 'updated_at' => gmdate('Y-m-d H:i:s'), 'lock_version' => $assignment['version'] + 1], ['participant_id' => $participantId, 'tenant_id' => $event['tenant_id'], 'event_id' => $event['id'], 'lock_version' => $assignment['version']]); } });
        $this->audit->log('running.start_numbers_changed', 'event', $publicId, $actor->getTenant(), $actor, [], $ip);
    }

    /**
     * @param array<string, mixed> $entries
     * @param array<string, mixed> $versions
     */
    public function saveQualification(TenantUser $actor, string $publicId, array $entries, array $versions, string $ip): void
    {
        $event = $this->events->eventForDataEntry($actor, $publicId); $settings = $this->settings((int) $event['id']); if ($settings['finalists_confirmed_at'] !== null) { throw new \DomainException('Qualifikationsdaten sind nach Bestätigung der Finalisten gesperrt.'); } $precision = TimePrecision::from((string) $settings['time_precision']);
        try { $this->connection->transactional(function (Connection $db) use ($entries, $versions, $event, $settings, $precision): void {
            if ($db->fetchOne('SELECT 1 FROM running_event_settings WHERE tenant_id = :tenant AND event_id = :event AND finalists_confirmed_at IS NULL FOR UPDATE', ['tenant' => $event['tenant_id'], 'event' => $event['id']]) === false) { throw new \DomainException('Qualifikationsdaten sind nach Bestätigung der Finalisten gesperrt.'); }
            foreach ($entries as $participantPublicId => $runs) {
                if (!is_array($runs)) { continue; }
                $participantId = $this->participantId($event, (string) $participantPublicId);
                foreach ($runs as $runNumber => $raw) {
                    $run = (int) $runNumber; if ($run < 1 || $run > (int) $settings['qualification_runs']) { continue; }
                    $expected = (int) ($versions[(string) $participantPublicId][$run] ?? 0);
                    $existing = $db->fetchAssociative('SELECT id, lock_version FROM running_qualification_results WHERE tenant_id = :tenant AND event_id = :event AND participant_id = :participant AND run_number = :run FOR UPDATE', ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'participant' => $participantId, 'run' => $run]);
                    if ($existing !== false && (int) $existing['lock_version'] !== $expected) { throw new \DomainException('Eine Qualifikationszeit wurde gleichzeitig geändert. Bitte neu laden.'); }
                    if ($existing === false && $expected !== 0) { throw new \DomainException('Eine Qualifikationszeit wurde gleichzeitig geändert. Bitte neu laden.'); }
                    if (trim((string) $raw) === '') { if ($existing !== false) { $db->delete('running_qualification_results', ['id' => $existing['id'], 'lock_version' => $expected]); } continue; }
                    [$status, $units] = $this->parse((string) $raw, $precision); $now = gmdate('Y-m-d H:i:s');
                    if ($existing === false) { $db->insert('running_qualification_results', ['tenant_id' => $event['tenant_id'], 'event_id' => $event['id'], 'participant_id' => $participantId, 'run_number' => $run, 'time_units' => $units, 'status' => $status->value, 'notes' => null, 'created_at' => $now, 'updated_at' => $now, 'lock_version' => 1]); continue; }
                    $affected = $db->update('running_qualification_results', ['time_units' => $units, 'status' => $status->value, 'updated_at' => $now, 'lock_version' => $expected + 1], ['id' => $existing['id'], 'lock_version' => $expected]);
                    if ($affected !== 1) { throw new \DomainException('Eine Qualifikationszeit wurde gleichzeitig geändert. Bitte neu laden.'); }
                }
            }
        }); } catch (UniqueConstraintViolationException $exception) { throw new \DomainException('Eine Qualifikationszeit wurde gleichzeitig geändert. Bitte neu laden.', 0, $exception); }
        $this->audit->log('running.qualification_saved', 'event', $publicId, $actor->getTenant(), $actor, [], $ip);
    }

    /** @param list<string> $selected */
    public function confirmFinalists(TenantUser $actor, string $publicId, array $selected, string $ip): void
    {
        $event = $this->events->event($actor, $publicId, true); $settings = $this->settings((int) $event['id']);
        if (!(bool) $settings['final_enabled']) { throw new \DomainException('Das Finale ist deaktiviert.'); }
        if ($settings['finalists_confirmed_at'] !== null) { throw new \DomainException('Die Finalistenauswahl ist bereits bestätigt und gesperrt.'); }
        $this->connection->transactional(function (Connection $db) use ($event, $selected, $actor): void {
            $lockedSettings = $db->fetchAssociative('SELECT final_enabled, finalists_per_category, finalists_confirmed_at FROM running_event_settings WHERE tenant_id = :tenant AND event_id = :event FOR UPDATE', ['tenant' => $event['tenant_id'], 'event' => $event['id']]);
            if ($lockedSettings === false || !(bool) $lockedSettings['final_enabled']) { throw new \DomainException('Das Finale ist deaktiviert.'); }
            if ($lockedSettings['finalists_confirmed_at'] !== null) { throw new \DomainException('Die Finalistenauswahl ist bereits bestätigt und gesperrt.'); }
            $required = []; foreach ($this->qualificationRankings($event) as $rows) { foreach ($this->ranking->finalists($rows, (int) $lockedSettings['finalists_per_category']) as $id) { $required[(string) $id] = true; } }
            $chosen = array_fill_keys($selected, true); if (array_diff_key($required, $chosen) !== [] || array_diff_key($chosen, $required) !== []) { throw new \DomainException('Die bestätigte Auswahl muss dem berechneten Finalistenvorschlag entsprechen.'); }
            $db->executeStatement('UPDATE running_participant_data SET finalist_confirmed = 0, final_start_order = NULL, lock_version = lock_version + 1 WHERE tenant_id = :tenant AND event_id = :event', ['tenant' => $event['tenant_id'], 'event' => $event['id']]);
            $ordersByCategory = [];
            foreach ($selected as $participantPublicId) {
                $participant = $db->fetchAssociative('SELECT p.id, d.category_id FROM event_participants p JOIN running_participant_data d ON d.participant_id = p.id WHERE p.tenant_id = :tenant AND p.event_id = :event AND p.public_id = :id', ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'id' => $participantPublicId]);
                if ($participant === false || $participant['category_id'] === null) { throw new \DomainException('Finalist oder Kategorie nicht gefunden.'); }
                $categoryId = (int) $participant['category_id'];
                $ordersByCategory[$categoryId] = ($ordersByCategory[$categoryId] ?? 0) + 1;
                $db->executeStatement('UPDATE running_participant_data SET finalist_confirmed = 1, final_start_order = :start_order, updated_at = :updated, lock_version = lock_version + 1 WHERE participant_id = :participant AND tenant_id = :tenant AND event_id = :event', ['start_order' => $ordersByCategory[$categoryId], 'updated' => gmdate('Y-m-d H:i:s'), 'participant' => (int) $participant['id'], 'tenant' => $event['tenant_id'], 'event' => $event['id']]);
            }
            $db->executeStatement('UPDATE running_event_settings SET finalists_confirmed_at = :confirmed, finalists_confirmed_by_user_id = :user, updated_at = :updated, lock_version = lock_version + 1 WHERE event_id = :event AND tenant_id = :tenant', ['confirmed' => gmdate('Y-m-d H:i:s'), 'user' => $actor->getId(), 'updated' => gmdate('Y-m-d H:i:s'), 'event' => $event['id'], 'tenant' => $event['tenant_id']]);
        });
        $this->audit->log('running.finalists_confirmed', 'event', $publicId, $actor->getTenant(), $actor, ['count' => count($selected)], $ip);
    }

    public function resetFinalists(TenantUser $actor, string $publicId, string $reason, string $ip): void
    {
        if (!in_array($actor->getTenantRole(), [TenantRole::Owner, TenantRole::Administrator], true) || trim($reason) === '') { throw new \DomainException('Nur Owner/Admin dürfen mit Begründung zurücksetzen.'); }
        $event = $this->events->event($actor, $publicId, true);
        $this->connection->transactional(function (Connection $db) use ($event): void {
            $lockedSettings = $db->fetchAssociative('SELECT finalists_confirmed_at FROM running_event_settings WHERE tenant_id = :tenant AND event_id = :event FOR UPDATE', ['tenant' => $event['tenant_id'], 'event' => $event['id']]);
            if ($lockedSettings === false || $lockedSettings['finalists_confirmed_at'] === null) { throw new \DomainException('Die Finalistenauswahl ist noch nicht bestätigt.'); }
            if ($db->fetchOne('SELECT 1 FROM running_final_results WHERE tenant_id = :tenant AND event_id = :event', ['tenant' => $event['tenant_id'], 'event' => $event['id']]) !== false) { throw new \DomainException('Finalzeiten sind bereits vorhanden.'); }
            $db->executeStatement('UPDATE running_participant_data SET finalist_confirmed = 0, final_start_order = NULL, lock_version = lock_version + 1 WHERE tenant_id = :tenant AND event_id = :event', ['tenant' => $event['tenant_id'], 'event' => $event['id']]);
            $db->executeStatement('UPDATE running_event_settings SET finalists_confirmed_at = NULL, finalists_confirmed_by_user_id = NULL, updated_at = :updated, lock_version = lock_version + 1 WHERE event_id = :event AND tenant_id = :tenant', ['updated' => gmdate('Y-m-d H:i:s'), 'event' => $event['id'], 'tenant' => $event['tenant_id']]);
        });
        $this->audit->log('running.finalists_reset', 'event', $publicId, $actor->getTenant(), $actor, ['reason' => trim($reason)], $ip);
    }

    /**
     * @param array<string, mixed> $entries
     * @param array<string, mixed> $versions
     */
    public function saveFinals(TenantUser $actor, string $publicId, array $entries, array $versions, string $ip): void
    {
        $event = $this->events->eventForDataEntry($actor, $publicId); $settings = $this->settings((int) $event['id']); if ($settings['finalists_confirmed_at'] === null) { throw new \DomainException('Finalisten sind noch nicht bestätigt.'); } $precision = TimePrecision::from((string) $settings['time_precision']);
        try { $this->connection->transactional(function (Connection $db) use ($entries, $versions, $event, $precision): void {
            if ($db->fetchOne('SELECT 1 FROM running_event_settings WHERE tenant_id = :tenant AND event_id = :event AND finalists_confirmed_at IS NOT NULL FOR UPDATE', ['tenant' => $event['tenant_id'], 'event' => $event['id']]) === false) { throw new \DomainException('Finalisten sind noch nicht bestätigt.'); }
            foreach ($entries as $participantPublicId => $raw) {
                $id = $this->participantId($event, (string) $participantPublicId, true); $expected = (int) ($versions[(string) $participantPublicId] ?? 0);
                $existing = $db->fetchAssociative('SELECT lock_version FROM running_final_results WHERE participant_id = :participant AND tenant_id = :tenant AND event_id = :event FOR UPDATE', ['participant' => $id, 'tenant' => $event['tenant_id'], 'event' => $event['id']]);
                if ($existing !== false && (int) $existing['lock_version'] !== $expected) { throw new \DomainException('Eine Finalzeit wurde gleichzeitig geändert. Bitte neu laden.'); }
                if ($existing === false && $expected !== 0) { throw new \DomainException('Eine Finalzeit wurde gleichzeitig geändert. Bitte neu laden.'); }
                if (trim((string) $raw) === '') { if ($existing !== false) { $db->delete('running_final_results', ['participant_id' => $id, 'lock_version' => $expected]); } continue; }
                [$status, $units] = $this->parse((string) $raw, $precision); $now = gmdate('Y-m-d H:i:s');
                if ($existing === false) { $db->insert('running_final_results', ['participant_id' => $id, 'tenant_id' => $event['tenant_id'], 'event_id' => $event['id'], 'time_units' => $units, 'status' => $status->value, 'notes' => null, 'created_at' => $now, 'updated_at' => $now, 'lock_version' => 1]); continue; }
                $affected = $db->update('running_final_results', ['time_units' => $units, 'status' => $status->value, 'updated_at' => $now, 'lock_version' => $expected + 1], ['participant_id' => $id, 'tenant_id' => $event['tenant_id'], 'event_id' => $event['id'], 'lock_version' => $expected]);
                if ($affected !== 1) { throw new \DomainException('Eine Finalzeit wurde gleichzeitig geändert. Bitte neu laden.'); }
            }
        }); } catch (UniqueConstraintViolationException $exception) { throw new \DomainException('Eine Finalzeit wurde gleichzeitig geändert. Bitte neu laden.', 0, $exception); }
        $this->audit->log('running.finals_saved', 'event', $publicId, $actor->getTenant(), $actor, [], $ip);
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, list<array{id: int|string, times: list<int>, rank: int}>>
     */
    private function qualificationRankings(array $event): array
    {
        $rows = $this->connection->fetchAllAssociative("SELECT p.public_id, c.name, c.gender, q.time_units FROM event_participants p JOIN running_participant_data d ON d.participant_id = p.id JOIN running_categories c ON c.id = d.category_id LEFT JOIN running_qualification_results q ON q.participant_id = p.id AND q.status = 'valid' WHERE p.tenant_id = :tenant AND p.event_id = :event ORDER BY c.sort_order, d.start_number", ['tenant' => $event['tenant_id'], 'event' => $event['id']]);
        $groups = [];
        foreach ($rows as $row) { $key = $row['name'].'|'.$row['gender']; if (!isset($groups[$key][(string) $row['public_id']])) { $groups[$key][(string) $row['public_id']] = ['id' => (string) $row['public_id'], 'times' => []]; } if ($row['time_units'] !== null) { $groups[$key][(string) $row['public_id']]['times'][] = (int) $row['time_units']; } }
        $result = []; foreach ($groups as $key => $group) { $result[$key] = $this->ranking->qualification(array_values($group)); } return $result;
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, list<array{id: int|string, time: ?int, status: RunStatus, rank?: ?int}>>
     */
    private function finalRankings(array $event): array
    {
        $settings = $this->settings((int) $event['id']);
        if (!(bool) $settings['final_enabled']) { $result = []; foreach ($this->qualificationRankings($event) as $group => $rows) { foreach ($rows as $row) { $result[$group][] = ['id' => $row['id'], 'time' => $row['times'][0] ?? null, 'status' => RunStatus::Valid, 'rank' => $row['rank']]; } } return $result; }
        $rows = $this->connection->fetchAllAssociative('SELECT p.public_id, c.name, c.gender, f.time_units, f.status FROM event_participants p JOIN running_participant_data d ON d.participant_id = p.id JOIN running_categories c ON c.id = d.category_id LEFT JOIN running_final_results f ON f.participant_id = p.id WHERE p.tenant_id = :tenant AND p.event_id = :event AND d.finalist_confirmed = 1 ORDER BY c.sort_order, d.final_start_order', ['tenant' => $event['tenant_id'], 'event' => $event['id']]);
        $groups = []; foreach ($rows as $row) { $groups[$row['name'].'|'.$row['gender']][] = ['id' => (string) $row['public_id'], 'time' => $row['time_units'] === null ? null : (int) $row['time_units'], 'status' => RunStatus::tryFrom((string) $row['status']) ?? RunStatus::Dns]; }
        foreach ($groups as &$group) { $group = $this->ranking->final($group); } unset($group); return $groups;
    }

    /** @return array<string, mixed> */ private function settings(int $eventId): array { $row = $this->connection->fetchAssociative('SELECT * FROM running_event_settings WHERE event_id = :event', ['event' => $eventId]); return $row === false ? throw new \LogicException('Laufkonfiguration fehlt.') : $row; }
    /** @param array<string, mixed> $event */ private function revisionForEvent(array $event): string { $parameters = ['tenant' => $event['tenant_id'], 'event' => $event['id']]; $parts = [$this->connection->fetchAssociative('SELECT qualification_runs, finalists_per_category, time_precision, final_enabled, finalists_confirmed_at FROM running_event_settings WHERE tenant_id = :tenant AND event_id = :event', $parameters), $this->connection->fetchAssociative('SELECT COUNT(*) AS amount, COALESCE(SUM(lock_version), 0) AS versions FROM running_categories WHERE tenant_id = :tenant AND event_id = :event', $parameters), $this->connection->fetchAssociative('SELECT COUNT(*) AS amount, COALESCE(SUM(CRC32(CONCAT_WS(\':\', participant_id, category_id, start_number, finalist_confirmed, final_start_order))), 0) AS checksum FROM running_participant_data WHERE tenant_id = :tenant AND event_id = :event', $parameters), $this->connection->fetchAssociative('SELECT COUNT(*) AS amount, COALESCE(SUM(lock_version), 0) AS versions FROM running_qualification_results WHERE tenant_id = :tenant AND event_id = :event', $parameters), $this->connection->fetchAssociative('SELECT COUNT(*) AS amount, COALESCE(SUM(lock_version), 0) AS versions FROM running_final_results WHERE tenant_id = :tenant AND event_id = :event', $parameters)]; return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR)); }
    /** @param array<string, mixed> $event */ private function participantId(array $event, string $publicId, bool $finalist = false): int { $sql = 'SELECT p.id FROM event_participants p'.($finalist ? ' JOIN running_participant_data d ON d.participant_id = p.id AND d.finalist_confirmed = 1' : '').' WHERE p.tenant_id = :tenant AND p.event_id = :event AND p.public_id = :id'; $id = $this->connection->fetchOne($sql, ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'id' => $publicId]); return $id === false ? throw new \DomainException('Teilnehmer nicht gefunden.') : (int) $id; }
    /** @return array{0: RunStatus, 1: ?int} */ private function parse(string $raw, TimePrecision $precision): array { $status = RunStatus::tryFrom(mb_strtolower(trim($raw))); return $status === null ? [RunStatus::Valid, TimeValue::parse($raw, $precision)] : [$status, null]; }
}
