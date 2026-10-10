-- MyToolLibrary DUMMY DATA
-- Populates every table with realistic-looking test data for development.
--
-- USAGE:
--   1. Run the schema first (Setup page -> "Run Database Setup"). This file
--      assumes the tables exist and the categories/tags/trainings lookup
--      tables are already seeded by schema.sql (category ids 1-11 / tag ids
--      1-12 / training ids 1-7 are referenced below).
--
-- CONTENTS: 60 members (39 verified with both scans, 10 with only one scan,
-- 11 with none, of whom 1 is deleted and anonymized and 1 is locked; 32 with
-- a profile photo), 125 tools (1 of them retired), their category/tag
-- mappings, 81 loans (26 active, 16 currently overdue, 5 returned late), 27
-- reservations (16 active, several tools carrying multi-member queues, plus 11
-- closed ones covering all seven values of closed_reason), and 41
-- member/training records across 24 members.
-- Machine-generated for volume testing (e.g. table pagination); repeated
-- tool types carry different brands, values, barcodes, and acquisition
-- dates so every row is distinct.
--
-- WARNING: The DELETE statements below wipe any existing member, tool, loan
-- and reservation rows so the file can be re-run safely. Do NOT run this
-- against a site with real data.
--
-- All dates below are anchored to "today" and re-shifted periodically so
-- overdue/active/returned states stay realistic; re-run the date-shift (or
-- regenerate) if this file sits unused long enough for the dates to go
-- stale again. Last anchored to 2026-10-09. The ready reservations in
-- section 7 are the first thing to go stale: their 14-day hold runs out
-- from 2026-10-18 onwards.


-- ==========================================
-- 0. CLEAR EXISTING DATA (so this file is safe to re-run)
--    Ordered children-first to satisfy FK constraints.
--    Categories/tags/trainings are left alone; schema.sql seeds those.
-- ==========================================
DELETE FROM wp_tool_reservations;
DELETE FROM wp_loans;
DELETE FROM wp_tool_training_mappings;
DELETE FROM wp_tool_tag_mappings;
DELETE FROM wp_tool_subcategory_mappings;
DELETE FROM wp_tool_category_mappings;
DELETE FROM wp_member_training_mappings;
DELETE FROM wp_member_verifications;
DELETE FROM wp_tool_inventory;
DELETE FROM wp_members;

-- ==========================================
-- 1. MEMBERS (60)
--    Explicit member_ids so the FK references further down stay stable.
--    (Explicit values are allowed on AUTO_INCREMENT columns; MySQL advances
--    the counter past them automatically.)
-- ==========================================
INSERT INTO wp_members
    (member_id, first_name, last_name, address_line1, address_line2, city, state, zip_code, country, phone_number, email, signup_date, recurring_donation_amount, has_donated_tools) VALUES
(1, 'Alice', 'Schmidt', '237 N Oakland Ave', NULL, 'Milwaukee', 'WI', '53210', 'United States', '+1 (414) 555-0101', 'alice.schmidt@example.com', '2024-03-22', 0.00, 'Y'),
(2, 'Marcus', 'Hall', '374 S Logan Ave', NULL, 'Milwaukee', 'WI', '53216', 'United States', '+1 (414) 555-0102', 'marcus.hall@example.com', '2024-04-06', 0.00, 'N'),
(3, 'Sofia', 'Carter', '511 N Humboldt Blvd', NULL, 'Milwaukee', 'WI', '53207', 'United States', '+1 (414) 555-0103', 'sofia.carter@example.com', '2024-04-21', 10.00, 'N'),
(4, 'David', 'Parker', '648 N Downer Ave', 'Apt 2', 'Milwaukee', 'WI', '53213', 'United States', '+1 (414) 555-0104', 'david.parker@example.com', '2024-05-06', 0.00, 'N'),
(5, 'Priya', 'Reed', '785 N Prospect Ave', NULL, 'Milwaukee', 'WI', '53203', 'United States', '+1 (414) 555-0105', 'priya.reed@example.com', '2024-05-21', 0.00, 'Y'),
(6, 'James', 'O''Brien', '922 S Howell Ave', NULL, 'Milwaukee', 'WI', '53211', 'United States', '+1 (414) 555-0106', 'james.obrien@example.com', '2024-06-05', 15.00, 'N'),
(7, 'Emily', 'Nguyen', '1059 S 27th St', NULL, 'Milwaukee', 'WI', '53218', 'United States', '+1 (414) 555-0107', 'emily.nguyen@example.com', '2024-06-20', 0.00, 'N'),
(8, 'Tyler', 'Allen', '1196 W Forest Home Ave', NULL, 'Milwaukee', 'WI', '53208', 'United States', '+1 (414) 555-0108', 'tyler.allen@example.com', '2024-07-05', 0.00, 'N'),
(9, 'Grace', 'Perez', '1333 W Historic Mitchell St', NULL, 'Milwaukee', 'WI', '53215', 'United States', '+1 (414) 555-0109', 'grace.perez@example.com', '2024-07-20', 20.00, 'Y'),
(10, 'Robert', 'Edwards', '1470 W Lisbon Ave', NULL, 'Milwaukee', 'WI', '53204', 'United States', '+1 (414) 555-0110', 'robert.edwards@example.com', '2024-08-04', 0.00, 'N'),
(11, 'Hannah', 'Johnson', '1607 N Astor St', NULL, 'Milwaukee', 'WI', '53212', 'United States', '+1 (414) 555-0111', 'hannah.johnson@example.com', '2024-08-19', 0.00, 'N'),
(12, 'Miguel', 'Brooks', '1744 W National Ave', 'Suite 100', 'Milwaukee', 'WI', '53202', 'United States', '+1 (414) 555-0112', 'miguel.brooks@example.com', '2024-09-03', 25.00, 'N'),
(13, 'Olivia', 'Martinez', '1881 W Center St', NULL, 'Milwaukee', 'WI', '53210', 'United States', '+1 (414) 555-0113', 'olivia.martinez@example.com', '2024-09-18', 0.00, 'Y'),
(14, 'Noah', 'Scott', '2018 W Burleigh St', NULL, 'Milwaukee', 'WI', '53216', 'United States', '+1 (414) 555-0114', 'noah.scott@example.com', '2024-10-03', 0.00, 'N'),
(15, 'Ava', 'Turner', '2155 W Wisconsin Ave', NULL, 'Milwaukee', 'WI', '53207', 'United States', '+1 (414) 555-0115', 'ava.turner@example.com', '2024-10-18', 50.00, 'N'),
(16, 'Liam', 'Stewart', '88 Queen St', NULL, 'Toronto', 'ON', 'M5H 2N2', 'Canada', '+1 (416) 555-0116', 'liam.stewart@example.com', '2024-11-02', 0.00, 'N'),
(17, 'Isabella', 'Ramirez', '2429 S Kinnickinnic Ave', NULL, 'Milwaukee', 'WI', '53203', 'United States', '+1 (414) 555-0117', 'isabella.ramirez@example.com', '2024-11-17', 0.00, 'Y'),
(18, 'Mason', 'Fitzgerald', '2566 E North Ave', NULL, 'Milwaukee', 'WI', '53211', 'United States', '+1 (414) 555-0118', 'mason.fitzgerald@example.com', '2024-12-02', 5.00, 'N'),
(19, 'Sophia', 'Walker', '2703 W Vliet St', NULL, 'Milwaukee', 'WI', '53218', 'United States', '+1 (414) 555-0119', 'sophia.walker@example.com', '2024-12-17', 0.00, 'N'),
(20, 'Ethan', 'Nelson', '2840 E Brady St', 'Unit 7B', 'Milwaukee', 'WI', '53208', 'United States', '+1 (414) 555-0120', 'ethan.nelson@example.com', '2025-01-01', 0.00, 'N'),
(21, 'Mia', 'Campbell', '2977 S 6th St', NULL, 'Milwaukee', 'WI', '53215', 'United States', '+1 (414) 555-0121', 'mia.campbell@example.com', '2025-01-16', 10.00, 'Y'),
(22, 'Lucas', 'Rogers', '3114 E Locust St', NULL, 'Milwaukee', 'WI', '53204', 'United States', '+1 (414) 555-0122', 'lucas.rogers@example.com', '2025-01-31', 0.00, 'N'),
(23, 'Charlotte', 'Patel', '3251 N Water St', NULL, 'Milwaukee', 'WI', '53212', 'United States', '+1 (414) 555-0123', 'charlotte.patel@example.com', '2025-02-15', 0.00, 'N'),
(24, 'Benjamin', 'Alvarez', '3388 N 35th St', NULL, 'Milwaukee', 'WI', '53202', 'United States', '+1 (414) 555-0124', 'benjamin.alvarez@example.com', '2025-03-02', 15.00, 'N'),
(25, 'Amelia', 'Young', '3525 N Farwell Ave', NULL, 'Milwaukee', 'WI', '53210', 'United States', '+1 (414) 555-0125', 'amelia.young@example.com', '2025-03-17', 0.00, 'Y'),
(26, 'Henry', 'Mitchell', '3662 N Oakland Ave', NULL, 'Milwaukee', 'WI', '53216', 'United States', '+1 (414) 555-0126', 'henry.mitchell@example.com', '2025-04-01', 0.00, 'N'),
(27, 'Harper', 'Evans', '3799 S Logan Ave', NULL, 'Milwaukee', 'WI', '53207', 'United States', '+1 (414) 555-0127', 'harper.evans@example.com', '2025-04-16', 20.00, 'N'),
(28, 'Alexander', 'Murphy', '3936 N Humboldt Blvd', '#4', 'Milwaukee', 'WI', '53213', 'United States', '+1 (414) 555-0128', 'alexander.murphy@example.com', '2025-05-01', 0.00, 'N'),
(29, 'Evelyn', 'Nowak', '4073 N Downer Ave', NULL, 'Milwaukee', 'WI', '53203', 'United States', '+1 (414) 555-0129', 'evelyn.nowak@example.com', '2025-05-16', 0.00, 'Y'),
(30, 'Daniel', 'Garcia', '4210 N Prospect Ave', NULL, 'Milwaukee', 'WI', '53211', 'United States', '+1 (414) 555-0130', 'daniel.garcia@example.com', '2025-05-31', 25.00, 'N'),
(31, 'Abigail', 'Wright', '4347 S Howell Ave', NULL, 'Milwaukee', 'WI', '53218', 'United States', '+1 (414) 555-0131', 'abigail.wright@example.com', '2025-06-15', 0.00, 'N'),
(32, 'Michael', 'Roberts', '4484 S 27th St', NULL, 'Milwaukee', 'WI', '53208', 'United States', '+1 (414) 555-0132', 'michael.roberts@example.com', '2025-06-30', 0.00, 'N'),
(33, 'Ella', 'Collins', '4621 W Forest Home Ave', NULL, 'Milwaukee', 'WI', '53215', 'United States', '+1 (414) 555-0133', 'ella.collins@example.com', '2025-07-15', 50.00, 'Y'),
(34, 'Jackson', 'Webb', '4758 W Historic Mitchell St', NULL, 'Milwaukee', 'WI', '53204', 'United States', '+1 (414) 555-0134', 'jackson.webb@example.com', '2025-07-30', 0.00, 'N'),
(35, 'Scarlett', 'Kim', '4895 W Lisbon Ave', NULL, 'Milwaukee', 'WI', '53212', 'United States', '+1 (414) 555-0135', 'scarlett.kim@example.com', '2025-08-14', 0.00, 'N'),
(36, 'Sebastian', 'Lee', '5032 N Astor St', 'Apt 12', 'Milwaukee', 'WI', '53202', 'United States', '+1 (414) 555-0136', 'sebastian.lee@example.com', '2025-08-29', 5.00, 'N'),
(37, 'Aiden', 'Torres', '5169 W National Ave', NULL, 'Milwaukee', 'WI', '53210', 'United States', '+1 (414) 555-0137', 'aiden.torres@example.com', '2025-09-13', 0.00, 'Y'),
(38, 'Chloe', 'Phillips', '5306 W Center St', NULL, 'Milwaukee', 'WI', '53216', 'United States', '+1 (414) 555-0138', 'chloe.phillips@example.com', '2025-09-28', 0.00, 'N'),
(39, 'Matthew', 'Morris', '5443 W Burleigh St', NULL, 'Milwaukee', 'WI', '53207', 'United States', '+1 (414) 555-0139', 'matthew.morris@example.com', '2025-10-13', 10.00, 'N'),
(40, 'Lily', 'Chen', '5580 W Wisconsin Ave', NULL, 'Milwaukee', 'WI', '53213', 'United States', '+1 (414) 555-0140', 'lily.chen@example.com', '2025-10-28', 0.00, 'N'),
(41, 'Alice', 'Schmidt', '5717 E Capitol Dr', NULL, 'Milwaukee', 'WI', '53203', 'United States', '+1 (414) 555-0141', 'alice.schmidt.41@example.com', '2025-11-12', 0.00, 'Y'),
(42, 'Marcus', 'Hall', '5854 S Kinnickinnic Ave', NULL, 'Milwaukee', 'WI', '53211', 'United States', '+1 (414) 555-0142', 'marcus.hall.42@example.com', '2025-11-27', 15.00, 'N'),
(43, 'Sofia', 'Carter', '5991 E North Ave', NULL, 'Milwaukee', 'WI', '53218', 'United States', '+1 (414) 555-0143', 'sofia.carter.43@example.com', '2025-12-12', 0.00, 'N'),
(44, 'David', 'Parker', '6128 W Vliet St', 'Suite 200', 'Milwaukee', 'WI', '53208', 'United States', '+1 (414) 555-0144', 'david.parker.44@example.com', '2025-12-27', 0.00, 'N'),
(45, 'Priya', 'Reed', '6265 E Brady St', NULL, 'Milwaukee', 'WI', '53215', 'United States', '+1 (414) 555-0145', 'priya.reed.45@example.com', '2026-01-11', 20.00, 'Y'),
(46, 'James', 'O''Brien', '6402 S 6th St', NULL, 'Milwaukee', 'WI', '53204', 'United States', '+1 (414) 555-0146', 'james.obrien.46@example.com', '2026-01-26', 0.00, 'N'),
(47, 'Emily', 'Nguyen', '6539 E Locust St', NULL, 'Milwaukee', 'WI', '53212', 'United States', '+1 (414) 555-0147', 'emily.nguyen.47@example.com', '2026-02-10', 0.00, 'N'),
(48, 'Tyler', 'Allen', '6676 N Water St', NULL, 'Milwaukee', 'WI', '53202', 'United States', '+1 (414) 555-0148', 'tyler.allen.48@example.com', '2026-02-25', 25.00, 'N'),
(49, 'Grace', 'Perez', '6813 N 35th St', NULL, 'Milwaukee', 'WI', '53210', 'United States', '+1 (414) 555-0149', 'grace.perez.49@example.com', '2026-03-12', 0.00, 'Y'),
(50, 'Robert', 'Edwards', '6950 N Farwell Ave', NULL, 'Milwaukee', 'WI', '53216', 'United States', '+1 (414) 555-0150', 'robert.edwards.50@example.com', '2026-03-27', 0.00, 'N'),
(51, 'Hannah', 'Johnson', '7087 N Oakland Ave', NULL, 'Milwaukee', 'WI', '53207', 'United States', '+1 (414) 555-0151', 'hannah.johnson.51@example.com', '2026-04-11', 50.00, 'N'),
(52, 'Miguel', 'Brooks', '7224 S Logan Ave', 'Unit 9', 'Milwaukee', 'WI', '53213', 'United States', '+1 (414) 555-0152', 'miguel.brooks.52@example.com', '2026-04-26', 0.00, 'N'),
(53, 'Olivia', 'Martinez', '7361 N Humboldt Blvd', NULL, 'Milwaukee', 'WI', '53203', 'United States', '+1 (414) 555-0153', 'olivia.martinez.53@example.com', '2026-05-11', 0.00, 'Y'),
(54, 'Noah', 'Scott', '7498 N Downer Ave', NULL, 'Milwaukee', 'WI', '53211', 'United States', '+1 (414) 555-0154', 'noah.scott.54@example.com', '2026-05-26', 5.00, 'N'),
(55, 'Ava', 'Turner', '7635 N Prospect Ave', NULL, 'Milwaukee', 'WI', '53218', 'United States', '+1 (414) 555-0155', 'ava.turner.55@example.com', '2026-06-10', 0.00, 'N'),
(56, 'Liam', 'Stewart', '7772 S Howell Ave', NULL, 'Milwaukee', 'WI', '53208', 'United States', '+1 (414) 555-0156', 'liam.stewart.56@example.com', '2026-06-25', 0.00, 'N'),
(57, 'Isabella', 'Ramirez', '7909 S 27th St', NULL, 'Milwaukee', 'WI', '53215', 'United States', '+1 (414) 555-0157', 'isabella.ramirez.57@example.com', '2026-07-10', 10.00, 'Y'),
(58, 'Mason', 'Fitzgerald', '8046 W Forest Home Ave', NULL, 'Milwaukee', 'WI', '53204', 'United States', '+1 (414) 555-0158', 'mason.fitzgerald.58@example.com', '2026-07-25', 0.00, 'N'),
(59, 'Sophia', 'Walker', '8183 W Historic Mitchell St', NULL, 'Milwaukee', 'WI', '53212', 'United States', '+1 (414) 555-0159', 'sophia.walker.59@example.com', '2026-08-09', 0.00, 'N'),
(60, 'Ethan', 'Nelson', '8320 W Lisbon Ave', 'Unit 3', 'Milwaukee', 'WI', '53202', 'United States', '+1 (414) 555-0160', 'ethan.nelson.60@example.com', '2026-08-24', 15.00, 'N');

-- Staff-only notes on a handful of members. Set with a follow-up UPDATE
-- rather than a column on the INSERT above, since only 6 of the 60 have one
-- and 54 trailing NULLs would bury the rows that matter. private_notes is
-- never shown to members (see the Membership page's detail view).
UPDATE wp_members SET private_notes = 'Prefers pickup after 5pm on weekdays; called ahead twice to arrange it.' WHERE member_id = 3;
UPDATE wp_members SET private_notes = 'Returned the table saw with a chipped blade in May 2026 and paid for the replacement without being asked. Good standing.' WHERE member_id = 24;
UPDATE wp_members SET private_notes = 'Account locked 2026-09-29. Two overdue notices mailed to the address on file came back undeliverable and the phone number is disconnected. Unlock once they bring in a current proof of address.' WHERE member_id = 31;
UPDATE wp_members SET private_notes = 'Two overdue returns in a row. Reminded about the 3-week limit on 2026-08-07. No issues since.' WHERE member_id = 39;
UPDATE wp_members SET private_notes = 'Runs a neighborhood repair cafe; often borrows in bulk for events. Coordinate ahead for large pickups.' WHERE member_id = 44;
UPDATE wp_members SET private_notes = 'Phone number on file is a shared household line, so ask for Mason by name.' WHERE member_id = 58;

-- Profile photos taken at the desk, on 32 of the 60 members. Spread across
-- every verification state in section 2 (both scans, one scan, none), since
-- having a photo says nothing about verification. Hosted on picsum.photos
-- like the tool photos, seeded by member_id so each member keeps the same
-- image every time this file is loaded.
UPDATE wp_members
    SET profile_photo_url = CONCAT('https://picsum.photos/seed/member-', member_id, '/400/400')
    WHERE member_id IN (1, 2, 3, 4, 5, 7, 9, 11, 13, 14, 17, 19, 21, 22, 24, 25,
                        27, 29, 30, 33, 35, 37, 39, 41, 43, 44, 45, 49, 50, 53, 57, 59);

-- One locked member, the way mtl_lock_member() leaves them: locked_at is the
-- only thing that marks it, and their one active reservation was closed as
-- 'member_locked' at the same moment (reservation #27, section 7B). Member 31
-- is used because they have no active loan, so the lock blocks nothing that
-- is already out, and because they have a photo ID but no proof of address
-- on file, which is what the reason in their private_notes above is about.
UPDATE wp_members SET locked_at = '2026-09-29 14:20:00' WHERE member_id = 31;

-- One deleted member, written the way mtl_delete_or_anonymize_member() leaves
-- a row: the record survives so their loan history stays attached, but every
-- identifying field is replaced and anonymized_at is stamped. Member 16 is
-- used because they hold no verification row (the delete path removes one),
-- no profile photo (the delete path clears it), no trainings, and no active
-- loan or reservation, so nothing else in this file has to bend around it.
-- Their returned loan #20 stays, and now reads as belonging to "Former
-- Member", which is the whole point of anonymizing rather than deleting.
UPDATE wp_members SET
    first_name = 'Former', last_name = 'Member',
    address_line1 = '(removed)', address_line2 = NULL,
    city = '(removed)', state = 'N/A', zip_code = '00000', country = 'United States',
    phone_number = '(removed)', email = 'deleted-member-16@example.invalid',
    private_notes = NULL, anonymized_at = '2026-09-18 10:05:00'
    WHERE member_id = 16;

-- ==========================================
-- 2. MEMBER VERIFICATIONS (49 rows: 39 with both scans, 10 with one)
--    A member counts as verified only with BOTH scans on file
--    (mtl_verification_urls_complete()), so the Membership page shows three
--    groups without editing anything:
--      * Both scans (39): verified.
--      * One scan (10): saved, but still "Not Verified". Photo ID only:
--        4, 20, 27, 31, 49, 56. Proof of address only: 12, 38, 42, 53.
--      * No row at all (11): 8, 16, 24, 28, 32, 36, 40, 44, 48, 52, 60.
--        Member 16 is the deleted member, whose row the delete path removed.
-- ==========================================
INSERT INTO wp_member_verifications
    (member_id, photo_id_scan_url, address_proof_scan_url, verified_at) VALUES
(1, 'https://docs.example.com/scans/id-0001.jpg', 'https://docs.example.com/scans/addr-0001.pdf', '2024-03-22 09:07:00'),
(2, 'https://docs.example.com/scans/id-0002.jpg', 'https://docs.example.com/scans/addr-0002.pdf', '2024-04-06 09:14:00'),
(3, 'https://docs.example.com/scans/id-0003.jpg', 'https://docs.example.com/scans/addr-0003.pdf', '2024-04-21 09:21:00'),
(4, 'https://docs.example.com/scans/id-0004.jpg', NULL, '2024-05-06 09:28:00'),
(5, 'https://docs.example.com/scans/id-0005.jpg', 'https://docs.example.com/scans/addr-0005.pdf', '2024-05-21 09:35:00'),
(6, 'https://docs.example.com/scans/id-0006.jpg', 'https://docs.example.com/scans/addr-0006.pdf', '2024-06-05 09:42:00'),
(7, 'https://docs.example.com/scans/id-0007.jpg', 'https://docs.example.com/scans/addr-0007.pdf', '2024-06-20 09:49:00'),
(9, 'https://docs.example.com/scans/id-0009.jpg', 'https://docs.example.com/scans/addr-0009.pdf', '2024-07-20 10:03:00'),
(10, 'https://docs.example.com/scans/id-0010.jpg', 'https://docs.example.com/scans/addr-0010.pdf', '2024-08-04 10:10:00'),
(11, 'https://docs.example.com/scans/id-0011.jpg', 'https://docs.example.com/scans/addr-0011.pdf', '2024-08-19 10:17:00'),
(12, NULL, 'https://docs.example.com/scans/addr-0012.pdf', '2024-09-03 10:24:00'),
(13, 'https://docs.example.com/scans/id-0013.jpg', 'https://docs.example.com/scans/addr-0013.pdf', '2024-09-18 10:31:00'),
(14, 'https://docs.example.com/scans/id-0014.jpg', 'https://docs.example.com/scans/addr-0014.pdf', '2024-10-03 10:38:00'),
(15, 'https://docs.example.com/scans/id-0015.jpg', 'https://docs.example.com/scans/addr-0015.pdf', '2024-10-18 10:45:00'),
(17, 'https://docs.example.com/scans/id-0017.jpg', 'https://docs.example.com/scans/addr-0017.pdf', '2024-11-17 10:59:00'),
(18, 'https://docs.example.com/scans/id-0018.jpg', 'https://docs.example.com/scans/addr-0018.pdf', '2024-12-02 11:06:00'),
(19, 'https://docs.example.com/scans/id-0019.jpg', 'https://docs.example.com/scans/addr-0019.pdf', '2024-12-17 11:13:00'),
(20, 'https://docs.example.com/scans/id-0020.jpg', NULL, '2025-01-01 11:20:00'),
(21, 'https://docs.example.com/scans/id-0021.jpg', 'https://docs.example.com/scans/addr-0021.pdf', '2025-01-16 11:27:00'),
(22, 'https://docs.example.com/scans/id-0022.jpg', 'https://docs.example.com/scans/addr-0022.pdf', '2025-01-31 11:34:00'),
(23, 'https://docs.example.com/scans/id-0023.jpg', 'https://docs.example.com/scans/addr-0023.pdf', '2025-02-15 11:41:00'),
(25, 'https://docs.example.com/scans/id-0025.jpg', 'https://docs.example.com/scans/addr-0025.pdf', '2025-03-17 11:55:00'),
(26, 'https://docs.example.com/scans/id-0026.jpg', 'https://docs.example.com/scans/addr-0026.pdf', '2025-04-01 12:02:00'),
(27, 'https://docs.example.com/scans/id-0027.jpg', NULL, '2025-04-16 12:09:00'),
(29, 'https://docs.example.com/scans/id-0029.jpg', 'https://docs.example.com/scans/addr-0029.pdf', '2025-05-16 12:23:00'),
(30, 'https://docs.example.com/scans/id-0030.jpg', 'https://docs.example.com/scans/addr-0030.pdf', '2025-05-31 12:30:00'),
(31, 'https://docs.example.com/scans/id-0031.jpg', NULL, '2025-06-15 12:37:00'),
(33, 'https://docs.example.com/scans/id-0033.jpg', 'https://docs.example.com/scans/addr-0033.pdf', '2025-07-15 12:51:00'),
(34, 'https://docs.example.com/scans/id-0034.jpg', 'https://docs.example.com/scans/addr-0034.pdf', '2025-07-30 12:58:00'),
(35, 'https://docs.example.com/scans/id-0035.jpg', 'https://docs.example.com/scans/addr-0035.pdf', '2025-08-14 13:05:00'),
(37, 'https://docs.example.com/scans/id-0037.jpg', 'https://docs.example.com/scans/addr-0037.pdf', '2025-09-13 13:19:00'),
(38, NULL, 'https://docs.example.com/scans/addr-0038.pdf', '2025-09-28 13:26:00'),
(39, 'https://docs.example.com/scans/id-0039.jpg', 'https://docs.example.com/scans/addr-0039.pdf', '2025-10-13 13:33:00'),
(41, 'https://docs.example.com/scans/id-0041.jpg', 'https://docs.example.com/scans/addr-0041.pdf', '2025-11-12 13:47:00'),
(42, NULL, 'https://docs.example.com/scans/addr-0042.pdf', '2025-11-27 13:54:00'),
(43, 'https://docs.example.com/scans/id-0043.jpg', 'https://docs.example.com/scans/addr-0043.pdf', '2025-12-12 14:01:00'),
(45, 'https://docs.example.com/scans/id-0045.jpg', 'https://docs.example.com/scans/addr-0045.pdf', '2026-01-11 14:15:00'),
(46, 'https://docs.example.com/scans/id-0046.jpg', 'https://docs.example.com/scans/addr-0046.pdf', '2026-01-26 14:22:00'),
(47, 'https://docs.example.com/scans/id-0047.jpg', 'https://docs.example.com/scans/addr-0047.pdf', '2026-02-10 14:29:00'),
(49, 'https://docs.example.com/scans/id-0049.jpg', NULL, '2026-03-12 14:43:00'),
(50, 'https://docs.example.com/scans/id-0050.jpg', 'https://docs.example.com/scans/addr-0050.pdf', '2026-03-27 14:50:00'),
(51, 'https://docs.example.com/scans/id-0051.jpg', 'https://docs.example.com/scans/addr-0051.pdf', '2026-04-11 14:57:00'),
(53, NULL, 'https://docs.example.com/scans/addr-0053.pdf', '2026-05-11 15:11:00'),
(54, 'https://docs.example.com/scans/id-0054.jpg', 'https://docs.example.com/scans/addr-0054.pdf', '2026-05-26 15:18:00'),
(55, 'https://docs.example.com/scans/id-0055.jpg', 'https://docs.example.com/scans/addr-0055.pdf', '2026-06-10 15:25:00'),
(56, 'https://docs.example.com/scans/id-0056.jpg', NULL, '2026-06-25 15:32:00'),
(57, 'https://docs.example.com/scans/id-0057.jpg', 'https://docs.example.com/scans/addr-0057.pdf', '2026-07-10 15:39:00'),
(58, 'https://docs.example.com/scans/id-0058.jpg', 'https://docs.example.com/scans/addr-0058.pdf', '2026-07-25 15:46:00'),
(59, 'https://docs.example.com/scans/id-0059.jpg', 'https://docs.example.com/scans/addr-0059.pdf', '2026-08-09 15:53:00');

-- ==========================================
-- 3. TOOL INVENTORY (125)
--    donated_by is always either NULL or the full name of a member who is
--    flagged has_donated_tools='Y', keeping the donor leaderboard honest.
--    location is assigned by tool type, so every copy of a type shelves
--    together and a quick-filter search on a shelf label returns a handful of
--    rows rather than one. Bulky types live off-shelf ("Floor Rack B", "Yard
--    Bay 1", "Wall Rack, North Wall") the way they would in a real library.
--    Every tool has one, so the em-dash/hidden path for a tool with no
--    location on file is not exercised by this data; blank a row to see it.
-- ==========================================
INSERT INTO wp_tool_inventory
    (tool_id, tool_name, barcode, brand, description, components, photo_url, initial_cash_value, annual_depreciation_amount, donated_by, date_acquired, private_notes, location) VALUES
(1, 'Cordless Drill Driver', 'MTL-000001', 'DeWalt', 'Drilling holes and driving screws. Keyless chuck, battery-powered, comes with a bit set.', 'Battery, Charger, Bit Set, Carrying Case', 'https://picsum.photos/seed/tool-1/600/400', 103.99, 15.00, NULL, '2024-03-16', NULL, NULL),
(2, 'Circular Saw', 'MTL-000002', 'Makita', 'Corded 7-1/4 inch circular saw for straight cuts in lumber and plywood.', 'Rip Fence, Blade Wrench', 'https://picsum.photos/seed/tool-2/600/400', 136.00, 20.00, NULL, '2024-03-23', NULL, 'Aisle 1, Shelf 2'),
(3, 'Table Saw', 'MTL-000003', 'DeWalt', '10-inch jobsite table saw. In-person orientation required before first checkout.', 'Miter Gauge, Push Stick, Blade Guard, Stand', 'https://picsum.photos/seed/tool-3/600/400', 389.99, 45.00, 'Priya Reed', '2024-03-30', 'Blade wobbles slightly above 6000 RPM. Flagged to maintenance 2026-05-06, not yet resolved. Warn borrowers and inspect before checkout.', 'Floor Rack A'),
(4, 'Random Orbit Sander', 'MTL-000004', 'Bosch', '5-inch random orbit sander for smooth finishes. Bring your own sanding discs.', 'Dust Canister', 'https://picsum.photos/seed/tool-4/600/400', 61.00, 10.00, NULL, '2024-04-06', NULL, 'Aisle 1, Shelf 3'),
(5, 'Cordless Impact Driver', 'MTL-000005', 'Milwaukee', 'Compact impact driver for driving long screws and lag bolts.', 'Battery, Charger, Belt Clip', 'https://picsum.photos/seed/tool-5/600/400', 185.99, 20.00, NULL, '2024-04-13', NULL, 'Aisle 1, Shelf 1'),
(6, 'Hedge Trimmer', 'MTL-000006', 'Ryobi', 'Cordless 22-inch hedge trimmer for shrubs and bushes.', 'Battery, Charger, Blade Cover', 'https://picsum.photos/seed/tool-6/600/400', 97.00, 14.00, 'Grace Perez', '2024-04-20', NULL, 'Aisle 2, Shelf 1'),
(7, 'Gas Lawn Mower', 'MTL-000007', 'Honda', 'Self-propelled 21-inch gas mower. Drain fuel before returning.', 'Grass Bag, Side Discharge Chute', 'https://picsum.photos/seed/tool-7/600/400', 441.99, 56.00, NULL, '2024-04-27', NULL, 'Yard Bay 1'),
(8, 'Chainsaw', 'MTL-000008', 'Stihl', '16-inch chainsaw. In-person orientation and PPE required for checkout.', 'Bar Cover, Chain Oil Bottle, Scrench Tool', 'https://picsum.photos/seed/tool-8/600/400', 354.00, 42.00, NULL, '2024-05-04', 'Chain tension loosens after about 20 minutes of continuous use, so remind borrowers to check it partway through a job, not just at pickup.', 'Aisle 2, Shelf 2 (locked cabinet)'),
(9, 'Pipe Wrench Set', 'MTL-000009', 'Ridgid', 'Pair of 14-inch and 18-inch pipe wrenches for plumbing work.', NULL, 'https://picsum.photos/seed/tool-9/600/400', 60.99, 5.00, 'Olivia Martinez', '2024-05-11', NULL, 'Aisle 3, Shelf 1'),
(10, 'Drain Auger', 'MTL-000010', 'Ridgid', 'Hand-crank drain auger for clearing sink and tub clogs.', NULL, 'https://picsum.photos/seed/tool-10/600/400', 79.00, 7.00, NULL, '2024-05-18', NULL, 'Aisle 3, Shelf 2'),
(11, 'Digital Multimeter', 'MTL-000011', 'Klein', 'Auto-ranging multimeter for AC/DC voltage, current and resistance.', 'Test Leads, Carrying Pouch', 'https://picsum.photos/seed/tool-11/600/400', 193.99, 10.00, NULL, '2024-05-25', NULL, 'Aisle 4, Shelf 1'),
(12, 'OBD-II Code Reader', 'MTL-000012', 'Innova', 'Reads and clears check-engine codes on 1996+ vehicles.', 'USB Cable, Quick Reference Card', 'https://picsum.photos/seed/tool-12/600/400', 85.00, 10.00, 'Isabella Ramirez', '2024-06-01', NULL, 'Aisle 4, Shelf 2'),
(13, 'Paint Sprayer', 'MTL-000013', 'Wagner', 'HVLP paint sprayer for walls, fences and furniture. Clean thoroughly before returning.', 'Two Nozzles, Viscosity Cup, Cleaning Brush', 'https://picsum.photos/seed/tool-13/600/400', 108.99, 17.00, NULL, '2024-06-08', NULL, 'Aisle 5, Shelf 1'),
(14, 'Drywall Sander', 'MTL-000014', 'Porter-Cable', 'Corded drywall sander with a dust collection hose.', 'Dust Hose, Sanding Head', 'https://picsum.photos/seed/tool-14/600/400', 191.00, 24.00, NULL, '2024-06-15', NULL, 'Aisle 5, Shelf 2'),
(15, 'Concrete Mixer', 'MTL-000015', 'Kushlan', 'Portable 3.5 cu ft electric concrete mixer on wheels. Very heavy, so bring a truck.', NULL, 'https://picsum.photos/seed/tool-15/600/400', 334.99, 40.00, 'Mia Campbell', '2024-06-22', 'Takes two people to load into most trunks/hatchbacks. Keep the loading dolly from the back room paired with this one at pickup.', 'Yard Bay 3'),
(16, 'Pressure Washer', 'MTL-000016', 'Ryobi', 'Electric pressure washer for decks, siding and driveways.', 'Spray Wand, Three Nozzle Tips, Detergent Tank', 'https://picsum.photos/seed/tool-16/600/400', 358.00, 26.00, NULL, '2024-06-29', NULL, 'Floor Rack B'),
(17, 'Angle Grinder', 'MTL-000017', 'Makita', '4-1/2 inch corded angle grinder for cutting and grinding metal. PPE required.', 'Side Handle, Wheel Guard, Spanner Wrench', 'https://picsum.photos/seed/tool-17/600/400', 68.99, 11.00, NULL, '2024-07-06', NULL, 'Aisle 6, Shelf 1'),
(18, 'Socket Wrench Set', 'MTL-000018', 'Craftsman', '120-piece SAE/metric socket set with ratchets.', 'Carrying Case', 'https://picsum.photos/seed/tool-18/600/400', 132.00, 6.00, 'Amelia Young', '2024-07-13', NULL, 'Aisle 4, Shelf 3'),
(19, 'Appliance Dolly', 'MTL-000019', 'Milwaukee', 'Heavy-duty appliance hand truck with straps and stair skids. Rated 800 lbs.', 'Ratchet Strap', 'https://picsum.photos/seed/tool-19/600/400', 145.99, 13.00, NULL, '2024-07-20', NULL, 'Floor Rack C'),
(20, 'Extension Ladder', 'MTL-000020', 'Werner', 'Aluminum extension ladder. Two-person carry recommended.', NULL, 'https://picsum.photos/seed/tool-20/600/400', 289.00, 17.00, NULL, '2024-07-27', 'Missing a rubber foot cap on the left rail as of 2026-05. Replacement ordered but not in yet; mention it to whoever borrows this.', 'Wall Rack, North Wall'),
(21, 'Tile Saw', 'MTL-000021', 'DeWalt', 'Wet tile saw for cutting ceramic and porcelain. Requires a water supply.', 'Water Tray, Rip Guide, Blade', 'https://picsum.photos/seed/tool-21/600/400', 212.99, 24.00, 'Evelyn Nowak', '2024-08-03', NULL, 'Aisle 6, Shelf 3'),
(22, 'Stud Finder', 'MTL-000022', 'Zircon', 'Electronic stud finder for locating studs and joists behind drywall.', NULL, 'https://picsum.photos/seed/tool-22/600/400', 65.00, 4.00, NULL, '2024-08-10', NULL, 'Aisle 4, Shelf 1'),
(23, 'Reciprocating Saw', 'MTL-000023', 'Milwaukee', 'Cordless reciprocating saw for demolition and pruning cuts.', 'Battery, Charger, Wood Blade, Metal Blade', 'https://picsum.photos/seed/tool-23/600/400', 126.99, 18.00, NULL, '2024-08-17', NULL, 'Aisle 1, Shelf 2'),
(24, 'Wet/Dry Vacuum', 'MTL-000024', 'Shop-Vac', '12-gallon wet/dry shop vacuum for cleanup jobs.', 'Hose, Crevice Tool, Filter', 'https://picsum.photos/seed/tool-24/600/400', 109.00, 11.00, 'Ella Collins', '2024-08-24', NULL, 'Floor Rack B'),
(25, 'Post Hole Digger', 'MTL-000025', 'Fiskars', 'Manual clamshell post hole digger for fence and deck projects.', NULL, 'https://picsum.photos/seed/tool-25/600/400', 85.99, 5.00, NULL, '2024-08-31', NULL, 'Wall Rack, North Wall'),
(26, 'Leaf Blower', 'MTL-000026', 'EGO', 'Cordless leaf blower for clearing yards, walks and gutters.', 'Battery, Charger, Nozzle', 'https://picsum.photos/seed/tool-26/600/400', 186.00, 18.00, NULL, '2024-09-07', NULL, 'Aisle 2, Shelf 3'),
(27, 'Rotary Hammer', 'MTL-000027', 'Bosch', 'SDS-plus rotary hammer for drilling and light chipping in concrete.', 'SDS Bits, Side Handle, Case', 'https://picsum.photos/seed/tool-27/600/400', 290.99, 26.00, 'Aiden Torres', '2024-09-14', NULL, 'Aisle 6, Shelf 2'),
(28, 'Wheelbarrow', 'MTL-000028', 'Jackson', '6 cu ft steel-tray wheelbarrow for hauling soil, mulch and concrete.', NULL, 'https://picsum.photos/seed/tool-28/600/400', 80.00, 9.00, NULL, '2024-09-21', NULL, 'Yard Bay 2'),
(29, 'Cordless Drill Driver', 'MTL-000029', 'Milwaukee', 'Drilling holes and driving screws. Keyless chuck, battery-powered, comes with a bit set.', 'Battery, Charger, Bit Set, Carrying Case', 'https://picsum.photos/seed/tool-29/600/400', 112.99, 16.00, NULL, '2024-09-28', NULL, 'Aisle 1, Shelf 1'),
(30, 'Circular Saw', 'MTL-000030', 'DeWalt', 'Corded 7-1/4 inch circular saw for straight cuts in lumber and plywood.', 'Rip Fence, Blade Wrench', 'https://picsum.photos/seed/tool-30/600/400', 176.00, 18.00, 'Alice Schmidt', '2024-10-05', NULL, 'Aisle 1, Shelf 2'),
(31, 'Table Saw', 'MTL-000031', 'Bosch', '10-inch jobsite table saw. In-person orientation required before first checkout.', 'Miter Gauge, Push Stick, Blade Guard, Stand', 'https://picsum.photos/seed/tool-31/600/400', 482.99, 46.00, NULL, '2024-10-12', NULL, 'Floor Rack A'),
(32, 'Random Orbit Sander', 'MTL-000032', 'DeWalt', '5-inch random orbit sander for smooth finishes. Bring your own sanding discs.', 'Dust Canister', 'https://picsum.photos/seed/tool-32/600/400', 68.00, 11.00, NULL, '2024-10-19', NULL, 'Aisle 1, Shelf 3'),
(33, 'Cordless Impact Driver', 'MTL-000033', 'DeWalt', 'Compact impact driver for driving long screws and lag bolts.', 'Battery, Charger, Belt Clip', 'https://picsum.photos/seed/tool-33/600/400', 123.99, 18.00, 'Priya Reed', '2024-10-26', NULL, 'Aisle 1, Shelf 1'),
(34, 'Hedge Trimmer', 'MTL-000034', 'EGO', 'Cordless 22-inch hedge trimmer for shrubs and bushes.', 'Battery, Charger, Blade Cover', 'https://picsum.photos/seed/tool-34/600/400', 106.00, 15.00, NULL, '2024-11-02', NULL, 'Aisle 2, Shelf 1'),
(35, 'Gas Lawn Mower', 'MTL-000035', 'Toro', 'Self-propelled 21-inch gas mower. Drain fuel before returning.', 'Grass Bag, Side Discharge Chute', 'https://picsum.photos/seed/tool-35/600/400', 463.99, 57.00, NULL, '2024-11-09', NULL, 'Yard Bay 1'),
(36, 'Chainsaw', 'MTL-000036', 'Husqvarna', '16-inch chainsaw. In-person orientation and PPE required for checkout.', 'Bar Cover, Chain Oil Bottle, Scrench Tool', 'https://picsum.photos/seed/tool-36/600/400', 376.00, 40.00, 'Grace Perez', '2024-11-16', 'Donor asked to be told before this one is ever sold or scrapped, even after end of life; it has sentimental value, was her late husband''s.', 'Aisle 2, Shelf 2 (locked cabinet)'),
(37, 'Pipe Wrench Set', 'MTL-000037', 'Craftsman', 'Pair of 14-inch and 18-inch pipe wrenches for plumbing work.', NULL, 'https://picsum.photos/seed/tool-37/600/400', 67.99, 6.00, NULL, '2024-11-23', NULL, 'Aisle 3, Shelf 1'),
(38, 'Drain Auger', 'MTL-000038', 'Ryobi', 'Hand-crank drain auger for clearing sink and tub clogs.', NULL, 'https://picsum.photos/seed/tool-38/600/400', 79.00, 8.00, NULL, '2024-11-30', NULL, 'Aisle 3, Shelf 2'),
(39, 'Digital Multimeter', 'MTL-000039', 'Fluke', 'Auto-ranging multimeter for AC/DC voltage, current and resistance.', 'Test Leads, Carrying Pouch', 'https://picsum.photos/seed/tool-39/600/400', 74.99, 8.00, 'Olivia Martinez', '2024-12-07', NULL, 'Aisle 4, Shelf 1'),
(40, 'OBD-II Code Reader', 'MTL-000040', 'Autel', 'Reads and clears check-engine codes on 1996+ vehicles.', 'USB Cable, Quick Reference Card', 'https://picsum.photos/seed/tool-40/600/400', 167.00, 11.00, NULL, '2024-12-14', NULL, 'Aisle 4, Shelf 2'),
(41, 'Paint Sprayer', 'MTL-000041', 'Graco', 'HVLP paint sprayer for walls, fences and furniture. Clean thoroughly before returning.', 'Two Nozzles, Viscosity Cup, Cleaning Brush', 'https://picsum.photos/seed/tool-41/600/400', 150.99, 18.00, NULL, '2024-12-21', NULL, 'Aisle 5, Shelf 1'),
(42, 'Drywall Sander', 'MTL-000042', 'WEN', 'Corded drywall sander with a dust collection hose.', 'Dust Hose, Sanding Head', 'https://picsum.photos/seed/tool-42/600/400', 222.00, 22.00, 'Isabella Ramirez', '2024-12-28', NULL, 'Aisle 5, Shelf 2'),
(43, 'Concrete Mixer', 'MTL-000043', 'YARDMAX', 'Portable 3.5 cu ft electric concrete mixer on wheels. Very heavy, so bring a truck.', NULL, 'https://picsum.photos/seed/tool-43/600/400', 376.99, 41.00, NULL, '2025-01-04', NULL, 'Yard Bay 3'),
(44, 'Pressure Washer', 'MTL-000044', 'Sun Joe', 'Electric pressure washer for decks, siding and driveways.', 'Spray Wand, Three Nozzle Tips, Detergent Tank', 'https://picsum.photos/seed/tool-44/600/400', 300.00, 27.00, NULL, '2025-01-11', 'Detergent tank cap does not seal well, so always hose it down and let it dry upside-down before shelving, or it drips in storage.', 'Floor Rack B'),
(45, 'Angle Grinder', 'MTL-000045', 'DeWalt', '4-1/2 inch corded angle grinder for cutting and grinding metal. PPE required.', 'Side Handle, Wheel Guard, Spanner Wrench', 'https://picsum.photos/seed/tool-45/600/400', 77.99, 9.00, 'Alice Schmidt', '2025-01-18', NULL, 'Aisle 6, Shelf 1'),
(46, 'Socket Wrench Set', 'MTL-000046', 'GearWrench', '120-piece SAE/metric socket set with ratchets.', 'Carrying Case', 'https://picsum.photos/seed/tool-46/600/400', 132.00, 7.00, NULL, '2025-01-25', NULL, 'Aisle 4, Shelf 3'),
(47, 'Appliance Dolly', 'MTL-000047', 'Harper', 'Heavy-duty appliance hand truck with straps and stair skids. Rated 800 lbs.', 'Ratchet Strap', 'https://picsum.photos/seed/tool-47/600/400', 176.99, 14.00, NULL, '2025-02-01', NULL, 'Floor Rack C'),
(48, 'Extension Ladder', 'MTL-000048', 'Louisville', 'Aluminum extension ladder. Two-person carry recommended.', NULL, 'https://picsum.photos/seed/tool-48/600/400', 200.00, 15.00, 'Priya Reed', '2025-02-08', NULL, 'Wall Rack, North Wall'),
(49, 'Tile Saw', 'MTL-000049', 'QEP', 'Wet tile saw for cutting ceramic and porcelain. Requires a water supply.', 'Water Tray, Rip Guide, Blade', 'https://picsum.photos/seed/tool-49/600/400', 154.99, 25.00, NULL, '2025-02-15', NULL, 'Aisle 6, Shelf 3'),
(50, 'Stud Finder', 'MTL-000050', 'Franklin', 'Electronic stud finder for locating studs and joists behind drywall.', NULL, 'https://picsum.photos/seed/tool-50/600/400', 60.00, 5.00, NULL, '2025-02-22', NULL, 'Aisle 4, Shelf 1'),
(51, 'Reciprocating Saw', 'MTL-000051', 'DeWalt', 'Cordless reciprocating saw for demolition and pruning cuts.', 'Battery, Charger, Wood Blade, Metal Blade', 'https://picsum.photos/seed/tool-51/600/400', 126.99, 16.00, 'Grace Perez', '2025-03-01', NULL, 'Aisle 1, Shelf 2'),
(52, 'Wet/Dry Vacuum', 'MTL-000052', 'Ridgid', '12-gallon wet/dry shop vacuum for cleanup jobs.', 'Hose, Crevice Tool, Filter', 'https://picsum.photos/seed/tool-52/600/400', 109.00, 12.00, NULL, '2025-03-08', NULL, 'Floor Rack B'),
(53, 'Post Hole Digger', 'MTL-000053', 'Seymour', 'Manual clamshell post hole digger for fence and deck projects.', NULL, 'https://picsum.photos/seed/tool-53/600/400', 57.99, 6.00, NULL, '2025-03-15', NULL, 'Wall Rack, North Wall'),
(54, 'Leaf Blower', 'MTL-000054', 'Ryobi', 'Cordless leaf blower for clearing yards, walks and gutters.', 'Battery, Charger, Nozzle', 'https://picsum.photos/seed/tool-54/600/400', 187.00, 16.00, 'Olivia Martinez', '2025-03-22', NULL, 'Aisle 2, Shelf 3'),
(55, 'Rotary Hammer', 'MTL-000055', 'Milwaukee', 'SDS-plus rotary hammer for drilling and light chipping in concrete.', 'SDS Bits, Side Handle, Case', 'https://picsum.photos/seed/tool-55/600/400', 232.99, 27.00, NULL, '2025-03-29', NULL, 'Aisle 6, Shelf 2'),
(56, 'Wheelbarrow', 'MTL-000056', 'True Temper', '6 cu ft steel-tray wheelbarrow for hauling soil, mulch and concrete.', NULL, 'https://picsum.photos/seed/tool-56/600/400', 80.00, 10.00, NULL, '2025-04-05', NULL, 'Yard Bay 2'),
(57, 'Cordless Drill Driver', 'MTL-000057', 'Makita', 'Drilling holes and driving screws. Keyless chuck, battery-powered, comes with a bit set.', 'Battery, Charger, Bit Set, Carrying Case', 'https://picsum.photos/seed/tool-57/600/400', 121.99, 14.00, 'Isabella Ramirez', '2025-04-12', NULL, 'Aisle 1, Shelf 1'),
(58, 'Circular Saw', 'MTL-000058', 'SKILSAW', 'Corded 7-1/4 inch circular saw for straight cuts in lumber and plywood.', 'Rip Fence, Blade Wrench', 'https://picsum.photos/seed/tool-58/600/400', 135.00, 19.00, NULL, '2025-04-19', NULL, 'Aisle 1, Shelf 2'),
(59, 'Table Saw', 'MTL-000059', 'SawStop', '10-inch jobsite table saw. In-person orientation required before first checkout.', 'Miter Gauge, Push Stick, Blade Guard, Stand', 'https://picsum.photos/seed/tool-59/600/400', 575.99, 47.00, NULL, '2025-04-26', NULL, 'Floor Rack A'),
(60, 'Random Orbit Sander', 'MTL-000060', 'Makita', '5-inch random orbit sander for smooth finishes. Bring your own sanding discs.', 'Dust Canister', 'https://picsum.photos/seed/tool-60/600/400', 75.00, 9.00, 'Mia Campbell', '2025-05-03', NULL, 'Aisle 1, Shelf 3'),
(61, 'Cordless Impact Driver', 'MTL-000061', 'Makita', 'Compact impact driver for driving long screws and lag bolts.', 'Battery, Charger, Belt Clip', 'https://picsum.photos/seed/tool-61/600/400', 132.99, 19.00, NULL, '2025-05-10', NULL, 'Aisle 1, Shelf 1'),
(62, 'Hedge Trimmer', 'MTL-000062', 'Black+Decker', 'Cordless 22-inch hedge trimmer for shrubs and bushes.', 'Battery, Charger, Blade Cover', 'https://picsum.photos/seed/tool-62/600/400', 115.00, 16.00, NULL, '2025-05-17', NULL, 'Aisle 2, Shelf 1'),
(63, 'Gas Lawn Mower', 'MTL-000063', 'Craftsman', 'Self-propelled 21-inch gas mower. Drain fuel before returning.', 'Grass Bag, Side Discharge Chute', 'https://picsum.photos/seed/tool-63/600/400', 485.99, 55.00, 'Amelia Young', '2025-05-24', NULL, 'Yard Bay 1'),
(64, 'Chainsaw', 'MTL-000064', 'EGO', '16-inch chainsaw. In-person orientation and PPE required for checkout.', 'Bar Cover, Chain Oil Bottle, Scrench Tool', 'https://picsum.photos/seed/tool-64/600/400', 398.00, 41.00, NULL, '2025-05-31', NULL, 'Aisle 2, Shelf 2 (locked cabinet)'),
(65, 'Pipe Wrench Set', 'MTL-000065', 'Ridgid', 'Pair of 14-inch and 18-inch pipe wrenches for plumbing work.', NULL, 'https://picsum.photos/seed/tool-65/600/400', 74.99, 7.00, NULL, '2025-06-07', NULL, 'Aisle 3, Shelf 1'),
(66, 'Drain Auger', 'MTL-000066', 'Ridgid', 'Hand-crank drain auger for clearing sink and tub clogs.', NULL, 'https://picsum.photos/seed/tool-66/600/400', 79.00, 6.00, 'Evelyn Nowak', '2025-06-14', NULL, 'Aisle 3, Shelf 2'),
(67, 'Digital Multimeter', 'MTL-000067', 'Klein', 'Auto-ranging multimeter for AC/DC voltage, current and resistance.', 'Test Leads, Carrying Pouch', 'https://picsum.photos/seed/tool-67/600/400', 116.99, 9.00, NULL, '2025-06-21', NULL, 'Aisle 4, Shelf 1'),
(68, 'OBD-II Code Reader', 'MTL-000068', 'Innova', 'Reads and clears check-engine codes on 1996+ vehicles.', 'USB Cable, Quick Reference Card', 'https://picsum.photos/seed/tool-68/600/400', 108.00, 12.00, NULL, '2025-06-28', NULL, 'Aisle 4, Shelf 2'),
(69, 'Paint Sprayer', 'MTL-000069', 'Wagner', 'HVLP paint sprayer for walls, fences and furniture. Clean thoroughly before returning.', 'Two Nozzles, Viscosity Cup, Cleaning Brush', 'https://picsum.photos/seed/tool-69/600/400', 192.99, 16.00, 'Ella Collins', '2025-07-05', NULL, 'Aisle 5, Shelf 1'),
(70, 'Drywall Sander', 'MTL-000070', 'Porter-Cable', 'Corded drywall sander with a dust collection hose.', 'Dust Hose, Sanding Head', 'https://picsum.photos/seed/tool-70/600/400', 142.00, 23.00, NULL, '2025-07-12', NULL, 'Aisle 5, Shelf 2'),
(71, 'Concrete Mixer', 'MTL-000071', 'Kushlan', 'Portable 3.5 cu ft electric concrete mixer on wheels. Very heavy, so bring a truck.', NULL, 'https://picsum.photos/seed/tool-71/600/400', 418.99, 42.00, NULL, '2025-07-19', 'Motor runs noticeably louder than our other mixer (tool #15), still within spec per the manual, but worth a heads-up for noise-sensitive neighborhoods.', 'Yard Bay 3'),
(72, 'Pressure Washer', 'MTL-000072', 'Simpson', 'Electric pressure washer for decks, siding and driveways.', 'Spray Wand, Three Nozzle Tips, Detergent Tank', 'https://picsum.photos/seed/tool-72/600/400', 242.00, 25.00, 'Aiden Torres', '2025-07-26', NULL, 'Floor Rack B'),
(73, 'Angle Grinder', 'MTL-000073', 'Milwaukee', '4-1/2 inch corded angle grinder for cutting and grinding metal. PPE required.', 'Side Handle, Wheel Guard, Spanner Wrench', 'https://picsum.photos/seed/tool-73/600/400', 86.99, 10.00, NULL, '2025-08-02', NULL, 'Aisle 6, Shelf 1'),
(74, 'Socket Wrench Set', 'MTL-000074', 'Craftsman', '120-piece SAE/metric socket set with ratchets.', 'Carrying Case', 'https://picsum.photos/seed/tool-74/600/400', 132.00, 8.00, NULL, '2025-08-09', NULL, 'Aisle 4, Shelf 3'),
(75, 'Appliance Dolly', 'MTL-000075', 'Milwaukee', 'Heavy-duty appliance hand truck with straps and stair skids. Rated 800 lbs.', 'Ratchet Strap', 'https://picsum.photos/seed/tool-75/600/400', 207.99, 12.00, 'Alice Schmidt', '2025-08-16', NULL, 'Floor Rack C'),
(76, 'Extension Ladder', 'MTL-000076', 'Werner', 'Aluminum extension ladder. Two-person carry recommended.', NULL, 'https://picsum.photos/seed/tool-76/600/400', 262.00, 16.00, NULL, '2025-08-23', NULL, 'Wall Rack, North Wall'),
(77, 'Tile Saw', 'MTL-000077', 'DeWalt', 'Wet tile saw for cutting ceramic and porcelain. Requires a water supply.', 'Water Tray, Rip Guide, Blade', 'https://picsum.photos/seed/tool-77/600/400', 307.99, 26.00, NULL, '2025-08-30', NULL, 'Aisle 6, Shelf 3'),
(78, 'Stud Finder', 'MTL-000078', 'Zircon', 'Electronic stud finder for locating studs and joists behind drywall.', NULL, 'https://picsum.photos/seed/tool-78/600/400', 55.00, 3.00, 'Priya Reed', '2025-09-06', NULL, 'Aisle 4, Shelf 1'),
(79, 'Reciprocating Saw', 'MTL-000079', 'Milwaukee', 'Cordless reciprocating saw for demolition and pruning cuts.', 'Battery, Charger, Wood Blade, Metal Blade', 'https://picsum.photos/seed/tool-79/600/400', 126.99, 17.00, NULL, '2025-09-13', NULL, 'Aisle 1, Shelf 2'),
(80, 'Wet/Dry Vacuum', 'MTL-000080', 'Shop-Vac', '12-gallon wet/dry shop vacuum for cleanup jobs.', 'Hose, Crevice Tool, Filter', 'https://picsum.photos/seed/tool-80/600/400', 109.00, 13.00, NULL, '2025-09-20', NULL, 'Floor Rack B'),
(81, 'Post Hole Digger', 'MTL-000081', 'Fiskars', 'Manual clamshell post hole digger for fence and deck projects.', NULL, 'https://picsum.photos/seed/tool-81/600/400', 85.99, 4.00, 'Grace Perez', '2025-09-27', NULL, 'Wall Rack, North Wall'),
(82, 'Leaf Blower', 'MTL-000082', 'Echo', 'Cordless leaf blower for clearing yards, walks and gutters.', 'Battery, Charger, Nozzle', 'https://picsum.photos/seed/tool-82/600/400', 188.00, 17.00, NULL, '2025-10-04', NULL, 'Aisle 2, Shelf 3'),
(83, 'Rotary Hammer', 'MTL-000083', 'Bosch', 'SDS-plus rotary hammer for drilling and light chipping in concrete.', 'SDS Bits, Side Handle, Case', 'https://picsum.photos/seed/tool-83/600/400', 174.99, 28.00, NULL, '2025-10-11', NULL, 'Aisle 6, Shelf 2'),
(84, 'Wheelbarrow', 'MTL-000084', 'Jackson', '6 cu ft steel-tray wheelbarrow for hauling soil, mulch and concrete.', NULL, 'https://picsum.photos/seed/tool-84/600/400', 80.00, 8.00, 'Olivia Martinez', '2025-10-18', NULL, 'Yard Bay 2'),
(85, 'Cordless Drill Driver', 'MTL-000085', 'Ryobi', 'Drilling holes and driving screws. Keyless chuck, battery-powered, comes with a bit set.', 'Battery, Charger, Bit Set, Carrying Case', 'https://picsum.photos/seed/tool-85/600/400', 130.99, 15.00, NULL, '2025-10-25', NULL, 'Aisle 1, Shelf 1'),
(86, 'Circular Saw', 'MTL-000086', 'Bosch', 'Corded 7-1/4 inch circular saw for straight cuts in lumber and plywood.', 'Rip Fence, Blade Wrench', 'https://picsum.photos/seed/tool-86/600/400', 175.00, 20.00, NULL, '2025-11-01', NULL, 'Aisle 1, Shelf 2'),
(87, 'Table Saw', 'MTL-000087', 'DeWalt', '10-inch jobsite table saw. In-person orientation required before first checkout.', 'Miter Gauge, Push Stick, Blade Guard, Stand', 'https://picsum.photos/seed/tool-87/600/400', 397.99, 45.00, 'Isabella Ramirez', '2025-11-08', 'Donated barely used, still has the original blade guard sticker on it. Donor runs a woodworking shop downtown and offered to sharpen/service blades for us at cost; see Setup page contacts.', 'Floor Rack A'),
(88, 'Random Orbit Sander', 'MTL-000088', 'Bosch', '5-inch random orbit sander for smooth finishes. Bring your own sanding discs.', 'Dust Canister', 'https://picsum.photos/seed/tool-88/600/400', 82.00, 10.00, NULL, '2025-11-15', NULL, 'Aisle 1, Shelf 3'),
(89, 'Cordless Impact Driver', 'MTL-000089', 'Ryobi', 'Compact impact driver for driving long screws and lag bolts.', 'Battery, Charger, Belt Clip', 'https://picsum.photos/seed/tool-89/600/400', 141.99, 20.00, NULL, '2025-11-22', NULL, 'Aisle 1, Shelf 1'),
(90, 'Hedge Trimmer', 'MTL-000090', 'Ryobi', 'Cordless 22-inch hedge trimmer for shrubs and bushes.', 'Battery, Charger, Blade Cover', 'https://picsum.photos/seed/tool-90/600/400', 124.00, 14.00, 'Alice Schmidt', '2025-11-29', NULL, 'Aisle 2, Shelf 1'),
(91, 'Gas Lawn Mower', 'MTL-000091', 'Honda', 'Self-propelled 21-inch gas mower. Drain fuel before returning.', 'Grass Bag, Side Discharge Chute', 'https://picsum.photos/seed/tool-91/600/400', 507.99, 56.00, NULL, '2025-12-06', NULL, 'Yard Bay 1'),
(92, 'Chainsaw', 'MTL-000092', 'Stihl', '16-inch chainsaw. In-person orientation and PPE required for checkout.', 'Bar Cover, Chain Oil Bottle, Scrench Tool', 'https://picsum.photos/seed/tool-92/600/400', 420.00, 42.00, NULL, '2025-12-13', NULL, 'Aisle 2, Shelf 2 (locked cabinet)'),
(93, 'Pipe Wrench Set', 'MTL-000093', 'Craftsman', 'Pair of 14-inch and 18-inch pipe wrenches for plumbing work.', NULL, 'https://picsum.photos/seed/tool-93/600/400', 81.99, 5.00, 'Priya Reed', '2025-12-20', NULL, 'Aisle 3, Shelf 1'),
(94, 'Drain Auger', 'MTL-000094', 'Ryobi', 'Hand-crank drain auger for clearing sink and tub clogs.', NULL, 'https://picsum.photos/seed/tool-94/600/400', 79.00, 7.00, NULL, '2025-12-27', NULL, 'Aisle 3, Shelf 2'),
(95, 'Digital Multimeter', 'MTL-000095', 'Fluke', 'Auto-ranging multimeter for AC/DC voltage, current and resistance.', 'Test Leads, Carrying Pouch', 'https://picsum.photos/seed/tool-95/600/400', 158.99, 10.00, NULL, '2026-01-03', NULL, 'Aisle 4, Shelf 1'),
(96, 'OBD-II Code Reader', 'MTL-000096', 'Autel', 'Reads and clears check-engine codes on 1996+ vehicles.', 'USB Cable, Quick Reference Card', 'https://picsum.photos/seed/tool-96/600/400', 190.00, 10.00, 'Grace Perez', '2026-01-10', NULL, 'Aisle 4, Shelf 2'),
(97, 'Paint Sprayer', 'MTL-000097', 'Graco', 'HVLP paint sprayer for walls, fences and furniture. Clean thoroughly before returning.', 'Two Nozzles, Viscosity Cup, Cleaning Brush', 'https://picsum.photos/seed/tool-97/600/400', 234.99, 17.00, NULL, '2026-01-17', NULL, 'Aisle 5, Shelf 1'),
(98, 'Drywall Sander', 'MTL-000098', 'WEN', 'Corded drywall sander with a dust collection hose.', 'Dust Hose, Sanding Head', 'https://picsum.photos/seed/tool-98/600/400', 173.00, 24.00, NULL, '2026-01-24', NULL, 'Aisle 5, Shelf 2'),
(99, 'Concrete Mixer', 'MTL-000099', 'YARDMAX', 'Portable 3.5 cu ft electric concrete mixer on wheels. Very heavy, so bring a truck.', NULL, 'https://picsum.photos/seed/tool-99/600/400', 460.99, 40.00, 'Olivia Martinez', '2026-01-31', NULL, 'Yard Bay 3'),
(100, 'Pressure Washer', 'MTL-000100', 'Ryobi', 'Electric pressure washer for decks, siding and driveways.', 'Spray Wand, Three Nozzle Tips, Detergent Tank', 'https://picsum.photos/seed/tool-100/600/400', 184.00, 26.00, NULL, '2026-02-07', NULL, 'Floor Rack B'),
(101, 'Angle Grinder', 'MTL-000101', 'Makita', '4-1/2 inch corded angle grinder for cutting and grinding metal. PPE required.', 'Side Handle, Wheel Guard, Spanner Wrench', 'https://picsum.photos/seed/tool-101/600/400', 95.99, 11.00, NULL, '2026-02-14', NULL, 'Aisle 6, Shelf 1'),
(102, 'Socket Wrench Set', 'MTL-000102', 'GearWrench', '120-piece SAE/metric socket set with ratchets.', 'Carrying Case', 'https://picsum.photos/seed/tool-102/600/400', 132.00, 6.00, 'Isabella Ramirez', '2026-02-21', NULL, 'Aisle 4, Shelf 3'),
(103, 'Appliance Dolly', 'MTL-000103', 'Harper', 'Heavy-duty appliance hand truck with straps and stair skids. Rated 800 lbs.', 'Ratchet Strap', 'https://picsum.photos/seed/tool-103/600/400', 127.99, 13.00, NULL, '2026-02-28', NULL, 'Floor Rack C'),
(104, 'Extension Ladder', 'MTL-000104', 'Louisville', 'Aluminum extension ladder. Two-person carry recommended.', NULL, 'https://picsum.photos/seed/tool-104/600/400', 324.00, 17.00, NULL, '2026-03-07', NULL, 'Wall Rack, North Wall'),
(105, 'Tile Saw', 'MTL-000105', 'QEP', 'Wet tile saw for cutting ceramic and porcelain. Requires a water supply.', 'Water Tray, Rip Guide, Blade', 'https://picsum.photos/seed/tool-105/600/400', 249.99, 24.00, 'Mia Campbell', '2026-03-14', NULL, 'Aisle 6, Shelf 3'),
(106, 'Stud Finder', 'MTL-000106', 'Franklin', 'Electronic stud finder for locating studs and joists behind drywall.', NULL, 'https://picsum.photos/seed/tool-106/600/400', 50.00, 4.00, NULL, '2026-03-21', NULL, 'Aisle 4, Shelf 1'),
(107, 'Reciprocating Saw', 'MTL-000107', 'DeWalt', 'Cordless reciprocating saw for demolition and pruning cuts.', 'Battery, Charger, Wood Blade, Metal Blade', 'https://picsum.photos/seed/tool-107/600/400', 126.99, 18.00, NULL, '2026-03-28', NULL, 'Aisle 1, Shelf 2'),
(108, 'Wet/Dry Vacuum', 'MTL-000108', 'Ridgid', '12-gallon wet/dry shop vacuum for cleanup jobs.', 'Hose, Crevice Tool, Filter', 'https://picsum.photos/seed/tool-108/600/400', 109.00, 11.00, 'Amelia Young', '2026-04-04', NULL, 'Floor Rack B'),
(109, 'Post Hole Digger', 'MTL-000109', 'Seymour', 'Manual clamshell post hole digger for fence and deck projects.', NULL, 'https://picsum.photos/seed/tool-109/600/400', 57.99, 5.00, NULL, '2026-04-11', NULL, 'Wall Rack, North Wall'),
(110, 'Leaf Blower', 'MTL-000110', 'EGO', 'Cordless leaf blower for clearing yards, walks and gutters.', 'Battery, Charger, Nozzle', 'https://picsum.photos/seed/tool-110/600/400', 189.00, 18.00, NULL, '2026-04-18', NULL, 'Aisle 2, Shelf 3'),
(111, 'Rotary Hammer', 'MTL-000111', 'Milwaukee', 'SDS-plus rotary hammer for drilling and light chipping in concrete.', 'SDS Bits, Side Handle, Case', 'https://picsum.photos/seed/tool-111/600/400', 327.99, 26.00, 'Evelyn Nowak', '2026-04-25', NULL, 'Aisle 6, Shelf 2'),
(112, 'Wheelbarrow', 'MTL-000112', 'True Temper', '6 cu ft steel-tray wheelbarrow for hauling soil, mulch and concrete.', NULL, 'https://picsum.photos/seed/tool-112/600/400', 80.00, 9.00, NULL, '2026-05-02', NULL, 'Yard Bay 2'),
(113, 'Cordless Drill Driver', 'MTL-000113', 'Bosch', 'Drilling holes and driving screws. Keyless chuck, battery-powered, comes with a bit set.', 'Battery, Charger, Bit Set, Carrying Case', 'https://picsum.photos/seed/tool-113/600/400', 139.99, 16.00, NULL, '2026-05-09', NULL, 'Aisle 1, Shelf 1'),
(114, 'Circular Saw', 'MTL-000114', 'Makita', 'Corded 7-1/4 inch circular saw for straight cuts in lumber and plywood.', 'Rip Fence, Blade Wrench', 'https://picsum.photos/seed/tool-114/600/400', 134.00, 18.00, 'Ella Collins', '2026-05-16', NULL, 'Aisle 1, Shelf 2'),
(115, 'Table Saw', 'MTL-000115', 'Bosch', '10-inch jobsite table saw. In-person orientation required before first checkout.', 'Miter Gauge, Push Stick, Blade Guard, Stand', 'https://picsum.photos/seed/tool-115/600/400', 490.99, 46.00, NULL, '2026-05-23', NULL, 'Floor Rack A'),
(116, 'Random Orbit Sander', 'MTL-000116', 'DeWalt', '5-inch random orbit sander for smooth finishes. Bring your own sanding discs.', 'Dust Canister', 'https://picsum.photos/seed/tool-116/600/400', 89.00, 11.00, NULL, '2026-05-30', NULL, 'Aisle 1, Shelf 3'),
(117, 'Cordless Impact Driver', 'MTL-000117', 'Milwaukee', 'Compact impact driver for driving long screws and lag bolts.', 'Battery, Charger, Belt Clip', 'https://picsum.photos/seed/tool-117/600/400', 150.99, 18.00, 'Aiden Torres', '2026-06-06', NULL, 'Aisle 1, Shelf 1'),
(118, 'Hedge Trimmer', 'MTL-000118', 'EGO', 'Cordless 22-inch hedge trimmer for shrubs and bushes.', 'Battery, Charger, Blade Cover', 'https://picsum.photos/seed/tool-118/600/400', 133.00, 15.00, NULL, '2026-06-13', NULL, 'Aisle 2, Shelf 1'),
(119, 'Gas Lawn Mower', 'MTL-000119', 'Toro', 'Self-propelled 21-inch gas mower. Drain fuel before returning.', 'Grass Bag, Side Discharge Chute', 'https://picsum.photos/seed/tool-119/600/400', 358.99, 57.00, NULL, '2026-06-20', NULL, 'Yard Bay 1'),
(120, 'Chainsaw', 'MTL-000120', 'Husqvarna', '16-inch chainsaw. In-person orientation and PPE required for checkout.', 'Bar Cover, Chain Oil Bottle, Scrench Tool', 'https://picsum.photos/seed/tool-120/600/400', 271.00, 40.00, 'Alice Schmidt', '2026-06-27', 'Newest of our three chainsaws, so prioritize this one for first-time borrowers since the safety guard is easiest to see clearly.', 'Aisle 2, Shelf 2 (locked cabinet)'),
(121, 'Pipe Wrench Set', 'MTL-000121', 'Ridgid', 'Pair of 14-inch and 18-inch pipe wrenches for plumbing work.', NULL, 'https://picsum.photos/seed/tool-121/600/400', 88.99, 6.00, NULL, '2026-07-04', NULL, 'Aisle 3, Shelf 1'),
(122, 'Drain Auger', 'MTL-000122', 'Ridgid', 'Hand-crank drain auger for clearing sink and tub clogs.', NULL, 'https://picsum.photos/seed/tool-122/600/400', 79.00, 8.00, NULL, '2026-07-11', NULL, 'Aisle 3, Shelf 2'),
(123, 'Digital Multimeter', 'MTL-000123', 'Klein', 'Auto-ranging multimeter for AC/DC voltage, current and resistance.', 'Test Leads, Carrying Pouch', 'https://picsum.photos/seed/tool-123/600/400', 200.99, 8.00, 'Priya Reed', '2026-07-18', NULL, 'Aisle 4, Shelf 1'),
(124, 'OBD-II Code Reader', 'MTL-000124', 'Innova', 'Reads and clears check-engine codes on 1996+ vehicles.', 'USB Cable, Quick Reference Card', 'https://picsum.photos/seed/tool-124/600/400', 131.00, 11.00, NULL, '2026-07-25', NULL, 'Aisle 4, Shelf 2'),
(125, 'Paint Sprayer', 'MTL-000125', 'Wagner', 'HVLP paint sprayer for walls, fences and furniture. Clean thoroughly before returning.', 'Two Nozzles, Viscosity Cup, Cleaning Brush', 'https://picsum.photos/seed/tool-125/600/400', 115.99, 18.00, NULL, '2026-08-01', NULL, 'Aisle 5, Shelf 1');

-- One retired tool. retired_at is the only thing that marks it: the row and
-- all its history stay intact, and clearing the column reactivates it. Tool 3
-- is chosen because its private_notes already describe a blade that wobbles
-- above 6000 RPM and was flagged to maintenance, so taking it out of
-- circulation is the story those notes were already telling.
--
-- Having exactly one retired tool is what exercises the split states: the
-- public catalog hides it unless the Retired filter asks for it, the staff
-- Inventory page hides it unless "Active + retired" is picked, and its
-- reservation queue was closed as 'tool_retired' (see section 7).
UPDATE wp_tool_inventory SET retired_at = '2026-08-20 09:30:00' WHERE tool_id = 3;

-- ==========================================
-- 4. TOOL <-> CATEGORY MAPPINGS
-- ==========================================
INSERT INTO wp_tool_category_mappings (tool_id, category_id) VALUES
(1, 1),
(1, 10),
(2, 1),
(3, 1),
(4, 1),
(4, 6),
(5, 1),
(5, 10),
(6, 2),
(7, 2),
(8, 2),
(8, 1),
(9, 3),
(9, 10),
(10, 3),
(11, 4),
(12, 5),
(13, 6),
(14, 6),
(15, 7),
(16, 8),
(16, 2),
(17, 9),
(17, 7),
(18, 5),
(18, 10),
(19, 11),
(20, 10),
(20, 6),
(21, 7),
(21, 1),
(22, 10),
(22, 6),
(23, 1),
(23, 9),
(24, 8),
(25, 2),
(25, 7),
(26, 2),
(27, 7),
(27, 9),
(28, 11),
(28, 7),
(29, 1),
(29, 10),
(30, 1),
(31, 1),
(32, 1),
(32, 6),
(33, 1),
(33, 10),
(34, 2),
(35, 2),
(36, 2),
(36, 1),
(37, 3),
(37, 10),
(38, 3),
(39, 4),
(40, 5),
(41, 6),
(42, 6),
(43, 7),
(44, 8),
(44, 2),
(45, 9),
(45, 7),
(46, 5),
(46, 10),
(47, 11),
(48, 10),
(48, 6),
(49, 7),
(49, 1),
(50, 10),
(50, 6),
(51, 1),
(51, 9),
(52, 8),
(53, 2),
(53, 7),
(54, 2),
(55, 7),
(55, 9),
(56, 11),
(56, 7),
(57, 1),
(57, 10),
(58, 1),
(59, 1),
(60, 1),
(60, 6),
(61, 1),
(61, 10),
(62, 2),
(63, 2),
(64, 2),
(64, 1),
(65, 3),
(65, 10),
(66, 3),
(67, 4),
(68, 5),
(69, 6),
(70, 6),
(71, 7),
(72, 8),
(72, 2),
(73, 9),
(73, 7),
(74, 5),
(74, 10),
(75, 11),
(76, 10),
(76, 6),
(77, 7),
(77, 1),
(78, 10),
(78, 6),
(79, 1),
(79, 9),
(80, 8),
(81, 2),
(81, 7),
(82, 2),
(83, 7),
(83, 9),
(84, 11),
(84, 7),
(85, 1),
(85, 10),
(86, 1),
(87, 1),
(88, 1),
(88, 6),
(89, 1),
(89, 10),
(90, 2),
(91, 2),
(92, 2),
(92, 1),
(93, 3),
(93, 10),
(94, 3),
(95, 4),
(96, 5),
(97, 6),
(98, 6),
(99, 7),
(100, 8),
(100, 2),
(101, 9),
(101, 7),
(102, 5),
(102, 10),
(103, 11),
(104, 10),
(104, 6),
(105, 7),
(105, 1),
(106, 10),
(106, 6),
(107, 1),
(107, 9),
(108, 8),
(109, 2),
(109, 7),
(110, 2),
(111, 7),
(111, 9),
(112, 11),
(112, 7),
(113, 1),
(113, 10),
(114, 1),
(115, 1),
(116, 1),
(116, 6),
(117, 1),
(117, 10),
(118, 2),
(119, 2),
(120, 2),
(120, 1),
(121, 3),
(121, 10),
(122, 3),
(123, 4),
(124, 5),
(125, 6);

-- ==========================================
-- 5. TOOL <-> TAG MAPPINGS
-- ==========================================
INSERT INTO wp_tool_tag_mappings (tool_id, tag_id) VALUES
(1, 1),
(2, 2),
(2, 9),
(3, 2),
(3, 5),
(3, 9),
(3, 11),
(4, 2),
(5, 1),
(6, 1),
(6, 8),
(7, 3),
(7, 8),
(7, 11),
(8, 3),
(8, 5),
(8, 8),
(8, 9),
(9, 4),
(9, 5),
(10, 4),
(11, 6),
(12, 6),
(13, 2),
(13, 10),
(14, 2),
(14, 7),
(14, 10),
(15, 2),
(15, 5),
(15, 11),
(16, 2),
(16, 8),
(17, 2),
(17, 9),
(17, 10),
(18, 4),
(18, 6),
(19, 4),
(19, 5),
(19, 11),
(20, 4),
(20, 11),
(21, 2),
(21, 9),
(21, 10),
(22, 6),
(23, 1),
(24, 2),
(24, 10),
(25, 4),
(25, 5),
(26, 1),
(26, 8),
(27, 2),
(27, 5),
(27, 9),
(28, 4),
(28, 11),
(29, 1),
(30, 2),
(30, 9),
(31, 2),
(31, 5),
(31, 9),
(31, 11),
(32, 2),
(33, 1),
(34, 1),
(34, 8),
(35, 3),
(35, 8),
(35, 11),
(36, 3),
(36, 5),
(36, 8),
(36, 9),
(37, 4),
(37, 5),
(38, 4),
(39, 6),
(40, 6),
(41, 2),
(41, 10),
(42, 2),
(42, 7),
(42, 10),
(43, 2),
(43, 5),
(43, 11),
(44, 2),
(44, 8),
(45, 2),
(45, 9),
(45, 10),
(46, 4),
(46, 6),
(47, 4),
(47, 5),
(47, 11),
(48, 4),
(48, 11),
(49, 2),
(49, 9),
(49, 10),
(50, 6),
(51, 1),
(52, 2),
(52, 10),
(53, 4),
(53, 5),
(54, 1),
(54, 8),
(55, 2),
(55, 5),
(55, 9),
(56, 4),
(56, 11),
(57, 1),
(58, 2),
(58, 9),
(59, 2),
(59, 5),
(59, 9),
(59, 11),
(60, 2),
(61, 1),
(62, 1),
(62, 8),
(63, 3),
(63, 8),
(63, 11),
(64, 3),
(64, 5),
(64, 8),
(64, 9),
(65, 4),
(65, 5),
(66, 4),
(67, 6),
(68, 6),
(69, 2),
(69, 10),
(70, 2),
(70, 7),
(70, 10),
(71, 2),
(71, 5),
(71, 11),
(72, 2),
(72, 8),
(73, 2),
(73, 9),
(73, 10),
(74, 4),
(74, 6),
(75, 4),
(75, 5),
(75, 11),
(76, 4),
(76, 11),
(77, 2),
(77, 9),
(77, 10),
(78, 6),
(79, 1),
(80, 2),
(80, 10),
(81, 4),
(81, 5),
(82, 1),
(82, 8),
(83, 2),
(83, 5),
(83, 9),
(84, 4),
(84, 11),
(85, 1),
(86, 2),
(86, 9),
(87, 2),
(87, 5),
(87, 9),
(87, 11),
(88, 2),
(89, 1),
(90, 1),
(90, 8),
(91, 3),
(91, 8),
(91, 11),
(92, 3),
(92, 5),
(92, 8),
(92, 9),
(93, 4),
(93, 5),
(94, 4),
(95, 6),
(96, 6),
(97, 2),
(97, 10),
(98, 2),
(98, 7),
(98, 10),
(99, 2),
(99, 5),
(99, 11),
(100, 2),
(100, 8),
(101, 2),
(101, 9),
(101, 10),
(102, 4),
(102, 6),
(103, 4),
(103, 5),
(103, 11),
(104, 4),
(104, 11),
(105, 2),
(105, 9),
(105, 10),
(106, 6),
(107, 1),
(108, 2),
(108, 10),
(109, 4),
(109, 5),
(110, 1),
(110, 8),
(111, 2),
(111, 5),
(111, 9),
(112, 4),
(112, 11),
(113, 1),
(114, 2),
(114, 9),
(115, 2),
(115, 5),
(115, 9),
(115, 11),
(116, 2),
(117, 1),
(118, 1),
(118, 8),
(119, 3),
(119, 8),
(119, 11),
(120, 3),
(120, 5),
(120, 8),
(120, 9),
(121, 4),
(121, 5),
(122, 4),
(123, 6),
(124, 6),
(125, 2),
(125, 10);

-- Tool <-> sub-categories. One per category the tool is in, so every row here
-- pairs with a category mapping above. Only a sample of tools carries one.
INSERT INTO wp_tool_subcategory_mappings (tool_id, category_id, subcategory_id) VALUES
(3, 1, 1), (31, 1, 1), (59, 1, 1), (87, 1, 1), (115, 1, 1),
(8, 1, 1), (36, 1, 1), (64, 1, 1), (92, 1, 1), (120, 1, 1),
(11, 4, 8), (39, 4, 8), (67, 4, 8);

-- Tool <-> required trainings: the five Table Saws need Table Saw Safety, the
-- five Chainsaws need Chainsaw Safety.
INSERT INTO wp_tool_training_mappings (tool_id, training_id) VALUES
(3, 2), (31, 2), (59, 2), (87, 2), (115, 2),
(8, 4), (36, 4), (64, 4), (92, 4), (120, 4);

-- ==========================================
-- 6. LOANS (81 total)
--    return_date NULL = currently checked out. Active checkouts are on
--    distinct tools (no tool has two open loans). As of 2026-10-09, all 10
--    in the first block of active loans (#56-65) are a few weeks overdue,
--    6 in the second block (#66-69, #80, #81) have just gone overdue, one
--    (#70) is due today, and the rest fall due over the next nine days.
-- ==========================================
INSERT INTO wp_loans (loan_id, tool_id, member_id, loan_date, due_date, return_date) VALUES
(1, 4, 3, '2024-08-17 16:15:00', '2024-08-31', '2024-08-30 14:40:00'),
(2, 13, 10, '2024-08-29 08:30:00', '2024-09-12', '2024-09-10 09:30:00'),
(3, 22, 17, '2024-09-10 08:45:00', '2024-09-24', '2024-09-21 13:20:00'),
(4, 31, 24, '2024-09-22 11:20:00', '2024-10-06', '2024-10-02 08:20:00'),
(5, 40, 31, '2024-10-04 15:45:00', '2024-10-18', '2024-10-13 09:15:00'),
(6, 49, 38, '2024-10-16 11:35:00', '2024-10-30', '2024-10-29 16:20:00'),
(7, 58, 45, '2024-10-28 13:00:00', '2024-11-11', '2024-11-09 10:45:00'),
(8, 67, 52, '2024-11-09 09:10:00', '2024-11-23', '2024-11-20 17:45:00'),
(9, 76, 59, '2024-11-21 14:35:00', '2024-12-05', '2024-12-01 13:50:00'),
(10, 85, 6, '2024-12-03 09:50:00', '2024-12-17', '2024-12-12 11:15:00'),
(11, 94, 13, '2024-12-15 17:20:00', '2024-12-29', '2024-12-28 09:10:00'),
(12, 103, 20, '2024-12-27 12:55:00', '2025-01-10', '2025-01-08 10:10:00'),
(13, 112, 27, '2025-01-08 17:10:00', '2025-01-22', '2025-01-19 17:00:00'),
(14, 121, 34, '2025-01-20 15:25:00', '2025-02-03', '2025-01-30 13:55:00'),
(15, 5, 41, '2025-02-01 15:05:00', '2025-02-15', '2025-02-10 14:55:00'),
(16, 14, 48, '2025-02-13 13:55:00', '2025-02-27', '2025-02-26 16:35:00'),
(17, 23, 55, '2025-02-25 11:50:00', '2025-03-11', '2025-03-09 15:35:00'),
(18, 32, 2, '2025-03-09 13:50:00', '2025-03-23', '2025-03-20 09:15:00'),
(19, 41, 9, '2025-03-21 15:35:00', '2025-04-04', '2025-04-09 17:25:00'),
(20, 50, 16, '2025-04-02 16:55:00', '2025-04-16', '2025-04-11 14:55:00'),
(21, 59, 23, '2025-04-14 10:55:00', '2025-04-28', '2025-04-27 16:00:00'),
(22, 68, 30, '2025-04-26 10:55:00', '2025-05-10', '2025-05-17 15:10:00'),
(23, 77, 37, '2025-05-08 11:05:00', '2025-05-22', '2025-05-19 13:10:00'),
(24, 86, 44, '2025-05-20 16:15:00', '2025-06-03', '2025-05-30 08:25:00'),
(25, 95, 51, '2025-06-01 12:00:00', '2025-06-15', '2025-06-20 08:45:00'),
(26, 104, 58, '2025-06-13 17:00:00', '2025-06-27', '2025-06-26 11:25:00'),
(27, 113, 5, '2025-06-25 09:25:00', '2025-07-09', '2025-07-07 15:50:00'),
(28, 122, 12, '2025-07-07 17:20:00', '2025-07-21', '2025-07-28 13:10:00'),
(29, 6, 19, '2025-07-19 13:40:00', '2025-08-02', '2025-07-29 11:20:00'),
(30, 15, 26, '2025-07-31 09:25:00', '2025-08-14', '2025-08-19 09:30:00'),
(31, 24, 33, '2025-08-12 16:40:00', '2025-08-26', '2025-08-25 10:40:00'),
(32, 33, 40, '2025-08-24 16:30:00', '2025-09-07', '2025-09-05 14:05:00'),
(33, 42, 47, '2025-09-05 14:00:00', '2025-09-19', '2025-09-16 17:05:00'),
(34, 51, 54, '2025-09-17 17:50:00', '2025-10-01', '2025-09-27 08:35:00'),
(35, 60, 1, '2025-09-29 12:10:00', '2025-10-13', '2025-10-08 11:45:00'),
(36, 69, 8, '2025-10-11 08:35:00', '2025-10-25', '2025-10-24 10:50:00'),
(37, 78, 15, '2025-10-23 12:35:00', '2025-11-06', '2025-11-04 08:20:00'),
(38, 87, 22, '2025-11-04 17:55:00', '2025-11-18', '2025-11-15 13:15:00'),
(39, 96, 29, '2025-11-16 11:30:00', '2025-11-30', '2025-11-26 17:25:00'),
(40, 105, 36, '2025-11-28 15:40:00', '2025-12-12', '2025-12-07 13:25:00'),
(41, 114, 43, '2025-12-10 09:30:00', '2025-12-24', '2025-12-23 16:55:00'),
(42, 123, 50, '2025-12-22 12:45:00', '2026-01-05', '2026-01-03 14:20:00'),
(43, 7, 57, '2026-01-03 16:40:00', '2026-01-17', '2026-01-14 08:25:00'),
(44, 16, 4, '2026-01-15 12:20:00', '2026-01-29', '2026-01-25 09:30:00'),
(45, 25, 11, '2026-01-27 17:45:00', '2026-02-10', '2026-02-05 16:30:00'),
(46, 34, 18, '2026-02-08 08:50:00', '2026-02-22', '2026-02-21 15:15:00'),
(47, 43, 25, '2026-02-20 15:10:00', '2026-03-06', '2026-03-04 08:05:00'),
(48, 52, 32, '2026-03-04 10:50:00', '2026-03-18', '2026-03-15 10:15:00'),
(49, 61, 39, '2026-03-16 16:50:00', '2026-03-30', '2026-03-26 09:35:00'),
(50, 70, 46, '2026-03-28 08:20:00', '2026-04-11', '2026-04-06 09:35:00'),
(51, 79, 53, '2026-04-09 16:15:00', '2026-04-23', '2026-04-22 13:45:00'),
(52, 88, 60, '2026-04-21 13:25:00', '2026-05-05', '2026-05-03 12:25:00'),
(53, 97, 7, '2026-05-03 16:50:00', '2026-05-17', '2026-05-14 16:20:00'),
(54, 106, 14, '2026-05-15 11:15:00', '2026-05-29', '2026-05-25 14:45:00'),
(55, 115, 21, '2026-05-27 10:35:00', '2026-06-10', '2026-06-05 08:20:00'),
(56, 1, 6, '2026-08-26 12:15:00', '2026-09-09', NULL),
(57, 5, 17, '2026-08-27 14:35:00', '2026-09-10', NULL),
(58, 9, 28, '2026-08-28 11:35:00', '2026-09-11', NULL),
(59, 13, 39, '2026-08-29 12:35:00', '2026-09-12', NULL),
(60, 17, 50, '2026-08-30 11:00:00', '2026-09-13', NULL),
(61, 21, 1, '2026-08-31 17:50:00', '2026-09-14', NULL),
(62, 25, 12, '2026-09-01 12:40:00', '2026-09-15', NULL),
(63, 29, 23, '2026-09-02 10:55:00', '2026-09-16', NULL),
(64, 33, 34, '2026-09-03 14:55:00', '2026-09-17', NULL),
(65, 37, 45, '2026-09-04 17:25:00', '2026-09-18', NULL),
(66, 41, 56, '2026-09-21 13:50:00', '2026-10-05', NULL),
(67, 45, 7, '2026-09-22 13:10:00', '2026-10-06', NULL),
(68, 49, 18, '2026-09-23 17:45:00', '2026-10-07', NULL),
(69, 53, 29, '2026-09-24 14:45:00', '2026-10-08', NULL),
(70, 57, 40, '2026-09-25 14:10:00', '2026-10-09', NULL),
(71, 61, 51, '2026-09-26 15:05:00', '2026-10-10', NULL),
(72, 65, 2, '2026-09-27 09:40:00', '2026-10-11', NULL),
(73, 69, 13, '2026-09-28 17:30:00', '2026-10-12', NULL),
(74, 73, 24, '2026-09-29 09:35:00', '2026-10-13', NULL),
(75, 77, 35, '2026-09-30 14:10:00', '2026-10-14', NULL),
(76, 81, 46, '2026-10-01 08:35:00', '2026-10-15', NULL),
(77, 85, 57, '2026-10-02 17:45:00', '2026-10-16', NULL),
(78, 89, 8, '2026-10-03 14:20:00', '2026-10-17', NULL),
(79, 93, 19, '2026-10-04 11:30:00', '2026-10-18', NULL),
(80, 97, 30, '2026-09-21 08:50:00', '2026-10-05', NULL),
(81, 101, 41, '2026-09-22 17:55:00', '2026-10-06', NULL);

-- ==========================================
-- 7. ACTIVE TOOL RESERVATIONS (16)
--    The waiting queues as they stand right now. Reservations that have
--    already ended are section 7B, kept separate because they answer a
--    different question and are matched by a different test (expiry_date IS
--    NOT NULL, never "active").
--
--    reservation_date is a full TIMESTAMP, so a tool can hold a multi-member
--    waiting queue; the earliest timestamp for a tool is queue position 1
--    (position is derived on the fly, never stored).
--
--    Deliberately mixed so the Loans & Reservations page shows both states:
--      * Tools 1, 13, 45 and 97 are CURRENTLY ON LOAN, so their reservations
--        are true wait-lists ("Waiting"). Tools 1 and 13 are also overdue.
--      * Tools 11, 35, 43, 51, 59 and 75 are NOT on loan, so their
--        reservations are collectable now ("Ready").
--    No member ever reserves a tool they already have checked out. Every row
--    here is an ACTIVE reservation, so expiry_date and closed_reason are both
--    NULL: the pair is only ever written together, when the reservation ends
--    (see schema.sql and mtl_reservation_close_reasons()).
--
--    ready_since is set ONLY on a reservation that is collectable right now:
--    front of its queue with the tool on the shelf. Everything queued behind a
--    loan, or behind another member, keeps NULL, since their hold period has not
--    started, which is exactly the case that must never auto-expire. The dates
--    used are recent enough to sit inside the default 14-day hold period, so
--    loading this file does not immediately expire anything; see
--    mtl_expire_stale_reservations().
-- ==========================================
INSERT INTO wp_tool_reservations (reservation_id, tool_id, member_id, reservation_date, ready_since, expiry_date, closed_reason) VALUES
-- Tool 1 (on loan to member 6, overdue): 3-member waiting queue, nobody ready
(1,  1,  8,  '2026-09-21 09:15:00', NULL, NULL, NULL),
(2,  1,  21, '2026-09-23 14:30:00', NULL, NULL, NULL),
(3,  1,  34, '2026-09-26 11:05:00', NULL, NULL, NULL),
-- Tool 13 (on loan to member 39, overdue): 2-member waiting queue
(4,  13, 47, '2026-09-22 10:00:00', NULL, NULL, NULL),
(5,  13, 52, '2026-09-25 16:20:00', NULL, NULL, NULL),
-- Tool 45 (on loan to member 7): 2-member waiting queue
(6,  45, 5,  '2026-09-24 08:45:00', NULL, NULL, NULL),
(7,  45, 18, '2026-09-28 15:40:00', NULL, NULL, NULL),
-- Tool 97 (on loan to member 30): single wait-list entry
(8,  97, 44, '2026-10-01 09:00:00', NULL, NULL, NULL),
-- Tool 11 (available): single reservation, ready for pickup
(9,  11, 21, '2026-09-27 13:25:00', '2026-10-06 09:00:00', NULL, NULL),
-- Tool 35 (available): single reservation
(10, 35, 5,  '2026-09-29 15:10:00', '2026-10-07 10:30:00', NULL, NULL),
-- Tool 43 (available): single reservation
(11, 43, 13, '2026-09-30 14:00:00', '2026-10-04 16:45:00', NULL, NULL),
-- Tool 51 (available): 2-member queue, of which only the front is ready
(12, 51, 26, '2026-09-26 11:00:00', '2026-10-08 08:15:00', NULL, NULL),
(13, 51, 60, '2026-09-29 10:15:00', NULL, NULL, NULL),
-- Tool 59 (available): 2-member queue, of which only the front is ready
(14, 59, 39, '2026-09-27 08:30:00', '2026-10-05 12:00:00', NULL, NULL),
(15, 59, 44, '2026-09-30 17:45:00', NULL, NULL, NULL),
-- Tool 75 (available): single reservation
(16, 75, 30, '2026-10-02 12:00:00', '2026-10-07 14:20:00', NULL, NULL);

-- ==========================================
-- 7B. CLOSED RESERVATIONS (11)
--    History, not queue: every row here has expiry_date set, so no query that
--    means "active reservation" (expiry_date IS NULL) picks them up, and none
--    of them shifts a queue position above.
--
--    Between them they cover all seven values of closed_reason, so every
--    branch of mtl_reservation_close_reasons() has data behind it:
--      fulfilled (2), lapsed (2), cancelled_member (2), cancelled_staff (1),
--      tool_retired (2), member_deleted (1), member_locked (1).
--
--    Each is kept consistent with the rest of this file rather than invented
--    freely:
--      * The two 'fulfilled' rows close at the exact loan_date of the loan
--        they turned into (loans 47 and 51), which is what mtl_create_loan()
--        stamps.
--      * The two 'lapsed' rows expire 14 days after ready_since, matching the
--        default mtl_reservation_hold_days. Only a reservation that actually
--        became collectable can lapse, so both carry a ready_since.
--      * The 'tool_retired' pair closes at the moment tool 3 was retired
--        (section 3), to the second, since one UPDATE closed them together.
--        Both members hold current Table Saw Safety, keeping the rule that
--        nobody here is queued for a tool they aren't cleared for.
--      * The 'member_deleted' row closes at member 16's anonymized_at
--        (section 1), for the same reason, and the 'member_locked' row at
--        member 31's locked_at.
--      * A reservation cancelled while still queued behind a loan never became
--        collectable, so ready_since stays NULL on those.
-- ==========================================
INSERT INTO wp_tool_reservations (reservation_id, tool_id, member_id, reservation_date, ready_since, expiry_date, closed_reason) VALUES
-- Became loan #47 (tool 43 to member 25, 2026-02-20): reserved, collected, closed.
(17, 43,  25, '2026-02-12 10:20:00', '2026-02-15 09:00:00', '2026-02-20 15:10:00', 'fulfilled'),
-- Became loan #51 (tool 79 to member 53, 2026-04-09).
(18, 79,  53, '2026-04-02 16:40:00', '2026-04-05 11:15:00', '2026-04-09 16:15:00', 'fulfilled'),
-- Ready 2026-07-09, never collected; the daily sweep closed it 14 days later.
(19, 22,  35, '2026-07-03 13:05:00', '2026-07-09 09:45:00', '2026-07-23 03:00:00', 'lapsed'),
-- Same again, on a different tool and member.
(20, 66,  47, '2026-08-08 15:30:00', '2026-08-15 08:20:00', '2026-08-29 03:00:00', 'lapsed'),
-- Gave up their place from My Reservations while still behind a loan.
(21, 105, 27, '2026-06-07 11:50:00', NULL, '2026-06-14 19:05:00', 'cancelled_member'),
-- Cancelled their own reservation after it was already collectable.
(22, 118, 42, '2026-07-24 09:10:00', '2026-08-01 10:00:00', '2026-08-03 08:40:00', 'cancelled_member'),
-- Member phoned to say they no longer needed it; staff closed it for them.
(23, 92,  19, '2026-07-16 14:25:00', '2026-07-20 09:30:00', '2026-07-25 16:10:00', 'cancelled_staff'),
-- Tool 3 was retired out from under its queue, closing both places at once.
(24, 3,   22, '2026-08-06 10:15:00', '2026-08-10 08:00:00', '2026-08-20 09:30:00', 'tool_retired'),
(25, 3,   44, '2026-08-13 17:20:00', NULL, '2026-08-20 09:30:00', 'tool_retired'),
-- Member 16 was deleted; their queue place went with them.
(26, 30,  16, '2026-09-08 12:35:00', NULL, '2026-09-18 10:05:00', 'member_deleted'),
-- Member 31's account was locked while this was waiting on the shelf for them.
(27, 110, 31, '2026-09-22 16:05:00', '2026-09-24 09:00:00', '2026-09-29 14:20:00', 'member_locked');

-- ==========================================
-- 8. MEMBER <-> TRAINING MAPPINGS (41 records across 24 of 60 members)
--    training_ids 1-7 and their renewal periods are seeded by schema.sql:
--      1 Power Tool Basics     never expires   5 Angle Grinder Safety  12 mo
--      2 Table Saw Safety      24 mo           6 Welding Basics        36 mo
--      3 Miter Saw Safety      24 mo           7 Ladder Safety         12 mo
--      4 Chainsaw Safety       12 mo
--
--    start_date is when that member completed the training; the certification
--    lapses that many months later (mtl_training_expiry_date()). Dates here
--    are chosen so BOTH states are represented without editing anything:
--    6 of the 41 records are deliberately expired as of October 2026:
--    (12,5) (30,6) (39,4) (47,3) (55,4) (58,7), and members 39, 55 and 58
--    each hold a mix of current and expired, which is what exercises the
--    "badges show current only, the table shows everything" split on the My
--    Account page.
--
--    Kept consistent with the loan/reservation history above: ten tools
--    require a training (see tool_training_mappings): Table Saws 3, 31, 59,
--    87, 115 need Table Saw Safety, Chainsaws 8, 36, 64, 92, 120 need Chainsaw Safety. Every member who has borrowed or reserved
--    one of those Table Saws (21, 22, 23, 24, 39, 44) holds Table Saw Safety
--    below AND it is still current, so no row in this file shows a member
--    using a tool they aren't qualified for. No member has borrowed a
--    Chainsaw, so Chainsaw Safety expiries are assigned freely.
--
--    Most members have no trainings at all, so the "None on record" empty
--    state is reachable without editing anything. These are all independent
--    tool-specific trainings with no prerequisite between them, so members
--    hold them in any combination.
-- ==========================================
INSERT INTO wp_member_training_mappings (member_id, training_id, start_date) VALUES
(1, 2, '2025-05-17'), (1, 3, '2025-05-17'),
(3, 1, '2024-07-25'),
(5, 3, '2025-08-07'),
(6, 2, '2025-12-19'), (6, 4, '2026-04-18'), (6, 6, '2024-06-13'),
(9, 1, '2024-02-04'), (9, 4, '2026-03-29'), (9, 7, '2026-05-08'),
-- EXPIRED: Angle Grinder (12 mo) completed in spring 2024.
(12, 5, '2024-05-15'),
(13, 2, '2025-10-24'), (13, 3, '2025-10-24'),
(17, 5, '2026-08-01'), (17, 6, '2024-11-21'),
-- Members 21-24 have each borrowed a Table Saw (tools 115, 87, 59, 31), so
-- their Table Saw Safety is dated recently enough to still be current.
(21, 2, '2025-11-10'),
(22, 2, '2026-03-22'), (22, 3, '2026-03-22'),
(23, 2, '2026-01-26'),
(24, 1, '2024-04-20'), (24, 2, '2026-02-07'),
(26, 1, '2025-06-14'),
-- EXPIRED: Welding (36 mo) completed back in 2022.
(30, 6, '2022-03-22'),
(33, 1, '2026-08-23'), (33, 7, '2026-08-23'),
(35, 2, '2026-05-02'), (35, 5, '2026-07-05'),
-- Members 39 and 44 are both in the reservation queue for Table Saw 59;
-- Table Saw Safety current for both. Member 39's Chainsaw ticket has lapsed,
-- giving one member a deliberate current/expired mix.
(39, 2, '2025-09-11'), (39, 4, '2025-07-18'),
(41, 1, '2025-05-05'),
-- (44, 6) expires 2026-10-27: an almost-lapsed certification, to check the
-- boundary reads as current rather than expired.
(44, 2, '2026-05-24'), (44, 5, '2026-08-06'), (44, 6, '2023-10-27'),
-- EXPIRED: Miter Saw (24 mo) completed in 2023.
(47, 3, '2023-08-06'),
(52, 1, '2026-09-14'),
-- EXPIRED: Chainsaw (12 mo) completed in early 2024.
(55, 4, '2024-03-26'), (55, 6, '2025-12-07'),
-- EXPIRED: Ladder (12 mo) completed in March 2025.
(58, 1, '2025-02-09'), (58, 7, '2025-03-12'),
(60, 2, '2026-06-19'), (60, 4, '2026-09-05');
