<?php
declare(strict_types=1);
namespace App\Tests\Core\Infrastructure\Event;
use PHPUnit\Framework\Attributes\CoversNothing; use PHPUnit\Framework\TestCase;
#[CoversNothing]
final class ParticipantTeamSchemaTest extends TestCase
{
    public function testSchemaScopesMembershipToOneTeamInSameEvent():void{$migration=file_get_contents(dirname(__DIR__,4).'/migrations/Version20260821060000.php');self::assertIsString($migration);foreach(['external_organizations','participant_registry','team_registry','event_participants','event_teams','event_team_memberships'] as $table){self::assertStringContainsString('CREATE TABLE '.$table,$migration);}self::assertStringContainsString('UNIQUE INDEX uniq_event_participant_team (tenant_id, event_id, participant_id)',$migration);self::assertStringContainsString('FOREIGN KEY (tenant_id, event_id, team_id)',$migration);self::assertStringContainsString('FOREIGN KEY (tenant_id, event_id, participant_id)',$migration);}
}
