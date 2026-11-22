-- Phase 18: a badge icon for each stage of the Action Plan (picked on the Template screen).
-- Run ONCE in cPanel > phpMyAdmin > your database (dreamhub_members) > SQL tab, BEFORE using the new zip.
-- Running it a second time gives "Duplicate column name": harmless, the data is not touched.
-- It only ADDS one column. Nothing existing is changed or deleted.
SET NAMES utf8mb4;

ALTER TABLE ap_sections ADD COLUMN badge_icon VARCHAR(20) NULL AFTER badge_title;

-- Starting icons for the seven original sections (only where still empty; change them on the Template screen).
UPDATE ap_sections SET badge_icon = 'leap'   WHERE name = 'Preparation'        AND badge_icon IS NULL;
UPDATE ap_sections SET badge_icon = 'book'   WHERE name = 'Knowledge'          AND badge_icon IS NULL;
UPDATE ap_sections SET badge_icon = 'bolt'   WHERE name = 'Kick Start'         AND badge_icon IS NULL;
UPDATE ap_sections SET badge_icon = 'flag'   WHERE name = 'Practical'          AND badge_icon IS NULL;
UPDATE ap_sections SET badge_icon = 'scout'  WHERE name = 'Talent Acquisition' AND badge_icon IS NULL;
UPDATE ap_sections SET badge_icon = 'shield' WHERE name = 'After-sales'        AND badge_icon IS NULL;
UPDATE ap_sections SET badge_icon = 'cup'    WHERE name = 'Review'             AND badge_icon IS NULL;
