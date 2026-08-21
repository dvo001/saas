<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260821020000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add platform administration, invitations, support sessions and maintenance windows'; }
    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'MariaDB/MySQL is required.');
        $this->addSql("ALTER TABLE platform_admins ADD display_name VARCHAR(120) DEFAULT '' NOT NULL, ADD email_confirmed TINYINT(1) DEFAULT 1 NOT NULL, ADD deleted_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql(<<<'SQL'
            CREATE TABLE platform_admin_tokens (
                id BIGINT AUTO_INCREMENT NOT NULL,
                platform_admin_id INT NOT NULL,
                token_hash CHAR(64) NOT NULL,
                token_type VARCHAR(40) NOT NULL,
                expires_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                consumed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_platform_admin_token_hash (token_hash),
                INDEX idx_platform_admin_token (platform_admin_id, token_type, expires_at),
                PRIMARY KEY(id),
                CONSTRAINT fk_platform_admin_token_admin FOREIGN KEY (platform_admin_id) REFERENCES platform_admins (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE support_sessions (
                id BIGINT AUTO_INCREMENT NOT NULL,
                public_id VARCHAR(36) NOT NULL,
                tenant_id INT NOT NULL,
                platform_admin_id INT NOT NULL,
                token_hash CHAR(64) NOT NULL,
                reason VARCHAR(500) NOT NULL,
                started_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                expires_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                ended_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_support_session_public_id (public_id),
                UNIQUE INDEX uniq_support_session_token (token_hash),
                INDEX idx_support_session_tenant (tenant_id, started_at),
                INDEX idx_support_session_admin (platform_admin_id, started_at),
                PRIMARY KEY(id),
                CONSTRAINT fk_support_session_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
                CONSTRAINT fk_support_session_admin FOREIGN KEY (platform_admin_id) REFERENCES platform_admins (id) ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE maintenance_windows (
                id BIGINT AUTO_INCREMENT NOT NULL,
                public_id VARCHAR(36) NOT NULL,
                created_by_platform_admin_id INT NOT NULL,
                starts_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                expected_end_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                message VARCHAR(1000) NOT NULL,
                cancelled_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_maintenance_public_id (public_id),
                INDEX idx_maintenance_schedule (starts_at, expected_end_at, cancelled_at),
                INDEX idx_maintenance_admin (created_by_platform_admin_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_maintenance_admin FOREIGN KEY (created_by_platform_admin_id) REFERENCES platform_admins (id) ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE cron_runs (
                id BIGINT AUTO_INCREMENT NOT NULL,
                public_id VARCHAR(36) NOT NULL,
                job_name VARCHAR(120) NOT NULL,
                started_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                finished_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                status VARCHAR(20) NOT NULL,
                error_reference VARCHAR(36) DEFAULT NULL,
                UNIQUE INDEX uniq_cron_run_public_id (public_id),
                INDEX idx_cron_run_job_time (job_name, started_at),
                INDEX idx_cron_run_status (status, started_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cron_runs');
        $this->addSql('DROP TABLE maintenance_windows');
        $this->addSql('DROP TABLE support_sessions');
        $this->addSql('DROP TABLE platform_admin_tokens');
        $this->addSql('ALTER TABLE platform_admins DROP display_name, DROP email_confirmed, DROP deleted_at');
    }
}
