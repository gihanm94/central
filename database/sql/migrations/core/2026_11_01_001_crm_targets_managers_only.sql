-- CRM → Manager → Sales targets (moved out of CRM settings) is for Admin and BU Manager only.
UPDATE role_permissions rp SET can_view = FALSE, can_create = FALSE, can_edit = FALSE, can_delete = FALSE, can_import = FALSE, can_export = FALSE, can_download = FALSE
FROM roles r, permissions p
WHERE rp.role_id = r.id AND rp.permission_id = p.id AND p.key = 'crm_targets' AND r.slug NOT IN ('admin', 'bu_manager');
UPDATE user_permissions up SET can_view = NULL, can_create = NULL, can_edit = NULL, can_delete = NULL, can_import = NULL, can_export = NULL, can_download = NULL
FROM permissions p WHERE up.permission_id = p.id AND p.key = 'crm_targets';
