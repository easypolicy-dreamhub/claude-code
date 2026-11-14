-- Phase 12: Action Plan step 2 (automatic counts). Run ONCE in cPanel > phpMyAdmin > your database > SQL tab, BEFORE uploading the matching zip.
-- No new tables. It only switches on the automatic count for the five items of your starting template that can use it.
-- (An item you have renamed, or one that already has an automatic count, is left alone. You can set any of them yourself
--  later in Action Plan > Template > Edit > "Counted automatically from".)
SET NAMES utf8mb4;

UPDATE ap_items SET auto_key = 'prospects'    WHERE plan_id IS NULL AND auto_key IS NULL AND title = 'Prospect list (100)';
UPDATE ap_items SET auto_key = 'open'         WHERE plan_id IS NULL AND auto_key IS NULL AND title = 'Natural market survey (70)';
UPDATE ap_items SET auto_key = 'present'      WHERE plan_id IS NULL AND auto_key IS NULL AND title = 'Presentations (50)';
UPDATE ap_items SET auto_key = 'closes'       WHERE plan_id IS NULL AND auto_key IS NULL AND title = 'New business (3)';
UPDATE ap_items SET auto_key = 'career_added' WHERE plan_id IS NULL AND auto_key IS NULL AND title = 'Career Introduction 1+2';
