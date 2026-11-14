-- Action Plan step 3: a buddy for each plan, and when the leader last sent a WhatsApp nudge.
-- Run ONCE (phpMyAdmin > your database > SQL). Running it a second time gives "Duplicate column": that is harmless.
ALTER TABLE ap_plans ADD COLUMN buddy_id INT UNSIGNED NULL AFTER private_number;
ALTER TABLE ap_plans ADD COLUMN last_nudged_at DATETIME NULL AFTER buddy_id;
