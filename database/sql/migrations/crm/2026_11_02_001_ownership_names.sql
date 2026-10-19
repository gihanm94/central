-- Owner history keeps the people's names next to their ids, so the history still reads right after a user is
-- renamed or removed. Older rows are filled in the first time their history is shown (users live in another database).
ALTER TABLE ownership_history ADD COLUMN IF NOT EXISTS from_user_name  VARCHAR(150);
ALTER TABLE ownership_history ADD COLUMN IF NOT EXISTS to_user_name    VARCHAR(150);
ALTER TABLE ownership_history ADD COLUMN IF NOT EXISTS changed_by_name VARCHAR(150);
