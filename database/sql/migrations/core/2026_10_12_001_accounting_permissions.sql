-- Accounting screens (ERP copies and the e-tax page). On a brand-new install user_db_seed.sql adds them instead.
INSERT INTO permissions (module, key, label, sort)
SELECT 'accounting', k, l, s FROM (VALUES
  ('accounting_customers', 'Accounting customers', 300),
  ('accounting_orders',    'Accounting orders',    310),
  ('accounting_invoices',  'Accounting invoices',  320),
  ('accounting_products',  'Accounting products',  330),
  ('accounting_inet',      'Accounting e-tax (INET)', 340)
) AS v(k, l, s)
WHERE EXISTS (SELECT 1 FROM roles)
ON CONFLICT (key) DO NOTHING;

-- Letters: v=view c=create e=edit d=delete i=import x=export w=download.   inet: c = generate, e = send / sync
INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete, can_import, can_export, can_download)
SELECT r.id, p.id,
       position('v' IN d.l) > 0, position('c' IN d.l) > 0, position('e' IN d.l) > 0, position('d' IN d.l) > 0,
       position('i' IN d.l) > 0, position('x' IN d.l) > 0, position('w' IN d.l) > 0
FROM (VALUES
  ('admin','accounting_customers','vx'),('admin','accounting_orders','vx'),('admin','accounting_invoices','vx'),('admin','accounting_products','vx'),('admin','accounting_inet','vcexw'),
  ('management','accounting_customers','vx'),('management','accounting_orders','vx'),('management','accounting_invoices','vx'),('management','accounting_products','vx'),('management','accounting_inet','vx'),
  ('bu_manager','accounting_customers','vx'),('bu_manager','accounting_orders','vx'),('bu_manager','accounting_invoices','vx'),('bu_manager','accounting_products','vx'),('bu_manager','accounting_inet',''),
  ('manager','accounting_customers','v'),('manager','accounting_orders','v'),('manager','accounting_invoices','v'),('manager','accounting_products','v'),('manager','accounting_inet',''),
  ('member','accounting_customers',''),('member','accounting_orders',''),('member','accounting_invoices',''),('member','accounting_products',''),('member','accounting_inet','')
) AS d(role, perm, l)
JOIN roles r ON r.slug = d.role
JOIN permissions p ON p.key = d.perm
ON CONFLICT (role_id, permission_id) DO NOTHING;
