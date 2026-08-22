<?php

declare(strict_types=1);

namespace App\Tests\Football\Application;

use App\Football\Application\FootballPdfService;
use PHPUnit\Framework\TestCase;

final class FootballPdfServiceTest extends TestCase
{
    public function testCreatesEveryRequiredPdfView(): void
    {
        $football = [
            'event' => ['name' => 'Dorfturnier', 'status' => 'running'],
            'categories' => [['public_id' => 'open', 'name' => 'Offen']],
            'matches' => [
                ['stage' => 'group', 'scheduled_start' => '2026-08-22 08:00:00', 'field_name' => 'Nord', 'home_name' => 'Blau', 'away_name' => 'Rot', 'status' => 'played', 'home_goals' => 2, 'away_goals' => 1, 'category_name' => 'Offen', 'sequence_number' => 1],
                ['stage' => 'final', 'scheduled_start' => '2026-08-22 12:00:00', 'field_name' => 'Nord', 'home_name' => 'Blau', 'away_name' => 'Grün', 'status' => 'played', 'home_goals' => 1, 'away_goals' => 1, 'home_penalties' => 5, 'away_penalties' => 4, 'category_name' => 'Offen', 'sequence_number' => 2],
            ],
            'standings' => [['category_name' => 'Offen', 'group_name' => 'A', 'rows' => [['position' => 1, 'team_name' => 'Blau', 'played' => 1, 'goals_for' => 2, 'goals_against' => 1, 'points' => 3]]]],
            'final_rankings' => ['open' => [['position' => 1, 'team_name' => 'Blau']]],
        ];
        $service = new FootballPdfService();
        foreach (['schedule', 'schedule_category', 'schedule_field', 'schedule_time', 'standings', 'finals', 'final_rankings'] as $document) { self::assertStringStartsWith('%PDF-', $service->create($football, $document), $document); }
    }

    public function testCancelledTournamentCannotCreateOfficialPdf(): void
    {
        $this->expectException(\DomainException::class);
        (new FootballPdfService())->create(['event' => ['status' => 'cancelled']], 'schedule');
    }
}
