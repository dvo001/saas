-- Plattform-/SaaS-Erweiterung fuer bestehende Installationen.
-- Vorher ein Datenbankbackup erstellen.

CREATE TABLE IF NOT EXISTS plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    max_events INT NULL,
    max_users INT NULL,
    features JSON NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plan_id INT NULL,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    contact_email VARCHAR(190) NULL,
    billing_email VARCHAR(190) NULL,
    status ENUM('trial', 'active', 'past_due', 'suspended', 'cancelled') NOT NULL DEFAULT 'trial',
    trial_ends_at DATETIME NULL,
    subscription_ends_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_tenants_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE SET NULL,
    INDEX idx_tenants_status (status)
);

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('invited', 'active', 'disabled') NOT NULL DEFAULT 'active',
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS tenant_users (
    tenant_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('owner', 'admin', 'operator', 'viewer') NOT NULL DEFAULT 'operator',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, user_id),
    CONSTRAINT fk_tenant_users_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_tenant_users_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_tenant_users_user (user_id)
);

CREATE TABLE IF NOT EXISTS invitations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    email VARCHAR(190) NOT NULL,
    role ENUM('admin', 'operator', 'viewer') NOT NULL DEFAULT 'operator',
    token_hash VARCHAR(255) NOT NULL,
    invited_by_user_id INT NULL,
    expires_at DATETIME NOT NULL,
    accepted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_invitations_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_invitations_inviter FOREIGN KEY (invited_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_invitations_tenant_email (tenant_id, email)
);

CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_password_resets_token (token_hash)
);

CREATE TABLE IF NOT EXISTS subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    plan_id INT NULL,
    provider VARCHAR(50) NULL,
    provider_customer_id VARCHAR(190) NULL,
    provider_subscription_id VARCHAR(190) NULL,
    status ENUM('trialing', 'active', 'past_due', 'paused', 'cancelled') NOT NULL DEFAULT 'trialing',
    current_period_ends_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_subscriptions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_subscriptions_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE SET NULL,
    UNIQUE KEY uq_subscriptions_tenant (tenant_id),
    INDEX idx_subscriptions_status (status),
    INDEX idx_subscriptions_provider (provider, provider_subscription_id)
);

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NULL,
    user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(100) NULL,
    entity_id VARCHAR(100) NULL,
    metadata JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_logs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE SET NULL,
    CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_logs_tenant_created (tenant_id, created_at),
    INDEX idx_audit_logs_action (action)
);

CREATE TABLE IF NOT EXISTS sports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    scoring_mode ENUM('timed', 'tournament', 'points', 'bracket', 'custom') NOT NULL DEFAULT 'custom',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO plans (code, name, max_events, max_users, features)
VALUES
    ('starter', 'Starter', 5, 3, JSON_OBJECT('exports', true, 'pdf', true)),
    ('club', 'Verein', 25, 10, JSON_OBJECT('exports', true, 'pdf', true, 'multi_sport', true)),
    ('pro', 'Pro', NULL, NULL, JSON_OBJECT('exports', true, 'pdf', true, 'multi_sport', true, 'support', true))
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    max_events = VALUES(max_events),
    max_users = VALUES(max_users),
    features = VALUES(features),
    active = 1;

INSERT INTO sports (code, name, scoring_mode)
VALUES
    ('running', 'Lauf', 'timed'),
    ('football', 'Fussballturnier', 'tournament'),
    ('athletics', 'Leichtathletik', 'points'),
    ('judo', 'Judo', 'bracket'),
    ('custom', 'Andere Sportart', 'custom')
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    scoring_mode = VALUES(scoring_mode),
    active = 1;

SET @starter_plan_id := (SELECT id FROM plans WHERE code = 'starter' LIMIT 1);
SET @running_sport_id := (SELECT id FROM sports WHERE code = 'running' LIMIT 1);

INSERT INTO tenants (plan_id, name, slug, status, trial_ends_at)
SELECT @starter_plan_id, 'Standard-Organisation', 'standard', 'active', DATE_ADD(NOW(), INTERVAL 30 DAY)
WHERE NOT EXISTS (SELECT 1 FROM tenants);

SET @default_tenant_id := (SELECT id FROM tenants ORDER BY id LIMIT 1);

INSERT INTO subscriptions (tenant_id, plan_id, provider, status, current_period_ends_at)
SELECT @default_tenant_id, @starter_plan_id, 'manual', 'active', DATE_ADD(NOW(), INTERVAL 1 MONTH)
WHERE @default_tenant_id IS NOT NULL
ON DUPLICATE KEY UPDATE
    plan_id = VALUES(plan_id),
    status = VALUES(status),
    current_period_ends_at = VALUES(current_period_ends_at);

ALTER TABLE events
    ADD COLUMN IF NOT EXISTS tenant_id INT NULL AFTER id,
    ADD COLUMN IF NOT EXISTS sport_id INT NULL AFTER tenant_id,
    ADD COLUMN IF NOT EXISTS discipline_label VARCHAR(100) NULL AFTER distance_label,
    ADD COLUMN IF NOT EXISTS scoring_mode ENUM('timed', 'tournament', 'points', 'bracket', 'custom') NOT NULL DEFAULT 'timed' AFTER discipline_label,
    ADD INDEX IF NOT EXISTS idx_events_tenant_date (tenant_id, event_date),
    ADD INDEX IF NOT EXISTS idx_events_sport (sport_id);

UPDATE events
SET tenant_id = COALESCE(tenant_id, @default_tenant_id),
    sport_id = COALESCE(sport_id, @running_sport_id),
    scoring_mode = COALESCE(scoring_mode, 'timed'),
    discipline_label = COALESCE(discipline_label, distance_label);

DELIMITER //
CREATE PROCEDURE add_saas_event_constraints()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = 'events'
          AND CONSTRAINT_NAME = 'fk_events_tenant'
    ) THEN
        ALTER TABLE events
            ADD CONSTRAINT fk_events_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = 'events'
          AND CONSTRAINT_NAME = 'fk_events_sport'
    ) THEN
        ALTER TABLE events
            ADD CONSTRAINT fk_events_sport FOREIGN KEY (sport_id) REFERENCES sports(id) ON DELETE SET NULL;
    END IF;
END//
DELIMITER ;
CALL add_saas_event_constraints();
DROP PROCEDURE add_saas_event_constraints;

CREATE TABLE IF NOT EXISTS teams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    group_label VARCHAR(100) NULL,
    contact_name VARCHAR(150) NULL,
    contact_email VARCHAR(190) NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_teams_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    UNIQUE KEY uq_teams_event_name (event_id, name)
);

CREATE TABLE IF NOT EXISTS sport_disciplines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    discipline_type ENUM('match', 'attempts', 'points', 'bracket', 'custom') NOT NULL DEFAULT 'custom',
    sort_order INT NOT NULL DEFAULT 0,
    config JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_sport_disciplines_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    INDEX idx_sport_disciplines_event (event_id, sort_order)
);

CREATE TABLE IF NOT EXISTS sport_matches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    discipline_id INT NULL,
    group_label VARCHAR(100) NULL,
    round_label VARCHAR(100) NULL,
    home_team_id INT NULL,
    away_team_id INT NULL,
    scheduled_at DATETIME NULL,
    home_score DECIMAL(10,2) NULL,
    away_score DECIMAL(10,2) NULL,
    status ENUM('scheduled', 'running', 'completed', 'cancelled') NOT NULL DEFAULT 'scheduled',
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_sport_matches_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_sport_matches_discipline FOREIGN KEY (discipline_id) REFERENCES sport_disciplines(id) ON DELETE SET NULL,
    CONSTRAINT fk_sport_matches_home FOREIGN KEY (home_team_id) REFERENCES teams(id) ON DELETE SET NULL,
    CONSTRAINT fk_sport_matches_away FOREIGN KEY (away_team_id) REFERENCES teams(id) ON DELETE SET NULL,
    INDEX idx_sport_matches_event (event_id, scheduled_at),
    INDEX idx_sport_matches_group (event_id, group_label)
);

CREATE TABLE IF NOT EXISTS sport_scores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    discipline_id INT NULL,
    participant_id INT NULL,
    team_id INT NULL,
    score_value DECIMAL(10,2) NULL,
    score_text VARCHAR(190) NULL,
    rank_position INT NULL,
    status ENUM('pending', 'valid', 'dns', 'dnf', 'dsq') NOT NULL DEFAULT 'pending',
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_sport_scores_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_sport_scores_discipline FOREIGN KEY (discipline_id) REFERENCES sport_disciplines(id) ON DELETE SET NULL,
    CONSTRAINT fk_sport_scores_participant FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE SET NULL,
    CONSTRAINT fk_sport_scores_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL,
    INDEX idx_sport_scores_event (event_id),
    INDEX idx_sport_scores_discipline (discipline_id)
);
