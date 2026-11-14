-- Phase 11: Action Plan (template, per-associate plans, progress, per-person adjustments).
-- Run ONCE in cPanel > phpMyAdmin > your database (dreamhub_members) > SQL tab, BEFORE uploading the matching zip.
SET NAMES utf8mb4;

-- Sections of the template (Preparation, Knowledge, ...). Can be turned off, never needed to delete.
CREATE TABLE IF NOT EXISTS ap_sections (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(80)  NOT NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  active      TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Template items (plan_id NULL) and "extra for this person" items (plan_id set).
-- kind: tick (done / not done) or count (a number with a target). week_from..week_to is the suggested window (weeks of a 90-day plan).
-- Removing an item that anybody has worked on archives it (archived = 1) so nobody's history is lost.
CREATE TABLE IF NOT EXISTS ap_items (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  plan_id     INT UNSIGNED NULL,
  section_id  INT UNSIGNED NULL,
  title       VARCHAR(160) NOT NULL,
  details     TEXT NULL,
  week_from   TINYINT UNSIGNED NOT NULL DEFAULT 1,
  week_to     TINYINT UNSIGNED NOT NULL DEFAULT 1,
  kind        VARCHAR(8) NOT NULL DEFAULT 'tick',
  target      INT UNSIGNED NULL,
  auto_key    VARCHAR(40) NULL,            -- later: counted automatically from the toolkit
  tool_key    VARCHAR(60) NULL,            -- "Open tool" shortcut
  sort_order  INT NOT NULL DEFAULT 0,
  active      TINYINT(1) NOT NULL DEFAULT 1,
  archived    TINYINT(1) NOT NULL DEFAULT 0,
  created_on  DATE NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ap_items_plan (plan_id),
  KEY idx_ap_items_section (section_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One plan per associate.
CREATE TABLE IF NOT EXISTS ap_plans (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id       INT UNSIGNED NOT NULL,
  start_date      DATE NOT NULL,
  length_days     SMALLINT UNSIGNED NOT NULL DEFAULT 90,
  disc            VARCHAR(2) NULL,
  private_number  TINYINT UNSIGNED NULL,   -- shown to the super admin only
  assigned_by     INT UNSIGNED NULL,
  closed          TINYINT(1) NOT NULL DEFAULT 0,
  created_at      DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ap_plans_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Progress of one person on one item. status: todo | waiting (agent finished, needs approval) | done (approved) | followup (sent back)
CREATE TABLE IF NOT EXISTS ap_progress (
  plan_id       INT UNSIGNED NOT NULL,
  item_id       INT UNSIGNED NOT NULL,
  status        VARCHAR(10) NOT NULL DEFAULT 'todo',
  cnt           INT UNSIGNED NOT NULL DEFAULT 0,
  note          VARCHAR(500) NULL,
  submitted_at  DATETIME NULL,
  approved_by   INT UNSIGNED NULL,
  approved_at   DATETIME NULL,
  updated_at    DATETIME NOT NULL,
  PRIMARY KEY (plan_id, item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Adjustments for one person only: skip an item, move its weeks, change its target.
CREATE TABLE IF NOT EXISTS ap_overrides (
  plan_id     INT UNSIGNED NOT NULL,
  item_id     INT UNSIGNED NOT NULL,
  skip        TINYINT(1) NOT NULL DEFAULT 0,
  skip_note   VARCHAR(200) NULL,
  week_from   TINYINT UNSIGNED NULL,
  week_to     TINYINT UNSIGNED NULL,
  target      INT UNSIGNED NULL,
  PRIMARY KEY (plan_id, item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
