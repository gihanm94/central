-- "KPIs & OKRs" (User Management) is the list people submit monthly results for (KPI submissions).
-- Each item is a KPI or an OKR with a criteria (1–100). Replaces the KPI / OKR type on tasks from 2026_10_24.
ALTER TABLE kpis ADD COLUMN IF NOT EXISTS kind     VARCHAR(10) NOT NULL DEFAULT 'kpi';
ALTER TABLE kpis ADD COLUMN IF NOT EXISTS criteria NUMERIC(6,2);
DO $$ BEGIN
    ALTER TABLE kpis ADD CONSTRAINT kpis_kind_check CHECK (kind IN ('kpi','okr'));
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

ALTER TABLE kpi_submissions ADD COLUMN IF NOT EXISTS kpi_id BIGINT REFERENCES kpis(id) ON DELETE SET NULL;
CREATE UNIQUE INDEX IF NOT EXISTS kpi_submissions_once_kpi ON kpi_submissions (kpi_id, period_year, period_month) WHERE deleted_at IS NULL AND kpi_id IS NOT NULL;

-- Move KPI / OKR tasks made since yesterday into KPIs & OKRs (whole current year), and point their submissions at them.
INSERT INTO kpis (user_id, department_id, team_id, created_by, title, description, unit, target, actual, period_start, period_end, status, kind, criteria)
SELECT t.assigned_to, t.department_id, t.team_id, t.created_by, left(t.title, 160), t.description, '%', coalesce(t.criteria, 0), 0,
       date_trunc('year', now())::date, (date_trunc('year', now()) + interval '1 year - 1 day')::date, 'active', t.kind, t.criteria
FROM tasks t
WHERE t.kind IN ('kpi','okr') AND t.deleted_at IS NULL AND t.assigned_to IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM kpis k WHERE k.user_id = t.assigned_to AND k.title = left(t.title, 160) AND k.deleted_at IS NULL);

UPDATE kpi_submissions s SET kpi_id = k.id
FROM tasks t JOIN kpis k ON k.user_id = t.assigned_to AND k.title = left(t.title, 160) AND k.deleted_at IS NULL
WHERE s.task_id = t.id AND s.kpi_id IS NULL;

UPDATE tasks SET kind = 'task', criteria = NULL WHERE kind <> 'task';

UPDATE permissions SET label = 'KPIs & OKRs' WHERE key = 'kpis';
