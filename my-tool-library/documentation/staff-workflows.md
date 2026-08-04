# My Tool Library — Staff Workflow Guide

Reference steps for the most common jobs staff will do in the plugin.

All admin pages require signing in with a WordPress account that has the **Administrator** role (`manage_options` capability) — there is currently no separate "staff" or "volunteer" role with restricted access. Everyone who works the desk needs a full Administrator account (see [Creating a New Administrator Account](#2-creating-a-new-administrator-account)).

## Contents

1. [Initial Setup](#1-initial-setup)
2. [Creating a New Administrator Account](#2-creating-a-new-administrator-account)
3. [Adding Inventory Items](#3-adding-inventory-items)
4. [Adding a New Member In-Person & Verifying Their Identity](#4-adding-a-new-member-in-person--verifying-their-identity)
5. [Starting a Loan for a Member](#5-starting-a-loan-for-a-member)
6. [Additional Workflows](#6-additional-workflows)

---

## 1. Initial Setup

Do this once, when the plugin is first installed on the site.

1. **Activate the plugin** from the WordPress **Plugins** screen.
2. Go to **Settings > General** in WordPress and confirm the site **Timezone** is set correctly (a named city, not just a UTC offset, so Daylight Saving is handled automatically). Every reservation and loan timestamp in the plugin is based on this setting — if it's left at the default (UTC), your timestamps won't match local time, and past timestamps can't be corrected retroactively.
3. Go to **My Tool Library > Setup**.
4. Under **Database Configuration**, slide the confirmation toggle, click **Run Database Setup**, and type `delete all my data` into the box that pops up. This creates all of the plugin's database tables and is required before any other page will work.
    - **Only do this once.** On a library that is already running this is not a repair tool: it always deletes every member, tool, loan and reservation and rebuilds the tables empty, with no undo. That is why it makes you type the phrase out. If you are troubleshooting a live library, use **Export Data** first.
5. On the **Setup** page, fill in:
    - **General Details**: organization name, logo URL, brand colors/fonts, button style.
    - **Reservations & Loans**: the default loan length (in days) used when checking a reservation out as a loan.
    - **Categories & Tags**: add or remove the tool categories (e.g. Woodworking, Plumbing) and tags (e.g. Cordless, Requires PPE) you plan to use — these are the choices staff pick from when adding inventory.
6. Copy the **Public Page Link** shown on the Setup page and add it to your site's navigation menu (or link to it from any page/post, or link it in a button). This is the one link your community needs to browse the catalog, reserve tools, and sign up.
7. Once categories/tags exist, move on to [Adding Inventory Items](#3-adding-inventory-items) and [Adding a New Member](#4-adding-a-new-member-in-person--verifying-their-identity) — both support **bulk import**, which is the fastest way to load an existing spreadsheet of tools or members:
    - On the **Inventory** or **Membership** page, find the **Bulk Import from CSV** panel.
    - Click **Download the CSV template** — it has the exact column headers and one example row.
    - Fill in your data in rows (delete the example row before uploading), keeping one tool or member per row. Column order doesn't matter as long as the header names match.
    - Upload the completed CSV and submit. The plugin reports how many rows succeeded and lists the reason for any row that failed (e.g. a missing required field or an invalid category), so you can fix just those rows and re-upload.
8. Use the **Export Data** section on the Setup page any time you want a full backup (a SQL dump or a ZIP of CSVs, one per table) — good practice before any bulk import or major change.

---

## 2. Creating a New Administrator Account

Every staff member who needs access to the admin back office needs a full WordPress Administrator account. This plugin does not have its own staff login system — access is entirely controlled by WordPress's built-in user roles.

> **Note:** Because Administrator is a full WordPress role, it grants access to _all_ of WordPress, not just this plugin. Only give it to people you trust with full site access.

1. In the WordPress dashboard, go to **Users > Add New**.
2. Enter the staff member's username and email address.
3. Under **Role**, select **Administrator**.
4. Click **Add New User**. WordPress emails them a link to set their own password (or set one yourself and share it securely).
5. The new administrator can now sign in — either through the normal WordPress login (`/wp-login.php` or `/wp-admin/`) or through the plugin's own branded **Sign In** page (reachable from the public catalog's footer), which routes administrators straight into the **My Tool Library** admin portal.

> **Note:** If administrators will also be customers of the tool library, make sure they create admin account with a username other than their personal email.

---

## 3. Adding Inventory Items

Go to **My Tool Library > Inventory**.

### Adding one tool at a time

1. Click **Add New Tool** (or the equivalent panel toggle at the top of the page).
2. Fill in the tool's name, barcode (must be unique), brand, description, and any components/accessories that come with it.
3. Add a **photo URL** — a link to an image hosted elsewhere (see [Hosting Photos and Documents](#hosting-photos-and-documents) below). This field is optional but recommended; it's shown to the public on the catalog.
4. Fill in financial tracking fields: initial cash value, annual depreciation amount, who donated it (if applicable), and date acquired.
5. Select one or more **categories** and **tags** (set up during Initial Setup).
6. Optionally, add **Private Notes** — see below.
7. Save. The tool immediately appears in the public catalog.

### Adding many tools at once

Use the **Bulk Import from CSV** panel described in [Initial Setup](#1-initial-setup), step 7. This is the fastest way to load an existing inventory spreadsheet, and can also be used later to add a new batch of donated or purchased tools. A `private_notes` column is supported too (see below) — just remember the CSV file itself isn't private once it leaves this page, so don't email or share an import file that has sensitive notes filled in.

> **Note:** The database makes no assumptions about barcode data, other than that it is required and will be unique to the tool. Barcodes can include any text/number up to 100 characters.

### Private Notes (staff-only)

The **Private Notes** field is for anything staff need on record about a tool that members should never see. This is a good way to make notes on maintenance needs or conditional donation. Unlike Description and Components, it's never shown on the public catalog. It only appears in the admin Inventory page's detail view (click a tool's row to expand it), in a highlighted "Private Notes" box. Leave it blank if there's nothing to record. Use the **Has Private Notes?** filter under Advanced Search to find every tool that has one on file.

---

## 4. Adding a New Member In-Person & Verifying Their Identity

Go to **My Tool Library > Membership**.

### Adding a walk-in member

1. Click **Add a New Member**.
2. Fill in their name, address (Address Line 1, optional Line 2, City, State/Province, ZIP/Postal Code, Country), phone number, and email address. Email doubles as their sign-in username if they later create an online account, so it must be unique.
3. Leave the two verification fields (below) blank for now if you haven't yet checked their ID, or fill in just one if that's all the member has provided so far. Either way the member is added as **unverified** until both are on file, which does not block them from browsing or reserving tools online.
4. Save.

### Verifying a member's identity

Verification means recording proof that staff have checked the member's photo ID and a proof-of-address document. A member is "verified" if and only if **both** scan links are on file — there's no separate checkbox.

**Recommended process — collect documents with a Google Form:**

1. Create a Google Form with two **File upload** questions: "Photo ID" and "Proof of Address." Point the form's file uploads at a dedicated Google Drive folder created just for this purpose.
2. **Lock down the destination folder's sharing permissions** so only the staff who need it (or a specific access-controlled staff group) can open it.
3. Hand the member your tablet/kiosk (or a QR code to the form) to fill out and upload their ID and proof of address. Alternatively, staff can photograph the documents directly and upload them through the same form on the member's behalf.
4. Once submitted, open the folder, locate the member's two uploaded files, and get a shareable link to each (set link sharing to your organization/domain only, or to the specific staff group — not "Anyone with the link").
5. Go back to **Membership**, find the member, click **Edit**, and paste the two links into **Photo ID Scan URL** and **Proof of Address Scan URL**. Save.
6. The member now shows as **Verified** throughout the admin pages (including on the Loans & Reservations detail panel, so staff can see verification status at pickup). The member will also see that they are verified on their account page.

A member can also have just **one** of the two documents on file, but the member stays unverified until both are present.

To **remove** a document, clear its URL field on Edit and save. Clearing just one downgrades a verified member to unverified (their remaining document stays on file); clearing both deletes their verification record entirely.

> **Note:** If member verification is not important to your organization, an admin can simply type in any URL into both document fields to mark the user as verified. You may also choose to communicate with members that verification is not necessary **(see Setup > Member Verification Directions)**.

### Hosting Photos and Documents

This plugin never uploads or stores image files itself — every photo/document field is a plain link. Host the actual file somewhere else (Google Drive recommended) and paste the resulting link into the plugin:

- **Tool photos** are shown to the public — share them with "Anyone with the link."
- **Member verification documents** (ID, proof of address) are sensitive — share them only with staff, never publicly.

---

## 5. Starting a Loan for a Member

There are two ways to start a loan, depending on whether the member already has a reservation for the tool.

### Option A: Check out an existing reservation

Use this when the member reserved the tool online ahead of time and is now picking it up.

1. **Get the tool reserved first**, if it isn't already: the member signs in to their own account on the public catalog and clicks **Reserve** on the tool they want. This works the same whether they do it from home ahead of time or from a library computer/kiosk on-site. Reserving does **not** require verification — any signed-in member can reserve and join the queue.
2. Go to **My Tool Library > Loans & Reservations**.
3. Find the member's reservation (search by member name, tool name, or barcode). If the tool is available, you can check it out to **any** member with an active reservation for it — not just whoever is first in the queue — since staff make the final call in person.
4. Open the reservation's detail panel. Confirm the member's verification status shown there if your process requires it before releasing a tool (verification is a staff judgment call the software does not enforce — see [Section 4](#4-adding-a-new-member-in-person--verifying-their-identity)).
5. Pick a due date using the quick 7/14/21/30-day buttons (21 days is the site's default, set on the Setup page) or enter a custom date. Due dates can't be set in the past — the form blocks it and the site will reject a backdated date even if one is forced through.
6. Click **Check out to this member**. This converts the reservation into an active loan and removes it from the waiting queue.

If the tool is currently on loan to someone else, checkout isn't available — end that loan first (see [Section 6](#6-additional-workflows)) before checking it out to the next person in line.

> **Note:** Reserved tools can also be loaned to a member from the **Membership** page.

### Option B: Quick Loan (no reservation needed)

Use this for a walk-in member borrowing a tool on the spot, with no reservation on file — this is the everyday staff-side path for most in-person checkouts.

1. Go to **My Tool Library > Inventory**.
2. Find the tool and click its **Quick Loan** button.
3. In the Quick Loan window, start typing the member's name or email in the **Member** box and click their entry to select them. Once selected, a **Verified**/**Not Verified** pill appears so you can see at a glance whether they've provided their ID documents yet — the plugin doesn't block a loan to an unverified member, but flags it so you can make the judgment call in person (same as at reservation checkout).
4. Pick a due date using the quick 7/14/21/30-day buttons or enter a custom date. As with checkout, past dates aren't accepted.
5. Click **Create Loan**. The loan is created immediately — no reservation is created or required.

Quick Loan refuses to run if the tool is already checked out to someone else (end that loan first) or if you submit without selecting a member from the dropdown.

### Quick Reserve (reserve in person, no online account needed to use it)

For a walk-in who wants to reserve a tool that's currently unavailable (or just hold it without taking it home yet) instead of borrowing it on the spot:

1. Go to **My Tool Library > Inventory**, find the tool, and click **Quick Reserve** (next to Quick Loan in the tool's detail panel).
2. Pick the member the same way as Quick Loan — the same search box and Verified/Not Verified pill are used.
3. Click **Create Reservation**. There's no due date to set; it behaves exactly like a reservation the member made themselves online, and shows up the same way on Loans & Reservations and the member's own record.

Quick Reserve refuses to run if the member already has that tool on loan, or already has an active reservation for it.

---

## 6. Additional Workflows

### Renewing or ending a loan

On **Loans & Reservations**, open an active loan's detail panel:

- **Renew loan** — pick a new due date (quick buttons or custom) and submit. The field pre-fills with the _current_ due date, so submitting without changing anything is a safe no-op.
- **End loan (mark returned)** — marks the tool returned as of right now and makes it available again for the next reservation or a new one.

For a fast drop-off (member returns the tool with no other changes needed), you can instead go to **Inventory**, find the tool, expand its row, and click **Mark Returned** — same effect as "End loan" above, just reachable without leaving the Inventory page.

You can also manage a loan from the member's own record: on **Membership**, click a member's row to expand their detail panel, then click any tool listed under **Currently On Loan**. A pop-up opens with the same two actions — **Save New Due Date** (with 7/14/21/30-day quick buttons, same as Quick Loan) and **Mark as Returned** — so you don't have to leave the member's page to handle their loan.

### Managing a reservation from a member's record

On **Membership**, expand a member's detail panel and click any tool listed under **Active Reservations** to open a **Manage Reservation** pop-up:

- **Cancel Reservation** is always available — use it if the member no longer wants the tool or is unreachable.
- **Start Loan for This Member** only appears when the member is first in line for that tool. Pick a due date (7/14/21/30-day quick buttons or custom) and click it to check the tool out to them directly, closing the reservation in the same step. If they're not first in the queue, the pop-up shows a note instead and points you to Loans & Reservations if you need to override the queue order.

You can also cancel a reservation from **Loans & Reservations** directly: open the reservation's detail panel and click **Cancel reservation**.

### Handling overdue tools

The **Dashboard**'s "Overdue Tools" panel lists every loan past its due date at a glance. Follow up with the member directly (there is no built-in email/SMS notification system). Once the tool is back, mark it returned as described above.

### Reviewing library activity

The **Dashboard** offers configurable, resizable stat panels. Drag panels to reorder or resize them; your layout is saved per-admin-user.

Two panels are search-driven rather than always-on: **Tool History Lookup** and **Member History Lookup**. Type a tool or member's name into the search box, pick it from the dropdown, and click **View History** to see more than the Inventory/Membership pages show on their own:

- **Tool History Lookup** shows who has rented that tool and how many times each person has, plus a full loan-by-loan log (dates, due dates, returned/late/still-out status).
- **Member History Lookup** shows that member's complete loan history (not just their currently active loans, which is all Membership's own detail panel shows) plus their full reservation history, including past/expired reservations.

Tool History Lookup's search box includes retired tools, since the point is looking up history that still exists on record. Member History Lookup's search box excludes anonymized (deleted-account) members, though, since they no longer have a real name to search by &mdash; their loan activity still shows up grouped into a tool's own history, just under their "Former Member" placeholder.

### Backing up your data

Before any bulk import, plugin update, or major cleanup, go to **Setup > Export Data** and download either a full SQL dump or a ZIP of CSVs (one per table). Keep exports somewhere secure as they include full member contact details and verification links.

**If you are keeping one as a real backup, keep the `.sql` dump.** It is the only export that can restore the library. The CSV export is for reading in a spreadsheet — the bulk importers always assign brand-new ID numbers, so re-importing `members.csv` creates fresh records rather than restoring the old ones, and there is no importer at all for loans or reservations.

To restore a `.sql` dump you need database access (phpMyAdmin, the `mysql` command line, or `wp db import`) — there is no restore button in the plugin.

### Members' online accounts and the database reset

Running **Setup > Run Database Setup** on a library that already has data deletes every record, but it **does not touch members' WordPress sign-ins**. Their accounts and passwords keep working. What breaks is the connection between the two: each record's ID number restarts from 1, so the sign-ins point at records that are gone or, worse, at somebody else's.

The plugin protects against the "somebody else's" case — it checks that a record's email matches the account signing in, and refuses to show a record it can't match. A member in that state sees "we couldn't match your sign-in to a membership record" rather than a stranger's details.

To put things right:

- **Restore the `.sql` dump.** IDs come back as they were and every sign-in reconnects on its own. This is the fix.
- **Or re-add the member with the exact same email address** (Membership > Add a New Member, or a CSV import that includes their email). The next time they open the site their account reconnects itself automatically — no admin step needed.
- If a member is stuck and their email in **Membership** does *not* match the email on their WordPress user (under **Users**), set the record's email to match theirs and save. That reconnects them, and from then on changing their email from either screen keeps both sides in step.

> **Note:** Deleting a member now leaves their WordPress sign-in alone if it can't be confirmed as theirs, and tells you so. Remove it yourself under **Users** if it is no longer wanted — this is deliberate, because deleting the wrong person's sign-in cannot be undone.

### Deleting a member

Click **Delete** next to a member on the **Membership** page. What actually happens depends on whether that member has ever borrowed or reserved a tool:

- **No loan or reservation history** — the member is removed outright: their record and their WordPress account are both permanently gone.
- **Has loan or reservation history** — deleting their record outright would corrupt tool-level statistics (like a tool's total-loans count), so instead their personal data is **anonymized**: name, address, phone, and email are replaced with placeholders, their verification documents are deleted, and their WordPress account is deleted, but their loan/reservation rows are left untouched so the library's records stay accurate. The row then shows a **Removed** badge in place of Verified/Not Verified, and Edit/Delete disappear — there's nothing left to do with it.

In both cases the WordPress account is only deleted when it can be confirmed as that member's (its email still matches the record). If it can't — usually because a database reset renumbered the records — the account is left in place and the confirmation message says so, since deleting the wrong person's sign-in is not reversible. Remove it under **Users** if it is no longer needed.

Either way, any reservation the member currently has (for any tool) is **cancelled** as part of the delete. A currently open loan is left alone, since deleting the account doesn't return the tool.

Either outcome is **permanent and cannot be undone**, and the confirmation dialog explains which one a given member will get before you confirm.

Members can also do this themselves: on their **Account** page, under **Danger Zone**, clicking **Delete Account and Remove Personal Data** walks them through the same two-step confirmation and produces the exact same outcome (full removal or anonymization, depending on their history, plus cancelling any active reservation).

### Retiring or deleting a tool

Tools work the same way in spirit, without the personal-data issue:

- **No loan or reservation history** — you may click **Delete** on the **Inventory** page to remove it outright.
- **Has loan or reservation history** — click **Retire** instead. This hides the tool from the public catalog and blocks any new loan or reservation for it (any reservations already queued for it are automatically cancelled, with a note on-screen telling you how many), while keeping the tool's row and its full history intact. A currently open loan is left alone and can still be ended normally whenever it's resolved.

Unlike a member or tool delete, retiring is fully reversible — click **Reactivate** on a retired tool to bring it back into service. Retired tools drop out of the Inventory page's default list; use the **Retired?** filter under Advanced Search (set to "Active + retired" or "Retired only") to find them.

Export a backup first (see "Backing up your data" above) if you're ever unsure before deleting or anonymizing something. Deletion and anonymization are the two actions in this plugin that can't be undone.

### Adjusting branding and appearance

The **Setup** page's General Details section controls the logo, colors, fonts, button style, and corner radius used across both the admin pages and the public-facing pages. Update it any time your organization's branding changes.
