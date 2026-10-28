-- NULL = the person never chose columns, so a list's own default (some columns start hidden) applies.
ALTER TABLE user_table_prefs ALTER COLUMN hidden DROP NOT NULL;
ALTER TABLE user_table_prefs ALTER COLUMN hidden DROP DEFAULT;
UPDATE user_table_prefs SET hidden = NULL WHERE hidden = '[]'::jsonb;
