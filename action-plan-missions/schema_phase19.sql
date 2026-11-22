-- Phase 19: links in the Action Plan: resources shared on a task (ebook, video, folder) and the work an agent shares back.
-- Run ONCE in cPanel > phpMyAdmin > your database (dreamhub_members) > SQL tab, BEFORE using the new zip.
-- Running it a second time gives "Duplicate column name": harmless, the data is not touched.
-- It only ADDS one table and one column. Nothing existing is changed or deleted.
SET NAMES utf8mb4;

-- One row per link.
--   kind 'resource': shared by the supervisor on a task. plan_id empty = on the template, for everyone;
--                    plan_id set = added by that person's coach for that person only.
--   kind 'work':     shared by the agent (plan_id = their plan) to show their own work.
-- Only links (web addresses) are kept. Nothing is uploaded and nothing is fetched from them.
CREATE TABLE IF NOT EXISTS ap_links (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  plan_id     INT UNSIGNED NULL,
  item_id     INT UNSIGNED NOT NULL,
  kind        VARCHAR(10)  NOT NULL,
  title       VARCHAR(120) NOT NULL,
  url         VARCHAR(500) NOT NULL,
  url_type    VARCHAR(10)  NOT NULL DEFAULT 'other',
  note        VARCHAR(255) NULL,
  added_by    INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ap_links_item (item_id),
  KEY idx_ap_links_plan (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One optional "work folder" link per plan (for example a Google Drive folder), set by the agent.
ALTER TABLE ap_plans ADD COLUMN folder_url VARCHAR(500) NULL AFTER reveal_rewards;
