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
    phone_number VARCHAR(20) NOT NULL,
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
-- Managed by admins on the Setup page, exactly like categories and tags.
-- These record which safety/skill trainings a member has completed, so staff
-- can tell at a glance which tools that member is qualified to check out --
-- the counterpart to the 'Requires Training' tool tag below.
CREATE TABLE {{prefix}}member_trainings (
    training_id INT AUTO_INCREMENT PRIMARY KEY,
    training_name VARCHAR(50) UNIQUE NOT NULL
);

-- Junction table for Member <-> Trainings (Many-to-Many)
-- ON DELETE CASCADE on member_id is a safety net for a row that is genuinely
-- removed (a manual cleanup, or the whole table being rebuilt). It is NOT the
-- path a member deletion takes: mtl_delete_or_anonymize_member() anonymizes
-- the members row rather than dropping it, precisely so these training
-- records survive as library history, re-attached to a "Former Member".
CREATE TABLE {{prefix}}member_training_mappings (
    member_id INT,
    training_id INT,
    PRIMARY KEY (member_id, training_id),
    FOREIGN KEY (member_id) REFERENCES {{prefix}}members(member_id) ON DELETE CASCADE,
    FOREIGN KEY (training_id) REFERENCES {{prefix}}member_trainings(training_id) ON DELETE CASCADE
);

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

-- Starting set of trainings; admins add/remove their own on the Setup page.
INSERT INTO {{prefix}}member_trainings (training_id, training_name) VALUES
(1, 'Power Tool Basics'),
(2, 'Table Saw Safety'),
(3, 'Miter Saw Safety'),
(4, 'Chainsaw Safety'),
(5, 'Angle Grinder Safety'),
(6, 'Welding Basics'),
(7, 'Ladder Safety');

SET FOREIGN_KEY_CHECKS = 1;
