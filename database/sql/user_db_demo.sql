-- =====================================================================
--  user_db demo data (optional). Every demo password: Password@123
-- =====================================================================

INSERT INTO departments (id, name, code, description) VALUES
  (2, 'Operations', 'OPS', 'Plant, machines and maintenance'),
  (3, 'Finance', 'FIN', 'Accounting and reporting'),
  (4, 'Sales', 'SAL', 'Customers and growth')
ON CONFLICT (code) DO NOTHING;

INSERT INTO teams (id, department_id, name) VALUES
  (1, 2, 'Maintenance'), (2, 2, 'Quality control'), (3, 4, 'Field sales')
ON CONFLICT DO NOTHING;

INSERT INTO users (id, name, email, password, role_id, department_id, team_id, manager_id) VALUES
  (2, 'Nadia Perera', 'management@acmeinter.com', '$2y$10$ESyiZzt7wb25pHcOhENXC.XgbPANlR/G/GQZdiw3l2ny.LIpt7jA6', 2, NULL, NULL, NULL),
  (3, 'Ruwan Silva', 'bu.manager@acmeinter.com', '$2y$10$ESyiZzt7wb25pHcOhENXC.XgbPANlR/G/GQZdiw3l2ny.LIpt7jA6', 3, 2, NULL, NULL),
  (4, 'Kasun Fernando', 'manager@acmeinter.com', '$2y$10$ESyiZzt7wb25pHcOhENXC.XgbPANlR/G/GQZdiw3l2ny.LIpt7jA6', 4, 2, 1, 3),
  (5, 'Tharushi Jayasinghe', 'member@acmeinter.com', '$2y$10$ESyiZzt7wb25pHcOhENXC.XgbPANlR/G/GQZdiw3l2ny.LIpt7jA6', 5, 2, 1, 4),
  (6, 'Somchai Wongsa', 'somchai@acmeinter.com', '$2y$10$ESyiZzt7wb25pHcOhENXC.XgbPANlR/G/GQZdiw3l2ny.LIpt7jA6', 5, 2, 1, 4),
  (7, 'Dilan Herath', 'dilan@acmeinter.com', '$2y$10$ESyiZzt7wb25pHcOhENXC.XgbPANlR/G/GQZdiw3l2ny.LIpt7jA6', 5, 2, 2, 3),
  (8, 'Malee Chaiyaporn', 'malee@acmeinter.com', '$2y$10$ESyiZzt7wb25pHcOhENXC.XgbPANlR/G/GQZdiw3l2ny.LIpt7jA6', 5, 3, NULL, NULL),
  (9, 'Ishara Bandara', 'ishara@acmeinter.com', '$2y$10$ESyiZzt7wb25pHcOhENXC.XgbPANlR/G/GQZdiw3l2ny.LIpt7jA6', 5, 4, 3, NULL)
ON CONFLICT DO NOTHING;

INSERT INTO employee_profiles (user_id, employee_code, job_title, joined_at, phone) VALUES
  (2, 'EMP-0002', 'Chief operating officer', CURRENT_DATE - INTERVAL '17 months', '+66 835760070'),
  (3, 'EMP-0003', 'Head of operations', CURRENT_DATE - INTERVAL '24 months', '+66 815105881'),
  (4, 'EMP-0004', 'Maintenance lead', CURRENT_DATE - INTERVAL '31 months', '+66 839409052'),
  (5, 'EMP-0005', 'Maintenance technician', CURRENT_DATE - INTERVAL '38 months', '+66 890249252'),
  (6, 'EMP-0006', 'Machine operator', CURRENT_DATE - INTERVAL '5 months', '+66 846725275'),
  (7, 'EMP-0007', 'QC inspector', CURRENT_DATE - INTERVAL '12 months', '+66 849329831'),
  (8, 'EMP-0008', 'Accountant', CURRENT_DATE - INTERVAL '19 months', '+66 866255246'),
  (9, 'EMP-0009', 'Sales executive', CURRENT_DATE - INTERVAL '26 months', '+66 896888563')
ON CONFLICT DO NOTHING;

UPDATE departments SET head_id = 3 WHERE id = 2;
UPDATE teams SET lead_id = 4 WHERE id = 1;

INSERT INTO tasks (assigned_to, created_by, department_id, team_id, title, status, priority, due_date, completed_at) VALUES
  (5, 4, 2, 1, 'Weekly lubrication round, line 2', 'todo', 'high', CURRENT_DATE + -3, NULL),
  (5, 4, 2, 1, 'Replace conveyor belt on packer B', 'in_progress', 'urgent', CURRENT_DATE + -1, NULL),
  (5, 4, 2, 1, 'Update spare parts register', 'review', 'medium', CURRENT_DATE + 1, NULL),
  (6, 4, 2, 1, 'Replace conveyor belt on packer B', 'in_progress', 'urgent', CURRENT_DATE + -1, NULL),
  (6, 4, 2, 1, 'Update spare parts register', 'review', 'medium', CURRENT_DATE + 1, NULL),
  (6, 4, 2, 1, 'Calibrate torque wrenches', 'done', 'medium', CURRENT_DATE + 3, now()),
  (7, 3, 2, 2, 'Update spare parts register', 'review', 'medium', CURRENT_DATE + 1, NULL),
  (7, 3, 2, 2, 'Calibrate torque wrenches', 'done', 'medium', CURRENT_DATE + 3, now()),
  (7, 3, 2, 2, 'Inspect safety guards, press area', 'todo', 'high', CURRENT_DATE + 5, NULL),
  (8, 3, 3, NULL, 'Weekly lubrication round, line 2', 'done', 'high', CURRENT_DATE + 3, now()),
  (8, 3, 3, NULL, 'Replace conveyor belt on packer B', 'todo', 'urgent', CURRENT_DATE + 5, NULL),
  (8, 3, 3, NULL, 'Update spare parts register', 'in_progress', 'medium', CURRENT_DATE + 7, NULL),
  (9, 3, 4, 3, 'Replace conveyor belt on packer B', 'todo', 'urgent', CURRENT_DATE + 5, NULL),
  (9, 3, 4, 3, 'Update spare parts register', 'in_progress', 'medium', CURRENT_DATE + 7, NULL),
  (9, 3, 4, 3, 'Calibrate torque wrenches', 'review', 'medium', CURRENT_DATE + 9, NULL);

INSERT INTO kpis (user_id, department_id, team_id, created_by, title, unit, target, actual, period_start, period_end) VALUES
  (5, 2, 1, 4, 'On-time task completion', '%', 95, 82, date_trunc('quarter', CURRENT_DATE)::date, (date_trunc('quarter', CURRENT_DATE) + INTERVAL '3 months - 1 day')::date),
  (5, 2, 1, 4, 'Training hours', 'hrs', 12, 8, date_trunc('quarter', CURRENT_DATE)::date, (date_trunc('quarter', CURRENT_DATE) + INTERVAL '3 months - 1 day')::date),
  (6, 2, 1, 4, 'On-time task completion', '%', 95, 91, date_trunc('quarter', CURRENT_DATE)::date, (date_trunc('quarter', CURRENT_DATE) + INTERVAL '3 months - 1 day')::date),
  (6, 2, 1, 4, 'Training hours', 'hrs', 12, 12, date_trunc('quarter', CURRENT_DATE)::date, (date_trunc('quarter', CURRENT_DATE) + INTERVAL '3 months - 1 day')::date),
  (7, 2, 2, 3, 'On-time task completion', '%', 95, 81, date_trunc('quarter', CURRENT_DATE)::date, (date_trunc('quarter', CURRENT_DATE) + INTERVAL '3 months - 1 day')::date),
  (7, 2, 2, 3, 'Training hours', 'hrs', 12, 8, date_trunc('quarter', CURRENT_DATE)::date, (date_trunc('quarter', CURRENT_DATE) + INTERVAL '3 months - 1 day')::date),
  (8, 3, NULL, 3, 'On-time task completion', '%', 95, 67, date_trunc('quarter', CURRENT_DATE)::date, (date_trunc('quarter', CURRENT_DATE) + INTERVAL '3 months - 1 day')::date),
  (8, 3, NULL, 3, 'Training hours', 'hrs', 12, 6, date_trunc('quarter', CURRENT_DATE)::date, (date_trunc('quarter', CURRENT_DATE) + INTERVAL '3 months - 1 day')::date),
  (9, 4, 3, 3, 'On-time task completion', '%', 95, 62, date_trunc('quarter', CURRENT_DATE)::date, (date_trunc('quarter', CURRENT_DATE) + INTERVAL '3 months - 1 day')::date),
  (9, 4, 3, 3, 'Training hours', 'hrs', 12, 11, date_trunc('quarter', CURRENT_DATE)::date, (date_trunc('quarter', CURRENT_DATE) + INTERVAL '3 months - 1 day')::date);

SELECT setval(pg_get_serial_sequence('departments','id'), (SELECT MAX(id) FROM departments));
SELECT setval(pg_get_serial_sequence('teams','id'), (SELECT MAX(id) FROM teams));
SELECT setval(pg_get_serial_sequence('users','id'), (SELECT MAX(id) FROM users));
