-- Machine Checklist module: what each role may open.
INSERT INTO permissions (module, key, label, sort)
SELECT 'machines', v.key, v.label, v.sort
FROM (VALUES
    ('machines_checklists',  'Machine checklists',   500),
    ('machines_machines',    'Machines',             510),
    ('machines_register',    'Machine register requests', 520),
    ('machines_maintenance', 'Machine maintenance',  530),
    ('machines_calibration', 'Machine calibration',  540),
    ('machines_types',       'Machine types',        550),
    ('machines_questions',   'Checklist questions',  560)
) AS v(key, label, sort)
WHERE EXISTS (SELECT 1 FROM roles)
ON CONFLICT (key) DO NOTHING;

INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete, can_import, can_export, can_download)
SELECT r.id, p.id, position('v' IN d.l) > 0, position('c' IN d.l) > 0, position('e' IN d.l) > 0, position('d' IN d.l) > 0, position('i' IN d.l) > 0, position('x' IN d.l) > 0, position('w' IN d.l) > 0
FROM (VALUES
    ('admin','machines_checklists','vcedxw'),   ('admin','machines_machines','vcedxw'),  ('admin','machines_register','vcedxw'), ('admin','machines_maintenance','vcedxw'),
    ('admin','machines_calibration','vcedxw'), ('admin','machines_types','vcedixw'),    ('admin','machines_questions','vcedixw'),
    ('management','machines_checklists','vxw'), ('management','machines_machines','vxw'), ('management','machines_register','vxw'), ('management','machines_maintenance','vxw'),
    ('management','machines_calibration','vxw'),
    ('bu_manager','machines_checklists','vcexw'), ('bu_manager','machines_machines','vcexw'), ('bu_manager','machines_register','vcexw'), ('bu_manager','machines_maintenance','vcexw'),
    ('bu_manager','machines_calibration','vcexw'), ('bu_manager','machines_types','vcedixw'), ('bu_manager','machines_questions','vcedixw'),
    ('manager','machines_checklists','vcexw'),  ('manager','machines_machines','vcexw'),  ('manager','machines_register','vcexw'),  ('manager','machines_maintenance','vcexw'),
    ('manager','machines_calibration','vcexw'), ('manager','machines_types','vcex'),     ('manager','machines_questions','vcex'),
    ('member','machines_checklists','vc'),      ('member','machines_machines','v'),      ('member','machines_register','vce'),    ('member','machines_maintenance','ve'),
    ('member','machines_calibration','ve')
) AS d(role, perm, l)
JOIN roles r ON r.slug = d.role
JOIN permissions p ON p.key = d.perm
ON CONFLICT (role_id, permission_id) DO NOTHING;
