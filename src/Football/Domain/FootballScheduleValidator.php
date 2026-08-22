<?php

declare(strict_types=1);

namespace App\Football\Domain;

final readonly class FootballScheduleValidator
{
    /**
     * @param array{id:int|string,field_id:int|string,start:int,duration:int,home_team_id:int|string|null,away_team_id:int|string|null} $candidate
     * @param list<array{id:int|string,field_id:int|string,start:int,duration:int,home_team_id:int|string|null,away_team_id:int|string|null,status:string}> $matches
     * @param array{available:list<array{start:int,end:int}>,blocked:list<array{start:int,end:int}>} $field
     * @return list<string>
     */
    public function validate(array $candidate, array $matches, array $field, int $minBreakMinutes): array
    {
        $warnings = []; $end = $candidate['start'] + $candidate['duration'] * 60;
        $available = false;
        foreach ($field['available'] as $window) { if ($candidate['start'] >= $window['start'] && $end <= $window['end']) { $available = true; break; } }
        if (!$available) { $warnings[] = 'Das Spiel liegt ausserhalb der Feldverfügbarkeit.'; }
        foreach ($field['blocked'] as $block) { if ($candidate['start'] < $block['end'] && $end > $block['start']) { $warnings[] = 'Das Spielfeld ist in diesem Zeitraum gesperrt.'; break; } }
        foreach ($matches as $match) {
            if ((string) $match['id'] === (string) $candidate['id'] || in_array($match['status'], ['cancelled', 'void'], true)) { continue; }
            $matchEnd = $match['start'] + $match['duration'] * 60;
            if ((string) $match['field_id'] === (string) $candidate['field_id'] && $candidate['start'] < $matchEnd && $end > $match['start']) { $warnings[] = 'Das Spielfeld ist durch ein anderes Spiel belegt.'; }
            $sharedTeam = array_intersect(array_filter([$candidate['home_team_id'], $candidate['away_team_id']], static fn (mixed $id): bool => $id !== null), array_filter([$match['home_team_id'], $match['away_team_id']], static fn (mixed $id): bool => $id !== null));
            if ($sharedTeam === []) { continue; }
            if ($candidate['start'] < $matchEnd && $end > $match['start']) { $warnings[] = 'Ein Team wäre gleichzeitig in zwei Spielen eingesetzt.'; continue; }
            $pause = $candidate['start'] >= $matchEnd ? $candidate['start'] - $matchEnd : $match['start'] - $end;
            if ($pause < $minBreakMinutes * 60) { $warnings[] = 'Die Mindestpause eines Teams wird unterschritten.'; }
        }
        return array_values(array_unique($warnings));
    }
}
