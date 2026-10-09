# My Tool Library staff guide

How to do the everyday jobs at the desk, plus the setup work an administrator does once. Staff need a WordPress account with the **Editor** or **Administrator** role.

## Contents

- [Common tasks](#common-tasks)
- [Getting started](#getting-started): [Roles and permissions](#staff-roles-and-permissions) · [Admin view switch](#working-the-desk-as-an-administrator) · [First-time setup](#first-time-setup) · [Bulk import](#bulk-importing-tools-and-members) · [Staff accounts](#creating-staff-accounts)
- [Tools](#tools): [Adding a tool](#adding-a-tool) · [Shelf location](#shelf-location) · [Resources and partner links](#resources-and-partner-links) · [Private notes](#private-notes) · [Sub-categories](#sub-categories) · [Maintenance](#putting-a-tool-under-maintenance) · [Retiring or deleting](#retiring-or-deleting-a-tool)
- [Members](#members): [Adding a member](#adding-a-member) · [Online sign-ins](#online-sign-ins) · [Logins after a CSV import](#creating-logins-after-a-csv-import) · [Verifying identity](#verifying-identity) · [Hosting photos and documents](#hosting-photos-and-documents) · [Trainings](#trainings-and-certifications) · [Forgotten passwords](#forgotten-passwords) · [Deleting a member](#deleting-a-member)
- [Loans and reservations](#loans-and-reservations): [Checking out a reservation](#checking-out-a-reservation) · [Quick Loan](#quick-loan) · [Quick Reserve](#quick-reserve) · [Bulk checkout](#bulk-checkout) · [From a member's record](#working-from-a-members-record) · [Renewing or returning](#renewing-or-returning-a-loan) · [Backdating a return](#backdating-a-return) · [Hold period](#reservation-hold-period) · [Overdue tools](#overdue-tools)
- [Member agreements](#member-agreements): [Modes](#choosing-a-mode) · [Writing and revising](#writing-and-revising-agreements) · [How members agree](#how-members-agree) · [Recording paper signatures](#recording-paper-signatures) · [Agreement requests](#sending-agreement-requests) · [After a CSV import](#agreements-after-a-csv-import) · [Agreement record](#downloading-a-members-agreement-record)
- [Dashboard](#dashboard)
- [Library settings](#library-settings): [Asking members to give](#asking-members-to-give) · [Tool requests](#letting-people-request-tools) · [Branding](#branding)
- [Data and backups](#data-and-backups): [Backing up](#backing-up-your-data) · [Database reset and sign-ins](#database-reset-and-member-sign-ins) · [Editing this guide](#editing-this-guide)

## Common tasks

| I need to...                                       | See                                                                  |
| -------------------------------------------------- | -------------------------------------------------------------------- |
| Lend a tool to someone at the desk                 | [Quick Loan](#quick-loan)                                            |
| Hand over a tool a member reserved                 | [Checking out a reservation](#checking-out-a-reservation)            |
| Lend several tools to one person                   | [Bulk checkout](#bulk-checkout)                                      |
| Check a tool back in                               | [Renewing or returning a loan](#renewing-or-returning-a-loan)        |
| Extend a loan                                      | [Renewing or returning a loan](#renewing-or-returning-a-loan)        |
| Follow up on a late tool                           | [Overdue tools](#overdue-tools)                                      |
| Sign up a new member                               | [Adding a member](#adding-a-member)                                  |
| Check someone's ID                                 | [Verifying identity](#verifying-identity)                            |
| Take a broken tool out of circulation              | [Putting a tool under maintenance](#putting-a-tool-under-maintenance) |
| Add a new tool                                     | [Adding a tool](#adding-a-tool)                                      |
| Help a member who can't sign in                    | [Forgotten passwords](#forgotten-passwords)                          |
| See everything a member or tool has done           | [Dashboard](#dashboard)                                              |
| Back up the library                                | [Backing up your data](#backing-up-your-data)                        |

---

## Getting started

### Staff roles and permissions

There are two staff roles, both standard WordPress roles. **Editor** is for anyone working the desk and is the right default for staff and volunteers. **Administrator** adds the **Setup** page, bulk imports, and deleting members and tools.

| Task                                                                     | Editor | Administrator |
| ------------------------------------------------------------------------ | :----: | :-----------: |
| View the Dashboard                                                       |   ✅   |      ✅       |
| Add or edit members                                                      |   ✅   |      ✅       |
| Record member trainings                                                  |   ✅   |      ✅       |
| Record a member's agreement (Add Member, or the Record agreement dialog) |   ✅   |      ✅       |
| Ask one member to agree (Send agreement request)                         |   ✅   |      ✅       |
| Send agreement requests in bulk                                          |   ❌   |      ✅       |
| Record signed paper agreements in bulk                                   |   ❌   |      ✅       |
| Download a member's agreement record                                     |   ❌   |      ✅       |
| View verification documents, mark members verified                       |   ✅   |      ✅       |
| Add, edit and retire tools                                               |   ✅   |      ✅       |
| Put tools under maintenance and back in service                          |   ✅   |      ✅       |
| Check tools out, renew, and mark returned                                |   ✅   |      ✅       |
| Create, cancel and fulfil reservations                                   |   ✅   |      ✅       |
| Read this guide                                                          |   ✅   |      ✅       |
| Bulk-import tools or members                                             |   ❌   |      ✅       |
| Delete a member's record                                                 |   ❌   |      ✅       |
| Delete a tool from inventory                                             |   ❌   |      ✅       |
| Open the Setup page (and everything on it)                               |   ❌   |      ✅       |
| ↳ Branding, colors, fonts, logo                                          |   ❌   |      ✅       |
| ↳ Categories, tags and trainings lists                                   |   ❌   |      ✅       |
| ↳ Member Agreements list                                                 |   ❌   |      ✅       |
| ↳ Export data (`.sql` / CSV)                                             |   ❌   |      ✅       |
| ↳ Run Database Setup                                                     |   ❌   |      ✅       |

Editors don't see the **Setup** tab at all.

> **Note:** Both are full WordPress roles. Editors can also edit posts and pages, and Administrators can change anything on the site. The plugin only checks which of the two roles an account has, so use a role-management plugin if you need tighter limits.

Members don't need staff permission to delete their own account. See [Deleting a member](#deleting-a-member).

### Working the desk as an administrator

The **Admin view** switch at the top of **Setup** hides delete, bulk import and database setup, so an administrator on a desk shift can't lose data by accident. With it off, the plugin behaves as it does for an Editor. It affects only your account and doesn't change any WordPress permissions. Switch it back on from **Setup**.

### First-time setup

Do this once, when the plugin is first installed.

1. Activate the plugin from the WordPress **Plugins** screen.
2. Under **Settings > General**, set the **Timezone** to a city, not a UTC offset, so Daylight Saving is handled for you. Every loan and reservation timestamp uses it, and past timestamps can't be corrected later.
3. Go to **My Tool Library > Setup**. Under **Database Configuration**, slide the toggle, click **Run Database Setup**, and type `Delete ALL my data` to confirm. This creates the plugin's tables, and no other page works until it's done.
4. **Only run Database Setup once.** On a library that's already running, it wipes every member, tool, loan and reservation, with no undo. Use **Export Data** first if you're troubleshooting a live library.
5. Fill in the rest of **Setup**:
    - **General Details**: name, logo, colors, fonts, button style, and an optional **Verified Badge Image URL** that replaces the green "Verified" pill on a verified member's account page. The giving and tool-request fields are here too (see [Asking members to give](#asking-members-to-give) and [Letting people request tools](#letting-people-request-tools)).
    - **Reservations & Loans**: the default loan length, the [Reservation Hold Period](#reservation-hold-period) (14 days by default), and whether members see a tool's [shelf location](#shelf-location).
    - **Categories & Tags**: what staff pick from when adding tools, such as Woodworking or Cordless.
    - **Member Trainings**: the trainings you offer. See [Trainings and certifications](#trainings-and-certifications).
    - **Member Agreements**: waivers and other statements members must agree to. **Set this up before anyone joins**, since adding it later means extra work for staff and members. See [Member agreements](#member-agreements).
6. Set up outgoing email with an SMTP plugin such as WP Mail SMTP, Post SMTP or FluentSMTP. Members don't get any email without it. Use a from address on your own domain, and add SPF and DKIM records for the mail service you chose.
7. Copy the **Public Page Link** from Setup into your site's menu or onto a button. It's the one link your community needs to browse, reserve and sign up.
8. Load your tools and members, one at a time or by [bulk import](#bulk-importing-tools-and-members).
9. Give everyone who works the desk their own Editor account. See [Creating staff accounts](#creating-staff-accounts).

### Bulk importing tools and members

**Administrators only.** Bulk import is the quickest way to load an existing spreadsheet, and it works later for a new batch of donated tools too.

1. On **Inventory** or **Membership**, open **Bulk Import from CSV** and click **Download the CSV template**.
2. Fill in one tool or member per row and delete the example row. Column order doesn't matter as long as the headers match.
3. Upload the file. The plugin reports how many rows worked and why any failed, so you can fix only those rows and upload them again.

The members template's `trainings` column takes a training name, a colon and the completion date, separated by semicolons: `Ladder Safety: 8/4/2026; Welding Basics: 8/3/2026`. Names must match Setup, though capitalization doesn't matter. A bad entry still imports the member, without that training, and the report tells you why.

A member import creates no sign-ins and records no agreements. See [Creating logins after a CSV import](#creating-logins-after-a-csv-import) and [Agreements after a CSV import](#agreements-after-a-csv-import).

### Creating staff accounts

Staff sign in with ordinary WordPress accounts. There's no separate staff login.

1. Go to **Users > Add New** in the WordPress dashboard.
2. Enter their username and email, and set **Role** to **Editor**. Use **Administrator** only for the people who run the library, since it controls the whole site.
3. Click **Add New User**. WordPress emails them a link to set a password, or you can set one and share it securely.

They can sign in at `/wp-admin/` or on the plugin's **Sign In** page, linked from the catalog footer, which takes staff straight to **My Tool Library**. To change an existing account's role, go to **Users**, click their name and change **Role**.

> **Note:** Staff who also borrow tools need a staff account with a different username and email from their member account. Otherwise they can't borrow as a member.

Administrators who also work the desk can use a second Editor account, or the [Admin view switch](#working-the-desk-as-an-administrator).

[Back to contents](#contents)

---

## Tools

### Adding a tool

On **Inventory**, click **Add a New Tool**.

1. Enter the name, barcode, brand, description, and any components or accessories that come with it. The barcode must be unique; otherwise it can be any text or number up to 100 characters.
2. Add a **photo URL**, a link to an image hosted elsewhere (see [Hosting photos and documents](#hosting-photos-and-documents)). It's optional but shown on the public catalog.
3. Fill in the money fields: initial value, annual depreciation (a dollar amount or a percentage of the initial value, not both), date acquired, and who donated it. Pick a member from the **Donated By** suggestions to credit them on the [Donor Leaderboard](#dashboard), or type any name.
4. Choose categories, sub-categories and tags, and any [required trainings](#trainings-and-certifications).
5. Optionally add a [shelf location](#shelf-location), [resources and partner links](#resources-and-partner-links), and [private notes](#private-notes).
6. Save. The tool appears in the public catalog right away.

### Shelf location

**Location** is free text for where the tool sits, so the person fetching it doesn't have to hunt. "Aisle 3, Shelf 4", "113" and "K4-1" all work. Leave it blank if you don't label storage.

Staff see it in the tool's detail panel on **Inventory**, and the quick filter searches it, so typing a shelf label lists everything on that shelf.

Members see it only if **Setup > Reservations & Loans > Shelf Location** is on. Then it shows under "Where to find it" in the catalog and on My Reservations. When it's off (the default), members see nothing about location anywhere.

### Resources and partner links

Each tool can carry two public lists of links:

- **Resources** help people use the tool: a manual, a safety video, a how-to.
- **Partner Links** point to local shops that sell its blades, belts, sandpaper or fuel.

Each entry is a name and a link. Write the name for a reader ("Chainsaw tutorial", "Bliffert Lumber"), and skip the `https://` if you like. Names can't repeat within a list. Click **+ Add resource** or **+ Add partner link** for another row, and **×** to remove one.

To copy a set between tools or from a spreadsheet, click **Edit JSON** and paste a list of name and link pairs, such as `{"Chainsaw tutorial": "example.com/chainsaw-basics", "Bliffert Lumber": "example.com/sanding-belts"}`. Click **Edit as boxes** to check it before saving. The switch won't happen if a link has no name or two entries share one. The CSV import takes the same format in its `resources` and `partner_links` columns, and a row with unreadable JSON fails and is reported.

The links appear in collapsed sections on the Inventory page, the public catalog and the member's My Reservations page. They open in a new tab.

### Private notes

**Private Notes** are for things staff need on record but members should never see, like a repair history or a condition attached to a donation. They appear only in the tool's detail panel on Inventory. To find every tool with notes, use **Has Private Notes?** under Advanced Search.

### Sub-categories

A sub-category narrows a category. Woodworking might hold Saws, Sanders & Planers, and Routers & Joinery.

- **Adding them:** on **Setup > Categories & Tags**, pick the parent category and type a name. Names only need to be unique within their category, so Woodworking and Electrical can both have "Drills". Deleting a category deletes its sub-categories.
- **On a tool:** tick the tool's categories first. The **Sub-category** row then shows one dropdown for each category. A tool can have at most one sub-category per category.
- **In the CSV import:** use the `subcategories` column, written as `Category > Sub-category` and separated by semicolons. A sub-category is skipped, and reported, if the row doesn't also list its category.

Advanced Search on **Inventory**, **Loans & Reservations** and the public catalog lists sub-categories indented under their category. Ticks match any of the boxes you tick. Ticking a category finds every tool in it, with or without a sub-category, and greys out its sub-categories. Ticking a sub-category finds only that one. Combined with tags, the search means "in one of these categories and carrying one of these tags".

### Putting a tool under maintenance

Use maintenance for a tool that needs repair, sharpening or a safety check. On **Inventory**, open the tool's row and click **Start Maintenance**. Any staff member can do this.

While a tool is under maintenance:

- It can't be lent from anywhere: Quick Loan, Bulk checkout, a reservation checkout, or Start Loan on Membership.
- It stays in the public catalog with an **Under Maintenance** badge, and members can still reserve it. Existing reservations are kept.
- Nobody's pickup countdown runs, so no reservation can expire while the tool is away.

Click **End Maintenance** when it's fixed. If anyone is waiting, the first person in line becomes ready for pickup and their [hold period](#reservation-hold-period) starts then.

A tool that's out on loan can go under maintenance too, for example when a member reports it broken. Mark it returned as usual and it stays under maintenance until you end it.

To list them, use **Under Maintenance?** under Advanced Search on Inventory. The Dashboard's **Tools On Loan** card shows the count.

### Retiring or deleting a tool

**Edit**, **Retire** and **Delete** are in the tool's detail panel on **Inventory**.

- **Retire** a tool that's worn out, stolen or missing. It disappears from the public catalog and can't be lent or reserved. Any waiting reservations are cancelled, and a message tells you how many. Its history is kept, and an open loan can still be ended normally. Note the reason in its private notes.
- **Delete** is for a tool added by mistake. Only administrators see it, and only for a tool with no loan or reservation history. Editors should retire the tool or ask an administrator.

Retiring can be undone: click **Reactivate**. Retired tools drop out of the default list, so set **Retired?** under Advanced Search to "Active + retired" or "Retired only" to find them. Deleting can't be undone, so [export a backup](#backing-up-your-data) first if you're unsure.

[Back to contents](#contents)

---

## Members

### Adding a member

On **Membership**, click **Add a New Member**.

1. Enter their name, phone, address and email. Pick the phone number's country first; the number formats itself. **State / Province** covers the U.S. and Canada, so choose "N/A" for anywhere else. The email becomes their sign-in username, so it must be unique.
2. If they give monthly, enter the amount under **Recurring Donation**.
3. Paste in their ID and proof-of-address links if you have them (see [Verifying identity](#verifying-identity)). They're **unverified** until both are on file, which doesn't stop them browsing or reserving online.
4. Under **Trainings**, tick anything they've completed and enter the date.
5. If agreements are on, tick the ones they've signed on paper. Leave them blank if they haven't signed yet.
6. Leave **Email them a link to choose their password** ticked unless you have a reason not to, then save.

### Online sign-ins

Adding a member creates their website sign-in automatically, with no password. They choose one through an emailed link that works like a password reset. It goes out when you save, if the box was ticked, or later from **Send setup link** on their row.

The **Sign-in** column shows where each member stands:

| Shows           | Means                                   | What to do                                                    |
| --------------- | --------------------------------------- | ------------------------------------------------------------- |
| **Active**      | They've set a password.                 | Nothing. If they forget it, they use "Lost your password?".   |
| **No password** | The sign-in exists but has no password. | **Send setup link** sends the email again.                    |
| **None**        | No sign-in yet.                         | **Send setup link** creates it and emails them.               |

Anyone with a membership can use **Lost your password?**, even before they've set one up. If they try to sign up again instead, the form doesn't say the address is taken. Once the rest of the form is filled in correctly, the site emails that address a link to set a password, or to sign in or reset it, at most once an hour.

### Creating logins after a CSV import

A CSV import creates no sign-ins and sends no email, so that importing an old list doesn't email everyone at once. Afterwards, an administrator opens the **Member Logins** panel below the import box:

1. **Create logins** makes the missing accounts and sends nothing.
2. **Send setup emails** emails everyone who hasn't chosen a password.

Both work in batches, so press the button again if the panel says some remain. Nobody gets the email twice in 24 hours unless you tick the box to include them.

If the panel says some addresses **belong to a different WordPress account**, it's usually a staff account or a sign-in left over from a deleted member. Fix it under **Users**, then run **Create logins** again.

If you use member agreements, there's one more step: see [Agreements after a CSV import](#agreements-after-a-csv-import).

### Verifying identity

A member is verified once staff have a link to both a photo ID scan and a proof-of-address scan on file. Verification is a staff judgment call. The plugin shows it but doesn't enforce it.

A Google Form works well for collecting the documents:

1. Create a form with two **File upload** questions, "Photo ID" and "Proof of Address", saving to a Drive folder used only for this.
2. Share that folder only with the staff who need it.
3. Have the member upload their documents on a tablet, kiosk or QR code. Staff can also photograph them and upload them through the same form.
4. Get a link to each file, shared with your organization or staff group. Never use "Anyone with the link".
5. On **Membership**, click **Edit** on the member, paste the links into **Photo ID Scan URL** and **Proof of Address Scan URL**, and save.

They then show as **Verified** on the staff pages, including the Loans & Reservations detail panel for checking at pickup, and on their own account page.

To remove a document, clear its field and save. Clearing one makes the member unverified and keeps the other; clearing both deletes their verification record.

If a member changes their name, phone or address on their own Account page, any scan on file is removed, since it shows the old details, and a verified member loses verified status. Verified members are warned before they save. The site administrator is emailed the scan links so the files can be deleted. Staff edits on Membership don't reset anything.

> **Note:** If your library doesn't need verification, an administrator can put any URL in both fields to mark someone verified. You can also tell members it isn't needed under **Setup > Member Verification Directions**.

### Hosting photos and documents

Photos and documents are links to files hosted elsewhere, such as Google Drive:

- **Tool photos and training badges** are public, so share them with "Anyone with the link".
- **Verification documents** are sensitive. Share them only with staff.
- **Agreement files** are the exception. They're uploaded to this site's Media Library and are public. See [Writing and revising agreements](#writing-and-revising-agreements).

Deleting a member doesn't delete their hosted files. The site administrator is emailed the links and asked to delete them by hand.

### Trainings and certifications

A training records that a member has been shown how to use something safely. Set each one up under **Setup > Member Trainings**:

| Field               | What it does                                                                                |
| ------------------- | ------------------------------------------------------------------------------------------- |
| **Name**            | What it's called. Renaming it doesn't change anyone's records.                              |
| **Badge Image URL** | Optional. Shown on the member's account page instead of a plain green pill.                 |
| **Valid For**       | Months it stays current after each member completes it. Leave blank if it never expires.    |

- **Recording one:** add or edit the member on **Membership**, tick the training and set the date they completed it. Expiry counts from that date, so a backdated training may already show as expired.
- **Renewing:** edit the member and change the date to when they retook it. Lapsed trainings are never deleted. The member's detail panel lists every training with its dates, marked **Current** or **Expired**.
- **Changing Valid For** applies to everyone straight away. Shortening it can expire people; lengthening it can bring lapsed trainings back.
- **Requiring one for a tool:** pick it under **Required Trainings** on the tool. At checkout, staff see a warning if the member hasn't completed it or it has lapsed. It doesn't block the loan.
- **Finding tools that need one:** use **Requires Training** under Advanced Search on Inventory or Loans & Reservations. **Any training** lists every tool that needs one.
- **Finding qualified members:** use **Trainings** under Advanced Search on Membership. It lists members who currently hold all the trainings you tick.

Members see badges for their current trainings near the top of their account page, and a full **Trainings** section, expired ones included, further down.

### Forgotten passwords

Members reset their own password. They click **Lost your password?** on the **Sign In** page, enter their email, and follow the emailed link to choose a new one. A second email confirms the change. Setting a first password doesn't send that confirmation.

If a member gets a confirmation they didn't expect, someone else reset their password. Have them change it, and check whether their account email was changed too. If it was, fix the email and password under **Users**, then send them a reset link.

If reset emails never arrive for anyone, the site's mail setup is the problem, not the plugin. Most hosts need an SMTP plugin (see [First-time setup](#first-time-setup)).

### Deleting a member

**Administrators only.** Editors can ask an administrator, or point the member to the self-service option below.

Click **Delete** on the member's row on **Membership**. This removes the person but keeps the library's records of what they borrowed.

- **Removed for good:** name, address, phone and email (replaced with placeholders), verification links, private staff notes, and their WordPress sign-in.
- **Kept:** every loan, past and current reservations, and completed trainings, so tool histories and totals stay correct.

Their row becomes **Former Member** with a **Removed** badge, and Edit and Delete disappear. Current reservations are cancelled. Open loans are left alone, so end them yourself, or retire the tools if they're missing.

The sign-in is deleted only if its email still matches the record. If it doesn't (usually after a [database reset](#database-reset-and-member-sign-ins)), it's left in place and the message tells you, so remove it under **Users** if needed.

Deleting can't be undone, so [export a backup](#backing-up-your-data) first if you're unsure.

Two emails go out every time: one confirming to the member, and one to the site administrator with the deleted record, asking them to delete the stored verification files.

Members can delete their own account from **Account > Danger Zone > Delete Account and Remove Personal Data**. The result and emails are the same, so staff always hear about it.

[Back to contents](#contents)

---

## Loans and reservations

### Checking out a reservation

When a member who reserved online comes in to collect:

1. On **Loans & Reservations**, find the reservation by member name, tool name or barcode.
2. Open it and check their verification status, if your library requires it.
3. Choose a due date with the 7, 14, 21 or 30-day buttons, or enter one. The default loan length is set on Setup.
4. Click **Check out to this member**. The reservation becomes a loan and leaves the queue.

You can check a tool out to anyone with an active reservation for it, not just the person at the front. If the tool is still on loan to someone else, [return it first](#renewing-or-returning-a-loan).

Members don't need to be verified to reserve. You can also start the loan from their record on Membership (see [Working from a member's record](#working-from-a-members-record)).

### Quick Loan

For someone borrowing on the spot without a reservation:

1. On **Inventory**, open the tool's row and click **Quick Loan**.
2. Type the member's name or email and pick them. A **Verified** or **Not Verified** pill appears.
3. Choose a due date and click **Create Loan**.

Quick Loan isn't available while the tool is on loan or under maintenance.

### Quick Reserve

For someone at the desk who wants to reserve a tool for later, whether or not they have an online account: open the tool's row on **Inventory**, click **Quick Reserve**, pick the member and click **Create Reservation**. It won't work if they already have that tool on loan or reserved.

### Bulk checkout

For one member collecting several tools. Click **Bulk checkout** at the top of **Loans & Reservations**.

1. Pick the member. Their verification status appears.
2. Scan or type a barcode. When it matches, the cursor moves to the next row, so you can keep scanning. A new row appears when you fill the last one.
3. Change any row's due date if needed, or tick **Reserve?** to add the member to that tool's queue instead of lending it.
4. Click the button at the bottom, which sums up the batch, such as "Loan 3, reserve 2".

The whole batch goes through or none of it does, and the problem rows are named. A batch stops for:

- A barcode that matches no tool, or the same tool on two rows.
- A retired tool.
- Lending a tool that's on loan or under maintenance. Tick **Reserve?** for that row instead.
- A due date in the past.

You'll be asked to confirm before lending a tool someone else has reserved. A **Reserve?** row for a tool the member already has on loan or reserved is skipped, and the rest goes through.

### Working from a member's record

On **Membership**, open a member's detail panel:

- Click a tool under **Currently On Loan** to change its due date or mark it returned.
- Click a tool under **Active Reservations** to cancel it, or to start the loan with **Start Loan for This Member**. Start Loan only appears when they're first in line. Otherwise, check it out from Loans & Reservations, which can skip the queue.

### Renewing or returning a loan

On **Loans & Reservations**, open the loan:

- **Renew loan** sets a new due date. It starts on the current due date, so submitting it unchanged does nothing.
- **End loan (mark returned)** checks the tool back in for the next person.

For a quick drop-off, find the tool on **Inventory** (the search box accepts a barcode scanner), open its row and click **Mark Returned**. You can also return a loan from the member's record (see [Working from a member's record](#working-from-a-members-record)).

### Backdating a return

All three return forms have a **Return date**, which starts on today. When you're catching up on a bin of tools dropped off yesterday, set the day they actually came back so nobody is marked late unfairly. The date can't be in the future or before the checkout date. Backdated returns are recorded at 12:00 AM, since the real drop-off time isn't known.

The next person's pickup countdown still starts when you process the return, so your backlog doesn't eat into their hold period.

### Reservation hold period

Once a reservation is ready (the member is first in line and the tool is on the shelf), the member has the **Reservation Hold Period** to collect it. If they don't, the reservation is cancelled automatically and the tool passes to the next person. Staff see a **Collect by** date in the reservation's detail panel on Loans & Reservations, and members see "Please collect by" on their My Loans & Reservations page.

Change the period under **Setup > Reservations & Loans** to 1 to 365 days, or tick **Never expires**. The change applies to reservations already waiting, so shortening it can expire some right away.

### Overdue tools

The Dashboard's **Overdue Tools** panel lists every loan past its due date. Overdue loans also show in red in Tool History Lookup, a tool's detail panel on Inventory, and a reservation's detail on Loans & Reservations. The plugin doesn't send overdue reminders, so contact the member yourself.

[Back to contents](#contents)

---

## Member agreements

Agreements are statements every member must accept, like a liability waiver, code of conduct or fee schedule. The plugin records what each member agreed to, worded exactly as it was at the time, and how. They're **Off** by default.

### Choosing a mode

Pick a mode at the top of **Setup > Member Agreements**:

- **Off:** nothing is tracked or shown. Existing records are kept.
- **Track signed paper only:** staff record signatures collected at the desk. Members see their record on their account page but are never asked to agree online or blocked from reserving.
- **Full: members agree online:** members tick each agreement to create an account and again whenever one is revised. **They can't reserve tools until they're up to date.** Tick **Allow paper tracking** underneath to let staff record desk signatures as well.

Use paper mode if you already collect signatures in person, and full mode for online click-through. Use full mode with paper tracking for both. Changing modes hides or shows data but never deletes it. Switching from paper to full blocks everyone who hasn't agreed from reserving until they do, and switching back releases them.

### Writing and revising agreements

Click **Add an agreement**, write the statement, and optionally attach a file, ideally one written with legal advice. Use the **↑ ↓** arrows to set the order members read them in.

**Select or upload file** puts the file in the WordPress Media Library, where **anyone can read it**. The plugin keeps a fingerprint of each file to prove later which version a member agreed to.

> **⚠ Never delete an agreement file from the Media Library**, even an old one that looks out of date.

**Every edit asks all members to agree again, and edits can't be undone.** Each edit raises the version number. Members aren't emailed, but the change applies at once: in full mode, nobody can reserve until they accept the new version. Existing loans and reservations are unaffected.

### How members agree

Members agree on the Create Account page, or on their **Account** page. Anything outstanding shows at the top with an **Agree** button. What they've already agreed to shows at the bottom with the date and file. There's no way to mark a member as no longer agreeing.

### Recording paper signatures

Paper tracking must be on. Tick agreements on **Add a New Member**, or open the member's detail panel on **Membership**, expand **Agreements** and click **Record agreement**. The plugin records which staff account did it. Only record an agreement once you have the signed form.

Administrators can record many at once under **Membership > Member Agreements > Record signed paper in bulk**: download the list, enter the date each member signed each agreement, and upload it. These records are permanent, so check the file before uploading.

### Sending agreement requests

**Administrators only.** Under **Membership > Member Agreements**, click **Send agreement requests**. You can email members who are behind on agreements (the default) or all active members. Members without a working sign-in are left out, since they couldn't agree online anyway.

### Agreements after a CSV import

An imported roster shows "No agreements" for everyone. That's expected. In this order:

1. **Create logins** (Membership > Member Logins).
2. **Send setup emails** from the same panel, so members choose passwords.
3. **Send agreement requests** (Membership > Member Agreements), or record paper signatures in bulk if you already have them.

### Downloading a member's agreement record

**Administrators only.** **Download agreement record** on the member's detail panel gives a printable history of everything they've agreed to. Each entry shows the text, the time in both UTC and local time, the version and its publish date, the file and its fingerprint, and who recorded it if staff did. It's available for Former Members too, so it's the document to produce if anyone asks what a member agreed to.

[Back to contents](#contents)

---

## Dashboard

The Dashboard is a set of panels you can arrange for yourself:

- Drag a panel by its header to move it. **⤢** cycles it between small, medium and large, and the eye icon hides it.
- **Panels** lists every panel, so tick one to bring it back.
- Click **Save Layout** to keep your changes, or **Reset** for the default layout. Each staff member's layout is their own.
- **Loan activity from / to** at the top narrows the loan figures to a date range.

The **Member Rental Leaderboard** and **Donor Leaderboard** each have a dropdown for All time, This year, This month or This week. Weeks start on the day set under **Settings > General**. While a date range is applied, the rental leaderboard also offers that range. The donor leaderboard ranks donors by the total value of the tools credited to them, dated by when each tool was acquired. It doesn't include cash donations.

**Tool History Lookup** and **Member History Lookup** show more than Inventory or Membership do. Type a name and pick a result, by clicking or with the arrow keys and Enter, and the history loads in place:

- **Tool History Lookup** shows how many times each member has borrowed the tool, and every loan with its dates and status. It includes retired tools.
- **Member History Lookup** shows all of a member's loans and reservations, past ones included. Deleted members aren't searchable.

[Back to contents](#contents)

---

## Library settings

### Asking members to give

Three optional fields on **Setup > General Details** add a **Consider Giving** box for signed-in members. It shows on their **Account** page and on **My Loans & Reservations**.

- **Consider Giving Message:** your request for support. Clear it to remove the whole box.
- **Consider Giving Link:** where **Give Now** goes, such as your donation page.
- **Consider Giving Wishlist Link:** where **Wishlist** goes, such as a list of tools you'd like bought or donated.

Leave a link blank to hide its button. Links must start with `http://` or `https://`.

### Letting people request tools

When a catalog search finds nothing, it shows **0 tools found** and suggests changing the search. Two optional fields on **Setup > General Details** add an invitation to request the tool underneath. The same message and button appear in a **Can't Find a Tool?** box on members' **Account** page.

- **Tool Request Message:** comes with a sample you can edit. Clear it to hide the message.
- **Tool Request Link:** where **Request a Tool** goes, usually a form. Leave it blank to hide the button.

Clearing both removes the Account page box. The link must start with `http://` or `https://`.

### Branding

**Setup > General Details** sets the logo, colors, fonts, button style and corner radius for both the staff pages and the public pages. By default they match your site's theme.

[Back to contents](#contents)

---

## Data and backups

### Backing up your data

Before a bulk import, plugin update or big cleanup, download a backup from **Setup > Export Data**: a SQL file or a ZIP of CSVs.

**Keep the `.sql` file if you want something you can restore from.** The CSVs are for reading in a spreadsheet. Re-importing them creates new records with new ID numbers, and loans and reservations can't be imported at all. Restoring a `.sql` file needs database access, such as phpMyAdmin, the `mysql` command line or `wp db import`. The plugin has no restore button.

Store exports somewhere private. They hold members' contact details and verification links, and agreement records keep the names and emails of deleted members.

### Database reset and member sign-ins

Running **Run Database Setup** on a live library deletes every record but leaves members' WordPress sign-ins and passwords alone. The records get new ID numbers starting from 1, so sign-ins no longer point to the right member. The plugin checks that the email matches before showing a record, so an affected member sees "we couldn't match your sign-in to a membership record" rather than someone else's account.

To reconnect them:

- **Restore the `.sql` backup.** The old IDs come back and every sign-in reconnects.
- **Or re-add members with the same email**, by hand or by CSV. Their sign-in reconnects, they keep their password, and no setup email is sent. After a CSV, press **Create logins** under Member Logins to reconnect the whole batch.
- If one member is still stuck, make their email on **Membership** match their WordPress account under **Users**.

### Editing this guide

The **Workflows** page is built from `documentation/staff-workflows.md` every time it loads. Edit that file in any text editor and save, and staff see the change on refresh.

[Back to contents](#contents)
