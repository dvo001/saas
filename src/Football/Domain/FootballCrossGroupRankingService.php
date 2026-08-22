<?php

declare(strict_types=1);

namespace App\Football\Domain;

final readonly class FootballCrossGroupRankingService
{
    /**
     * @param list<array{rows:list<array<string,mixed>>,matches:list<array{home:int,away:int,home_goals:int,away_goals:int,home_yellow:int,away_yellow:int,home_red:int,away_red:int}>}> $groups
     * @param array{win:int,draw:int,loss:int} $points
     * @param list<int|string> $lotOrder
     * @return list<array<string,mixed>>
     */
    public function rankThirdPlaced(array $groups, array $points, bool $excludeLast, array $lotOrder = []): array
    {
        $groups = array_values(array_filter($groups, static fn (array $group): bool => count($group['rows']) >= 3));
        if ($groups === []) { return []; }
        $minimumGroupSize = min(array_map(static fn (array $group): int => count($group['rows']), $groups));
        $thirds = [];
        foreach ($groups as $group) {
            $candidate = $group['rows'][2];
            $candidate['comparison_adjusted'] = false;
            if ($excludeLast && count($group['rows']) > $minimumGroupSize) {
                $last = $group['rows'][array_key_last($group['rows'])];
                foreach ($group['matches'] as $match) {
                    if ($this->isPair($match, (int) $candidate['team_id'], (int) $last['team_id'])) {
                        $candidate = $this->removeMatch($candidate, $match, $points);
                        $candidate['comparison_adjusted'] = true;
                        break;
                    }
                }
            }
            $thirds[] = $candidate;
        }
        $lotPositions = array_flip(array_map('strval', $lotOrder));
        usort($thirds, static function (array $left, array $right) use ($lotPositions): int {
            $comparison = [$right['points'], $right['goal_difference'], $right['goals_for'], -$right['fairplay']] <=> [$left['points'], $left['goal_difference'], $left['goals_for'], -$left['fairplay']];
            if ($comparison !== 0) { return $comparison; }
            $leftKey = (string) $left['team_id']; $rightKey = (string) $right['team_id'];
            $comparison = ($lotPositions[$leftKey] ?? PHP_INT_MAX) <=> ($lotPositions[$rightKey] ?? PHP_INT_MAX);
            return $comparison !== 0 ? $comparison : ((int) $left['team_id'] <=> (int) $right['team_id']);
        });
        foreach ($thirds as &$third) { $third['cross_group_lot_required'] = false; } unset($third);
        foreach ($thirds as $index => $third) {
            $ties = array_filter($thirds, fn (array $candidate): bool => $this->comparisonKey($candidate) === $this->comparisonKey($third));
            if (count($ties) > 1 && count(array_filter($ties, static fn (array $candidate): bool => isset($lotPositions[(string) $candidate['team_id']]))) !== count($ties)) { $thirds[$index]['cross_group_lot_required'] = true; }
        }
        return $thirds;
    }

    /**
     * @param list<array<string,mixed>> $ranked
     * @return list<int>
     */
    public function unresolvedTieAtCutoff(array $ranked, int $qualifyingPlaces): array
    {
        if ($qualifyingPlaces < 1 || $qualifyingPlaces >= count($ranked)) { return []; }
        $boundary = $ranked[$qualifyingPlaces - 1];
        if (empty($boundary['cross_group_lot_required'])) { return []; }
        return array_values(array_map(static fn (array $row): int => (int) $row['team_id'], array_filter($ranked, fn (array $row): bool => $this->comparisonKey($row) === $this->comparisonKey($boundary))));
    }

    /**
     * @param array<string,mixed> $row
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function comparisonKey(array $row): array { return [(int) $row['points'], (int) $row['goal_difference'], (int) $row['goals_for'], (int) $row['fairplay']]; }

    /** @param array{home:int,away:int,home_goals:int,away_goals:int,home_yellow:int,away_yellow:int,home_red:int,away_red:int} $match */
    private function isPair(array $match, int $first, int $second): bool
    {
        return ($match['home'] === $first && $match['away'] === $second) || ($match['home'] === $second && $match['away'] === $first);
    }

    /**
     * @param array<string,mixed> $row
     * @param array{home:int,away:int,home_goals:int,away_goals:int,home_yellow:int,away_yellow:int,home_red:int,away_red:int} $match
     * @param array{win:int,draw:int,loss:int} $points
     * @return array<string,mixed>
     */
    private function removeMatch(array $row, array $match, array $points): array
    {
        $home = $match['home'] === (int) $row['team_id'];
        $for = $home ? $match['home_goals'] : $match['away_goals'];
        $against = $home ? $match['away_goals'] : $match['home_goals'];
        $yellow = $home ? $match['home_yellow'] : $match['away_yellow'];
        $red = $home ? $match['home_red'] : $match['away_red'];
        $outcome = $for > $against ? 'wins' : ($for < $against ? 'losses' : 'draws');
        $row['played'] = max(0, (int) $row['played'] - 1);
        $row[$outcome] = max(0, (int) $row[$outcome] - 1);
        $row['goals_for'] = (int) $row['goals_for'] - $for;
        $row['goals_against'] = (int) $row['goals_against'] - $against;
        $row['goal_difference'] = (int) $row['goals_for'] - (int) $row['goals_against'];
        $row['fairplay'] = max(0, (int) $row['fairplay'] - $yellow - 3 * $red);
        $row['points'] = (int) $row['points'] - $points[$outcome === 'wins' ? 'win' : ($outcome === 'losses' ? 'loss' : 'draw')];
        return $row;
    }
}
