-- Billing notes screen.
INSERT INTO permissions (module, key, label, sort)
SELECT 'accounting', 'accounting_billing', 'Accounting billing notes', 360
WHERE EXISTS (SELECT 1 FROM roles)
ON CONFLICT (key) DO NOTHING;

INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete, can_import, can_export, can_download)
SELECT r.id, p.id, position('v' IN d.l) > 0, position('c' IN d.l) > 0, position('e' IN d.l) > 0, position('d' IN d.l) > 0, FALSE, position('x' IN d.l) > 0, position('w' IN d.l) > 0
FROM (VALUES ('admin','vcedxw'),('management','vxw'),('bu_manager','vcexw'),('manager',''),('member','')) AS d(role, l)
JOIN roles r ON r.slug = d.role
JOIN permissions p ON p.key = 'accounting_billing'
ON CONFLICT (role_id, permission_id) DO NOTHING;
