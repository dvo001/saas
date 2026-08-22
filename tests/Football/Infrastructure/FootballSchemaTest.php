<?php

declare(strict_types=1);

namespace App\Tests\Football\Infrastructure;

use PHPUnit\Framework\TestCase;

final class FootballSchemaTest extends TestCase
{
    public function testSchemaCoversTournamentLifecycleAndTenantScopedRelations(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 3).'/migrations/Version20260821080000.php');
        self::assertIsString($migration);
        foreach (['football_event_settings', 'football_categories', 'football_groups', 'football_team_data', 'football_fields', 'football_field_periods', 'football_matches', 'football_tiebreak_decisions', 'football_publications'] as $table) { self::assertStringContainsString('CREATE TABLE '.$table, $migration); }
        foreach (['fk_football_match_category', 'fk_football_match_group', 'fk_football_match_field', 'fk_football_match_home', 'fk_football_match_away', 'fk_football_match_winner'] as $constraint) { self::assertStringContainsString('CONSTRAINT '.$constraint.' FOREIGN KEY (tenant_id, event_id,', $migration); }
        self::assertStringContainsString('UNIQUE INDEX uniq_football_match_scope (tenant_id, event_id, id)', $migration);
        self::assertStringContainsString('UNIQUE INDEX uniq_football_publication_version (tenant_id, event_id, document_type, version_number)', $migration);
    }
}
