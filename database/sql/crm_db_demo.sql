-- =====================================================================
--  crm_db demo data (optional, only when the CRM tables are still empty).
--  Uses the demo people of user_db_demo.sql: 2 Management, 3 BU Manager (Operations),
--  4 Manager, 5-7 Operations members, 8 Finance, 9 Sales.  Department ids: 2 Operations, 3 Finance, 4 Sales.
-- =====================================================================
DO $demo$
BEGIN
IF EXISTS (SELECT 1 FROM leads) THEN RETURN; END IF;

INSERT INTO leads (id, code, name_en, name_th, tax_id, revenue, phone, mobile, address, city, province, country, zipcode, description, is_register, is_government, company_id, owner_id, department_id, created_by, created_at) VALUES
 (1, 'LD-00001', 'Siam Packaging Co., Ltd.', 'บริษัท สยามแพคเกจจิ้ง จำกัด', '0105551234567', 480000000, '02-555-0101', '081-555-0101', '88 Bangna-Trad Rd.', 'Bangkok', 'Bangkok', 'Thailand', '10260', 'Flexible packaging, three plants. Buying a new filling line in Q4.', TRUE, FALSE, NULL, 9, 4, 9, now() - interval '70 days'),
 (2, 'LD-00002', 'Chao Phraya Foods Public Co., Ltd.', 'บริษัท เจ้าพระยาฟู้ดส์ จำกัด (มหาชน)', '0107559876543', 2100000000, '02-555-0202', NULL, '12 Rama II Rd.', 'Samut Sakhon', 'Samut Sakhon', 'Thailand', '74000', 'Frozen seafood exporter.', TRUE, FALSE, NULL, 9, 4, 9, now() - interval '55 days'),
 (3, 'LD-00003', 'Eastern Seaboard Auto Parts', 'อีสเทิร์นซีบอร์ด ออโต้พาร์ท', '0205560011223', 760000000, '038-555-0303', '089-555-0303', 'Amata City Industrial Estate', 'Chonburi', 'Chonburi', 'Thailand', '20160', 'Tier-2 supplier, press and weld lines.', TRUE, FALSE, NULL, 9, 4, 9, now() - interval '40 days'),
 (4, 'LD-00004', 'Provincial Electricity Cooperative', 'สหกรณ์ไฟฟ้าจังหวัด', NULL, 90000000, '044-555-0404', NULL, '1 Mittraphap Rd.', 'Khon Kaen', 'Khon Kaen', 'Thailand', '40000', 'Government-linked; tender based.', FALSE, TRUE, NULL, 9, 4, 9, now() - interval '25 days'),
 (5, 'LD-00005', 'Lanna Ceramics', 'ล้านนาเซรามิก', '0505561122334', 120000000, '053-555-0505', '086-555-0505', '9 Chiang Mai-Lampang Rd.', 'Chiang Mai', 'Chiang Mai', 'Thailand', '50000', 'Kilns and glazing; maintenance contract prospect.', TRUE, FALSE, NULL, 5, 2, 5, now() - interval '33 days'),
 (6, 'LD-00006', 'Rayong Petrochemical Services', 'ระยองปิโตรเคมีเซอร์วิส', '0215562233445', 1500000000, '038-555-0606', NULL, 'Map Ta Phut Industrial Estate', 'Rayong', 'Rayong', 'Thailand', '21150', 'Turnaround maintenance and spare parts.', TRUE, FALSE, NULL, 3, 2, 3, now() - interval '48 days'),
 (7, 'LD-00007', 'Andaman Cold Chain', 'อันดามันโคลด์เชน', '0835563344556', 210000000, '076-555-0707', '081-555-0707', '15 Phuket Bypass Rd.', 'Phuket', 'Phuket', 'Thailand', '83000', 'Cold storage; refrigeration service.', TRUE, FALSE, NULL, 6, 2, 6, now() - interval '12 days'),
 (8, 'LD-00008', 'Bangkok Finance Partners', NULL, '0105564455667', 40000000, '02-555-0808', NULL, '99 Sathorn Rd.', 'Bangkok', 'Bangkok', 'Thailand', '10120', 'Finance-department vendor, not a sales prospect.', TRUE, FALSE, NULL, 8, 3, 8, now() - interval '9 days');

INSERT INTO lead_industries (lead_id, industry_id) SELECT l, i.id FROM (VALUES
 (1,'Manufacturing'),(1,'Food & Beverage'),(2,'Food & Beverage'),(3,'Automotive'),(3,'Manufacturing'),(4,'Energy & Utilities'),(4,'Government'),
 (5,'Manufacturing'),(5,'Construction'),(6,'Energy & Utilities'),(7,'Logistics'),(7,'Food & Beverage'),(8,'Retail')) v(l, n) JOIN industries i ON i.name = v.n;
INSERT INTO lead_lead_sources (lead_id, lead_source_id) SELECT l, s.id FROM (VALUES
 (1,'Trade show'),(1,'Referral'),(2,'Website'),(3,'Cold call'),(4,'Partner'),(5,'Website'),(6,'Existing customer'),(7,'Social media'),(8,'Referral')) v(l, n) JOIN lead_sources s ON s.name = v.n;

INSERT INTO contacts (id, code, salutation, name_en, name_th, phone, phone_ext, email, job_title, department, lead_source_id, lead_id, additional_contact, city, country, owner_id, department_id, created_by, created_at) VALUES
 (1, 'CT-00001', 'Mr.',  'Somsak Rattanakul', 'สมศักดิ์ รัตนกุล', '02-555-0101', '120', 'somsak@siampack.example', 'Plant manager', 'Production', (SELECT id FROM lead_sources WHERE name = 'Trade show'), 1, 'LINE: somsak.r', 'Bangkok', 'Thailand', 9, 4, 9, now() - interval '68 days'),
 (2, 'CT-00002', 'Ms.',  'Naree Charoen', 'นารี เจริญ', '02-555-0101', '210', 'naree@siampack.example', 'Purchasing manager', 'Purchasing', NULL, 1, NULL, 'Bangkok', 'Thailand', 9, 4, 9, now() - interval '66 days'),
 (3, 'CT-00003', 'Khun', 'Anan Boonmee', 'อนันต์ บุญมี', '02-555-0202', NULL, 'anan@chaophrayafoods.example', 'Engineering director', 'Engineering', (SELECT id FROM lead_sources WHERE name = 'Website'), 2, 'WeChat: anan-b', 'Samut Sakhon', 'Thailand', 9, 4, 9, now() - interval '54 days'),
 (4, 'CT-00004', 'Mr.',  'Kittipong Saelim', NULL, '038-555-0303', '305', 'kittipong@eastauto.example', 'Maintenance head', 'Maintenance', (SELECT id FROM lead_sources WHERE name = 'Cold call'), 3, NULL, 'Chonburi', 'Thailand', 9, 4, 9, now() - interval '39 days'),
 (5, 'CT-00005', 'Mrs.', 'Pimchanok Wattana', 'พิมพ์ชนก วัฒนา', '044-555-0404', NULL, 'pimchanok@pec.example', 'Procurement officer', 'Procurement', (SELECT id FROM lead_sources WHERE name = 'Partner'), 4, NULL, 'Khon Kaen', 'Thailand', 9, 4, 9, now() - interval '24 days'),
 (6, 'CT-00006', 'Mr.',  'Chatchai Intharat', NULL, '053-555-0505', NULL, 'chatchai@lanna.example', 'Owner', NULL, (SELECT id FROM lead_sources WHERE name = 'Website'), 5, NULL, 'Chiang Mai', 'Thailand', 5, 2, 5, now() - interval '32 days'),
 (7, 'CT-00007', 'Dr.',  'Suda Kanchana', 'สุดา กาญจนา', '038-555-0606', '88', 'suda@rayongpetro.example', 'Reliability engineer', 'Maintenance', (SELECT id FROM lead_sources WHERE name = 'Existing customer'), 6, NULL, 'Rayong', 'Thailand', 3, 2, 3, now() - interval '47 days'),
 (8, 'CT-00008', 'Ms.',  'Mali Phongsri', NULL, '076-555-0707', NULL, 'mali@andamancold.example', 'Operations manager', 'Operations', (SELECT id FROM lead_sources WHERE name = 'Social media'), 7, NULL, 'Phuket', 'Thailand', 6, 2, 6, now() - interval '11 days');

INSERT INTO contact_mobiles (contact_id, number, ext, label, is_primary, sort) VALUES
 (1, '081-555-1001', NULL, 'Work', TRUE, 0), (1, '089-555-1002', NULL, 'Personal', FALSE, 1),
 (2, '081-555-2001', NULL, NULL, TRUE, 0),
 (3, '082-555-3001', NULL, 'Work', TRUE, 0), (3, '086-555-3002', NULL, 'Assistant', FALSE, 1), (3, '+66 83 555 3003', NULL, 'Personal', FALSE, 2),
 (4, '084-555-4001', NULL, NULL, TRUE, 0), (5, '085-555-5001', NULL, NULL, TRUE, 0), (6, '086-555-6001', NULL, NULL, TRUE, 0),
 (7, '087-555-7001', NULL, 'Work', TRUE, 0), (7, '087-555-7002', NULL, 'Site', FALSE, 1), (8, '088-555-8001', NULL, NULL, TRUE, 0);

INSERT INTO products (id, code, name, unit, unit_price, currency, description, is_active) VALUES
 (1, 'SFM-100', 'Servo filling machine', 'set', 1250000, 'THB', 'Single-head servo filler.', TRUE),
 (2, 'CVB-020', 'Conveyor belt module', 'm', 18500, 'THB', NULL, TRUE),
 (3, 'MNT-ANN', 'Annual maintenance contract', 'year', 360000, 'THB', 'Planned maintenance, 4 visits.', TRUE),
 (4, 'SPK-KIT', 'Spare parts kit (press)', 'kit', 2400, 'USD', NULL, TRUE),
 (5, 'OLD-001', 'Legacy gearbox', 'pc', 54000, 'THB', 'No longer sold.', FALSE);

INSERT INTO campaigns (id, code, name, type, status, start_date, end_date, budget, actual_cost, expected_revenue, expected_response_rate, actual_response_rate, description, owner_id, department_id, created_by) VALUES
 (1, 'CP-00001', 'Pack Expo Bangkok 2026', 'TRADE_SHOW', 'ACTIVE', now() - interval '20 days', now() + interval '25 days', 800000, 520000, 6000000, 4, 2.8, 'Booth plus two live demos of the filling line.', 9, 4, 9),
 (2, 'CP-00002', 'Q4 maintenance contract mail-out', 'EMAIL', 'PLANNED', now() + interval '10 days', now() + interval '40 days', 60000, NULL, 900000, 3, NULL, NULL, 3, 2, 3),
 (3, 'CP-00003', 'Customer referral programme', 'REFERRAL', 'COMPLETED', now() - interval '120 days', now() - interval '30 days', 150000, 138000, 1200000, 5, 6.1, NULL, 9, 4, 9);

INSERT INTO opportunities (id, code, name, probability, amount, follow_at, close_at, currency, cancel_reason, description, priority, opportunity_stage, lead_id, contact_id, owner_id, department_id, created_by, created_at, updated_at) VALUES
 (1, 'OP-00001', 'Filling line upgrade, plant 2', 75, 4200000, now() + interval '3 days',  now() + interval '30 days', 'THB', NULL, 'Replace two legacy fillers.', 'HIGH', 'NEGOTIATION', 1, 1, 9, 4, 9, now() - interval '50 days', now() - interval '2 days'),
 (2, 'OP-00002', 'Conveyor modules for new warehouse', 50, 925000, now() + interval '6 days', now() + interval '45 days', 'THB', NULL, NULL, 'MEDIUM', 'EVALUATION_TESTING', 1, 2, 9, 4, 9, now() - interval '35 days', now() - interval '5 days'),
 (3, 'OP-00003', 'Seafood line servo fillers', 30, 3100000, now() + interval '9 days', now() + interval '70 days', 'THB', NULL, NULL, 'HIGH', 'SURVEY_PROPOSAL', 2, 3, 9, 4, 9, now() - interval '30 days', now() - interval '8 days'),
 (4, 'OP-00004', 'Press line spare parts programme', 10, 54000, now() + interval '12 days', now() + interval '90 days', 'USD', NULL, NULL, 'MEDIUM', 'QUALIFICATION', 3, 4, 9, 4, 9, now() - interval '14 days', now() - interval '14 days'),
 (5, 'OP-00005', 'Co-op substation conveyor tender', 100, 1850000, NULL, now() - interval '10 days', 'THB', NULL, NULL, 'MEDIUM', 'CLOSED_WON', 4, 5, 9, 4, 9, now() - interval '60 days', now() - interval '10 days'),
 (6, 'OP-00006', 'Packaging film tester', 0, 380000, NULL, now() - interval '40 days', 'THB', 'Customer chose a cheaper import.', NULL, 'LOW', 'CLOSED_LOST', 1, 2, 9, 4, 9, now() - interval '90 days', now() - interval '40 days'),
 (7, 'OP-00007', 'Kiln maintenance contract', 50, 360000, now() + interval '4 days', now() + interval '20 days', 'THB', NULL, NULL, 'MEDIUM', 'EVALUATION_TESTING', 5, 6, 5, 2, 5, now() - interval '28 days', now() - interval '3 days'),
 (8, 'OP-00008', 'Turnaround spare parts, unit 4', 75, 6800000, now() + interval '2 days', now() + interval '25 days', 'THB', NULL, 'Largest open deal in Operations.', 'HIGH', 'NEGOTIATION', 6, 7, 3, 2, 3, now() - interval '45 days', now() - interval '1 days'),
 (9, 'OP-00009', 'Cold room compressor service', 100, 240000, NULL, now() - interval '5 days', 'THB', NULL, NULL, 'MEDIUM', 'CLOSED_WON', 7, 8, 6, 2, 6, now() - interval '30 days', now() - interval '5 days'),
 (10,'OP-00010', 'Chiller retrofit', NULL, 12000, NULL, now() + interval '60 days', 'USD', 'Customer budget freeze until next year.', NULL, 'LOW', 'ON_HOLD', 7, 8, 6, 2, 6, now() - interval '20 days', now() - interval '6 days');

INSERT INTO opportunity_products (opportunity_id, product_id, name, quantity, unit_price, note, sort) VALUES
 (1, 1, 'Servo filling machine', 2, 1250000, NULL, 0), (1, 2, 'Conveyor belt module', 10, 18500, 'Infeed section', 1), (1, NULL, 'Custom nozzle set (customer drawing)', 1, 55000, 'Not in the catalogue yet', 2),
 (2, 2, 'Conveyor belt module', 50, 18500, NULL, 0),
 (3, 1, 'Servo filling machine', 2, 1250000, NULL, 0), (3, NULL, 'Stainless guard cabinet', 2, 300000, NULL, 1),
 (4, 4, 'Spare parts kit (press)', 20, 2400, NULL, 0),
 (7, 3, 'Annual maintenance contract', 1, 360000, NULL, 0),
 (8, NULL, 'Mechanical seals, assorted', 1, NULL, 'Quote from supplier pending', 0);

INSERT INTO opportunity_steps (opportunity_id, kind, stage, from_stage, title, note, due_at, is_done, done_at, done_by, created_by, created_at) VALUES
 (1, 'stage', 'QUALIFICATION',      NULL,                 '', NULL, NULL, TRUE, now() - interval '50 days', 9, 9, now() - interval '50 days'),
 (1, 'stage', 'SURVEY_PROPOSAL',    'QUALIFICATION',      '', 'Site survey booked with the plant manager.', NULL, TRUE, now() - interval '42 days', 9, 9, now() - interval '42 days'),
 (1, 'step',  'SURVEY_PROPOSAL',    NULL, 'Site survey, plant 2', 'Measured the line, photos shared in the folder.', NULL, TRUE, now() - interval '38 days', 9, 9, now() - interval '41 days'),
 (1, 'stage', 'EVALUATION_TESTING', 'SURVEY_PROPOSAL',    '', 'Proposal accepted for trial.', NULL, TRUE, now() - interval '30 days', 9, 9, now() - interval '30 days'),
 (1, 'step',  'EVALUATION_TESTING', NULL, 'Run 3-day trial with their product', 'Trial passed: fill accuracy within 0.5%.', NULL, TRUE, now() - interval '20 days', 9, 9, now() - interval '28 days'),
 (1, 'stage', 'NEGOTIATION',        'EVALUATION_TESTING', '', 'Price and delivery terms.', NULL, TRUE, now() - interval '14 days', 9, 9, now() - interval '14 days'),
 (1, 'step',  'NEGOTIATION',        NULL, 'Send revised quotation', NULL, now() + interval '3 days', FALSE, NULL, NULL, 9, now() - interval '2 days'),
 (1, 'step',  'NEGOTIATION',        NULL, 'Get legal review of the contract', NULL, now() + interval '9 days', FALSE, NULL, NULL, 9, now() - interval '2 days'),
 (2, 'stage', 'QUALIFICATION',      NULL, '', NULL, NULL, TRUE, now() - interval '35 days', 9, 9, now() - interval '35 days'),
 (2, 'stage', 'EVALUATION_TESTING', 'QUALIFICATION', '', NULL, NULL, TRUE, now() - interval '12 days', 9, 9, now() - interval '12 days'),
 (2, 'step',  'EVALUATION_TESTING', NULL, 'Deliver demo module to the warehouse', NULL, now() + interval '6 days', FALSE, NULL, NULL, 9, now() - interval '5 days'),
 (3, 'stage', 'SURVEY_PROPOSAL',    NULL, '', NULL, NULL, TRUE, now() - interval '30 days', 9, 9, now() - interval '30 days'),
 (3, 'step',  'SURVEY_PROPOSAL',    NULL, 'Send proposal to engineering director', NULL, now() + interval '9 days', FALSE, NULL, NULL, 9, now() - interval '8 days'),
 (4, 'stage', 'QUALIFICATION',      NULL, '', NULL, NULL, TRUE, now() - interval '14 days', 9, 9, now() - interval '14 days'),
 (5, 'stage', 'QUALIFICATION',      NULL, '', NULL, NULL, TRUE, now() - interval '60 days', 9, 9, now() - interval '60 days'),
 (5, 'stage', 'CLOSED_WON',         'QUALIFICATION', '', 'Tender awarded to us.', NULL, TRUE, now() - interval '10 days', 9, 9, now() - interval '10 days'),
 (6, 'stage', 'QUALIFICATION',      NULL, '', NULL, NULL, TRUE, now() - interval '90 days', 9, 9, now() - interval '90 days'),
 (6, 'stage', 'CLOSED_LOST',        'QUALIFICATION', '', 'Customer chose a cheaper import.', NULL, TRUE, now() - interval '40 days', 9, 9, now() - interval '40 days'),
 (7, 'stage', 'EVALUATION_TESTING', NULL, '', NULL, NULL, TRUE, now() - interval '28 days', 5, 5, now() - interval '28 days'),
 (8, 'stage', 'NEGOTIATION',        NULL, '', NULL, NULL, TRUE, now() - interval '45 days', 3, 3, now() - interval '45 days'),
 (8, 'step',  'NEGOTIATION',        NULL, 'Confirm seal delivery dates with the supplier', NULL, now() + interval '2 days', FALSE, NULL, NULL, 3, now() - interval '1 days'),
 (9, 'stage', 'CLOSED_WON',         NULL, '', NULL, NULL, TRUE, now() - interval '5 days', 6, 6, now() - interval '30 days'),
 (10,'stage', 'ON_HOLD',            'QUALIFICATION', '', 'Customer budget freeze until next year.', NULL, TRUE, now() - interval '6 days', 6, 6, now() - interval '6 days');

INSERT INTO activities (id, code, activity_type, topic, status, call_duration, call_direction, meeting_duration, meeting_type, meeting_location, start_at, description, lead_id, contact_id, opportunity_id, notify_me, notify_before, owner_id, department_id, created_by) VALUES
 (1, 'AC-00001', 'CALL',    'Follow-up call about the quotation', 'PLANNED', NULL, 'OUTBOUND', NULL, NULL, NULL, now() + interval '1 day', NULL, 1, 1, 1, TRUE, 15, 9, 4, 9),
 (2, 'AC-00002', 'MEETING', 'Contract review with legal', 'PLANNED', NULL, NULL, 60, 'ONSITE', 'Siam Packaging, meeting room 3', now() + interval '5 days', 'Bring the redlined draft.', 1, 2, 1, TRUE, 60, 9, 4, 9),
 (3, 'AC-00003', 'CALL',    'Check trial results', 'PLANNED', NULL, 'OUTBOUND', NULL, NULL, NULL, now() - interval '2 days', NULL, 2, 3, 3, FALSE, 0, 9, 4, 9),
 (4, 'AC-00004', 'MEETING', 'Site survey, seafood line', 'DONE', NULL, NULL, 90, 'CUSTOMER_SITE', 'Samut Sakhon plant', now() - interval '12 days', 'Survey done, photos uploaded.', 2, 3, 3, FALSE, 0, 9, 4, 9),
 (5, 'AC-00005', 'EMAIL',   'Send brochure and price list', 'DONE', NULL, NULL, NULL, NULL, NULL, now() - interval '20 days', NULL, 3, 4, 4, FALSE, 0, 9, 4, 9),
 (6, 'AC-00006', 'CALL',    'Kiln inspection scheduling', 'PLANNED', NULL, 'INBOUND', NULL, NULL, NULL, now() + interval '2 days', NULL, 5, 6, 7, TRUE, 30, 5, 2, 5),
 (7, 'AC-00007', 'MEETING', 'Turnaround planning workshop', 'PLANNED', NULL, NULL, 120, 'ONLINE', 'https://meet.example/turnaround', now() + interval '4 days', NULL, 6, 7, 8, TRUE, 15, 3, 2, 3),
 (8, 'AC-00008', 'TASK',    'Prepare spare-parts price comparison', 'PLANNED', NULL, NULL, NULL, NULL, NULL, now() - interval '1 days', NULL, 6, 7, 8, FALSE, 0, 3, 2, 3),
 (9, 'AC-00009', 'CALL',    'Intro call with new cold-storage lead', 'DONE', 25, 'OUTBOUND', NULL, NULL, NULL, now() - interval '10 days', NULL, 7, 8, 9, FALSE, 0, 6, 2, 6);

INSERT INTO comments (entity_type, entity_id, body, created_by, created_at) VALUES
 ('opportunity', 1, E'Plant manager confirmed budget is approved. Waiting for the legal review.', 9, now() - interval '3 days'),
 ('opportunity', 1, E'Reminder: they want delivery before the end of Q1.', 3, now() - interval '2 days'),
 ('opportunity', 8, E'Supplier lead time is 6 weeks; need to confirm before we quote.', 3, now() - interval '1 days'),
 ('activity', 4, E'Photos and measurements are in the shared folder.', 9, now() - interval '11 days');

-- Sales shared one lead and one opportunity with Operations (department) and a contact with one person
INSERT INTO record_shares (entity_type, entity_id, target_type, target_id, access, shared_by) VALUES
 ('lead', 1, 'department', 2, 'view', 9),
 ('opportunity', 1, 'user', 4, 'edit', 9),
 ('contact', 1, 'user', 5, 'view', 9);

PERFORM setval(pg_get_serial_sequence('leads','id'), (SELECT max(id) FROM leads));
PERFORM setval(pg_get_serial_sequence('contacts','id'), (SELECT max(id) FROM contacts));
PERFORM setval(pg_get_serial_sequence('products','id'), (SELECT max(id) FROM products));
PERFORM setval(pg_get_serial_sequence('campaigns','id'), (SELECT max(id) FROM campaigns));
PERFORM setval(pg_get_serial_sequence('opportunities','id'), (SELECT max(id) FROM opportunities));
PERFORM setval(pg_get_serial_sequence('activities','id'), (SELECT max(id) FROM activities));
END
$demo$;
