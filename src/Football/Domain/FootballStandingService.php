<?php

declare(strict_types=1);

namespace App\Football\Domain;

final readonly class FootballStandingService
{
    /**
     * @param list<int|string> $teamIds
     * @param list<array{home:int|string,away:int|string,home_goals:int,away_goals:int,home_yellow:int,away_yellow:int,home_red:int,away_red:int}> $matches
     * @param array{win:int,draw:int,loss:int} $points
     * @param list<int|string> $lotOrder
     * @return list<array{team_id:int|string,played:int,wins:int,draws:int,losses:int,goals_for:int,goals_against:int,goal_difference:int,points:int,fairplay:int,position:int,lot_required:bool}>
     */
    public function standings(array $teamIds, array $matches, array $points, array $lotOrder = []): array
    {
        $rows = [];
        foreach ($teamIds as $teamId) { $rows[(string) $teamId] = $this->emptyRow($teamId); }
        $headToHead = [];
        foreach ($matches as $match) {
            $homeKey = (string) $match['home']; $awayKey = (string) $match['away'];
            if (!isset($rows[$homeKey], $rows[$awayKey])) { continue; }
            $home = $rows[$homeKey]; $away = $rows[$awayKey];
            ++$home['played']; ++$away['played'];
            $home['goals_for'] += $match['home_goals']; $home['goals_against'] += $match['away_goals'];
            $away['goals_for'] += $match['away_goals']; $away['goals_against'] += $match['home_goals'];
            $home['fairplay'] += $match['home_yellow'] + 3 * $match['home_red']; $away['fairplay'] += $match['away_yellow'] + 3 * $match['away_red'];
            if ($match['home_goals'] > $match['away_goals']) { ++$home['wins']; ++$away['losses']; $home['points'] += $points['win']; $away['points'] += $points['loss']; $headToHead[$homeKey][$awayKey] = $points['win']; $headToHead[$awayKey][$homeKey] = $points['loss']; }
            elseif ($match['home_goals'] < $match['away_goals']) { ++$away['wins']; ++$home['losses']; $away['points'] += $points['win']; $home['points'] += $points['loss']; $headToHead[$homeKey][$awayKey] = $points['loss']; $headToHead[$awayKey][$homeKey] = $points['win']; }
            else { ++$home['draws']; ++$away['draws']; $home['points'] += $points['draw']; $away['points'] += $points['draw']; $headToHead[$homeKey][$awayKey] = $points['draw']; $headToHead[$awayKey][$homeKey] = $points['draw']; }
            $rows[$homeKey] = $home; $rows[$awayKey] = $away;
        }
        foreach ($rows as &$row) { $row['goal_difference'] = $row['goals_for'] - $row['goals_against']; } unset($row);
        $lotPositions = array_flip(array_map('strval', $lotOrder));
        $ranked = array_values($rows);
        usort($ranked, static function (array $left, array $right) use ($headToHead, $lotPositions): int {
            $comparison = [$right['points'], $right['goal_difference'], $right['goals_for']] <=> [$left['points'], $left['goal_difference'], $left['goals_for']];
            if ($comparison !== 0) { return $comparison; }
            $leftKey = (string) $left['team_id']; $rightKey = (string) $right['team_id'];
            $comparison = ($headToHead[$rightKey][$leftKey] ?? 0) <=> ($headToHead[$leftKey][$rightKey] ?? 0);
            if ($comparison !== 0) { return $comparison; }
            $comparison = $left['fairplay'] <=> $right['fairplay'];
            if ($comparison !== 0) { return $comparison; }
            return ($lotPositions[$leftKey] ?? PHP_INT_MAX) <=> ($lotPositions[$rightKey] ?? PHP_INT_MAX);
        });
        foreach ($ranked as $index => &$row) {
            $row['position'] = $index + 1;
            if ($index > 0) {
                $previous = $ranked[$index - 1];
                $previousKey = (string) $previous['team_id']; $rowKey = (string) $row['team_id'];
                $directEqual = ($headToHead[$previousKey][$rowKey] ?? 0) === ($headToHead[$rowKey][$previousKey] ?? 0);
                $row['lot_required'] = $previous['points'] === $row['points'] && $previous['goal_difference'] === $row['goal_difference'] && $previous['goals_for'] === $row['goals_for'] && $directEqual && $previous['fairplay'] === $row['fairplay'] && !isset($lotPositions[$rowKey]);
                if ($row['lot_required']) { $previous['lot_required'] = true; $ranked[$index - 1] = $previous; }
            }
        }
        unset($row);
        return $ranked;
    }

    /** @return array{team_id:int|string,played:int,wins:int,draws:int,losses:int,goals_for:int,goals_against:int,goal_difference:int,points:int,fairplay:int,position:int,lot_required:bool} */
    private function emptyRow(int|string $teamId): array
    {
        return ['team_id' => $teamId, 'played' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0, 'goals_for' => 0, 'goals_against' => 0, 'goal_difference' => 0, 'points' => 0, 'fairplay' => 0, 'position' => 0, 'lot_required' => false];
    }
}
