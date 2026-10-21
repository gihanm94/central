-- Accounting → ERP data → Departments (departments copied from the ERP, read only).
INSERT INTO permissions (module, key, label, sort)
SELECT 'accounting', 'accounting_departments', 'Accounting departments', 345
WHERE EXISTS (SELECT 1 FROM roles)
ON CONFLICT (key) DO NOTHING;

INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete, can_import, can_export, can_download)
SELECT r.id, p.id, position('v' IN d.l) > 0, FALSE, FALSE, FALSE, FALSE, position('x' IN d.l) > 0, FALSE
FROM (VALUES ('admin','vx'),('management','vx'),('bu_manager','vx'),('manager',''),('member',''),('operation','')) AS d(role, l)
JOIN roles r ON r.slug = d.role
JOIN permissions p ON p.key = 'accounting_departments'
ON CONFLICT (role_id, permission_id) DO NOTHING;
