-- MyToolLibrary Database
-- NOTE: table names use a {{prefix}} placeholder, which setup-page.php
-- replaces with $wpdb->prefix (e.g. "wp_") before executing this file.
-- This keeps the plugin's tables namespaced per-site, per WordPress
-- table naming conventions, instead of colliding with other plugins
-- or other sites on a multisite install.


-- ==========================================
-- 1. DROP TABLES (For easy resetting)
-- ==========================================
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS {{prefix}}tool_reservations;
DROP TABLE IF EXISTS {{prefix}}loans;
DROP TABLE IF EXISTS {{prefix}}tool_tag_mappings;
DROP TABLE IF EXISTS {{prefix}}tool_category_mappings;
DROP TABLE IF EXISTS {{prefix}}tool_tags;
DROP TABLE IF EXISTS {{prefix}}tool_categories;
DROP TABLE IF EXISTS {{prefix}}member_training_mappings;
DROP TABLE IF EXISTS {{prefix}}member_trainings;
DROP TABLE IF EXISTS {{prefix}}member_verifications;
-- Acceptances hold foreign keys into BOTH member_agreements and members, so
-- they drop first; member_agreements then drops before members.
DROP TABLE IF EXISTS {{prefix}}member_agreement_acceptances;
DROP TABLE IF EXISTS {{prefix}}member_agreements;
DROP TABLE IF EXISTS {{prefix}}tool_inventory;
DROP TABLE IF EXISTS {{prefix}}members;

-- ==========================================
-- 2. CORE TABLES
-- ==========================================

-- Members Table
-- Address is split into structured fields (rather than one free-text line)
-- so city/state/zip/country are each independently usable -- e.g. the
-- Dashboard's member-areas-by-ZIP panel reads zip_code directly instead of
-- regex-parsing a combined string. address_line2 (apartment/suite/unit) is
-- nullable since not every address has one; every other address field is
-- required.
--
-- state and country are both short codes/names drawn from a fixed list
-- (mtl_get_state_options() / mtl_get_country_options() in
-- my-tool-library.php), rendered as <select> dropdowns and validated
-- server-side on every write -- not arbitrary free text. state covers the
-- U.S. and Canada (both use short, standardized subdivision codes); every
-- other country's members use the 'N/A' entry, since region/province
-- systems vary too much per-country to model generally here. country is the
-- full ISO 3166-1 country name (not the alpha-2 code) so it displays
-- correctly with no separate lookup elsewhere in the plugin. country
-- defaults to 'United States' since that's this plugin's primary audience,
-- but any member -- admin-entered or self-signed-up -- can select a
-- different one; this plugin is not restricted to U.S. libraries.
--
-- Deleting a member never drops this row. The request is honored by
-- anonymizing it: identifying fields are overwritten with placeholders,
-- anonymized_at is stamped, and the member reads as "Former Member"
-- afterwards. Their loans, reservations and completed trainings all stay
-- attached to it so tool histories, borrowing counts and training records
-- remain accurate -- keeping the row is what makes that possible, since
-- member_training_mappings and member_verifications both cascade off it.
-- Their WordPress account is a separate matter and IS deleted outright
-- (wp_users plus wp_usermeta). See mtl_delete_or_anonymize_member().
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
    -- Always stored pre-formatted as "+<calling code> <national number>"
    -- (e.g. "+1 (414) 555-0123"), the canonical form every write path
    -- produces via mtl_format_phone_number() in my-tool-library.php --
    -- Signup, Account edit, Add/Edit Member, and CSV import all funnel
    -- through it, so every phone number in this column is guaranteed to be
    -- in the same format. 32 chars comfortably covers the longest realistic
    -- value (a 3-digit calling code plus a 14-digit national number grouped
    -- with spaces). See the "PHONE NUMBERS" block comment in
    -- my-tool-library.php for the full design.
    phone_number VARCHAR(32) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    signup_date DATE NOT NULL,
    recurring_donation_amount DECIMAL(10, 2) DEFAULT 0.00,
    has_donated_tools CHAR(1) DEFAULT 'N',
    anonymized_at TIMESTAMP NULL DEFAULT NULL,
    -- private_notes is staff-only, like tool_inventory.private_notes below: it
    -- is never selected by any public-facing or member-self-service query and
    -- is shown only in the admin Membership page's detail view.
    private_notes TEXT DEFAULT NULL
);

-- Sensitive Member Data (Separated for security compliance)
-- NOTE: member_id here is intentionally NOT AUTO_INCREMENT -- it is a 1:1
-- mirror of the owning row in the members table, never generated on its own.
-- Both scan URLs are nullable -- a member may have provided only one form of
-- ID so far, and staff should be able to save whatever they currently have on
-- hand rather than being forced to withhold it until the other arrives. A
-- member only counts as fully verified (mtl_member_is_verified() in
-- member-pages.php, mtl_verification_urls_complete() in my-tool-library.php)
-- once BOTH are on file; one alone still leaves them unverified.
CREATE TABLE {{prefix}}member_verifications (
    member_id INT PRIMARY KEY,
    photo_id_scan_url VARCHAR(255) DEFAULT NULL,
    address_proof_scan_url VARCHAR(255) DEFAULT NULL,
    verified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES {{prefix}}members(member_id) ON DELETE CASCADE
);

-- Tool Inventory Table
-- Like members, a tool with loans/tool_reservations history can't be deleted
-- outright (same missing ON DELETE CASCADE). retired_at is the counterpart
-- to a member's anonymized_at: NULL means active/lendable; once set, the
-- tool is hidden from the public catalog and blocked from new loans or
-- reservations, but the row and its full history stay intact and reversible
-- (retired_at can simply be cleared again) -- unlike a member delete, which
-- irreversibly discards personal data.
-- private_notes is staff-only, unlike description/components: it is never
-- selected by any public-facing query (see public/shop-page.php, which lists
-- its columns explicitly rather than using SELECT *) and is shown only in
-- the admin Inventory page's detail view.
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
    private_notes TEXT DEFAULT NULL
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
-- Managed by admins on the Setup page, exactly like categories and tags,
-- except that all three columns are editable in place there (categories and
-- tags are add-or-delete only) -- a badge image or certification length can
-- reasonably change long after the training itself was created.
-- These record which safety/skill trainings a member has completed, so staff
-- can tell at a glance which tools that member is qualified to check out --
-- the counterpart to the 'Requires Training' tool tag below.
--
-- badge_image_url is optional and admin-set: when present, the member's own
-- My Account page shows this image (with the training name as alt/hover
-- text) instead of the plain green pill, and ONLY for trainings that are
-- still current. Public-facing only -- the staff-side admin pages always
-- show trainings as plain names, never as images.
--
-- certification_length_months is how long a completed training stays valid,
-- counted from that member's start_date below. NULL means it never expires.
-- Expiry is always DERIVED (start_date + certification_length_months, see
-- mtl_training_expiry_date() in my-tool-library.php), never stored: editing
-- the length here has to re-date every member who holds that training, and
-- deriving it means that happens automatically instead of needing a
-- backfill.
CREATE TABLE {{prefix}}member_trainings (
    training_id INT AUTO_INCREMENT PRIMARY KEY,
    training_name VARCHAR(50) UNIQUE NOT NULL,
    badge_image_url VARCHAR(255) DEFAULT NULL,
    certification_length_months INT DEFAULT NULL
);

-- Junction table for Member <-> Trainings (Many-to-Many)
-- start_date is the day that member completed this training; combined with
-- the training's certification_length_months it determines whether their
-- certification is still current. A plain DATE, not a TIMESTAMP -- a
-- certification is granted for a day, not a moment, and comparing whole
-- days avoids an off-by-a-few-hours expiry.
--
-- ON DELETE CASCADE on member_id is a safety net for a row that is genuinely
-- removed (a manual cleanup, or the whole table being rebuilt). It is NOT the
-- path a member deletion takes: mtl_delete_or_anonymize_member() anonymizes
-- the members row rather than dropping it, precisely so these training
-- records survive as library history, re-attached to a "Former Member".
CREATE TABLE {{prefix}}member_training_mappings (
    member_id INT,
    training_id INT,
    start_date DATE NOT NULL,
    PRIMARY KEY (member_id, training_id),
    FOREIGN KEY (member_id) REFERENCES {{prefix}}members(member_id) ON DELETE CASCADE,
    FOREIGN KEY (training_id) REFERENCES {{prefix}}member_trainings(training_id) ON DELETE CASCADE
);

-- Member Agreements
-- The statements a member must agree to before an account is created, written
-- and maintained by admins on the Setup page -- exactly like member_trainings
-- above, and edited in place there in the same way.
--
-- agreement_text is the wording the member reads next to the checkbox. It is
-- REQUIRED; an agreement with no text is not an agreement. Stored as plain
-- text and escaped on output -- no HTML is permitted, because this string is
-- snapshotted into a legal record and replayed on public pages.
--
-- attachment_id is an OPTIONAL supporting file (a policy PDF, a fee schedule,
-- a safety sheet) held in the WordPress Media Library. file_sha256
-- fingerprints the file's bytes so a record identifies the exact file that was
-- attached; both are NULL together when no file is attached.
--
-- There is deliberately NO file_url column here. An earlier design cached the
-- resolved URL and it was wrong twice over: it went stale the moment a site
-- changed domain, and it was a second copy of a value with a single source.
-- Every read calls wp_get_attachment_url( attachment_id ) instead. Acceptance
-- rows still SNAPSHOT the URL, because there the point is to freeze what it
-- was at that moment; here the point is to always be current.
--
-- version_num starts at 1 and is incremented on EVERY save that changes the
-- wording or the attached file -- there is no "minor edit" exemption, by
-- decision. Every member whose latest acceptance of THIS agreement is on a
-- lower number reads as outstanding and is asked again.
--
-- version_published_at is when the CURRENT version took effect -- stamped on
-- creation and re-stamped on every version bump. It is what the Setup panel's
-- "in use since" reads, and it is the date on which this obligation began.
--
-- retired_at mirrors tool_inventory.retired_at: NULL means active and
-- required; once set, the agreement stops appearing at signup and stops being
-- enforced, but the row stays so existing acceptance records keep resolving.
-- Agreements are NEVER hard-deleted once accepted -- the foreign key on the
-- acceptances table is explicitly RESTRICT, so the database refuses it.
--
-- All DATETIMEs in these two tables hold UTC and are written explicitly from
-- PHP with gmdate(). They are deliberately NOT TIMESTAMP: TIMESTAMP converts
-- on read using the session timezone, so its meaning would depend on who was
-- asking, and it cannot represent dates after 2038 -- unacceptable for records
-- meant to outlive the staff who created them.
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
-- Append-only. One row per acceptance EVENT, so (member_id, agreement_id) is
-- deliberately NOT a primary key and no row is ever updated in place -- this
-- is the one place the pattern departs from member_training_mappings above,
-- which keys on the pair. A member who accepts v1 and later v2 of the same
-- agreement has two rows, and the v1 row stays exactly as it was.
--
-- agreement_text, assent_text, agreement_version_num,
-- agreement_version_published_at, file_url and file_sha256 are all SNAPSHOTS
-- copied in at insert time, never live lookups. That is the whole point: an
-- admin editing the wording afterwards leaves existing rows describing what
-- was actually agreed to. Reading the current wording through the foreign key
-- would silently rewrite history.
--
-- assent_text is the wording that framed the tick -- "By ticking this box I
-- agree to the statement above", or the staff attestation equivalent. In a
-- clickwrap dispute the contested question is frequently whether the interface
-- conveyed assent at all, not what the clause said; a record of the clause
-- without the assent wording answers only half of it. It comes from
-- mtl_assent_language( $context ), the SAME function that renders it on
-- screen, and only that function may supply it.
--
-- accepted_context is one of 'signup', 'agree_page', 'staff_add',
-- 'staff_edit'. NO DEFAULT: every possible value is a factual claim about how
-- the member agreed, so there is no inert value to fall back to.
--
-- THIS TABLE CONTAINS PERSONAL DATA -- member_name and member_email, held
-- deliberately and RETAINED even when the member is deleted, so the library
-- can still show who agreed to what. That is a considered retention decision.
-- Consequently NO ROW IN THIS TABLE IS EVER UPDATED AFTER INSERT, by anything,
-- including a member deletion. There is no exception and no carve-out. Any
-- future code that writes an UPDATE against this table is a bug.
--
-- There is deliberately no IP address, user agent or device column here.
-- acted_by identifies a member of STAFF, not the member the row is about: it
-- is set only for staff-recorded acceptances, so
--   acted_by IS NOT NULL  <=>  accepted_context IN ('staff_add','staff_edit')
-- It is NOT a foreign key -- wp_users is core's table, and that staff account
-- may be deleted long before this row stops mattering.
--
-- member_name is 101 = members.first_name(50) + a space + last_name(50).
-- Being snapshots, these preserve mistakes: a member who corrects a typo the
-- next day leaves the typo here forever, which is correct and must never be
-- "fixed" by a backfill.
--
-- ON DELETE CASCADE on member_id matches member_training_mappings: a safety
-- net for a row genuinely removed by hand, not the path a member deletion
-- takes. ON DELETE RESTRICT on agreement_id is the opposite on purpose: it
-- makes "agreements are retired, never deleted" a rule the database enforces,
-- and it is spelled out rather than left to MySQL's default because a
-- guarantee that depends on an implicit server setting is not one.
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

    -- Two columns, not three. The status query selects MAX(acceptance_id), and
    -- in InnoDB every secondary index already carries the primary key as its
    -- row locator, so this behaves as (member_id, agreement_id, acceptance_id)
    -- for free. Its leftmost column also satisfies InnoDB's index requirement
    -- for the member_id foreign key.
    KEY member_agreement (member_id, agreement_id),
    -- Required for the agreement_id foreign key; also serves Advanced Search's
    -- "who accepted agreement X at version Y".
    KEY agreement (agreement_id),
    FOREIGN KEY (member_id) REFERENCES {{prefix}}members(member_id) ON DELETE CASCADE,
    FOREIGN KEY (agreement_id) REFERENCES {{prefix}}member_agreements(agreement_id) ON DELETE RESTRICT
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================
-- 4. TRANSACTIONAL TABLES (Loans & Reservations)
-- ==========================================

-- Historical and Active Loans
-- loan_date and return_date are full TIMESTAMPs (not just DATEs) so the exact
-- checkout/check-in moment is on record; every list/table still DISPLAYS just
-- the date (see mtl_format_date() in my-tool-library.php), with the full
-- timestamp available in each admin page's detail view. due_date stays a
-- plain DATE -- it is a hand-picked calendar date (via a date picker), not a
-- moment the system stamps.
-- One exception to "the exact moment": a return can be BACKDATED by staff who
-- are processing a backlog of drop-offs, in which case return_date holds the
-- start of the day the tool actually came back, since the real check-in time
-- is not known (see mtl_resolve_return_timestamp() in my-tool-library.php).
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
-- A tool may have MANY concurrent reservations, forming a waiting queue.
-- reservation_date is a full TIMESTAMP (not just a DATE) so the queue can be
-- ordered precisely: for a given tool, the earliest reservation_date is queue
-- position 1, the next is 2, and so on. Queue position is derived on the fly
-- (see the Loans & Reservations admin page), never stored.
--
-- expiry_date is NULL while a reservation is active/waiting. It is stamped
-- (also a full TIMESTAMP, for the same reason as loan_date/return_date above)
-- only when the reservation ENDS -- cancelled (by the member or an admin) or
-- fulfilled by a loan -- so "active reservation" everywhere means
-- "expiry_date IS NULL", and a non-NULL expiry_date is when it closed.
-- ready_since is when this reservation became collectable: the member reached
-- the front of the queue AND the tool was back on the shelf. NULL means they
-- are still waiting their turn. It exists so an unclaimed reservation can
-- expire on its own after the hold period set on the Setup page
-- (mtl_reservation_hold_days) without penalising anyone who is simply queued
-- behind a long loan -- their clock has not started yet. Kept in step by
-- mtl_sync_reservation_readiness(), which runs after every event that can
-- change a tool's queue: a loan starting or ending, a reservation being
-- placed, cancelled or fulfilled, and a tool being retired.
CREATE TABLE {{prefix}}tool_reservations (
    reservation_id INT AUTO_INCREMENT PRIMARY KEY,
    tool_id INT NOT NULL,
    member_id INT NOT NULL,
    reservation_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ready_since TIMESTAMP NULL DEFAULT NULL,
    expiry_date TIMESTAMP NULL DEFAULT NULL,
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
(11, 'Requires Training'),
(12, 'Large/Bulky');

-- Starting set of trainings; admins add, edit and remove their own on the
-- Setup page, including changing any of these renewal periods.
-- certification_length_months is a plausible starting point rather than a
-- recommendation -- a general introduction that doesn't lapse, and shorter
-- renewals on the higher-risk machines. Badge images are left unset; each
-- library uploads its own.
INSERT INTO {{prefix}}member_trainings (training_id, training_name, certification_length_months) VALUES
(1, 'Power Tool Basics', NULL),
(2, 'Table Saw Safety', 24),
(3, 'Miter Saw Safety', 24),
(4, 'Chainsaw Safety', 12),
(5, 'Angle Grinder Safety', 12),
(6, 'Welding Basics', 36),
(7, 'Ladder Safety', 12);

SET FOREIGN_KEY_CHECKS = 1;
