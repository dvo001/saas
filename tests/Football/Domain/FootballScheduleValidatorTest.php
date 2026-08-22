<?php

declare(strict_types=1);

namespace App\Tests\Football\Domain;

use App\Football\Domain\FootballScheduleValidator;
use PHPUnit\Framework\TestCase;

final class FootballScheduleValidatorTest extends TestCase
{
    public function testReportsFieldTeamAndBreakConflicts(): void
    {
        $warnings = (new FootballScheduleValidator())->validate(
            ['id' => 2, 'field_id' => 1, 'start' => 500, 'duration' => 10, 'home_team_id' => 1, 'away_team_id' => 3],
            [['id' => 1, 'field_id' => 1, 'start' => 0, 'duration' => 10, 'home_team_id' => 1, 'away_team_id' => 2, 'status' => 'scheduled']],
            ['available' => [['start' => 0, 'end' => 3600]], 'blocked' => [['start' => 900, 'end' => 1200]]],
            10,
        );
        self::assertContains('Das Spielfeld ist durch ein anderes Spiel belegt.', $warnings);
        self::assertContains('Ein Team wäre gleichzeitig in zwei Spielen eingesetzt.', $warnings);
        self::assertContains('Das Spielfeld ist in diesem Zeitraum gesperrt.', $warnings);
    }
}
