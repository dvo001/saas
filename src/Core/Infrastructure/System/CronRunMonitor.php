<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\System;

use App\Core\Infrastructure\Security\AuditLogger;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class CronRunMonitor
{
    public function __construct(private Connection $connection, private AuditLogger $audit) {}

    public function start(string $jobName): string
    {
        $publicId = Uuid::v7()->toRfc4122();
        $this->connection->insert('cron_runs', [
            'public_id' => $publicId,
            'job_name' => $jobName,
            'started_at' => gmdate('Y-m-d H:i:s'),
            'finished_at' => null,
            'status' => 'running',
            'error_reference' => null,
        ]);

        return $publicId;
    }

    /** @param array<string, mixed> $context */
    public function succeed(string $publicId, string $jobName, array $context = []): void
    {
        $this->connection->update('cron_runs', ['finished_at' => gmdate('Y-m-d H:i:s'), 'status' => 'success', 'result_context' => json_encode($context, JSON_THROW_ON_ERROR)], ['public_id' => $publicId]);
        $this->audit->logSystem('cron.succeeded', 'cron_run', $publicId, ['job_name' => $jobName, ...$context]);
    }

    public function fail(string $publicId, string $jobName, string $errorReference): void
    {
        $this->connection->update('cron_runs', [
            'finished_at' => gmdate('Y-m-d H:i:s'),
            'status' => 'failed',
            'error_reference' => $errorReference,
        ], ['public_id' => $publicId]);
        $this->audit->logSystem('cron.failed', 'cron_run', $publicId, ['job_name' => $jobName, 'error_reference' => $errorReference]);
    }
}
