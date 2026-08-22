<?php

declare(strict_types=1);

namespace App\Core\Application\Cron;

use App\Core\Application\Billing\BillingLifecycleService;
use App\Core\Application\Billing\SubscriptionRenewalService;
use App\Core\Application\Export\TenantExportBuilder;
use App\Core\Application\Notification\NotificationService;
use App\Core\Infrastructure\Mail\SystemMailer;
use App\Core\Infrastructure\Security\AuditLogger;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class CronJobRunner
{
    public function __construct(
        private Connection $connection,
        private NotificationService $notifications,
        private SystemMailer $mailer,
        private SubscriptionRenewalService $renewals,
        private BillingLifecycleService $billing,
        private AuditLogger $audit,
        private TenantExportBuilder $exportBuilder,
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
        foreach ($jobs as $job) {
            $this->connection->update('export_jobs', ['status' => 'running', 'started_at' => $now->format('Y-m-d H:i:s')], ['id' => $job['id']]);
            try {
                if ($job['export_type'] !== 'full_tenant_zip') { throw new \DomainException('Unbekannter Exporttyp.'); }
                $relative = $this->exportBuilder->build((int) $job['tenant_id'], (string) $job['public_id'], $now);
                $this->connection->update('export_jobs', ['status' => 'ready', 'storage_path' => $relative, 'finished_at' => $now->format('Y-m-d H:i:s'), 'expires_at' => $now->add(new \DateInterval('P7D'))->format('Y-m-d H:i:s')], ['id' => $job['id']]);
                $slug = (string) $this->connection->fetchOne('SELECT slug FROM tenants WHERE id = :tenant', ['tenant' => $job['tenant_id']]);
                $this->notifications->notifyAdministrativeUsers((int) $job['tenant_id'], 'export.ready', 'Export bereit', 'Der angeforderte Export steht sieben Tage zum Download bereit.', 'export.ready:'.$job['public_id'], 'info', '/v/'.$slug.'/datenexport');
                $recipient = $this->connection->fetchOne('SELECT email FROM tenant_users WHERE tenant_id = :tenant AND id = :user', ['tenant' => $job['tenant_id'], 'user' => $job['requested_by_user_id']]);
                if (is_string($recipient) && $recipient !== '') { try { $this->mailer->send($recipient, 'Datenexport bereit', 'Ihr vollständiger Vereinsdatenexport steht sieben Tage im geschützten Bereich zum Download bereit.', 'tenant_export_ready', (int) $job['tenant_id']); } catch (\RuntimeException) {} }
            } catch (\Throwable) {
                $reference = Uuid::v7()->toRfc4122();
                $this->connection->update('export_jobs', ['status' => 'failed', 'error_reference' => $reference, 'finished_at' => $now->format('Y-m-d H:i:s')], ['id' => $job['id']]);
                $this->notifications->notifyAdministrativeUsers((int) $job['tenant_id'], 'export.failed', 'Export fehlgeschlagen', 'Der Export konnte nicht erstellt werden. Referenz: '.$reference, 'export.failed:'.$job['public_id'], 'danger');
                $this->audit->logSystem('tenant_export.failed', 'export_job', (string) $job['public_id'], ['reference' => $reference, 'tenant_id' => $job['tenant_id']]);
            }
        }
        return ['processed' => count($jobs), 'cleaned' => count($cleanedRows)];
    }

    /** @return array{deleted: int, anonymized: int, warned: int, modules_expired: int} */
    public function retention(?\DateTimeImmutable $now = null, bool $preview = false): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $warned = 0;
        $nowSql = $now->format('Y-m-d H:i:s');
        $candidates = $this->connection->fetchAllAssociative("SELECT DISTINCT t.id, t.public_id FROM tenants t LEFT JOIN subscriptions s ON s.tenant_id = t.id WHERE t.status = 'suspended' AND ((s.retention_until IS NOT NULL AND s.retention_until <= :now) OR (t.trial_retention_until IS NOT NULL AND t.trial_retention_until <= :now))", ['now' => $now->format('Y-m-d H:i:s')]);
        $warnings = $this->connection->fetchAllAssociative("SELECT DISTINCT t.id, t.public_id FROM tenants t LEFT JOIN subscriptions s ON s.tenant_id = t.id WHERE t.status = 'suspended' AND ((s.retention_until BETWEEN :now AND :warning) OR (t.trial_retention_until BETWEEN :now AND :warning))", ['now' => $now->format('Y-m-d H:i:s'), 'warning' => $now->add(new \DateInterval('P7D'))->format('Y-m-d H:i:s')]);
        $expiredModules = $this->connection->fetchAllAssociative("SELECT smod.id, smod.tenant_id, smod.module_id, sm.code FROM subscription_modules smod JOIN sport_modules sm ON sm.id = smod.module_id WHERE smod.status = 'expired' AND smod.archive_until IS NOT NULL AND smod.archive_until <= :now", ['now' => $nowSql]);
        $modulesExpired = count($expiredModules);
        if ($preview) {
            $anonymized = 0; foreach ($candidates as $candidate) { if ($this->connection->fetchOne('SELECT 1 FROM invoices WHERE tenant_id = :tenant LIMIT 1', ['tenant' => $candidate['id']]) !== false) { ++$anonymized; } }
            return ['deleted' => count($candidates) - $anonymized, 'anonymized' => $anonymized, 'warned' => count($warnings), 'modules_expired' => $modulesExpired];
        }
        foreach ($warnings as $row) { $created = $this->notifications->notifyAdministrativeUsers((int) $row['id'], 'retention.deletion_warning', 'Datenlöschung in 7 Tagen', 'Ohne Reaktivierung wird der Vereinsaccount nach Ablauf der Aufbewahrungsfrist gelöscht.', 'retention.warning:'.$row['public_id'], 'danger'); if ($created > 0) { $this->mailTenantOwners((int) $row['id'], 'Datenlöschung in 7 Tagen', 'Reaktivieren Sie Ihren Account, wenn die Vereinsdaten erhalten bleiben sollen.', 'retention_warning'); } $warned += $created; }
        $this->pruneLegalRecords($nowSql);
        foreach ($expiredModules as $module) { $this->deleteModuleData((int) $module['tenant_id'], (int) $module['module_id'], (string) $module['code'], $nowSql); $this->connection->update('subscription_modules', ['status' => 'deleted'], ['id' => $module['id'], 'tenant_id' => $module['tenant_id']]); }
        $deleted = $anonymized = 0;
        foreach ($candidates as $row) {
            $hasInvoices = (bool) $this->connection->fetchOne('SELECT EXISTS(SELECT 1 FROM invoices WHERE tenant_id = :tenant)', ['tenant' => $row['id']]);
            $hash = hash('sha256', (string) $row['public_id']);
            if ($hasInvoices) {
                $counts = $this->deleteOperationalTenantData((int) $row['id']);
                $this->connection->update('tenants', ['name' => 'Gelöschter Verein '.substr($hash, 0, 12), 'slug' => 'deleted-'.substr($hash, 0, 24), 'logo_storage_path' => null, 'status' => 'closed'], ['id' => $row['id']]);
                ++$anonymized;
            } else { $counts = $this->deleteOperationalTenantData((int) $row['id']); $this->connection->delete('tenants', ['id' => $row['id']]); ++$deleted; }
            $this->connection->insert('deletion_log', ['tenant_public_id_hash' => $hash, 'reason' => 'subscription_retention_expired', 'deleted_counts' => json_encode(['account' => 1, ...$counts], JSON_THROW_ON_ERROR), 'deleted_at' => $nowSql]);
        }
        $closed = $this->connection->fetchAllAssociative("SELECT id, public_id FROM tenants t WHERE status = 'closed' AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.tenant_id = t.id)");
        foreach ($closed as $row) { $this->deleteOperationalTenantData((int) $row['id']); $this->connection->delete('tenants', ['id' => $row['id']]); $this->connection->insert('deletion_log', ['tenant_public_id_hash' => hash('sha256', (string) $row['public_id']), 'reason' => 'legal_retention_expired', 'deleted_counts' => json_encode(['account_shell' => 1], JSON_THROW_ON_ERROR), 'deleted_at' => $nowSql]); ++$deleted; }
        if ($deleted + $anonymized > 0) { $this->audit->logSystem('retention.deletion.completed', 'deletion_log', null, ['deleted' => $deleted, 'anonymized' => $anonymized]); }
        return ['deleted' => $deleted, 'anonymized' => $anonymized, 'warned' => $warned, 'modules_expired' => $modulesExpired];
    }

    private function pruneLegalRecords(string $now): void
    {
        $this->connection->executeStatement('DELETE FROM audit_log WHERE retention_until IS NOT NULL AND retention_until <= :now', ['now' => $now]);
        $this->connection->executeStatement('DELETE FROM payment_transactions WHERE retention_until <= :now', ['now' => $now]);
        $expiredInvoices = $this->connection->fetchAllAssociative('SELECT id, tenant_id, pdf_storage_path FROM invoices i WHERE retention_until <= :now AND NOT EXISTS (SELECT 1 FROM payment_transactions p WHERE p.tenant_id = i.tenant_id AND p.invoice_id = i.id)', ['now' => $now]);
        foreach ($expiredInvoices as $invoice) {
            if (is_string($invoice['pdf_storage_path']) && is_file($this->projectDirectory.'/'.$invoice['pdf_storage_path'])) { @unlink($this->projectDirectory.'/'.$invoice['pdf_storage_path']); }
            $this->connection->delete('invoice_lines', ['tenant_id' => $invoice['tenant_id'], 'invoice_id' => $invoice['id']]);
            $this->connection->delete('invoices', ['tenant_id' => $invoice['tenant_id'], 'id' => $invoice['id']]);
        }
    }

    private function deleteModuleData(int $tenantId, int $moduleId, string $moduleCode, string $now): void
    {
        $events = $this->connection->fetchAllAssociative('SELECT id, public_id FROM events WHERE tenant_id = :tenant AND module_id = :module', ['tenant' => $tenantId, 'module' => $moduleId]);
        foreach ($events as $event) { $this->unlinkEventDocuments($tenantId, (int) $event['id']); $this->connection->delete('events', ['tenant_id' => $tenantId, 'id' => $event['id']]); }
        $tenantPublicId = $this->connection->fetchOne('SELECT public_id FROM tenants WHERE id = :tenant', ['tenant' => $tenantId]);
        if (is_string($tenantPublicId)) { $this->connection->insert('deletion_log', ['tenant_public_id_hash' => hash('sha256', $tenantPublicId), 'reason' => 'module_retention_expired', 'deleted_counts' => json_encode(['module' => $moduleCode, 'events' => count($events)], JSON_THROW_ON_ERROR), 'deleted_at' => $now]); }
    }

    /** @return array<string, int> */
    private function deleteOperationalTenantData(int $tenantId): array
    {
        $counts = [];
        $logo = $this->connection->fetchOne('SELECT logo_storage_path FROM tenants WHERE id = :tenant', ['tenant' => $tenantId]);
        if (is_string($logo) && is_file($this->projectDirectory.'/'.$logo)) { @unlink($this->projectDirectory.'/'.$logo); }
        $events = $this->connection->fetchAllAssociative('SELECT id FROM events WHERE tenant_id = :tenant', ['tenant' => $tenantId]);
        foreach ($events as $event) { $this->unlinkEventDocuments($tenantId, (int) $event['id']); }
        $exports = $this->connection->fetchAllAssociative('SELECT storage_path FROM export_jobs WHERE tenant_id = :tenant', ['tenant' => $tenantId]);
        foreach ($exports as $export) { if (is_string($export['storage_path']) && is_file($this->projectDirectory.'/'.$export['storage_path'])) { @unlink($this->projectDirectory.'/'.$export['storage_path']); } }
        foreach (['events', 'participant_registry', 'team_registry', 'external_organizations', 'notifications', 'export_jobs', 'support_sessions', 'owner_transfers'] as $table) { $counts[$table] = (int) $this->connection->delete($table, ['tenant_id' => $tenantId]); }
        $templateIds = $this->connection->fetchFirstColumn('SELECT id FROM event_templates WHERE tenant_id = :tenant', ['tenant' => $tenantId]);
        foreach ($templateIds as $templateId) { $this->connection->delete('event_template_versions', ['template_id' => $templateId]); }
        $counts['event_templates'] = (int) $this->connection->delete('event_templates', ['tenant_id' => $tenantId]);
        $counts['billing_profiles'] = (int) $this->connection->delete('billing_profiles', ['tenant_id' => $tenantId]);
        $counts['audit_log'] = (int) $this->connection->executeStatement('DELETE FROM audit_log WHERE tenant_id = :tenant AND actor_platform_admin_id IS NULL', ['tenant' => $tenantId]);
        $counts['tenant_users'] = (int) $this->connection->delete('tenant_users', ['tenant_id' => $tenantId]);
        return $counts;
    }

    private function unlinkEventDocuments(int $tenantId, int $eventId): void
    {
        $paths = $this->connection->fetchFirstColumn('SELECT storage_path FROM event_documents WHERE tenant_id = :tenant AND event_id = :event', ['tenant' => $tenantId, 'event' => $eventId]);
        foreach ($paths as $path) { if (is_string($path) && is_file($this->projectDirectory.'/'.$path)) { @unlink($this->projectDirectory.'/'.$path); } }
    }

    private function mailTenantOwners(int $tenantId, string $subject, string $body, string $template): void
    {
        $owners = $this->connection->fetchFirstColumn("SELECT email FROM tenant_users WHERE tenant_id = :tenant AND tenant_role = 'owner' AND active = 1 AND deleted_at IS NULL", ['tenant' => $tenantId]);
        foreach ($owners as $email) { $this->mailer->send((string) $email, $subject, $body, $template, $tenantId); }
    }
}
