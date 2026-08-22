<?php

declare(strict_types=1);

namespace App\Core\Application\Billing;

use App\Core\Domain\Billing\Money;
use App\Core\Infrastructure\Doctrine\Entity\PlatformAdmin;
use App\Core\Infrastructure\Security\AuditLogger;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class BillingCatalogService
{
    public function __construct(private Connection $connection, private AuditLogger $audit) {}

    /** @return list<array<string, mixed>> */
    public function products(): array
    {
        return $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT bp.public_id, bp.product_key, bp.product_type, bp.name, bp.active, sm.code AS module_code,
                   pv.amount_minor, pv.currency, pv.valid_from
            FROM billing_products bp
            LEFT JOIN sport_modules sm ON sm.id = bp.module_id
            LEFT JOIN price_versions pv ON pv.id = (
                SELECT p.id FROM price_versions p
                WHERE p.billing_product_id = bp.id AND p.valid_from <= UTC_TIMESTAMP()
                ORDER BY p.valid_from DESC, p.id DESC LIMIT 1
            )
            ORDER BY bp.product_type, bp.name
            SQL);
    }

    /** @return list<array<string, mixed>> */
    public function modules(): array
    {
        return $this->connection->fetchAllAssociative('SELECT code, name, complexity, active FROM sport_modules ORDER BY name');
    }

    public function createProduct(PlatformAdmin $admin, string $key, string $name, string $type, ?string $moduleCode, string $ip): void
    {
        $key = mb_strtolower(trim($key));
        $name = trim($name);
        if (preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $key) !== 1 || $name === '') {
            throw new \DomainException('Produktschlüssel oder Bezeichnung ist ungültig.');
        }
        if (!in_array($type, ['main_subscription', 'sport_module'], true)) {
            throw new \DomainException('Ungültiger Produkttyp.');
        }
        $moduleId = null;
        if ($type === 'sport_module') {
            $moduleId = $this->connection->fetchOne('SELECT id FROM sport_modules WHERE code = :code', ['code' => $moduleCode]);
            if ($moduleId === false) { throw new \DomainException('Bitte ein gültiges Sportmodul wählen.'); }
        }
        if ($this->connection->fetchOne('SELECT 1 FROM billing_products WHERE product_key = :key', ['key' => $key]) !== false) {
            throw new \DomainException('Dieser Produktschlüssel ist bereits vorhanden.');
        }

        $publicId = Uuid::v7()->toRfc4122();
        $this->connection->insert('billing_products', [
            'public_id' => $publicId,
            'module_id' => $moduleId === null ? null : (int) $moduleId,
            'product_key' => $key,
            'product_type' => $type,
            'name' => mb_substr($name, 0, 160),
            'active' => 1,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->audit->logPlatform('billing.product.created', 'billing_product', $publicId, $admin, ['product_key' => $key, 'type' => $type, 'module' => $moduleCode], null, $ip);
    }

    public function setPrice(PlatformAdmin $admin, string $productPublicId, string $decimalAmount, \DateTimeImmutable $validFrom, string $ip): void
    {
        $productId = $this->connection->fetchOne('SELECT id FROM billing_products WHERE public_id = :public_id', ['public_id' => $productPublicId]);
        if ($productId === false) { throw new \DomainException('Das Produkt wurde nicht gefunden.'); }
        $money = Money::fromDecimal($decimalAmount);
        $validFrom = $validFrom->setTimezone(new \DateTimeZone('UTC'));
        if ($this->connection->fetchOne('SELECT 1 FROM price_versions WHERE billing_product_id = :product AND valid_from = :valid_from', ['product' => $productId, 'valid_from' => $validFrom->format('Y-m-d H:i:s')]) !== false) {
            throw new \DomainException('Für diesen Zeitpunkt existiert bereits eine Preisversion.');
        }
        $publicId = Uuid::v7()->toRfc4122();
        $this->connection->insert('price_versions', [
            'public_id' => $publicId,
            'billing_product_id' => (int) $productId,
            'amount_minor' => $money->minor,
            'currency' => $money->currency,
            'valid_from' => $validFrom->format('Y-m-d H:i:s'),
            'created_by_platform_admin_id' => $admin->getId() ?? throw new \LogicException('Missing platform admin id.'),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->audit->logPlatform('billing.price.created', 'price_version', $publicId, $admin, [
            'product_public_id' => $productPublicId,
            'amount_minor' => $money->minor,
            'currency' => $money->currency,
            'valid_from' => $validFrom->format(DATE_ATOM),
        ], null, $ip);
    }

    public function setProductActive(PlatformAdmin $admin, string $publicId, bool $active, string $ip): void
    {
        if ($this->connection->update('billing_products', ['active' => $active ? 1 : 0], ['public_id' => $publicId]) === 0) {
            throw new \DomainException('Das Produkt wurde nicht gefunden.');
        }
        $this->audit->logPlatform($active ? 'billing.product.activated' : 'billing.product.deactivated', 'billing_product', $publicId, $admin, [], null, $ip);
    }

    public function setModuleActive(PlatformAdmin $admin, string $code, bool $active, string $ip): void
    {
        if ($this->connection->update('sport_modules', ['active' => $active ? 1 : 0, 'updated_at' => gmdate('Y-m-d H:i:s')], ['code' => $code]) === 0) { throw new \DomainException('Das Sportmodul wurde nicht gefunden.'); }
        $this->audit->logPlatform($active ? 'billing.module.activated' : 'billing.module.deactivated', 'sport_module', $code, $admin, [], null, $ip);
    }
}
