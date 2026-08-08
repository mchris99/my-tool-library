# My Tool Library — Staff Workflow Guide

Reference steps for the most common jobs staff will do in the plugin. This guide will benefit of all staff, but is written with system administrators in mind as the audience. Some of the actions listed here will not be executable without administrator privileges.

Using the admin pages requires signing in with a WordPress account that has either the **Administrator** or the **Editor** role. Editor is the everyday desk role; Administrator is for whoever runs the library. See [Staff Roles and Permissions](#staff-roles-and-permissions) for exactly what each one can do.

## Contents

1. [Staff Roles and Permissions](#staff-roles-and-permissions)
2. [Initial Setup](#1-initial-setup)
3. [Creating a New Staff Account](#2-creating-a-new-staff-account)
4. [Adding Inventory Items](#3-adding-inventory-items)
5. [Adding a New Member In-Person & Verifying Their Identity](#4-adding-a-new-member-in-person--verifying-their-identity)
6. [Starting a Loan for a Member](#5-starting-a-loan-for-a-member)
7. [Additional Workflows](#6-additional-workflows)
    - [Members](#members)
    - [Inventory](#inventory)
    - [Other](#other)

---

## Staff Roles and Permissions

There are two staff roles, both of them standard WordPress roles.

**Editor**: the role for anyone working the desk. They can run the library day to day: add and edit members, add and edit tools, check tools in and out, manage reservations, and read this guide. This is the role to give volunteers and staff by default.

**Administrator**: everything an Editor can do, plus access to the **Setup** page and the ability to delete a member's record or a tool.

### What each role can do

| Task                                               | Editor | Administrator |
| -------------------------------------------------- | :----: | :-----------: |
| View the Dashboard                                 |   ✅   |      ✅       |
| Add or edit members                                |   ✅   |      ✅       |
| Record member trainings                            |   ✅   |      ✅       |
| View verification documents, mark members verified |   ✅   |      ✅       |
| Add, edit, retire and tools                        |   ✅   |      ✅       |
| Check tools out, renew, and mark returned          |   ✅   |      ✅       |
| Create, cancel and fulfil reservations             |   ✅   |      ✅       |
| Read the Workflows guide                           |   ✅   |      ✅       |
| Bulk-import tools or members                       |   ❌   |      ✅       |
| Delete a member's record                           |   ❌   |      ✅       |
| Delete a tool from inventory                       |   ❌   |      ✅       |
| Open the Setup page (and everything on it)         |   ❌   |      ✅       |
| — Branding, colors, fonts, logo                    |   ❌   |      ✅       |
| — Categories, tags and trainings lists             |   ❌   |      ✅       |
| — Export data (`.sql` / CSV)                       |   ❌   |      ✅       |
| — Run Database Setup                               |   ❌   |      ✅       |

An Editor doesn't see a **Setup** tab at all, and doesn't see a **Delete** button on the Membership or Inventory page. **Retire** is still theirs on Inventory, and it's the better option for almost everything Delete gets reached for — it pulls the item from the public catalog and blocks new loans while keeping its history, and it can be undone.

> **Note:** Editor is a full WordPress role, so it also grants the ability to write and edit posts and pages elsewhere on your site. If that matters to you, restrict it with a role-management plugin; the tool library only ever checks whether the account is an Editor or an Administrator.

### Members can always delete their own account

None of the above stops a **member** removing themselves, which they can do without staff permission. That works regardless of which staff roles exist, and it produces exactly the same outcome as a staff deletion (see [Deleting a member](#deleting-a-member)).

---

## 1. Initial Setup

Do this once, when the plugin is first installed on the site.

1. **Activate the plugin** from the WordPress **Plugins** screen.
2. Go to **Settings > General** in WordPress and confirm the site **Timezone** is set correctly (a named city, not just a UTC offset, so Daylight Saving is handled automatically). Every reservation and loan timestamp in the plugin is based on this setting — if it's left at the default (UTC), your timestamps won't match local time, and past timestamps can't be corrected retroactively.
3. Go to **My Tool Library > Setup**.
4. Under **Database Configuration**, slide the confirmation toggle, click **Run Database Setup**, and type `Delete ALL my data` into the box that pops up. This creates all of the plugin's database tables and is required before any other page will work.
    - **Only do this once.** On a library that is already running this is not a repair tool: it always deletes every member, tool, loan and reservation and rebuilds the tables empty, with no undo. That is why it makes you type the phrase out. If you are troubleshooting a live library, use **Export Data** first.
5. On the **Setup** page, fill in:
    - **General Details**: organization name, logo URL, brand colors/fonts, button style, and an optional **Verified Badge Image URL** — when set, a verified member sees this image on their own account page instead of the plain green "Verified" pill.
    - **Reservations & Loans**: the default loan length (in days) used when checking a reservation out as a loan, and the **Reservation Hold Period** — how long a tool is held for a member once it's ready for them to collect (default 14 days; see [Reservations that expire on their own](#reservations-that-expire-on-their-own)).
    - **Categories & Tags**: add or remove the tool categories (e.g. Woodworking, Plumbing) and tags (e.g. Cordless, Requires PPE) you plan to use — these are the choices staff pick from when adding inventory.
    - **Member Trainings**: add the trainings you offer. Each one has three editable fields, all changeable later: the **name**, an optional **Badge Image URL** (upload the image to the WordPress Media Library and paste its File URL), and **Valid For** — how many months the training stays current once someone completes it, or blank if it never expires. See [Trainings and certifications](#trainings-and-certifications).
    - **Consider Giving Message** and **Consider Giving Link**: an optional fundraising ask. See [Asking members to give](#asking-members-to-give).
6. Copy the **Public Page Link** shown on the Setup page and add it to your site's navigation menu (or link to it from any page/post, or link it in a button). This is the one link your community needs to browse the catalog, reserve tools, and sign up.
7. Once categories/tags exist, move on to [Adding Inventory Items](#3-adding-inventory-items) and [Adding a New Member](#4-adding-a-new-member-in-person--verifying-their-identity) — both support **bulk import**, which is the fastest way to load an existing spreadsheet of tools or members:
    - On the **Inventory** or **Membership** page, find the **Bulk Import from CSV** panel.
    - Click **Download the CSV template** — it has the exact column headers and one example row.
    - Fill in your data in rows (delete the example row before uploading), keeping one tool or member per row. Column order doesn't matter as long as the header names match.
    - Upload the completed CSV and submit. The plugin reports how many rows succeeded and lists the reason for any row that failed (e.g. a missing required field or an invalid category), so you can fix just those rows and re-upload.
    - On the members template, the **`trainings`** column takes a training name, a colon, and the date it was completed. Separate several with semicolons: `Ladder Safety: 8/4/2026; Welding Basics: 8/3/2026`. Names must match the ones on your Setup page, though capitalization doesn't matter. If one entry is malformed or names a training that doesn't exist, the member is still imported — just without that training — and the reason is listed in the results, so you can fix it on their record rather than re-running the whole file.
8. Use the **Export Data** section on the Setup page any time you want a full backup (a SQL dump or a ZIP of CSVs, one per table) — good practice before any bulk import or major change.
9. **Set up accounts for the rest of your staff.** Everyone working the desk should get their own **Editor** account rather than sharing yours — see [Creating a New Staff Account](#2-creating-a-new-staff-account). Editors can do everything day to day; keep **Administrator** for whoever runs the library, since it's what unlocks the Setup page and deleting members.

---

## 2. Creating a New Staff Account

Staff access is controlled entirely by WordPress's built-in user roles. This plugin has no separate staff login system. See [Staff Roles and Permissions](#staff-roles-and-permissions) for what each role can do.

### Adding an Editor (the usual choice)

Give **Editor** to anyone working the desk: volunteers, front-of-house staff, anyone who checks tools in and out or signs members up. They get everything except the Setup page and deleting members.

1. In the WordPress dashboard, go to **Users > Add New**.
2. Enter the staff member's username and email address.
3. Under **Role**, select **Editor**.
4. Click **Add New User**. WordPress emails them a link to set their own password (or set one yourself and share it securely).
5. They can now sign in, either through the normal WordPress login (`/wp-login.php` or `/wp-admin/`) or through the plugin's branded **Sign In** page (linked from the public catalog's footer), which routes staff straight into the **My Tool Library** admin portal.

To change someone who already has an account, go to **Users**, click their name, and set **Role** to **Editor**.

### Adding an Administrator

Give **Administrator** only to the people who run the library as it grants full control of the entire WordPress site.

Follow the same steps, choosing **Administrator** at step 3.

> **Note:** Both of these are full WordPress roles, so they grant access beyond this plugin. Editor can write and edit posts and pages, and Administrator can do anything at all on the site. Give out Administrator sparingly.

> **Note:** If a staff member will also borrow tools as a member, have them use a different username (or email) for their staff account than their personal email address, so the two accounts stay separate. Otherwise, they will not be able to borrow as a member.

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

Use the **Bulk Import from CSV** panel described in [Initial Setup](#1-initial-setup), step 7. This is the fastest way to load an existing inventory spreadsheet, and can also be used later to add a new batch of donated or purchased tools. **Admin** action only.

> **Note:** The database makes no assumptions about barcode data, other than that it is required and will be unique to the tool. Barcodes can include any text/number up to 100 characters.

### Private Notes (staff-only)

The **Private Notes** field is for anything staff need on record about a tool that members should never see. This is a good way to make notes on maintenance needs or conditional donation. Unlike Description and Components, it's never shown on the public catalog. It only appears in the Inventory page's detail view (click a tool's row to expand it), in a highlighted "Private Notes" box. Leave it blank if there's nothing to record. Use the **Has Private Notes?** filter under Advanced Search to find every tool that has one on file.

---

## 4. Adding a New Member In-Person & Verifying Their Identity

Go to **My Tool Library > Membership**.

### Adding a walk-in member

1. Click **Add a New Member**.
2. Fill in their name, address, phone number, and email address. Email doubles as their sign-in username if they later create an online account, so it must be unique.
3. Leave the two verification fields (below) blank for now if you haven't yet checked their ID, or fill in just one if that's all the member has provided so far. Either way the member is added as **unverified** until both are on file, which does not block them from browsing or reserving tools online.
4. Under **Trainings**, tick anything they've already completed. Ticking one opens a completion date box next to it. See [Trainings and certifications](#trainings-and-certifications).
5. Leave **Email them a link to choose their password** ticked unless you have a reason not to. See below.
6. Save.

### Giving a new member their online sign-in

Adding a member creates their website sign-in automatically. It has no password to begin with. They choose a password through a link you email them, which is the same mechanism as a normal password reset.

Leaving the box ticked sends that email the moment you save. You can send the email later from the **Send setup link** button on their row.

The **Sign-in** column in the members table tells you where anyone stands:

| Shows           | Means                                        | What to do                                                         |
| --------------- | -------------------------------------------- | ------------------------------------------------------------------ |
| **Active**      | They have set a password.                    | Nothing. A member who has forgotten it uses "Lost your password?". |
| **No password** | The sign-in exists, they have never set one. | **Send setup link** re-sends the email.                            |
| **None**        | No sign-in yet, creation failed.             | **Send setup link** creates it and emails them in one go.          |

> **Note:** Anyone with a membership on file can use **Lost your password?** on the sign-in page, even if they have no account yet. If they try to sign up again instead, the site tells them they already have a membership and points them at the same place.

### After a CSV import: creating logins in bulk

A CSV import deliberately does **not** create sign-ins or send any email. Two reasons: making hundreds of accounts in a single request would time out, and importing a legacy membership list should not email everybody on it immediately.

Instead, once the import finishes, open the **Member Logins** panel below the import box (administrators only). It shows how many members have no sign-in and how many have never set a password, then gives you two buttons:

1. **Create logins** — creates the missing accounts. Sends nothing. Safe to run whenever.
2. **Send setup emails** — emails everyone who has not chosen a password.

Both work through the list a batch at a time so they cannot tie up the request, so if the panel says some remain, just press the button again. Nobody is emailed twice within 24 hours unless you tick the box to include them, and it is safe to press either button more than once.

If the panel warns that some members' addresses **belong to a different WordPress account**, those need a person. It usually means the address belongs to a staff account, or to a leftover sign-in from a member deleted earlier. Sort it out under **Users**, then run **Create logins** again.

### Verifying a member's identity

Verification means recording proof that staff have checked the member's photo ID and a proof-of-address document. A member is "verified" if and only if **both** scan links are on file.

**Recommended process — collect documents with a Google Form:**

1. Create a Google Form with two **File upload** questions: "Photo ID" and "Proof of Address." Point the form's file uploads at a dedicated Google Drive folder created just for this purpose.
2. **Lock down the destination folder's sharing permissions** so only the staff who need it (or a specific access-controlled staff group) can open it.
3. Hand the member your tablet/kiosk (or a QR code to the form) to fill out and upload their ID and proof of address. Alternatively, staff can photograph the documents directly and upload them through the same form on the member's behalf.
4. Once submitted, open the folder, locate the member's two uploaded files, and get a shareable link to each (set link sharing to your organization/domain only, or to the specific staff group — not "Anyone with the link").
5. Go back to **Membership**, find the member, click **Edit**, and paste the two links into **Photo ID Scan URL** and **Proof of Address Scan URL**. Save.
6. The member now shows as **Verified** throughout the staff pages (including on the Loans & Reservations detail panel, so staff can see verification status at pickup). The member will also see that they are verified on their account page.

A member can also have just **one** of the two documents on file, but the member stays unverified until both are present.

To remove a document, clear its URL field on Edit and save. Clearing just one downgrades a verified member to unverified (their remaining document stays on file); clearing both deletes their verification record entirely.

> **Note:** If member verification is not important to your organization, an admin can simply type in any URL into both document fields to mark the user as verified. You may also choose to communicate with members that verification is not necessary **(see Setup > Member Verification Directions)**.

### Hosting Photos and Documents

This plugin never uploads or stores image files itself. Every photo/document field is a plain link. Host the actual file somewhere else (Google Drive recommended) and paste the resulting link into the plugin:

- **Tool photos** and **Training Badges** are shown to the public — share them with "Anyone with the link."
- **Member verification documents** (ID, proof of address) are sensitive — share them only with staff, never publicly.

---

## 5. Starting a Loan for a Member

There are two ways to start a loan, depending on whether the member already has a reservation for the tool.

### Option A: Check out an existing reservation

Use this when the member reserved the tool online ahead of time and is now picking it up.

1. **Get the tool reserved first**, if it isn't already: the member signs in to their own account on the public catalog and clicks **Reserve** on the tool they want. Reserving does **not** require member verification.
2. Go to **My Tool Library > Loans & Reservations**.
3. Find the member's reservation (search by member name, tool name, or barcode). If the tool is available, you can check it out to **any** member with an active reservation for it, not just whoever is first in the queue.
4. Open the reservation's detail panel. Confirm the member's verification status shown there if your process requires it before releasing a tool (verification is a staff judgment call the software does not enforce, see [Section 4](#4-adding-a-new-member-in-person--verifying-their-identity)).
5. Pick a due date using the quick 7/14/21/30-day buttons (21 days is the site's default, set on the Setup page) or enter a custom date.
6. Click **Check out to this member**. This converts the reservation into an active loan and removes it from the waiting queue.

If the tool is currently on loan to someone else, checkout isn't available. End that loan first (see [Section 6](#6-additional-workflows)) before checking it out to the next person in line.

> **Note:** Reserved tools can also be loaned to a member from the **Membership** page.

### Option B: Quick Loan (no reservation needed)

Use this for a walk-in member borrowing a tool on the spot, with no reservation on file.

1. Go to **My Tool Library > Inventory**.
2. Find the tool and click its **Quick Loan** button.
3. In the Quick Loan window, start typing the member's name or email in the **Member** box and click their entry to select them. Once selected, a **Verified**/**Not Verified** pill appears so you can see at a glance whether they've provided their ID documents yet (see [Section 4](#4-adding-a-new-member-in-person--verifying-their-identity)).
4. Pick a due date using the quick 7/14/21/30-day buttons or enter a custom date.
5. Click **Create Loan**. The loan is created immediately, no reservation is created or required.

Quick Loan refuses to run if the tool is already checked out to someone else (end that loan first) or if you submit without selecting a member from the dropdown.

### Quick Reserve (reserve in person, no online account needed to use it)

Use this for a walk-in member who wants to reserve a tool for future use.

1. Go to **My Tool Library > Inventory**, find the tool, and click **Quick Reserve** (next to Quick Loan in the tool's detail panel).
2. Pick the member the same way as Quick Loan — the same search box and Verified/Not Verified pill are used.
3. Click **Create Reservation**. There's no due date to set.

Quick Reserve refuses to run if the member already has that tool on loan, or already has an active reservation for it.

---

## 6. Additional Workflows

### Members

#### Managing a reservation from a member's record

On **Membership**, expand a member's detail panel and click any tool listed under **Active Reservations** to open a **Manage Reservation** pop-up:

- **Cancel Reservation** is always available, this will remove the member from the waiting queue for the tool.
- **Start Loan for This Member** only appears when the member is first in line for that tool. Pick a due date (7/14/21/30-day quick buttons or custom) and click it to check the tool out. This action also closes the member's reservation in the same step. If they're not first in the queue, the pop-up shows a note instead and points you to Loans & Reservations if you need to override the queue order.

You can also cancel a reservation from **Loans & Reservations** directly: open the reservation's detail panel and click **Cancel reservation**.

#### Asking members to give

**Setup > General Details** has two optional fields that put a **Consider Giving** section in front of signed-in members. They will see **Consider Givng** on their **Account** page, above Your details, and on **My Loans & Reservations**, below My Reservations.

- **Consider Giving Message** — Your message to members asking for a donation.
- **Consider Giving Link** — Where the **Give Now** button sends people (your donation page, a fundraising platform, etc.).

**The message controls whether the section appears at all.** Clear it and the section disappears from both pages. **The link only controls the button.** You may leave it blank to hide the button but keep the message (use this setting if you'd rather people give in person).

> **Note:** Only ordinary `http://` and `https://` web addresses are accepted for the link.

#### Trainings and certifications

A training records that a member has been shown how to use something safely. Each one is set up under **Setup > Member Trainings**:

| Field               | What it does                                                                                                   |
| ------------------- | -------------------------------------------------------------------------------------------------------------- |
| **Name**            | What the training is called. Renaming it does not alter a member's training completion record.                 |
| **Badge Image URL** | Optional. Shown on the member's own account page instead of a plain green pill.                                |
| **Valid For**       | How many months it stays current, counted from the day each member completed it. Blank means it never expires. |

**Recording a training.** On the **Membership** page, add or edit a member, tick each training they've completed and set the date they completed it. Backdating a training the member took last year will correctly show it as closer to expiring, or already expired.

**When a certification lapses.** Nothing is deleted. The record stays on the member forever; it just stops counting as current. In their detail panel on the Membership page you'll see every training they've ever completed, each marked **Current** or **Expired** with its completion and expiry dates. To renew one, edit the member and set the completion date to the day they retook it.

**Changing "Valid For" applies immediately and retroactively.** Each member's expiry is worked out from their own completion date and the training's current setting. Shortening a period can therefore expire people straight away, and lengthening it can bring lapsed certifications back.

**Finding qualified members.** On the **Membership** page, open **Advanced Search** and use the **Trainings** dropdown. Tick one or more trainings to show only members who hold _all_ of them, or use **Select all** to find members who've completed everything. This matches on **current** certifications only.

**What members see.** On their own account page, the badges near the top show only what they're currently certified in. A collapsible **Trainings** section below lists everything they've ever completed, expired included, with dates and status.

#### When a member forgets their password

Members reset their own password without staff involvement:

1. On the **Sign In** page they click **Lost your password?** in the footer.
2. They enter their email address and we send them a link.
3. The link opens a **Choose a New Password** page on your site. Once saved, they're returned to Sign In and can use the new password.
4. A second email confirms the change went through.

If a member tells you they received a password change confirmation email they weren't expecting, have them change their password. This means somebody completed a password reset on their account, and they no longer control it.

> **Note:** In the case of possible unauthorized account access, you should check that the email address on the account hasn't been altered too. If it has, you can manually change the email and password through the WordPress **Users** menu. After doing so, send a password reset link to the member so they can set their own password.

If the email never arrives for anyone, that's a mail problem on the site rather than a plugin one. WordPress sends emails through whatever mail setup your host provides, and many hosts need an SMTP plugin before `wp_mail()` reliably reaches inboxes.

See [Giving a new member their online sign-in](#giving-a-new-member-their-online-sign-in) for a member who has **never** set a password. They can still use the **Lost your password?** feature. Note that a member setting their very first password does **not** get the "your password has been changed" confirmation described above.

#### Members' online accounts and the database reset

Running **Setup > Run Database Setup** on a library that already has data deletes every record, but it **does not touch members' WordPress sign-ins**. Their accounts and passwords keep working. What breaks is the connection between the two: each record's ID number restarts from 1, so the sign-ins point at records that are gone or, worse, at somebody else's.

The plugin protects against the a user sign-in pointing to a different member: it checks that a record's email matches the account signing in, and refuses to show a record it can't match. A member in that state sees "we couldn't match your sign-in to a membership record".

To put things right:

- **Restore the `.sql` dump.** IDs come back as they were and every sign-in reconnects on its own. You should access the database directly when restoring the dump file.
- **Or re-add the member with the exact same email address** (Membership > Add a New Member, or a CSV import that includes their email). Their existing sign-in is found and reconnected to the new record on the spot, and they keep the password they already had. No setup email is sent, because there is nothing for them to set up. If you re-added them by CSV, press **Create logins** in the **Member Logins** panel afterwards to reconnect the whole batch at once.
- If a member is stuck and their email in **Membership** does _not_ match the email on their WordPress user (under **Users**), set the record's email to match theirs and save.

> **Note:** Deleting a member leaves their WordPress sign-in alone if it can't be confirmed as theirs, and tells you so. Remove it yourself under **Users** if it is no longer wanted. This is deliberate, because deleting the wrong person's sign-in cannot be undone.

#### Deleting a member

**Administrators only.** Editors have no **Delete** button on the Membership page. If a member needs removing and you're an Editor, ask an administrator, or point the member at the self-service option below, which is always available to them.

Click **Delete** next to a member on the **Membership** page. Deleting a member removes the person, not the library's records of what they did.

**What is permanently removed:**

- Their name, address, phone number and email, replaced with placeholders.
- Their verification documents (photo ID and proof-of-address links).
- Any private staff notes on their record.
- Their **WordPress sign-in**, account and all its settings. They cannot log in again.

**What is kept:**

- Every loan they ever had, so each tool's borrowing history and totals stay correct.
- Their reservations, past and present.
- The trainings they completed.

Their row stays on the Membership page as **Former Member** with a **Removed** badge in place of Verified/Not Verified, and Edit/Delete disappear.

The WordPress account is only deleted when it can be confirmed as that member's (its email still matches the record). If it can't — usually because a database reset renumbered the records — the account is left in place and the confirmation message says so. Remove it yourself under **Users** in that case.

Any reservation the member currently has is **cancelled** as part of the delete. A currently open loan is left alone, so be sure to end the loans manually or mark the tools as retired if missing.

Deletion and anonymization are the two actions in this plugin that are **permanent and cannot be undone.** Export a backup first (see "Backing up your data" above) if you're ever unsure before deleting a member.

**Members can always do this themselves, without staff involvement.** Signed in on the public site, a member goes to their **Account** page and, under **Danger Zone**, clicks **Delete Account and Remove Personal Data**. That walks them through the same two-step confirmation and produces exactly the same outcome described above.

### Inventory

#### Renewing or ending a loan

On **Loans & Reservations**, open an active loan's detail panel:

- **Renew loan** — pick a new due date (quick buttons or custom) and submit. The field pre-fills with the _current_ due date, so submitting without changing anything is safe.
- **End loan (mark returned)** — marks the tool returned as of right now and makes it available again for the next reservation or a new one.

For a fast drop-off (member returns the tool with no other changes needed), you can instead go to **Inventory**, find the tool, expand its row, and click **Mark Returned**. The search function can be completed with a barcode scanner for quick processing.

You can also manage a loan from the member's own record: on **Membership**, click a member's row to expand their detail panel, then click any tool listed under **Currently On Loan**. A pop-up opens with the same two actions — **Save New Due Date** (with 7/14/21/30-day quick buttons, same as Quick Loan) and **Mark as Returned** — so you don't have to leave the member's page to handle their loan.

#### Reservations that expire on their own

A tool isn't held forever. Once a reservation becomes **collectable**, meaning the member is at the front of the queue _and_ the tool is back on the shelf, a countdown starts. If they don't come in within the **Reservation Hold Period** (set on the Setup page), the reservation is cancelled automatically with today's date, and the tool passes to the next person in line.

Where you'll see it:

- **Loans & Reservations** — open any reservation showing **Ready** and its detail panel shows a **Collect by** date.
- **The member's own My Loans & Reservations page** — they see "Please collect by …" against anything waiting for them.

To change the period, go to **Setup > Reservations & Loans** and set **Reservation Hold Period**. Anything from 1 to 365 days, or tick **Never expires** to hold reservations indefinitely.

> **Note:** Changing the number applies to reservations already waiting. Shortening the period can therefore expire some reservations on the next page load. Lengthening it gives everyone currently waiting more time.

Expiry is checked whenever anyone loads a page, so the list is correct whenever you look at it.

#### Handling overdue tools

The **Dashboard**'s "Overdue Tools" panel lists every loan past its due date at a glance. Follow up with the member directly (there is not yet a built-in email/SMS notification system). Once the tool is back, mark it returned as described above.

#### Retiring or deleting a tool

You might retire a tool at the end of its useful life, or if it is stolen or missing. Deleting a tool is usually for removing tools that were added in error.

- **No loan or reservation history** — an administrator may click **Delete** on the **Inventory** page to remove it outright. Editors don't see this button; they should either use **Retire** or ask an administrator.
- **Has loan or reservation history** — click **Retire** instead. This hides the tool from the public catalog and blocks any new loan or reservation for it (any reservations already queued for it are automatically cancelled, with a note on-screen telling you how many), while keeping the tool's row and its full history intact. A currently open loan is left alone and can still be ended normally whenever it's resolved.

Unlike a member or tool delete, retiring is fully reversible. Click **Reactivate** on a retired tool to bring it back into service. Retired tools drop out of the Inventory page's default list; use the **Retired?** filter under Advanced Search (set to "Active + retired" or "Retired only") to find them.

You can document the reason for retiring a tool under the tool's **private notes**.

Export a backup first (see "Backing up your data" above) if you're ever unsure before deleting a tool. Deletion and anonymization are the two actions in this plugin that can't be undone.

### Other

#### Reviewing library activity

The **Dashboard** offers configurable, resizable stat panels. Drag panels to reorder or resize them; your layout is saved per-user.

Two panels are search-driven rather than always-on: **Tool History Lookup** and **Member History Lookup**. Type a tool or member's name into the search box, pick it from the dropdown, and click **View History** to see more than the Inventory/Membership pages show on their own:

- **Tool History Lookup** shows who has rented that tool and how many times each person has, plus a full loan-by-loan log (dates, due dates, returned/late/still-out status).
- **Member History Lookup** shows that member's complete loan history (not just their currently active loans, which is all Membership's own detail panel shows) plus their full reservation history, including past/expired reservations.

Tool History Lookup's search box includes retired tools. Member History Lookup's search box excludes anonymized (deleted-account) members since they no longer have a real name to search by.

#### Backing up your data

Before any bulk import, plugin update, or major cleanup, go to **Setup > Export Data** and download either a full SQL dump or a ZIP of CSVs. Keep exports somewhere secure as they include full member contact details and verification links.

**If you are keeping one as a real backup, keep the `.sql` dump.** It is the only export that can restore the library. The CSV export is for reading in a spreadsheet. The bulk importers always assign brand-new ID numbers, so re-importing `members.csv` creates fresh records rather than restoring the old ones, and there is no importer at all for loans or reservations.

To restore from a `.sql` dump you need database access (phpMyAdmin, the `mysql` command line, or `wp db import`) — there is no restore button in the plugin.

#### Adjusting branding and appearance

The **Setup** page's General Details section controls the logo, colors, fonts, button style, and corner radius used across both the admin pages and the public-facing pages. Update it any time your organization's branding changes. Default is to inherit appearance settings.

#### Making changes to the Staff Workflows page

The **Workflows** page is converted to `HTML` directly from the `staff-workflows.md` file. If you want to add to, remove from, or otherwise change the directions that your staff will see, simply open the `staff-workflows.md` file in your favorite text editor, write your edits, and save. Changes can be seen by staff on page refresh.
