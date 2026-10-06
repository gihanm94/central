-- Personal preferences, employment details and notification settings.
-- Safe to run more than once.

ALTER TABLE users ADD COLUMN IF NOT EXISTS locale            VARCHAR(10);   -- NULL = company default
ALTER TABLE users ADD COLUMN IF NOT EXISTS default_module    VARCHAR(40);   -- module opened after sign-in
ALTER TABLE users ADD COLUMN IF NOT EXISTS default_dashboard VARCHAR(60);   -- dashboard opened in that module
ALTER TABLE users ADD COLUMN IF NOT EXISTS notify_prefs      JSONB;         -- personal opt-outs per module/action/channel

ALTER TABLE employee_profiles ADD COLUMN IF NOT EXISTS gender            VARCHAR(20);
ALTER TABLE employee_profiles ADD COLUMN IF NOT EXISTS employee_level    VARCHAR(10);
ALTER TABLE employee_profiles ADD COLUMN IF NOT EXISTS employment_status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE';
ALTER TABLE employee_profiles ADD COLUMN IF NOT EXISTS employment_type   VARCHAR(20) NOT NULL DEFAULT 'FULL_TIME';

DO $$ BEGIN
    ALTER TABLE employee_profiles ADD CONSTRAINT employee_gender_chk
        CHECK (gender IS NULL OR gender IN ('MALE','FEMALE','OTHER','PREFER_NOT_TO_SAY'));
EXCEPTION WHEN duplicate_object THEN NULL; END $$;
DO $$ BEGIN
    ALTER TABLE employee_profiles ADD CONSTRAINT employee_level_chk
        CHECK (employee_level IS NULL OR employee_level IN ('C1','C2','C3','C4','C5','C6','C7','C8','C9','C10','C11','C12','OTHER'));
EXCEPTION WHEN duplicate_object THEN NULL; END $$;
DO $$ BEGIN
    ALTER TABLE employee_profiles ADD CONSTRAINT employee_status_chk
        CHECK (employment_status IN ('ACTIVE','PROBATION','SUSPENDED','RESIGNED','TERMINATED','RETIRED'));
EXCEPTION WHEN duplicate_object THEN NULL; END $$;
DO $$ BEGIN
    ALTER TABLE employee_profiles ADD CONSTRAINT employee_type_chk
        CHECK (employment_type IN ('FULL_TIME','PART_TIME','REMOTE','OTHER'));
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

-- Users without a profile row get one, so every person has employment fields.
INSERT INTO employee_profiles (user_id) SELECT id FROM users u
WHERE NOT EXISTS (SELECT 1 FROM employee_profiles p WHERE p.user_id = u.id);

INSERT INTO settings (key, value) VALUES
    ('default_locale', 'en'),
    ('lark_mode', 'off'),                 -- off | webhook (group chat) | app (direct message by e-mail)
    ('lark_domain', 'https://open.larksuite.com'),
    ('lark_webhook_url', NULL),
    ('lark_webhook_secret', NULL),
    ('lark_app_id', NULL),
    ('lark_app_secret', NULL),
    ('notify_matrix', NULL),              -- JSON; NULL = defaults (e-mail on, Lark on)
    ('notify_security', NULL)
ON CONFLICT (key) DO NOTHING;
