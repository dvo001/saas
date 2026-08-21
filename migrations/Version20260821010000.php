<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260821010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tenant-isolated authentication, registration, roles, event assignments and audit data';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'MariaDB/MySQL is required.');

        $this->addSql(<<<'SQL'
            CREATE TABLE tenants (
                id INT AUTO_INCREMENT NOT NULL,
                public_id VARCHAR(36) NOT NULL,
                name VARCHAR(180) NOT NULL,
                slug VARCHAR(80) NOT NULL,
                status VARCHAR(32) NOT NULL,
                trial_module VARCHAR(32) NOT NULL,
                legal_version VARCHAR(20) NOT NULL,
                legal_accepted_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                support_impersonation_enabled TINYINT(1) DEFAULT 1 NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                confirmed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                trial_starts_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                trial_ends_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                registration_reminder_sent_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_tenant_public_id (public_id),
                UNIQUE INDEX uniq_tenant_name (name),
                UNIQUE INDEX uniq_tenant_slug (slug),
                INDEX idx_tenant_status (status),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE tenant_users (
                id INT AUTO_INCREMENT NOT NULL,
                tenant_id INT NOT NULL,
                public_id VARCHAR(36) NOT NULL,
                email VARCHAR(180) NOT NULL,
                display_name VARCHAR(120) NOT NULL,
                tenant_role VARCHAR(32) NOT NULL,
                password VARCHAR(255) NOT NULL,
                active TINYINT(1) DEFAULT 1 NOT NULL,
                email_confirmed TINYINT(1) DEFAULT 0 NOT NULL,
                totp_secret_encrypted LONGTEXT DEFAULT NULL,
                failed_login_count INT DEFAULT 0 NOT NULL,
                locked_until DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                auth_version INT DEFAULT 1 NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                deleted_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_tenant_user_tenant (tenant_id),
                INDEX idx_tenant_user_role (tenant_id, tenant_role),
                UNIQUE INDEX uniq_tenant_user_public_id (public_id),
                UNIQUE INDEX uniq_tenant_user_email (tenant_id, email),
                UNIQUE INDEX uniq_tenant_user_tenant_id (tenant_id, id),
                PRIMARY KEY(id),
                CONSTRAINT fk_tenant_user_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE tenant_auth_tokens (
                id BIGINT AUTO_INCREMENT NOT NULL,
                tenant_id INT NOT NULL,
                user_id INT DEFAULT NULL,
                token_hash CHAR(64) NOT NULL,
                token_type VARCHAR(40) NOT NULL,
                payload JSON DEFAULT NULL,
                expires_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                consumed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_auth_token_tenant (tenant_id),
                INDEX idx_auth_token_user (user_id),
                UNIQUE INDEX uniq_auth_token_hash (token_hash),
                INDEX idx_auth_token_lookup (token_type, expires_at, consumed_at),
                PRIMARY KEY(id),
                CONSTRAINT fk_auth_token_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
                CONSTRAINT fk_auth_token_user FOREIGN KEY (tenant_id, user_id) REFERENCES tenant_users (tenant_id, id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE events (
                id INT AUTO_INCREMENT NOT NULL,
                tenant_id INT NOT NULL,
                public_id VARCHAR(36) NOT NULL,
                primary_event_manager_id INT NOT NULL,
                name VARCHAR(180) NOT NULL,
                status VARCHAR(24) DEFAULT 'draft' NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_event_tenant (tenant_id),
                INDEX idx_event_manager (primary_event_manager_id),
                UNIQUE INDEX uniq_event_public_id (public_id),
                UNIQUE INDEX uniq_event_tenant_name (tenant_id, name),
                UNIQUE INDEX uniq_event_tenant_id (tenant_id, id),
                PRIMARY KEY(id),
                CONSTRAINT fk_event_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
                CONSTRAINT fk_event_primary_manager FOREIGN KEY (tenant_id, primary_event_manager_id) REFERENCES tenant_users (tenant_id, id) ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE event_user_assignments (
                id INT AUTO_INCREMENT NOT NULL,
                tenant_id INT NOT NULL,
                event_id INT NOT NULL,
                user_id INT NOT NULL,
                event_role VARCHAR(32) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_assignment_tenant_event (tenant_id, event_id),
                INDEX idx_assignment_user (tenant_id, user_id),
                UNIQUE INDEX uniq_event_user_assignment (tenant_id, event_id, user_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_assignment_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
                CONSTRAINT fk_assignment_event FOREIGN KEY (tenant_id, event_id) REFERENCES events (tenant_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_assignment_user FOREIGN KEY (tenant_id, user_id) REFERENCES tenant_users (tenant_id, id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE owner_transfers (
                id BIGINT AUTO_INCREMENT NOT NULL,
                tenant_id INT NOT NULL,
                initiated_by_user_id INT NOT NULL,
                target_user_id INT NOT NULL,
                confirmation_token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                confirmed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                cancelled_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_owner_transfer_tenant (tenant_id),
                INDEX idx_owner_transfer_initiator (initiated_by_user_id),
                INDEX idx_owner_transfer_target (target_user_id),
                UNIQUE INDEX uniq_owner_transfer_token (confirmation_token_hash),
                PRIMARY KEY(id),
                CONSTRAINT fk_owner_transfer_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
                CONSTRAINT fk_owner_transfer_initiator FOREIGN KEY (tenant_id, initiated_by_user_id) REFERENCES tenant_users (tenant_id, id) ON DELETE RESTRICT,
                CONSTRAINT fk_owner_transfer_target FOREIGN KEY (tenant_id, target_user_id) REFERENCES tenant_users (tenant_id, id) ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE audit_log (
                id BIGINT AUTO_INCREMENT NOT NULL,
                tenant_id INT DEFAULT NULL,
                actor_user_id INT DEFAULT NULL,
                actor_platform_admin_id INT DEFAULT NULL,
                action VARCHAR(100) NOT NULL,
                subject_type VARCHAR(80) NOT NULL,
                subject_public_id VARCHAR(36) DEFAULT NULL,
                context JSON NOT NULL,
                ip_hash CHAR(64) DEFAULT NULL,
                occurred_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_audit_tenant_time (tenant_id, occurred_at),
                INDEX idx_audit_user (actor_user_id),
                INDEX idx_audit_platform_admin (actor_platform_admin_id),
                INDEX idx_audit_action (action, occurred_at),
                PRIMARY KEY(id),
                CONSTRAINT fk_audit_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE SET NULL,
                CONSTRAINT fk_audit_user FOREIGN KEY (actor_user_id) REFERENCES tenant_users (id) ON DELETE SET NULL,
                CONSTRAINT fk_audit_platform_admin FOREIGN KEY (actor_platform_admin_id) REFERENCES platform_admins (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql('ALTER TABLE platform_admins ADD totp_secret_encrypted LONGTEXT DEFAULT NULL, ADD failed_login_count INT DEFAULT 0 NOT NULL, ADD locked_until DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD auth_version INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE platform_admins DROP totp_secret_encrypted, DROP failed_login_count, DROP locked_until, DROP auth_version');
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('DROP TABLE owner_transfers');
        $this->addSql('DROP TABLE event_user_assignments');
        $this->addSql('DROP TABLE events');
        $this->addSql('DROP TABLE tenant_auth_tokens');
        $this->addSql('DROP TABLE tenant_users');
        $this->addSql('DROP TABLE tenants');
    }
}
