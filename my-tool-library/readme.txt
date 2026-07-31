=== My Tool Library ===
Contributors: mkelibrary
Donate link: https://mkelibrary.org
Tags: tool library, lending library, inventory, reservations, membership
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Run a community tool-lending library from WordPress: inventory, memberships, reservations, and loans, with a zero-JavaScript public catalog.

== Description ==

My Tool Library turns a WordPress site into the online home of a physical tool-lending library: a public catalog customers can browse and reserve from, a membership system, and a full admin back office for staff to manage inventory, memberships, loans, and reservations.

= For your community =

* Browse the tool catalog and search/filter by name, brand, category, tag, and availability (no account required).
* Create a free account, reserve a tool, and track your place in the waiting queue.
* View active loans and due dates with an at-a-glance status: due soon, due today, or overdue.
* Manage your own contact details from an Account page, and see your borrowing history.
* The entire public-facing experience works with **zero JavaScript**. Every interaction is a plain link or form: searching, filtering, sorting, pagination, selecting a tool, reserving, cancelling. This plugin works identically with JavaScript disabled.

= For your staff =

* A Dashboard of configurable stat panels: current membership, tools on loan, overdue tools, upcoming reservations, most/least popular tools, asset value and depreciation, and more.
* Inventory management with CSV bulk import, categories/tags, and a detailed per-tool view showing loan history, current reservations, and financial tracking (value, depreciation, donor).
* Membership management with CSV bulk import and identity-verification tracking (photo ID + proof of address), kept in a separate table from everyday member contact info.
* A unified Loans & Reservations page: check a reservation out as a loan (with a configurable default loan length), cancel a reservation, renew or end a loan. Search, sorting, and advanced filters throughout.
* A Setup page for branding (logo, colors, fonts, button style), category/tag management, database installation, and full data export/backup.

= Design principles =

* Every public-facing page is intentionally built with no JavaScript at all, using plain forms, links, and CSS (including the `:target` pseudo-class for instant, same-page tool detail views).
* Admin pages use JavaScript freely where it improves the staff experience (sorting, filtering, resizing, drag-and-drop).
* Member passwords are always handled by WordPress core (`wp_insert_user()`). The plugin's own database tables never store a password in any form.

= Who this is for =

Tool libraries, makerspaces, community lending programs, and similar organizations that lend physical items to members and want a self-hosted, no-subscription way to manage it from their existing WordPress site. Built with a U.S.-based library in mind by default, but not limited to one -- members can sign up from any country.

= Assumptions and intended use =

This plugin is built around a specific, deliberately simple operating model. Before installing, confirm it matches how your organization actually runs:

* **Single location, single copy per tool.** Each tool is one row with one barcode; the plugin does not model multiple copies of the same tool or multiple lending locations/branches. Duplicate locations could lead to collisions.
* **Reservation does not require verification.** Any signed-in member can reserve a tool and join its queue. Identity verification (photo ID + proof of address) is a separate, staff-run process intended to happen in person. This would typically be at pickup, before a tool actually leaves the building. The plugin does not enforce verification as a precondition for reserving, and enforcing it at checkout time is a staff judgment call, not something the software blocks.
* **Staff are trusted WordPress Administrators.** Every admin page requires the `manage_options` capability. There is currently no separate lower-privilege "staff" or "volunteer" role with restricted access.
* **No payments.** `recurring_donation_amount` on a member record is informational only; the plugin does not collect, charge, or reconcile payments of any kind.
* **No outbound notifications.** There is no email/SMS system. Members and staff see current status (due soon, overdue, ready for pickup, queue position) by visiting the relevant page; nothing is pushed to them automatically.
* **One database, one WordPress install.** The plugin creates its own tables via `$wpdb` using your site's table prefix. It has not been tested against multisite network-activation; on multisite it should be activated per-site.
* **Site timezone must be set correctly.** Reservations and loans are timestamped using WordPress's configured site timezone (Settings > General > Timezone). If that is left at its default, timestamps will not reflect your actual local time (see the FAQ).
* **Signup has no CAPTCHA or throttling.** Anyone who can reach the sign-up page can create a member account (email confirmation is not required; the account is active immediately). This matches a walk-in-friendly, low-friction community tool library; if your site is at higher risk of automated abuse, put it behind whatever anti-spam layer (CAPTCHA, firewall rules, etc.) you'd normally use for a public registration form.
* **Members can be from any country.** The signup and Membership pages collect a full address: Address Line 1 (required), Address Line 2 (optional -- apartment/suite/unit), City, State/Province, ZIP/Postal Code, and Country. Country is a dropdown of every ISO 3166-1 country, defaulting to United States but changeable by the member or by staff (via Edit Member) to any other. State/Province is a dropdown covering U.S. states/territories and Canadian provinces (both use short, standardized 2-letter codes); members anywhere else select "N/A" there, since region/province systems vary too much per-country to model directly, and can still note their actual region in the address lines if it matters. Country is always the last line of a member's displayed address, per international postal addressing convention (UPU S42), regardless of which country is selected.
* **Photos and documents are links, not uploads.** The plugin does not host or upload image files itself: `photo_url` (a tool's photo), `photo_id_scan_url`, and `address_proof_scan_url` (a member's verification documents) are plain link fields. Host the actual image elsewhere (a cloud storage provider such as Google Drive, with sharing permissions set deliberately) and paste the resulting link in. Tool photos are shown on the public catalog to anyone, so they should be publicly viewable; verification document scans are sensitive personal records shown only on the admin-only Membership page and should require the viewer to be signed in or explicitly granted access, not just have an unguessable URL. See the FAQ for more detail.
* **Deleting a member with loan/reservation history anonymizes it instead.** A member can delete their own account (Account page), and staff can delete any member (Membership page); either way, a member with no borrowing history is removed outright, but one who has ever borrowed or reserved a tool has their personal data anonymized and their WordPress account deleted, while that loan/reservation history is kept so tool-level statistics stay accurate. See the FAQ for detail. Tools work the same way in spirit but are never anonymous: a tool with history can be **Retired** (hidden from the catalog, blocked from new loans, fully reversible) instead of deleted.
* **Tools can carry private staff notes.** The Inventory page's Add/Edit forms (and the bulk CSV import, via a `private_notes` column) have an optional Private Notes field (e.g. "missing a screw, ask Jim before lending" or "this one's a loaner from another org, handle with care"). It's never included in the public catalog or any member-facing page or query -- it's shown only in the admin-only Inventory page's detail view. A CSV file itself isn't private once it leaves the site, though, so avoid sharing an import file that has sensitive notes filled in.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/my-tool-library`, or install it through the WordPress Plugins screen directly.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Go to **My Tool Library > Setup** and click **Run Database Setup** to create the plugin's database tables. This step is required before any other page will work correctly.
4. Confirm your site's timezone is correct under **Settings > General > Timezone**. The plugin timestamps reservations and loans using this setting; left at the default (UTC), timestamps will not match your local time.
5. Still on the Setup page, fill in your organization's name, logo, and colors, and set the Home Page Link and any pickup/verification directions you want members to see.
6. Copy the **Public Page Link** shown on the Setup page and add it to your site's navigation menu (or link to it from any page or post); this is the one link your customers need to reach the tool catalog.
7. Add your tool categories and tags (Setup page), then add tools (**My Tool Library > Inventory**) and members (**My Tool Library > Membership**). Inventory and members can be added one at a time, or via CSV bulk import on either page.

= Permalinks =

The public catalog is reachable at `/tool-library/` automatically if your site uses any permalink structure other than "Plain" (Settings > Permalinks). On Plain-permalink sites, the plugin falls back to a query-string URL; the Setup page's Public Page Link box always shows whichever form is currently active, so you never need to construct it by hand.

== Frequently Asked Questions ==

= Does this plugin require JavaScript? =

Not for your customers. Every public-facing page (the catalog, sign-up, sign-in, reservations, account) works with JavaScript completely disabled. The admin/staff back office does use JavaScript for a better staff experience (sorting, filtering, drag-and-drop dashboard panels), but nothing customer-facing depends on it.

= Does reserving a tool require identity verification? =

No. Any signed-in member can reserve a tool and join its waiting queue. Verification (checking a photo ID and proof of address) is a separate, staff-performed process tracked on the member's record. See "Assumptions and intended use" above.

= How do I add tool photos or verification document scans? =

Both are link fields, not file uploads: `photo_url` on a tool, and `photo_id_scan_url` / `address_proof_scan_url` on a member's verification record. The plugin has no built-in file storage or media upload for any of them, so you'll need to host the actual image somewhere else first and paste in the resulting URL. A cloud storage provider such as Google Drive works well for this, but the permissions you give that link need to match what's actually being shown:

* **Tool photos** are displayed on the public catalog to any visitor, signed in or not, so the hosted image needs to be publicly viewable (e.g. Google Drive's "Anyone with the link" sharing option).
* **Verification document scans** (a photo ID, a proof-of-address document) are sensitive personal records, shown only on the admin-only Membership page. Their hosted image should require the viewer to be signed in or specifically granted access. Share it only with the specific staff accounts (or a properly access-controlled group) who need it, the same way you'd handle any other sensitive document.

The plugin has no way to check how a linked image is actually hosted or shared, and cannot verify access controls.

= Where are member passwords stored? =

Entirely in WordPress's own user system (`wp_users`), using WordPress's normal password hashing via `wp_insert_user()`. The plugin's own database tables never contain a password in any form.

= Why don't my reservation/loan timestamps match the time I actually took the action? =

Your site's timezone (Settings > General > Timezone) is probably still set to its default. The plugin timestamps events using `current_time()`, which follows that setting. Set it to your actual local timezone (a named city, not just a UTC offset, so Daylight Saving is handled automatically) and new timestamps will be correct going forward. Timestamps already recorded before the fix will not be retroactively corrected.

= Can I customize how the catalog and account pages look? =

Yes. The Setup page lets you set your organization's logo, colors, fonts, button style, and corner radius, applied consistently across both the admin pages and the public-facing pages.

= Is there a way to quickly test the plugin and database? =

Yes. Testing can be done on your site or in a local instance through LocalWP (recommended). Simply borrow or create a dummy data SQL file (there is a dummy-data.sql file included in the documentation for this plugin). If borrowing the included dummy-data.sql file, it is recommended to change the stale dates of loans, reservations, and membership signup to reflect current dates (your favorite AI tool can do this quickly). Use the database schema, dbml file, and visual schematics when creating your own dummy date file. Run the SQL file as a command through the WordPress backend database manager (phpMyAdmin, Adminer, AdminNeo, etc.) to insert your dummy data. You may then test the plugin as both an administrator and customer.

= Does the plugin send email notifications (e.g. "your reservation is ready")? =

Not currently. Members and staff see up-to-date status by visiting the relevant page; there is no outbound email or notification system in this version.

= Can I export my data? =

Yes. The Setup page can export the plugin's full dataset as either a SQL dump (importable back into MySQL) or a ZIP of CSV files, one per table.

= What happens when I delete a member, or a member deletes their own account? =

It depends on whether that member has any loan or reservation history. With none, the delete is a true, permanent removal: their row in the plugin's database and their WordPress account are both gone. With history, the plugin can't remove their row outright (loans and reservations reference it, and deleting it would corrupt those tables' history), so it anonymizes them instead: name, address, phone, and email are overwritten with placeholders, their verification documents are deleted, and their WordPress account is deleted -- but their loan and reservation rows are left completely untouched, so a tool's total-loans count and similar statistics stay accurate. Either way it's permanent and cannot be undone. Members can start this themselves from their Account page ("Delete Account and Remove Personal Data"); staff can do the same for any member from the Membership page's Delete button, which explains up front which outcome a given member will get.

= What happens when I delete a tool? =

Same idea as members, without the personal-data angle: a tool with no loan/reservation history can be deleted outright. One with history can't be (same underlying reason), so use **Retire** instead, on the Inventory page. Retiring hides the tool from the public catalog and blocks new loans or reservations for it (any reservations already queued for it are automatically cancelled), while keeping its row and full history intact. Unlike deleting a member, retiring is fully reversible -- click **Reactivate** to bring it back. Retired tools are hidden from the Inventory page's default list; use the "Retired?" advanced-search filter to find them.

= What happens to my data if I uninstall the plugin? =

Deleting the plugin through the Plugins screen removes its own settings (branding, appearance, and the other values configured on the Setup page). Your actual library data (members, tools, loans, and reservations) is intentionally left in place in the database, since that is real operational and financial history that shouldn't disappear just because the plugin was removed (even temporarily, e.g. while troubleshooting something unrelated). Use the Setup page's export feature to make a backup first if you intend to remove the plugin's database tables entirely; they can then be dropped manually.

= Does this plugin phone home, load anything from a third-party CDN, or track my visitors? =

No. There are no external network calls, no bundled third-party analytics, and no assets loaded from any CDN.

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
* Initial release: public catalog, member accounts, reservations, loans, and the full admin back office (Dashboard, Inventory, Membership, Loans & Reservations, Setup).

== Upgrade Notice ==

= 1.0.0 =
Initial release.
