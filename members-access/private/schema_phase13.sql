-- Phase 13: "Open Client Portal" shortcut on the four items that are counted from the Client Portal.
-- Run ONCE in cPanel > phpMyAdmin > your database > SQL tab. No new tables, nothing is deleted.
-- It finds the items by what they count (not by their title), so it still works if you renamed them
-- (for example "New business (12)"). An item that already has a shortcut is left alone.
SET NAMES utf8mb4;

UPDATE ap_items SET tool_key = 'client-portal'
WHERE plan_id IS NULL AND tool_key IS NULL AND auto_key IN ('prospects', 'open', 'present', 'closes');
