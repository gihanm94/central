-- Everbetter ranking: Management names the 1st, 2nd and 3rd project of each year.
ALTER TABLE everbetter_projects ADD COLUMN IF NOT EXISTS award_year SMALLINT;
ALTER TABLE everbetter_projects ADD COLUMN IF NOT EXISTS award_rank SMALLINT;
DO $$ BEGIN
    ALTER TABLE everbetter_projects ADD CONSTRAINT everbetter_award_check CHECK ((award_year IS NULL) = (award_rank IS NULL) AND (award_rank IS NULL OR award_rank BETWEEN 1 AND 3));
EXCEPTION WHEN duplicate_object THEN NULL; END $$;
CREATE UNIQUE INDEX IF NOT EXISTS everbetter_award_idx ON everbetter_projects (award_year, award_rank) WHERE award_rank IS NOT NULL AND deleted_at IS NULL;
