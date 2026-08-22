<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260821040000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add notifications, mail delivery tracking, export queue and deletion records'; }
    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'MariaDB/MySQL is required.');
        $this->addSql(<<<'SQL'
            CREATE TABLE notifications (
                id BIGINT AUTO_INCREMENT NOT NULL,
                tenant_id INT NOT NULL,
                recipient_user_id INT DEFAULT NULL,
                public_id VARCHAR(36) NOT NULL,
                notification_type VARCHAR(60) NOT NULL,
                severity VARCHAR(16) DEFAULT 'info' NOT NULL,
                title VARCHAR(180) NOT NULL,
                message VARCHAR(1000) NOT NULL,
                action_url VARCHAR(500) DEFAULT NULL,
                deduplication_key VARCHAR(190) NOT NULL,
                read_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_notification_public_id (public_id),
                UNIQUE INDEX uniq_notification_dedup (tenant_id, recipient_user_id, deduplication_key),
                INDEX idx_notification_recipient (tenant_id, recipient_user_id, read_at, created_at),
                CONSTRAINT fk_notification_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
                CONSTRAINT fk_notification_user FOREIGN KEY (tenant_id, recipient_user_id) REFERENCES tenant_users (tenant_id, id) ON DELETE CASCADE,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE mail_deliveries (
                id BIGINT AUTO_INCREMENT NOT NULL,
                tenant_id INT DEFAULT NULL,
                public_id VARCHAR(36) NOT NULL,
                template_key VARCHAR(80) NOT NULL,
                recipient_hash CHAR(64) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                status VARCHAR(20) NOT NULL,
                error_reference VARCHAR(36) DEFAULT NULL,
                sent_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_mail_delivery_public_id (public_id),
                INDEX idx_mail_delivery_status (status, created_at),
                INDEX idx_mail_delivery_tenant (tenant_id, created_at),
                CONSTRAINT fk_mail_delivery_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE SET NULL,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE export_jobs (
                id BIGINT AUTO_INCREMENT NOT NULL,
                tenant_id INT NOT NULL,
                requested_by_user_id INT NOT NULL,
                public_id VARCHAR(36) NOT NULL,
                export_type VARCHAR(60) NOT NULL,
                status VARCHAR(20) NOT NULL,
                storage_path VARCHAR(255) DEFAULT NULL,
                error_reference VARCHAR(36) DEFAULT NULL,
                expires_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                started_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                finished_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_export_job_public_id (public_id),
                INDEX idx_export_job_queue (status, created_at),
                CONSTRAINT fk_export_job_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
                CONSTRAINT fk_export_job_user FOREIGN KEY (tenant_id, requested_by_user_id) REFERENCES tenant_users (tenant_id, id) ON DELETE CASCADE,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE deletion_log (
                id BIGINT AUTO_INCREMENT NOT NULL,
                tenant_public_id_hash CHAR(64) NOT NULL,
                reason VARCHAR(80) NOT NULL,
                deleted_counts JSON NOT NULL,
                deleted_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_deletion_log_time (deleted_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql('ALTER TABLE maintenance_windows ADD notified_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE cron_runs ADD result_context JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE tenants ADD trial_retention_until DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD INDEX idx_tenant_trial_retention (status, trial_retention_until)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenants DROP INDEX idx_tenant_trial_retention, DROP trial_retention_until');
        $this->addSql('ALTER TABLE cron_runs DROP result_context');
        $this->addSql('ALTER TABLE maintenance_windows DROP notified_at');
        $this->addSql('DROP TABLE deletion_log');
        $this->addSql('DROP TABLE export_jobs');
        $this->addSql('DROP TABLE mail_deliveries');
        $this->addSql('DROP TABLE notifications');
    }
}
