<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260821080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add football tournament categories, groups, fields, schedules, results and publications';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'MariaDB/MySQL is required.');
        $this->addSql(<<<'SQL'
CREATE TABLE football_event_settings (
    event_id INT NOT NULL, tenant_id INT NOT NULL,
    points_win SMALLINT DEFAULT 3 NOT NULL, points_draw SMALLINT DEFAULT 1 NOT NULL, points_loss SMALLINT DEFAULT 0 NOT NULL,
    forfait_goals_winner SMALLINT UNSIGNED DEFAULT 3 NOT NULL, forfait_goals_loser SMALLINT UNSIGNED DEFAULT 0 NOT NULL,
    scheduling_strategy VARCHAR(32) DEFAULT 'field_utilization' NOT NULL,
    schedule_state VARCHAR(16) DEFAULT 'draft' NOT NULL, ranking_state VARCHAR(16) DEFAULT 'draft' NOT NULL,
    created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, lock_version INT DEFAULT 1 NOT NULL,
    CONSTRAINT fk_football_settings_event FOREIGN KEY (tenant_id, event_id) REFERENCES events (tenant_id, id) ON DELETE CASCADE,
    PRIMARY KEY(event_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE=InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE football_categories (
    id BIGINT AUTO_INCREMENT NOT NULL, tenant_id INT NOT NULL, event_id INT NOT NULL, public_id VARCHAR(36) NOT NULL,
    name VARCHAR(120) NOT NULL, age_min SMALLINT UNSIGNED DEFAULT NULL, age_max SMALLINT UNSIGNED DEFAULT NULL,
    gender VARCHAR(16) DEFAULT 'open' NOT NULL, max_roster_size SMALLINT UNSIGNED DEFAULT 15 NOT NULL,
    players_on_field SMALLINT UNSIGNED DEFAULT 7 NOT NULL, match_minutes SMALLINT UNSIGNED DEFAULT 15 NOT NULL,
    min_break_minutes SMALLINT UNSIGNED DEFAULT 15 NOT NULL, overtime_minutes SMALLINT UNSIGNED DEFAULT 5 NOT NULL,
    tournament_mode VARCHAR(32) DEFAULT 'semifinal_final' NOT NULL, group_size SMALLINT UNSIGNED DEFAULT 4 NOT NULL,
    third_place_enabled TINYINT(1) DEFAULT 0 NOT NULL, knockout_draw_mode VARCHAR(32) DEFAULT 'penalties' NOT NULL,
    qualify_group_winners SMALLINT UNSIGNED DEFAULT 1 NOT NULL, qualify_group_runners_up SMALLINT UNSIGNED DEFAULT 1 NOT NULL,
    qualify_best_thirds SMALLINT UNSIGNED DEFAULT 0 NOT NULL, exclude_last_for_cross_group TINYINT(1) DEFAULT 0 NOT NULL,
    sort_order INT DEFAULT 0 NOT NULL, active TINYINT(1) DEFAULT 1 NOT NULL,
    created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, lock_version INT DEFAULT 1 NOT NULL,
    UNIQUE INDEX uniq_football_category_public (public_id),
    UNIQUE INDEX uniq_football_category_name (tenant_id, event_id, name),
    UNIQUE INDEX uniq_football_category_scope (tenant_id, event_id, id),
    CONSTRAINT fk_football_category_event FOREIGN KEY (tenant_id, event_id) REFERENCES events (tenant_id, id) ON DELETE CASCADE,
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE=InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE football_groups (
    id BIGINT AUTO_INCREMENT NOT NULL, tenant_id INT NOT NULL, event_id INT NOT NULL, category_id BIGINT NOT NULL,
    public_id VARCHAR(36) NOT NULL, name VARCHAR(80) NOT NULL, sort_order INT DEFAULT 0 NOT NULL,
    created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, lock_version INT DEFAULT 1 NOT NULL,
    UNIQUE INDEX uniq_football_group_public (public_id),
    UNIQUE INDEX uniq_football_group_name (tenant_id, category_id, name),
    UNIQUE INDEX uniq_football_group_scope (tenant_id, event_id, id),
    CONSTRAINT fk_football_group_event FOREIGN KEY (tenant_id, event_id) REFERENCES events (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_football_group_category FOREIGN KEY (tenant_id, event_id, category_id) REFERENCES football_categories (tenant_id, event_id, id) ON DELETE CASCADE,
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE=InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE football_team_data (
    team_id BIGINT NOT NULL, tenant_id INT NOT NULL, event_id INT NOT NULL, category_id BIGINT NOT NULL, group_id BIGINT DEFAULT NULL,
    withdrawn_at DATETIME DEFAULT NULL, withdrawal_reason VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, lock_version INT DEFAULT 1 NOT NULL,
    INDEX idx_football_team_category (tenant_id, event_id, category_id), INDEX idx_football_team_group (tenant_id, event_id, group_id),
    CONSTRAINT fk_football_team_event_team FOREIGN KEY (tenant_id, event_id, team_id) REFERENCES event_teams (tenant_id, event_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_football_team_category FOREIGN KEY (tenant_id, event_id, category_id) REFERENCES football_categories (tenant_id, event_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_football_team_group FOREIGN KEY (tenant_id, event_id, group_id) REFERENCES football_groups (tenant_id, event_id, id) ON DELETE RESTRICT,
    PRIMARY KEY(team_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE=InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE football_fields (
    id BIGINT AUTO_INCREMENT NOT NULL, tenant_id INT NOT NULL, event_id INT NOT NULL, public_id VARCHAR(36) NOT NULL,
    name VARCHAR(120) NOT NULL, sort_order INT DEFAULT 0 NOT NULL, active TINYINT(1) DEFAULT 1 NOT NULL,
    created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, lock_version INT DEFAULT 1 NOT NULL,
    UNIQUE INDEX uniq_football_field_public (public_id), UNIQUE INDEX uniq_football_field_name (tenant_id, event_id, name),
    UNIQUE INDEX uniq_football_field_scope (tenant_id, event_id, id),
    CONSTRAINT fk_football_field_event FOREIGN KEY (tenant_id, event_id) REFERENCES events (tenant_id, id) ON DELETE CASCADE,
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE=InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE football_field_periods (
    id BIGINT AUTO_INCREMENT NOT NULL, tenant_id INT NOT NULL, event_id INT NOT NULL, field_id BIGINT NOT NULL,
    period_type VARCHAR(16) NOT NULL, starts_at DATETIME NOT NULL, ends_at DATETIME NOT NULL, reason VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_football_field_period (tenant_id, event_id, field_id, starts_at),
    CONSTRAINT fk_football_period_field FOREIGN KEY (tenant_id, event_id, field_id) REFERENCES football_fields (tenant_id, event_id, id) ON DELETE CASCADE,
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE=InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE football_matches (
    id BIGINT AUTO_INCREMENT NOT NULL, tenant_id INT NOT NULL, event_id INT NOT NULL, public_id VARCHAR(36) NOT NULL,
    category_id BIGINT NOT NULL, group_id BIGINT DEFAULT NULL, stage VARCHAR(24) DEFAULT 'group' NOT NULL,
    round_number SMALLINT UNSIGNED DEFAULT 1 NOT NULL, sequence_number INT UNSIGNED NOT NULL,
    home_team_id BIGINT DEFAULT NULL, away_team_id BIGINT DEFAULT NULL,
    home_source_match_id BIGINT DEFAULT NULL, away_source_match_id BIGINT DEFAULT NULL,
    home_source_outcome VARCHAR(8) DEFAULT NULL, away_source_outcome VARCHAR(8) DEFAULT NULL,
    field_id BIGINT DEFAULT NULL, scheduled_start DATETIME DEFAULT NULL, duration_minutes SMALLINT UNSIGNED NOT NULL,
    status VARCHAR(20) DEFAULT 'scheduled' NOT NULL, home_goals SMALLINT UNSIGNED DEFAULT NULL, away_goals SMALLINT UNSIGNED DEFAULT NULL,
    home_penalties SMALLINT UNSIGNED DEFAULT NULL, away_penalties SMALLINT UNSIGNED DEFAULT NULL,
    home_yellow SMALLINT UNSIGNED DEFAULT 0 NOT NULL, away_yellow SMALLINT UNSIGNED DEFAULT 0 NOT NULL,
    home_red SMALLINT UNSIGNED DEFAULT 0 NOT NULL, away_red SMALLINT UNSIGNED DEFAULT 0 NOT NULL,
    is_forfait TINYINT(1) DEFAULT 0 NOT NULL, counts_for_standings TINYINT(1) DEFAULT 1 NOT NULL,
    winner_team_id BIGINT DEFAULT NULL, manual_override TINYINT(1) DEFAULT 0 NOT NULL,
    created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, lock_version INT DEFAULT 1 NOT NULL,
    UNIQUE INDEX uniq_football_match_public (public_id), UNIQUE INDEX uniq_football_match_sequence (tenant_id, event_id, sequence_number),
    UNIQUE INDEX uniq_football_match_scope (tenant_id, event_id, id),
    INDEX idx_football_match_time_field (tenant_id, event_id, scheduled_start, field_id), INDEX idx_football_match_group (tenant_id, event_id, group_id),
    CONSTRAINT fk_football_match_event FOREIGN KEY (tenant_id, event_id) REFERENCES events (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_football_match_category FOREIGN KEY (tenant_id, event_id, category_id) REFERENCES football_categories (tenant_id, event_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_football_match_group FOREIGN KEY (tenant_id, event_id, group_id) REFERENCES football_groups (tenant_id, event_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_football_match_field FOREIGN KEY (tenant_id, event_id, field_id) REFERENCES football_fields (tenant_id, event_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_football_match_home FOREIGN KEY (tenant_id, event_id, home_team_id) REFERENCES event_teams (tenant_id, event_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_football_match_away FOREIGN KEY (tenant_id, event_id, away_team_id) REFERENCES event_teams (tenant_id, event_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_football_match_winner FOREIGN KEY (tenant_id, event_id, winner_team_id) REFERENCES event_teams (tenant_id, event_id, id) ON DELETE RESTRICT,
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE=InnoDB
SQL);
        $this->addSql('ALTER TABLE football_matches ADD CONSTRAINT fk_football_match_home_source FOREIGN KEY (tenant_id, event_id, home_source_match_id) REFERENCES football_matches (tenant_id, event_id, id) ON DELETE RESTRICT, ADD CONSTRAINT fk_football_match_away_source FOREIGN KEY (tenant_id, event_id, away_source_match_id) REFERENCES football_matches (tenant_id, event_id, id) ON DELETE RESTRICT');
        $this->addSql(<<<'SQL'
CREATE TABLE football_tiebreak_decisions (
    id BIGINT AUTO_INCREMENT NOT NULL, tenant_id INT NOT NULL, event_id INT NOT NULL, group_id BIGINT DEFAULT NULL,
    public_id VARCHAR(36) NOT NULL, affected_team_ids JSON NOT NULL, ordered_team_ids JSON NOT NULL,
    decided_by_user_id INT NOT NULL, reason VARCHAR(255) DEFAULT 'Losentscheid' NOT NULL, created_at DATETIME NOT NULL,
    UNIQUE INDEX uniq_football_tiebreak_public (public_id), INDEX idx_football_tiebreak_scope (tenant_id, event_id, group_id),
    CONSTRAINT fk_football_tiebreak_event FOREIGN KEY (tenant_id, event_id) REFERENCES events (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_football_tiebreak_user FOREIGN KEY (tenant_id, decided_by_user_id) REFERENCES tenant_users (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_football_tiebreak_group FOREIGN KEY (tenant_id, event_id, group_id) REFERENCES football_groups (tenant_id, event_id, id) ON DELETE RESTRICT,
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE=InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE football_publications (
    id BIGINT AUTO_INCREMENT NOT NULL, tenant_id INT NOT NULL, event_id INT NOT NULL, document_type VARCHAR(24) NOT NULL,
    version_number INT UNSIGNED NOT NULL, snapshot JSON NOT NULL, published_by_user_id INT NOT NULL,
    published_at DATETIME NOT NULL, withdrawn_by_user_id INT DEFAULT NULL, withdrawn_at DATETIME DEFAULT NULL,
    UNIQUE INDEX uniq_football_publication_version (tenant_id, event_id, document_type, version_number),
    INDEX idx_football_publication_active (tenant_id, event_id, document_type, withdrawn_at),
    CONSTRAINT fk_football_publication_event FOREIGN KEY (tenant_id, event_id) REFERENCES events (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_football_publication_user FOREIGN KEY (tenant_id, published_by_user_id) REFERENCES tenant_users (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_football_publication_withdrawer FOREIGN KEY (tenant_id, withdrawn_by_user_id) REFERENCES tenant_users (tenant_id, id) ON DELETE RESTRICT,
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE=InnoDB
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE football_publications');
        $this->addSql('DROP TABLE football_tiebreak_decisions');
        $this->addSql('DROP TABLE football_matches');
        $this->addSql('DROP TABLE football_field_periods');
        $this->addSql('DROP TABLE football_fields');
        $this->addSql('DROP TABLE football_team_data');
        $this->addSql('DROP TABLE football_groups');
        $this->addSql('DROP TABLE football_categories');
        $this->addSql('DROP TABLE football_event_settings');
    }
}
