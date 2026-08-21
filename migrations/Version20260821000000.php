<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260821000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create platform administrators and versioned platform settings';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'MariaDB/MySQL is required.');

        $this->addSql(<<<'SQL'
            CREATE TABLE platform_admins (
                id INT AUTO_INCREMENT NOT NULL,
                public_id VARCHAR(36) NOT NULL,
                email VARCHAR(180) NOT NULL,
                password VARCHAR(255) NOT NULL,
                roles JSON NOT NULL,
                active TINYINT(1) DEFAULT 1 NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_platform_admin_public_id (public_id),
                UNIQUE INDEX uniq_platform_admin_email (email),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE platform_settings (
                id INT AUTO_INCREMENT NOT NULL,
                setting_key VARCHAR(100) NOT NULL,
                value JSON NOT NULL,
                valid_from DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                changed_by_platform_admin_id INT DEFAULT NULL,
                INDEX idx_setting_effective (setting_key, valid_from),
                INDEX idx_setting_admin (changed_by_platform_admin_id),
                UNIQUE INDEX uniq_setting_effective_date (setting_key, valid_from),
                PRIMARY KEY(id),
                CONSTRAINT fk_setting_admin FOREIGN KEY (changed_by_platform_admin_id)
                    REFERENCES platform_admins (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE platform_settings');
        $this->addSql('DROP TABLE platform_admins');
    }
}
