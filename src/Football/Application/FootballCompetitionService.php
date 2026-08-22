<?php

declare(strict_types=1);

namespace App\Football\Application;

use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Security\AuditLogger;
use App\Football\Domain\FootballBracketService;
use App\Football\Domain\FootballCrossGroupRankingService;
use App\Football\Domain\FootballScheduleGenerator;
use App\Football\Domain\FootballScheduleValidator;
use App\Football\Domain\FootballStandingService;
use App\Football\Domain\SchedulingStrategy;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class FootballCompetitionService
{
    public function __construct(
        private Connection $connection,
        private FootballEventGateway $events,
        private FootballSetupService $setup,
        private FootballScheduleGenerator $generator,
        private FootballScheduleValidator $validator,
        private FootballStandingService $standings,
        private FootballBracketService $brackets,
        private FootballCrossGroupRankingService $crossGroupRankings,
        private AuditLogger $audit,
    ) {}

    /** @return array<string, mixed> */
    public function workspace(TenantUser $actor, string $publicId): array
    {
        $setup = $this->setup->data($actor, $publicId); $event = $setup['event']; $capabilities = $this->events->capabilities($actor, $publicId);
        $matches = $this->matches($event); $standings = $this->currentStandings($event); $finalRankings = $this->currentFinalRankings($event); $publications = $this->activePublications($event);
        if (!$capabilities['data_entry']) {
            $matches = isset($publications['schedule']['matches']) && is_array($publications['schedule']['matches']) ? $publications['schedule']['matches'] : [];
            $standings = isset($publications['rankings']['standings']) && is_array($publications['rankings']['standings']) ? $publications['rankings']['standings'] : [];
            $finalRankings = isset($publications['rankings']['final_rankings']) && is_array($publications['rankings']['final_rankings']) ? $publications['rankings']['final_rankings'] : [];
        }
        return [...$setup, 'matches' => $matches, 'standings' => $standings, 'final_rankings' => $finalRankings, 'publications' => $publications, 'capabilities' => $capabilities, 'revision' => $this->revisionForEvent($event)];
    }

    public function revision(TenantUser $actor, string $publicId): string
    {
        return $this->revisionForEvent($this->events->read($actor, $publicId));
    }

    public function generateSchedule(TenantUser $actor, string $publicId, string $ip): void
    {
        $event = $this->events->manage($actor, $publicId); if ($event['status'] === 'running') { throw new \DomainException('Nach Turnierstart wird der Spielplan nicht vollständig neu erzeugt.'); }
        $this->events->initialize($event); $settings = $this->settings($event); $scope = ['tenant' => $event['tenant_id'], 'event' => $event['id']];
        if ($this->connection->fetchOne("SELECT 1 FROM football_matches WHERE tenant_id=:tenant AND event_id=:event AND status='played'", $scope) !== false) { throw new \DomainException('Ein Spiel wurde bereits gespielt; eine vollständige Neuplanung ist nicht mehr erlaubt.'); }
        $groupRows = $this->connection->fetchAllAssociative('SELECT g.id,g.name,g.category_id,c.match_minutes,c.min_break_minutes FROM football_groups g JOIN football_categories c ON c.id=g.category_id WHERE g.tenant_id=:tenant AND g.event_id=:event ORDER BY c.sort_order,g.sort_order', $scope);
        $groups = [];
        foreach ($groupRows as $group) {
            $teams = $this->connection->fetchFirstColumn('SELECT team_id FROM football_team_data WHERE tenant_id=:tenant AND event_id=:event AND group_id=:group AND withdrawn_at IS NULL ORDER BY team_id', [...$scope, 'group' => $group['id']]);
            if (count($teams) < 2) { throw new \DomainException('Jede Gruppe benötigt mindestens zwei aktive Teams: '.$group['name']); }
            $groups[] = ['category_id' => (int) $group['category_id'], 'group_id' => (int) $group['id'], 'group_name' => (string) $group['name'], 'teams' => array_map('intval', $teams), 'duration' => (int) $group['match_minutes'], 'min_break' => (int) $group['min_break_minutes']];
        }
        if ($groups === []) { throw new \DomainException('Vor der Spielplanerstellung müssen Gruppen erzeugt werden.'); }
        $fields = $this->generatorFields($event); $generated = $this->generator->generate($groups, $fields, SchedulingStrategy::from((string) $settings['scheduling_strategy'])); $now = gmdate('Y-m-d H:i:s');
        $this->connection->transactional(function (Connection $db) use ($event, $generated, $now): void {
            $db->executeStatement('DELETE FROM football_matches WHERE tenant_id=:tenant AND event_id=:event ORDER BY id DESC', ['tenant' => $event['tenant_id'], 'event' => $event['id']]);
            foreach ($generated as $index => $match) {
                $db->insert('football_matches', ['tenant_id' => $event['tenant_id'], 'event_id' => $event['id'], 'public_id' => Uuid::v7()->toRfc4122(), 'category_id' => $match['category_id'], 'group_id' => $match['group_id'], 'stage' => 'group', 'round_number' => $match['round'], 'sequence_number' => $index + 1, 'home_team_id' => $match['home_team_id'], 'away_team_id' => $match['away_team_id'], 'home_source_match_id' => null, 'away_source_match_id' => null, 'home_source_outcome' => null, 'away_source_outcome' => null, 'field_id' => $match['field_id'], 'scheduled_start' => gmdate('Y-m-d H:i:s', $match['start']), 'duration_minutes' => $match['duration'], 'status' => 'scheduled', 'home_goals' => null, 'away_goals' => null, 'home_penalties' => null, 'away_penalties' => null, 'home_yellow' => 0, 'away_yellow' => 0, 'home_red' => 0, 'away_red' => 0, 'is_forfait' => 0, 'counts_for_standings' => 1, 'winner_team_id' => null, 'manual_override' => 0, 'created_at' => $now, 'updated_at' => $now, 'lock_version' => 1]);
            }
            $db->executeStatement("UPDATE football_event_settings SET schedule_state='draft',ranking_state='draft',updated_at=:now,lock_version=lock_version+1 WHERE tenant_id=:tenant AND event_id=:event", ['now' => $now, 'tenant' => $event['tenant_id'], 'event' => $event['id']]);
        });
        $this->audit->log('football.schedule_generated', 'event', $publicId, $actor->getTenant(), $actor, ['matches' => count($generated), 'strategy' => $settings['scheduling_strategy']], $ip);
    }

    public function moveMatch(TenantUser $actor, string $publicId, string $matchPublicId, string $fieldPublicId, string $startsAt, int $expectedVersion, bool $acknowledge, string $ip): void
    {
        $event = $this->events->manage($actor, $publicId); $match = $this->match($event, $matchPublicId); $field = $this->field($event, $fieldPublicId);
        try { $start = new \DateTimeImmutable($startsAt, new \DateTimeZone('Europe/Zurich')); } catch (\Exception) { throw new \DomainException('Die neue Spielzeit ist ungültig.'); }
        $timestamp = $start->setTimezone(new \DateTimeZone('UTC'))->getTimestamp(); $candidate = ['id' => (int) $match['id'], 'field_id' => (int) $field['id'], 'start' => $timestamp, 'duration' => (int) $match['duration_minutes'], 'home_team_id' => $match['home_team_id'] === null ? null : (int) $match['home_team_id'], 'away_team_id' => $match['away_team_id'] === null ? null : (int) $match['away_team_id']];
        $warnings = array_values(array_unique([...$this->validator->validate($candidate, $this->validatorMatches($event), $this->fieldPeriods($event, (int) $field['id']), (int) $match['min_break_minutes']), ...$this->bracketMoveWarnings($event, $match, $timestamp)]));
        if ($warnings !== [] && !$acknowledge) { throw new \DomainException('Spielplanwarnung: '.implode(' ', $warnings).' Zum Speichern ausdrücklich bestätigen.'); }
        $affected = $this->connection->update('football_matches', ['field_id' => $field['id'], 'scheduled_start' => gmdate('Y-m-d H:i:s', $timestamp), 'manual_override' => 1, 'updated_at' => gmdate('Y-m-d H:i:s'), 'lock_version' => $expectedVersion + 1], ['id' => $match['id'], 'tenant_id' => $event['tenant_id'], 'event_id' => $event['id'], 'lock_version' => $expectedVersion]);
        if ($affected !== 1) { throw new \DomainException('Das Spiel wurde gleichzeitig geändert. Bitte neu laden.'); }
        $this->markDraft($event, true, false); $this->audit->log('football.match_moved', 'football_match', $matchPublicId, $actor->getTenant(), $actor, ['field' => $fieldPublicId, 'start' => gmdate(DATE_ATOM, $timestamp), 'warnings' => $warnings], $ip);
    }

    /** @param array<string, mixed> $input */
    public function saveResult(TenantUser $actor, string $publicId, string $matchPublicId, array $input, string $ip): void
    {
        $event = $this->events->enterData($actor, $publicId); $match = $this->match($event, $matchPublicId); if ($match['home_team_id'] === null || $match['away_team_id'] === null) { throw new \DomainException('Die Teams dieses K.-o.-Spiels stehen noch nicht fest.'); }
        if ($match['stage'] === 'group' && $this->connection->fetchOne("SELECT 1 FROM football_matches WHERE tenant_id=:tenant AND event_id=:event AND category_id=:category AND stage<>'group'", ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'category' => $match['category_id']]) !== false) { throw new \DomainException('Gruppenresultate können nach Erstellung der Finalrunde nicht mehr korrigiert werden.'); }
        if ($match['stage'] !== 'group' && $this->connection->fetchOne("SELECT 1 FROM football_matches WHERE tenant_id=:tenant AND event_id=:event AND status='played' AND (home_source_match_id=:match OR away_source_match_id=:match)", ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'match' => $match['id']]) !== false) { throw new \DomainException('Dieses Resultat kann nicht mehr geändert werden, weil ein Folgespiel bereits gespielt wurde.'); }
        $expected = (int) ($input['lock_version'] ?? 0); $home = $this->smallScore($input['home_goals'] ?? null); $away = $this->smallScore($input['away_goals'] ?? null); $forfait = (string) ($input['forfait'] ?? '');
        if ($forfait !== '') { $settings = $this->settings($event); if (!in_array($forfait, ['home', 'away'], true)) { throw new \DomainException('Ungültige Forfait-Auswahl.'); } $home = $forfait === 'home' ? (int) $settings['forfait_goals_winner'] : (int) $settings['forfait_goals_loser']; $away = $forfait === 'away' ? (int) $settings['forfait_goals_winner'] : (int) $settings['forfait_goals_loser']; }
        if ($home === null || $away === null) { throw new \DomainException('Für beide Teams muss ein Resultat erfasst werden.'); }
        $homePenalties = $this->optionalScore($input['home_penalties'] ?? null); $awayPenalties = $this->optionalScore($input['away_penalties'] ?? null);
        $winner = $home > $away ? (int) $match['home_team_id'] : ($away > $home ? (int) $match['away_team_id'] : null);
        if (($match['stage'] === 'group' || $winner !== null) && ($homePenalties !== null || $awayPenalties !== null)) { throw new \DomainException('Ein Penaltyresultat ist nur bei einem unentschiedenen K.-o.-Spiel zulässig.'); }
        if ($match['stage'] !== 'group' && $winner === null) { if ($homePenalties === null || $awayPenalties === null || $homePenalties === $awayPenalties) { throw new \DomainException('Ein K.-o.-Unentschieden benötigt ein eindeutiges Penaltyresultat.'); } $winner = $homePenalties > $awayPenalties ? (int) $match['home_team_id'] : (int) $match['away_team_id']; }
        $values = ['status' => 'played', 'home_goals' => $home, 'away_goals' => $away, 'home_penalties' => $homePenalties, 'away_penalties' => $awayPenalties, 'home_yellow' => $this->cardCount($input['home_yellow'] ?? 0), 'away_yellow' => $this->cardCount($input['away_yellow'] ?? 0), 'home_red' => $this->cardCount($input['home_red'] ?? 0), 'away_red' => $this->cardCount($input['away_red'] ?? 0), 'is_forfait' => $forfait !== '', 'counts_for_standings' => 1, 'winner_team_id' => $winner, 'updated_at' => gmdate('Y-m-d H:i:s'), 'lock_version' => $expected + 1];
        $this->connection->transactional(function (Connection $db) use ($event, $match, $expected, $values, $winner): void {
            $affected = $db->update('football_matches', $values, ['id' => $match['id'], 'tenant_id' => $event['tenant_id'], 'event_id' => $event['id'], 'lock_version' => $expected]); if ($affected !== 1) { throw new \DomainException('Das Resultat wurde gleichzeitig geändert. Bitte neu laden.'); }
            if ($winner !== null && $match['stage'] !== 'group') { $loser = $winner === (int) $match['home_team_id'] ? (int) $match['away_team_id'] : (int) $match['home_team_id']; $this->resolveBracketDependants($db, $event, (int) $match['id'], $winner, $loser); }
            $db->executeStatement("UPDATE football_event_settings SET ranking_state='draft',updated_at=:now,lock_version=lock_version+1 WHERE tenant_id=:tenant AND event_id=:event", ['now' => gmdate('Y-m-d H:i:s'), 'tenant' => $event['tenant_id'], 'event' => $event['id']]);
        });
        $this->audit->log('football.result_saved', 'football_match', $matchPublicId, $actor->getTenant(), $actor, ['forfait' => $forfait ?: null], $ip);
    }

    public function drawLot(TenantUser $actor, string $publicId, string $groupPublicId, string $ip): void
    {
        $event = $this->events->manage($actor, $publicId); $group = $this->connection->fetchAssociative('SELECT id FROM football_groups WHERE tenant_id=:tenant AND event_id=:event AND public_id=:id', ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'id' => $groupPublicId]); if ($group === false) { throw new \DomainException('Gruppe nicht gefunden.'); }
        if ($this->connection->fetchOne('SELECT 1 FROM football_matches WHERE tenant_id=:tenant AND event_id=:event AND group_id=:group', ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'group' => $group['id']]) === false) { throw new \DomainException('Für diese Gruppe wurde noch kein Spielplan erzeugt.'); }
        if ($this->connection->fetchOne("SELECT 1 FROM football_matches WHERE tenant_id=:tenant AND event_id=:event AND group_id=:group AND status='scheduled'", ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'group' => $group['id']]) !== false) { throw new \DomainException('Ein Losentscheid ist erst nach Abschluss aller Gruppenspiele möglich.'); }
        $standings = $this->currentStandings($event); $rows = $standings[$groupPublicId]['rows'] ?? []; $affected = []; $collecting = false;
        foreach ($rows as $row) { if (!empty($row['lot_required'])) { $collecting = true; $affected[] = (int) $row['team_id']; } elseif ($collecting) { break; } }
        if (count($affected) < 2) { throw new \DomainException('In dieser Gruppe ist kein Losentscheid erforderlich.'); }
        $ordered = $affected;
        for ($index = count($ordered) - 1; $index > 0; --$index) { $swap = random_int(0, $index); [$ordered[$index], $ordered[$swap]] = [$ordered[$swap], $ordered[$index]]; }
        $id = Uuid::v7()->toRfc4122(); $this->connection->insert('football_tiebreak_decisions', ['tenant_id' => $event['tenant_id'], 'event_id' => $event['id'], 'group_id' => $group['id'], 'public_id' => $id, 'affected_team_ids' => json_encode($affected, JSON_THROW_ON_ERROR), 'ordered_team_ids' => json_encode($ordered, JSON_THROW_ON_ERROR), 'decided_by_user_id' => $actor->getId(), 'reason' => 'Losentscheid', 'created_at' => gmdate('Y-m-d H:i:s')]);
        $this->markDraft($event, false, true); $this->audit->log('football.lot_drawn', 'football_tiebreak', $id, $actor->getTenant(), $actor, ['affected_team_ids' => $affected, 'ordered_team_ids' => $ordered], $ip);
    }

    public function createFinalRound(TenantUser $actor, string $publicId, string $categoryPublicId, string $ip): void
    {
        $event = $this->events->manage($actor, $publicId); $category = $this->connection->fetchAssociative('SELECT * FROM football_categories WHERE tenant_id=:tenant AND event_id=:event AND public_id=:id', ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'id' => $categoryPublicId]); if ($category === false) { throw new \DomainException('Kategorie nicht gefunden.'); }
        if ($this->connection->fetchOne("SELECT 1 FROM football_matches WHERE tenant_id=:tenant AND event_id=:event AND category_id=:category AND stage<>'group'", ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'category' => $category['id']]) !== false) { throw new \DomainException('Die Finalrunde dieser Kategorie existiert bereits.'); }
        if ($this->connection->fetchOne("SELECT 1 FROM football_matches WHERE tenant_id=:tenant AND event_id=:event AND category_id=:category AND stage='group'", ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'category' => $category['id']]) === false) { throw new \DomainException('Für diese Kategorie wurde noch kein Gruppenspielplan erzeugt.'); }
        $incomplete = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM football_matches WHERE tenant_id=:tenant AND event_id=:event AND category_id=:category AND stage='group' AND status='scheduled'", ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'category' => $category['id']]); if ($incomplete > 0) { throw new \DomainException('Alle Gruppenspiele müssen gespielt, abgesagt oder gestrichen sein.'); }
        $allStandings = $this->currentStandings($event); $categoryGroups = array_values(array_map(static function (array $group): array { $group['rows'] = array_values(array_filter($group['rows'], static fn (array $row): bool => empty($row['withdrawn_at']))); return $group; }, array_filter($allStandings, static fn (array $group): bool => (int) $group['category_id'] === (int) $category['id'])));
        $seeded = [];
        for ($position = 0; $position < (int) $category['qualify_group_winners']; ++$position) { foreach ($categoryGroups as $group) { if (isset($group['rows'][$position])) { $seeded[] = $group['rows'][$position]; } } }
        $runnerStart = (int) $category['qualify_group_winners'];
        for ($position = 0; $position < (int) $category['qualify_group_runners_up']; ++$position) { foreach ($categoryGroups as $group) { if (isset($group['rows'][$runnerStart + $position])) { $seeded[] = $group['rows'][$runnerStart + $position]; } } }
        foreach ($categoryGroups as $group) { foreach ($group['rows'] as $row) { if (!empty($row['lot_required'])) { throw new \DomainException('Vor der Finalrundenerstellung müssen alle Losentscheide dieser Kategorie abgeschlossen sein.'); } } }
        if ((int) $category['qualify_best_thirds'] > 0) {
            $comparisonGroups = [];
            foreach ($categoryGroups as $group) { $comparisonGroups[] = ['rows' => $group['rows'], 'matches' => $this->playedMatchesForGroup($event, (string) $group['group_id'])]; }
            $settings = $this->settings($event);
            $thirds = $this->crossGroupRankings->rankThirdPlaced($comparisonGroups, ['win' => (int) $settings['points_win'], 'draw' => (int) $settings['points_draw'], 'loss' => (int) $settings['points_loss']], (bool) $category['exclude_last_for_cross_group'], $this->crossGroupLotOrder($event));
            if ($this->crossGroupRankings->unresolvedTieAtCutoff($thirds, (int) $category['qualify_best_thirds']) !== []) { throw new \DomainException('An der Qualifikationsgrenze der besten Gruppendritten ist ein Losentscheid erforderlich.'); }
            $seeded = [...$seeded, ...array_slice($thirds, 0, (int) $category['qualify_best_thirds'])];
        }
        $teamIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['team_id'], $seeded)));
        $bracket = $this->brackets->create((string) $category['tournament_mode'], $teamIds, (bool) $category['third_place_enabled']); $scheduled = $this->scheduleBracket($event, $category, $bracket); $sequence = (int) $this->connection->fetchOne('SELECT COALESCE(MAX(sequence_number),0) FROM football_matches WHERE tenant_id=:tenant AND event_id=:event', ['tenant' => $event['tenant_id'], 'event' => $event['id']]); $createdIds = []; $now = gmdate('Y-m-d H:i:s');
        $this->connection->transactional(function (Connection $db) use ($event, $category, $bracket, $scheduled, &$createdIds, &$sequence, $now): void {
            foreach ($bracket as $index => $match) {
                $homeSource = $match['home_source'] === null ? null : $createdIds[$match['home_source']]; $awaySource = $match['away_source'] === null ? null : $createdIds[$match['away_source']];
                $db->insert('football_matches', ['tenant_id' => $event['tenant_id'], 'event_id' => $event['id'], 'public_id' => Uuid::v7()->toRfc4122(), 'category_id' => $category['id'], 'group_id' => null, 'stage' => $match['stage'], 'round_number' => 1, 'sequence_number' => ++$sequence, 'home_team_id' => $match['home_team_id'], 'away_team_id' => $match['away_team_id'], 'home_source_match_id' => $homeSource, 'away_source_match_id' => $awaySource, 'home_source_outcome' => $match['home_outcome'], 'away_source_outcome' => $match['away_outcome'], 'field_id' => $scheduled[$index]['field_id'], 'scheduled_start' => gmdate('Y-m-d H:i:s', $scheduled[$index]['start']), 'duration_minutes' => (int) $category['match_minutes'] + ((string) $category['knockout_draw_mode'] === 'overtime_penalties' ? (int) $category['overtime_minutes'] : 0), 'status' => 'scheduled', 'home_goals' => null, 'away_goals' => null, 'home_penalties' => null, 'away_penalties' => null, 'home_yellow' => 0, 'away_yellow' => 0, 'home_red' => 0, 'away_red' => 0, 'is_forfait' => 0, 'counts_for_standings' => 0, 'winner_team_id' => null, 'manual_override' => 0, 'created_at' => $now, 'updated_at' => $now, 'lock_version' => 1]); $createdIds[] = (int) $db->lastInsertId();
            }
        });
        $this->markDraft($event, true, true); $this->audit->log('football.final_round_created', 'football_category', $categoryPublicId, $actor->getTenant(), $actor, ['qualified_team_ids' => $teamIds, 'matches' => count($bracket)], $ip);
    }

    public function drawCrossGroupLot(TenantUser $actor, string $publicId, string $categoryPublicId, string $ip): void
    {
        $event = $this->events->manage($actor, $publicId); $category = $this->connection->fetchAssociative('SELECT * FROM football_categories WHERE tenant_id=:tenant AND event_id=:event AND public_id=:id AND active=1', ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'id' => $categoryPublicId]);
        if ($category === false) { throw new \DomainException('Kategorie nicht gefunden.'); }
        $places = (int) $category['qualify_best_thirds']; if ($places < 1) { throw new \DomainException('Für diese Kategorie sind keine besten Gruppendritten konfiguriert.'); }
        if ($this->connection->fetchOne("SELECT 1 FROM football_matches WHERE tenant_id=:tenant AND event_id=:event AND category_id=:category AND stage='group'", ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'category' => $category['id']]) === false) { throw new \DomainException('Für diese Kategorie wurde noch kein Gruppenspielplan erzeugt.'); }
        if ($this->connection->fetchOne("SELECT 1 FROM football_matches WHERE tenant_id=:tenant AND event_id=:event AND category_id=:category AND stage='group' AND status='scheduled'", ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'category' => $category['id']]) !== false) { throw new \DomainException('Ein Losentscheid ist erst nach Abschluss aller Gruppenspiele möglich.'); }
        $standings = $this->currentStandings($event); $groups = array_values(array_map(static function (array $group): array { $group['rows'] = array_values(array_filter($group['rows'], static fn (array $row): bool => empty($row['withdrawn_at']))); return $group; }, array_filter($standings, static fn (array $group): bool => (int) $group['category_id'] === (int) $category['id'])));
        foreach ($groups as $group) { foreach ($group['rows'] as $row) { if (!empty($row['lot_required'])) { throw new \DomainException('Zuerst müssen die Losentscheide innerhalb der Gruppen abgeschlossen sein.'); } } }
        $comparisonGroups = []; foreach ($groups as $group) { $comparisonGroups[] = ['rows' => $group['rows'], 'matches' => $this->playedMatchesForGroup($event, (string) $group['group_id'])]; }
        $settings = $this->settings($event); $ranked = $this->crossGroupRankings->rankThirdPlaced($comparisonGroups, ['win' => (int) $settings['points_win'], 'draw' => (int) $settings['points_draw'], 'loss' => (int) $settings['points_loss']], (bool) $category['exclude_last_for_cross_group'], $this->crossGroupLotOrder($event));
        $affected = $this->crossGroupRankings->unresolvedTieAtCutoff($ranked, $places); if (count($affected) < 2) { throw new \DomainException('An der Qualifikationsgrenze ist kein Losentscheid erforderlich.'); }
        $ordered = $affected; for ($index = count($ordered) - 1; $index > 0; --$index) { $swap = random_int(0, $index); [$ordered[$index], $ordered[$swap]] = [$ordered[$swap], $ordered[$index]]; }
        $id = Uuid::v7()->toRfc4122(); $this->connection->insert('football_tiebreak_decisions', ['tenant_id' => $event['tenant_id'], 'event_id' => $event['id'], 'group_id' => null, 'public_id' => $id, 'affected_team_ids' => json_encode($affected, JSON_THROW_ON_ERROR), 'ordered_team_ids' => json_encode($ordered, JSON_THROW_ON_ERROR), 'decided_by_user_id' => $actor->getId(), 'reason' => 'Losentscheid beste Gruppendritte: '.mb_substr((string) $category['name'], 0, 180), 'created_at' => gmdate('Y-m-d H:i:s')]);
        $this->markDraft($event, false, true); $this->audit->log('football.cross_group_lot_drawn', 'football_tiebreak', $id, $actor->getTenant(), $actor, ['category' => $categoryPublicId, 'affected_team_ids' => $affected, 'ordered_team_ids' => $ordered], $ip);
    }

    public function withdrawTeam(TenantUser $actor, string $publicId, string $teamPublicId, string $upcomingAction, string $playedAction, string $reason, string $ip): void
    {
        $event = $this->events->manage($actor, $publicId); if (!in_array($upcomingAction, ['cancel', 'void'], true) || !in_array($playedAction, ['keep', 'remove'], true) || trim($reason) === '') { throw new \DomainException('Ausfallbehandlung und Begründung sind erforderlich.'); }
        $teamId = $this->teamId($event, $teamPublicId); $now = gmdate('Y-m-d H:i:s');
        $this->connection->transactional(function (Connection $db) use ($event, $teamId, $upcomingAction, $playedAction, $reason, $now): void {
            $knockouts = $db->fetchAllAssociative("SELECT id,home_team_id,away_team_id FROM football_matches WHERE tenant_id=:tenant AND event_id=:event AND stage<>'group' AND status='scheduled' AND (home_team_id=:team OR away_team_id=:team)", ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'team' => $teamId]);
            $affected = $db->executeStatement('UPDATE football_team_data SET withdrawn_at=:now,withdrawal_reason=:reason,updated_at=:now,lock_version=lock_version+1 WHERE team_id=:team AND tenant_id=:tenant AND event_id=:event', ['now' => $now, 'reason' => mb_substr(trim($reason), 0, 500), 'team' => $teamId, 'tenant' => $event['tenant_id'], 'event' => $event['id']]);
            if ($affected !== 1) { throw new \DomainException('Das Team ist keiner Fussballkategorie zugeordnet.'); }
            $db->executeStatement("UPDATE football_matches SET status=:status,counts_for_standings=0,updated_at=:now,lock_version=lock_version+1 WHERE tenant_id=:tenant AND event_id=:event AND status='scheduled' AND (home_team_id=:team OR away_team_id=:team)", ['status' => $upcomingAction === 'cancel' ? 'cancelled' : 'void', 'now' => $now, 'tenant' => $event['tenant_id'], 'event' => $event['id'], 'team' => $teamId]);
            foreach ($knockouts as $match) { $opponent = (int) $match['home_team_id'] === $teamId ? $match['away_team_id'] : $match['home_team_id']; if ($opponent === null) { continue; } $opponentId = (int) $opponent; $db->update('football_matches', ['winner_team_id' => $opponentId, 'updated_at' => $now], ['id' => $match['id'], 'tenant_id' => $event['tenant_id'], 'event_id' => $event['id']]); $this->resolveBracketDependants($db, $event, (int) $match['id'], $opponentId, $teamId); }
            $db->executeStatement("UPDATE football_matches SET status=:status,counts_for_standings=0,updated_at=:now,lock_version=lock_version+1 WHERE tenant_id=:tenant AND event_id=:event AND status='scheduled' AND (home_team_id=:team OR away_team_id=:team)", ['status' => $upcomingAction === 'cancel' ? 'cancelled' : 'void', 'now' => $now, 'tenant' => $event['tenant_id'], 'event' => $event['id'], 'team' => $teamId]);
            if ($playedAction === 'remove') { $db->executeStatement("UPDATE football_matches SET counts_for_standings=0,updated_at=:now,lock_version=lock_version+1 WHERE tenant_id=:tenant AND event_id=:event AND status='played' AND (home_team_id=:team OR away_team_id=:team)", ['now' => $now, 'tenant' => $event['tenant_id'], 'event' => $event['id'], 'team' => $teamId]); }
        });
        $this->markDraft($event, true, true); $this->audit->log('football.team_withdrawn', 'event_team', $teamPublicId, $actor->getTenant(), $actor, ['upcoming' => $upcomingAction, 'played' => $playedAction, 'reason' => trim($reason)], $ip);
    }

    public function publish(TenantUser $actor, string $publicId, string $type, string $ip): void
    {
        $event = $this->events->manage($actor, $publicId); if (!in_array($type, ['schedule', 'rankings'], true)) { throw new \DomainException('Unbekannter Freigabetyp.'); }
        $snapshot = $type === 'schedule' ? ['matches' => $this->matches($event)] : ['standings' => $this->currentStandings($event), 'final_rankings' => $this->currentFinalRankings($event)]; $now = gmdate('Y-m-d H:i:s');
        $this->connection->transactional(function (Connection $db) use ($event, $type, $snapshot, $actor, $now): void {
            $db->executeStatement('UPDATE football_publications SET withdrawn_by_user_id=:user,withdrawn_at=:now WHERE tenant_id=:tenant AND event_id=:event AND document_type=:type AND withdrawn_at IS NULL', ['user' => $actor->getId(), 'now' => $now, 'tenant' => $event['tenant_id'], 'event' => $event['id'], 'type' => $type]);
            $version = (int) $db->fetchOne('SELECT COALESCE(MAX(version_number),0)+1 FROM football_publications WHERE tenant_id=:tenant AND event_id=:event AND document_type=:type', ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'type' => $type]);
            $db->insert('football_publications', ['tenant_id' => $event['tenant_id'], 'event_id' => $event['id'], 'document_type' => $type, 'version_number' => $version, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'published_by_user_id' => $actor->getId(), 'published_at' => $now, 'withdrawn_by_user_id' => null, 'withdrawn_at' => null]);
            $db->executeStatement('UPDATE football_event_settings SET '.($type === 'schedule' ? 'schedule_state' : 'ranking_state')."='published',updated_at=:now,lock_version=lock_version+1 WHERE tenant_id=:tenant AND event_id=:event", ['now' => $now, 'tenant' => $event['tenant_id'], 'event' => $event['id']]);
        });
        $this->audit->log('football.'.$type.'_published', 'event', $publicId, $actor->getTenant(), $actor, [], $ip);
    }

    public function withdrawPublication(TenantUser $actor, string $publicId, string $type, string $ip): void
    {
        $event = $this->events->manage($actor, $publicId); if (!in_array($type, ['schedule', 'rankings'], true)) { throw new \DomainException('Unbekannter Freigabetyp.'); } $now = gmdate('Y-m-d H:i:s');
        $affected = $this->connection->executeStatement('UPDATE football_publications SET withdrawn_by_user_id=:user,withdrawn_at=:now WHERE tenant_id=:tenant AND event_id=:event AND document_type=:type AND withdrawn_at IS NULL', ['user' => $actor->getId(), 'now' => $now, 'tenant' => $event['tenant_id'], 'event' => $event['id'], 'type' => $type]); if ($affected < 1) { throw new \DomainException('Es gibt keine aktive Freigabe.'); }
        $this->connection->executeStatement('UPDATE football_event_settings SET '.($type === 'schedule' ? 'schedule_state' : 'ranking_state')."='draft',updated_at=:now,lock_version=lock_version+1 WHERE tenant_id=:tenant AND event_id=:event", ['now' => $now, 'tenant' => $event['tenant_id'], 'event' => $event['id']]);
        $this->audit->log('football.'.$type.'_publication_withdrawn', 'event', $publicId, $actor->getTenant(), $actor, [], $ip);
    }

    /**
     * @param array<string, mixed> $event
     * @return list<array<string, mixed>>
     */
    private function matches(array $event): array { return $this->connection->fetchAllAssociative("SELECT m.*,c.name AS category_name,g.name AS group_name,f.name AS field_name,h.public_id AS home_public_id,h.name AS home_name,a.public_id AS away_public_id,a.name AS away_name FROM football_matches m JOIN football_categories c ON c.id=m.category_id LEFT JOIN football_groups g ON g.id=m.group_id LEFT JOIN football_fields f ON f.id=m.field_id LEFT JOIN event_teams h ON h.id=m.home_team_id LEFT JOIN event_teams a ON a.id=m.away_team_id WHERE m.tenant_id=:tenant AND m.event_id=:event ORDER BY COALESCE(m.scheduled_start,'9999-12-31'),m.sequence_number", ['tenant' => $event['tenant_id'], 'event' => $event['id']]); }

    /**
     * @param array<string, mixed> $event
     * @return array<string, array<string, mixed>>
     */
    private function currentStandings(array $event): array
    {
        $settings = $this->settings($event); $groups = $this->connection->fetchAllAssociative('SELECT g.id,g.public_id,g.name,g.category_id,c.name AS category_name FROM football_groups g JOIN football_categories c ON c.id=g.category_id WHERE g.tenant_id=:tenant AND g.event_id=:event ORDER BY c.sort_order,g.sort_order', ['tenant' => $event['tenant_id'], 'event' => $event['id']]); $result = [];
        foreach ($groups as $group) {
            $teams = $this->connection->fetchAllAssociative('SELECT t.id,t.public_id,t.name,t.team_number,d.withdrawn_at FROM football_team_data d JOIN event_teams t ON t.id=d.team_id WHERE d.tenant_id=:tenant AND d.event_id=:event AND d.group_id=:group ORDER BY t.team_number', ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'group' => $group['id']]); $teamIds = array_map(static fn (array $team): int => (int) $team['id'], $teams); $teamMap = []; foreach ($teams as $team) { $teamMap[(string) $team['id']] = $team; }
            $matches = $this->connection->fetchAllAssociative("SELECT home_team_id,away_team_id,home_goals,away_goals,home_yellow,away_yellow,home_red,away_red FROM football_matches WHERE tenant_id=:tenant AND event_id=:event AND group_id=:group AND status='played' AND counts_for_standings=1", ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'group' => $group['id']]);
            $matchValues = array_map(static fn (array $match): array => ['home' => (int) $match['home_team_id'], 'away' => (int) $match['away_team_id'], 'home_goals' => (int) $match['home_goals'], 'away_goals' => (int) $match['away_goals'], 'home_yellow' => (int) $match['home_yellow'], 'away_yellow' => (int) $match['away_yellow'], 'home_red' => (int) $match['home_red'], 'away_red' => (int) $match['away_red']], $matches);
            $lotOrder = []; foreach ($this->connection->fetchFirstColumn('SELECT ordered_team_ids FROM football_tiebreak_decisions WHERE tenant_id=:tenant AND event_id=:event AND group_id=:group ORDER BY created_at,id', ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'group' => $group['id']]) as $json) { $decoded = json_decode((string) $json, true); if (is_array($decoded)) { foreach ($decoded as $id) { $lotOrder[] = (int) $id; } } }
            $rows = $this->standings->standings($teamIds, $matchValues, ['win' => (int) $settings['points_win'], 'draw' => (int) $settings['points_draw'], 'loss' => (int) $settings['points_loss']], $lotOrder);
            foreach ($rows as &$row) { $team = $teamMap[(string) $row['team_id']]; $row['team_public_id'] = $team['public_id']; $row['team_name'] = $team['name']; $row['team_number'] = (int) $team['team_number']; $row['withdrawn_at'] = $team['withdrawn_at']; } unset($row);
            $result[(string) $group['public_id']] = ['group_id' => $group['public_id'], 'group_name' => $group['name'], 'category_id' => (int) $group['category_id'], 'category_name' => $group['category_name'], 'rows' => $rows];
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, list<array{position:int,team_id:int,team_public_id:string,team_name:string}>>
     */
    private function currentFinalRankings(array $event): array
    {
        $categories = $this->connection->fetchAllAssociative('SELECT id,public_id,name FROM football_categories WHERE tenant_id=:tenant AND event_id=:event AND active=1 ORDER BY sort_order,name', ['tenant' => $event['tenant_id'], 'event' => $event['id']]); $result = [];
        foreach ($categories as $category) {
            $matches = $this->connection->fetchAllAssociative("SELECT m.stage,m.home_team_id,m.away_team_id,m.winner_team_id,h.public_id AS home_public_id,h.name AS home_name,a.public_id AS away_public_id,a.name AS away_name FROM football_matches m LEFT JOIN event_teams h ON h.id=m.home_team_id LEFT JOIN event_teams a ON a.id=m.away_team_id WHERE m.tenant_id=:tenant AND m.event_id=:event AND m.category_id=:category AND m.stage<>'group' AND m.status='played'", ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'category' => $category['id']]);
            $ranking = []; $semifinalLosers = [];
            foreach ($matches as $match) {
                if ($match['winner_team_id'] === null || $match['home_team_id'] === null || $match['away_team_id'] === null) { continue; }
                $winnerIsHome = (int) $match['winner_team_id'] === (int) $match['home_team_id'];
                $winner = ['team_id' => (int) $match['winner_team_id'], 'team_public_id' => (string) ($winnerIsHome ? $match['home_public_id'] : $match['away_public_id']), 'team_name' => (string) ($winnerIsHome ? $match['home_name'] : $match['away_name'])];
                $loser = ['team_id' => (int) ($winnerIsHome ? $match['away_team_id'] : $match['home_team_id']), 'team_public_id' => (string) ($winnerIsHome ? $match['away_public_id'] : $match['home_public_id']), 'team_name' => (string) ($winnerIsHome ? $match['away_name'] : $match['home_name'])];
                if ($match['stage'] === 'final') { $ranking[1] = ['position' => 1, ...$winner]; $ranking[2] = ['position' => 2, ...$loser]; }
                elseif ($match['stage'] === 'third_place') { $ranking[3] = ['position' => 3, ...$winner]; $ranking[4] = ['position' => 4, ...$loser]; }
                elseif ($match['stage'] === 'semifinal') { $semifinalLosers[] = $loser; }
            }
            if (!isset($ranking[3])) { foreach ($semifinalLosers as $loser) { $ranking[] = ['position' => 3, ...$loser]; } }
            if ($ranking !== []) { ksort($ranking); $result[(string) $category['public_id']] = array_values($ranking); }
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $event
     * @return list<array{home:int,away:int,home_goals:int,away_goals:int,home_yellow:int,away_yellow:int,home_red:int,away_red:int}>
     */
    private function playedMatchesForGroup(array $event, string $groupPublicId): array
    {
        $rows = $this->connection->fetchAllAssociative("SELECT m.home_team_id,m.away_team_id,m.home_goals,m.away_goals,m.home_yellow,m.away_yellow,m.home_red,m.away_red FROM football_matches m JOIN football_groups g ON g.id=m.group_id AND g.tenant_id=m.tenant_id AND g.event_id=m.event_id WHERE m.tenant_id=:tenant AND m.event_id=:event AND g.public_id=:group AND m.status='played' AND m.counts_for_standings=1", ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'group' => $groupPublicId]);
        return array_map(static fn (array $match): array => ['home' => (int) $match['home_team_id'], 'away' => (int) $match['away_team_id'], 'home_goals' => (int) $match['home_goals'], 'away_goals' => (int) $match['away_goals'], 'home_yellow' => (int) $match['home_yellow'], 'away_yellow' => (int) $match['away_yellow'], 'home_red' => (int) $match['home_red'], 'away_red' => (int) $match['away_red']], $rows);
    }

    /**
     * @param array<string, mixed> $event
     * @return list<int>
     */
    private function crossGroupLotOrder(array $event): array
    {
        $order = [];
        foreach ($this->connection->fetchFirstColumn('SELECT ordered_team_ids FROM football_tiebreak_decisions WHERE tenant_id=:tenant AND event_id=:event AND group_id IS NULL ORDER BY created_at,id', ['tenant' => $event['tenant_id'], 'event' => $event['id']]) as $json) { $decoded = json_decode((string) $json, true); if (is_array($decoded)) { foreach ($decoded as $teamId) { $order[] = (int) $teamId; } } }
        return $order;
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $category
     * @param list<array{stage:string,home_team_id:int|string|null,away_team_id:int|string|null,home_source:?int,away_source:?int,home_outcome:?string,away_outcome:?string}> $bracket
     * @return list<array{field_id:int,start:int,end:int}>
     */
    private function scheduleBracket(array $event, array $category, array $bracket): array
    {
        $fields = $this->generatorFields($event);
        foreach ($this->validatorMatches($event) as $existing) {
            if (in_array($existing['status'], ['cancelled', 'void'], true)) { continue; }
            foreach ($fields as &$field) { if ((int) $field['id'] === (int) $existing['field_id']) { $field['blocked'][] = ['start' => $existing['start'], 'end' => $existing['start'] + $existing['duration'] * 60]; break; } } unset($field);
        }
        $lastGroupEnd = $this->connection->fetchOne("SELECT MAX(DATE_ADD(scheduled_start, INTERVAL duration_minutes MINUTE)) FROM football_matches WHERE tenant_id=:tenant AND event_id=:event AND category_id=:category AND stage='group' AND scheduled_start IS NOT NULL", ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'category' => $category['id']]);
        $notBefore = $lastGroupEnd === false || $lastGroupEnd === null ? 0 : $this->databaseTimestamp((string) $lastGroupEnd) + (int) $category['min_break_minutes'] * 60;
        $duration = (int) $category['match_minutes'] + ((string) $category['knockout_draw_mode'] === 'overtime_penalties' ? (int) $category['overtime_minutes'] : 0);
        $fieldReady = []; $scheduled = [];
        foreach ($bracket as $match) {
            $dependencyReady = $notBefore;
            foreach ([$match['home_source'], $match['away_source']] as $source) { if ($source !== null) { $dependencyReady = max($dependencyReady, $scheduled[$source]['end'] + (int) $category['min_break_minutes'] * 60); } }
            $candidates = [];
            foreach ($fields as $field) {
                $start = $this->earliestFieldSlot($field, max($dependencyReady, $fieldReady[(string) $field['id']] ?? 0), $duration);
                if ($start !== null) { $candidates[] = ['field_id' => (int) $field['id'], 'start' => $start, 'end' => $start + $duration * 60]; }
            }
            if ($candidates === []) { throw new \DomainException('Für die Finalrunde gibt es unter den Feld- und Pausenbedingungen keinen vollständigen freien Zeitraum.'); }
            usort($candidates, static fn (array $left, array $right): int => [$left['start'], $left['field_id']] <=> [$right['start'], $right['field_id']]);
            $chosen = $candidates[0]; $scheduled[] = $chosen; $fieldReady[(string) $chosen['field_id']] = $chosen['end'];
        }
        return $scheduled;
    }

    /**
     * @param array{id:int,name:string,available:list<array{start:int,end:int}>,blocked:list<array{start:int,end:int}>} $field
     */
    private function earliestFieldSlot(array $field, int $notBefore, int $durationMinutes): ?int
    {
        $duration = $durationMinutes * 60;
        foreach ($field['available'] as $window) {
            $start = max($notBefore, $window['start']);
            do { $changed = false; foreach ($field['blocked'] as $block) { if ($start < $block['end'] && $start + $duration > $block['start']) { $start = $block['end']; $changed = true; } } } while ($changed);
            if ($start + $duration <= $window['end']) { return $start; }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $event
     * @param array<string,mixed> $match
     * @return list<string>
     */
    private function bracketMoveWarnings(array $event, array $match, int $start): array
    {
        $warnings = []; $pause = (int) $match['min_break_minutes'] * 60; $end = $start + (int) $match['duration_minutes'] * 60;
        foreach ([$match['home_source_match_id'], $match['away_source_match_id']] as $sourceId) {
            if ($sourceId === null) { continue; }
            $source = $this->connection->fetchAssociative('SELECT scheduled_start,duration_minutes FROM football_matches WHERE tenant_id=:tenant AND event_id=:event AND id=:id', ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'id' => $sourceId]);
            if ($source !== false && $source['scheduled_start'] !== null && $start < $this->databaseTimestamp((string) $source['scheduled_start']) + (int) $source['duration_minutes'] * 60 + $pause) { $warnings[] = 'Die Mindestpause nach einem vorgelagerten K.-o.-Spiel wird unterschritten.'; }
        }
        $dependants = $this->connection->fetchAllAssociative('SELECT scheduled_start FROM football_matches WHERE tenant_id=:tenant AND event_id=:event AND (home_source_match_id=:match OR away_source_match_id=:match)', ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'match' => $match['id']]);
        foreach ($dependants as $dependant) { if ($dependant['scheduled_start'] !== null && $end + $pause > $this->databaseTimestamp((string) $dependant['scheduled_start'])) { $warnings[] = 'Die Mindestpause vor einem nachgelagerten K.-o.-Spiel wird unterschritten.'; } }
        return array_values(array_unique($warnings));
    }

    /**
     * @param array<string, mixed> $event
     * @return list<array{id:int,name:string,available:list<array{start:int,end:int}>,blocked:list<array{start:int,end:int}>}>
     */
    private function generatorFields(array $event): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id,name FROM football_fields WHERE tenant_id=:tenant AND event_id=:event AND active=1 ORDER BY sort_order,name', ['tenant' => $event['tenant_id'], 'event' => $event['id']]); $fields = [];
        foreach ($rows as $field) { $periods = $this->fieldPeriods($event, (int) $field['id']); if ($periods['available'] === []) { throw new \DomainException('Für das Feld '.$field['name'].' fehlt eine Verfügbarkeitszeit.'); } $fields[] = ['id' => (int) $field['id'], 'name' => (string) $field['name'], ...$periods]; }
        return $fields;
    }

    /**
     * @param array<string, mixed> $event
     * @return array{available:list<array{start:int,end:int}>,blocked:list<array{start:int,end:int}>}
     */
    private function fieldPeriods(array $event, int $fieldId): array { $result = ['available' => [], 'blocked' => []]; $rows = $this->connection->fetchAllAssociative('SELECT period_type,starts_at,ends_at FROM football_field_periods WHERE tenant_id=:tenant AND event_id=:event AND field_id=:field ORDER BY starts_at', ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'field' => $fieldId]); foreach ($rows as $row) { $period = ['start' => $this->databaseTimestamp((string) $row['starts_at']), 'end' => $this->databaseTimestamp((string) $row['ends_at'])]; if ($row['period_type'] === 'available') { $result['available'][] = $period; } else { $result['blocked'][] = $period; } } return $result; }
    /**
     * @param array<string, mixed> $event
     * @return list<array{id:int,field_id:int,start:int,duration:int,home_team_id:?int,away_team_id:?int,status:string}>
     */
    private function validatorMatches(array $event): array { $rows = $this->connection->fetchAllAssociative('SELECT id,field_id,scheduled_start,duration_minutes,home_team_id,away_team_id,status FROM football_matches WHERE tenant_id=:tenant AND event_id=:event AND field_id IS NOT NULL AND scheduled_start IS NOT NULL', ['tenant' => $event['tenant_id'], 'event' => $event['id']]); $result = []; foreach ($rows as $row) { $result[] = ['id' => (int) $row['id'], 'field_id' => (int) $row['field_id'], 'start' => $this->databaseTimestamp((string) $row['scheduled_start']), 'duration' => (int) $row['duration_minutes'], 'home_team_id' => $row['home_team_id'] === null ? null : (int) $row['home_team_id'], 'away_team_id' => $row['away_team_id'] === null ? null : (int) $row['away_team_id'], 'status' => (string) $row['status']]; } return $result; }
    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function match(array $event, string $publicId): array { $row = $this->connection->fetchAssociative('SELECT m.*,c.min_break_minutes,c.knockout_draw_mode FROM football_matches m JOIN football_categories c ON c.id=m.category_id WHERE m.tenant_id=:tenant AND m.event_id=:event AND m.public_id=:id', ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'id' => $publicId]); return $row === false ? throw new \DomainException('Spiel nicht gefunden.') : $row; }
    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function field(array $event, string $publicId): array { $row = $this->connection->fetchAssociative('SELECT id,name FROM football_fields WHERE tenant_id=:tenant AND event_id=:event AND public_id=:id AND active=1', ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'id' => $publicId]); return $row === false ? throw new \DomainException('Spielfeld nicht gefunden.') : $row; }
    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function settings(array $event): array { $row = $this->connection->fetchAssociative('SELECT * FROM football_event_settings WHERE tenant_id=:tenant AND event_id=:event', ['tenant' => $event['tenant_id'], 'event' => $event['id']]); return $row === false ? throw new \LogicException('Fussballkonfiguration fehlt.') : $row; }
    /** @param array<string, mixed> $event */
    private function teamId(array $event, string $publicId): int { $id = $this->connection->fetchOne('SELECT id FROM event_teams WHERE tenant_id=:tenant AND event_id=:event AND public_id=:id', ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'id' => $publicId]); return $id === false ? throw new \DomainException('Team nicht gefunden.') : (int) $id; }
    private function smallScore(mixed $value): ?int { if ($value === '' || $value === null) { return null; } $score = filter_var($value, FILTER_VALIDATE_INT); if ($score === false || $score < 0 || $score > 99) { throw new \DomainException('Tore müssen zwischen 0 und 99 liegen.'); } return $score; }
    private function optionalScore(mixed $value): ?int { return $this->smallScore($value); }
    private function cardCount(mixed $value): int { $count = filter_var($value, FILTER_VALIDATE_INT); if ($count === false || $count < 0 || $count > 99) { throw new \DomainException('Kartenanzahl ist ungültig.'); } return $count; }
    private function databaseTimestamp(string $value): int { try { return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp(); } catch (\Exception $exception) { throw new \LogicException('Ungültiger Datenbankzeitpunkt.', 0, $exception); } }
    /** @param array<string, mixed> $event */
    private function resolveBracketDependants(Connection $db, array $event, int $sourceMatchId, int $winner, int $loser): void
    {
        foreach (['home', 'away'] as $side) {
            $rows = $db->fetchAllAssociative('SELECT id,'.$side.'_source_outcome AS outcome FROM football_matches WHERE tenant_id=:tenant AND event_id=:event AND '.$side.'_source_match_id=:source', ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'source' => $sourceMatchId]);
            foreach ($rows as $row) {
                $team = $row['outcome'] === 'loser' ? $loser : $winner; $db->executeStatement('UPDATE football_matches SET '.$side.'_team_id=:team,updated_at=:now,lock_version=lock_version+1 WHERE id=:id AND tenant_id=:tenant AND event_id=:event', ['team' => $team, 'now' => gmdate('Y-m-d H:i:s'), 'id' => $row['id'], 'tenant' => $event['tenant_id'], 'event' => $event['id']]);
                $dependant = $db->fetchAssociative('SELECT id,status,home_team_id,away_team_id,winner_team_id FROM football_matches WHERE id=:id AND tenant_id=:tenant AND event_id=:event', ['id' => $row['id'], 'tenant' => $event['tenant_id'], 'event' => $event['id']]);
                if ($dependant === false || !in_array($dependant['status'], ['cancelled', 'void'], true) || $dependant['winner_team_id'] !== null || $dependant['home_team_id'] === null || $dependant['away_team_id'] === null) { continue; }
                $withdrawn = $db->fetchFirstColumn('SELECT team_id FROM football_team_data WHERE tenant_id=:tenant AND event_id=:event AND team_id IN (:home,:away) AND withdrawn_at IS NOT NULL', ['tenant' => $event['tenant_id'], 'event' => $event['id'], 'home' => $dependant['home_team_id'], 'away' => $dependant['away_team_id']]);
                if (count($withdrawn) !== 1) { continue; }
                $withdrawnId = (int) $withdrawn[0]; $advanced = $withdrawnId === (int) $dependant['home_team_id'] ? (int) $dependant['away_team_id'] : (int) $dependant['home_team_id'];
                $db->executeStatement('UPDATE football_matches SET winner_team_id=:winner,updated_at=:now,lock_version=lock_version+1 WHERE id=:id AND tenant_id=:tenant AND event_id=:event', ['winner' => $advanced, 'now' => gmdate('Y-m-d H:i:s'), 'id' => $dependant['id'], 'tenant' => $event['tenant_id'], 'event' => $event['id']]);
                $this->resolveBracketDependants($db, $event, (int) $dependant['id'], $advanced, $withdrawnId);
            }
        }
    }
    /** @param array<string, mixed> $event */
    private function markDraft(array $event, bool $schedule, bool $rankings): void { $sets = []; if ($schedule) { $sets[] = "schedule_state='draft'"; } if ($rankings) { $sets[] = "ranking_state='draft'"; } if ($sets === []) { return; } $this->connection->executeStatement('UPDATE football_event_settings SET '.implode(',', $sets).',updated_at=:now,lock_version=lock_version+1 WHERE tenant_id=:tenant AND event_id=:event', ['now' => gmdate('Y-m-d H:i:s'), 'tenant' => $event['tenant_id'], 'event' => $event['id']]); }
    /**
     * @param array<string, mixed> $event
     * @return array<string, array<string, mixed>>
     */
    private function activePublications(array $event): array { $rows = $this->connection->fetchAllAssociative('SELECT document_type,version_number,snapshot,published_at FROM football_publications WHERE tenant_id=:tenant AND event_id=:event AND withdrawn_at IS NULL ORDER BY version_number DESC', ['tenant' => $event['tenant_id'], 'event' => $event['id']]); $result = []; foreach ($rows as $row) { $type = (string) $row['document_type']; if (isset($result[$type])) { continue; } $snapshot = json_decode((string) $row['snapshot'], true); $result[$type] = is_array($snapshot) ? [...$snapshot, 'version' => (int) $row['version_number'], 'published_at' => $row['published_at']] : []; } return $result; }
    /** @param array<string, mixed> $event */
    private function revisionForEvent(array $event): string { $scope = ['tenant' => $event['tenant_id'], 'event' => $event['id']]; $parts = []; foreach (['football_event_settings', 'football_categories', 'football_groups', 'football_team_data', 'football_fields', 'football_matches'] as $table) { $parts[] = $this->connection->fetchAssociative('SELECT COUNT(*) AS amount,COALESCE(SUM(lock_version),0) AS versions,MAX(updated_at) AS changed FROM '.$table.' WHERE tenant_id=:tenant AND event_id=:event', $scope); } $parts[] = $this->connection->fetchAssociative('SELECT COUNT(*) AS amount,MAX(version_number) AS version,MAX(COALESCE(withdrawn_at,published_at)) AS changed FROM football_publications WHERE tenant_id=:tenant AND event_id=:event', $scope); $parts[] = $this->connection->fetchAssociative('SELECT COUNT(*) AS amount,MAX(created_at) AS changed FROM football_field_periods WHERE tenant_id=:tenant AND event_id=:event', $scope); $parts[] = $this->connection->fetchAssociative('SELECT COUNT(*) AS amount,MAX(id) AS latest FROM football_tiebreak_decisions WHERE tenant_id=:tenant AND event_id=:event', $scope); return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR)); }
}
