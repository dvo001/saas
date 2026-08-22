<?php

declare(strict_types=1);

namespace App\Football\Domain;

final readonly class FootballBracketService
{
    /**
     * @param list<int|string> $seededTeams
     * @return list<array{stage:string,home_team_id:int|string|null,away_team_id:int|string|null,home_source:?int,away_source:?int,home_outcome:?string,away_outcome:?string}>
     */
    public function create(string $mode, array $seededTeams, bool $thirdPlace): array
    {
        $required = match ($mode) { 'final_only' => 2, 'semifinal_final' => 4, 'quarterfinal_semifinal_final' => 8, default => throw new \DomainException('Unbekannter Finalmodus.') };
        if (count($seededTeams) < $required) { throw new \DomainException(sprintf('Für den Finalmodus werden mindestens %d qualifizierte Teams benötigt.', $required)); }
        $teams = array_slice($seededTeams, 0, $required);
        $matches = [];
        $pairings = match ($required) { 2 => [[0, 1]], 4 => [[0, 3], [1, 2]], 8 => [[0, 7], [3, 4], [1, 6], [2, 5]] };
        $firstStage = match ($required) { 2 => 'final', 4 => 'semifinal', 8 => 'quarterfinal' };
        foreach ($pairings as [$home, $away]) { $matches[] = ['stage' => $firstStage, 'home_team_id' => $teams[$home], 'away_team_id' => $teams[$away], 'home_source' => null, 'away_source' => null, 'home_outcome' => null, 'away_outcome' => null]; }
        if ($required === 8) {
            $matches[] = ['stage' => 'semifinal', 'home_team_id' => null, 'away_team_id' => null, 'home_source' => 0, 'away_source' => 1, 'home_outcome' => 'winner', 'away_outcome' => 'winner'];
            $matches[] = ['stage' => 'semifinal', 'home_team_id' => null, 'away_team_id' => null, 'home_source' => 2, 'away_source' => 3, 'home_outcome' => 'winner', 'away_outcome' => 'winner'];
        }
        if ($required > 2) {
            $semiOffset = $required === 8 ? 4 : 0;
            if ($thirdPlace) { $matches[] = ['stage' => 'third_place', 'home_team_id' => null, 'away_team_id' => null, 'home_source' => $semiOffset, 'away_source' => $semiOffset + 1, 'home_outcome' => 'loser', 'away_outcome' => 'loser']; }
            $matches[] = ['stage' => 'final', 'home_team_id' => null, 'away_team_id' => null, 'home_source' => $semiOffset, 'away_source' => $semiOffset + 1, 'home_outcome' => 'winner', 'away_outcome' => 'winner'];
        }
        return $matches;
    }
}
