<?php

declare(strict_types=1);

namespace App\Core\Application\Billing;

use App\Core\Infrastructure\Doctrine\Entity\PlatformAdmin;
use App\Core\Infrastructure\Security\AuditLogger;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class BillingAdministrationService
{
    public function __construct(private Connection $connection, private AuditLogger $audit) {}

    /** @return list<array<string, mixed>> */
    public function coupons(): array { return $this->connection->fetchAllAssociative('SELECT c.public_id, c.coupon_type, c.percentage_basis_points, c.module_scope, c.valid_from, c.valid_until, c.redeemed_at, t.name AS tenant_name FROM coupons c LEFT JOIN tenants t ON t.id = c.tenant_id ORDER BY c.created_at DESC LIMIT 100'); }
    /** @return list<array<string, mixed>> */
    public function tenants(): array { return $this->connection->fetchAllAssociative("SELECT public_id, name FROM tenants WHERE status <> 'closed' ORDER BY name"); }
    /** @return list<array<string, mixed>> */
    public function modules(): array { return $this->connection->fetchAllAssociative('SELECT code, name FROM sport_modules ORDER BY name'); }

    /** @param list<string> $moduleScope */
    public function createCoupon(PlatformAdmin $admin, int $percentageBasisPoints, string $type, ?string $tenantPublicId, array $moduleScope, ?\DateTimeImmutable $validUntil, string $ip): string
    {
        if (!in_array($type, ['first_booking', 'compassion'], true) || $percentageBasisPoints < 1 || $percentageBasisPoints > 10_000) { throw new \DomainException('Ungültiger Gutschein.'); }
        $tenantId = null;
        if ($type === 'compassion') {
            $tenantId = $this->connection->fetchOne('SELECT id FROM tenants WHERE public_id = :id', ['id' => $tenantPublicId]);
            if ($tenantId === false) { throw new \DomainException('Für Kulanz muss ein Verein gewählt werden.'); }
        }
        $knownModules = array_column($this->modules(), 'code');
        if (array_diff($moduleScope, $knownModules) !== []) { throw new \DomainException('Ungültiger Modulbereich.'); }
        $code = strtoupper(substr(bin2hex(random_bytes(8)), 0, 4).'-'.substr(bin2hex(random_bytes(8)), 0, 8));
        $publicId = Uuid::v7()->toRfc4122(); $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->connection->insert('coupons', ['public_id' => $publicId, 'tenant_id' => $tenantId === null ? null : (int) $tenantId, 'code_hash' => hash('sha256', $code), 'coupon_type' => $type, 'percentage_basis_points' => $percentageBasisPoints, 'module_scope' => $moduleScope === [] ? null : json_encode($moduleScope, JSON_THROW_ON_ERROR), 'valid_from' => $now->format('Y-m-d H:i:s'), 'valid_until' => $validUntil?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), 'redeemed_at' => null, 'created_by_platform_admin_id' => $admin->getId() ?? throw new \LogicException('Missing admin id.'), 'created_at' => $now->format('Y-m-d H:i:s')]);
        $this->audit->logPlatform('billing.coupon.created', 'coupon', $publicId, $admin, ['type' => $type, 'percentage_basis_points' => $percentageBasisPoints, 'tenant_public_id' => $tenantPublicId, 'module_scope' => $moduleScope], null, $ip);
        return $code;
    }

    public function grantExtension(PlatformAdmin $admin, string $tenantPublicId, int $days, ?string $moduleCode, string $reason, string $ip): void
    {
        $reason = trim($reason); if ($days < 1 || $days > 365 || mb_strlen($reason) < 5) { throw new \DomainException('Dauer oder Pflichtbegründung ist ungültig.'); }
        $tenantId = $this->connection->fetchOne('SELECT id FROM tenants WHERE public_id = :id', ['id' => $tenantPublicId]); if ($tenantId === false) { throw new \DomainException('Verein nicht gefunden.'); }
        $changed = $this->connection->transactional(function (Connection $db) use ($tenantId, $days, $moduleCode): int {
            $subscription = $db->fetchAssociative("SELECT id FROM subscriptions WHERE tenant_id = :tenant AND status IN ('active', 'cancelled', 'temporary') ORDER BY ends_at DESC LIMIT 1 FOR UPDATE", ['tenant' => $tenantId]);
            if ($subscription === false) { throw new \DomainException('Kein verlängerbares Abo gefunden.'); }
            if ($moduleCode === null) {
                $db->executeStatement('UPDATE subscriptions SET ends_at = DATE_ADD(ends_at, INTERVAL :days DAY), updated_at = UTC_TIMESTAMP() WHERE id = :id AND tenant_id = :tenant', ['days' => $days, 'id' => $subscription['id'], 'tenant' => $tenantId]);
                return (int) $db->executeStatement('UPDATE subscription_modules SET ends_at = DATE_ADD(ends_at, INTERVAL :days DAY) WHERE subscription_id = :id AND tenant_id = :tenant', ['days' => $days, 'id' => $subscription['id'], 'tenant' => $tenantId]);
            }
            return (int) $db->executeStatement('UPDATE subscription_modules smod INNER JOIN sport_modules sm ON sm.id = smod.module_id SET smod.ends_at = DATE_ADD(smod.ends_at, INTERVAL :days DAY) WHERE smod.subscription_id = :id AND smod.tenant_id = :tenant AND sm.code = :code', ['days' => $days, 'id' => $subscription['id'], 'tenant' => $tenantId, 'code' => $moduleCode]);
        });
        if ($changed === 0) { throw new \DomainException('Kein passendes Modul gefunden.'); }
        $this->audit->logPlatform('billing.grace_extension.granted', 'tenant', $tenantPublicId, $admin, ['days' => $days, 'module' => $moduleCode, 'reason' => $reason], null, $ip);
    }
}
