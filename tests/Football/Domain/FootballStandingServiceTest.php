<?php

declare(strict_types=1);

namespace App\Tests\Football\Domain;

use App\Football\Domain\FootballStandingService;
use PHPUnit\Framework\TestCase;

final class FootballStandingServiceTest extends TestCase
{
    public function testRanksByPointsGoalDifferenceGoalsDirectMatchAndFairplay(): void
    {
        $matches = [
            ['home' => 1, 'away' => 2, 'home_goals' => 1, 'away_goals' => 0, 'home_yellow' => 1, 'away_yellow' => 0, 'home_red' => 0, 'away_red' => 0],
            ['home' => 1, 'away' => 3, 'home_goals' => 0, 'away_goals' => 1, 'home_yellow' => 0, 'away_yellow' => 0, 'home_red' => 0, 'away_red' => 0],
            ['home' => 2, 'away' => 3, 'home_goals' => 2, 'away_goals' => 1, 'home_yellow' => 0, 'away_yellow' => 1, 'home_red' => 0, 'away_red' => 0],
        ];
        $rows = (new FootballStandingService())->standings([1, 2, 3], $matches, ['win' => 3, 'draw' => 1, 'loss' => 0]);
        self::assertSame([2, 3, 1], array_column($rows, 'team_id'));
        self::assertSame([3, 3, 3], array_column($rows, 'points'));
    }

    public function testMarksUnresolvedTieUntilLotOrderExists(): void
    {
        $service = new FootballStandingService();
        $rows = $service->standings([1, 2], [], ['win' => 3, 'draw' => 1, 'loss' => 0]);
        self::assertTrue($rows[0]['lot_required']); self::assertTrue($rows[1]['lot_required']);
        self::assertSame([2, 1], array_column($service->standings([1, 2], [], ['win' => 3, 'draw' => 1, 'loss' => 0], [2, 1]), 'team_id'));
    }
}
