-- Phase 17: Action Plan missions: stages with a badge title and a reward, a pop-up when a stage is completed, and "reward given" tracking.
-- Run ONCE in cPanel > phpMyAdmin > your database (dreamhub_members) > SQL tab, BEFORE using the new zip.
-- Running it a second time gives "Duplicate column name": harmless, the data is not touched.
-- It only ADDS columns and tables. Nothing existing is changed or deleted.
SET NAMES utf8mb4;

-- Each section of the template is a stage: it gets a badge title and a template reward (set on the Template screen).
ALTER TABLE ap_sections ADD COLUMN badge_title  VARCHAR(60)  NULL AFTER name;
ALTER TABLE ap_sections ADD COLUMN reward_title VARCHAR(100) NULL AFTER badge_title;
ALTER TABLE ap_sections ADD COLUMN reward_desc  VARCHAR(255) NULL AFTER reward_title;

-- Per plan: may the agent see upcoming rewards? NULL = follow the default below; 1 = show; 0 = keep it a surprise.
ALTER TABLE ap_plans ADD COLUMN reveal_rewards TINYINT(1) NULL AFTER coach_set;

-- Small settings table (so far: the default for showing upcoming rewards, and the day this feature went live).
CREATE TABLE IF NOT EXISTS ap_settings (
  skey    VARCHAR(40)  NOT NULL,
  svalue  VARCHAR(255) NOT NULL,
  PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per plan and stage, created only when something is set: the coach's own reward for that person,
-- when the reward was given (and by whom), and when the agent saw the congratulations pop-up.
CREATE TABLE IF NOT EXISTS ap_plan_stage (
  plan_id      INT UNSIGNED NOT NULL,
  section_id   INT UNSIGNED NOT NULL,
  reward_title VARCHAR(100) NULL,
  reward_desc  VARCHAR(255) NULL,
  given_at     DATETIME NULL,
  given_by     INT UNSIGNED NULL,
  seen_at      DATETIME NULL,
  updated_at   DATETIME NULL,
  PRIMARY KEY (plan_id, section_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ap_settings (skey, svalue) VALUES ('reveal_default', '1') ON DUPLICATE KEY UPDATE svalue = svalue;
-- A stage finished BEFORE today does not pop up for anyone; only stages finished from today on do.
INSERT INTO ap_settings (skey, svalue) VALUES ('missions_from', CURDATE()) ON DUPLICATE KEY UPDATE svalue = svalue;

-- Suggested badge titles for the seven starting sections (only filled in where still empty; change them on the Template screen).
UPDATE ap_sections SET badge_title = 'Ready for Takeoff' WHERE name = 'Preparation'        AND badge_title IS NULL;
UPDATE ap_sections SET badge_title = 'Product Expert'    WHERE name = 'Knowledge'          AND badge_title IS NULL;
UPDATE ap_sections SET badge_title = 'Fast Starter'      WHERE name = 'Kick Start'         AND badge_title IS NULL;
UPDATE ap_sections SET badge_title = 'Field Ready'       WHERE name = 'Practical'          AND badge_title IS NULL;
UPDATE ap_sections SET badge_title = 'Talent Spotter'    WHERE name = 'Talent Acquisition' AND badge_title IS NULL;
UPDATE ap_sections SET badge_title = 'Client Guardian'   WHERE name = 'After-sales'        AND badge_title IS NULL;
UPDATE ap_sections SET badge_title = '90-Day Finisher'   WHERE name = 'Review'             AND badge_title IS NULL;
