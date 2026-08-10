-- Realistischer, wiederholt ausfuehrbarer Demo-Seed fuer Sportanlaesse.
-- Voraussetzung: database/schema.sql wurde bereits importiert.
--
-- Demo-Login (nur fuer lokale Demo-/Testsysteme):
--   E-Mail:  owner@demo.test
--   Passwort: Demo1234!
--
-- Der Seed arbeitet ausschliesslich in der Organisation mit dem Slug
-- "demo-sportverein" und veraendert keine anderen Organisationen.

START TRANSACTION;

INSERT INTO plans (code, name, max_events, max_users, features)
VALUES
    ('starter', 'Starter', 5, 3, JSON_OBJECT('exports', true, 'pdf', true)),
    ('club', 'Verein', 25, 10, JSON_OBJECT('exports', true, 'pdf', true, 'multi_sport', true)),
    ('pro', 'Pro', NULL, NULL, JSON_OBJECT('exports', true, 'pdf', true, 'multi_sport', true, 'support', true))
ON DUPLICATE KEY UPDATE name = VALUES(name), max_events = VALUES(max_events),
    max_users = VALUES(max_users), features = VALUES(features), active = 1;

INSERT INTO sports (code, name, scoring_mode)
VALUES
    ('running', 'Lauf', 'timed'),
    ('football', 'Fussballturnier', 'tournament'),
    ('athletics', 'Leichtathletik', 'points'),
    ('judo', 'Judo', 'bracket'),
    ('custom', 'Andere Sportart', 'custom')
ON DUPLICATE KEY UPDATE name = VALUES(name), scoring_mode = VALUES(scoring_mode), active = 1;

SET @club_plan_id := (SELECT id FROM plans WHERE code = 'club' LIMIT 1);
SET @running_sport_id := (SELECT id FROM sports WHERE code = 'running' LIMIT 1);
SET @football_sport_id := (SELECT id FROM sports WHERE code = 'football' LIMIT 1);

INSERT INTO tenants (plan_id, name, slug, contact_email, billing_email, status, subscription_ends_at)
VALUES (@club_plan_id, 'Sportverein Musterwil', 'demo-sportverein', 'verein@demo.test',
        'rechnung@demo.test', 'active', DATE_ADD(CURDATE(), INTERVAL 1 YEAR))
ON DUPLICATE KEY UPDATE plan_id = VALUES(plan_id), name = VALUES(name),
    contact_email = VALUES(contact_email), billing_email = VALUES(billing_email), status = 'active';

SET @tenant_id := (SELECT id FROM tenants WHERE slug = 'demo-sportverein' LIMIT 1);

INSERT INTO subscriptions (tenant_id, plan_id, provider, status, current_period_ends_at)
VALUES (@tenant_id, @club_plan_id, 'manual', 'active', DATE_ADD(CURDATE(), INTERVAL 1 YEAR))
ON DUPLICATE KEY UPDATE plan_id = VALUES(plan_id), provider = VALUES(provider),
    status = VALUES(status), current_period_ends_at = VALUES(current_period_ends_at);

-- Alle Demo-Konten verwenden absichtlich dasselbe, oben dokumentierte Passwort.
INSERT INTO users (name, email, password_hash, status)
VALUES
    ('Olivia Muster', 'owner@demo.test', '$2y$12$6NThOCMazfwucpeBjPXiCOrc2Omfl8/4wtaridHQbVfjLZHw5jewu', 'active'),
    ('Adrian Keller', 'admin@demo.test', '$2y$12$6NThOCMazfwucpeBjPXiCOrc2Omfl8/4wtaridHQbVfjLZHw5jewu', 'active'),
    ('Simone Frei', 'operator@demo.test', '$2y$12$6NThOCMazfwucpeBjPXiCOrc2Omfl8/4wtaridHQbVfjLZHw5jewu', 'active'),
    ('Viktor Roth', 'viewer@demo.test', '$2y$12$6NThOCMazfwucpeBjPXiCOrc2Omfl8/4wtaridHQbVfjLZHw5jewu', 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), password_hash = VALUES(password_hash), status = 'active';

INSERT INTO tenant_users (tenant_id, user_id, role)
SELECT @tenant_id, id,
    CASE email WHEN 'owner@demo.test' THEN 'owner' WHEN 'admin@demo.test' THEN 'admin'
        WHEN 'operator@demo.test' THEN 'operator' ELSE 'viewer' END
FROM users WHERE email IN ('owner@demo.test', 'admin@demo.test', 'operator@demo.test', 'viewer@demo.test')
ON DUPLICATE KEY UPDATE role = VALUES(role);

INSERT INTO events (tenant_id, sport_id, name, event_date, distance_label, discipline_label,
                    scoring_mode, time_window, qualification_runs, final_enabled,
                    finalists_per_group, logo_path, status, notes)
SELECT @tenant_id, @running_sport_id, 'Musterwiler Fruehlingslauf 2026', '2026-05-16',
       '600 m', 'Sprintlauf', 'timed', '09:00–13:00', 2, 1, 2,
       '/assets/img/laufblatt-logo.png', 'active', 'Demo-Anlass mit Qualifikation und Finale.'
WHERE NOT EXISTS (SELECT 1 FROM events WHERE tenant_id = @tenant_id AND name = 'Musterwiler Fruehlingslauf 2026');

INSERT INTO events (tenant_id, sport_id, name, event_date, distance_label, discipline_label,
                    scoring_mode, time_window, qualification_runs, final_enabled,
                    finalists_per_group, status, notes)
SELECT @tenant_id, @football_sport_id, 'Musterwiler Gruempeli 2026', '2026-06-20',
       'Kleinfeld', 'Junioren-Turnier', 'tournament', '10:00–17:00', 1, 0, 0,
       'preparation', 'Demo fuer die generische Turnierwertung.'
WHERE NOT EXISTS (SELECT 1 FROM events WHERE tenant_id = @tenant_id AND name = 'Musterwiler Gruempeli 2026');

SET @run_event_id := (SELECT id FROM events WHERE tenant_id = @tenant_id AND name = 'Musterwiler Fruehlingslauf 2026' LIMIT 1);
SET @football_event_id := (SELECT id FROM events WHERE tenant_id = @tenant_id AND name = 'Musterwiler Gruempeli 2026' LIMIT 1);

INSERT INTO categories (event_id, name, year_from, year_to, sort_order, active)
SELECT @run_event_id, seed.name, seed.year_from, seed.year_to, seed.sort_order, 1
FROM (
    SELECT 'U10' name, 2017 year_from, 2019 year_to, 10 sort_order UNION ALL
    SELECT 'U12', 2015, 2016, 20 UNION ALL
    SELECT 'U14', 2013, 2014, 30 UNION ALL
    SELECT 'U16', 2011, 2012, 40
) seed
WHERE NOT EXISTS (SELECT 1 FROM categories c WHERE c.event_id = @run_event_id AND c.name = seed.name);

-- 24 Teilnehmende: je Kategorie drei Maedchen und drei Knaben.
INSERT INTO participants (event_id, category_id, sheet_number, last_name, first_name,
                          birth_year, gender, school_class, city, notes)
SELECT @run_event_id, (SELECT id FROM categories WHERE event_id = @run_event_id AND name = p.category LIMIT 1),
       p.sheet, p.last_name, p.first_name, p.birth_year, p.gender, p.school_class, p.city, 'Demo-Seed'
FROM (
    SELECT '101' sheet, 'Meier' last_name, 'Lina' first_name, 2018 birth_year, 'female' gender, 'U10' category, '3a' school_class, 'Musterwil' city UNION ALL
    SELECT '102','Keller','Mia',2018,'female','U10','3b','Musterwil' UNION ALL
    SELECT '103','Frei','Elena',2017,'female','U10','4a','Nachbardorf' UNION ALL
    SELECT '104','Huber','Noah',2018,'male','U10','3a','Musterwil' UNION ALL
    SELECT '105','Roth','Liam',2017,'male','U10','4b','Nachbardorf' UNION ALL
    SELECT '106','Schmid','Finn',2019,'male','U10','2a','Musterwil' UNION ALL
    SELECT '201','Baumann','Sofia',2016,'female','U12','5a','Musterwil' UNION ALL
    SELECT '202','Gerber','Emma',2015,'female','U12','6a','Nachbardorf' UNION ALL
    SELECT '203','Steiner','Nora',2016,'female','U12','5b','Musterwil' UNION ALL
    SELECT '204','Weber','Leon',2015,'male','U12','6a','Musterwil' UNION ALL
    SELECT '205','Fischer','Elias',2016,'male','U12','5a','Nachbardorf' UNION ALL
    SELECT '206','Mueller','Ben',2015,'male','U12','6b','Musterwil' UNION ALL
    SELECT '301','Brunner','Lea',2014,'female','U14','7a','Musterwil' UNION ALL
    SELECT '302','Widmer','Anna',2013,'female','U14','8a','Nachbardorf' UNION ALL
    SELECT '303','Graf','Laura',2014,'female','U14','7b','Musterwil' UNION ALL
    SELECT '304','Moser','Luca',2013,'male','U14','8a','Musterwil' UNION ALL
    SELECT '305','Suter','Jonas',2014,'male','U14','7a','Nachbardorf' UNION ALL
    SELECT '306','Ammann','David',2013,'male','U14','8b','Musterwil' UNION ALL
    SELECT '401','Bachmann','Julia',2012,'female','U16','9a','Musterwil' UNION ALL
    SELECT '402','Hauser','Sara',2011,'female','U16','9b','Nachbardorf' UNION ALL
    SELECT '403','Kunz','Nina',2012,'female','U16','9a','Musterwil' UNION ALL
    SELECT '404','Peter','Tim',2011,'male','U16','9b','Musterwil' UNION ALL
    SELECT '405','Wenger','Jan',2012,'male','U16','9a','Nachbardorf' UNION ALL
    SELECT '406','Ziegler','Nico',2011,'male','U16','9b','Musterwil'
) p
ON DUPLICATE KEY UPDATE category_id = VALUES(category_id), last_name = VALUES(last_name),
    first_name = VALUES(first_name), birth_year = VALUES(birth_year), gender = VALUES(gender),
    school_class = VALUES(school_class), city = VALUES(city), notes = VALUES(notes);

INSERT INTO results (participant_id)
SELECT id FROM participants p WHERE p.event_id = @run_event_id
ON DUPLICATE KEY UPDATE participant_id = VALUES(participant_id);

-- Plausible Zeiten in Zehntelsekunden; Blatt 203 ist DNS, 306 DNF.
UPDATE results r JOIN participants p ON p.id = r.participant_id
SET r.run1_time_tenths = CASE WHEN p.sheet_number IN ('203','306') THEN NULL ELSE 118 + MOD(CAST(p.sheet_number AS UNSIGNED) * 7, 65) END,
    r.run2_time_tenths = CASE WHEN p.sheet_number IN ('203','306') THEN NULL ELSE 116 + MOD(CAST(p.sheet_number AS UNSIGNED) * 11, 67) END,
    r.best_qualification_time_tenths = CASE WHEN p.sheet_number IN ('203','306') THEN NULL ELSE LEAST(118 + MOD(CAST(p.sheet_number AS UNSIGNED) * 7, 65), 116 + MOD(CAST(p.sheet_number AS UNSIGNED) * 11, 67)) END,
    r.qualification_status = CASE p.sheet_number WHEN '203' THEN 'dns' WHEN '306' THEN 'dnf' ELSE 'valid' END,
    r.is_finalist = 0, r.finalist_confirmed = 0, r.final_time_tenths = NULL,
    r.final_status = 'not_qualified', r.notes = 'Demo-Seed'
WHERE p.event_id = @run_event_id AND p.notes = 'Demo-Seed';

-- Zwei bestaetigte Finalisten pro Kategorie/Geschlecht, gemaess Qualifikationszeit.
UPDATE results r
JOIN (
    SELECT ranked.id FROM (
        SELECT r2.id,
               ROW_NUMBER() OVER (PARTITION BY p2.category_id, p2.gender ORDER BY r2.best_qualification_time_tenths, p2.id) AS pos
        FROM results r2 JOIN participants p2 ON p2.id = r2.participant_id
        WHERE p2.event_id = @run_event_id AND r2.qualification_status = 'valid'
    ) ranked WHERE ranked.pos <= 2
) finalists ON finalists.id = r.id
JOIN participants p ON p.id = r.participant_id
SET r.is_finalist = 1, r.finalist_confirmed = 1,
    r.final_time_tenths = r.best_qualification_time_tenths + MOD(CAST(p.sheet_number AS UNSIGNED), 5) - 2,
    r.final_status = 'valid';

-- Fussball-Demo: vier Teams, eine Disziplin und vier Gruppenspiele.
INSERT INTO teams (event_id, name, group_label, contact_name, contact_email, notes)
VALUES
    (@football_event_id, 'FC Musterwil Blau', 'Gruppe A', 'Marco Beispiel', 'blau@demo.test', 'Demo-Seed'),
    (@football_event_id, 'FC Musterwil Weiss', 'Gruppe A', 'Sarah Beispiel', 'weiss@demo.test', 'Demo-Seed'),
    (@football_event_id, 'SC Nachbardorf', 'Gruppe A', 'Nina Beispiel', 'sc@demo.test', 'Demo-Seed'),
    (@football_event_id, 'SV Bergtal', 'Gruppe A', 'Tom Beispiel', 'sv@demo.test', 'Demo-Seed')
ON DUPLICATE KEY UPDATE group_label = VALUES(group_label), contact_name = VALUES(contact_name),
    contact_email = VALUES(contact_email), notes = VALUES(notes);

INSERT INTO sport_disciplines (event_id, name, discipline_type, sort_order, config)
SELECT @football_event_id, 'Gruppenphase', 'match', 10,
       JSON_OBJECT('duration_minutes', 12, 'points_win', 3, 'points_draw', 1)
WHERE NOT EXISTS (SELECT 1 FROM sport_disciplines WHERE event_id = @football_event_id AND name = 'Gruppenphase');
SET @discipline_id := (SELECT id FROM sport_disciplines WHERE event_id = @football_event_id AND name = 'Gruppenphase' LIMIT 1);

INSERT INTO sport_matches (event_id, discipline_id, group_label, round_label, home_team_id,
                           away_team_id, scheduled_at, home_score, away_score, status, notes)
SELECT @football_event_id, @discipline_id, 'Gruppe A', games.round_label,
       (SELECT id FROM teams WHERE event_id = @football_event_id AND name = games.home_name),
       (SELECT id FROM teams WHERE event_id = @football_event_id AND name = games.away_name),
       games.scheduled_at, games.home_score, games.away_score, games.status, 'Demo-Seed'
FROM (
    SELECT 'Runde 1' round_label, 'FC Musterwil Blau' home_name, 'SV Bergtal' away_name, '2026-06-20 10:00:00' scheduled_at, 3.00 home_score, 1.00 away_score, 'completed' status UNION ALL
    SELECT 'Runde 1','FC Musterwil Weiss','SC Nachbardorf','2026-06-20 10:20:00',2.00,2.00,'completed' UNION ALL
    SELECT 'Runde 2','SC Nachbardorf','FC Musterwil Blau','2026-06-20 11:00:00',NULL,NULL,'scheduled' UNION ALL
    SELECT 'Runde 2','SV Bergtal','FC Musterwil Weiss','2026-06-20 11:20:00',NULL,NULL,'scheduled'
) games
WHERE NOT EXISTS (
    SELECT 1 FROM sport_matches m
    WHERE m.event_id = @football_event_id AND m.round_label = games.round_label
      AND m.home_team_id = (SELECT id FROM teams WHERE event_id = @football_event_id AND name = games.home_name)
      AND m.away_team_id = (SELECT id FROM teams WHERE event_id = @football_event_id AND name = games.away_name)
);

INSERT INTO audit_logs (tenant_id, user_id, action, entity_type, entity_id, metadata)
SELECT @tenant_id, (SELECT id FROM users WHERE email = 'owner@demo.test'), 'demo.seeded',
       'tenant', CAST(@tenant_id AS CHAR), JSON_OBJECT('source', 'database/seed.sql')
WHERE NOT EXISTS (SELECT 1 FROM audit_logs WHERE tenant_id = @tenant_id AND action = 'demo.seeded');

COMMIT;
