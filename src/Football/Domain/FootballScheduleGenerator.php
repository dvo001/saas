<?php

declare(strict_types=1);

namespace App\Football\Domain;

final readonly class FootballScheduleGenerator
{
    /**
     * @param list<array{category_id:int|string,group_id:int|string,group_name:string,teams:list<int|string>,duration:int,min_break:int}> $groups
     * @param list<array{id:int|string,name:string,available:list<array{start:int,end:int}>,blocked:list<array{start:int,end:int}>}> $fields
     * @return list<array{category_id:int|string,group_id:int|string,group_name:string,round:int,home_team_id:int|string,away_team_id:int|string,field_id:int|string,start:int,duration:int}>
     */
    public function generate(array $groups, array $fields, SchedulingStrategy $strategy): array
    {
        if ($fields === []) {
            throw new ScheduleGenerationException(['Es ist kein Spielfeld konfiguriert.']);
        }

        $pending = [];
        foreach ($groups as $groupIndex => $group) {
            foreach ($this->roundRobin($group['teams']) as $round => $pairs) {
                foreach ($pairs as $pairIndex => [$home, $away]) {
                    $pending[] = [
                        'key' => sprintf('%04d-%04d-%04d', $groupIndex, $round, $pairIndex),
                        'category_id' => $group['category_id'],
                        'group_id' => $group['group_id'],
                        'group_name' => $group['group_name'],
                        'round' => $round + 1,
                        'home_team_id' => $home,
                        'away_team_id' => $away,
                        'duration' => $group['duration'],
                        'min_break' => $group['min_break'],
                    ];
                }
            }
        }

        $fieldReady = [];
        $teamReady = [];
        $teamGames = [];
        $scheduled = [];
        while ($pending !== []) {
            $candidates = [];
            foreach ($pending as $pendingIndex => $match) {
                foreach ($fields as $field) {
                    $slot = $this->earliestSlot($field, max(
                        $fieldReady[(string) $field['id']] ?? PHP_INT_MIN,
                        $teamReady[(string) $match['home_team_id']] ?? PHP_INT_MIN,
                        $teamReady[(string) $match['away_team_id']] ?? PHP_INT_MIN,
                    ), $match['duration']);
                    if ($slot === null) { continue; }
                    $gap = max(0, $slot - ($fieldReady[(string) $field['id']] ?? $slot));
                    $games = max($teamGames[(string) $match['home_team_id']] ?? 0, $teamGames[(string) $match['away_team_id']] ?? 0);
                    $score = match ($strategy) {
                        SchedulingStrategy::FieldUtilization => [$gap, $slot, $match['key'], (string) $field['id']],
                        SchedulingStrategy::Compact => [$slot + $match['duration'], $slot, $match['key'], (string) $field['id']],
                        SchedulingStrategy::Balanced => [$games, $slot, $match['key'], (string) $field['id']],
                    };
                    $candidates[] = ['pending' => $pendingIndex, 'match' => $match, 'field' => $field, 'start' => $slot, 'score' => $score];
                }
            }
            if ($candidates === []) {
                $match = reset($pending);
                throw new ScheduleGenerationException([sprintf('Für %s, Runde %d (%s gegen %s) gibt es unter Feld-, Zeit- und Pausenbedingungen keinen freien Slot.', $match['group_name'], $match['round'], $match['home_team_id'], $match['away_team_id'])]);
            }
            usort($candidates, static fn (array $left, array $right): int => $left['score'] <=> $right['score']);
            $chosen = $candidates[0];
            $match = $chosen['match'];
            $end = $chosen['start'] + $match['duration'] * 60;
            $scheduled[] = [
                'category_id' => $match['category_id'], 'group_id' => $match['group_id'], 'group_name' => $match['group_name'],
                'round' => $match['round'], 'home_team_id' => $match['home_team_id'], 'away_team_id' => $match['away_team_id'],
                'field_id' => $chosen['field']['id'], 'start' => $chosen['start'], 'duration' => $match['duration'],
            ];
            $fieldReady[(string) $chosen['field']['id']] = $end;
            foreach ([$match['home_team_id'], $match['away_team_id']] as $teamId) {
                $teamReady[(string) $teamId] = $end + $match['min_break'] * 60;
                $teamGames[(string) $teamId] = ($teamGames[(string) $teamId] ?? 0) + 1;
            }
            unset($pending[$chosen['pending']]);
        }
        usort($scheduled, static fn (array $left, array $right): int => [$left['start'], (string) $left['field_id'], (string) $left['group_id']] <=> [$right['start'], (string) $right['field_id'], (string) $right['group_id']]);
        return $scheduled;
    }

    /**
     * @param list<int|string> $teams
     * @return list<list<array{0:int|string,1:int|string}>>
     */
    public function roundRobin(array $teams): array
    {
        if (count($teams) < 2) { return []; }
        if (count($teams) % 2 !== 0) { $teams[] = null; }
        $rounds = [];
        $count = count($teams);
        for ($round = 0; $round < $count - 1; ++$round) {
            $pairs = [];
            for ($index = 0; $index < $count / 2; ++$index) {
                $left = $teams[$index]; $right = $teams[$count - 1 - $index];
                if ($left !== null && $right !== null) {
                    $pairs[] = $round % 2 === 0 ? [$left, $right] : [$right, $left];
                }
            }
            $rounds[] = $pairs;
            $fixed = array_shift($teams);
            $last = array_pop($teams);
            array_unshift($teams, $fixed, $last);
        }
        return $rounds;
    }

    /**
     * @param array{id:int|string,name:string,available:list<array{start:int,end:int}>,blocked:list<array{start:int,end:int}>} $field
     */
    private function earliestSlot(array $field, int $notBefore, int $durationMinutes): ?int
    {
        $duration = $durationMinutes * 60;
        foreach ($field['available'] as $window) {
            $start = max($notBefore, $window['start']);
            do {
                $changed = false;
                foreach ($field['blocked'] as $block) {
                    if ($start < $block['end'] && $start + $duration > $block['start']) {
                        $start = $block['end']; $changed = true;
                    }
                }
            } while ($changed);
            if ($start + $duration <= $window['end']) { return $start; }
        }
        return null;
    }
}
