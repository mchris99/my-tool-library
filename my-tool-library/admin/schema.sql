-- MyToolLibrary Database
-- NOTE: table names use a {{prefix}} placeholder, which setup-page.php
-- replaces with $wpdb->prefix (e.g. "wp_") before executing this file. This
-- keeps the plugin's tables namespaced per-site per WordPress convention,
-- instead of colliding with other plugins or other sites on a multisite install.


-- ==========================================
-- 1. DROP TABLES (For easy resetting)
-- ==========================================
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS {{prefix}}tool_reservations;
DROP TABLE IF EXISTS {{prefix}}loans;
DROP TABLE IF EXISTS {{prefix}}tool_training_mappings;
DROP TABLE IF EXISTS {{prefix}}tool_tag_mappings;
DROP TABLE IF EXISTS {{prefix}}tool_subcategory_mappings;
DROP TABLE IF EXISTS {{prefix}}tool_subcategories;
DROP TABLE IF EXISTS {{prefix}}tool_category_mappings;
DROP TABLE IF EXISTS {{prefix}}tool_tags;
DROP TABLE IF EXISTS {{prefix}}tool_categories;
DROP TABLE IF EXISTS {{prefix}}member_training_mappings;
DROP TABLE IF EXISTS {{prefix}}member_trainings;
DROP TABLE IF EXISTS {{prefix}}member_verifications;
-- Acceptances key into both member_agreements and members, so they drop first.
DROP TABLE IF EXISTS {{prefix}}member_agreement_acceptances;
DROP TABLE IF EXISTS {{prefix}}member_agreements;
DROP TABLE IF EXISTS {{prefix}}tool_inventory;
DROP TABLE IF EXISTS {{prefix}}members;

-- ==========================================
-- 2. CORE TABLES
-- ==========================================

-- Members Table
-- Address is split into structured fields rather than one free-text line, so
-- city/state/zip/country are each independently usable: the Dashboard's
-- member-areas-by-ZIP panel reads zip_code directly instead of regex-parsing a
-- combined string. address_line2 (apartment/suite/unit) is nullable; every
-- other address field is required.
--
-- state and country are short codes/names drawn from fixed lists
-- (mtl_get_state_options() / mtl_get_country_options() in my-tool-library.php),
-- rendered as <select> dropdowns and validated server-side on every write.
-- state covers the U.S. and Canada, which both use short standardized
-- subdivision codes; members elsewhere take the 'N/A' entry, since
-- region/province systems vary too much per-country to model generally here.
-- country is the full ISO 3166-1 name, not the alpha-2 code, so it displays
-- with no separate lookup, and defaults to 'United States' as this plugin's
-- primary audience without being restricted to it.
--
-- Deleting a member never drops this row. The request is honored by
-- anonymizing it: identifying fields are overwritten with placeholders,
-- anonymized_at is stamped, and the member reads as "Former Member"
-- afterwards. Keeping the row is what lets their loans, reservations and
-- completed trainings stay attached and accurate, since
-- member_training_mappings and member_verifications both cascade off it. Their
-- WordPress account is a separate matter and is deleted outright (wp_users
-- plus wp_usermeta). See mtl_delete_or_anonymize_member().
--
-- phone_number is always stored pre-formatted as "+<calling code> <national
-- number>" (e.g. "+1 (414) 555-0123"), the canonical form every write path
-- produces via mtl_format_phone_number() in my-tool-library.php. 32 chars
-- comfortably covers the longest realistic value: a 3-digit calling code plus
-- a 14-digit national number grouped with spaces. See the "PHONE NUMBERS"
-- block comment in my-tool-library.php for the full design.
--
-- private_notes is staff-only: never selected by any public-facing or
-- member-self-service query, and shown only in the admin Membership page's
-- detail view.
CREATE TABLE {{prefix}}members (
    member_id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    address_line1 VARCHAR(255) NOT NULL,
    address_line2 VARCHAR(255) DEFAULT NULL,
    city VARCHAR(100) NOT NULL,
    state VARCHAR(10) NOT NULL,
    zip_code VARCHAR(20) NOT NULL,
    country VARCHAR(100) NOT NULL DEFAULT 'United States',
    phone_number VARCHAR(32) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    signup_date DATE NOT NULL,
    recurring_donation_amount DECIMAL(10, 2) DEFAULT 0.00,
    has_donated_tools CHAR(1) DEFAULT 'N',
    anonymized_at TIMESTAMP NULL DEFAULT NULL,
    private_notes TEXT DEFAULT NULL
);

-- Sensitive Member Data (Separated for security compliance)
-- NOTE: member_id here is intentionally NOT AUTO_INCREMENT, since it is a 1:1
-- mirror of the owning row in the members table, never generated on its own.
-- Both scan URLs are nullable, since a member may have provided only one form
-- of ID so far and staff should be able to save what they have on hand. Full
-- verification requires both on file (mtl_member_is_verified() in
-- member-pages.php, mtl_verification_urls_complete() in my-tool-library.php);
-- one alone still leaves them unverified.
CREATE TABLE {{prefix}}member_verifications (
    member_id INT PRIMARY KEY,
    photo_id_scan_url VARCHAR(255) DEFAULT NULL,
    address_proof_scan_url VARCHAR(255) DEFAULT NULL,
    verified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES {{prefix}}members(member_id) ON DELETE CASCADE
);

-- Tool Inventory Table
-- Like members, a tool with loans/tool_reservations history can't be deleted
-- outright (same missing ON DELETE CASCADE). retired_at is the counterpart to
-- a member's anonymized_at, except reversible: NULL means active/lendable;
-- once set, the tool is hidden from the public catalog and blocked from new
-- loans or reservations, but the row and its full history stay intact and
-- retired_at can simply be cleared again.
-- private_notes is staff-only, like members.private_notes above and unlike
-- description/components; see public/shop-page.php, which lists its columns
-- explicitly rather than using SELECT *.
-- location is where the tool sits on the shelf, written in whatever notation
-- the library already uses ("Aisle 3, Shelf 4", "113", "K4-1"), so it is free
-- text rather than a structured aisle/shelf/bin. Optional, and unlike
-- private_notes it is only conditionally staff-only: staff always see it,
-- members see it only while the Setup page's "Shelf Location" switch is on.
-- See mtl_tool_location_visible_to_members().
CREATE TABLE {{prefix}}tool_inventory (
    tool_id INT AUTO_INCREMENT PRIMARY KEY,
    tool_name VARCHAR(100) NOT NULL,
    barcode VARCHAR(100) NOT NULL UNIQUE,
    brand VARCHAR(50) NOT NULL,
    description TEXT,
    components TEXT,
    photo_url VARCHAR(255),
    initial_cash_value DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    annual_depreciation_amount DECIMAL(10, 2) DEFAULT 0.00,
    donated_by VARCHAR(255),
    date_acquired DATE DEFAULT (CURRENT_DATE),
    retired_at TIMESTAMP NULL DEFAULT NULL,
    private_notes TEXT DEFAULT NULL,
    location VARCHAR(100) DEFAULT NULL
);

-- ==========================================
-- 3. LOOKUP & ATTRIBUTE TABLES (Categories, Tags, Flags)
-- ==========================================

-- Categories (e.g., Woodworking, Gardening, Plumbing)
CREATE TABLE {{prefix}}tool_categories (
    category_id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(50) UNIQUE NOT NULL
);

-- Junction table for Tool <-> Categories (Many-to-Many)
CREATE TABLE {{prefix}}tool_category_mappings (
    tool_id INT,
    category_id INT,
    PRIMARY KEY (tool_id, category_id),
    FOREIGN KEY (tool_id) REFERENCES {{prefix}}tool_inventory(tool_id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES {{prefix}}tool_categories(category_id) ON DELETE CASCADE
);

-- Sub-categories (e.g., Woodworking > Circular Saws)
-- One level deep, and each belongs to exactly one category. Deleting a
-- category takes its sub-categories with it, as the Setup page warns.
--
-- subcategory_name is unique per category, not globally, so two categories can
-- each have their own "Drills". That is why the CSV import names them
-- qualified, as "Category > Sub-category".
--
-- The second unique key exists only so tool_subcategory_mappings can point a
-- composite foreign key at it; see the note there.
CREATE TABLE {{prefix}}tool_subcategories (
    subcategory_id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    subcategory_name VARCHAR(50) NOT NULL,
    UNIQUE KEY category_subcategory (category_id, subcategory_name),
    UNIQUE KEY subcategory_category (subcategory_id, category_id),
    FOREIGN KEY (category_id) REFERENCES {{prefix}}tool_categories(category_id) ON DELETE CASCADE
);

-- Junction table for Tool <-> Sub-categories
-- A tool takes at most one sub-category per category it belongs to, which is
-- what PRIMARY KEY (tool_id, category_id) enforces.
--
-- category_id is derivable from subcategory_id, and is stored anyway because
-- it is what makes that rule a database guarantee rather than a habit of the
-- form. The composite foreign key below points at both columns together, so a
-- row can never claim a category the sub-category does not actually belong to.
CREATE TABLE {{prefix}}tool_subcategory_mappings (
    tool_id INT,
    category_id INT,
    subcategory_id INT,
    PRIMARY KEY (tool_id, category_id),
    KEY subcategory (subcategory_id),
    FOREIGN KEY (tool_id) REFERENCES {{prefix}}tool_inventory(tool_id) ON DELETE CASCADE,
    FOREIGN KEY (subcategory_id, category_id) REFERENCES {{prefix}}tool_subcategories(subcategory_id, category_id) ON DELETE CASCADE
);

-- Tags (e.g., Heavy-Duty, Cordless, Indoor, Precision)
CREATE TABLE {{prefix}}tool_tags (
    tag_id INT AUTO_INCREMENT PRIMARY KEY,
    tag_name VARCHAR(50) UNIQUE NOT NULL
);

-- Junction table for Tool <-> Tags (Many-to-Many)
CREATE TABLE {{prefix}}tool_tag_mappings (
    tool_id INT,
    tag_id INT,
    PRIMARY KEY (tool_id, tag_id),
    FOREIGN KEY (tool_id) REFERENCES {{prefix}}tool_inventory(tool_id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES {{prefix}}tool_tags(tag_id) ON DELETE CASCADE
);

-- Member Trainings (e.g., Table Saw Safety, Welding Basics)
-- Which safety/skill trainings a member has completed, so staff can tell at a
-- glance which tools that member is qualified to check out; the counterpart
-- to tool_training_mappings below. Managed by admins on the Setup page
-- like categories and tags, except that all three columns are editable in
-- place there (categories and tags are add-or-delete only), since a badge
-- image or certification length can change long after the training was created.
--
-- badge_image_url is optional and admin-set: when present, the member's own My
-- Account page shows this image (with the training name as alt/hover text)
-- instead of the plain green pill, and only for trainings that are still
-- current. Staff-side admin pages always show trainings as plain names.
--
-- certification_length_months is how long a completed training stays valid,
-- counted from that member's start_date below; NULL means it never expires.
-- Expiry is always derived (start_date + certification_length_months, see
-- mtl_training_expiry_date() in my-tool-library.php), never stored, so editing
-- the length here re-dates every member who holds that training automatically
-- instead of needing a backfill.
CREATE TABLE {{prefix}}member_trainings (
    training_id INT AUTO_INCREMENT PRIMARY KEY,
    training_name VARCHAR(50) UNIQUE NOT NULL,
    badge_image_url VARCHAR(255) DEFAULT NULL,
    certification_length_months INT DEFAULT NULL
);

-- Junction table for Member <-> Trainings (Many-to-Many)
-- start_date is the day that member completed this training; with the
-- training's certification_length_months it determines whether their
-- certification is still current. A plain DATE, not a TIMESTAMP: certification
-- is granted for a day, not a moment, and comparing whole days avoids an
-- off-by-a-few-hours expiry.
--
-- ON DELETE CASCADE on member_id is a safety net for a row genuinely removed
-- by hand, not the path a member deletion takes:
-- mtl_delete_or_anonymize_member() anonymizes the members row rather than
-- dropping it, precisely so these training records survive as library history,
-- re-attached to a "Former Member".
CREATE TABLE {{prefix}}member_training_mappings (
    member_id INT,
    training_id INT,
    start_date DATE NOT NULL,
    PRIMARY KEY (member_id, training_id),
    FOREIGN KEY (member_id) REFERENCES {{prefix}}members(member_id) ON DELETE CASCADE,
    FOREIGN KEY (training_id) REFERENCES {{prefix}}member_trainings(training_id) ON DELETE CASCADE
);

-- Junction table for Tool <-> Trainings (Many-to-Many)
-- Which trainings a member must hold before this tool goes out; no rows means
-- none required. Advisory only, never blocks a loan; see mtl_tool_training_gap().
CREATE TABLE {{prefix}}tool_training_mappings (
    tool_id INT,
    training_id INT,
    PRIMARY KEY (tool_id, training_id),
    FOREIGN KEY (tool_id) REFERENCES {{prefix}}tool_inventory(tool_id) ON DELETE CASCADE,
    FOREIGN KEY (training_id) REFERENCES {{prefix}}member_trainings(training_id) ON DELETE CASCADE
);

-- Member Agreements
-- The statements a member must agree to before an account is created, written
-- and maintained by admins on the Setup page like member_trainings above.
--
-- agreement_text is the wording the member reads next to the checkbox: plain
-- text, no HTML, escaped on output, since it is snapshotted into a legal
-- record and replayed on public pages. attachment_id is an optional supporting
-- file (a policy PDF, a fee schedule, a safety sheet) in the WordPress Media
-- Library, and file_sha256 fingerprints its bytes so a record identifies the
-- exact file attached; the two are NULL together.
--
-- There is deliberately no file_url column here. Every read calls
-- wp_get_attachment_url( attachment_id ), so a site changing domain cannot
-- strand a stale copy. Acceptance rows do snapshot the URL, to freeze what it
-- was at that moment rather than stay current.
--
-- version_num is incremented on every save that changes the wording or the
-- attached file, with no "minor edit" exemption; a member whose latest
-- acceptance of this agreement sits on a lower number reads as outstanding and
-- is asked again. version_published_at is re-stamped on each bump and is what
-- the Setup panel's "in use since" reads.
--
-- retired_at mirrors tool_inventory.retired_at: NULL means active and
-- required; once set, the agreement stops appearing at signup and stops being
-- enforced, but the row stays so existing acceptance records keep resolving.
-- The acceptances table's RESTRICT foreign key blocks deleting it outright.
--
-- DATETIMEs in these two tables hold UTC, written from PHP with gmdate(), and
-- are deliberately not TIMESTAMP: that converts on read using the session
-- timezone, so its meaning would depend on who was asking, and it cannot
-- represent dates after 2038.
CREATE TABLE {{prefix}}member_agreements (
    agreement_id INT AUTO_INCREMENT PRIMARY KEY,
    agreement_text TEXT NOT NULL,
    attachment_id INT DEFAULT NULL,
    file_sha256 CHAR(64) DEFAULT NULL,
    version_num INT NOT NULL DEFAULT 1,
    version_published_at DATETIME NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    retired_at DATETIME NULL DEFAULT NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Junction table for Member <-> Agreements
-- Append-only: one row per acceptance event, so (member_id, agreement_id) is
-- deliberately not a primary key and no row is ever updated in place. This
-- differs from member_training_mappings above, which keys on the pair. A
-- member who accepts v1 and later v2 of the same agreement has two rows, and
-- the v1 row stays exactly as it was.
--
-- agreement_text, assent_text, agreement_version_num,
-- agreement_version_published_at, file_url and file_sha256 are snapshots
-- copied in at insert time, never live lookups, so an admin editing the
-- wording afterwards leaves existing rows describing what was actually agreed
-- to. They preserve mistakes too: a member who corrects a typo the next day
-- leaves the typo here, and no backfill should "fix" it.
--
-- assent_text is the wording that framed the tick ("By ticking this box I
-- agree to the statement above", or the staff attestation equivalent), since a
-- clickwrap dispute is frequently about whether the interface conveyed assent
-- at all, not about what the clause said. It comes from mtl_assent_language(
-- $context ), the same function that renders it on screen, and only from there.
--
-- accepted_context is one of 'signup', 'agree_page', 'staff_add' or
-- 'staff_edit', with no default: every value is a factual claim about how the
-- member agreed, so there is no inert one to fall back to. acted_by is the
-- staff account that recorded it, not the member the row is about, so
--   acted_by IS NOT NULL  <=>  accepted_context IN ('staff_add','staff_edit')
-- It is not a foreign key, because wp_users is core's table and that account
-- may be deleted long before this row stops mattering. There is deliberately
-- no IP address, user agent or device column.
--
-- member_name (101 = members.first_name(50) + a space + last_name(50)) and
-- member_email are personal data, retained deliberately even when the member
-- is deleted so the library can still show who agreed to what. Nothing may
-- UPDATE a row here, member deletion included.
--
-- ON DELETE CASCADE on member_id is a safety net for a row removed by hand, as
-- in member_training_mappings; RESTRICT on agreement_id is the opposite on
-- purpose, making "agreements are retired, never deleted" a rule the database
-- enforces rather than one left to MySQL's default.
--
-- KEY member_agreement is two columns, not three: the status query selects
-- MAX(acceptance_id), and in InnoDB every secondary index already carries the
-- primary key as its row locator, so this behaves as (member_id, agreement_id,
-- acceptance_id) for free. Its leftmost column also satisfies InnoDB's index
-- requirement for the member_id foreign key.
CREATE TABLE {{prefix}}member_agreement_acceptances (
    acceptance_id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    agreement_id INT NOT NULL,
    accepted_at DATETIME NOT NULL,
    agreement_text TEXT NOT NULL,
    assent_text TEXT NOT NULL,
    agreement_version_num INT NOT NULL DEFAULT 1,
    agreement_version_published_at DATETIME NOT NULL,
    file_url VARCHAR(512) DEFAULT NULL,
    file_sha256 CHAR(64) DEFAULT NULL,
    accepted_context VARCHAR(20) NOT NULL,
    acted_by BIGINT UNSIGNED DEFAULT NULL,
    member_name VARCHAR(101) NOT NULL DEFAULT '',
    member_email VARCHAR(100) NOT NULL DEFAULT '',

    KEY member_agreement (member_id, agreement_id),
    -- Required for the agreement_id foreign key; also serves Advanced Search.
    KEY agreement (agreement_id),
    FOREIGN KEY (member_id) REFERENCES {{prefix}}members(member_id) ON DELETE CASCADE,
    FOREIGN KEY (agreement_id) REFERENCES {{prefix}}member_agreements(agreement_id) ON DELETE RESTRICT
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================
-- 4. TRANSACTIONAL TABLES (Loans & Reservations)
-- ==========================================

-- Historical and Active Loans
-- loan_date and return_date are full TIMESTAMPs, not DATEs, so the exact
-- checkout/check-in moment is on record; lists still display just the date
-- (mtl_format_date() in my-tool-library.php), with the full timestamp in each
-- admin page's detail view. due_date stays a plain DATE, being a hand-picked
-- calendar date, not a moment the system stamps.
-- One exception to "the exact moment": staff processing a backlog of drop-offs
-- can backdate a return, in which case return_date holds the start of the day
-- the tool actually came back, since the real check-in time is not known (see
-- mtl_resolve_return_timestamp() in my-tool-library.php).
CREATE TABLE {{prefix}}loans (
    loan_id INT AUTO_INCREMENT PRIMARY KEY,
    tool_id INT NOT NULL,
    member_id INT NOT NULL,
    loan_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    due_date DATE NOT NULL,
    return_date TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (tool_id) REFERENCES {{prefix}}tool_inventory(tool_id),
    FOREIGN KEY (member_id) REFERENCES {{prefix}}members(member_id)
);

-- Tool Reservations
-- A tool may have many concurrent reservations, forming a waiting queue.
-- reservation_date is a full TIMESTAMP so the queue can be ordered precisely:
-- for a given tool the earliest reservation_date is position 1, the next is 2,
-- and so on. Position is derived on the fly (see the Loans & Reservations
-- admin page), never stored.
--
-- expiry_date is NULL while a reservation is active and is stamped only when
-- it ends, whether cancelled or fulfilled by a loan, so "active reservation"
-- everywhere means "expiry_date IS NULL" and a non-NULL value is when it
-- closed. closed_reason records WHY it ended, since expiry_date alone cannot
-- tell a reservation that became a loan from one nobody came to collect: see
-- mtl_reservation_close_reasons() in my-tool-library.php for the six values
-- and what each means. Every path that stamps expiry_date must stamp this too,
-- and the two are only ever written together.
--
-- A NULL closed_reason means one of two things, told apart by expiry_date:
-- the reservation is still active (expiry_date NULL too), or it closed on a
-- site that upgraded from before this column existed (expiry_date set), where
-- the reason genuinely was not recorded and must not be guessed at.
--
-- ready_since is when the reservation became collectable: the member
-- reached the front of the queue and the tool was back on the shelf. NULL
-- means they are still waiting their turn. It exists so an unclaimed
-- reservation can expire on its own after the hold period set on the Setup
-- page (mtl_reservation_hold_days) without penalising anyone simply queued
-- behind a long loan, whose clock has not started yet.
-- mtl_sync_reservation_readiness() keeps it in step, running after every event
-- that can change a tool's queue.
CREATE TABLE {{prefix}}tool_reservations (
    reservation_id INT AUTO_INCREMENT PRIMARY KEY,
    tool_id INT NOT NULL,
    member_id INT NOT NULL,
    reservation_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ready_since TIMESTAMP NULL DEFAULT NULL,
    expiry_date TIMESTAMP NULL DEFAULT NULL,
    closed_reason VARCHAR(20) DEFAULT NULL,
    FOREIGN KEY (tool_id) REFERENCES {{prefix}}tool_inventory(tool_id),
    FOREIGN KEY (member_id) REFERENCES {{prefix}}members(member_id)
);

-- ==========================================
-- 5. POPULATE LOOKUP TABLES
-- ==========================================

INSERT INTO {{prefix}}tool_categories (category_id, category_name) VALUES
(1, 'Woodworking'),
(2, 'Gardening & Landscaping'),
(3, 'Plumbing'),
(4, 'Electrical'),
(5, 'Automotive'),
(6, 'Painting & Drywall'),
(7, 'Masonry & Concrete'),
(8, 'Cleaning'),
(9, 'Metalworking'),
(10, 'General Hand Tools'),
(11, 'Moving & Hauling');

-- A starting set so the picker is not empty. Admins add their own on Setup.
INSERT INTO {{prefix}}tool_subcategories (subcategory_id, category_id, subcategory_name) VALUES
(1, 1, 'Saws'),
(2, 1, 'Sanders & Planers'),
(3, 1, 'Routers & Joinery'),
(4, 2, 'Mowers & Trimmers'),
(5, 2, 'Digging & Soil'),
(6, 3, 'Drain Clearing'),
(7, 3, 'Pipe & Fitting'),
(8, 4, 'Testing & Metering'),
(9, 4, 'Wiring & Conduit'),
(10, 5, 'Lifting & Jacks'),
(11, 5, 'Diagnostics'),
(12, 9, 'Welding'),
(13, 9, 'Cutting & Grinding'),
(14, 10, 'Drills & Drivers'),
(15, 10, 'Wrenches & Sockets');

INSERT INTO {{prefix}}tool_tags (tag_id, tag_name) VALUES
(1, 'Cordless'),
(2, 'Corded'),
(3, 'Gas-Powered'),
(4, 'Manual'),
(5, 'Heavy-Duty'),
(6, 'Precision'),
(7, 'Indoor Use Only'),
(8, 'Outdoor Use Only'),
(9, 'Requires PPE'),
(10, 'Consumables Required'),
(11, 'Large/Bulky');

-- Starting set of trainings; admins add, edit and remove their own on the Setup
-- page, including changing any of these renewal periods. The lengths are a
-- plausible starting point rather than a recommendation: a general
-- introduction that does not lapse, shorter renewals on the higher-risk
-- machines. Badge images are left unset; each library uploads its own.
INSERT INTO {{prefix}}member_trainings (training_id, training_name, certification_length_months) VALUES
(1, 'Power Tool Basics', NULL),
(2, 'Table Saw Safety', 24),
(3, 'Miter Saw Safety', 24),
(4, 'Chainsaw Safety', 12),
(5, 'Angle Grinder Safety', 12),
(6, 'Welding Basics', 36),
(7, 'Ladder Safety', 12);

SET FOREIGN_KEY_CHECKS = 1;
