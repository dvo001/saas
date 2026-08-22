<?php

declare(strict_types=1);

namespace App\Core\Application\Billing;

use App\Core\Infrastructure\Security\AuditLogger;
use Doctrine\DBAL\Connection;

final readonly class BillingLifecycleService
{
    public function __construct(private Connection $connection, private AuditLogger $audit) {}

    /** @return array{overdue: int, dunning: int, suspended: int, expired: int} */
    public function run(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $timestamp = $now->format('Y-m-d H:i:s');
        $result = $this->connection->transactional(function (Connection $db) use ($timestamp): array {
            $overdue = (int) $db->executeStatement("UPDATE invoices SET status = 'overdue' WHERE status = 'open' AND due_at < :now", ['now' => $timestamp]);
            $dunning = (int) $db->executeStatement("UPDATE invoices SET status = 'dunning' WHERE status IN ('open', 'overdue') AND reminder_due_at < :now", ['now' => $timestamp]);
            $suspended = (int) $db->executeStatement("UPDATE tenants t SET status = 'suspended' WHERE status = 'active' AND EXISTS (SELECT 1 FROM invoices i WHERE i.tenant_id = t.id AND i.status = 'dunning' AND i.reminder_due_at < :now)", ['now' => $timestamp]);
            $expired = (int) $db->executeStatement("UPDATE subscriptions SET status = 'expired', retention_until = DATE_ADD(ends_at, INTERVAL 90 DAY), updated_at = :now WHERE status IN ('active', 'cancelled') AND ends_at <= :now", ['now' => $timestamp]);
            $db->executeStatement("UPDATE subscription_modules SET status = 'expired' WHERE status = 'active' AND ends_at <= :now", ['now' => $timestamp]);
            $db->executeStatement("UPDATE tenants t SET status = 'suspended' WHERE status = 'active' AND EXISTS (SELECT 1 FROM subscriptions s WHERE s.tenant_id = t.id AND s.status = 'expired') AND NOT EXISTS (SELECT 1 FROM subscriptions a WHERE a.tenant_id = t.id AND a.status IN ('active', 'temporary') AND a.ends_at > :now)", ['now' => $timestamp]);
            return ['overdue' => $overdue, 'dunning' => $dunning, 'suspended' => $suspended, 'expired' => $expired];
        });
        $this->audit->logSystem('billing.lifecycle.completed', 'billing', null, $result);
        return $result;
    }
}
