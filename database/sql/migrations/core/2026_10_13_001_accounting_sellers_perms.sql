-- Sellers page (ERP data you can complete by hand) + administrators may remove e-tax rows.
INSERT INTO permissions (module, key, label, sort)
SELECT 'accounting', 'accounting_sellers', 'Accounting sellers', 350
WHERE EXISTS (SELECT 1 FROM roles)
ON CONFLICT (key) DO NOTHING;

INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete, can_import, can_export, can_download)
SELECT r.id, p.id, position('v' IN d.l) > 0, FALSE, position('e' IN d.l) > 0, FALSE, FALSE, position('x' IN d.l) > 0, FALSE
FROM (VALUES ('admin','vex'),('management','vx'),('bu_manager','v'),('manager',''),('member','')) AS d(role, l)
JOIN roles r ON r.slug = d.role
JOIN permissions p ON p.key = 'accounting_sellers'
ON CONFLICT (role_id, permission_id) DO NOTHING;

UPDATE role_permissions SET can_delete = TRUE
WHERE role_id IN (SELECT id FROM roles WHERE slug = 'admin') AND permission_id IN (SELECT id FROM permissions WHERE key = 'accounting_inet');
