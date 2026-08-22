<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260821050000 extends AbstractMigration
{
    public function getDescription(): string { return 'Complete event model, templates and versioned module defaults'; }
    public function isTransactional(): bool { return false; }
    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'MariaDB/MySQL is required.');
        $this->addSql(<<<'SQL'
            CREATE TABLE event_templates (
                id BIGINT AUTO_INCREMENT NOT NULL, tenant_id INT DEFAULT NULL, module_id INT NOT NULL, public_id VARCHAR(36) NOT NULL,
                scope VARCHAR(20) NOT NULL, name VARCHAR(180) NOT NULL, active TINYINT(1) DEFAULT 1 NOT NULL,
                created_by_platform_admin_id INT DEFAULT NULL, created_by_user_id INT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_event_template_public_id (public_id), UNIQUE INDEX uniq_event_template_name (tenant_id, module_id, scope, name),
                INDEX idx_event_template_visible (scope, active, module_id),
                CONSTRAINT fk_event_template_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
                CONSTRAINT fk_event_template_module FOREIGN KEY (module_id) REFERENCES sport_modules (id) ON DELETE RESTRICT,
                CONSTRAINT fk_event_template_admin FOREIGN KEY (created_by_platform_admin_id) REFERENCES platform_admins (id) ON DELETE RESTRICT,
                CONSTRAINT fk_event_template_user FOREIGN KEY (created_by_user_id) REFERENCES tenant_users (id) ON DELETE SET NULL,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE event_template_versions (
                id BIGINT AUTO_INCREMENT NOT NULL, template_id BIGINT NOT NULL, version_number INT UNSIGNED NOT NULL,
                configuration JSON NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_event_template_version (template_id, version_number),
                CONSTRAINT fk_event_template_version_template FOREIGN KEY (template_id) REFERENCES event_templates (id) ON DELETE RESTRICT,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE module_default_versions (
                id BIGINT AUTO_INCREMENT NOT NULL, module_id INT NOT NULL, version_number INT UNSIGNED NOT NULL,
                configuration JSON NOT NULL, valid_from DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', created_by_platform_admin_id INT NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_module_default_version (module_id, version_number), INDEX idx_module_default_validity (module_id, valid_from),
                CONSTRAINT fk_module_default_module FOREIGN KEY (module_id) REFERENCES sport_modules (id) ON DELETE RESTRICT,
                CONSTRAINT fk_module_default_admin FOREIGN KEY (created_by_platform_admin_id) REFERENCES platform_admins (id) ON DELETE RESTRICT,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE events ADD module_id INT DEFAULT NULL, ADD template_version_id BIGINT DEFAULT NULL,
                ADD starts_on DATE DEFAULT NULL, ADD ends_on DATE DEFAULT NULL, ADD location VARCHAR(255) DEFAULT NULL,
                ADD internal_notes LONGTEXT DEFAULT NULL, ADD configuration JSON DEFAULT NULL, ADD cancellation_reason VARCHAR(1000) DEFAULT NULL,
                ADD completed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', ADD archived_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                ADD updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', ADD lock_version INT DEFAULT 1 NOT NULL,
                ADD INDEX idx_event_module (module_id), ADD INDEX idx_event_status_dates (tenant_id, status, starts_on), ADD INDEX idx_event_template_version (template_version_id),
                ADD CONSTRAINT fk_event_module FOREIGN KEY (module_id) REFERENCES sport_modules (id) ON DELETE RESTRICT,
                ADD CONSTRAINT fk_event_template_version FOREIGN KEY (template_version_id) REFERENCES event_template_versions (id) ON DELETE RESTRICT
            SQL);
        $this->addSql("UPDATE events SET module_id = (SELECT id FROM sport_modules ORDER BY id LIMIT 1), starts_on = DATE(created_at), ends_on = DATE(created_at), configuration = JSON_OBJECT(), updated_at = created_at WHERE module_id IS NULL");
        $this->addSql('ALTER TABLE events MODIFY module_id INT NOT NULL, MODIFY starts_on DATE NOT NULL, MODIFY ends_on DATE NOT NULL, MODIFY configuration JSON NOT NULL, MODIFY updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE events DROP FOREIGN KEY fk_event_template_version, DROP FOREIGN KEY fk_event_module, DROP INDEX idx_event_template_version, DROP INDEX idx_event_status_dates, DROP INDEX idx_event_module, DROP module_id, DROP template_version_id, DROP starts_on, DROP ends_on, DROP location, DROP internal_notes, DROP configuration, DROP cancellation_reason, DROP completed_at, DROP archived_at, DROP updated_at, DROP lock_version');
        $this->addSql('DROP TABLE module_default_versions'); $this->addSql('DROP TABLE event_template_versions'); $this->addSql('DROP TABLE event_templates');
    }
}
