<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260821090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add final event documents, logos and ten-year legal retention metadata';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'MariaDB/MySQL is required.');
        $this->addSql('ALTER TABLE tenants ADD logo_storage_path VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE invoices ADD retention_until DATETIME DEFAULT NULL, ADD INDEX idx_invoice_retention (retention_until)');
        $this->addSql('UPDATE invoices SET retention_until = DATE_ADD(issued_at, INTERVAL 10 YEAR) WHERE retention_until IS NULL');
        $this->addSql('ALTER TABLE invoices MODIFY retention_until DATETIME NOT NULL');
        $this->addSql('ALTER TABLE payment_transactions ADD retention_until DATETIME DEFAULT NULL, ADD INDEX idx_payment_retention (retention_until)');
        $this->addSql('UPDATE payment_transactions SET retention_until = DATE_ADD(COALESCE(received_at, created_at), INTERVAL 10 YEAR) WHERE retention_until IS NULL');
        $this->addSql('ALTER TABLE payment_transactions MODIFY retention_until DATETIME NOT NULL');
        $this->addSql('ALTER TABLE audit_log ADD retention_until DATETIME DEFAULT NULL, ADD INDEX idx_audit_retention (retention_until)');
        $this->addSql('UPDATE audit_log SET retention_until = DATE_ADD(occurred_at, INTERVAL 10 YEAR) WHERE actor_platform_admin_id IS NOT NULL OR tenant_id IS NULL');
        $this->addSql(<<<'SQL'
CREATE TABLE event_documents (
    id BIGINT AUTO_INCREMENT NOT NULL,
    tenant_id INT NOT NULL,
    event_id INT NOT NULL,
    public_id VARCHAR(36) NOT NULL,
    module_code VARCHAR(60) NOT NULL,
    document_type VARCHAR(60) NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    snapshot JSON NOT NULL,
    storage_path VARCHAR(255) NOT NULL,
    sha256 CHAR(64) NOT NULL,
    released_by_user_public_id VARCHAR(36) NOT NULL,
    released_by_name VARCHAR(120) NOT NULL,
    is_current TINYINT(1) DEFAULT 1 NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE INDEX uniq_event_document_public (public_id),
    UNIQUE INDEX uniq_event_document_version (tenant_id, event_id, document_type, version_number),
    INDEX idx_event_document_current (tenant_id, event_id, is_current, document_type),
    CONSTRAINT fk_event_document_event FOREIGN KEY (tenant_id, event_id) REFERENCES events (tenant_id, id) ON DELETE CASCADE,
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE=InnoDB
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE event_documents');
        $this->addSql('ALTER TABLE audit_log DROP INDEX idx_audit_retention, DROP retention_until');
        $this->addSql('ALTER TABLE payment_transactions DROP INDEX idx_payment_retention, DROP retention_until');
        $this->addSql('ALTER TABLE invoices DROP INDEX idx_invoice_retention, DROP retention_until');
        $this->addSql('ALTER TABLE tenants DROP logo_storage_path');
    }
}
