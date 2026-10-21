-- Accounting → ERP data → Vouchers (read only, with rows, accounts payable and comment).
INSERT INTO permissions (module, key, label, sort)
SELECT 'accounting', 'accounting_vouchers', 'Accounting vouchers', 346
WHERE EXISTS (SELECT 1 FROM roles)
ON CONFLICT (key) DO NOTHING;

INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete, can_import, can_export, can_download)
SELECT r.id, p.id, position('v' IN d.l) > 0, FALSE, FALSE, FALSE, FALSE, position('x' IN d.l) > 0, position('w' IN d.l) > 0
FROM (VALUES ('admin','vxw'),('management','vxw'),('bu_manager',''),('manager',''),('member',''),('operation','')) AS d(role, l)
JOIN roles r ON r.slug = d.role
JOIN permissions p ON p.key = 'accounting_vouchers'
ON CONFLICT (role_id, permission_id) DO NOTHING;
