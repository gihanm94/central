-- Each person can keep the filters they chose on a list (Filter sheet → Apply): they are applied again every time the list is opened.
ALTER TABLE user_table_prefs ADD COLUMN IF NOT EXISTS filters JSONB NOT NULL DEFAULT '{}'::jsonb;
