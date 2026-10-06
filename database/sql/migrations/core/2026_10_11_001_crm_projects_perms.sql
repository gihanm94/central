-- Permission rows for CRM projects, tasks, statistics cards and the team / reassign screen.
-- (On a brand-new install user_db_seed.sql adds them instead.)
INSERT INTO permissions (module, key, label, sort)
SELECT 'crm', k, l, s FROM (VALUES
  ('crm_projects', 'CRM projects',                    190),
  ('crm_tasks',    'CRM project tasks',               200),
  ('crm_stats',    'CRM statistics cards',            210),
  ('crm_reassign', 'CRM team: reassign and bulk change', 220)
) AS v(k, l, s)
WHERE EXISTS (SELECT 1 FROM roles)
ON CONFLICT (key) DO NOTHING;

-- Letters: v=view c=create e=edit d=delete i=import x=export w=download
-- crm_stats: view = sees the cards.  crm_reassign: view = opens the Team screen, edit = may hand records to someone else / change many at once.
INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete, can_import, can_export, can_download)
SELECT r.id, p.id,
       position('v' IN d.l) > 0, position('c' IN d.l) > 0, position('e' IN d.l) > 0, position('d' IN d.l) > 0,
       position('i' IN d.l) > 0, position('x' IN d.l) > 0, position('w' IN d.l) > 0
FROM (VALUES
  ('admin','crm_projects','vcedixw'),('management','crm_projects','vxw'),('bu_manager','crm_projects','vcedixw'),('manager','crm_projects','vcexw'),('member','crm_projects','vce'),
  ('admin','crm_tasks','vcedixw'),('management','crm_tasks','vxw'),('bu_manager','crm_tasks','vcedixw'),('manager','crm_tasks','vcedxw'),('member','crm_tasks','vce'),
  ('admin','crm_stats','v'),('management','crm_stats',''),('bu_manager','crm_stats','v'),('manager','crm_stats',''),('member','crm_stats',''),
  ('admin','crm_reassign','ve'),('management','crm_reassign',''),('bu_manager','crm_reassign','ve'),('manager','crm_reassign',''),('member','crm_reassign','')
) AS d(role, perm, l)
JOIN roles r ON r.slug = d.role
JOIN permissions p ON p.key = d.perm
ON CONFLICT (role_id, permission_id) DO NOTHING;
