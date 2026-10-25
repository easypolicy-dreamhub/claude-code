-- Phase 4: live mode + paper-score entry. Run in phpMyAdmin > dreamhub_members > SQL tab BEFORE uploading the matching zip.
-- (referred_by_member_id from schema_phase3c.sql must already exist; run that first if you have not.)
ALTER TABLE candidates ADD COLUMN IF NOT EXISTS client_id CHAR(32) NULL AFTER notified_at;
ALTER TABLE candidates ADD UNIQUE INDEX IF NOT EXISTS uq_cand_client (client_id);
