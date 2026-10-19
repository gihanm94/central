-- ERP → Invoices (User Management): stamped Inet invoices of the person's own department, view and download only.
-- Given to no role here; tick it in Roles & access for the roles that need it. Administrators always have it.
-- (On a fresh install this runs before the roles exist and skips; the seed adds it after its fixed ids.)
INSERT INTO permissions (module, key, label, sort)
SELECT 'core', 'erp_invoices', 'ERP invoices (own department)', 95
WHERE EXISTS (SELECT 1 FROM roles)
ON CONFLICT (key) DO NOTHING;
