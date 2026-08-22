<?php

declare(strict_types=1);

namespace App\Tests\Core\Infrastructure\System;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class NotificationCronSchemaTest extends TestCase
{
    public function testMigrationProvidesMilestoneFiveOperationalTables(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 4).'/migrations/Version20260821040000.php');
        self::assertIsString($migration);
        foreach (['notifications', 'mail_deliveries', 'export_jobs', 'deletion_log', 'result_context', 'notified_at'] as $required) {
            self::assertStringContainsString($required, $migration);
        }
        self::assertStringContainsString('recipient_user_id', $migration);
        self::assertStringContainsString('deduplication_key', $migration);
    }

    public function testCronRunnerContainsAllRequiredOperationalJobs(): void
    {
        $runner = file_get_contents(dirname(__DIR__, 4).'/src/Core/Presentation/Cli/CronRunnerCommand.php');
        self::assertIsString($runner);
        foreach (['trials', 'billing', 'maintenance', 'exports', 'retention'] as $job) {
            self::assertStringContainsString("'{$job}'", $runner);
        }
        self::assertStringContainsString("name: 'app:cron:run'", $runner);
        self::assertStringContainsString("'preview'", $runner);
    }
}
