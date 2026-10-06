-- CRM areas in the permission rule book + sensible defaults per built-in role.
-- On a brand-new install roles do not exist yet (user_db_seed.sql adds these rows instead), so this does nothing then.
INSERT INTO permissions (module, key, label, sort)
SELECT 'crm', k, l, s FROM (VALUES
  ('crm_dashboard',     'CRM dashboard',        100),
  ('crm_leads',         'CRM leads',            110),
  ('crm_contacts',      'CRM contacts',         120),
  ('crm_opportunities', 'CRM opportunities',    130),
  ('crm_campaigns',     'CRM campaigns',        140),
  ('crm_activities',    'CRM activities',       150),
  ('crm_settings',      'CRM settings',         160)
) AS v(k, l, s)
WHERE EXISTS (SELECT 1 FROM roles)
ON CONFLICT (key) DO NOTHING;

-- Letters: v=view c=create e=edit d=delete i=import x=export w=download
INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete, can_import, can_export, can_download)
SELECT r.id, p.id,
       position('v' IN d.l) > 0, position('c' IN d.l) > 0, position('e' IN d.l) > 0, position('d' IN d.l) > 0,
       position('i' IN d.l) > 0, position('x' IN d.l) > 0, position('w' IN d.l) > 0
FROM (VALUES
  ('admin','crm_dashboard','vcedixw'),('admin','crm_leads','vcedixw'),('admin','crm_contacts','vcedixw'),('admin','crm_opportunities','vcedixw'),('admin','crm_campaigns','vcedixw'),('admin','crm_activities','vcedixw'),('admin','crm_settings','vcedixw'),
  ('management','crm_dashboard','v'),('management','crm_leads','vxw'),('management','crm_contacts','vxw'),('management','crm_opportunities','vxw'),('management','crm_campaigns','vxw'),('management','crm_activities','vxw'),('management','crm_settings','v'),
  ('bu_manager','crm_dashboard','v'),('bu_manager','crm_leads','vcedixw'),('bu_manager','crm_contacts','vcedixw'),('bu_manager','crm_opportunities','vcedixw'),('bu_manager','crm_campaigns','vcedixw'),('bu_manager','crm_activities','vcedixw'),('bu_manager','crm_settings','vcedx'),
  ('manager','crm_dashboard','v'),('manager','crm_leads','vcexw'),('manager','crm_contacts','vcexw'),('manager','crm_opportunities','vcexw'),('manager','crm_campaigns','vcex'),('manager','crm_activities','vcedxw'),('manager','crm_settings','v'),
  ('member','crm_dashboard','v'),('member','crm_leads','vce'),('member','crm_contacts','vce'),('member','crm_opportunities','vce'),('member','crm_campaigns','v'),('member','crm_activities','vce'),('member','crm_settings','')
) AS d(role, perm, l)
JOIN roles r ON r.slug = d.role
JOIN permissions p ON p.key = d.perm
ON CONFLICT (role_id, permission_id) DO NOTHING;
