<?php

declare(strict_types=1);

namespace App\Core\Application\Billing;

use App\Core\Domain\Billing\InvoiceCalculator;
use App\Core\Domain\Billing\Money;
use App\Core\Domain\Billing\SubscriptionPolicy;
use App\Core\Infrastructure\Security\AuditLogger;
use App\Core\Infrastructure\Settings\PlatformSettings;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class SubscriptionRenewalService
{
    public function __construct(private Connection $connection, private InvoiceCalculator $calculator, private SubscriptionPolicy $policy, private PlatformSettings $settings, private AuditLogger $audit, private InvoiceDeliveryService $delivery) {}

    public function processDue(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $until = $now->add(new \DateInterval('P30D'));
        $ids = $this->connection->fetchFirstColumn("SELECT id FROM subscriptions WHERE status = 'active' AND auto_renew = 1 AND ends_at > :now AND ends_at <= :until ORDER BY ends_at", ['now' => $now->format('Y-m-d H:i:s'), 'until' => $until->format('Y-m-d H:i:s')]);
        $renewed = 0;
        foreach ($ids as $id) { if ($this->renew((int) $id, $now)) { ++$renewed; } }
        if ($renewed > 0) { $this->audit->logSystem('billing.renewals.created', 'subscription', null, ['count' => $renewed]); }
        return $renewed;
    }

    private function renew(int $subscriptionId, \DateTimeImmutable $now): bool
    {
        $result = $this->connection->transactional(function (Connection $db) use ($subscriptionId, $now): ?array {
            $subscription = $db->fetchAssociative("SELECT * FROM subscriptions WHERE id = :id AND status = 'active' AND auto_renew = 1 AND ends_at > :now AND ends_at <= :until FOR UPDATE", ['id' => $subscriptionId, 'now' => $now->format('Y-m-d H:i:s'), 'until' => $now->add(new \DateInterval('P30D'))->format('Y-m-d H:i:s')]);
            if ($subscription === false) { return null; }
            $profile = $db->fetchAssociative('SELECT * FROM billing_profiles WHERE tenant_id = :tenant AND invoice_email_confirmed = 1', ['tenant' => $subscription['tenant_id']]);
            if ($profile === false) { throw new \DomainException('Für die automatische Verlängerung fehlen bestätigte Rechnungsdaten.'); }
            $items = $db->fetchAllAssociative(<<<'SQL'
                SELECT smod.id AS subscription_module_id, smod.module_role, sm.code AS module_code, bp.name,
                       pv.id AS price_id, pv.amount_minor, pv.currency
                FROM subscription_modules smod INNER JOIN sport_modules sm ON sm.id = smod.module_id
                INNER JOIN price_versions old_price ON old_price.id = smod.price_version_id
                INNER JOIN billing_products bp ON bp.id = old_price.billing_product_id
                INNER JOIN price_versions pv ON pv.id = (SELECT p.id FROM price_versions p WHERE p.billing_product_id = bp.id AND p.valid_from <= :now ORDER BY p.valid_from DESC, p.id DESC LIMIT 1)
                WHERE smod.subscription_id = :subscription AND smod.tenant_id = :tenant AND (smod.module_role = 'main' OR smod.renew = 1)
                ORDER BY smod.module_role DESC, sm.name
                SQL, ['now' => $now->format('Y-m-d H:i:s'), 'subscription' => $subscriptionId, 'tenant' => $subscription['tenant_id']]);
            $previousMain = $db->fetchAssociative(<<<'SQL'
                SELECT bp.name, pv.id AS price_id, pv.amount_minor, pv.currency FROM invoices i
                INNER JOIN invoice_lines il ON il.invoice_id = i.id AND il.tenant_id = i.tenant_id AND il.position = 1
                INNER JOIN price_versions old_price ON old_price.id = il.price_version_id
                INNER JOIN billing_products bp ON bp.id = old_price.billing_product_id
                INNER JOIN price_versions pv ON pv.id = (SELECT p.id FROM price_versions p WHERE p.billing_product_id = bp.id AND p.valid_from <= :now ORDER BY p.valid_from DESC, p.id DESC LIMIT 1)
                WHERE i.subscription_id = :subscription AND i.tenant_id = :tenant ORDER BY i.issued_at DESC LIMIT 1
                SQL, ['now' => $now->format('Y-m-d H:i:s'), 'subscription' => $subscriptionId, 'tenant' => $subscription['tenant_id']]);
            if ($previousMain === false || $items === []) { throw new \DomainException('Die Preisgrundlage der Verlängerung ist unvollständig.'); }
            $allItems = array_merge([$previousMain], $items);
            $money = array_map(static fn (array $item): Money => new Money((int) $item['amount_minor'], (string) $item['currency']), $allItems);
            $coupon = $db->fetchAssociative("SELECT * FROM coupons WHERE tenant_id = :tenant AND coupon_type = 'compassion' AND redeemed_at IS NULL AND valid_from <= :now AND (valid_until IS NULL OR valid_until >= :now) ORDER BY created_at LIMIT 1 FOR UPDATE", ['tenant' => $subscription['tenant_id'], 'now' => $now->format('Y-m-d H:i:s')]);
            $discountRate = $coupon === false ? 0 : (int) $coupon['percentage_basis_points'];
            $totals = $this->calculator->calculate($money, $discountRate, (int) $this->settings->get('billing.vat_basis_points', 0));
            $startsAt = new \DateTimeImmutable((string) $subscription['ends_at'], new \DateTimeZone('UTC')); $endsAt = $this->policy->annualEnd($startsAt);
            $year = (int) $now->format('Y'); $last = $db->fetchOne("SELECT last_number FROM invoice_sequences WHERE sequence_year = :year AND document_type = 'invoice' FOR UPDATE", ['year' => $year]);
            if ($last === false) { $next = 1; $db->insert('invoice_sequences', ['sequence_year' => $year, 'document_type' => 'invoice', 'last_number' => 1]); } else { $next = (int) $last + 1; $db->update('invoice_sequences', ['last_number' => $next], ['sequence_year' => $year, 'document_type' => 'invoice']); }
            $invoiceIdPublic = Uuid::v7()->toRfc4122(); $issued = $now->format('Y-m-d H:i:s'); $due = $now->add(new \DateInterval('P30D'));
            $snapshot = array_intersect_key($profile, array_flip(['club_name', 'address_line', 'postal_code', 'city', 'country_code', 'invoice_email', 'contact_name', 'recipient', 'order_number', 'cost_center', 'invoice_reference'])) + ['payment_term_days' => 30, 'dunning_term_days' => 30];
            $db->insert('invoices', ['tenant_id' => $subscription['tenant_id'], 'public_id' => $invoiceIdPublic, 'subscription_id' => $subscriptionId, 'coupon_id' => $coupon['id'] ?? null, 'document_type' => 'invoice', 'invoice_number' => $year.'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT), 'status' => 'open', 'currency' => $totals->currency, 'subtotal_minor' => $totals->subtotalMinor, 'discount_minor' => $totals->discountMinor, 'vat_rate_basis_points' => $totals->vatRateBasisPoints, 'vat_minor' => $totals->vatMinor, 'total_minor' => $totals->totalMinor, 'billing_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'qr_payload' => null, 'pdf_storage_path' => null, 'issued_at' => $issued, 'due_at' => $due->format('Y-m-d H:i:s'), 'reminder_due_at' => $due->add(new \DateInterval('P30D'))->format('Y-m-d H:i:s'), 'paid_at' => null, 'cancelled_at' => null, 'retention_until' => $now->add(new \DateInterval('P10Y'))->format('Y-m-d H:i:s'), 'created_at' => $issued]);
            $invoiceId = (int) $db->lastInsertId();
            foreach ($allItems as $position => $item) { $db->insert('invoice_lines', ['tenant_id' => $subscription['tenant_id'], 'invoice_id' => $invoiceId, 'price_version_id' => $item['price_id'], 'position' => $position + 1, 'description' => $item['name'], 'quantity' => 1, 'unit_price_minor' => $item['amount_minor'], 'line_total_minor' => $item['amount_minor'], 'service_starts_at' => $startsAt->format('Y-m-d H:i:s'), 'service_ends_at' => $endsAt->format('Y-m-d H:i:s')]); }
            $db->update('subscriptions', ['ends_at' => $endsAt->format('Y-m-d H:i:s'), 'updated_at' => $issued], ['id' => $subscriptionId, 'tenant_id' => $subscription['tenant_id']]);
            foreach ($items as $item) { $db->update('subscription_modules', ['price_version_id' => $item['price_id'], 'ends_at' => $endsAt->format('Y-m-d H:i:s')], ['id' => $item['subscription_module_id'], 'tenant_id' => $subscription['tenant_id']]); }
            if ($coupon !== false) { $db->update('coupons', ['redeemed_at' => $issued], ['id' => $coupon['id'], 'redeemed_at' => null]); }
            return ['tenant_id' => (int) $subscription['tenant_id'], 'invoice_public_id' => $invoiceIdPublic];
        });
        if ($result === null) { return false; }
        $this->delivery->deliver($result['tenant_id'], $result['invoice_public_id']);
        return true;
    }
}
