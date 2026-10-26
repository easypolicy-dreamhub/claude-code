-- DISC step 2: resume uploads. Run in phpMyAdmin > dreamhub_members > SQL tab BEFORE uploading the matching zip.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS candidate_files (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  candidate_id  INT UNSIGNED NOT NULL,
  kind          VARCHAR(20) NOT NULL DEFAULT 'resume',
  original_name VARCHAR(190) NOT NULL,                  -- cleaned name shown to advisors
  stored_name   VARCHAR(80) NOT NULL,                   -- random name on disk in private/uploads/resumes/ (never public)
  mime          VARCHAR(100) NOT NULL,
  size_bytes    INT UNSIGNED NOT NULL,
  uploaded_by   VARCHAR(20) NOT NULL,                   -- 'candidate' or the advisor's member id
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_file_candidate (candidate_id),
  CONSTRAINT fk_file_cand FOREIGN KEY (candidate_id) REFERENCES candidates (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
