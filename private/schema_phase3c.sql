-- Remember which advisor's personal link a candidate used, even when the candidate stays with the first advisor
-- (repeat applicants). Run in phpMyAdmin > dreamhub_members > SQL tab BEFORE uploading the matching zip.
ALTER TABLE candidates ADD COLUMN IF NOT EXISTS referred_by_member_id INT UNSIGNED NULL AFTER owner_member_id;
