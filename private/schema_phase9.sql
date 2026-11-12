-- Phase 9: each advisor can have their own WhatsApp number (digits with country code, e.g. 60123456789).
-- Run once in cPanel > phpMyAdmin (select your database first), SQL tab.
ALTER TABLE members ADD COLUMN whatsapp VARCHAR(20) NULL DEFAULT NULL AFTER email;
