-- Accounting → Management → Account report. BU managers view, add and import lines; management views.
INSERT INTO permissions (module, key, label, sort)
SELECT 'accounting', 'accounting_reports', 'Accounting account report', 370
WHERE EXISTS (SELECT 1 FROM roles)
ON CONFLICT (key) DO NOTHING;

INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete, can_import, can_export, can_download)
SELECT r.id, p.id, position('v' IN d.l) > 0, position('c' IN d.l) > 0, position('e' IN d.l) > 0, position('d' IN d.l) > 0, position('i' IN d.l) > 0, position('x' IN d.l) > 0, position('w' IN d.l) > 0
FROM (VALUES ('admin','vcedixw'),('management','vxw'),('bu_manager','vceixw'),('manager',''),('member',''),('operation','')) AS d(role, l)
JOIN roles r ON r.slug = d.role
JOIN permissions p ON p.key = 'accounting_reports'
ON CONFLICT (role_id, permission_id) DO NOTHING;
