=== My Tool Library ===
Contributors: chrismchenry5, ToolLibrarian
Donate link: https://mkelibrary.org
Tags: tool library, lending library, inventory, reservations, membership
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Run a community tool-lending library from WordPress: inventory, memberships, reservations, and loans, with a zero-JavaScript public catalog.

== Description ==

My Tool Library turns a WordPress site into the online home of a physical tool-lending library: a public catalog customers can browse and reserve from, a membership system, and a full admin back office for staff to manage inventory, memberships, loans, and reservations.

= For your community =

* Browse and search the catalog without an account. Filter by name, brand, category, sub-category, tag and availability.
* Share a link to any tool.
* Sign up free, reserve tools, and see your place in each queue.
* See your loans flagged due soon, due today or overdue, plus your full loan history.
* Update your details, see your trainings, and delete your account, all from the Account page.
* Review and agree to member agreements online, if the library uses them.
* Follow the library's links to request a tool or give (a donation link and a wishlist), if it sets them up.
* No JavaScript on any public page. Everything is a plain link or form.

= For your staff =

* Dashboard: drag, resize and hide stat panels for membership, loans, overdue tools, popular tools, donors, and asset value.
* Inventory: add tools one at a time or by CSV import, with categories, sub-categories, tags and private notes.
* Tool details: shelf location (members see it only if you switch it on), resource and partner links, value, depreciation and usable life left.
* Maintenance and Retire: take a tool out of service for a while, or for good. Both are reversible.
* Membership: add members one at a time or by CSV import, track ID and proof-of-address verification, add profile photos and private notes.
* Member logins: email setup links so members added by staff can sign in.
* Account locks: stop a member reserving or borrowing until staff unlock them.
* Trainings: record them with expiry dates. Tools can require them, and checkout warns (never blocks) when one is missing.
* Member agreements (optional): collected online, or recorded from signed paper copies one at a time or in bulk.
* Loans & Reservations: check out, renew, return (backdated if needed) and cancel in one place, or check out several tools at once by barcode.
* Reservations expire on their own after a hold period you set, and keep a record of why each one closed.
* Advanced search on every staff list, including a category tree, shelf location and required training.
* Workflows: a step-by-step staff guide inside the admin.
* Setup: branding, fonts and colors; categories, tags and trainings; loan and hold defaults; full SQL or CSV export, encrypted automatic backups to the Media Library, and restore from either.
* Go Live: set the library up behind a Coming soon page, optionally with the catalog open to browse, then open sign-up, sign-in and reservations at once. A launch checklist flags anything worth fixing first.
* Admin view switch: administrators can hide admin-only actions while working the desk.

= Design principles =

* Public-facing pages ship no JavaScript at all. They use plain forms, links, and CSS (including the `:target` pseudo-class for instant, same-page tool detail views), and the server does any checking or formatting, such as tidying a phone number.
* Admin pages use JavaScript freely where it improves the staff experience (sorting, filtering, resizing, drag-and-drop).
* Member passwords are always handled by WordPress core (`wp_insert_user()`). The plugin's own database tables never store a password in any form.
* Every member gets a WordPress account, whether they signed up themselves or were added by staff. An account created for them is given a random password nobody ever sees; the member chooses their real one through an emailed link, using WordPress's own password-reset machinery.

= Who this is for =

Tool libraries, makerspaces, community lending programs, and similar organizations that lend physical items to members and want a self-hosted, no-subscription way to manage it from their existing WordPress site. Built with a U.S.-based library in mind by default, but not limited to one, since members can sign up from any country.

= Assumptions and intended use =

This plugin is built around a specific, deliberately simple operating model. Before installing, confirm it matches how your organization actually runs:

* Single location, single copy per tool. Each tool is one row with one barcode; the plugin does not model multiple copies of the same tool or multiple lending locations/branches. Duplicate locations could lead to collisions.
* Reservation does not require verification. Any signed-in member can reserve a tool and join its queue, unless staff have locked their account. Identity verification (photo ID + proof of address) is a separate, staff-run process intended to happen in person. This would typically be at pickup, before a tool actually leaves the building. The plugin does not enforce verification as a precondition for reserving, and enforcing it at checkout time is a staff judgment call, not something the software blocks.
* Staff are WordPress Editors and Administrators. The plugin grants an `mtl_manage_library` capability to both roles, and grants it again on every page load, so removing it from either role won't stick. Editors run the library day to day (Dashboard, Membership, Inventory, Loans & Reservations, Workflows); the Setup page, bulk-importing members or tools from CSV, deleting a member, and deleting a tool are Administrator-only (`manage_options`). Both are full WordPress roles, so they also grant access elsewhere on your site: Editor can write and edit posts and pages, Administrator can do anything at all. Narrow them with a role-management plugin if that matters to you. The plugin never checks role names, only `mtl_manage_library` and `manage_options`, so a custom role given `mtl_manage_library` gets the same library access as an Editor.
* No payments. `recurring_donation_amount` on a member record is informational only; the plugin does not collect, charge, or reconcile payments of any kind.
* No status notifications. Nothing about library activity is pushed to anyone: members and staff see due soon, overdue, ready for pickup and queue position by visiting the relevant page. The plugin does send account email (a setup link so a new member can choose their first password, WordPress's own password-reset link, a confirmation once a password has been changed, notice that an account has been locked or deleted, and, if member agreements are enabled, a request to agree and a record of what was agreed), but each of those is triggered by someone's action, never by a schedule. There is no SMS.
* One database, one WordPress install. The plugin creates its own tables via `$wpdb` using your site's table prefix. It has not been tested against multisite network-activation; on multisite it should be activated per-site.
* Site timezone must be set correctly. Reservations and loans are timestamped using WordPress's configured site timezone (Settings > General > Timezone). If that is left at its default, timestamps will not reflect your actual local time (see the FAQ).
* Signup has no CAPTCHA or throttling. Anyone who can reach the sign-up page can create a member account (email confirmation is not required; the account is active immediately). This matches a walk-in-friendly, low-friction community tool library; if your site is at higher risk of automated abuse, put it behind whatever anti-spam layer (CAPTCHA, firewall rules, etc.) you'd normally use for a public registration form.
* Members can be from any country. The signup and Membership pages collect a full address: Address Line 1 (required), Address Line 2 (optional; apartment/suite/unit), City, State/Province, ZIP/Postal Code, and Country. Country is a dropdown of every ISO 3166-1 country, defaulting to United States but changeable by the member or by staff (via Edit Member) to any other. State/Province is a dropdown covering U.S. states/territories and Canadian provinces (both use short, standardized 2-letter codes); members anywhere else select "N/A" there, since region/province systems vary too much per-country to model directly, and can still note their actual region in the address lines if it matters. Country is always the last line of a member's displayed address, per international postal addressing convention (UPU S42), regardless of which country is selected.
* Photos and documents are links, not uploads. The plugin does not host or upload image files itself: `photo_url` (a tool's photo), `profile_photo_url` (a member's photo), `photo_id_scan_url`, and `address_proof_scan_url` (a member's verification documents) are plain link fields. Host the actual image elsewhere (a cloud storage provider such as Google Drive, with sharing permissions set deliberately) and paste the resulting link in. Tool photos are shown on the public catalog to anyone, so they should be publicly viewable; member profile photos and verification document scans are sensitive personal records shown only on the admin-only Membership page and should require the viewer to be signed in or explicitly granted access, not just have an unguessable URL. See the FAQ for more detail.
* Deleting a member with loan/reservation history anonymizes it instead. A member can delete their own account (Account page), and staff can delete any member (Membership page); either way, a member with no borrowing history is removed outright, but one who has ever borrowed or reserved a tool has their personal data anonymized and their WordPress account deleted, while that loan/reservation history is kept so tool-level statistics stay accurate. See the FAQ for detail. Tools work the same way in spirit but are never anonymous: a tool with history can be Retired (left out of the catalog unless a visitor asks for retired tools, blocked from new loans, fully reversible) instead of deleted.
* Tools can carry private staff notes. The Inventory page's Add/Edit forms (and the bulk CSV import, via a `private_notes` column) have an optional Private Notes field (e.g. "missing a screw, ask Jim before lending" or "this one's a loaner from another org, handle with care"). It's never included in the public catalog or any member-facing page or query, and is shown only in the admin-only Inventory page's detail view. A CSV file itself isn't private once it leaves the site, though, so avoid sharing an import file that has sensitive notes filled in.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/my-tool-library`, or install it through the WordPress Plugins screen directly.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Go to My Tool Library > Setup and click Run Database Setup to create the plugin's database tables. This step is required before any other page will work correctly.
4. Confirm your site's timezone is correct under Settings > General > Timezone. The plugin timestamps reservations and loans using this setting; left at the default (UTC), timestamps will not match your local time.
5. Still on the Setup page, fill in your organization's name, logo, and colors, and set the Home Page Link and any pickup/verification directions you want members to see.
6. Copy the Public Page Link shown on the Setup page and add it to your site's navigation menu (or link to it from any page or post); this is the one link your customers need to reach the tool catalog. Until you go live (step 8), it shows a Coming soon page.
7. Add your tool categories and tags (Setup page), then add tools (My Tool Library > Inventory) and members (My Tool Library > Membership). Inventory and members can be added one at a time, or via CSV bulk import on either page.
8. When you're ready to open, turn on Go Live at the top of the Setup page, then send setup emails from Membership > Member Logins. Until then, customers see a Coming soon page and member accounts can't sign in, while staff use everything as normal. See "Why does my library say Coming soon?" in the FAQ.

= Permalinks =

The public catalog is reachable at `/tool-library/` automatically if your site uses any permalink structure other than "Plain" (Settings > Permalinks). On Plain-permalink sites, the plugin falls back to a query-string URL; the Setup page's Public Page Link box always shows whichever form is currently active, so you never need to construct it by hand.

== Frequently Asked Questions ==

= Does this plugin require JavaScript? =

Not for your customers. No public-facing page (the catalog, sign-up, sign-in, reservations, account) loads any JavaScript. The admin/staff back office does use JavaScript for a better staff experience (sorting, filtering, drag-and-drop dashboard panels), but nothing customer-facing depends on it.

= Does reserving a tool require identity verification? =

No. Any signed-in member can reserve a tool and join its waiting queue, unless staff have locked their account. Verification (checking a photo ID and proof of address) is a separate, staff-performed process tracked on the member's record. See "Assumptions and intended use" above.

= How do I add tool photos, member photos or verification document scans? =

All are link fields, not file uploads: `photo_url` on a tool, `profile_photo_url` on a member, and `photo_id_scan_url` / `address_proof_scan_url` on a member's verification record. The plugin has no built-in file storage or media upload for any of them, so you'll need to host the actual image somewhere else first and paste in the resulting URL. A cloud storage provider such as Google Drive works well for this, but the permissions you give that link need to match what's actually being shown:

* Tool photos are displayed on the public catalog to any visitor, signed in or not, so the hosted image needs to be publicly viewable (e.g. Google Drive's "Anyone with the link" sharing option).
* Member profile photos are taken by staff and shown only on the admin-only Membership page, never to the member. Share them the same way as verification document scans, below.
* Verification document scans (a photo ID, a proof-of-address document) are sensitive personal records, shown only on the admin-only Membership page. Their hosted image should require the viewer to be signed in or specifically granted access. Share it only with the specific staff accounts (or a properly access-controlled group) who need it, the same way you'd handle any other sensitive document.

The plugin has no way to check how a linked image is actually hosted or shared, and cannot verify access controls.

= Where are member passwords stored? =

Entirely in WordPress's own user system (`wp_users`), using WordPress's normal password hashing via `wp_insert_user()`. The plugin's own database tables never contain a password in any form.

When staff add a member, the account is created with a random password that is never displayed, logged, or emailed, and exists only so the account has one. The member sets a real password through the link in their setup email, which is a standard WordPress password-reset key.

= A member staff added can't sign in. What do I do? =

They need a password. Open Membership and look at the Sign-in column:

* None: they have no WordPress account yet. Press Send setup link on their row; it creates the account and emails them.
* No password: the account exists but they have never set one. Send setup link re-sends the email. Note this cancels any earlier link.
* Active: they are set up, so this is an ordinary forgotten password. Point them at "Lost your password?" on the sign-in page.

After a CSV import, use the Member Logins panel instead of going row by row: Create logins makes all the missing accounts, then Send setup emails invites everyone at once. Both work through the list a batch at a time, so press again if any remain.

A member can also sort this out themselves without staff help. "Lost your password?" creates the account if it is missing and emails them a link.

= Why does my library say Coming soon? =

Because it isn't live yet. A new install starts closed to the public, so you can set up, load tools and members, and test without anybody signing up or reserving early. Until an administrator turns on Go Live at the top of the Setup page:

* Customers see a Coming soon page in place of the catalog, sign-in, sign-up and account pages. Setup can add your own message, such as an opening date.
* Optionally, under the same switch, customers can browse the catalog. Sign-up, sign-in and reserving stay closed, and every account link is taken out.
* Member accounts can't sign in anywhere, including WordPress's own login page.
* Setup emails and agreement requests wait, because their links lead to pages customers can't open yet. Send them once you're live.
* Staff see the real pages, with a bar along the bottom of the window saying what customers see instead, and use the staff pages as normal. Staff who forget their password reset it through WordPress's own login page.

On launch day, turn on Go Live, then send setup emails (and agreement requests, if you use them) from Membership. A library that was already running when this switch was added stays live. Go Live can be turned back off, but members then lose access to their accounts, loans and due dates until it's on again, so it isn't meant for short closures.

If customers still see the Coming soon page after you go live, a page cache on your host may be holding on to it. These pages ask not to be cached, but clear the cache if your host offers one.

= Why don't my reservation/loan timestamps match the time I actually took the action? =

Your site's timezone (Settings > General > Timezone) is probably still set to its default. The plugin timestamps events using `current_time()`, which follows that setting. Set it to your actual local timezone (a named city, not just a UTC offset, so Daylight Saving is handled automatically) and new timestamps will be correct going forward. Timestamps already recorded before the fix will not be retroactively corrected.

= Can I customize how the catalog and account pages look? =

Yes. The Setup page lets you set your organization's logo, colors, fonts, button style, and corner radius, applied consistently across both the admin pages and the public-facing pages.

= Is there a way to quickly test the plugin and database? =

Yes. Testing can be done on your site or in a local instance through LocalWP (recommended). Simply borrow or create a dummy data SQL file (there is a dummy-data.sql file included in the documentation for this plugin). If borrowing the included dummy-data.sql file, it is recommended to change the stale dates of loans, reservations, and membership signup to reflect current dates (your favorite AI tool can do this quickly). Use the database schema, dbml file, and visual schematics when creating your own dummy date file. Run the SQL file as a command through the WordPress backend database manager (phpMyAdmin, Adminer, AdminNeo, etc.) to insert your dummy data. You may then test the plugin as both an administrator and customer. Turn on Go Live in Setup first, or customers will only see the Coming soon page.

= Does the plugin send emails? =

Yes, but never about library lending activity.

Every email it does send is triggered by a person, not a schedule:

* The setup link a new member uses to choose their first password.
* WordPress's own password-reset link, and the confirmation once a password has been changed.
* When someone tries to sign up with an email address that already has an account: a note to that address explaining how to sign in or set a password, at most once an hour.
* When staff lock an account: a note asking the member to speak with staff.
* On a member delete: a confirmation to the member, plus a request to the site administrator to delete that member's stored photo and verification files.
* When a member with verification documents on file changes their personal details, which voids those documents: a request to the site administrator to delete the old files.
* With member agreements switched on: a request asking a member to review and agree, and a confirmation recording what they agreed to, with any attached documents included.

Setup emails and agreement requests are held until the library goes live, since their links lead to pages customers can't open before then.

= The plugin's emails aren't arriving. How do I set up outgoing mail? =

The plugin has no mail settings of its own. It calls WordPress's `wp_mail()` and nothing else, so delivery is entirely WordPress and host configuration.

Out of the box WordPress sends from `wordpress@your-domain.com`, a mailbox that usually does not exist, from a server your domain has never authorized. Receiving servers treat that as forgery, which is why the mail lands in spam or is rejected outright. To fix it:

1. Install an SMTP plugin (WP Mail SMTP, Post SMTP, FluentSMTP) and connect it to a real sending service: your Google Workspace or Microsoft 365 account, or a transactional provider such as Postmark, SendGrid, Mailgun or Amazon SES.
2. Set the From address to a real mailbox on your own domain, e.g. `library@yourdomain.org`. Members reply to these emails, so it should be one staff read.
3. Add the SPF and DKIM DNS records your sending service gives you. Add DMARC only once those two verify.

= Can I export my data? =

Yes. The Setup page can export the plugin's full dataset as either a SQL dump or a ZIP of CSV files, one per table.

= Can I restore from a backup? =

Yes. Under Setup > Restore from Backup, pick one of the automatic backups stored on the site or upload a SQL dump from Export Data, and it replaces all of the plugin's data with the contents of the file, keeping every record's ID so members' sign-ins still match. The whole file is checked before anything changes, and the replacement happens all at once, so a file that is damaged, incomplete or not from Export Data leaves your data exactly as it was. It works on a new WordPress site too, creating the plugin's tables if they are missing. Settings on the Setup page and members' WordPress accounts are not part of the backup. That includes Go Live, so a library restored onto a new site shows the Coming soon page until you turn Go Live on there. Download a fresh dump regularly: a restore can only bring back what was in the file.

= Can backups run automatically? =

Yes. Under Setup > Automatic Backups, choose how often (every 1 to 90 days) and how many to keep. Each backup is the same SQL dump as Export Data, encrypted as it is written and saved to the Media Library under a name with the date, time and a random token, for example my-tool-library-backup-2026-10-17-030512-....sql.enc. Only Administrators can see or download them: they are private, hidden from Editors and the REST API, kept in their own folder that Apache servers refuse to serve, and downloaded through an administrator-only link. If the server hands out files from that folder anyway (nginx ignores .htaccess files), the Setup page says so and shows the rule to block it, which it finds out by requesting a test file from its own site once a day.

The encryption key is stored in the database and shown on the Setup page. Save a copy somewhere else, such as a password manager: if the database is ever lost, the backups cannot be opened without it, and nobody can recover it. Uninstalling the plugin leaves the key in place for the same reason.

Backups run through WordPress's scheduler, which only fires when someone visits the site, so on a quiet site a backup can run some hours late. Because these backups live on the same server, keep downloading a dump from Export Data now and then and store it somewhere else.

= What happens when I delete a member, or a member deletes their own account? =

It depends on whether that member has any loan or reservation history. With none, the delete is a true, permanent removal: their row in the plugin's database and their WordPress account are both gone. With history, the plugin can't remove their row outright (loans and reservations reference it, and deleting it would corrupt those tables' history), so it anonymizes them instead: name, address, phone, and email are overwritten with placeholders, their profile photo link and verification documents are deleted, and their WordPress account is deleted, but their loan and reservation rows are left completely untouched, so a tool's total-loans count and similar statistics stay accurate. Either way it's permanent and cannot be undone. Members can start this themselves from their Account page ("Delete Account and Remove Personal Data"); staff can do the same for any member from the Membership page's Delete button, which explains up front which outcome a given member will get.

One narrow exception, if you enable member agreements: an agreement record keeps the member's name and email address as they were at the moment they agreed, and keeps them after that member is deleted. A record with nobody's name on it cannot show who agreed, which is the only thing it exists to show. Nothing else about them is retained on those records: no IP addresses, no device or browser data, nothing about the connection at all. A name, an email address, what they agreed to, and when. Data exports contain these records too, so store export files somewhere access-controlled. This retention normally needs mentioning in whatever privacy notice you give members, and it is worth deciding on your own legal advice before you turn the feature on.

= What happens when I delete a tool? =

Same idea as members, without the personal-data angle: a tool with no loan/reservation history can be deleted outright. One with history can't be (same underlying reason), so use Retire instead, on the Inventory page. Retiring takes the tool out of the public catalog's default listing (visitors can still find it, marked Retired, with the catalog's Retired filter) and blocks new loans or reservations for it (any reservations already queued for it are automatically cancelled), while keeping its row and full history intact. Unlike deleting a member, retiring is fully reversible, so click Reactivate to bring it back. Retired tools are hidden from the Inventory page's default list; use the "Retired?" advanced-search filter to find them.

= What happens to my data if I uninstall the plugin? =

Deleting the plugin through the Plugins screen removes its own settings (branding, appearance, and the other values configured on the Setup page). Your actual library data (members, tools, loans, and reservations) is intentionally left in place in the database, since that is real operational and financial history that shouldn't disappear just because the plugin was removed (even temporarily, e.g. while troubleshooting something unrelated). Use the Setup page's export feature to make a backup first if you intend to remove the plugin's database tables entirely; they can then be dropped manually.

= Does this plugin phone home, load anything from a third-party CDN, or track my visitors? =

No. There are no external network calls, no bundled third-party analytics, and no assets loaded from any CDN. The only request the plugin makes is to its own site: once a day, when automatic backups are in use, the Setup page checks whether the backup folder can be downloaded from.

== Screenshots ==

1. The public tool catalog (no account required to browse).
2. A tool's detail view with a shareable link and reservation option.
3. The staff Dashboard, with configurable/resizable stat panels.
4. The Inventory admin page with a tool's detailed view expanded.
5. The Loans & Reservations page: checking a reservation out as a loan.
6. The Setup page's controls.
7. Database schema diagram.

== Changelog ==

= 1.0.0 =
* Initial release: public catalog, member accounts, reservations, loans, and the full admin back office (Dashboard, Inventory, Membership, Loans & Reservations, Workflows, Setup).
* Go Live switch: a new install stays behind a Coming soon page, optionally with the catalog open to browse, until an administrator opens it from Setup.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
