-- Phase 2: candidates + assessments. Run in phpMyAdmin > SQL tab (database dreamhub_members) AFTER schema.sql.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS candidates (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_member_id    INT UNSIGNED NOT NULL,                 -- the advisor this candidate belongs to
  purpose            ENUM('career','internship') NOT NULL DEFAULT 'career',
  source             ENUM('landing','referral','live') NOT NULL,
  name               VARCHAR(120) NOT NULL,
  mobile             VARCHAR(20)  NOT NULL,                 -- normalised +60xxxxxxxxx
  email              VARCHAR(190) NULL,
  age_group          TINYINT UNSIGNED NULL,                 -- index into the form's list
  education          TINYINT UNSIGNED NULL,
  work_status        TINYINT UNSIGNED NULL,
  field              VARCHAR(190) NULL,
  lang               ENUM('en','zh') NOT NULL DEFAULT 'en',
  consent_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  consent_version    VARCHAR(20) NOT NULL,
  pipeline_status    ENUM('new','contacted','interviewed','joined','not_suitable') NOT NULL DEFAULT 'new',
  duplicate_of       INT UNSIGNED NULL,                     -- first record for the same mobile/email
  submit_token_hash  CHAR(64) NULL,
  ip_hash            CHAR(64) NULL,
  started_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at       TIMESTAMP NULL DEFAULT NULL,
  result_emailed_at  TIMESTAMP NULL DEFAULT NULL,
  notified_at        TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_cand_owner (owner_member_id),
  KEY idx_cand_mobile (mobile),
  KEY idx_cand_email (email),
  KEY idx_cand_ip (ip_hash, started_at),
  KEY idx_cand_started (started_at),
  CONSTRAINT fk_cand_owner FOREIGN KEY (owner_member_id) REFERENCES members (id) ON DELETE RESTRICT,
  CONSTRAINT fk_cand_dup   FOREIGN KEY (duplicate_of)    REFERENCES candidates (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessments (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  candidate_id         INT UNSIGNED NOT NULL,
  mode                 ENUM('test','manual') NOT NULL DEFAULT 'test',
  answers_json         MEDIUMTEXT NULL,                      -- raw answers; scores are always recomputed server-side
  scores_json          TEXT NOT NULL,
  code                 CHAR(3) NOT NULL,
  entered_by_member_id INT UNSIGNED NULL,                    -- manual entry: who keyed it in
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_assess_candidate (candidate_id),
  CONSTRAINT fk_assess_cand FOREIGN KEY (candidate_id) REFERENCES candidates (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
