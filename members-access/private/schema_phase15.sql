-- Phase 15: a fourth type, Intern (outside the team tree, like Admin and Tester).
-- Run ONCE in phpMyAdmin > your database > SQL tab, BEFORE using Intern. Safe to run again.
ALTER TABLE members MODIFY COLUMN member_type ENUM('team','admin','tester','intern') NOT NULL DEFAULT 'team';
