<?php

declare(strict_types=1);

namespace App\Tests\Football\Domain;

use App\Football\Domain\FootballCrossGroupRankingService;
use PHPUnit\Framework\TestCase;

final class FootballCrossGroupRankingServiceTest extends TestCase
{
    public function testRemovesMatchAgainstLastTeamOnlyFromLargerGroup(): void
    {
        $row = static fn (int $team, int $points, int $for, int $against): array => ['team_id' => $team, 'played' => 3, 'wins' => 1, 'draws' => 0, 'losses' => 2, 'goals_for' => $for, 'goals_against' => $against, 'goal_difference' => $for - $against, 'points' => $points, 'fairplay' => 0];
        $groups = [
            ['rows' => [$row(1, 9, 6, 0), $row(2, 6, 4, 2), $row(3, 3, 5, 5), $row(4, 0, 0, 8)], 'matches' => [['home' => 3, 'away' => 4, 'home_goals' => 4, 'away_goals' => 0, 'home_yellow' => 0, 'away_yellow' => 0, 'home_red' => 0, 'away_red' => 0]]],
            ['rows' => [$row(5, 6, 4, 1), $row(6, 3, 2, 2), $row(7, 1, 1, 3)], 'matches' => []],
        ];
        $ranked = (new FootballCrossGroupRankingService())->rankThirdPlaced($groups, ['win' => 3, 'draw' => 1, 'loss' => 0], true);
        self::assertSame([7, 3], array_column($ranked, 'team_id'));
        self::assertSame(1, $ranked[1]['goals_for']);
        self::assertTrue($ranked[1]['comparison_adjusted']);
        self::assertFalse($ranked[0]['comparison_adjusted']);
    }

    public function testRequiresAndAppliesLotAtQualificationCutoff(): void
    {
        $row = static fn (int $team): array => ['team_id' => $team, 'played' => 2, 'wins' => 0, 'draws' => 2, 'losses' => 0, 'goals_for' => 1, 'goals_against' => 1, 'goal_difference' => 0, 'points' => 2, 'fairplay' => 0];
        $groups = [
            ['rows' => [$row(10), $row(11), $row(1)], 'matches' => []],
            ['rows' => [$row(20), $row(21), $row(2)], 'matches' => []],
        ];
        $service = new FootballCrossGroupRankingService();
        $unresolved = $service->rankThirdPlaced($groups, ['win' => 3, 'draw' => 1, 'loss' => 0], false);
        self::assertSame([1, 2], $service->unresolvedTieAtCutoff($unresolved, 1));
        $resolved = $service->rankThirdPlaced($groups, ['win' => 3, 'draw' => 1, 'loss' => 0], false, [2, 1]);
        self::assertSame([2, 1], array_column($resolved, 'team_id'));
        self::assertSame([], $service->unresolvedTieAtCutoff($resolved, 1));
    }
}
