-- =====================================================================
--  user_db seed: built-in roles, permission rule book, settings, admin.
--  Letters: v=view c=create e=edit d=delete i=import x=export w=download
--  Admin password here is Admin@12345 — install.php replaces it with yours.
-- =====================================================================

INSERT INTO roles (id, name, slug, level, data_scope, description, is_system) VALUES
  (1, 'Admin', 'admin', 100, 'all', 'Full control of the system', TRUE),
  (2, 'Management', 'management', 80, 'all', 'Sees the whole company, cannot change data', TRUE),
  (3, 'BU Manager', 'bu_manager', 60, 'department', 'Runs one department', TRUE),
  (4, 'Manager', 'manager', 40, 'team', 'Runs one team', TRUE),
  (5, 'Member', 'member', 10, 'own', 'Employee: own work only', TRUE)
ON CONFLICT (slug) DO NOTHING;

INSERT INTO permissions (id, module, key, label, sort) VALUES
  (1, 'core', 'dashboard', 'Dashboard', 10),
  (2, 'core', 'members', 'Members', 20),
  (3, 'core', 'departments', 'Departments', 30),
  (4, 'core', 'teams', 'Teams', 40),
  (5, 'core', 'roles', 'Roles & permissions', 50),
  (6, 'core', 'kpis', 'KPIs', 60),
  (7, 'core', 'tasks', 'Tasks', 70),
  (8, 'core', 'activity_logs', 'Activity log', 80),
  (9, 'core', 'settings', 'Company settings', 90)
ON CONFLICT (key) DO NOTHING;

INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete, can_import, can_export, can_download) VALUES
  (1, 1, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE),
  (1, 2, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE),
  (1, 3, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE),
  (1, 4, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE),
  (1, 5, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE),
  (1, 6, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE),
  (1, 7, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE),
  (1, 8, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE),
  (1, 9, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE),
  (2, 1, TRUE, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE),
  (2, 2, TRUE, FALSE, FALSE, FALSE, FALSE, TRUE, TRUE),
  (2, 3, TRUE, FALSE, FALSE, FALSE, FALSE, TRUE, TRUE),
  (2, 4, TRUE, FALSE, FALSE, FALSE, FALSE, TRUE, TRUE),
  (2, 5, TRUE, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE),
  (2, 6, TRUE, FALSE, FALSE, FALSE, FALSE, TRUE, TRUE),
  (2, 7, TRUE, FALSE, FALSE, FALSE, FALSE, TRUE, TRUE),
  (2, 8, TRUE, FALSE, FALSE, FALSE, FALSE, TRUE, TRUE),
  (2, 9, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE),
  (3, 1, TRUE, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE),
  (3, 2, TRUE, TRUE, TRUE, FALSE, FALSE, TRUE, TRUE),
  (3, 3, TRUE, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE),
  (3, 4, TRUE, TRUE, TRUE, FALSE, FALSE, TRUE, TRUE),
  (3, 5, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE),
  (3, 6, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE),
  (3, 7, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE),
  (3, 8, TRUE, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE),
  (3, 9, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE),
  (4, 1, TRUE, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE),
  (4, 2, TRUE, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE),
  (4, 3, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE),
  (4, 4, TRUE, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE),
  (4, 5, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE),
  (4, 6, TRUE, TRUE, TRUE, FALSE, FALSE, TRUE, TRUE),
  (4, 7, TRUE, TRUE, TRUE, TRUE, FALSE, TRUE, TRUE),
  (4, 8, TRUE, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE),
  (4, 9, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE),
  (5, 1, TRUE, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE),
  (5, 2, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE),
  (5, 3, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE),
  (5, 4, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE),
  (5, 5, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE),
  (5, 6, TRUE, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE),
  (5, 7, TRUE, FALSE, TRUE, FALSE, FALSE, FALSE, FALSE),
  (5, 8, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE),
  (5, 9, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE)
ON CONFLICT (role_id, permission_id) DO NOTHING;

INSERT INTO settings (key, value) VALUES
  ('company_name', 'Acme Inter'),
  ('login_tagline', 'Every department, one sign-in.'),
  ('support_email', NULL),
  ('company_logo', NULL),
  ('login_image', NULL)
ON CONFLICT (key) DO NOTHING;

INSERT INTO departments (id, name, code, description) VALUES
  (1, 'Administration', 'ADM', 'System administration')
ON CONFLICT (code) DO NOTHING;

INSERT INTO users (id, name, email, password, role_id, department_id) VALUES
  (1, 'System Admin', 'admin@acmeinter.com', '$2y$10$0MS1f5NiQaT7Ys5WP51k1uyl4GMRwD5Uyr2q2wpodFYV8hcnJSlSq', 1, 1)
ON CONFLICT DO NOTHING;
INSERT INTO employee_profiles (user_id, employee_code, job_title, joined_at) VALUES (1, 'EMP-0001', 'System administrator', CURRENT_DATE)
ON CONFLICT DO NOTHING;

-- CRM module (same rows the 2026_10_07_001_crm_permissions migration adds to existing installs)
INSERT INTO permissions (id, module, key, label, sort) VALUES
  (10, 'crm', 'crm_dashboard', 'CRM dashboard', 100),
  (11, 'crm', 'crm_leads', 'CRM leads', 110),
  (12, 'crm', 'crm_contacts', 'CRM contacts', 120),
  (13, 'crm', 'crm_opportunities', 'CRM opportunities', 130),
  (14, 'crm', 'crm_campaigns', 'CRM campaigns', 140),
  (15, 'crm', 'crm_activities', 'CRM activities', 150),
  (16, 'crm', 'crm_settings', 'CRM settings', 160),
  (17, 'crm', 'crm_stages', 'CRM opportunity stages', 170),
  (18, 'crm', 'crm_targets', 'CRM sales targets', 180),
  (19, 'crm', 'crm_projects', 'CRM projects', 190),
  (20, 'crm', 'crm_tasks', 'CRM project tasks', 200),
  (21, 'crm', 'crm_stats', 'CRM statistics cards', 210),
  (22, 'crm', 'crm_reassign', 'CRM team: reassign and bulk change', 220),
  (23, 'accounting', 'accounting_customers', 'Accounting customers', 300),
  (24, 'accounting', 'accounting_orders', 'Accounting orders', 310),
  (25, 'accounting', 'accounting_invoices', 'Accounting invoices', 320),
  (26, 'accounting', 'accounting_products', 'Accounting products', 330),
  (27, 'accounting', 'accounting_inet', 'Accounting e-tax (INET)', 340),
  (28, 'accounting', 'accounting_sellers', 'Accounting sellers', 350),
  (29, 'accounting', 'accounting_billing', 'Accounting billing notes', 360)
ON CONFLICT (key) DO NOTHING;

-- Letters: v=view c=create e=edit d=delete i=import x=export w=download
INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete, can_import, can_export, can_download)
SELECT r.id, p.id,
       position('v' IN d.l) > 0, position('c' IN d.l) > 0, position('e' IN d.l) > 0, position('d' IN d.l) > 0,
       position('i' IN d.l) > 0, position('x' IN d.l) > 0, position('w' IN d.l) > 0
FROM (VALUES
  ('admin','crm_dashboard','vcedixw'),('admin','crm_leads','vcedixw'),('admin','crm_contacts','vcedixw'),('admin','crm_opportunities','vcedixw'),('admin','crm_campaigns','vcedixw'),('admin','crm_activities','vcedixw'),('admin','crm_settings','vcedixw'),
  ('management','crm_dashboard','v'),('management','crm_leads','vxw'),('management','crm_contacts','vxw'),('management','crm_opportunities','vxw'),('management','crm_campaigns','vxw'),('management','crm_activities','vxw'),('management','crm_settings',''),
  ('bu_manager','crm_dashboard','v'),('bu_manager','crm_leads','vcedixw'),('bu_manager','crm_contacts','vcedixw'),('bu_manager','crm_opportunities','vcedixw'),('bu_manager','crm_campaigns','vcedixw'),('bu_manager','crm_activities','vcedixw'),('bu_manager','crm_settings','vcedx'),
  ('manager','crm_dashboard','v'),('manager','crm_leads','vcexw'),('manager','crm_contacts','vcexw'),('manager','crm_opportunities','vcexw'),('manager','crm_campaigns','vcex'),('manager','crm_activities','vcedxw'),('manager','crm_settings','vcedx'),
  ('member','crm_dashboard','v'),('member','crm_leads','vce'),('member','crm_contacts','vce'),('member','crm_opportunities','vce'),('member','crm_campaigns','v'),('member','crm_activities','vce'),('member','crm_settings',''),
  ('admin','crm_stages','vcedixw'),('admin','crm_targets','vcedixw'),('management','crm_stages',''),('management','crm_targets','v'),('bu_manager','crm_stages','ve'),('bu_manager','crm_targets','vce'),('manager','crm_stages',''),('manager','crm_targets','v'),('member','crm_stages',''),('member','crm_targets',''),
  ('admin','crm_projects','vcedixw'),('management','crm_projects','vxw'),('bu_manager','crm_projects','vcedixw'),('manager','crm_projects','vcexw'),('member','crm_projects','vce'),
  ('admin','crm_tasks','vcedixw'),('management','crm_tasks','vxw'),('bu_manager','crm_tasks','vcedixw'),('manager','crm_tasks','vcedxw'),('member','crm_tasks','vce'),
  ('admin','crm_stats','v'),('management','crm_stats',''),('bu_manager','crm_stats','v'),('manager','crm_stats',''),('member','crm_stats',''),
  ('admin','crm_reassign','ve'),('management','crm_reassign',''),('bu_manager','crm_reassign','ve'),('manager','crm_reassign',''),('member','crm_reassign',''),
  ('admin','accounting_customers','vx'),('admin','accounting_orders','vx'),('admin','accounting_invoices','vx'),('admin','accounting_products','vx'),('admin','accounting_inet','vcedxw'),('admin','accounting_sellers','vex'),('admin','accounting_billing','vcedxw'),
  ('management','accounting_customers','vx'),('management','accounting_orders','vx'),('management','accounting_invoices','vx'),('management','accounting_products','vx'),('management','accounting_inet','vx'),('management','accounting_sellers','vx'),('management','accounting_billing','vxw'),
  ('bu_manager','accounting_customers','vx'),('bu_manager','accounting_orders','vx'),('bu_manager','accounting_invoices','vx'),('bu_manager','accounting_products','vx'),('bu_manager','accounting_inet',''),('bu_manager','accounting_sellers','v'),('bu_manager','accounting_billing','vcexw'),
  ('manager','accounting_customers','v'),('manager','accounting_orders','v'),('manager','accounting_invoices','v'),('manager','accounting_products','v'),('manager','accounting_inet',''),('manager','accounting_sellers',''),('manager','accounting_billing',''),
  ('member','accounting_customers',''),('member','accounting_orders',''),('member','accounting_invoices',''),('member','accounting_products',''),('member','accounting_inet',''),('member','accounting_sellers',''),('member','accounting_billing','')
) AS d(role, perm, l)
JOIN roles r ON r.slug = d.role
JOIN permissions p ON p.key = d.perm
ON CONFLICT (role_id, permission_id) DO NOTHING;

-- keep identity counters ahead of the fixed ids above
SELECT setval(pg_get_serial_sequence('roles','id'), GREATEST((SELECT MAX(id) FROM roles), 1));
SELECT setval(pg_get_serial_sequence('permissions','id'), GREATEST((SELECT MAX(id) FROM permissions), 1));
SELECT setval(pg_get_serial_sequence('departments','id'), GREATEST((SELECT MAX(id) FROM departments), 1));
SELECT setval(pg_get_serial_sequence('users','id'), GREATEST((SELECT MAX(id) FROM users), 1));
