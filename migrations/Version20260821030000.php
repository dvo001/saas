<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260821030000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add module catalogue, historical prices, subscriptions, immutable invoices, payments and coupons'; }
    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'MariaDB/MySQL is required.');
        $this->addSql(<<<'SQL'
            CREATE TABLE sport_modules (
                id INT AUTO_INCREMENT NOT NULL,
                code VARCHAR(60) NOT NULL,
                name VARCHAR(120) NOT NULL,
                complexity VARCHAR(20) NOT NULL,
                active TINYINT(1) DEFAULT 1 NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_sport_module_code (code),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql("INSERT INTO sport_modules (code, name, complexity, active, created_at, updated_at) VALUES ('running_event', 'Laufveranstaltung', 'simple', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()), ('football_tournament', 'Fussballturnier', 'simple', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $this->addSql(<<<'SQL'
            CREATE TABLE billing_products (
                id INT AUTO_INCREMENT NOT NULL,
                public_id VARCHAR(36) NOT NULL,
                module_id INT DEFAULT NULL,
                product_key VARCHAR(100) NOT NULL,
                product_type VARCHAR(30) NOT NULL,
                name VARCHAR(160) NOT NULL,
                active TINYINT(1) DEFAULT 1 NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_billing_product_public_id (public_id),
                UNIQUE INDEX uniq_billing_product_key (product_key),
                INDEX idx_billing_product_module (module_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_billing_product_module FOREIGN KEY (module_id) REFERENCES sport_modules (id) ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE price_versions (
                id BIGINT AUTO_INCREMENT NOT NULL,
                public_id VARCHAR(36) NOT NULL,
                billing_product_id INT NOT NULL,
                amount_minor INT UNSIGNED NOT NULL,
                currency CHAR(3) DEFAULT 'CHF' NOT NULL,
                valid_from DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_by_platform_admin_id INT NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_price_public_id (public_id),
                UNIQUE INDEX uniq_product_price_valid_from (billing_product_id, valid_from),
                INDEX idx_price_validity (billing_product_id, valid_from),
                INDEX idx_price_admin (created_by_platform_admin_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_price_product FOREIGN KEY (billing_product_id) REFERENCES billing_products (id) ON DELETE RESTRICT,
                CONSTRAINT fk_price_admin FOREIGN KEY (created_by_platform_admin_id) REFERENCES platform_admins (id) ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE billing_profiles (
                id INT AUTO_INCREMENT NOT NULL,
                tenant_id INT NOT NULL,
                public_id VARCHAR(36) NOT NULL,
                club_name VARCHAR(180) NOT NULL,
                address_line VARCHAR(180) NOT NULL,
                postal_code VARCHAR(20) NOT NULL,
                city VARCHAR(120) NOT NULL,
                country_code CHAR(2) DEFAULT 'CH' NOT NULL,
                invoice_email VARCHAR(180) NOT NULL,
                invoice_email_confirmed TINYINT(1) DEFAULT 0 NOT NULL,
                pending_invoice_email VARCHAR(180) DEFAULT NULL,
                contact_name VARCHAR(120) NOT NULL,
                recipient VARCHAR(180) DEFAULT NULL,
                order_number VARCHAR(100) DEFAULT NULL,
                cost_center VARCHAR(100) DEFAULT NULL,
                invoice_reference VARCHAR(160) DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_billing_profile_tenant (tenant_id),
                UNIQUE INDEX uniq_billing_profile_public_id (public_id),
                UNIQUE INDEX uniq_billing_profile_tenant_id (tenant_id, id),
                PRIMARY KEY(id),
                CONSTRAINT fk_billing_profile_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE subscriptions (
                id INT AUTO_INCREMENT NOT NULL,
                tenant_id INT NOT NULL,
                public_id VARCHAR(36) NOT NULL,
                main_module_id INT NOT NULL,
                status VARCHAR(30) NOT NULL,
                starts_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                ends_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                auto_renew TINYINT(1) DEFAULT 0 NOT NULL,
                cancelled_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                retention_until DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_subscription_public_id (public_id),
                UNIQUE INDEX uniq_subscription_tenant_period (tenant_id, starts_at),
                UNIQUE INDEX uniq_subscription_tenant_id (tenant_id, id),
                INDEX idx_subscription_status_end (status, ends_at),
                INDEX idx_subscription_module (main_module_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_subscription_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
                CONSTRAINT fk_subscription_main_module FOREIGN KEY (main_module_id) REFERENCES sport_modules (id) ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE subscription_modules (
                id INT AUTO_INCREMENT NOT NULL,
                tenant_id INT NOT NULL,
                subscription_id INT NOT NULL,
                module_id INT NOT NULL,
                price_version_id BIGINT NOT NULL,
                module_role VARCHAR(20) NOT NULL,
                status VARCHAR(24) NOT NULL,
                starts_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                ends_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                renew TINYINT(1) DEFAULT 1 NOT NULL,
                archive_until DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_subscription_module (tenant_id, subscription_id, module_id),
                UNIQUE INDEX uniq_subscription_module_tenant_id (tenant_id, id),
                INDEX idx_subscription_module_subscription (tenant_id, subscription_id),
                INDEX idx_subscription_module_module (module_id),
                INDEX idx_subscription_module_price (price_version_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_subscription_module_subscription FOREIGN KEY (tenant_id, subscription_id) REFERENCES subscriptions (tenant_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_subscription_module_module FOREIGN KEY (module_id) REFERENCES sport_modules (id) ON DELETE RESTRICT,
                CONSTRAINT fk_subscription_module_price FOREIGN KEY (price_version_id) REFERENCES price_versions (id) ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE coupons (
                id BIGINT AUTO_INCREMENT NOT NULL,
                public_id VARCHAR(36) NOT NULL,
                tenant_id INT DEFAULT NULL,
                code_hash CHAR(64) NOT NULL,
                coupon_type VARCHAR(30) NOT NULL,
                percentage_basis_points SMALLINT UNSIGNED NOT NULL,
                module_scope JSON DEFAULT NULL,
                valid_from DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                valid_until DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                redeemed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_by_platform_admin_id INT NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_coupon_public_id (public_id),
                UNIQUE INDEX uniq_coupon_code_hash (code_hash),
                INDEX idx_coupon_tenant (tenant_id),
                INDEX idx_coupon_admin (created_by_platform_admin_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_coupon_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
                CONSTRAINT fk_coupon_admin FOREIGN KEY (created_by_platform_admin_id) REFERENCES platform_admins (id) ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE invoice_sequences (
                sequence_year SMALLINT NOT NULL,
                document_type VARCHAR(20) NOT NULL,
                last_number INT UNSIGNED DEFAULT 0 NOT NULL,
                PRIMARY KEY(sequence_year, document_type)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE invoices (
                id BIGINT AUTO_INCREMENT NOT NULL,
                tenant_id INT NOT NULL,
                public_id VARCHAR(36) NOT NULL,
                subscription_id INT DEFAULT NULL,
                coupon_id BIGINT DEFAULT NULL,
                document_type VARCHAR(20) NOT NULL,
                invoice_number VARCHAR(30) NOT NULL,
                status VARCHAR(24) NOT NULL,
                currency CHAR(3) DEFAULT 'CHF' NOT NULL,
                subtotal_minor INT UNSIGNED NOT NULL,
                discount_minor INT UNSIGNED DEFAULT 0 NOT NULL,
                vat_rate_basis_points SMALLINT UNSIGNED DEFAULT 0 NOT NULL,
                vat_minor INT UNSIGNED DEFAULT 0 NOT NULL,
                total_minor INT UNSIGNED NOT NULL,
                billing_snapshot JSON NOT NULL,
                qr_payload LONGTEXT DEFAULT NULL,
                pdf_storage_path VARCHAR(255) DEFAULT NULL,
                issued_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                due_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                reminder_due_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                paid_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                cancelled_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_invoice_public_id (public_id),
                UNIQUE INDEX uniq_invoice_number (invoice_number),
                UNIQUE INDEX uniq_invoice_tenant_id (tenant_id, id),
                INDEX idx_invoice_tenant_status (tenant_id, status, due_at),
                INDEX idx_invoice_subscription (tenant_id, subscription_id),
                INDEX idx_invoice_coupon (coupon_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_invoice_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT,
                CONSTRAINT fk_invoice_subscription FOREIGN KEY (tenant_id, subscription_id) REFERENCES subscriptions (tenant_id, id) ON DELETE RESTRICT,
                CONSTRAINT fk_invoice_coupon FOREIGN KEY (coupon_id) REFERENCES coupons (id) ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE invoice_lines (
                id BIGINT AUTO_INCREMENT NOT NULL,
                tenant_id INT NOT NULL,
                invoice_id BIGINT NOT NULL,
                price_version_id BIGINT DEFAULT NULL,
                position SMALLINT UNSIGNED NOT NULL,
                description VARCHAR(255) NOT NULL,
                quantity SMALLINT UNSIGNED DEFAULT 1 NOT NULL,
                unit_price_minor INT UNSIGNED NOT NULL,
                line_total_minor INT UNSIGNED NOT NULL,
                service_starts_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                service_ends_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_invoice_line_position (tenant_id, invoice_id, position),
                INDEX idx_invoice_line_invoice (tenant_id, invoice_id),
                INDEX idx_invoice_line_price (price_version_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_invoice_line_invoice FOREIGN KEY (tenant_id, invoice_id) REFERENCES invoices (tenant_id, id) ON DELETE RESTRICT,
                CONSTRAINT fk_invoice_line_price FOREIGN KEY (price_version_id) REFERENCES price_versions (id) ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE payment_transactions (
                id BIGINT AUTO_INCREMENT NOT NULL,
                tenant_id INT NOT NULL,
                invoice_id BIGINT NOT NULL,
                public_id VARCHAR(36) NOT NULL,
                payment_method VARCHAR(30) NOT NULL,
                provider_key VARCHAR(80) DEFAULT NULL,
                provider_reference VARCHAR(190) DEFAULT NULL,
                status VARCHAR(24) NOT NULL,
                amount_minor INT UNSIGNED NOT NULL,
                currency CHAR(3) DEFAULT 'CHF' NOT NULL,
                provider_data JSON DEFAULT NULL,
                received_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_payment_public_id (public_id),
                UNIQUE INDEX uniq_payment_provider_reference (provider_key, provider_reference),
                INDEX idx_payment_invoice (tenant_id, invoice_id),
                INDEX idx_payment_status (status, created_at),
                PRIMARY KEY(id),
                CONSTRAINT fk_payment_invoice FOREIGN KEY (tenant_id, invoice_id) REFERENCES invoices (tenant_id, id) ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE payment_transactions');
        $this->addSql('DROP TABLE invoice_lines');
        $this->addSql('DROP TABLE invoices');
        $this->addSql('DROP TABLE invoice_sequences');
        $this->addSql('DROP TABLE coupons');
        $this->addSql('DROP TABLE subscription_modules');
        $this->addSql('DROP TABLE subscriptions');
        $this->addSql('DROP TABLE billing_profiles');
        $this->addSql('DROP TABLE price_versions');
        $this->addSql('DROP TABLE billing_products');
        $this->addSql('DROP TABLE sport_modules');
    }
}
