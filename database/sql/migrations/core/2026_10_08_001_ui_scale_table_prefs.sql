-- Display size per person, and which table columns / page size each person chose.
ALTER TABLE users ADD COLUMN IF NOT EXISTS ui_scale SMALLINT;     -- percent of the normal text size, NULL = 100

CREATE TABLE IF NOT EXISTS user_table_prefs (
    user_id    BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    resource   VARCHAR(60) NOT NULL,                              -- e.g. crm_leads, tasks
    hidden     JSONB NOT NULL DEFAULT '[]',                       -- column keys the person turned off
    per_page   SMALLINT,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (user_id, resource)
);
