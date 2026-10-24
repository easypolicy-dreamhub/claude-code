-- Team page: a "team admin" switch for Group Managers who stay in the system, and a batch id so
-- "reactivate with their team" brings back exactly the people who were removed together.
-- Run in phpMyAdmin > dreamhub_members > SQL tab.
ALTER TABLE members ADD COLUMN IF NOT EXISTS is_team_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER is_admin;
ALTER TABLE members ADD COLUMN IF NOT EXISTS removal_batch CHAR(16) NULL DEFAULT NULL AFTER deactivated_at;
