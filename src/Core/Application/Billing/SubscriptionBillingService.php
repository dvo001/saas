<?php

declare(strict_types=1);

namespace App\Core\Application\Billing;

use App\Core\Domain\Billing\InvoiceCalculator;
use App\Core\Domain\Billing\Money;
use App\Core\Domain\Billing\SubscriptionPolicy;
use App\Core\Domain\Tenant\TenantRole;
use App\Core\Infrastructure\Doctrine\Entity\Tenant;
use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Doctrine\Entity\PlatformAdmin;
use App\Core\Infrastructure\Security\AuditLogger;
use App\Core\Infrastructure\Settings\PlatformSettings;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class SubscriptionBillingService
{
    public function __construct(
        private Connection $connection,
        private InvoiceCalculator $calculator,
        private SubscriptionPolicy $policy,
        private PlatformSettings $settings,
        private AuditLogger $audit,
        private InvoiceDeliveryService $delivery,
    ) {}

    /** @return array{main_products: list<array<string, mixed>>, modules: list<array<string, mixed>>} */
    public function catalogue(): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT bp.public_id, bp.product_key, bp.product_type, bp.name, sm.code AS module_code,
                   sm.name AS module_name, pv.amount_minor, pv.currency
            FROM billing_products bp
            LEFT JOIN sport_modules sm ON sm.id = bp.module_id AND sm.active = 1
            INNER JOIN price_versions pv ON pv.id = (
                SELECT p.id FROM price_versions p WHERE p.billing_product_id = bp.id AND p.valid_from <= UTC_TIMESTAMP()
                ORDER BY p.valid_from DESC, p.id DESC LIMIT 1
            )
            WHERE bp.active = 1 AND (bp.module_id IS NULL OR sm.id IS NOT NULL)
            ORDER BY bp.product_type, bp.name
            SQL);

        return [
            'main_products' => array_values(array_filter($rows, static fn (array $row): bool => $row['product_type'] === 'main_subscription')),
            'modules' => array_values(array_filter($rows, static fn (array $row): bool => $row['product_type'] === 'sport_module')),
        ];
    }

    /** @return array<string, mixed> */
    public function overview(Tenant $tenant): array
    {
        $tenantId = $tenant->getId() ?? throw new \LogicException('Missing tenant id.');
        $subscription = $this->connection->fetchAssociative(<<<'SQL'
            SELECT s.*, sm.name AS main_module_name, sm.code AS main_module_code
            FROM subscriptions s INNER JOIN sport_modules sm ON sm.id = s.main_module_id
            WHERE s.tenant_id = :tenant ORDER BY s.starts_at DESC LIMIT 1
            SQL, ['tenant' => $tenantId]);

        return [
            'profile' => $this->connection->fetchAssociative('SELECT * FROM billing_profiles WHERE tenant_id = :tenant', ['tenant' => $tenantId]) ?: null,
            'subscription' => $subscription ?: null,
            'modules' => $subscription === false ? [] : $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT smod.*, sm.name, sm.code FROM subscription_modules smod
                INNER JOIN sport_modules sm ON sm.id = smod.module_id
                WHERE smod.tenant_id = :tenant AND smod.subscription_id = :subscription ORDER BY smod.module_role, sm.name
                SQL, ['tenant' => $tenantId, 'subscription' => $subscription['id']]),
            'invoices' => $this->connection->fetchAllAssociative('SELECT public_id, invoice_number, document_type, status, currency, total_minor, issued_at, due_at, paid_at FROM invoices WHERE tenant_id = :tenant ORDER BY issued_at DESC, id DESC', ['tenant' => $tenantId]),
        ];
    }

    /** @param array<string, string> $data */
    public function saveProfile(TenantUser $actor, array $data, string $ip): bool
    {
        $this->requireBillingRole($actor);
        foreach (['club_name', 'address_line', 'postal_code', 'city', 'invoice_email', 'contact_name'] as $required) {
            if (trim($data[$required] ?? '') === '') { throw new \DomainException('Bitte alle Pflichtfelder der Rechnungsadresse ausfüllen.'); }
        }
        $email = mb_strtolower(trim($data['invoice_email']));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) { throw new \DomainException('Bitte eine gültige Rechnungs-E-Mail angeben.'); }
        $tenantId = $actor->getTenant()->getId() ?? throw new \LogicException('Missing tenant id.');
        $existing = $this->connection->fetchAssociative('SELECT id, invoice_email FROM billing_profiles WHERE tenant_id = :tenant', ['tenant' => $tenantId]);
        $now = gmdate('Y-m-d H:i:s');
        $values = [
            'club_name' => mb_substr(trim($data['club_name']), 0, 180), 'address_line' => mb_substr(trim($data['address_line']), 0, 180),
            'postal_code' => mb_substr(trim($data['postal_code']), 0, 20), 'city' => mb_substr(trim($data['city']), 0, 120),
            'country_code' => strtoupper(trim($data['country_code'] ?? 'CH')), 'contact_name' => mb_substr(trim($data['contact_name']), 0, 120),
            'recipient' => $this->optional($data['recipient'] ?? null, 180), 'order_number' => $this->optional($data['order_number'] ?? null, 100),
            'cost_center' => $this->optional($data['cost_center'] ?? null, 100), 'invoice_reference' => $this->optional($data['invoice_reference'] ?? null, 160),
            'updated_at' => $now,
        ];
        if (preg_match('/^[A-Z]{2}$/', $values['country_code']) !== 1) { throw new \DomainException('Ungültiger Ländercode.'); }

        $confirmationRequired = is_array($existing) && $existing['invoice_email'] !== $email;
        if ($existing === false) {
            $this->connection->insert('billing_profiles', $values + ['tenant_id' => $tenantId, 'public_id' => Uuid::v7()->toRfc4122(), 'invoice_email' => $email, 'invoice_email_confirmed' => 1, 'pending_invoice_email' => null, 'created_at' => $now]);
        } else {
            $values += $confirmationRequired ? ['pending_invoice_email' => $email] : ['invoice_email' => $email];
            $this->connection->update('billing_profiles', $values, ['id' => $existing['id']]);
        }
        $this->audit->log('billing.profile.updated', 'billing_profile', null, $actor->getTenant(), $actor, ['email_confirmation_required' => $confirmationRequired], $ip);

        return $confirmationRequired;
    }

    /**
     * @param list<string> $moduleProductPublicIds First item is the included main module.
     * @return string Invoice public id.
     */
    public function book(TenantUser $actor, string $mainProductPublicId, array $moduleProductPublicIds, ?string $couponCode, string $ip): string
    {
        $this->requireBillingRole($actor);
        if ($moduleProductPublicIds === []) { throw new \DomainException('Bitte ein Hauptmodul wählen.'); }
        if (count($moduleProductPublicIds) !== count(array_unique($moduleProductPublicIds))) { throw new \DomainException('Ein Modul kann nur einmal gebucht werden.'); }
        $tenant = $actor->getTenant();
        $tenantId = $tenant->getId() ?? throw new \LogicException('Missing tenant id.');
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $invoicePublicId = $this->connection->transactional(function (Connection $db) use ($tenantId, $mainProductPublicId, $moduleProductPublicIds, $couponCode, $now): string {
            if ($db->fetchOne("SELECT 1 FROM subscriptions WHERE tenant_id = :tenant AND status IN ('active', 'cancelled', 'temporary') AND ends_at > :now FOR UPDATE", ['tenant' => $tenantId, 'now' => $now->format('Y-m-d H:i:s')]) !== false) {
                throw new \DomainException('Es besteht bereits ein laufendes Hauptabo.');
            }
            $profile = $db->fetchAssociative('SELECT * FROM billing_profiles WHERE tenant_id = :tenant AND invoice_email_confirmed = 1 FOR UPDATE', ['tenant' => $tenantId]);
            if ($profile === false) { throw new \DomainException('Vor der Buchung werden vollständige, bestätigte Rechnungsdaten benötigt.'); }
            $main = $this->pricedProduct($db, $mainProductPublicId, 'main_subscription', $now);
            $modules = [];
            foreach ($moduleProductPublicIds as $publicId) { $modules[] = $this->pricedProduct($db, $publicId, 'sport_module', $now); }

            $coupon = $this->resolveCoupon($db, $tenantId, $couponCode, array_column($modules, 'module_code'), $now);
            $discountBasisPoints = $coupon === null ? 0 : (int) $coupon['percentage_basis_points'];
            $lineMoney = [new Money((int) $main['amount_minor'], (string) $main['currency'])];
            foreach ($modules as $module) { $lineMoney[] = new Money((int) $module['amount_minor'], (string) $module['currency']); }
            $vatRate = (int) $this->settings->get('billing.vat_basis_points', 0);
            $totals = $this->calculator->calculate($lineMoney, $discountBasisPoints, $vatRate);
            $startsAt = $now;
            $endsAt = $this->policy->annualEnd($startsAt);
            $subscriptionPublicId = Uuid::v7()->toRfc4122();
            $db->insert('subscriptions', ['tenant_id' => $tenantId, 'public_id' => $subscriptionPublicId, 'main_module_id' => $modules[0]['module_id'], 'status' => 'active', 'starts_at' => $startsAt->format('Y-m-d H:i:s'), 'ends_at' => $endsAt->format('Y-m-d H:i:s'), 'auto_renew' => 0, 'cancelled_at' => null, 'retention_until' => null, 'created_at' => $now->format('Y-m-d H:i:s'), 'updated_at' => $now->format('Y-m-d H:i:s')]);
            $subscriptionId = (int) $db->lastInsertId();
            foreach ($modules as $position => $module) {
                $db->insert('subscription_modules', ['tenant_id' => $tenantId, 'subscription_id' => $subscriptionId, 'module_id' => $module['module_id'], 'price_version_id' => $module['price_id'], 'module_role' => $position === 0 ? 'main' : 'addon', 'status' => 'active', 'starts_at' => $startsAt->format('Y-m-d H:i:s'), 'ends_at' => $endsAt->format('Y-m-d H:i:s'), 'renew' => 1, 'archive_until' => null, 'created_at' => $now->format('Y-m-d H:i:s')]);
            }

            $invoicePublicId = Uuid::v7()->toRfc4122();
            $invoiceNumber = $this->nextInvoiceNumber($db, $now, 'invoice');
            $snapshot = ['club_name' => $profile['club_name'], 'address_line' => $profile['address_line'], 'postal_code' => $profile['postal_code'], 'city' => $profile['city'], 'country_code' => $profile['country_code'], 'invoice_email' => $profile['invoice_email'], 'contact_name' => $profile['contact_name'], 'recipient' => $profile['recipient'], 'order_number' => $profile['order_number'], 'cost_center' => $profile['cost_center'], 'invoice_reference' => $profile['invoice_reference'], 'payment_term_days' => 30, 'dunning_term_days' => 30];
            $dueAt = $now->add(new \DateInterval('P30D'));
            $db->insert('invoices', ['tenant_id' => $tenantId, 'public_id' => $invoicePublicId, 'subscription_id' => $subscriptionId, 'coupon_id' => $coupon['id'] ?? null, 'document_type' => 'invoice', 'invoice_number' => $invoiceNumber, 'status' => 'open', 'currency' => $totals->currency, 'subtotal_minor' => $totals->subtotalMinor, 'discount_minor' => $totals->discountMinor, 'vat_rate_basis_points' => $totals->vatRateBasisPoints, 'vat_minor' => $totals->vatMinor, 'total_minor' => $totals->totalMinor, 'billing_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'qr_payload' => null, 'pdf_storage_path' => null, 'issued_at' => $now->format('Y-m-d H:i:s'), 'due_at' => $dueAt->format('Y-m-d H:i:s'), 'reminder_due_at' => $dueAt->add(new \DateInterval('P30D'))->format('Y-m-d H:i:s'), 'paid_at' => null, 'cancelled_at' => null, 'retention_until' => $now->add(new \DateInterval('P10Y'))->format('Y-m-d H:i:s'), 'created_at' => $now->format('Y-m-d H:i:s')]);
            $invoiceId = (int) $db->lastInsertId();
            $items = array_merge([$main], $modules);
            foreach ($items as $position => $item) {
                $db->insert('invoice_lines', ['tenant_id' => $tenantId, 'invoice_id' => $invoiceId, 'price_version_id' => $item['price_id'], 'position' => $position + 1, 'description' => $item['name'], 'quantity' => 1, 'unit_price_minor' => $item['amount_minor'], 'line_total_minor' => $item['amount_minor'], 'service_starts_at' => $startsAt->format('Y-m-d H:i:s'), 'service_ends_at' => $endsAt->format('Y-m-d H:i:s')]);
            }
            if ($coupon !== null) { $db->update('coupons', ['redeemed_at' => $now->format('Y-m-d H:i:s'), 'tenant_id' => $coupon['tenant_id'] ?? $tenantId], ['id' => $coupon['id'], 'redeemed_at' => null]); }
            $db->update('tenants', ['status' => 'active'], ['id' => $tenantId]);

            return $invoicePublicId;
        });
        $this->audit->log('subscription.booked', 'invoice', $invoicePublicId, $tenant, $actor, ['main_product' => $mainProductPublicId, 'module_products' => $moduleProductPublicIds], $ip);
        $this->delivery->deliver($tenantId, $invoicePublicId);

        return $invoicePublicId;
    }

    public function cancel(TenantUser $actor, string $ip): void
    {
        $this->requireOwner($actor);
        $tenantId = $actor->getTenant()->getId() ?? throw new \LogicException('Missing tenant id.');
        $now = gmdate('Y-m-d H:i:s');
        $changed = $this->connection->executeStatement("UPDATE subscriptions SET status = 'cancelled', auto_renew = 0, cancelled_at = :now, updated_at = :now WHERE tenant_id = :tenant AND status = 'active' AND ends_at > :now", ['now' => $now, 'tenant' => $tenantId]);
        if ($changed === 0) { throw new \DomainException('Kein kündbares Hauptabo gefunden.'); }
        $this->audit->log('subscription.cancelled', 'subscription', null, $actor->getTenant(), $actor, [], $ip);
    }

    public function setAutoRenew(TenantUser $actor, bool $enabled, string $ip): void
    {
        $this->requireOwner($actor);
        $tenantId = $actor->getTenant()->getId() ?? throw new \LogicException('Missing tenant id.');
        $row = $this->connection->fetchAssociative("SELECT id, ends_at FROM subscriptions WHERE tenant_id = :tenant AND status = 'active' AND ends_at > UTC_TIMESTAMP() ORDER BY ends_at DESC LIMIT 1", ['tenant' => $tenantId]);
        if ($row === false) { throw new \DomainException('Kein aktives Hauptabo gefunden.'); }
        if (!$this->policy->mayChangeRenewal(new \DateTimeImmutable('now', new \DateTimeZone('UTC')), new \DateTimeImmutable((string) $row['ends_at'], new \DateTimeZone('UTC')))) { throw new \DomainException('Die Verlängerung kann in den letzten sieben Tagen nicht mehr geändert werden.'); }
        $this->connection->update('subscriptions', ['auto_renew' => $enabled ? 1 : 0, 'updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $row['id'], 'tenant_id' => $tenantId]);
        $this->audit->log('subscription.auto_renew_changed', 'subscription', null, $actor->getTenant(), $actor, ['enabled' => $enabled], $ip);
    }

    public function recordManualPayment(PlatformAdmin $actor, string $invoicePublicId, string $ip): void
    {
        $tenantId = $this->connection->transactional(function (Connection $db) use ($invoicePublicId): int {
            $invoice = $db->fetchAssociative('SELECT id, tenant_id, status, total_minor, currency FROM invoices WHERE public_id = :public_id FOR UPDATE', ['public_id' => $invoicePublicId]);
            if ($invoice === false || !in_array($invoice['status'], ['open', 'overdue', 'dunning'], true)) { throw new \DomainException('Die Rechnung ist nicht offen.'); }
            $tenantId = (int) $invoice['tenant_id'];
            $now = gmdate('Y-m-d H:i:s');
            $db->insert('payment_transactions', ['tenant_id' => $tenantId, 'invoice_id' => $invoice['id'], 'public_id' => Uuid::v7()->toRfc4122(), 'payment_method' => 'invoice_manual', 'provider_key' => null, 'provider_reference' => null, 'status' => 'completed', 'amount_minor' => $invoice['total_minor'], 'currency' => $invoice['currency'], 'provider_data' => null, 'received_at' => $now, 'retention_until' => (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))->add(new \DateInterval('P10Y'))->format('Y-m-d H:i:s'), 'created_at' => $now, 'updated_at' => $now]);
            $db->update('invoices', ['status' => 'paid', 'paid_at' => $now], ['id' => $invoice['id'], 'tenant_id' => $tenantId]);
            if ($db->fetchOne("SELECT 1 FROM invoices WHERE tenant_id = :tenant AND status IN ('open', 'overdue', 'dunning') AND reminder_due_at < UTC_TIMESTAMP()", ['tenant' => $tenantId]) === false) { $db->update('tenants', ['status' => 'active'], ['id' => $tenantId, 'status' => 'suspended']); }
            return $tenantId;
        });
        $this->audit->logPlatform('invoice.payment_recorded', 'invoice', $invoicePublicId, $actor, ['method' => 'invoice_manual', 'tenant_id' => $tenantId], null, $ip);
    }

    /** @return list<array<string, mixed>> */
    public function platformInvoices(): array
    {
        return $this->connection->fetchAllAssociative('SELECT i.public_id, i.invoice_number, i.status, i.currency, i.total_minor, i.issued_at, i.due_at, t.name AS tenant_name FROM invoices i INNER JOIN tenants t ON t.id = i.tenant_id ORDER BY i.issued_at DESC, i.id DESC LIMIT 250');
    }

    /** @return array<string, mixed> */
    private function pricedProduct(Connection $db, string $publicId, string $type, \DateTimeImmutable $now): array
    {
        $row = $db->fetchAssociative(<<<'SQL'
            SELECT bp.id, bp.name, bp.product_type, bp.module_id, sm.code AS module_code,
                   pv.id AS price_id, pv.amount_minor, pv.currency
            FROM billing_products bp LEFT JOIN sport_modules sm ON sm.id = bp.module_id
            INNER JOIN price_versions pv ON pv.id = (SELECT p.id FROM price_versions p WHERE p.billing_product_id = bp.id AND p.valid_from <= :now ORDER BY p.valid_from DESC, p.id DESC LIMIT 1)
            WHERE bp.public_id = :public_id AND bp.product_type = :type AND bp.active = 1 AND (sm.id IS NULL OR sm.active = 1)
            SQL, ['public_id' => $publicId, 'type' => $type, 'now' => $now->format('Y-m-d H:i:s')]);
        if ($row === false) { throw new \DomainException('Ein gewähltes Produkt ist nicht mehr verfügbar.'); }

        return $row;
    }

    /**
     * @param list<string> $moduleCodes
     * @return array<string, mixed>|null
     */
    private function resolveCoupon(Connection $db, int $tenantId, ?string $code, array $moduleCodes, \DateTimeImmutable $now): ?array
    {
        if ($code === null || trim($code) === '') { return null; }
        $coupon = $db->fetchAssociative('SELECT * FROM coupons WHERE code_hash = :hash AND redeemed_at IS NULL AND valid_from <= :now AND (valid_until IS NULL OR valid_until >= :now) AND (tenant_id IS NULL OR tenant_id = :tenant) FOR UPDATE', ['hash' => hash('sha256', strtoupper(trim($code))), 'now' => $now->format('Y-m-d H:i:s'), 'tenant' => $tenantId]);
        if ($coupon === false) { throw new \DomainException('Der Gutschein ist ungültig oder bereits verwendet.'); }
        if ($coupon['coupon_type'] === 'first_booking' && $db->fetchOne('SELECT 1 FROM subscriptions WHERE tenant_id = :tenant', ['tenant' => $tenantId]) !== false) { throw new \DomainException('Dieser Gutschein gilt nur für die erste Buchung.'); }
        if ($coupon['module_scope'] !== null) {
            $scope = json_decode((string) $coupon['module_scope'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($scope) || array_intersect($scope, $moduleCodes) === []) { throw new \DomainException('Der Gutschein gilt nicht für die gewählten Module.'); }
        }

        return $coupon;
    }

    private function nextInvoiceNumber(Connection $db, \DateTimeImmutable $now, string $documentType): string
    {
        $year = (int) $now->format('Y');
        $number = $db->fetchOne('SELECT last_number FROM invoice_sequences WHERE sequence_year = :year AND document_type = :type FOR UPDATE', ['year' => $year, 'type' => $documentType]);
        if ($number === false) { $next = 1; $db->insert('invoice_sequences', ['sequence_year' => $year, 'document_type' => $documentType, 'last_number' => $next]); }
        else { $next = (int) $number + 1; $db->update('invoice_sequences', ['last_number' => $next], ['sequence_year' => $year, 'document_type' => $documentType]); }

        return ($documentType === 'credit_note' ? 'GS-' : '').$year.'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    private function optional(?string $value, int $length): ?string { $value = trim((string) $value); return $value === '' ? null : mb_substr($value, 0, $length); }
    private function requireBillingRole(TenantUser $actor): void { if (!in_array($actor->getTenantRole(), [TenantRole::Owner, TenantRole::Administrator], true)) { throw new \DomainException('Keine Berechtigung für Abrechnungsdaten.'); } }
    private function requireOwner(TenantUser $actor): void { if ($actor->getTenantRole() !== TenantRole::Owner) { throw new \DomainException('Nur der Owner darf die Verlängerung ändern.'); } }
}
