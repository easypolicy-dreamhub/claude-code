-- DISC step 1: invites and results. Run in phpMyAdmin > dreamhub_members > SQL tab BEFORE uploading the matching zip.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS disc_invites (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  candidate_id         INT UNSIGNED NOT NULL,
  token                CHAR(64) NOT NULL,                      -- secret link code (kept so "Copy link" and "Send reminder" can reuse the same link)
  mode                 ENUM('email','live') NOT NULL DEFAULT 'email',   -- live: advisor started it in the room, no mobile-digits check
  created_by_member_id INT UNSIGNED NOT NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_sent_at         TIMESTAMP NULL DEFAULT NULL,
  sent_count           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  wrong_tries          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  completed_at         TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_disc_token (token),
  KEY idx_disc_candidate (candidate_id),
  CONSTRAINT fk_disc_inv_cand FOREIGN KEY (candidate_id) REFERENCES candidates (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS disc_results (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  candidate_id INT UNSIGNED NOT NULL,
  invite_id    INT UNSIGNED NULL,
  answers_json MEDIUMTEXT NOT NULL,
  scores_json  TEXT NOT NULL,                                  -- {"D":n,"I":n,"S":n,"C":n}
  style        VARCHAR(4) NOT NULL,                            -- e.g. "I" or "IS"
  lang         ENUM('en','zh') NOT NULL DEFAULT 'en',
  duration_sec INT UNSIGNED NULL,
  flags_json   TEXT NULL,
  consent_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_disc_res_candidate (candidate_id),
  CONSTRAINT fk_disc_res_cand FOREIGN KEY (candidate_id) REFERENCES candidates (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
