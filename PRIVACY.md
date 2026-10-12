# Privacy guide for libraries

My Tool Library doesn't have a privacy policy of its own, because it doesn't collect anything for its authors: no analytics, no usage reporting, no calls home. The personal data it handles belongs to your library, about your members, and lives in your own WordPress database. Your library's privacy policy is what tells members how you handle it.

This guide is for the staff who maintain that policy. It lists what the plugin stores and does, and what we recommend your policy says about it. It isn't legal advice. Privacy law differs by country and state, so check your final wording with whoever advises your library.

## Start with the suggested text

The plugin adds a "My Tool Library" section to **Settings › Privacy › Policy Guide** in your WordPress admin. It covers member accounts, account deletion, member agreements, cookies and fonts, in plain language you can copy into your policy page.

The rest of this guide covers what that text leaves out, and the places where it depends on how your library works.

## What the plugin stores

| Data | Who can see it | Kept until |
|---|---|---|
| Name, address, phone, email, sign-up date | The member (Account page) and staff | The account is deleted |
| A WordPress sign-in account | The member and Administrators | The account is deleted |
| Links to a photo ID and proof of address, and the date staff verified them | Staff only | The member changes their details or the account is deleted |
| A link to a profile photo staff took | Staff only | The account is deleted |
| Trainings completed, with dates | The member and staff | The account is deleted |
| Loans and reservations | The member and staff | Kept after deletion without the member's details, for tool statistics |
| Member agreement records (if turned on) | Staff | Kept after deletion, with the name and email as they were when the member agreed |
| Staff notes about the member | Staff only | The account is deleted |
| Donation amount and a "has donated tools" flag | Staff only | The account is deleted |
| Whether the account is locked | The member and staff | The account is unlocked or deleted |

"Deleted" here means the plugin's own deletion: from the member's Account page, or by staff on the Membership page. A member who has never borrowed or reserved anything is removed completely. Anyone else is anonymized: their details are replaced with placeholders and their loan history stays.

## What we recommend adding to your policy

**1. Where photos and documents are kept, and who deletes them.**
The plugin stores *links* to member photos and verification documents, not the files. The files live wherever your staff put them, such as a Google Drive folder. Say where that is and who can open it. When an account is deleted, the plugin removes the links and emails your site administrator a list of the files to delete. The suggested text promises "we delete your photo and documents", so keep that promise only if someone acts on that email every time.

**2. Staff notes and donation details.**
The suggested text doesn't mention them. If your staff use the notes field or record donations, say so. Ask staff to keep notes factual and free of sensitive details they wouldn't want a member to read.

**3. The emails the plugin sends.**
All of these are sent when someone acts, never on a schedule, and they go through your site's own mail setup:
- a link to set a password, for members staff added
- WordPress's password-reset email, and a notice when a password changes
- a notice to someone who tries to sign up with an email that already has an account
- a notice when an account is locked or deleted
- member agreement requests and copies of what was agreed (if agreements are on)
- to the site administrator: verification files to delete when a member changes their details or deletes their account

If your site sends mail through a service such as an SMTP provider, that service handles these messages. Name it if your policy lists the companies you share data with.

**4. Exports and backups.**
Staff can export everything as a SQL file or as CSV files, and Setup can make automatic encrypted backups in the Media Library. By default it keeps the last 10, and only Administrators can download them. Say that backups exist and how long they're kept. Exported files aren't encrypted, so store them somewhere access-controlled and delete them when you're done.

**5. How long you keep active members' data.**
The plugin never deletes members on its own. Decide whether you remove members who haven't borrowed for a set time, and say so. If you don't, say that data is kept until the member deletes their account or asks you to.

**6. How members get a copy of their data.**
The plugin doesn't hook into WordPress's **Tools › Export Personal Data** and **Erase Personal Data** screens, so those won't include member records. Members can see most of their details on their Account page. For a full copy, staff can read the member's record on the Membership page. Members can delete their own accounts; staff can delete anyone's. Say how members should ask, and who answers.

**7. Cookies.**
The suggested text covers the three cookies: WordPress's sign-in cookies, a password-reset cookie that exists only during a reset, and `mtl_catalog_view`. The last one remembers which catalog view a visitor picked, for a year, and holds nothing about them. A cookie that only remembers a visitor's own choice usually needs no consent banner, but check the rules where your library operates.

**8. Other parties.**
The plugin sends nothing to anyone. Fonts are served from your site, including any installed through WordPress's Font Library. The parties to list are the ones your library chose: your web host, your mail service, and wherever you keep member photos and documents.

## What the plugin doesn't do

- No analytics, tracking or advertising.
- No requests to other sites from the public pages. Fonts and styles come from your own site.
- No IP addresses or device details saved with agreement records.
- No payments. Donation fields are notes only.
- Only one thing is written to the server log: a member's ID number when an email to the site administrator fails to send.

## Before you publish

- [ ] Copied the suggested text from Settings › Privacy › Policy Guide, and removed the agreements section if you don't use them.
- [ ] Said where member photos and documents are stored, and made sure someone deletes them when an account is deleted.
- [ ] Mentioned staff notes and donation details, if you use them.
- [ ] Listed the emails members may receive, and your mail service if you use one.
- [ ] Described exports and backups and how long backups are kept.
- [ ] Stated how long you keep active members' data.
- [ ] Explained how members can ask for a copy of their data or have it deleted.
- [ ] Checked the cookie wording, and any consent rules, for where you operate.
- [ ] Had the final policy reviewed by whoever advises your library.
