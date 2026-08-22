<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\System;

use App\Core\Infrastructure\Maintenance\MaintenanceService;
use App\Core\Infrastructure\Settings\PlatformSettings;
use Doctrine\DBAL\Connection;

final readonly class SystemStatusService
{
    public function __construct(
        private Connection $connection,
        private MaintenanceService $maintenance,
        private PlatformSettings $settings,
        private string $mailerDsn,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $cronRuns = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT cr.job_name, cr.started_at, cr.finished_at, cr.status, cr.error_reference, cr.result_context
            FROM cron_runs cr
            INNER JOIN (SELECT job_name, MAX(id) AS max_id FROM cron_runs GROUP BY job_name) latest ON latest.max_id = cr.id
            ORDER BY cr.job_name
            SQL);
        $intervals = $this->settings->get('cron.intervals', ['default_minutes' => 15, 'retention_minutes' => 1440]);
        $defaultMinutes = is_array($intervals) && isset($intervals['dispatcher_minutes']) ? max(1, (int) $intervals['dispatcher_minutes']) : 5;
        $hasFailedCron = false;
        foreach ($cronRuns as &$run) {
            $startedAt = new \DateTimeImmutable((string) $run['started_at'], new \DateTimeZone('UTC'));
            $run['overdue'] = $startedAt < new \DateTimeImmutable('-'.($defaultMinutes * 2).' minutes', new \DateTimeZone('UTC'));
            if ($run['status'] === 'failed' || $run['overdue']) { $hasFailedCron = true; }
        }
        unset($run);
        $maintenance = $this->maintenance->active();

        return [
            'overall' => $maintenance !== null ? 'maintenance' : ($hasFailedCron ? 'degraded' : 'operational'),
            'maintenance' => $maintenance,
            'next_maintenance' => $this->maintenance->next(),
            'mail' => str_starts_with($this->mailerDsn, 'null://') ? 'development' : ((bool) $this->connection->fetchOne("SELECT EXISTS(SELECT 1 FROM mail_deliveries WHERE status = 'failed' AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR))") ? 'degraded' : 'configured'),
            'cron_runs' => $cronRuns,
            'cron_history' => $this->connection->fetchAllAssociative('SELECT job_name, started_at, finished_at, status, error_reference, result_context FROM cron_runs ORDER BY started_at DESC LIMIT 100'),
            'cron_intervals' => $intervals,
        ];
    }
}
