-- =====================================================================
-- Outing System — dummy seed data (run AFTER schema.sql, on empty tables)
--
-- LOGINS
--   Staff    (login_staff.php)   password: Staff@123
--     ADM001  admin   | STF001, STF002  warden | GRD001, GRD002  guard
--     GRD003  guard, INACTIVE (tests the deactivated-account block)
--   Students (login_student.php) password: Student@123
--     2026000001 - 2026000015
--     2026000002 is forced to change its password at first login
--
-- SPECIAL OUTING RULES THIS SEED FOLLOWS
--   * Special track is only used during curfew (23:00 - 06:00): every special
--     time below (requested out, actual out, expected arrival) falls in that window.
--   * checkout request: only requested_out_at is stored; the gate records
--     actual_out_at. No expected return, so a checkout is never flagged overdue.
--   * checkin request: pre-clears an arrival (expected_return_at). The student can
--     be coming back from a STANDARD outing or from a SPECIAL checkout.
--   * Standard outings are in daytime hours (curfew blocks standard checkout).
--
-- WHO TO LOOK UP FOR EACH SCENARIO
--   2026000001     approved-arrival (checkin) request, OVERDUE, never showed up
--   2026000002     clean slate, no history
--   2026000003     special CHECKOUT request PENDING (warden approves/rejects)
--   2026000004     special CHECKIN request PENDING
--   2026000005/06  special CHECKOUT APPROVED, ready to check out at the gate
--   2026000007     special CHECKOUT REJECTED
--   2026000008     special CHECKED OUT (no expected return) + approved CHECKIN request
--   2026000009     special CHECKED OUT since 2 nights ago (no deadline, never overdue)
--   2026000010     special CHECKOUT COMPLETED + standard on-time and late returns
--   2026000011     special CHECKOUT CANCELLED
--   2026000012     standard OUT (group member) + approved CHECKIN request for a curfew arrival
--   2026000013     standard OUT, group lead of an ACTIVE group, no arrival approval
--   2026000014     standard return in curfew WITH approved checkin (not late); lead of a CLOSED group
--   2026000015     standard LATE return in curfew, no approval; has a pending group invite
--
-- Dates are relative to NOW()/CURDATE(), so the data always looks current.
-- =====================================================================

USE outing_system;
SET NAMES utf8mb4;
SET time_zone = '+08:00';

SET @staff_pw   = '$2y$10$uh6uPzJ4U9Mqkt71g8g0Yeoy.l99EKFBDleUUxRjUIJmP3cXkpJ4m'; -- Staff@123
SET @student_pw = '$2y$10$6evXQQdebeTkm72OcIWnUeXW5h06fisW3lfXvjkmjzKZgLXL6u4X2'; -- Student@123

-- ---------------------------------------------------------------------
-- staff
-- ---------------------------------------------------------------------
INSERT INTO staff (staff_id, name, email, role, password_hash, is_active, must_change_password) VALUES
  ('ADM001', 'Admin Test One',   'admin1@outing.test',  'admin',  @staff_pw, 1, 0),
  ('STF001', 'Warden Test One',  'warden1@outing.test', 'warden', @staff_pw, 1, 0),
  ('STF002', 'Warden Test Two',  'warden2@outing.test', 'warden', @staff_pw, 1, 0),
  ('GRD001', 'Guard Test One',   'guard1@outing.test',  'guard',  @staff_pw, 1, 0),
  ('GRD002', 'Guard Test Two',   'guard2@outing.test',  'guard',  @staff_pw, 1, 0),
  ('GRD003', 'Guard Test Three (inactive)', 'guard3@outing.test', 'guard', @staff_pw, 0, 0);

-- ---------------------------------------------------------------------
-- student
-- ---------------------------------------------------------------------
INSERT INTO student
  (std_no, std_name, ic_no, program, email, phone, emergency_contact_name, emergency_contact_phone, password_hash, is_active, must_change_password) VALUES
  ('2026000001', 'Student Test One',             '990101-00-0001', 'Bachelor of Computer Science',   'student1@example.test',   '0123450001', 'Test Parent One',      '0193450001', @student_pw, 1, 0),
  ('2026000002', 'Student Test Two',             '990202-00-0002', 'Bachelor of Accounting',         'student2@example.test',   '0123450002', 'Test Parent Two',      '0193450002', @student_pw, 1, 1),
  ('2026000003', 'Ahmad Daniel bin Ismail',      '030315-11-0001', 'Bachelor of Computer Science',   'daniel3@example.test',    '0123450003', 'Ismail bin Hamid',     '0193450003', @student_pw, 1, 0),
  ('2026000004', 'Nur Aisyah binti Hassan',      '030420-11-0002', 'Bachelor of Marine Biology',     'aisyah4@example.test',    '0123450004', 'Hassan bin Ali',       '0193450004', @student_pw, 1, 0),
  ('2026000005', 'Muhammad Haziq bin Zulkifli',  '030512-11-0003', 'Bachelor of Engineering',        'haziq5@example.test',     '0123450005', 'Zulkifli bin Omar',    '0193450005', @student_pw, 1, 0),
  ('2026000006', 'Siti Nurhaliza binti Rahman',  '030618-11-0004', 'Bachelor of Business',           'nurhaliza6@example.test', '0123450006', 'Rahman bin Yusof',     '0193450006', @student_pw, 1, 0),
  ('2026000007', 'Farid Iskandar bin Yusof',     '030722-11-0005', 'Bachelor of Accounting',         'farid7@example.test',     '0123450007', 'Yusof bin Salleh',     '0193450007', @student_pw, 1, 0),
  ('2026000008', 'Nurul Ain binti Kamal',        '030825-11-0006', 'Bachelor of Education',          'ain8@example.test',        '0123450008', 'Kamal bin Ahmad',      '0193450008', @student_pw, 1, 0),
  ('2026000009', 'Amirul Hakim bin Osman',       '030930-11-0007', 'Bachelor of Computer Science',   'hakim9@example.test',     '0123450009', 'Osman bin Daud',       '0193450009', @student_pw, 1, 0),
  ('2026000010', 'Wan Nur Batrisyia binti Wan',  '031002-11-0008', 'Bachelor of Business',           'batrisyia10@example.test','0123450010', 'Wan Ahmad bin Wan',    '0193450010', @student_pw, 1, 0),
  ('2026000011', 'Zul Hilmi bin Rosli',          '031105-11-0009', 'Bachelor of Engineering',        'hilmi11@example.test',    '0123450011', 'Rosli bin Ibrahim',    '0193450011', @student_pw, 1, 0),
  ('2026000012', 'Aina Sofea binti Mahmud',      '031208-11-0010', 'Bachelor of Marine Biology',     'sofea12@example.test',    '0123450012', 'Mahmud bin Hashim',    '0193450012', @student_pw, 1, 0),
  ('2026000013', 'Danish Iman bin Roslan',       '031310-11-0011', 'Bachelor of Computer Science',   'danish13@example.test',   '0123450013', 'Roslan bin Kassim',    '0193450013', @student_pw, 1, 0),
  ('2026000014', 'Qistina Alya binti Zamri',     '031415-11-0012', 'Bachelor of Education',          'qistina14@example.test',  '0123450014', 'Zamri bin Ariffin',    '0193450014', @student_pw, 1, 0),
  ('2026000015', 'Rayyan Haqimi bin Fauzi',      '031520-11-0013', 'Bachelor of Business',           'rayyan15@example.test',   '0123450015', 'Fauzi bin Mustafa',    '0193450015', @student_pw, 1, 0);

-- ---------------------------------------------------------------------
-- outing_request (special track, curfew hours only)
--   checkout: requested_out_at only (+ actual_out_at set by the gate)
--   checkin : expected_return_at only
-- ---------------------------------------------------------------------
SET @d0 = CURDATE();

INSERT INTO outing_request
  (id, std_no, type, reason, destination, requested_out_at, expected_return_at, status,
   reviewed_by, reviewed_at, rejection_reason, actual_out_at, actual_return_at, created_at) VALUES
  -- 1 pending checkout
  (1,  '2026000003', 'checkout', 'Family emergency',        'Kuala Terengganu',
       TIMESTAMP(DATE_ADD(@d0, INTERVAL 1 DAY), '23:30:00'), NULL, 'pending',
       NULL, NULL, NULL, NULL, NULL, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
  -- 2 pending checkin
  (2,  '2026000004', 'checkin',  'Late flight arrival',     'N/A',
       NULL, TIMESTAMP(DATE_ADD(@d0, INTERVAL 2 DAY), '00:45:00'), 'pending',
       NULL, NULL, NULL, NULL, NULL, DATE_SUB(NOW(), INTERVAL 30 MINUTE)),
  -- 3-4 approved checkouts, waiting at the gate
  (3,  '2026000005', 'checkout', 'Hospital emergency (relative)', 'Hospital Sultanah Nur Zahirah',
       TIMESTAMP(@d0, '23:30:00'), NULL, 'approved',
       'STF001', DATE_SUB(NOW(), INTERVAL 1 HOUR), NULL, NULL, NULL, DATE_SUB(NOW(), INTERVAL 1 DAY)),
  (4,  '2026000006', 'checkout', 'Urgent family matter',    'Dungun',
       TIMESTAMP(@d0, '23:45:00'), NULL, 'approved',
       'STF002', DATE_SUB(NOW(), INTERVAL 3 HOUR), NULL, NULL, NULL, DATE_SUB(NOW(), INTERVAL 26 HOUR)),
  -- 5 rejected checkout
  (5,  '2026000007', 'checkout', 'Concert',                 'Kuala Lumpur',
       TIMESTAMP(DATE_ADD(@d0, INTERVAL 1 DAY), '23:15:00'), NULL, 'rejected',
       'STF001', DATE_SUB(NOW(), INTERVAL 4 HOUR), 'Not an emergency; please go out before curfew.',
       NULL, NULL, DATE_SUB(NOW(), INTERVAL 1 DAY)),
  -- 6-7 checked out, still out (no expected return -> never overdue)
  (6,  '2026000008', 'checkout', 'Pick up sibling',         'Kuala Terengganu Airport',
       TIMESTAMP(DATE_SUB(@d0, INTERVAL 1 DAY), '23:30:00'), NULL, 'checked_out',
       'STF001', DATE_SUB(NOW(), INTERVAL 30 HOUR), NULL,
       TIMESTAMP(DATE_SUB(@d0, INTERVAL 1 DAY), '23:38:00'), NULL, DATE_SUB(NOW(), INTERVAL 32 HOUR)),
  (7,  '2026000009', 'checkout', 'Family emergency',        'Kuala Nerus',
       TIMESTAMP(DATE_SUB(@d0, INTERVAL 2 DAY), '23:50:00'), NULL, 'checked_out',
       'STF002', DATE_SUB(NOW(), INTERVAL 55 HOUR), NULL,
       TIMESTAMP(DATE_SUB(@d0, INTERVAL 2 DAY), '23:55:00'), NULL, DATE_SUB(NOW(), INTERVAL 56 HOUR)),
  -- 8 completed checkout (closed at the gate on return)
  (8,  '2026000010', 'checkout', 'Emergency ride home',     'Kuantan',
       TIMESTAMP(DATE_SUB(@d0, INTERVAL 3 DAY), '23:30:00'), NULL, 'checked_in',
       'STF001', DATE_SUB(NOW(), INTERVAL 80 HOUR), NULL,
       TIMESTAMP(DATE_SUB(@d0, INTERVAL 3 DAY), '23:36:00'),
       TIMESTAMP(DATE_SUB(@d0, INTERVAL 2 DAY), '05:20:00'), DATE_SUB(NOW(), INTERVAL 82 HOUR)),
  -- 9 cancelled checkout
  (9,  '2026000011', 'checkout', 'Night market',            'Pasar Malam Batu Buruk',
       TIMESTAMP(DATE_ADD(@d0, INTERVAL 1 DAY), '23:00:00'), NULL, 'cancelled',
       NULL, NULL, NULL, NULL, NULL, DATE_SUB(NOW(), INTERVAL 5 HOUR)),
  -- 10 approved checkin: student is out on the STANDARD track
  (10, '2026000012', 'checkin',  'Late bus from KL',        'N/A',
       NULL, TIMESTAMP(DATE_ADD(@d0, INTERVAL 1 DAY), '00:30:00'), 'approved',
       'STF001', DATE_SUB(NOW(), INTERVAL 1 HOUR), NULL, NULL, NULL, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
  -- 11 overdue checkin: approved to arrive, never showed up
  (11, '2026000001', 'checkin',  'Flight delay',            'N/A',
       NULL, TIMESTAMP(DATE_SUB(@d0, INTERVAL 1 DAY), '01:00:00'), 'overdue',
       'STF001', DATE_SUB(NOW(), INTERVAL 40 HOUR), NULL, NULL, NULL, DATE_SUB(NOW(), INTERVAL 42 HOUR)),
  -- 12 fulfilled checkin: came back from a STANDARD outing during curfew, pre-cleared
  (12, '2026000014', 'checkin',  'Bus arrives after curfew', 'N/A',
       NULL, TIMESTAMP(DATE_SUB(@d0, INTERVAL 2 DAY), '23:30:00'), 'checked_in',
       'STF002', DATE_SUB(NOW(), INTERVAL 70 HOUR), NULL,
       NULL, TIMESTAMP(DATE_SUB(@d0, INTERVAL 2 DAY), '23:40:00'), DATE_SUB(NOW(), INTERVAL 72 HOUR)),
  -- 13 approved checkin: student is out on a SPECIAL checkout (request 6)
  (13, '2026000008', 'checkin',  'Returning with sibling',  'N/A',
       NULL, TIMESTAMP(DATE_ADD(@d0, INTERVAL 1 DAY), '01:15:00'), 'approved',
       'STF001', DATE_SUB(NOW(), INTERVAL 20 HOUR), NULL, NULL, NULL, DATE_SUB(NOW(), INTERVAL 21 HOUR));

-- ---------------------------------------------------------------------
-- outing_event (audit trail; the app stores the gate in `note`, not gate_location)
-- ---------------------------------------------------------------------
INSERT INTO outing_event (request_id, event_type, actor_type, actor_id, gate_location, note, event_at) VALUES
  (1,  'submitted', 'student', '2026000003', NULL, NULL, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
  (2,  'submitted', 'student', '2026000004', NULL, NULL, DATE_SUB(NOW(), INTERVAL 30 MINUTE)),
  (3,  'submitted', 'student', '2026000005', NULL, NULL, DATE_SUB(NOW(), INTERVAL 1 DAY)),
  (3,  'approved',  'staff',   'STF001',     NULL, NULL, DATE_SUB(NOW(), INTERVAL 1 HOUR)),
  (4,  'submitted', 'student', '2026000006', NULL, NULL, DATE_SUB(NOW(), INTERVAL 26 HOUR)),
  (4,  'approved',  'staff',   'STF002',     NULL, NULL, DATE_SUB(NOW(), INTERVAL 3 HOUR)),
  (5,  'submitted', 'student', '2026000007', NULL, NULL, DATE_SUB(NOW(), INTERVAL 1 DAY)),
  (5,  'rejected',  'staff',   'STF001',     NULL, 'Not an emergency; please go out before curfew.', DATE_SUB(NOW(), INTERVAL 4 HOUR)),
  (6,  'submitted', 'student', '2026000008', NULL, NULL, DATE_SUB(NOW(), INTERVAL 32 HOUR)),
  (6,  'approved',  'staff',   'STF001',     NULL, NULL, DATE_SUB(NOW(), INTERVAL 30 HOUR)),
  (6,  'checked_out','staff',  'GRD001',     NULL, 'Gate: Main Gate', TIMESTAMP(DATE_SUB(@d0, INTERVAL 1 DAY), '23:38:00')),
  (7,  'submitted', 'student', '2026000009', NULL, NULL, DATE_SUB(NOW(), INTERVAL 56 HOUR)),
  (7,  'approved',  'staff',   'STF002',     NULL, NULL, DATE_SUB(NOW(), INTERVAL 55 HOUR)),
  (7,  'checked_out','staff',  'GRD002',     NULL, 'Gate: Main Gate', TIMESTAMP(DATE_SUB(@d0, INTERVAL 2 DAY), '23:55:00')),
  (8,  'submitted', 'student', '2026000010', NULL, NULL, DATE_SUB(NOW(), INTERVAL 82 HOUR)),
  (8,  'approved',  'staff',   'STF001',     NULL, NULL, DATE_SUB(NOW(), INTERVAL 80 HOUR)),
  (8,  'checked_out','staff',  'GRD002',     NULL, 'Gate: Main Gate', TIMESTAMP(DATE_SUB(@d0, INTERVAL 3 DAY), '23:36:00')),
  (8,  'checked_in','staff',   'GRD002',     NULL, 'Gate: Main Gate', TIMESTAMP(DATE_SUB(@d0, INTERVAL 2 DAY), '05:20:00')),
  (9,  'submitted', 'student', '2026000011', NULL, NULL, DATE_SUB(NOW(), INTERVAL 5 HOUR)),
  (9,  'cancelled', 'student', '2026000011', NULL, NULL, DATE_SUB(NOW(), INTERVAL 4 HOUR)),
  (10, 'submitted', 'student', '2026000012', NULL, NULL, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
  (10, 'approved',  'staff',   'STF001',     NULL, NULL, DATE_SUB(NOW(), INTERVAL 1 HOUR)),
  (11, 'submitted', 'student', '2026000001', NULL, NULL, DATE_SUB(NOW(), INTERVAL 42 HOUR)),
  (11, 'approved',  'staff',   'STF001',     NULL, NULL, DATE_SUB(NOW(), INTERVAL 40 HOUR)),
  (11, 'overdue_flagged','system', NULL,     NULL, 'Past expected arrival time', TIMESTAMP(DATE_SUB(@d0, INTERVAL 1 DAY), '01:20:00')),
  (12, 'submitted', 'student', '2026000014', NULL, NULL, DATE_SUB(NOW(), INTERVAL 72 HOUR)),
  (12, 'approved',  'staff',   'STF002',     NULL, NULL, DATE_SUB(NOW(), INTERVAL 70 HOUR)),
  (12, 'checked_in','staff',   'GRD001',     NULL, 'Gate: Main Gate', TIMESTAMP(DATE_SUB(@d0, INTERVAL 2 DAY), '23:40:00')),
  (13, 'submitted', 'student', '2026000008', NULL, NULL, DATE_SUB(NOW(), INTERVAL 21 HOUR)),
  (13, 'approved',  'staff',   'STF001',     NULL, NULL, DATE_SUB(NOW(), INTERVAL 20 HOUR));

-- ---------------------------------------------------------------------
-- standard_outing (standard track, daytime departures)
--   1-2  group #1 members, currently out (relative to NOW, as the group is)
--   3-4  group #2 members: 3 returned in curfew WITH approval (request 12, not late),
--        4 returned in curfew with NO approval (late)
--   5-6  solo trips: on-time and late
-- ---------------------------------------------------------------------
INSERT INTO standard_outing
  (id, std_no, checked_out_at, checked_out_by, gate_location_out, checked_in_at, checked_in_by, gate_location_in, is_late_return, created_at) VALUES
  (1, '2026000012', DATE_SUB(NOW(), INTERVAL 1 HOUR),    'GRD001', 'Main Gate', NULL, NULL, NULL, 0, DATE_SUB(NOW(), INTERVAL 1 HOUR)),
  (2, '2026000013', DATE_SUB(NOW(), INTERVAL 30 MINUTE), 'GRD001', 'Main Gate', NULL, NULL, NULL, 0, DATE_SUB(NOW(), INTERVAL 30 MINUTE)),
  (3, '2026000014', TIMESTAMP(DATE_SUB(@d0, INTERVAL 2 DAY), '19:00:00'), 'GRD001', 'Main Gate',
                    TIMESTAMP(DATE_SUB(@d0, INTERVAL 2 DAY), '23:40:00'), 'GRD001', 'Main Gate', 0,
                    TIMESTAMP(DATE_SUB(@d0, INTERVAL 2 DAY), '19:00:00')),
  (4, '2026000015', TIMESTAMP(DATE_SUB(@d0, INTERVAL 2 DAY), '19:00:00'), 'GRD001', 'Main Gate',
                    TIMESTAMP(DATE_SUB(@d0, INTERVAL 1 DAY), '00:20:00'), 'GRD001', 'Main Gate', 1,
                    TIMESTAMP(DATE_SUB(@d0, INTERVAL 2 DAY), '19:00:00')),
  (5, '2026000010', TIMESTAMP(DATE_SUB(@d0, INTERVAL 4 DAY), '13:00:00'), 'GRD002', 'Main Gate',
                    TIMESTAMP(DATE_SUB(@d0, INTERVAL 4 DAY), '21:15:00'), 'GRD002', 'Main Gate', 0,
                    TIMESTAMP(DATE_SUB(@d0, INTERVAL 4 DAY), '13:00:00')),
  (6, '2026000010', TIMESTAMP(DATE_SUB(@d0, INTERVAL 6 DAY), '13:00:00'), 'GRD002', 'Main Gate',
                    TIMESTAMP(DATE_SUB(@d0, INTERVAL 5 DAY), '01:10:00'), 'GRD001', 'Main Gate', 1,
                    TIMESTAMP(DATE_SUB(@d0, INTERVAL 6 DAY), '13:00:00'));

-- ---------------------------------------------------------------------
-- groups: #1 ACTIVE (lead 13; 12 accepted + out, 15 invited, 11 declined)
--         #2 CLOSED (lead 14; 15 accepted, both back)
-- ---------------------------------------------------------------------
INSERT INTO outing_group (id, lead_std_no, status, created_at, expires_at) VALUES
  (1, '2026000013', 'active', DATE_SUB(NOW(), INTERVAL 3 HOUR), DATE_ADD(NOW(), INTERVAL 21 HOUR)),
  (2, '2026000014', 'closed', TIMESTAMP(DATE_SUB(@d0, INTERVAL 2 DAY), '18:30:00'), TIMESTAMP(DATE_SUB(@d0, INTERVAL 1 DAY), '18:30:00'));

INSERT INTO outing_group_member (id, group_id, std_no, status, created_at, responded_at) VALUES
  (1, 1, '2026000012', 'accepted', DATE_SUB(NOW(), INTERVAL 3 HOUR), DATE_SUB(NOW(), INTERVAL 170 MINUTE)),
  (2, 1, '2026000015', 'invited',  DATE_SUB(NOW(), INTERVAL 2 HOUR), NULL),
  (3, 1, '2026000011', 'declined', DATE_SUB(NOW(), INTERVAL 3 HOUR), DATE_SUB(NOW(), INTERVAL 165 MINUTE)),
  (4, 2, '2026000015', 'accepted', TIMESTAMP(DATE_SUB(@d0, INTERVAL 2 DAY), '18:30:00'), TIMESTAMP(DATE_SUB(@d0, INTERVAL 2 DAY), '18:40:00'));

INSERT INTO outing_group_log (group_id, std_no, out_at, in_at) VALUES
  (1, '2026000013', DATE_SUB(NOW(), INTERVAL 30 MINUTE), NULL),
  (1, '2026000012', DATE_SUB(NOW(), INTERVAL 1 HOUR),    NULL),
  (2, '2026000014', TIMESTAMP(DATE_SUB(@d0, INTERVAL 2 DAY), '19:00:00'), TIMESTAMP(DATE_SUB(@d0, INTERVAL 2 DAY), '23:40:00')),
  (2, '2026000015', TIMESTAMP(DATE_SUB(@d0, INTERVAL 2 DAY), '19:00:00'), TIMESTAMP(DATE_SUB(@d0, INTERVAL 1 DAY), '00:20:00'));

INSERT INTO outing_group_link (kind, ref_id, group_id) VALUES
  ('standard', 1, 1),
  ('standard', 2, 1),
  ('standard', 3, 2),
  ('standard', 4, 2);

INSERT INTO outing_group_scan (group_id, guard_id, gate_location, processed_count, skipped_count, created_at) VALUES
  (1, 'GRD001', 'Main Gate', 2, 0, DATE_SUB(NOW(), INTERVAL 30 MINUTE)),
  (2, 'GRD001', 'Main Gate', 2, 0, TIMESTAMP(DATE_SUB(@d0, INTERVAL 2 DAY), '19:00:00'));

-- ---------------------------------------------------------------------
-- notification (student bell; types match the app's own)
-- ---------------------------------------------------------------------
INSERT INTO notification (std_no, type, message, reference_table, reference_id, is_read, created_at) VALUES
  ('2026000003', 'request_submitted', 'Application submitted, CRS will review application soon.', 'outing_request', 1, 0, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
  ('2026000004', 'request_submitted', 'Application submitted, CRS will review application soon.', 'outing_request', 2, 0, DATE_SUB(NOW(), INTERVAL 30 MINUTE)),
  ('2026000005', 'request_approved',  'Your outing application has been approved.',               'outing_request', 3, 0, DATE_SUB(NOW(), INTERVAL 1 HOUR)),
  ('2026000006', 'request_approved',  'Your outing application has been approved.',               'outing_request', 4, 1, DATE_SUB(NOW(), INTERVAL 3 HOUR)),
  ('2026000007', 'request_rejected',  'Your outing application has been rejected.',               'outing_request', 5, 0, DATE_SUB(NOW(), INTERVAL 4 HOUR)),
  ('2026000008', 'request_approved',  'Your outing application has been approved.',               'outing_request', 13, 0, DATE_SUB(NOW(), INTERVAL 20 HOUR)),
  ('2026000001', 'violation',         'A violation has been recorded: did not arrive by the approved time.', 'outing_request', 11, 0, TIMESTAMP(DATE_SUB(@d0, INTERVAL 1 DAY), '01:20:00')),
  ('2026000015', 'violation',         'A violation has been recorded: late return.',              'standard_outing', 4, 1, TIMESTAMP(DATE_SUB(@d0, INTERVAL 1 DAY), '00:20:00')),
  ('2026000015', 'group_invite',      'Danish Iman bin Roslan invited you to a group outing. Open "My group" to accept or decline.', 'outing_group_member', 2, 0, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
  ('2026000012', 'time_alert_30',     'Curfew starts in 30 minutes. Please return to campus.',    'standard_outing', 1, 0, DATE_SUB(NOW(), INTERVAL 10 MINUTE));
