-- Phase 16: a coach for each Action Plan (the person who approves, sends back and nudges that plan).
-- Run ONCE in phpMyAdmin > your database > SQL tab, BEFORE using the new zip. Running it twice gives "Duplicate column": harmless.
-- coach_set = 0 means "not decided yet": the site fills in the default coach (the nearest Unit Manager or above, else you) the first time the Action Plan opens.
ALTER TABLE ap_plans ADD COLUMN coach_id INT UNSIGNED NULL AFTER buddy_id;
ALTER TABLE ap_plans ADD COLUMN coach_set TINYINT(1) NOT NULL DEFAULT 0 AFTER coach_id;
