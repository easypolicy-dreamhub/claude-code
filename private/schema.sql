-- DreamHub E3 members platform: phase 1 schema (MySQL 5.7+ / MariaDB 10.3+)
-- Run once in cPanel > phpMyAdmin (select your database first).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS members (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email          VARCHAR(190) NOT NULL,
  name           VARCHAR(120) NOT NULL,
  -- Label only. Visibility is decided by upline_id, never by rank.
  rank_label     ENUM('agent','unit_manager','group_manager') NOT NULL DEFAULT 'agent',
  upline_id      INT UNSIGNED NULL,
  is_admin       TINYINT(1) NOT NULL DEFAULT 0,
  referral_code  VARCHAR(32) NOT NULL,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deactivated_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_members_email (email),
  UNIQUE KEY uq_members_referral (referral_code),
  KEY idx_members_upline (upline_id),
  -- RESTRICT: members are deactivated, never deleted (their candidates stay visible upline).
  CONSTRAINT fk_members_upline FOREIGN KEY (upline_id) REFERENCES members (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_id   INT UNSIGNED NULL,          -- NULL = command line / system
  action     VARCHAR(40) NOT NULL,
  subject_id INT UNSIGNED NULL,
  detail     TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_subject (subject_id),
  KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
