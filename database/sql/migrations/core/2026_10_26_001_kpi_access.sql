-- KPIs & OKRs and KPI submissions: only the BU manager of the department (and administrators) add, change and delete.
-- Who sees what is decided in code: all (Admin, Management), department + own (BU manager), own (everyone else).
UPDATE role_permissions rp SET can_create = FALSE, can_edit = FALSE, can_delete = FALSE, can_import = FALSE
FROM roles r, permissions p
WHERE rp.role_id = r.id AND rp.permission_id = p.id AND p.key = 'kpis' AND r.slug IN ('manager', 'member', 'management');

UPDATE role_permissions rp SET can_view = TRUE, can_create = TRUE, can_edit = TRUE, can_delete = TRUE
FROM roles r, permissions p
WHERE rp.role_id = r.id AND rp.permission_id = p.id AND p.key = 'kpis' AND r.slug IN ('admin', 'bu_manager');

UPDATE role_permissions rp SET can_edit = TRUE, can_delete = TRUE
FROM roles r, permissions p
WHERE rp.role_id = r.id AND rp.permission_id = p.id AND p.key = 'kpi_submissions' AND r.slug IN ('admin', 'bu_manager');
