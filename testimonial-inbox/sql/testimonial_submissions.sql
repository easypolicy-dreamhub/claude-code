-- Run once in cPanel > phpMyAdmin > (your site database) > SQL tab.
-- Holds testimonials that clients send through /share-testimonial/ until the admin approves or rejects them.
-- Approving copies the testimonial into the existing `testimonials` table; nothing here is ever shown publicly.
CREATE TABLE IF NOT EXISTS testimonial_submissions (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_name    VARCHAR(120) NOT NULL,
  area           VARCHAR(120) NOT NULL,
  rating         TINYINT UNSIGNED NOT NULL,
  message        TEXT NOT NULL,
  phone          VARCHAR(30) NULL,                 -- private, for the admin only
  status         VARCHAR(10) NOT NULL DEFAULT 'pending',  -- pending | approved | rejected
  testimonial_id INT UNSIGNED NULL,                -- the row created in `testimonials` on approval
  ip_hash        CHAR(64) NOT NULL,                -- hashed, only used to stop spam floods
  created_at     DATETIME NOT NULL,
  reviewed_at    DATETIME NULL,
  reviewed_by    INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_status_created (status, created_at),
  KEY idx_ip_created (ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
