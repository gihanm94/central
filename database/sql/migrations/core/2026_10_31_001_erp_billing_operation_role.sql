-- ERP → Billing notes (User Management) and the Operation role.
-- The ERP group is for administrators (every department) and the Operation role (own department) only.
-- (On a fresh install this runs before the roles exist and skips; the seed does the same after its fixed ids.)
INSERT INTO permissions (module, key, label, sort)
SELECT 'core', 'erp_billing', 'ERP billing notes (own department)', 96
WHERE EXISTS (SELECT 1 FROM roles)
ON CONFLICT (key) DO NOTHING;

INSERT INTO roles (name, slug, level, data_scope, description, is_system)
SELECT 'Operation', 'operation', 20, 'own', 'Operations: own work, plus ERP invoices & billing notes of own department', TRUE
WHERE EXISTS (SELECT 1 FROM roles)
ON CONFLICT (slug) DO NOTHING;

-- Operation starts with what Member has …
INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete, can_import, can_export, can_download)
SELECT o.id, rp.permission_id, rp.can_view, rp.can_create, rp.can_edit, rp.can_delete, rp.can_import, rp.can_export, rp.can_download
FROM role_permissions rp JOIN roles m ON m.id = rp.role_id AND m.slug = 'member' CROSS JOIN roles o
WHERE o.slug = 'operation' AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = o.id)
ON CONFLICT (role_id, permission_id) DO NOTHING;

-- … plus the ERP pages (view and download), as does Admin.
INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete, can_import, can_export, can_download)
SELECT r.id, p.id, TRUE, FALSE, FALSE, FALSE, FALSE, FALSE, TRUE
FROM roles r JOIN permissions p ON p.key IN ('erp_invoices', 'erp_billing')
WHERE r.slug IN ('admin', 'operation')
ON CONFLICT (role_id, permission_id) DO UPDATE SET can_view = TRUE, can_download = TRUE;

-- Nobody else sees the ERP group (personal overrides go back to the role's rule).
UPDATE role_permissions rp SET can_view = FALSE, can_download = FALSE FROM roles r, permissions p
WHERE rp.role_id = r.id AND rp.permission_id = p.id AND p.key IN ('erp_invoices', 'erp_billing') AND r.slug NOT IN ('admin', 'operation');
UPDATE user_permissions up SET can_view = NULL, can_download = NULL FROM permissions p
WHERE up.permission_id = p.id AND p.key IN ('erp_invoices', 'erp_billing');
