-- Demo data for the Machine Checklist API (machine_db). The installer adds none.
-- psql -h 127.0.0.1 -U acme -d machine_db -f seed-machines.sql
-- Users from the installer's demo data: 3 = bu.manager, 4 = manager, 5 = member, 6 = somchai.
INSERT INTO machine_type (machine_group_id, machine_group_name, machine_type_id, machine_type_name)
VALUES ('CN', 'CNC', '01', 'Lathe') ON CONFLICT DO NOTHING;

INSERT INTO question (detail, description, is_choice) VALUES
  ('Oil level is between the marks', 'Look at the sight glass on the left side.', true),
  ('Emergency stop works', NULL, true),
  ('Spindle temperature (°C)', 'Read the panel after 10 minutes of running.', false),
  ('Belt tension', 'Monthly check.', true)
ON CONFLICT DO NOTHING;

INSERT INTO machine (machine_code, machine_name, machine_group_id, machine_type_id, brand, model, serial_number, machine_number,
                     department_id, responsible_person_id, responsible_person_name, supervisor_id, manager_id, maintenance_period, check_status)
VALUES ('OPS-CN01-0001', 'CNC Lathe 1', 'CN', '01', 'Okuma', 'LB3000', 'SN-1001', 'L-01', 2, 5, 'Tharushi Jayasinghe', 4, 3, 'MONTHLY', 'PENDING'),
       ('OPS-CN01-0002', 'CNC Lathe 2', 'CN', '01', 'Okuma', 'LB3000', 'SN-1002', 'L-02', 2, 6, 'Somchai Wongsa', 4, 3, 'MONTHLY', 'COMPLETED')
ON CONFLICT (machine_code) DO NOTHING;

-- general items ('0 0 0 * * 1' = every week) for both machines, plus one monthly item on machine 1
INSERT INTO machine_checklist (machine_code, question_id, reset_time, check_status)
SELECT m.code, q.id, '0 0 0 * * 1', false
FROM (VALUES ('OPS-CN01-0001'), ('OPS-CN01-0002')) m(code)
JOIN question q ON q.detail IN ('Oil level is between the marks', 'Emergency stop works', 'Spindle temperature (°C)')
ON CONFLICT DO NOTHING;
INSERT INTO machine_checklist (machine_code, question_id, reset_time, check_status)
SELECT 'OPS-CN01-0001', id, '0 0 0 1 * *', false FROM question WHERE detail = 'Belt tension'
ON CONFLICT DO NOTHING;

INSERT INTO maintenance_record (machine_code, machine_name, years, maintenance_type, due_date)
SELECT 'OPS-CN01-0001', 'CNC Lathe 1', to_char(CURRENT_DATE, 'YYYY'), 'PREVENTIVE', CURRENT_DATE + 7
WHERE NOT EXISTS (SELECT 1 FROM maintenance_record WHERE machine_code = 'OPS-CN01-0001');
INSERT INTO calibration_record (machine_code, years, due_date)
SELECT 'OPS-CN01-0001', to_char(CURRENT_DATE, 'YYYY'), CURRENT_DATE - 2
WHERE NOT EXISTS (SELECT 1 FROM calibration_record WHERE machine_code = 'OPS-CN01-0001');
