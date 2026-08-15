# My Tool Library — Staff Workflow Guide

Reference steps for the most common jobs staff do in the plugin. It is written with system administrators as the audience, and some actions here need administrator privileges.

The admin pages require a WordPress account with the **Administrator** or **Editor** role. Editor is the everyday desk role; Administrator is for whoever runs the library. See [Staff Roles and Permissions](#staff-roles-and-permissions) for what each can do.

## Contents

1. [Staff Roles and Permissions](#staff-roles-and-permissions)
2. [Initial Setup](#1-initial-setup)
3. [Creating a New Staff Account](#2-creating-a-new-staff-account)
4. [Adding Inventory Items](#3-adding-inventory-items)
5. [Adding a New Member In-Person & Verifying Their Identity](#4-adding-a-new-member-in-person--verifying-their-identity)
6. [Starting a Loan for a Member](#5-starting-a-loan-for-a-member)
7. [Member Agreements](#6-member-agreements)
8. [Additional Workflows](#7-additional-workflows) — [Members](#members), [Inventory](#inventory), [Other](#other)

---

## Staff Roles and Permissions

Two staff roles, both standard WordPress roles. **Editor** is for anyone working the desk: add and edit members and tools, check tools in and out, manage reservations, and read this guide. Give it to volunteers and staff by default. **Administrator** adds the **Setup** page and the ability to delete a member's record or a tool.

### What each role can do

| Task                                                                     | Editor | Administrator |
| ------------------------------------------------------------------------ | :----: | :-----------: |
| View the Dashboard                                                       |   ✅   |      ✅       |
| Add or edit members                                                      |   ✅   |      ✅       |
| Record member trainings                                                  |   ✅   |      ✅       |
| Record a member's agreement (Add Member, or the Record agreement dialog) |   ✅   |      ✅       |
| Ask one member to agree (Send agreement request)                         |   ✅   |      ✅       |
| Send agreement requests in bulk                                          |   ❌   |      ✅       |
| Download a member's agreement record                                     |   ❌   |      ✅       |
| View verification documents, mark members verified                       |   ✅   |      ✅       |
| Add, edit and retire tools                                               |   ✅   |      ✅       |
| Check tools out, renew, and mark returned                                |   ✅   |      ✅       |
| Create, cancel and fulfil reservations                                   |   ✅   |      ✅       |
| Read the Workflows guide                                                 |   ✅   |      ✅       |
| Bulk-import tools or members                                             |   ❌   |      ✅       |
| Delete a member's record                                                 |   ❌   |      ✅       |
| Delete a tool from inventory                                             |   ❌   |      ✅       |
| Open the Setup page (and everything on it)                               |   ❌   |      ✅       |
| — Branding, colors, fonts, logo                                          |   ❌   |      ✅       |
| — Categories, tags and trainings lists                                   |   ❌   |      ✅       |
| — Member Agreements list                                                 |   ❌   |      ✅       |
| — Export data (`.sql` / CSV)                                             |   ❌   |      ✅       |
| — Run Database Setup                                                     |   ❌   |      ✅       |

An Editor doesn't see a **Setup** tab at all.

> **Note:** Editor is a full WordPress role, so it also grants editing of posts and pages elsewhere on your site. Restrict it with a role-management plugin if that matters; the tool library only checks whether the account is an Editor or an Administrator.

### Members can always delete their own account

None of the above stops a **member** removing themselves without staff permission. The outcome is identical to a staff deletion (see [Deleting a member](#deleting-a-member)), including the emails to the member and the site administrator, so staff always hear about it.

---

## 1. Initial Setup

Do this once, when the plugin is first installed on the site.

1. **Activate the plugin** from the WordPress **Plugins** screen.
2. Go to **Settings > General** and set the site **Timezone** to a named city rather than a UTC offset, so Daylight Saving is handled automatically. Every reservation and loan timestamp uses this setting, and past timestamps cannot be corrected retroactively.
3. Go to **My Tool Library > Setup**.
4. Under **Database Configuration**, slide the confirmation toggle, click **Run Database Setup**, and type `Delete ALL my data` into the box that pops up. This creates all of the plugin's database tables and is required before any other page will work.
    - **Only do this once.** On a library that is already running, it deletes every member, tool, loan and reservation and rebuilds the tables empty, with no undo. That is why it makes you type the phrase out. To troubleshoot a live library, use **Export Data** first.
5. On the **Setup** page, fill in:
    - **General Details**: organization name, logo URL, brand colors/fonts, button style, and an optional **Verified Badge Image URL**, shown on a verified member's account page in place of the plain green "Verified" pill.
    - **Reservations & Loans**: the default loan length in days used when checking a reservation out, and the **Reservation Hold Period**, how long a tool is held once it's ready to collect (default 14 days; see [Reservations that expire on their own](#reservations-that-expire-on-their-own)).
    - **Categories & Tags**: the tool categories (e.g. Woodworking, Plumbing) and tags (e.g. Cordless, Requires PPE) staff pick from when adding inventory.
    - **Member Trainings**: the trainings you offer. Each has a **name**, an optional **Badge Image URL** (upload the image to the WordPress Media Library and paste its File URL), and **Valid For**, how many months it stays current after someone completes it, or blank if it never expires. All three are changeable later. See [Trainings and certifications](#trainings-and-certifications).
    - **Member Agreements**: the statements a member must agree to before creating an account (e.g. a liability waiver). Choose a mode at the top of the section: **Off**, **Track signed paper only**, or **Full — members agree online**. See [Member Agreements](#6-member-agreements) for what each mode does.
    - **Consider Giving Message** and **Consider Giving Link**: an optional fundraising ask. See [Asking members to give](#asking-members-to-give).
6. **If you want agreement tracking, set it up now, before anyone joins.** This is the one part of Setup where doing it later means more work for members and staff. See [Member Agreements](#6-member-agreements).
7. Configure outgoing email through an SMTP plugin (recommended: WP Mail SMTP, Post SMTP, or FluentSMTP). This is required to send emails to members. Set the from address to a mailbox on your domain. Add SPF and DKIM DNS records for whichever service you chose.
8. Copy the **Public Page Link** from the Setup page into your site's navigation menu, or link to it from any page, post or button. This is the one link your community needs to browse the catalog, reserve tools, and sign up.
9. Once categories/tags exist, move on to [Adding Inventory Items](#3-adding-inventory-items) and [Adding a New Member](#4-adding-a-new-member-in-person--verifying-their-identity). Both support **bulk import**, the fastest way to load an existing spreadsheet:
    - On the **Inventory** or **Membership** page, find the **Bulk Import from CSV** panel.
    - Click **Download the CSV template** — it has the exact column headers and one example row. Fill in one tool or member per row and delete the example row. Column order doesn't matter as long as the header names match.
    - Upload the completed CSV and submit. The plugin reports how many rows succeeded and the reason for any that failed (a missing required field, an invalid category), so you can fix just those and re-upload.
    - On the members template, the **`trainings`** column takes a training name, a colon, and the completion date, several separated by semicolons: `Ladder Safety: 8/4/2026; Welding Basics: 8/3/2026`. Names must match your Setup page, though capitalization doesn't matter. A malformed entry, or one naming a training that doesn't exist, still imports the member without that training and lists the reason, so you can fix it on their record rather than re-running the file.
10. Use **Export Data** on the Setup page any time you want a full backup: a SQL dump, or a ZIP of CSVs, one per table.
11. **Set up accounts for the rest of your staff.** Everyone working the desk should get their own **Editor** account — see [Creating a New Staff Account](#2-creating-a-new-staff-account).

---

## 2. Creating a New Staff Account

Staff access uses WordPress's built-in user roles; this plugin has no separate staff login system. See [Staff Roles and Permissions](#staff-roles-and-permissions) for what each role can do.

> **Note:** If a staff member will also borrow tools as a member, have them use a different username or email for their staff account than their personal address, so the two stay separate. Otherwise they cannot borrow as a member.

### Adding an Editor (the usual choice)

Give **Editor** to anyone working the desk. They get everything except the Setup page and deleting members.

1. In the WordPress dashboard, go to **Users > Add New**.
2. Enter the staff member's username and email address.
3. Under **Role**, select **Editor**.
4. Click **Add New User**. WordPress emails them a link to set their own password, or set one yourself and share it securely.
5. They can now sign in through the normal WordPress login (`/wp-login.php` or `/wp-admin/`) or through the plugin's branded **Sign In** page, linked from the public catalog's footer, which routes staff straight into the **My Tool Library** admin portal.

To change someone who already has an account, go to **Users**, click their name, and set **Role** to **Editor**.

### Adding an Administrator

Give **Administrator** only to the people who run the library, as it grants full control of the entire WordPress site. Follow the same steps, choosing **Administrator** at step 3.

> **Note:** Both are full WordPress roles, so they grant access beyond this plugin: Editor can write and edit posts and pages, Administrator can do anything at all. Give out Administrator sparingly, and if you are an admin, consider a second Editor account for desk work — it simplifies the interface and prevents accidental data loss.

---

## 3. Adding Inventory Items

Go to **My Tool Library > Inventory**.

### Adding one tool at a time

1. Click **Add New Tool**, or the equivalent panel toggle at the top of the page.
2. Fill in the tool's name, barcode (must be unique), brand, description, and any components or accessories that come with it.
3. Add a **photo URL**, a link to an image hosted elsewhere (see [Hosting Photos and Documents](#hosting-photos-and-documents)). Optional but recommended; it's shown to the public on the catalog.
4. Fill in the financial tracking fields: initial cash value, annual depreciation amount, who donated it, and date acquired.
5. Select one or more **categories** and **tags**, set up during Initial Setup.
6. Optionally, add **Private Notes** — see below.
7. Save. The tool immediately appears in the public catalog.

### Adding many tools at once

Use the **Bulk Import from CSV** panel described in [Initial Setup](#1-initial-setup), step 8. It is the fastest way to load an existing inventory spreadsheet, and works later for a new batch of donated or purchased tools. **Admin** action only.

> **Note:** A barcode is required and must be unique to the tool. Beyond that the database makes no assumptions: any text or number up to 100 characters works.

### Private Notes (staff-only)

For anything staff need on record about a tool that members should never see, such as maintenance needs or a conditional donation. Unlike Description and Components, it never appears on the public catalog, only in the Inventory page's detail view (click a tool's row to expand it) in a highlighted "Private Notes" box. Leave it blank if there's nothing to record. Use the **Has Private Notes?** filter under Advanced Search to find every tool that has one on file.

---

## 4. Adding a New Member In-Person & Verifying Their Identity

Go to **My Tool Library > Membership**.

### Adding a walk-in member

1. Click **Add a New Member**.
2. Fill in their name, address, phone number, and email address. Email doubles as their sign-in username if they later create an online account, so it must be unique.
3. Leave the two verification fields blank if you haven't checked their ID, or fill in just one if that's all they've provided. Either way they are added as **unverified** until both are on file, which does not block them from browsing or reserving tools online.
4. Under **Trainings**, tick anything they've already completed. Ticking one opens a completion date box next to it. See [Trainings and certifications](#trainings-and-certifications).
5. Under **Member agreements**, shown only if you have turned agreements on, tick the ones they have read and signed. Leave them blank if the paperwork isn't signed yet. See [Member Agreements](#6-member-agreements).
6. Leave **Email them a link to choose their password** ticked unless you have a reason not to. See below.
7. Save.

### Giving a new member their online sign-in

Adding a member creates their website sign-in automatically, with no password to begin with. They choose one through a link you email them, the same mechanism as a normal password reset. Leaving the box ticked sends that email the moment you save; **Send setup link** on their row sends it later.

The **Sign-in** column in the members table tells you where anyone stands:

| Shows           | Means                                        | What to do                                                         |
| --------------- | -------------------------------------------- | ------------------------------------------------------------------ |
| **Active**      | They have set a password.                    | Nothing. A member who has forgotten it uses "Lost your password?". |
| **No password** | The sign-in exists, they have never set one. | **Send setup link** re-sends the email.                            |
| **None**        | No sign-in yet, creation failed.             | **Send setup link** creates it and emails them in one go.          |

> **Note:** Anyone with a membership on file can use **Lost your password?** on the sign-in page, even without an account yet. If they try to sign up again instead, the site tells them they already have a membership and points them at the same place.

### After a CSV import: creating logins in bulk

A CSV import deliberately creates no sign-ins and sends no email: hundreds of accounts in a single request would time out, and importing a legacy membership list should not email everybody on it immediately.

Once the import finishes, open the **Member Logins** panel below the import box (administrators only). It shows how many members have no sign-in and how many have never set a password, then gives you two buttons:

1. **Create logins** — creates the missing accounts, sends nothing, safe to run whenever.
2. **Send setup emails** — emails everyone who has not chosen a password.

Both work through the list a batch at a time so they cannot tie up the request, so press the button again if the panel says some remain. Nobody is emailed twice within 24 hours unless you tick the box to include them.

If the panel warns that some members' addresses **belong to a different WordPress account**, those need a person. It usually means the address belongs to a staff account, or to a leftover sign-in from a member deleted earlier. Sort it out under **Users**, then run **Create logins** again.

**An import records no agreements either.** If you are using member agreements, there is a third job after these two. See [After a CSV import](#after-a-csv-import) under Member Agreements.

### Verifying a member's identity

Verification records that staff have checked the member's photo ID and a proof-of-address document. A member is verified only once **both** scan links are on file.

**Recommended process — collect documents with a Google Form:**

1. Create a Google Form with two **File upload** questions, "Photo ID" and "Proof of Address", pointing its uploads at a Google Drive folder created just for this purpose.
2. **Lock down that folder's sharing permissions** so only the staff who need it, or a specific access-controlled staff group, can open it.
3. Hand the member your tablet or kiosk, or a QR code to the form, to upload their ID and proof of address. Staff can also photograph the documents and upload them on the member's behalf through the same form.
4. Once submitted, open the folder, find the member's two files, and get a shareable link to each. Set link sharing to your organization or the specific staff group, never "Anyone with the link".
5. Go back to **Membership**, find the member, click **Edit**, and paste the two links into **Photo ID Scan URL** and **Proof of Address Scan URL**. Save.
6. They now show as **Verified** throughout the staff pages, including the Loans & Reservations detail panel so staff can check at pickup, and on their own account page.

To remove a document, clear its URL field on Edit and save. Clearing just one downgrades a verified member to unverified and leaves the other on file; clearing both deletes their verification record entirely.

> **Note:** If member verification is not important to your organization, an admin can type any URL into both fields to mark the member verified. You may also tell members that verification is not necessary **(see Setup > Member Verification Directions)**.

### Hosting Photos and Documents

Every photo and document field **except an agreement's attached file** is a plain link. Host the file elsewhere (Google Drive recommended) and paste the resulting link into the plugin:

- **Tool photos** and **Training Badges** are shown to the public — share them with "Anyone with the link."
- **Member verification documents** (ID, proof of address) are sensitive — share them only with staff, never publicly.
- **Agreement files** are the exception: they are uploaded into this site's own Media Library rather than hosted elsewhere, and they are **public** — see [Member Agreements](#6-member-agreements).

> **⚠ Note: never delete an agreement's attached file from the Media Library**, including old ones that look superseded.

Deleting a member does not delete hosted files. Member deletion emails the links to the site administrator with a request to delete them by hand. See [Deleting a member](#deleting-a-member).

---

## 5. Starting a Loan for a Member

There are two ways to start a loan, depending on whether the member already has a reservation for the tool.

### Option A: Check out an existing reservation

Use this when the member reserved the tool online ahead of time and is now picking it up.

1. **Get the tool reserved first**, if it isn't already: the member signs in to their own account on the public catalog and clicks **Reserve**. Reserving does **not** require member verification.
2. Go to **My Tool Library > Loans & Reservations**.
3. Find the member's reservation, searching by member name, tool name, or barcode. If the tool is available you can check it out to **any** member with an active reservation for it, not only whoever is first in the queue.
4. Open the reservation's detail panel and confirm the member's verification status if your process requires it before releasing a tool. Verification is a staff judgment call the software does not enforce (see [Section 4](#4-adding-a-new-member-in-person--verifying-their-identity)).
5. Pick a due date with the quick 7/14/21/30-day buttons (21 days is the site default, set on the Setup page) or enter a custom date.
6. Click **Check out to this member**. This converts the reservation into an active loan and removes it from the waiting queue.

If the tool is currently on loan to someone else, checkout isn't available. End that loan first (see [Section 7](#7-additional-workflows)) before checking it out to the next person in line.

> **Note:** Reserved tools can also be loaned to a member from the **Membership** page.

### Option B: Quick Loan (no reservation needed)

Use this for a walk-in member borrowing a tool on the spot, with no reservation on file.

1. Go to **My Tool Library > Inventory**.
2. Find the tool and click its **Quick Loan** button.
3. Start typing the member's name or email in the **Member** box and click their entry. A **Verified**/**Not Verified** pill then appears so you can see at a glance whether they've provided their ID documents (see [Section 4](#4-adding-a-new-member-in-person--verifying-their-identity)).
4. Pick a due date with the quick buttons or enter a custom date.
5. Click **Create Loan**. The loan is created immediately; no reservation is created or required.

Quick Loan refuses to run if the tool is already checked out to someone else (end that loan first) or if you submit without selecting a member from the dropdown.

### Quick Reserve (reserve in person, no online account needed to use it)

Use this for a walk-in member who wants to reserve a tool for future use.

1. Go to **My Tool Library > Inventory**, find the tool, and click **Quick Reserve**, next to Quick Loan in the tool's detail panel.
2. Pick the member the same way as Quick Loan, using the same search box and Verified/Not Verified pill.
3. Click **Create Reservation**. There's no due date to set.

Quick Reserve refuses to run if the member already has that tool on loan, or already has an active reservation for it.

---

## 6. Member Agreements

Statements every member has to agree to: a liability waiver, a code of conduct, a fee schedule. The plugin records what each member agreed to **exactly as it was worded at the time**, and how. It is **Off** by default, and nothing on this page happens until you turn it on.

### Choosing a mode

Go to **Setup > Member Agreements**. The three options at the top of that section decide how much of the feature is live:

- **Off** — nothing is tracked, recorded, or shown anywhere. Any agreement records you already have are kept in the database.
- **Track signed paper only** — you collect signatures at the desk and the plugin keeps a ledger: who has signed what, who hasn't, and a record each member can see on their own account page. Members are never asked to agree on the website and are **never blocked from reserving**.
- **Full — members agree online** — members tick every agreement to create an account, agree again whenever you revise one, and **cannot reserve a tool themselves until they have no outstanding agreements.**

Underneath **Full** is a checkbox, **Allow paper tracking**, which decides whether staff can also record a signature at the desk. Tick it and **Record agreement** appears on each member's detail panel, along with the agreement checkboxes on Add New Member.

**Which should we pick?** _Paper_ if you already collect signatures in person and just want the record and the chasing list. _Full_ if you want members to agree on the website via clickwrap. _Full_ plus _Allow paper tracking_ if you want both.

**Changing mode later hides or reveals, but does not destroy data.**

> **Note:** Switching from paper to full immediately blocks every member who has not agreed from reserving until they agree online. Switching back releases them all again straight away.

### Writing an agreement

Click **Add an agreement**, type the statement members must agree to, and optionally attach a file. Files written with legal advice are encouraged. Use the **↑ ↓** arrows to put them in the order you want members to read them.

### Attaching a file

**Files are uploaded to your website.** When you add or edit an agreement, click **Select or upload file** to open the WordPress media window, where you can upload or select a file. **Anyone can read these files.**

This plugin never deletes them, so if a member questions what they agreed to years later, the file they actually agreed to is still on the site.

> **Note:** never delete an agreement file from the Media Library, and when tidying it up leave anything that was ever attached to an agreement alone. The plugin keeps a fingerprint of each file so that years later you can prove which document was agreed to.

### Where members agree

Either on the Create Account page, or on an active member's **account page**. Anything outstanding appears **at the top** of that page with checkboxes and an **Agree** button; what they have already agreed to appears **at the bottom**, with the date and a link to the file they agreed to.

> **Note:** There is no way to mark a member as no longer in agreement.

### Staff recording agreement

In-person tracking must be enabled. On **Membership > Add a New Member** the agreements appear as checkboxes near the bottom of the form, optional there. You can also record later from the member's detail panel on the **Membership** page: expand **Agreements** and click **Record agreement**. **The plugin records which staff account took action.**

> **Note:** Only mark a member as having agreed once you have their signed form in your possession. There is no way to mark a member as no longer in agreement.

### Revising an agreement

Agreements are edited from **Setup > Member Agreements > Agreements**. **Any edit asks every member to agree again, and edits cannot be undone.** Version numbers increase every time you edit one. Members are not emailed automatically, but enforcement is immediate: nobody can reserve tools until they agree to the newest version. **Existing loans and reservations are unaffected.**

### Sending agreement requests (administrators only)

Navigate to **Membership > Member Agreements** and click _Send agreement requests_. Two audiences: **members who have not agreed to all current agreements** (the default), or **all active members**.

**Members who cannot sign in are left out.** The email tells them to agree on the website, and somebody with no sign-in lands on a login form with no credentials. See below.

### After a CSV import

A CSV import records **no agreements at all**, so an imported roster arrives showing "No agreements" against every member. That is expected. Do these three jobs in this order:

1. **Create logins** — Membership > Member Logins > _Create logins_.
2. **Send setup emails** — same panel. Members choose a password.
3. **Send agreement requests** — Membership > Member Agreements.

A member with no sign-in, or one who has never chosen a password, cannot agree online.

### Downloading a member's agreement record (administrators only)

**Download agreement record** on the detail panel produces a printable page with that member's **complete** agreement history since account creation. Each entry shows what they were shown, what they were told, when they agreed in both UTC and local time, which version it was and when that version was published, the file they agreed to and its fingerprint, and for staff-recorded ones which staff account recorded it. This is the document to produce if anyone ever asks what a member agreed to, and it is offered on **Former Member** rows too.

---

## 7. Additional Workflows

### Members

#### Managing a reservation from a member's record

On **Membership**, expand a member's detail panel and click any tool listed under **Active Reservations** to open a **Manage Reservation** pop-up:

- **Cancel Reservation** is always available, and removes the member from the waiting queue for that tool.
- **Start Loan for This Member** appears only when the member is first in line. Pick a due date (quick buttons or custom) and click it to check the tool out, which closes their reservation in the same step. If they're not first in the queue, the pop-up shows a note instead and points you to Loans & Reservations to override the queue order.

You can also cancel a reservation from **Loans & Reservations** by opening its detail panel and clicking **Cancel reservation**.

#### Asking members to give

**Setup > General Details** has two optional fields that put a **Consider Giving** section in front of signed-in members, on their **Account** page above Your details and on **My Loans & Reservations** below My Reservations.

- **Consider Giving Message** — your message to members asking for a donation.
- **Consider Giving Link** — where the **Give Now** button sends people: your donation page, a fundraising platform, and so on.

**The message controls whether the section appears at all**, so clearing it removes the section from both pages. **The link only controls the button**, so leave it blank to hide the button but keep the message, if you'd rather people give in person.

> **Note:** Only ordinary `http://` and `https://` web addresses are accepted for the link.

#### Trainings and certifications

A training records that a member has been shown how to use something safely. Each one is set up under **Setup > Member Trainings**:

| Field               | What it does                                                                                                   |
| ------------------- | -------------------------------------------------------------------------------------------------------------- |
| **Name**            | What the training is called. Renaming it does not alter a member's training completion record.                 |
| **Badge Image URL** | Optional. Shown on the member's own account page instead of a plain green pill.                                |
| **Valid For**       | How many months it stays current, counted from the day each member completed it. Blank means it never expires. |

**Recording a training.** On the **Membership** page, add or edit a member, tick each training they've completed and set the date. Backdating a training the member took last year correctly shows it as closer to expiring, or already expired.

**When a certification lapses.** Nothing is deleted; the record stays on the member forever and simply stops counting as current. Their detail panel lists every training they have ever completed, each marked **Current** or **Expired** with its completion and expiry dates. To renew one, edit the member and set the completion date to the day they retook it.

**Changing "Valid For" applies immediately and retroactively.** Each member's expiry is worked out from their own completion date and the training's current setting, so shortening a period can expire people straight away, and lengthening it can bring lapsed certifications back.

**Finding qualified members.** On the **Membership** page, open **Advanced Search** and use the **Trainings** dropdown. Tick one or more to show only members who hold _all_ of them, or use **Select all** for members who've completed everything. This matches on **current** certifications only.

**What members see.** On their own account page, the badges near the top show only what they're currently certified in. A collapsible **Trainings** section below lists everything they've ever completed, expired included, with dates and status.

#### When a member forgets their password

Members reset their own password without staff involvement:

1. On the **Sign In** page they click **Lost your password?** in the footer.
2. They enter their email address and we send them a link.
3. The link opens a **Choose a New Password** page on your site. Once saved, they're returned to Sign In and can use the new password.
4. A second email confirms the change went through.

If a member reports a password change confirmation they weren't expecting, have them change their password: somebody completed a reset on their account, and they no longer control it.

> **Note:** After possible unauthorized access, check that the email address on the account hasn't been altered too. If it has, change the email and password through the WordPress **Users** menu, then send a password reset link so the member can set their own.

If the email never arrives for anyone, that's a mail problem on the site rather than a plugin one. WordPress sends emails through whatever mail setup your host provides, and many hosts need an SMTP plugin before `wp_mail()` reliably reaches inboxes.

For a member who has **never** set a password, see [Giving a new member their online sign-in](#giving-a-new-member-their-online-sign-in). They can still use **Lost your password?**, and setting a very first password does **not** send the "your password has been changed" confirmation described above.

#### Members' online accounts and the database reset

Running **Setup > Run Database Setup** on a library that already has data deletes every record, but **does not touch members' WordPress sign-ins**. Their accounts and passwords keep working. What breaks is the connection between the two: each record's ID number restarts from 1, so the sign-ins point at records that are gone or, worse, at somebody else's. The plugin guards against the latter by checking that a record's email matches the account signing in and refusing to show a record it can't match, so a member in that state sees "we couldn't match your sign-in to a membership record".

To put things right:

- **Restore the `.sql` dump.** IDs come back as they were and every sign-in reconnects on its own. Access the database directly to restore the dump file.
- **Or re-add the member with the exact same email address** (Membership > Add a New Member, or a CSV import including their email). Their existing sign-in is found and reconnected on the spot, and they keep the password they already had. No setup email is sent, because there is nothing for them to set up. If you re-added them by CSV, press **Create logins** in the **Member Logins** panel afterwards to reconnect the whole batch at once.
- If a member is stuck and their email in **Membership** does _not_ match the email on their WordPress user (under **Users**), set the record's email to match theirs and save.

> **Note:** Deleting a member leaves their WordPress sign-in alone if it can't be confirmed as theirs, and tells you so. Remove it yourself under **Users** if it is no longer wanted. This is deliberate, because deleting the wrong person's sign-in cannot be undone.

#### Deleting a member

**Administrators only.** Editors have no **Delete** button on the Membership page. If a member needs removing and you're an Editor, ask an administrator, or point the member at the self-service option below.

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

The WordPress account is only deleted when it can be confirmed as that member's, meaning its email still matches the record. If it can't — usually because a database reset renumbered the records — the account is left in place and the confirmation message says so. Remove it yourself under **Users** in that case.

Any reservation the member currently has is **cancelled** as part of the delete. A currently open loan is left alone, so end those manually, or mark the tools retired if they are missing. Deletion and anonymization are the two actions in this plugin that are **permanent and cannot be undone**, so export a backup first (see [Backing up your data](#backing-up-your-data)) if you're unsure.

> **Note:** Every member delete sends **two emails** — one to the member confirming their account is gone, and one to the site administrator carrying the deleted record and asking them to delete the member's stored verification files. This happens whether staff or the member did the deleting.

**Members can always do this themselves, without staff involvement.** Signed in on the public site, a member goes to their **Account** page and, under **Danger Zone**, clicks **Delete Account and Remove Personal Data**. That walks them through the same two-step confirmation and produces the same outcome described above.

### Inventory

#### Renewing or ending a loan

On **Loans & Reservations**, open an active loan's detail panel:

- **Renew loan** — pick a new due date (quick buttons or custom) and submit. The field pre-fills with the _current_ due date, so submitting without changing anything is safe.
- **End loan (mark returned)** — marks the tool returned and available again for the next reservation or a new one.

For a fast drop-off with no other changes needed, go to **Inventory** instead, find the tool, expand its row, and click **Mark Returned**. The search box accepts a barcode scanner for quick processing.

You can also manage a loan from the member's own record: on **Membership**, expand their detail panel and click any tool under **Currently On Loan**. A pop-up opens with the same two actions, **Save New Due Date** (with the same quick buttons as Quick Loan) and **Mark as Returned**, so you don't have to leave the member's page.

#### Backdating a return

Every one of those three return forms has a **Return date** field. It starts on today, which is what you want for an ordinary drop-off, so you can keep clicking straight through as before.

Change it when you're catching up on a backlog, such as a bin of tools dropped off yesterday that you're only processing this morning. Set the date the tool actually came back and the member's record shows that date, so nobody is marked as returning a tool late when they didn't.

The date can't be in the future, and can't be earlier than the day the tool was checked out. A backdated return is recorded at the start of that day, since the exact drop-off time isn't known, so the detail panel shows 12:00 AM against it rather than a made-up time.

The **collect by** countdown is _not_ backdated: if somebody is queued for the tool, theirs starts when you process the return. They couldn't have collected a tool that was still sitting unprocessed, so their hold period shouldn't be eaten by your backlog.

#### Reservations that expire on their own

A tool isn't held forever. Once a reservation becomes **collectable**, meaning the member is at the front of the queue _and_ the tool is back on the shelf, a countdown starts. If they don't come in within the **Reservation Hold Period** set on the Setup page, the reservation is cancelled automatically with today's date and the tool passes to the next person in line. Expiry is checked whenever anyone loads a page, so the list is correct whenever you look at it.

Where you'll see it:

- **Loans & Reservations** — open any reservation showing **Ready** and its detail panel shows a **Collect by** date.
- **The member's own My Loans & Reservations page** — they see "Please collect by …" against anything waiting for them.

To change the period, go to **Setup > Reservations & Loans** and set **Reservation Hold Period** to anything from 1 to 365 days, or tick **Never expires** to hold reservations indefinitely.

> **Note:** Changing the number applies to reservations already waiting, so shortening the period can expire some on the next page load, and lengthening it gives everyone currently waiting more time.

#### Handling overdue tools

The **Dashboard**'s "Overdue Tools" panel lists every loan past its due date at a glance. Follow up with the member directly, as there is not yet a built-in email/SMS notification system. Once the tool is back, mark it returned as described above.

#### Retiring or deleting a tool

Retire a tool at the end of its useful life, or if it is stolen or missing. Deleting is usually for tools added in error.

- **No loan or reservation history** — an administrator may click **Delete** on the **Inventory** page to remove it outright. Editors don't see this button and should either use **Retire** or ask an administrator.
- **Has loan or reservation history** — click **Retire** instead. This hides the tool from the public catalog and blocks any new loan or reservation for it, cancelling any reservations already queued (with an on-screen note telling you how many), while keeping the tool's row and full history intact. A currently open loan is left alone and can still be ended normally. Document the reason under the tool's **private notes**.

Unlike a member or tool delete, retiring is fully reversible: click **Reactivate** on a retired tool to bring it back into service. Retired tools drop out of the Inventory page's default list, so use the **Retired?** filter under Advanced Search, set to "Active + retired" or "Retired only", to find them.

Deletion and anonymization are the two actions in this plugin that can't be undone, so export a backup first (see [Backing up your data](#backing-up-your-data)) if you're unsure.

### Other

#### Reviewing library activity

The **Dashboard** offers configurable, resizable stat panels. Drag panels to reorder or resize them; your layout is saved per-user.

Two panels are search-driven rather than always-on: **Tool History Lookup** and **Member History Lookup**. Type a tool or member's name into the search box, pick it from the dropdown, and click **View History** to see more than the Inventory/Membership pages show on their own:

- **Tool History Lookup** shows who has rented that tool and how many times each person has, plus a full loan-by-loan log with dates, due dates, and returned/late/still-out status.
- **Member History Lookup** shows that member's complete loan history, not just the currently active loans Membership's own detail panel shows, plus their full reservation history including past and expired ones.

Tool History Lookup's search box includes retired tools. Member History Lookup's excludes anonymized (deleted-account) members, since they no longer have a real name to search by.

#### Backing up your data

Before any bulk import, plugin update, or major cleanup, go to **Setup > Export Data** and download either a full SQL dump or a ZIP of CSVs. Keep exports somewhere access-controlled: they include full member contact details and verification links, and the **agreement records** keep the name and email of members who have since been deleted (see [Deleting a member](#deleting-a-member)).

**If you are keeping one as a real backup, keep the `.sql` dump.** It is the only export that can restore the library; the CSV export is for reading in a spreadsheet. The bulk importers always assign brand-new ID numbers, so re-importing `members.csv` creates fresh records rather than restoring the old ones, and there is no importer at all for loans or reservations. Restoring a `.sql` dump needs database access — phpMyAdmin, the `mysql` command line, or `wp db import` — as there is no restore button in the plugin.

#### Adjusting branding and appearance

The **Setup** page's General Details section controls the logo, colors, fonts, button style, and corner radius used across both the admin pages and the public-facing pages. Update it any time your organization's branding changes. The default is to inherit appearance settings.

#### Making changes to the Staff Workflows page

The **Workflows** page is converted to `HTML` directly from the `staff-workflows.md` file. To add to, remove from, or otherwise change the directions your staff see, open `staff-workflows.md` in your favorite text editor, write your edits, and save. Staff see the changes on page refresh.
