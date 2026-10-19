-- Management module (overview of revenue, sellers and departments, and the KPI calculator): administrators and Management.
-- (On a fresh install this runs before the roles exist and skips; the seed adds it.)
INSERT INTO permissions (module, key, label, sort)
SELECT 'management', 'management', 'Management module', 400 WHERE EXISTS (SELECT 1 FROM roles) ON CONFLICT (key) DO NOTHING;
INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete, can_import, can_export, can_download)
SELECT r.id, p.id, TRUE, FALSE, FALSE, FALSE, FALSE, TRUE, TRUE FROM roles r JOIN permissions p ON p.key = 'management'
WHERE r.slug IN ('admin', 'management') ON CONFLICT (role_id, permission_id) DO NOTHING;
-- The KPI calculator now lives in that module: Management may calculate and save too.
UPDATE role_permissions rp SET can_view = TRUE, can_create = TRUE, can_edit = TRUE, can_export = TRUE
FROM roles r, permissions p WHERE rp.role_id = r.id AND rp.permission_id = p.id AND p.key = 'kpi_calculator' AND r.slug IN ('admin', 'management');
