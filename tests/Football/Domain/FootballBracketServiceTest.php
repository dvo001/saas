<?php

declare(strict_types=1);

namespace App\Tests\Football\Domain;

use App\Football\Domain\FootballBracketService;
use PHPUnit\Framework\TestCase;

final class FootballBracketServiceTest extends TestCase
{
    public function testCreatesQuarterfinalBracketWithThirdPlaceAndFinal(): void
    {
        $matches = (new FootballBracketService())->create('quarterfinal_semifinal_final', range(1, 8), true);
        self::assertCount(8, $matches);
        self::assertSame(['quarterfinal', 'quarterfinal', 'quarterfinal', 'quarterfinal', 'semifinal', 'semifinal', 'third_place', 'final'], array_column($matches, 'stage'));
        self::assertSame([1, 8], [$matches[0]['home_team_id'], $matches[0]['away_team_id']]);
        self::assertSame('loser', $matches[6]['home_outcome']);
    }
}
