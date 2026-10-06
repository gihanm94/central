-- How many rows each run read and saved
ALTER TABLE erp_sync_runs ADD COLUMN IF NOT EXISTS fetched INTEGER NOT NULL DEFAULT 0;
ALTER TABLE erp_sync_runs ADD COLUMN IF NOT EXISTS saved   INTEGER NOT NULL DEFAULT 0;
