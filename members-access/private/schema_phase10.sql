-- Phase 10: Members & Access (per-person tool ticks, member types, standard no-access page).
-- Run ONCE in cPanel > phpMyAdmin > your database (dreamhub_members) > SQL tab, BEFORE uploading the matching zip.
SET NAMES utf8mb4;

-- Team member (default, everyone today), Admin (staff with their own job) or Tester.
ALTER TABLE members ADD COLUMN IF NOT EXISTS member_type ENUM('team','admin','tester') NOT NULL DEFAULT 'team' AFTER is_team_admin;

-- Pause switch: a paused person is blocked everywhere (pages and data) but keeps their record, team place and candidates.
ALTER TABLE members ADD COLUMN IF NOT EXISTS is_suspended TINYINT(1) NOT NULL DEFAULT 0 AFTER member_type;

-- One row per person per tool they may use. The super admin needs no rows (always has every tool).
CREATE TABLE IF NOT EXISTS tool_access (
  member_id INT UNSIGNED NOT NULL,
  tool_key  VARCHAR(60)  NOT NULL,
  PRIMARY KEY (member_id, tool_key),
  CONSTRAINT fk_tool_access_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- So nobody loses a tool on the day this goes live: every current active member gets every tool the toolkit page shows
-- them today (all except Monthly Report and the Site Admin tools). Trim them afterwards under Members & Access.
-- Run this part only once; running it again would re-tick tools you have since switched off.
INSERT IGNORE INTO tool_access (member_id, tool_key)
SELECT m.id, t.k FROM members m
CROSS JOIN (SELECT 'panel-finder' AS k UNION ALL SELECT 'great-journey-finder' UNION ALL SELECT 'protection-needs' UNION ALL SELECT 'sum-assured' UNION ALL SELECT 'legacy-planner' UNION ALL SELECT 'ci-poster-generator' UNION ALL SELECT 'policy-summary' UNION ALL SELECT 'career-code' UNION ALL SELECT 'career-code-candidates' UNION ALL SELECT 'event-links' UNION ALL SELECT 'event-invitees' UNION ALL SELECT 'million-income' UNION ALL SELECT 'activity' UNION ALL SELECT 'team' UNION ALL SELECT 'agency-report' UNION ALL SELECT 'nominee-form' UNION ALL SELECT 'hnw-coach') t
WHERE m.is_admin = 0 AND m.is_active = 1;
