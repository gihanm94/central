-- Importing data is for administrators only (checked in code too); clear the import box of every other role.
UPDATE role_permissions rp SET can_import = FALSE FROM roles r WHERE rp.role_id = r.id AND r.slug <> 'admin' AND rp.can_import;
UPDATE user_permissions SET can_import = FALSE WHERE can_import IS TRUE;
