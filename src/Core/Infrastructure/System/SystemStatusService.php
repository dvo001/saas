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
            SELECT cr.job_name, cr.started_at, cr.finished_at, cr.status, cr.error_reference
            FROM cron_runs cr
            INNER JOIN (SELECT job_name, MAX(id) AS max_id FROM cron_runs GROUP BY job_name) latest ON latest.max_id = cr.id
            ORDER BY cr.job_name
            SQL);
        $hasFailedCron = false;
        foreach ($cronRuns as $run) {
            if ($run['status'] === 'failed') {
                $hasFailedCron = true;
                break;
            }
        }
        $maintenance = $this->maintenance->active();

        return [
            'overall' => $maintenance !== null ? 'maintenance' : ($hasFailedCron ? 'degraded' : 'operational'),
            'maintenance' => $maintenance,
            'next_maintenance' => $this->maintenance->next(),
            'mail' => str_starts_with($this->mailerDsn, 'null://') ? 'development' : 'configured',
            'cron_runs' => $cronRuns,
            'cron_intervals' => $this->settings->get('cron.intervals', []),
        ];
    }
}
