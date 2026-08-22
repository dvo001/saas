<?php

declare(strict_types=1);

namespace App\Core\Application\Cron;

use App\Core\Application\Billing\BillingLifecycleService;
use App\Core\Application\Billing\SubscriptionRenewalService;
use App\Core\Application\Notification\NotificationService;
use App\Core\Infrastructure\Mail\SystemMailer;
use App\Core\Infrastructure\Security\AuditLogger;
use Doctrine\DBAL\Connection;

final readonly class CronJobRunner
{
    public function __construct(
        private Connection $connection,
        private NotificationService $notifications,
        private SystemMailer $mailer,
        private SubscriptionRenewalService $renewals,
        private BillingLifecycleService $billing,
        private AuditLogger $audit,
        private string $projectDirectory,
    ) {}

    /** @return array<string, int> */
    public function billing(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $renewed = $this->renewals->processDue($now);
        $result = $this->billing->run($now);
        $notified = 0;
        $rows = $this->connection->fetchAllAssociative("SELECT i.id, i.public_id, i.tenant_id, i.invoice_number, i.status, i.due_at, i.reminder_due_at FROM invoices i WHERE i.status IN ('overdue', 'dunning')");
        foreach ($rows as $row) {
            $type = $row['status'] === 'dunning' ? 'invoice.dunning' : 'invoice.overdue';
            $title = $row['status'] === 'dunning' ? 'Account-Sperrung droht' : 'Rechnung überfällig';
            $message = sprintf('Rechnung %s ist noch offen. Bitte begleichen Sie den Betrag fristgerecht.', $row['invoice_number']);
            $created = $this->notifications->notifyAdministrativeUsers((int) $row['tenant_id'], $type, $title, $message, $type.':'.$row['public_id'], $row['status'] === 'dunning' ? 'danger' : 'warning');
            if ($created > 0) { $this->mailTenantOwners((int) $row['tenant_id'], $title, $message, $type); }
            $notified += $created;
        }
        return ['renewed' => $renewed, ...$result, 'notifications' => $notified];
    }

    /** @return array{warned: int, expired: int} */
    public function trials(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $warned = 0;
        $rows = $this->connection->fetchAllAssociative("SELECT id, public_id, trial_ends_at FROM tenants WHERE status = 'trial' AND trial_ends_at IS NOT NULL AND trial_ends_at <= :warning", ['warning' => $now->add(new \DateInterval('P7D'))->format('Y-m-d H:i:s')]);
        foreach ($rows as $row) {
            $endsAt = new \DateTimeImmutable((string) $row['trial_ends_at'], new \DateTimeZone('UTC'));
            if ($endsAt <= $now) { continue; }
            $days = max(1, (int) $now->diff($endsAt)->format('%a'));
            $keyDays = $days <= 1 ? 1 : 7;
            $title = sprintf('Testphase endet in %d Tag%s', $keyDays, $keyDays === 1 ? '' : 'en');
            $message = 'Buchen Sie ein Abo, um Ihre Vereinsplattform ohne Unterbruch weiterzuverwenden.';
            $created = $this->notifications->notifyAdministrativeUsers((int) $row['id'], 'trial.expiring', $title, $message, 'trial.expiring:'.$row['public_id'].':'.$keyDays, 'warning');
            if ($created > 0) { $this->mailTenantOwners((int) $row['id'], $title, $message, 'trial_expiring'); }
            $warned += $created;
        }
        $expired = (int) $this->connection->executeStatement("UPDATE tenants SET status = 'suspended', trial_retention_until = DATE_ADD(trial_ends_at, INTERVAL 30 DAY) WHERE status = 'trial' AND trial_ends_at <= :now", ['now' => $now->format('Y-m-d H:i:s')]);
        return ['warned' => $warned, 'expired' => $expired];
    }

    /** @return array{notified: int} */
    public function maintenance(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $windows = $this->connection->fetchAllAssociative('SELECT id, public_id, starts_at, expected_end_at, message FROM maintenance_windows WHERE cancelled_at IS NULL AND notified_at IS NULL AND starts_at BETWEEN :now AND :until', ['now' => $now->format('Y-m-d H:i:s'), 'until' => $now->add(new \DateInterval('P1D'))->format('Y-m-d H:i:s')]);
        $notified = 0;
        foreach ($windows as $window) {
            $tenants = $this->connection->fetchFirstColumn("SELECT id FROM tenants WHERE status IN ('trial', 'active', 'suspended')");
            foreach ($tenants as $tenantId) {
                $title = 'Geplante Wartung';
                $message = sprintf('Wartungsbeginn: %s UTC. %s', $window['starts_at'], $window['message']);
                $created = $this->notifications->notifyAdministrativeUsers((int) $tenantId, 'maintenance.scheduled', $title, $message, 'maintenance:'.$window['public_id'], 'warning');
                if ($created > 0) { $this->mailTenantOwners((int) $tenantId, $title, $message, 'maintenance_scheduled'); }
                $notified += $created;
            }
            $this->connection->update('maintenance_windows', ['notified_at' => $now->format('Y-m-d H:i:s')], ['id' => $window['id']]);
        }
        return ['notified' => $notified];
    }

    /** @return array{processed: int, cleaned: int} */
    public function exports(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $cleanedRows = $this->connection->fetchAllAssociative("SELECT id, storage_path FROM export_jobs WHERE expires_at <= :now AND status = 'ready'", ['now' => $now->format('Y-m-d H:i:s')]);
        foreach ($cleanedRows as $row) { if (is_string($row['storage_path']) && is_file($this->projectDirectory.'/'.$row['storage_path'])) { @unlink($this->projectDirectory.'/'.$row['storage_path']); } $this->connection->update('export_jobs', ['status' => 'expired', 'storage_path' => null], ['id' => $row['id']]); }
        $jobs = $this->connection->fetchAllAssociative("SELECT id, public_id, tenant_id, requested_by_user_id, export_type FROM export_jobs WHERE status = 'queued' ORDER BY id LIMIT 10");
        $directory = $this->projectDirectory.'/storage/exports';
        if (!is_dir($directory)) { mkdir($directory, 0770, true); }
        foreach ($jobs as $job) {
            $this->connection->update('export_jobs', ['status' => 'running', 'started_at' => $now->format('Y-m-d H:i:s')], ['id' => $job['id']]);
            $relative = 'storage/exports/'.$job['public_id'].'.json';
            file_put_contents($this->projectDirectory.'/'.$relative, json_encode(['export' => $job['export_type'], 'tenant_public_id' => $this->connection->fetchOne('SELECT public_id FROM tenants WHERE id = :id', ['id' => $job['tenant_id']]), 'created_at' => $now->format(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $this->connection->update('export_jobs', ['status' => 'ready', 'storage_path' => $relative, 'finished_at' => $now->format('Y-m-d H:i:s'), 'expires_at' => $now->add(new \DateInterval('P7D'))->format('Y-m-d H:i:s')], ['id' => $job['id']]);
            $this->notifications->notifyAdministrativeUsers((int) $job['tenant_id'], 'export.ready', 'Export bereit', 'Der angeforderte Export steht sieben Tage zum Download bereit.', 'export.ready:'.$job['public_id'], 'info');
        }
        return ['processed' => count($jobs), 'cleaned' => count($cleanedRows)];
    }

    /** @return array{deleted: int, anonymized: int, warned: int, modules_expired: int} */
    public function retention(?\DateTimeImmutable $now = null, bool $preview = false): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $warned = 0;
        $candidates = $this->connection->fetchAllAssociative("SELECT DISTINCT t.id, t.public_id FROM tenants t LEFT JOIN subscriptions s ON s.tenant_id = t.id WHERE t.status = 'suspended' AND ((s.retention_until IS NOT NULL AND s.retention_until <= :now) OR (t.trial_retention_until IS NOT NULL AND t.trial_retention_until <= :now))", ['now' => $now->format('Y-m-d H:i:s')]);
        $warnings = $this->connection->fetchAllAssociative("SELECT DISTINCT t.id, t.public_id FROM tenants t LEFT JOIN subscriptions s ON s.tenant_id = t.id WHERE t.status = 'suspended' AND ((s.retention_until BETWEEN :now AND :warning) OR (t.trial_retention_until BETWEEN :now AND :warning))", ['now' => $now->format('Y-m-d H:i:s'), 'warning' => $now->add(new \DateInterval('P7D'))->format('Y-m-d H:i:s')]);
        $modulesExpired = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM subscription_modules WHERE status = 'expired' AND archive_until IS NOT NULL AND archive_until <= :now", ['now' => $now->format('Y-m-d H:i:s')]);
        if ($preview) { return ['deleted' => count($candidates), 'anonymized' => 0, 'warned' => count($warnings), 'modules_expired' => $modulesExpired]; }
        foreach ($warnings as $row) { $created = $this->notifications->notifyAdministrativeUsers((int) $row['id'], 'retention.deletion_warning', 'Datenlöschung in 7 Tagen', 'Ohne Reaktivierung wird der Vereinsaccount nach Ablauf der Aufbewahrungsfrist gelöscht.', 'retention.warning:'.$row['public_id'], 'danger'); if ($created > 0) { $this->mailTenantOwners((int) $row['id'], 'Datenlöschung in 7 Tagen', 'Reaktivieren Sie Ihren Account, wenn die Vereinsdaten erhalten bleiben sollen.', 'retention_warning'); } $warned += $created; }
        $this->connection->executeStatement("UPDATE subscription_modules SET status = 'deleted' WHERE status = 'expired' AND archive_until IS NOT NULL AND archive_until <= :now", ['now' => $now->format('Y-m-d H:i:s')]);
        $deleted = $anonymized = 0;
        foreach ($candidates as $row) {
            $hasInvoices = (bool) $this->connection->fetchOne('SELECT EXISTS(SELECT 1 FROM invoices WHERE tenant_id = :tenant)', ['tenant' => $row['id']]);
            $hash = hash('sha256', (string) $row['public_id']);
            if ($hasInvoices) {
                $this->connection->delete('tenant_users', ['tenant_id' => $row['id']]);
                $this->connection->update('tenants', ['name' => 'Gelöschter Verein '.$hash, 'slug' => 'deleted-'.substr($hash, 0, 24), 'status' => 'closed'], ['id' => $row['id']]);
                ++$anonymized;
            } else { $this->connection->delete('tenants', ['id' => $row['id']]); ++$deleted; }
            $this->connection->insert('deletion_log', ['tenant_public_id_hash' => $hash, 'reason' => 'subscription_retention_expired', 'deleted_counts' => json_encode(['account' => 1], JSON_THROW_ON_ERROR), 'deleted_at' => $now->format('Y-m-d H:i:s')]);
        }
        if ($deleted + $anonymized > 0) { $this->audit->logSystem('retention.deletion.completed', 'deletion_log', null, ['deleted' => $deleted, 'anonymized' => $anonymized]); }
        return ['deleted' => $deleted, 'anonymized' => $anonymized, 'warned' => $warned, 'modules_expired' => $modulesExpired];
    }

    private function mailTenantOwners(int $tenantId, string $subject, string $body, string $template): void
    {
        $owners = $this->connection->fetchFirstColumn("SELECT email FROM tenant_users WHERE tenant_id = :tenant AND tenant_role = 'owner' AND active = 1 AND deleted_at IS NULL", ['tenant' => $tenantId]);
        foreach ($owners as $email) { $this->mailer->send((string) $email, $subject, $body, $template, $tenantId); }
    }
}
