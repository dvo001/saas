<?php

declare(strict_types=1);

namespace App\Tests\Football\Domain;

use App\Football\Domain\FootballScheduleGenerator;
use App\Football\Domain\ScheduleGenerationException;
use App\Football\Domain\SchedulingStrategy;
use PHPUnit\Framework\TestCase;

final class FootballScheduleGeneratorTest extends TestCase
{
    public function testRoundRobinProducesEveryPairExactlyOnce(): void
    {
        $rounds = (new FootballScheduleGenerator())->roundRobin([1, 2, 3, 4, 5]);
        $pairs = [];
        foreach ($rounds as $round) {
            $seen = [];
            foreach ($round as [$home, $away]) {
                self::assertArrayNotHasKey((string) $home, $seen); self::assertArrayNotHasKey((string) $away, $seen);
                $seen[(string) $home] = true; $seen[(string) $away] = true;
                $pair = [$home, $away]; sort($pair); $pairs[] = implode('-', $pair);
            }
        }
        self::assertCount(10, $pairs);
        self::assertCount(10, array_unique($pairs));
    }

    public function testScheduleHonoursFieldsBlocksAndTeamBreaks(): void
    {
        $schedule = (new FootballScheduleGenerator())->generate(
            [['category_id' => 1, 'group_id' => 1, 'group_name' => 'A', 'teams' => [1, 2, 3, 4], 'duration' => 10, 'min_break' => 10]],
            [
                ['id' => 1, 'name' => 'Nord', 'available' => [['start' => 0, 'end' => 7200]], 'blocked' => [['start' => 1200, 'end' => 1800]]],
                ['id' => 2, 'name' => 'Süd', 'available' => [['start' => 0, 'end' => 7200]], 'blocked' => []],
            ],
            SchedulingStrategy::Compact,
        );
        self::assertCount(6, $schedule);
        foreach ($schedule as $match) {
            if ($match['field_id'] === 1) { self::assertFalse($match['start'] < 1800 && $match['start'] + 600 > 1200); }
        }
        foreach ([1, 2, 3, 4] as $team) {
            $games = array_values(array_filter($schedule, static fn (array $match): bool => $match['home_team_id'] === $team || $match['away_team_id'] === $team));
            usort($games, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
            for ($index = 1; $index < count($games); ++$index) { self::assertGreaterThanOrEqual($games[$index - 1]['start'] + 1200, $games[$index]['start']); }
        }
    }

    public function testImpossibleScheduleReportsConcreteMatch(): void
    {
        $this->expectException(ScheduleGenerationException::class);
        $this->expectExceptionMessage('Runde');
        (new FootballScheduleGenerator())->generate(
            [['category_id' => 1, 'group_id' => 1, 'group_name' => 'A', 'teams' => [1, 2], 'duration' => 20, 'min_break' => 5]],
            [['id' => 1, 'name' => 'Nord', 'available' => [['start' => 0, 'end' => 600]], 'blocked' => []]],
            SchedulingStrategy::FieldUtilization,
        );
    }
}
