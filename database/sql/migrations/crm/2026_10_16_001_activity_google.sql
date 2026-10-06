-- Activities appear on the owner's Google Calendar when they have connected it.
ALTER TABLE activities ADD COLUMN IF NOT EXISTS google_event_id VARCHAR(120);
ALTER TABLE activities ADD COLUMN IF NOT EXISTS google_user_id BIGINT;      -- whose calendar holds the event
