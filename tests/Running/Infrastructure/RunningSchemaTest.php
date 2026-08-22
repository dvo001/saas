<?php
declare(strict_types=1);
namespace App\Tests\Running\Infrastructure;
use PHPUnit\Framework\Attributes\CoversNothing;use PHPUnit\Framework\TestCase;
#[CoversNothing]
final class RunningSchemaTest extends TestCase
{
 public function testSchemaSupportsDynamicRunsFinalsAndTenantScope():void{$migration=file_get_contents(dirname(__DIR__,3).'/migrations/Version20260821070000.php');self::assertIsString($migration);foreach(['running_event_settings','running_categories','running_participant_data','running_qualification_results','running_final_results'] as $table){self::assertStringContainsString('CREATE TABLE '.$table,$migration);}self::assertStringContainsString('UNIQUE INDEX uniq_running_qualification(tenant_id,event_id,participant_id,run_number)',$migration);self::assertStringContainsString('UNIQUE INDEX uniq_running_start_number(tenant_id,event_id,start_number)',$migration);self::assertGreaterThanOrEqual(4,substr_count($migration,'lock_version'));}
}
